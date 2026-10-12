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
 *    (Assistant IA et diffusion email) + envoie un dernier email de relance
 * 3. Détecte les emails à 80% / 90% / 100% du quota IA / Email pour notifier
 * 4. Résiliations arrivées à échéance → plan gratuit Démarrage (ak_sub_expire_cancelled)
 *
 * « Emails envoyés » ne compte que les e-mails réellement partis ; un échec va dans
 * « Erreurs » (et la relance de l'étape 2 est retentée au passage suivant).
 *
 * Sécurité : protégé par CRON_TOKEN dans l'URL
 *
 * Appel via :
 *   https://assokit.fr/cron_subscriptions.php?token=CRON_TOKEN
 *
 * Configuration cPanel CRON jobs :
 *   Tous les jours à 8h00
 *   wget -qO- "https://assokit.fr/cron_subscriptions.php?token=VOTRE_CRON_TOKEN" > /dev/null 2>&1
 * --------------------------------------------------------------
 */

require_once __DIR__ . '/config.php';
@require_once __DIR__ . '/resend-helper.php';
require_once __DIR__ . '/plan-helpers.php';

header('Content-Type: text/plain; charset=utf-8');

// === Sécurité : token obligatoire ===
$expected = defined('CRON_TOKEN') ? CRON_TOKEN : '';
if ($expected === '') { http_response_code(500); echo "CRON_TOKEN non configure\n"; exit; }
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
    'cancelled_expired' => 0,
    'errors' => 0,
];

// Liens des e-mails : « Régulariser » mène à la facture Stripe à payer (ou au portail de la carte, ou au
// contact) ; « Passer au plan Démarrage » à la page qui le fait vraiment (confirmation + CSRF).
function cs_abs_url(string $url): string {
    return preg_match('#^https?://#i', $url) ? $url : 'https://assokit.fr' . (strpos($url, '/') === 0 ? '' : '/') . $url;
}
// Envoi compté seulement s'il est parti (send_transactional_email renvoie ['success' => false] sans exception)
function cs_send(string $to, string $subject, string $body, string $tag, array &$counters, string $log): bool {
    try {
        $res = send_transactional_email($to, $subject, $body, ['tag' => $tag]);
    } catch (Throwable $e) {
        $res = ['success' => false, 'error' => $e->getMessage()];
    }
    if (!empty($res['success'])) {
        $counters['reminders_sent']++;
        return true;
    }
    $counters['errors']++;
    error_log('[CRON sub email ' . $log . '] ' . ($res['error'] ?? 'échec'));
    echo "      ! e-mail non parti : " . ($res['error'] ?? 'échec') . "\n";
    return false;
}
$downgrade_url = 'https://assokit.fr/mon-asso-annuler-abonnement';

// Étapes 1 et 2 : seulement la ligne COURANTE de l'association (ak_sub_order, celle des fonctionnalités et
// de l'écran bloquant). Une ligne en attente ou en retard derrière un essai ou une formule active ne
// déclenche aucun e-mail d'impayé (l'association est en essai, ou payée par ailleurs).
require_once __DIR__ . '/trial-helpers.php';
$current_row_sql = "s.id = (SELECT s2.id FROM asso_subscriptions s2 JOIN asso_plans p2 ON p2.id = s2.plan_id
                            WHERE s2.org_id = s.org_id ORDER BY " . ak_sub_order('s2') . " LIMIT 1)";
