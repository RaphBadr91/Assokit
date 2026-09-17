<?php
/**
 * AssoKit — CRON Verification fin d'essai 14j
 * À LANCER TOUTES LES 10 MINUTES (et non plus 1x par jour). Les rappels
 * sont notés dans cron_envois : les relancer souvent n'en envoie pas
 * davantage, chaque échéance ne part qu'une fois.
 *   /usr/bin/php /home/pura7044/public_html/cron-trial-check.php
 *
 * - status='trial' + trial_ends_at depasse → 'suspended' + email
 * - J-3 et J-1 → email rappel
 */
require_once __DIR__ . '/config.php';
@require_once __DIR__ . '/resend-helper.php';

$is_cli = (PHP_SAPI === 'cli');
$has_key = isset($_GET['key']) && defined('CRON_SECRET') && hash_equals(CRON_SECRET, $_GET['key']);
if (!$is_cli && !$has_key) { http_response_code(403); die('Forbidden'); }

echo "[" . date('Y-m-d H:i:s') . "] CRON trial-check demarre\n";

require_once __DIR__ . '/cron-lots.php';
if (!ak_lot_demarrer($pdo, 'trial-check')) exit(0);

// 1. Trials expires → suspended + email
$stmt = $pdo->query("
    SELECT s.id AS sub_id, s.org_id, o.name AS org_name, o.trial_ends_at
    FROM subscriptions s
    JOIN organizations o ON o.id = s.org_id
    WHERE s.status = 'trial'
      AND o.trial_ends_at IS NOT NULL
      AND o.trial_ends_at < NOW()
      AND o.deleted_at IS NULL
    ORDER BY o.trial_ends_at ASC
    LIMIT " . (int) ak_lot_place());
$expired = $stmt->fetchAll();
echo "→ " . count($expired) . " essai(s) expire(s)\n";

$updated = 0;
foreach ($expired as $row) {
    if (!ak_lot_encore()) break;
    ak_lot_fait();
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

// 2. Rappels J-7, J-3, J-1 et J-0
//
// J-7 et J-0 viennent de cron-job-essai, qui envoyait les mêmes rappels
// en parallèle depuis organizations : une association à J-3 recevait
// donc deux e-mails. Ce cron-ci est désormais le seul à les envoyer,
// parce que c'est subscriptions que lit includes-layout pour décider
// d'afficher le bandeau d'essai — c'est donc elle qui fait foi sur le
// cycle de vie de l'essai.
//
// Ils sont DÉPLACÉS et non supprimés : ne garder que J-3 et J-1 aurait
// fait disparaître sans bruit l'alerte d'une semaine avant et celle du
// dernier jour.
foreach ([7, 3, 1, 0] as $days_left) {
    // trial_ends_at est ramenée : elle sert de clé de dédoublonnage.
    // Sans elle, la clé serait « j3: » pour tout le monde et le premier
    // rappel envoyé bloquerait tous les autres.
    $stmt = $pdo->prepare("
        SELECT s.id AS sub_id, s.org_id, o.name AS org_name, o.trial_ends_at
        FROM subscriptions s
        JOIN organizations o ON o.id = s.org_id
        WHERE s.status = 'trial'
          AND o.deleted_at IS NULL
          AND DATE(o.trial_ends_at) = DATE(DATE_ADD(NOW(), INTERVAL ? DAY))
        ORDER BY s.org_id
        LIMIT " . (int) ak_lot_place() . "
    ");
    $stmt->execute([$days_left]);
    $rs = $stmt->fetchAll();
    echo "→ J-" . $days_left . " : " . count($rs) . " rappel(s)\n";

    foreach ($rs as $row) {
        if (!ak_lot_encore()) break 2;
        ak_lot_fait();

        // Ce cron ne notait rien : il comptait sur le fait de ne tourner
        // qu'une fois par jour. Lancé toutes les dix minutes pour tenir
        // 10 000 associations, il enverrait le même rappel 96 fois.
        //
        // La clé porte la date d'échéance et non celle du jour : le
        // rappel J-3 d'un essai donné n'est dû qu'une fois, quelle que
        // soit l'heure à laquelle le cron passe.
        $cle = 'j' . $days_left . ':' . date('Y-m-d', strtotime((string) $row['trial_ends_at']));
        if (!ak_envoi_reserver($pdo, 'trial-check', (int) $row['org_id'], $cle)) {
            continue;   // déjà parti
        }
        $parti = false;

        $admins = $pdo->prepare("SELECT email, first_name FROM users WHERE org_id=? AND role='admin' AND is_active=1");
        $admins->execute([$row['org_id']]);
        if (function_exists('ak_asso_send_resend') && function_exists('ak_email_template_wrap')) {
            foreach ($admins->fetchAll() as $a) {
                if (!filter_var($a['email'], FILTER_VALIDATE_EMAIL)) continue;
                try {
                    // Le dernier jour et la veille se disent, ils ne se
                    // comptent pas : « plus que 0 jour » ne veut rien dire.
                    if ($days_left === 0) {
                        $title  = "⏰ Votre essai se termine aujourd'hui";
                        $corps  = "C'est le <strong>dernier jour</strong> de votre essai pour ";
                    } elseif ($days_left === 1) {
                        $title  = "⏰ Votre essai se termine demain";
                        $corps  = "Votre essai se termine <strong>demain</strong> pour ";
                    } else {
                        $title  = "⏰ Plus que " . $days_left . " jours d'essai gratuit";
                        $corps  = "Plus que <strong>" . $days_left . " jours</strong> avant la fin de votre essai pour ";
                    }
                    $html = ak_email_template_wrap(
                        "Bonjour " . htmlspecialchars($a['first_name']) . ",",
                        $corps . "<strong>" . htmlspecialchars($row['org_name']) . "</strong>.",
                        "https://assokit.fr/abonnement", "Choisir une formule", "AssoKit"
                    );
                    ak_asso_send_resend($a['email'], $title, $html, null, null, 'AssoKit');
                    $parti = true;
                } catch (Throwable $e) { error_log('[cron-trial-check] rappel: ' . $e->getMessage()); }
            }
        }

        // Rien n'est parti : on rend la réservation, sinon une panne de
        // messagerie d'une minute ferait sauter le rappel pour de bon.
        if (!$parti) {
            ak_envoi_rendre($pdo, 'trial-check', (int) $row['org_id'], $cle);
            echo "  ! Org #" . $row['org_id'] . " : aucun e-mail parti, rappel reporté\n";
        }
    }
}

echo $updated . " abonnement(s) bascule(s) en suspendu\n";
ak_lot_terminer($pdo);
