<?php
/**
 * AssoKit — CRON Rappels subventions
 * À lancer 1x par jour (matin).
 *   /usr/bin/php /home/pura7044/public_html/cron-grant-reminders.php
 *
 * Envoie un email aux admins de l'org pour :
 *   - J-7, J-1, jour J : avant deadline dépôt
 *   - Retard : si deadline dépôt dépassée et statut draft/submitted
 *   - J-30, J-7 : avant deadline bilan (statut granted)
 *   - Retard bilan : si deadline_report dépassée
 * Chaque rappel n'est envoyé qu'1x grâce à grant_reminders_sent.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes-grants.php';
@require_once __DIR__ . '/resend-helper.php';

// CLI uniquement (avec key web optionnelle)
$is_cli = (PHP_SAPI === 'cli');
$has_key = isset($_GET['key']) && defined('CRON_SECRET') && hash_equals(CRON_SECRET, $_GET['key']);
if (!$is_cli && !$has_key) { http_response_code(403); die('Forbidden'); }

$started = microtime(true);
echo "[" . date('Y-m-d H:i:s') . "] CRON Grant reminders démarré\n";

$today = date('Y-m-d');

// Charger toutes les subventions à scanner
$stmt = $pdo->query("SELECT g.* FROM grants g WHERE g.archived_at IS NULL AND g.status NOT IN ('rejected','reported','archived')");
$grants = $stmt->fetchAll();
echo "→ " . count($grants) . " dossiers à scanner\n";

$sent_total = 0;

function reminder_send(PDO $pdo, array $grant, string $type, string $subject, string $bodyHtml, string $bodyText): bool {
    // Vérifier qu'on n'a pas déjà envoyé ce type
    $stmt = $pdo->prepare("SELECT 1 FROM grant_reminders_sent WHERE grant_id = ? AND reminder_type = ?");
    $stmt->execute([$grant['id'], $type]);
    if ($stmt->fetch()) return false;

    // Récupérer admins de l'org
    $stmt = $pdo->prepare("SELECT email, first_name FROM users WHERE org_id = ? AND role = 'admin' AND is_active = 1");
    $stmt->execute([$grant['org_id']]);
    $admins = $stmt->fetchAll();
    if (empty($admins)) return false;

    $sent_any = false;
    foreach ($admins as $a) {
        if (!filter_var($a['email'], FILTER_VALIDATE_EMAIL)) continue;
        try {
            if (function_exists('send_email_resend')) {
                send_email_resend($a['email'], $subject, $bodyHtml, $bodyText);
                $sent_any = true;
            } else {
                // Fallback mail() PHP
                $headers = "MIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\nFrom: AssoKit <noreply@assokit.fr>\r\n";
                if (@mail($a['email'], $subject, $bodyHtml, $headers)) $sent_any = true;
            }
        } catch (Throwable $e) {}
    }

    if ($sent_any) {
        try {
            $pdo->prepare("INSERT INTO grant_reminders_sent (grant_id, reminder_type) VALUES (?, ?)")->execute([$grant['id'], $type]);
            gr_log($pdo, (int)$grant['id'], null, 'reminder_email', "📨 Rappel envoyé : {$subject}");
        } catch (Throwable $e) {}
    }
    return $sent_any;
}

function build_email(array $grant, string $title, string $urgency_color, string $urgency_label, string $action_text): array {
    $url = "https://assokit.fr/subvention/" . (int)$grant['id'];
    $amount = $grant['amount_requested'] ? number_format((float)$grant['amount_requested'], 0, ',', ' ') . ' €' : '—';
    $subject = "[AssoKit] {$urgency_label} · {$grant['name']}";
    $html = '<div style="font-family:-apple-system,sans-serif;max-width:520px;margin:0 auto;padding:24px;background:#f9fafb;">'
        . '<div style="background:#fff;border-radius:14px;padding:28px;border:1px solid #e5e7eb;">'
        . '<div style="display:inline-block;background:'.$urgency_color.';color:#fff;font-size:11px;font-weight:700;padding:4px 10px;border-radius:999px;text-transform:uppercase;letter-spacing:0.05em;margin-bottom:14px;">'.htmlspecialchars($urgency_label).'</div>'
        . '<h1 style="font-size:20px;margin:0 0 8px;color:#111827;">'.htmlspecialchars($title).'</h1>'
        . '<p style="color:#4b5563;font-size:14px;line-height:1.55;margin:0 0 18px;"><strong>'.htmlspecialchars($grant['name']).'</strong><br>'
        . 'Financeur : '.htmlspecialchars($grant['funder']).'<br>'
        . 'Montant demandé : '.$amount.'</p>'
        . '<p style="color:#4b5563;font-size:14px;line-height:1.55;margin:0 0 22px;">'.htmlspecialchars($action_text).'</p>'
        . '<a href="'.$url.'" style="display:inline-block;background:#10B981;color:#fff;padding:11px 22px;border-radius:8px;text-decoration:none;font-weight:600;font-size:14px;">Ouvrir le dossier →</a>'
        . '</div><p style="text-align:center;color:#6b7280;font-size:11px;margin:18px 0 0;">AssoKit · gestion d\'association</p></div>';
    $text = "{$urgency_label}\n\n{$title}\n\n{$grant['name']}\nFinanceur : {$grant['funder']}\nMontant : {$amount}\n\n{$action_text}\n\n{$url}";
    return [$subject, $html, $text];
}

foreach ($grants as $g) {
    // === DEADLINE DÉPÔT ===
    if (in_array($g['status'], ['draft','submitted','in_review'], true) && $g['deadline_apply']) {
        $diff = (int)((strtotime($g['deadline_apply']) - strtotime($today)) / 86400);

        if ($diff === 7) {
            list($s, $h, $t) = build_email($g, "⏰ Deadline dans 7 jours", '#F59E0B', 'J-7', "La deadline de dépôt approche. Vérifie que ton dossier est prêt et complet.");
            if (reminder_send($pdo, $g, 'apply_7d', $s, $h, $t)) { echo "  ✓ J-7 dépôt #{$g['id']}\n"; $sent_total++; }
        }
        if ($diff === 1) {
            list($s, $h, $t) = build_email($g, "🚨 Deadline DEMAIN", '#EF4444', 'URGENT J-1', "Dernier jour pour finaliser le dossier ! Dépose-le aujourd'hui ou demain matin maximum.");
            if (reminder_send($pdo, $g, 'apply_1d', $s, $h, $t)) { echo "  ✓ J-1 dépôt #{$g['id']}\n"; $sent_total++; }
        }
        if ($diff === 0) {
            list($s, $h, $t) = build_email($g, "🚨 C'est aujourd'hui !", '#DC2626', "JOUR J", "Dépose le dossier MAINTENANT, avant la fermeture (vérifie l'heure limite).");
            if (reminder_send($pdo, $g, 'apply_0d', $s, $h, $t)) { echo "  ✓ J0 dépôt #{$g['id']}\n"; $sent_total++; }
        }
        if ($diff < 0 && $g['status'] !== 'submitted') {
            list($s, $h, $t) = build_email($g, "❌ Deadline dépassée", '#7F1D1D', 'RETARD', "Le dossier n'a pas encore été déposé alors que la deadline est dépassée. Contacte le financeur pour savoir si un dépôt tardif est possible.");
            if (reminder_send($pdo, $g, 'apply_overdue', $s, $h, $t)) { echo "  ✓ Retard dépôt #{$g['id']}\n"; $sent_total++; }
        }
    }

    // === DEADLINE BILAN ===
    if ($g['status'] === 'granted' && $g['deadline_report'] && !$g['reported_at']) {
        $diff = (int)((strtotime($g['deadline_report']) - strtotime($today)) / 86400);

        if ($diff === 30) {
            list($s, $h, $t) = build_email($g, "📊 Bilan à rendre dans 30 jours", '#3B82F6', 'BILAN J-30', "Pense à préparer le bilan de cette subvention. Compile factures, photos et rapport d'activité.");
            if (reminder_send($pdo, $g, 'report_30d', $s, $h, $t)) { echo "  ✓ J-30 bilan #{$g['id']}\n"; $sent_total++; }
        }
        if ($diff === 7) {
            list($s, $h, $t) = build_email($g, "📊 Bilan dans 7 jours", '#F59E0B', 'BILAN J-7', "La deadline de bilan approche. Finalise et transmets ton compte-rendu.");
            if (reminder_send($pdo, $g, 'report_7d', $s, $h, $t)) { echo "  ✓ J-7 bilan #{$g['id']}\n"; $sent_total++; }
        }
        if ($diff < 0) {
            list($s, $h, $t) = build_email($g, "❌ Bilan en retard", '#7F1D1D', 'BILAN RETARD', "Le bilan aurait dû être rendu. Risque de devoir rembourser : contacte vite le financeur.");
            if (reminder_send($pdo, $g, 'report_overdue', $s, $h, $t)) { echo "  ✓ Retard bilan #{$g['id']}\n"; $sent_total++; }
        }
    }
}

$elapsed = round(microtime(true) - $started, 2);
echo "\n[" . date('Y-m-d H:i:s') . "] Terminé en {$elapsed}s · {$sent_total} rappels envoyés\n";
exit(0);
