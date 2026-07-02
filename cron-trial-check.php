<?php
/**
 * AssoKit — CRON Verification fin d'essai 14j
 * À lancer 1x par jour (matin, 8h).
 *   /usr/bin/php /home/pura7044/public_html/cron-trial-check.php
 *
 * - status='trial' + trial_ends_at depasse → 'suspended' + email
 * - J-3 et J-1 → email rappel
 */
require_once __DIR__ . '/config.php';
@require_once __DIR__ . '/resend-helper.php';

$is_cli = (php_sapi_name() === 'cli') || defined('STDIN') || (empty($_SERVER['HTTP_HOST']) && empty($_SERVER['REMOTE_ADDR'])) || !empty($_SERVER['argv']);
$has_key = isset($_GET['key']) && defined('CRON_SECRET') && hash_equals(CRON_SECRET, $_GET['key']);
if (!$is_cli && !$has_key) { http_response_code(403); die('Forbidden'); }

echo "[" . date('Y-m-d H:i:s') . "] CRON trial-check demarre\n";

// 1. Trials expires → suspended + email
$stmt = $pdo->query("
    SELECT s.id AS sub_id, s.org_id, o.name AS org_name, o.trial_ends_at
    FROM subscriptions s
    JOIN organizations o ON o.id = s.org_id
    WHERE s.status = 'trial'
      AND o.trial_ends_at IS NOT NULL
      AND o.trial_ends_at < NOW()
      AND o.deleted_at IS NULL
");
$expired = $stmt->fetchAll();
echo "→ " . count($expired) . " essai(s) expire(s)\n";

$updated = 0;
foreach ($expired as $row) {
    try {
        $pdo->prepare("UPDATE subscriptions SET status='suspended', updated_at=NOW() WHERE id=?")
            ->execute([$row['sub_id']]);
        $updated++;
        echo "  ✓ Org #" . $row['org_id'] . " " . $row['org_name'] . " → suspended\n";

        $admins = $pdo->prepare("SELECT email, first_name FROM users WHERE org_id=? AND role='admin' AND is_active=1");
        $admins->execute([$row['org_id']]);
        if (function_exists('ak_asso_send_resend') && function_exists('ak_email_template_wrap')) {
            foreach ($admins->fetchAll() as $a) {
                if (!filter_var($a['email'], FILTER_VALIDATE_EMAIL)) continue;
                try {
                    $html = ak_email_template_wrap(
                        "Bonjour " . htmlspecialchars($a['first_name']) . ",",
                        "Votre essai gratuit de 14 jours sur AssoKit a pris fin aujourd'hui."
                        . "<br><br>Pour continuer avec <strong>" . htmlspecialchars($row['org_name']) . "</strong>, choisissez une formule sur la page Abonnement.",
                        "https://assokit.fr/abonnement", "Voir mon abonnement", "AssoKit"
                    );
                    ak_asso_send_resend($a['email'], "🎁 Votre essai AssoKit a pris fin", $html, null, null, 'AssoKit');
                } catch (Throwable $e) { error_log('[cron-trial-check] email expire: ' . $e->getMessage()); }
            }
        }
    } catch (Throwable $e) { error_log('[cron-trial-check] bascule: ' . $e->getMessage()); }
}

// 2. Rappels J-3 et J-1
foreach ([3, 1] as $days_left) {
    $stmt = $pdo->prepare("
        SELECT s.id AS sub_id, s.org_id, o.name AS org_name
        FROM subscriptions s
        JOIN organizations o ON o.id = s.org_id
        WHERE s.status = 'trial'
          AND o.deleted_at IS NULL
          AND DATE(o.trial_ends_at) = DATE(DATE_ADD(NOW(), INTERVAL ? DAY))
    ");
    $stmt->execute([$days_left]);
    $rs = $stmt->fetchAll();
    echo "→ J-" . $days_left . " : " . count($rs) . " rappel(s)\n";

    foreach ($rs as $row) {
        $admins = $pdo->prepare("SELECT email, first_name FROM users WHERE org_id=? AND role='admin' AND is_active=1");
        $admins->execute([$row['org_id']]);
        if (function_exists('ak_asso_send_resend') && function_exists('ak_email_template_wrap')) {
            foreach ($admins->fetchAll() as $a) {
                if (!filter_var($a['email'], FILTER_VALIDATE_EMAIL)) continue;
                try {
                    $title = $days_left === 1 ? "⏰ Votre essai se termine demain" : "⏰ Plus que " . $days_left . " jours d'essai gratuit";
                    $html = ak_email_template_wrap(
                        "Bonjour " . htmlspecialchars($a['first_name']) . ",",
                        "Plus que <strong>" . $days_left . " jour" . ($days_left > 1 ? 's' : '') . "</strong> avant la fin de votre essai pour <strong>" . htmlspecialchars($row['org_name']) . "</strong>.",
                        "https://assokit.fr/abonnement", "Choisir une formule", "AssoKit"
                    );
                    ak_asso_send_resend($a['email'], $title, $html, null, null, 'AssoKit');
                } catch (Throwable $e) { error_log('[cron-trial-check] rappel: ' . $e->getMessage()); }
            }
        }
    }
}

echo "[" . date('Y-m-d H:i:s') . "] termine. " . $updated . " sub(s) basculee(s)\n";
