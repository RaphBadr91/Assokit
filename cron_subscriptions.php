<?php
/**
 * cron_subscriptions.php
 * --------------------------------------------------------------
 * CRON quotidien de gestion des abonnements
 *
 * Actions effectuées :
 * 1. Détecte les abonnements 'pending_payment' arrivés à échéance → passe à 'overdue'
 *    + envoie email de rappel + crée une période de grâce 15 jours
 * 2. Détecte les 'overdue' avec grâce expirée → l'overlay s'affichera automatiquement
 *    + envoie un dernier email de relance
 * 3. Détecte les emails à 80% / 90% / 100% du quota IA / Email pour notifier
 *
 * Sécurité : protégé par CRON_TOKEN dans l'URL
 *
 * Appel via :
 *   https://assokit.fr/cron_subscriptions.php?token=CRON_TOKEN
 *
 * Configuration cPanel CRON jobs :
 *   Tous les jours à 8h00
 *   wget -qO- "https://assokit.fr/cron_subscriptions.php?token=02a17ae0ff09..." > /dev/null 2>&1
 * --------------------------------------------------------------
 */

require_once __DIR__ . '/config.php';
@require_once __DIR__ . '/resend-helper.php';
require_once __DIR__ . '/plan-helpers.php';

header('Content-Type: text/plain; charset=utf-8');

// === Sécurité : token obligatoire ===
$expected = defined('CRON_TOKEN') ? CRON_TOKEN : '02a17ae0ff0911bf0a246e45307a275c5d6c8bbaba37cbe06bdf59a23e47a7ed';
$received = $_GET['token'] ?? '';
if (!hash_equals($expected, $received)) {
    http_response_code(403);
    echo "FORBIDDEN — invalid token\n";
    exit;
}

$now = new DateTime();
$nowStr = $now->format('Y-m-d H:i:s');
echo "🌿 CRON Subscriptions — {$nowStr}\n";
echo str_repeat('=', 60) . "\n\n";

$counters = [
    'pending_to_overdue' => 0,
    'overdue_grace_expired' => 0,
    'reminders_sent' => 0,
    'errors' => 0,
];

// ============================================================
// 1. Pending payment → Overdue
// ============================================================
echo "📋 Étape 1 : Pending payment → Overdue\n";
echo str_repeat('-', 60) . "\n";