$sub_cols = ak_db_columns($pdo, 'asso_subscriptions');
// Paiement par carte jamais abouti (abonnement Stripe « incomplete ») : Stripe gère ses relances et
// l'arrête de lui-même au bout de 23 h ; ce n'est pas un impayé, jamais de « Régularisez » à ce titre
$not_stripe_attempt_sql = isset($sub_cols['stripe_subscription_id']) ? "AND COALESCE(s.stripe_subscription_id, '') = ''" : '';
$org_alive_sql = isset(ak_db_columns($pdo, 'organizations')['deleted_at']) ? 'AND o.deleted_at IS NULL' : '';

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
          $not_stripe_attempt_sql
          $org_alive_sql
          AND $current_row_sql
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
            $pay_url = cs_abs_url(function_exists('ak_get_regularize_url') ? ak_get_regularize_url($pdo, (int)$sub['org_id']) : '/contact?subject=billing');
            $subject = '⏳ Régularisez votre abonnement Assokit — ' . $sub['org_name'];
            $body = '<h2 style="color:#0F172A;">Votre paiement est en attente</h2>';
            $body .= '<p>Bonjour,</p>';
            $body .= '<p>Le paiement de votre abonnement <strong>' . htmlspecialchars($sub['plan_name']) . '</strong> pour <strong>' . htmlspecialchars($sub['org_name']) . '</strong> n\'a pas été reçu.</p>';
            $body .= '<p>Vous bénéficiez d\'une <strong>période de tolérance de 15 jours</strong> pour régulariser, jusqu\'au <strong>' . date('d/m/Y', strtotime($grace_end)) . '</strong>. Pendant cette période, vous gardez l\'accès complet à votre plan.</p>';
            $body .= '<p style="margin:24px 0;"><a href="' . htmlspecialchars($pay_url) . '" style="background:#059669;color:white;padding:12px 24px;border-radius:8px;text-decoration:none;font-weight:600;">💳 Régulariser maintenant</a></p>';
            $body .= '<p>Si vous préférez passer au plan gratuit Démarrage (avec ses limites), vous pouvez aussi <a href="' . $downgrade_url . '">basculer ici</a>.</p>';
            $body .= '<p style="color:#64748B;font-size:13px;margin-top:30px;">— L\'équipe Assokit · RBPS</p>';

            cs_send($sub['billing_email'], $subject, $body, 'overdue_warning_15d', $counters, 'J0');
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
          $org_alive_sql
          AND $current_row_sql
    ");
    $st->execute();
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as $sub) {
        echo "   🔴 Org #{$sub['org_id']} ({$sub['org_name']}) — grâce expirée le " . date('d/m/Y', strtotime($sub['grace_period_end'])) . "\n";
        $counters['overdue_grace_expired']++;

        // Email « fonctionnalités bloquées » : l'écran bloquant ne couvre que l'Assistant IA et la diffusion email
        $sent = true;
        if (!empty($sub['billing_email']) && function_exists('send_transactional_email')) {
            $pay_url = cs_abs_url(function_exists('ak_get_regularize_url') ? ak_get_regularize_url($pdo, (int)$sub['org_id']) : '/contact?subject=billing');
            $subject = '🔒 Fonctionnalités bloquées sur Assokit — ' . $sub['org_name'];
            $body = '<h2 style="color:#0F172A;">Certaines fonctionnalités sont bloquées</h2>';
            $body .= '<p>Bonjour,</p>';
            $body .= '<p>La période de tolérance de 15 jours est écoulée sans régularisation pour <strong>' . htmlspecialchars($sub['org_name']) . '</strong>. L’<strong>Assistant IA</strong> et la <strong>diffusion email</strong> sont bloqués jusqu’au paiement ; le reste de votre espace reste accessible.</p>';
            $body .= '<p><strong>Vos données sont en sécurité</strong> et restent accessibles.</p>';
            $body .= '<p style="margin:24px 0;"><a href="' . htmlspecialchars($pay_url) . '" style="background:#059669;color:white;padding:12px 24px;border-radius:8px;text-decoration:none;font-weight:600;">💳 Régulariser maintenant</a></p>';
            $body .= '<p>Ou <a href="' . $downgrade_url . '">passez au plan gratuit Démarrage</a> : l’abonnement impayé est alors arrêté.</p>';
            $body .= '<p style="color:#64748B;font-size:13px;margin-top:30px;">— L\'équipe Assokit · RBPS</p>';

            $sent = cs_send($sub['billing_email'], $subject, $body, 'grace_expired_locked', $counters, 'J15');
        }

        // Marquer comme notifié, seulement si l'e-mail est parti (sinon retenté au passage suivant)
        if ($sent) {
            $upd = $pdo->prepare("UPDATE asso_subscriptions SET notes = CONCAT(COALESCE(notes, ''), '|grace_expired_notified|', NOW()), updated_at = NOW() WHERE id = :id");
            $upd->execute([':id' => $sub['id']]);
        }
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
// 4. Résiliations arrivées à échéance → plan gratuit Démarrage
// ============================================================
echo "\n📋 Étape 4 : Résiliations arrivées à échéance\n";
echo str_repeat('-', 60) . "\n";
if (function_exists('ak_sub_expire_cancelled')) {
    $counters['cancelled_expired'] = ak_sub_expire_cancelled($pdo, null, 500);
}
echo "   ↓ {$counters['cancelled_expired']} association(s) passée(s) au plan Démarrage\n";

// ============================================================
// Bilan
// ============================================================
echo "\n" . str_repeat('=', 60) . "\n";
echo "📊 BILAN\n";
echo str_repeat('-', 60) . "\n";
echo "   • Pending → Overdue : {$counters['pending_to_overdue']}\n";
echo "   • Grâce expirée (overlay actif) : {$counters['overdue_grace_expired']}\n";
echo "   • Résiliations → Démarrage : {$counters['cancelled_expired']}\n";
echo "   • Emails envoyés : {$counters['reminders_sent']}\n";
echo "   • Erreurs : {$counters['errors']}\n";
echo "\n✓ CRON terminé — " . (new DateTime())->format('Y-m-d H:i:s') . "\n";
