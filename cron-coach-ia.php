<?php
/**
 * AssoKit — CRON Coach Assokit hebdomadaire
 * Lundi 8h : génère le rapport de la semaine passée + envoie email aux admins.
 *   /usr/bin/php /home/pura7044/public_html/cron-coach-ia.php
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes-coach-ia.php';
@require_once __DIR__ . '/ai-helper.php';
@require_once __DIR__ . '/resend-helper.php';

$is_cli = (PHP_SAPI === 'cli');
$has_key = isset($_GET['key']) && defined('CRON_SECRET') && hash_equals(CRON_SECRET, $_GET['key']);
if (!$is_cli && !$has_key) { http_response_code(403); die('Forbidden'); }

if (!function_exists('ask_claude')) { echo "ai-helper manquant\n"; exit(1); }

echo "[" . date('Y-m-d H:i:s') . "] CRON Coach Assokit démarré\n";
$started = microtime(true);

// Charger toutes les orgs actives
$stmt = $pdo->query("SELECT id, name, legal_form AS type FROM organizations WHERE id IN (SELECT DISTINCT org_id FROM users WHERE is_active = 1 AND role = 'admin')");
$orgs = $stmt->fetchAll();
echo "→ " . count($orgs) . " organisations\n";

$stats = ['ok' => 0, 'mail' => 0, 'errors' => 0];

foreach ($orgs as $org) {
    $org_id = (int)$org['id'];
    echo "── Org #$org_id {$org['name']} ──\n";
    try {
        // Skip si rapport déjà généré pour cette semaine
        $week_start = date('Y-m-d', strtotime('monday last week'));
        $check = $pdo->prepare("SELECT id, sent_email_at FROM coach_reports WHERE org_id = ? AND week_start = ?");
        $check->execute([$org_id, $week_start]);
        $existing = $check->fetch();
        if ($existing && $existing['sent_email_at']) {
            echo "  ⏭ Déjà envoyé\n";
            continue;
        }

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

$elapsed = round(microtime(true) - $started, 2);
echo "\n[" . date('Y-m-d H:i:s') . "] Terminé en {$elapsed}s · {$stats['ok']} rapports · {$stats['mail']} emails · {$stats['errors']} erreurs\n";
exit(0);