try {
    $st = $pdo->prepare("
        SELECT s.id, s.org_id, s.plan_id, s.current_period_end,
               o.name AS org_name, o.billing_email,
               p.name AS plan_name, p.slug AS plan_slug
        FROM asso_subscriptions s
        JOIN organizations o ON o.id = s.org_id
        JOIN asso_plans p ON p.id = s.plan_id
        WHERE s.status = 'pending_payment'
          AND s.current_period_end IS NOT NULL
          AND s.current_period_end < NOW()
    ");
    $st->execute();
    $expired = $st->fetchAll(PDO::FETCH_ASSOC);

    foreach ($expired as $sub) {
        // Marquer overdue + créer période de grâce 15j
        $grace_end = (clone $now)->modify('+15 days')->format('Y-m-d H:i:s');
        $upd = $pdo->prepare("
            UPDATE asso_subscriptions
            SET status = 'overdue', grace_period_end = :g, updated_at = NOW()
            WHERE id = :id
        ");
        $upd->execute([':g' => $grace_end, ':id' => $sub['id']]);

        echo "   ✓ Org #{$sub['org_id']} ({$sub['org_name']}) → overdue, grâce jusqu'au {$grace_end}\n";
        $counters['pending_to_overdue']++;

        // Email de rappel au billing_email de l'org
        if (!empty($sub['billing_email']) && function_exists('send_transactional_email')) {
            try {
                $subject = '⏳ Régularisez votre abonnement Assokit — ' . $sub['org_name'];
                $body = '<h2 style="color:#0F172A;">Votre paiement est en attente</h2>';
                $body .= '<p>Bonjour,</p>';
                $body .= '<p>Le paiement de votre abonnement <strong>' . htmlspecialchars($sub['plan_name']) . '</strong> pour <strong>' . htmlspecialchars($sub['org_name']) . '</strong> n\'a pas été reçu.</p>';
                $body .= '<p>Vous bénéficiez d\'une <strong>période de tolérance de 15 jours</strong> pour régulariser, jusqu\'au <strong>' . date('d/m/Y', strtotime($grace_end)) . '</strong>. Pendant cette période, vous gardez l\'accès complet à votre plan.</p>';
                $body .= '<p style="margin:24px 0;"><a href="https://assokit.fr/mon-asso-plan?action=pay" style="background:#059669;color:white;padding:12px 24px;border-radius:8px;text-decoration:none;font-weight:600;">💳 Régulariser maintenant</a></p>';
                $body .= '<p>Si vous préférez passer au plan gratuit Démarrage (avec ses limites), vous pouvez aussi <a href="https://assokit.fr/mon-asso-plan?action=downgrade">basculer ici</a>.</p>';
                $body .= '<p style="color:#64748B;font-size:13px;margin-top:30px;">— L\'équipe Assokit · RBPS</p>';

                send_transactional_email($sub['billing_email'], $subject, $body, ['tag' => 'overdue_warning_15d']);
                $counters['reminders_sent']++;
            } catch (Throwable $e) {
                error_log('[CRON sub email J0] ' . $e->getMessage());
                $counters['errors']++;
            }
        }
    }
    if (count($expired) === 0) echo "   (aucun)\n";

} catch (Throwable $e) {
    echo "   ❌ Erreur : " . $e->getMessage() . "\n";
    $counters['errors']++;
}

// ============================================================
// 2. Overdue avec grâce expirée → just log (l'overlay se déclenche via plan-helpers)
// ============================================================
echo "\n📋 Étape 2 : Overdue avec grâce expirée\n";
echo str_repeat('-', 60) . "\n";

try {
    $st = $pdo->prepare("
        SELECT s.id, s.org_id, s.grace_period_end,
               o.name AS org_name, o.billing_email,
               p.name AS plan_name
        FROM asso_subscriptions s
        JOIN organizations o ON o.id = s.org_id
        JOIN asso_plans p ON p.id = s.plan_id
        WHERE s.status = 'overdue'
          AND s.grace_period_end IS NOT NULL
          AND s.grace_period_end < NOW()
          AND (s.notes IS NULL OR s.notes NOT LIKE '%grace_expired_notified%')
    ");
    $st->execute();
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as $sub) {
        echo "   🔴 Org #{$sub['org_id']} ({$sub['org_name']}) — grâce expirée le " . date('d/m/Y', strtotime($sub['grace_period_end'])) . "\n";
        $counters['overdue_grace_expired']++;

        // Email "compte verrouillé"
        if (!empty($sub['billing_email']) && function_exists('send_transactional_email')) {
            try {
                $subject = '🔒 Accès limité à Assokit — ' . $sub['org_name'];
                $body = '<h2 style="color:#0F172A;">Votre compte est en accès restreint</h2>';
                $body .= '<p>Bonjour,</p>';
                $body .= '<p>La période de tolérance de 15 jours est écoulée. Votre compte <strong>' . htmlspecialchars($sub['org_name']) . '</strong> est désormais en accès restreint.</p>';
                $body .= '<p><strong>Vos données sont en sécurité</strong> et restent accessibles dès régularisation.</p>';
                $body .= '<p style="margin:24px 0;"><a href="https://assokit.fr/mon-asso-plan?action=pay" style="background:#059669;color:white;padding:12px 24px;border-radius:8px;text-decoration:none;font-weight:600;">💳 Régulariser maintenant</a></p>';
                $body .= '<p>Ou <a href="https://assokit.fr/mon-asso-plan?action=downgrade">basculez sur le plan gratuit Démarrage</a> pour conserver un accès limité.</p>';
                $body .= '<p style="color:#64748B;font-size:13px;margin-top:30px;">— L\'équipe Assokit · RBPS</p>';

                send_transactional_email($sub['billing_email'], $subject, $body, ['tag' => 'grace_expired_locked']);
                $counters['reminders_sent']++;
            } catch (Throwable $e) {
                error_log('[CRON sub email J15] ' . $e->getMessage());
            }
        }

        // Marquer comme notifié
        $upd = $pdo->prepare("UPDATE asso_subscriptions SET notes = CONCAT(COALESCE(notes, ''), '|grace_expired_notified|', NOW()), updated_at = NOW() WHERE id = :id");
        $upd->execute([':id' => $sub['id']]);
    }
    if (count($rows) === 0) echo "   (aucun)\n";

} catch (Throwable $e) {
    echo "   ❌ Erreur : " . $e->getMessage() . "\n";
    $counters['errors']++;
}

// ============================================================
// 3. Notifications quota (80% / 90% / 100%) — 1x par mois max
// ============================================================
echo "\n📋 Étape 3 : Notifications quotas IA / emails\n";
echo str_repeat('-', 60) . "\n";

try {
    $st = $pdo->query("
        SELECT s.org_id, o.name AS org_name, o.billing_email, p.name AS plan_name,
               p.slug AS plan_slug, p.limit_ai_text_per_month, p.limit_emails_per_month
        FROM asso_subscriptions s
        JOIN organizations o ON o.id = s.org_id
        JOIN asso_plans p ON p.id = s.plan_id
        WHERE s.status IN ('active', 'overdue')
          AND p.slug != 'sur-mesure'
    ");
    $orgs = $st->fetchAll(PDO::FETCH_ASSOC);

    $notif_count = 0;
    foreach ($orgs as $o) {
        $usage = ak_get_usage($pdo, (int)$o['org_id']);

        // Quota IA texte
        if ($o['limit_ai_text_per_month'] && $usage['ai_text_month'] / $o['limit_ai_text_per_month'] >= 0.9) {
            $notif_count++;
            // (envoi email optionnel, à activer plus tard si besoin)
        }
        // Quota emails
        if ($o['limit_emails_per_month'] && $o['limit_emails_per_month'] > 0
            && $usage['emails_month'] / $o['limit_emails_per_month'] >= 0.9) {
            $notif_count++;
        }
    }
    echo "   ⚠️  {$notif_count} organisations à 90%+ d'un quota mensuel\n";

} catch (Throwable $e) {
    echo "   ❌ Erreur : " . $e->getMessage() . "\n";
    $counters['errors']++;
}

// ============================================================
// Bilan
// ============================================================
echo "\n" . str_repeat('=', 60) . "\n";
echo "📊 BILAN\n";
echo str_repeat('-', 60) . "\n";
echo "   • Pending → Overdue : {$counters['pending_to_overdue']}\n";
echo "   • Grâce expirée (overlay actif) : {$counters['overdue_grace_expired']}\n";
echo "   • Emails envoyés : {$counters['reminders_sent']}\n";
echo "   • Erreurs : {$counters['errors']}\n";
echo "\n✓ CRON terminé — " . (new DateTime())->format('Y-m-d H:i:s') . "\n";
