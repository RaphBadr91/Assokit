<?php
/**
 * AssoKit — CRON Coach Assokit hebdomadaire
 * Génère le rapport de la semaine passée et l'envoie aux admins.
 *
 * À LANCER TOUTES LES 10 MINUTES, et non plus une fois le lundi à 8h.
 * Chaque passage traite un lot d'associations puis rend la main ; le
 * suivant reprend la suite. La semaine visée ne bouge pas du lundi au
 * dimanche, donc l'heure du passage n'a aucune importance, et quand
 * tout le monde a reçu son rapport le cron ne fait plus rien.
 *   /usr/bin/php /home/pura7044/public_html/cron-coach-ia.php
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes-coach-ia.php';
@require_once __DIR__ . '/ai-helper.php';
@require_once __DIR__ . '/resend-helper.php';
require_once __DIR__ . '/cron-lots.php';

$is_cli = (PHP_SAPI === 'cli');
$has_key = isset($_GET['key']) && defined('CRON_SECRET') && hash_equals(CRON_SECRET, $_GET['key']);
if (!$is_cli && !$has_key) { http_response_code(403); die('Forbidden'); }

if (!function_exists('ask_claude')) { echo "ai-helper manquant\n"; exit(1); }

if (!ak_lot_demarrer($pdo, 'coach-ia')) exit(0);

// La semaine visée, calculée une fois : « monday last week » donne le
// même lundi du lundi au dimanche, donc ce cron peut tourner n'importe
// quel jour sans décaler le rapport.
$week_start = date('Y-m-d', strtotime('monday last week'));

// On ne demande QUE ce qui reste à faire, et seulement de quoi remplir
// le lot. L'ancienne version chargeait les 10 000 associations puis en
// écartait 9 800 une par une en PHP ; ici la base ne renvoie que les
// bonnes, et le passage suivant reprendra tout seul à la suite.
//
// EXISTS plutôt que « id IN (SELECT DISTINCT org_id …) » : le DISTINCT
// matérialisait la liste des org_id de tous les comptes de la base.
$stmt = $pdo->prepare("SELECT o.id, o.name, o.legal_form AS type
    FROM organizations o
    WHERE EXISTS (SELECT 1 FROM users u
                   WHERE u.org_id = o.id AND u.is_active = 1 AND u.role = 'admin')
      AND NOT EXISTS (SELECT 1 FROM coach_reports c
                       WHERE c.org_id = o.id AND c.week_start = ?
                         AND c.sent_email_at IS NOT NULL)
    ORDER BY o.id
    LIMIT " . ($place = (int) ak_lot_place()));
$stmt->execute([$week_start]);
$orgs = $stmt->fetchAll();
echo "→ " . count($orgs) . " organisation(s) à traiter pour la semaine du $week_start\n";

$stats = ['ok' => 0, 'mail' => 0, 'errors' => 0, 'repris' => 0];

foreach ($orgs as $org) {
    // Le budget se vérifie entre deux associations, jamais pendant : un
    // appel à l'IA coupé en deux serait payé sans rien produire.
    if (!ak_lot_encore()) break;
    ak_lot_fait();

    $org_id = (int)$org['id'];
    echo "── Org #$org_id {$org['name']} ──\n";
    try {
        // Un rapport déjà écrit mais dont l'e-mail n'est pas parti : on
        // le renvoie tel quel. L'ancienne version relançait l'IA, donc
        // une panne d'e-mail coûtait un second appel payant par semaine
        // et par association, sans rien changer au contenu.
        $check = $pdo->prepare("SELECT * FROM coach_reports WHERE org_id = ? AND week_start = ?");
        $check->execute([$org_id, $week_start]);
        $existing = $check->fetch();

        if ($existing) {
            echo "  ↻ Rapport déjà écrit, on ne refait que l'envoi\n";
            $report_id = (int) $existing['id'];
            $ctx = ['week_start' => $existing['week_start'], 'week_end' => $existing['week_end']];
            $parsed = [
                'summary'    => (string) $existing['summary_md'],
                'highlights' => json_decode((string) $existing['highlights_json'], true) ?: [],
                'warnings'   => json_decode((string) $existing['warnings_json'], true) ?: [],
                'recos'      => json_decode((string) $existing['recos_json'], true) ?: [],
            ];
            $stats['repris']++;
        } else {
            // 1. Build context
            $ctx = coach_build_context($pdo, $org_id);
            $prompt = coach_build_prompt($ctx);

            // 2. Appel Claude
            $resp = ask_claude(
                "Tu es AssoCoach, un coach Assokit hebdomadaire pour les responsables associatifs. Tu réponds UNIQUEMENT en JSON valide, sans markdown, sans texte autour.",
                [['role' => 'user', 'content' => $prompt]],
                1500
            );
            $raw = ($resp && !empty($resp['success'])) ? ($resp['content'] ?? '') : '';
            if (!$raw) { echo "  ✗ Pas de réponse Claude\n"; $stats['errors']++; continue; }

            // 3. Parse
            $parsed = coach_parse_response($raw);
            if (!$parsed) { echo "  ✗ JSON invalide\n"; $stats['errors']++; continue; }

            // 4. Save
            $report_id = coach_save_report($pdo, $org_id, $ctx, $parsed, $raw, null);
            $stats['ok']++;
            echo "  ✓ Rapport généré #$report_id\n";
        }

        // 5. Email aux admins
        $stmt2 = $pdo->prepare("SELECT email, first_name FROM users WHERE org_id = ? AND role = 'admin' AND is_active = 1");
        $stmt2->execute([$org_id]);
        $admins = $stmt2->fetchAll();

        $report = ['week_start' => $ctx['week_start'], 'week_end' => $ctx['week_end'],
            'summary_md' => $parsed['summary'],
            'highlights_json' => json_encode($parsed['highlights']),
            'warnings_json' => json_encode($parsed['warnings']),
            'recos_json' => json_encode($parsed['recos'])];
        $html = coach_render_email_html($report, $org);
        $subject = "🤖 Ton coach Assokit · " . date('d/m', strtotime($ctx['week_start'])) . "→" . date('d/m', strtotime($ctx['week_end']));
        $text = "Coach Assokit semaine du " . $ctx['week_start'] . "\n\n" . $parsed['summary'] . "\n\nhttps://assokit.fr/coach-ia";

        $sent_any = false;
        foreach ($admins as $a) {
            if (!filter_var($a['email'], FILTER_VALIDATE_EMAIL)) continue;
            try {
                if (function_exists('send_email_resend')) {
                    send_email_resend($a['email'], $subject, $html, $text);
                    $sent_any = true;
                } else {
                    $headers = "MIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\nFrom: AssoKit <noreply@assokit.fr>\r\n";
                    if (@mail($a['email'], $subject, $html, $headers)) $sent_any = true;
                }
            } catch (Throwable $e) {}
        }
        if ($sent_any) {
            $pdo->prepare("UPDATE coach_reports SET sent_email_at = NOW() WHERE id = ?")->execute([$report_id]);
            $stats['mail']++;
            echo "  ✓ Email envoyé à " . count($admins) . " admin(s)\n";
        }

    } catch (Throwable $e) {
        echo "  EXCEPTION : " . $e->getMessage() . "\n";
        $stats['errors']++;
    }
}

echo "\n{$stats['ok']} rapport(s) · {$stats['repris']} envoi(s) repris · {$stats['mail']} e-mail(s) · {$stats['errors']} erreur(s)\n";
// La requête a rempli tout le lot : il reste très probablement des
// associations derrière, qu'un prochain passage prendra.
ak_lot_terminer($pdo, count($orgs) >= $place);
exit(0);
