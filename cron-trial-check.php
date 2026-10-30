<?php
/**
 * Assokit — CRON Verification fin d'essai gratuit (AK_TRIAL_DAYS, 14 j)
 * À LANCER TOUTES LES 10 MINUTES (et non plus 1x par jour). Les rappels
 * sont notés dans cron_envois : les relancer souvent n'en envoie pas
 * davantage, chaque échéance ne part qu'une fois.
 *   /usr/local/bin/php /home/pura7044/public_html/cron-trial-check.php
 *
 * - essai terminé (trial_ends_at < NOW()) → ancienne ligne 'suspended', association passée
 *   en Démarrage, formule technique d'un essai terminé (ak_trial_expire, la même bascule que cron-job-essai, au même
 *   instant) + e-mail de fin ; jamais pour un client payant, ni d'e-mail à une association
 *   résiliée ou suspendue à la main
 * - J-7, J-3, J-1 et J-0 → e-mail de rappel (essais en cours seulement)
 * - abonnement souscrit pendant l'essai (carte ou mandat SEPA enregistré, premier prélèvement à la fin
 *   de l'essai) : ni fin d'essai ni rappels « choisissez votre formule », mais UN rappel à J-3
 *   « premier prélèvement le … » ; après la fin de l'essai, l'abonnement est relu chez Stripe tant que
 *   le premier prélèvement n'est pas connu (webhook en retard)
 * - abonnement résilié arrivé à échéance → plan gratuit Démarrage (ak_sub_expire_cancelled)
 * - demande de création d'espace (app iPhone) sans décision depuis 24 h → un rappel au Fondateur
 *   (signup-review-helpers.php)
 * Idempotent : chaque fin d'essai et chaque rappel ne part qu'une fois (cron_envois).
 */
require_once __DIR__ . '/config.php';
@require_once __DIR__ . '/resend-helper.php';
// ak_asso_send_resend / ak_email_template_wrap : sans ces deux fonctions, aucun
// rappel ni e-mail de fin d'essai ne partait (function_exists() renvoyait false).
@require_once __DIR__ . '/projet-email-helpers.php';
@require_once __DIR__ . '/asso-invoice-email-helpers.php';
require_once __DIR__ . '/trial-helpers.php';
// Prix de la formule Assokit dans les e-mails (HT + TTC au taux de TVA de la plateforme)
require_once __DIR__ . '/includes-pricing.php';

// php-cgi (/usr/bin/php sur O2Switch) : PHP_SAPI vaut « cgi-fcgi », sans REQUEST_METHOD ; c'est aussi la ligne de commande
$is_cli = (PHP_SAPI === 'cli') || !isset($_SERVER['REQUEST_METHOD']);
$has_key = is_string($_GET['key'] ?? null) && defined('CRON_SECRET') && (string)CRON_SECRET !== '' && hash_equals((string)CRON_SECRET, $_GET['key']);
if (!$is_cli && !$has_key) { http_response_code(403); die('Forbidden'); }

echo "[" . date('Y-m-d H:i:s') . "] CRON trial-check demarre\n";

require_once __DIR__ . '/cron-lots.php';
if (!ak_lot_demarrer($pdo, 'trial-check')) exit(0);

// 0. Abonnements souscrits pendant l'essai dont l'essai est fini depuis plus de 2 heures : Stripe a dû
// prélever le premier mois (une heure environ après la fin de l'essai). Sans nouvelle (webhook en retard
// ou perdu), l'abonnement est relu chez Stripe : payé → actif ; refusé → en retard ; arrêté → note retirée
// (la fin d'essai normale suit). Passé AK_TRIAL_CARD_GRACE_DAYS jours sans réponse, la fin d'essai reprend.
if (isset(ak_db_columns($pdo, 'organizations')['stripe_trial_sub_id'])) {
    @require_once __DIR__ . '/stripe-config-helpers.php';
    @require_once __DIR__ . '/stripe-helpers.php';
    require_once __DIR__ . '/stripe-sync-helpers.php';
    if (function_exists('ak_stripe_get_secret_key') && ak_stripe_get_secret_key($pdo)) {
        $relus = 0;
        $q = $pdo->query("SELECT id, stripe_trial_sub_id FROM organizations
                          WHERE COALESCE(stripe_trial_sub_id, '') <> '' AND deleted_at IS NULL
                            AND trial_ends_at IS NOT NULL AND trial_ends_at < NOW() - INTERVAL 2 HOUR
                          ORDER BY trial_ends_at ASC LIMIT " . max(1, (int) ak_lot_place()));
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (!ak_lot_encore()) break;
            ak_lot_fait();
            try {
                $err = null;
                $sub = ak_stripe_fetch_subscription($pdo, (string) $row['stripe_trial_sub_id'], $err);
                if ($sub === false) { echo "  ! Org #" . $row['id'] . " : Stripe injoignable (" . $err . ")\n"; continue; }
                if ($sub === null) ak_stripe_trial_clear($pdo, (int) $row['id'], (string) $row['stripe_trial_sub_id']);
                else ak_handle_subscription_event($pdo, $sub, 'customer.subscription.updated');
                $relus++;
            } catch (Throwable $e) { error_log('[cron-trial-check] relecture abonnement d’essai org #' . $row['id'] . ': ' . get_class($e) . ' ' . $e->getMessage()); }
        }
        if ($relus) echo "→ " . $relus . " abonnement(s) souscrit(s) pendant l'essai relu(s) chez Stripe\n";
    }
}

// 1. Essais terminés → ancienne ligne « suspended », plan Démarrage, e-mail de fin
//
// Abonnement souscrit pendant l'essai (ak_org_no_trial_card_sql) : Stripe prélève le premier mois à la fin
// de l'essai ; l'association n'est pas basculée (le paiement termine l'essai), sauf sans nouvelle de Stripe
// AK_TRIAL_CARD_GRACE_DAYS jours après.
//
// Premier prélèvement SEPA en cours de vérification par la banque (ak_org_not_payment_pending_sql) :
// l'essai attend la réponse de la banque, il n'est pas terminé pendant ce temps.
//
// Un client payant n'est jamais concerné (son ancienne ligne a pu rester « trial ») : il ne
// reçoit pas « votre essai a pris fin ». Une association résiliée, ou suspendue à la main
// (suspended_reason renseigné par le cockpit ou l'appli), voit sa ligne basculer sans e-mail.
$has_reason = isset(ak_db_columns($pdo, 'organizations')['suspended_reason']);
$stmt = $pdo->query("
    SELECT s.id AS sub_id, s.org_id, o.name AS org_name, o.trial_ends_at, o.status AS org_status,
           " . ($has_reason ? "o.suspended_reason" : "NULL") . " AS suspended_reason
    FROM subscriptions s
    JOIN organizations o ON o.id = s.org_id
    WHERE s.status = 'trial'
      AND o.trial_ends_at IS NOT NULL
      AND o.trial_ends_at < NOW()
      AND o.deleted_at IS NULL
      AND " . ak_org_not_payment_pending_sql($pdo) . "
      AND " . ak_org_no_trial_card_sql($pdo) . "
      AND NOT EXISTS (SELECT 1 FROM asso_subscriptions a JOIN asso_plans p ON p.id = a.plan_id
                      WHERE a.org_id = o.id AND " . ak_paid_sub_sql($pdo, 'a', 'p') . ")
    ORDER BY o.trial_ends_at ASC
    LIMIT " . (int) ak_lot_place());
$expired = $stmt->fetchAll();
echo "→ " . count($expired) . " essai(s) expire(s)\n";

$updated = 0;
foreach ($expired as $row) {
    if (!ak_lot_encore()) break;
    ak_lot_fait();
    try {
        $pdo->prepare("UPDATE subscriptions SET status='suspended'"
                      . (isset(ak_db_columns($pdo, 'subscriptions')['updated_at']) ? ", updated_at=NOW()" : "")
                      . " WHERE id=?")
            ->execute([$row['sub_id']]);
        $updated++;
        // Statut lu AVANT la bascule : « trial » (ou « suspended » posé par la fin d'essai, sans motif) = fin naturelle
        $by_hand = $row['org_status'] === 'cancelled'
                || ($row['org_status'] === 'suspended' && trim((string) $row['suspended_reason']) !== '');
        ak_trial_expire($pdo, (int) $row['org_id']);
        echo "  ✓ Org #" . $row['org_id'] . " " . $row['org_name'] . " → suspended" . ($by_hand ? " (sans e-mail : " . $row['org_status'] . " à la main)" : "") . "\n";
        if ($by_hand) continue;

        // Une seule fois par fin d'essai, même si l'association a plusieurs anciennes lignes
        $fin = date('Y-m-d', strtotime((string) $row['trial_ends_at']));
        if (!ak_envoi_reserver($pdo, 'trial-check', (int) $row['org_id'], 'fin:' . $fin)) continue;
        // La durée n'est plus annoncée : un essai prolongé ou ouvert par le fondateur ne dure pas AK_TRIAL_DAYS jours
        $quand = $fin === date('Y-m-d') ? "aujourd'hui" : "le " . date('d/m/Y', strtotime((string) $row['trial_ends_at']));

        $admins = $pdo->prepare("SELECT email, first_name FROM users WHERE org_id=? AND role='admin' AND is_active=1");
        $admins->execute([$row['org_id']]);
        if (function_exists('ak_asso_send_resend') && function_exists('ak_email_template_wrap')) {
            foreach ($admins->fetchAll() as $a) {
                if (!filter_var($a['email'], FILTER_VALIDATE_EMAIL)) continue;
                try {
                    $html = ak_email_template_wrap(
                        "Bonjour " . htmlspecialchars($a['first_name']) . ",",
                        "Votre essai gratuit sur Assokit a pris fin " . $quand . "."
                        . "<br><br>Les données de <strong>" . htmlspecialchars($row['org_name']) . "</strong> sont conservées."
                        . "<br><br>Pour continuer avec toutes les fonctionnalités, choisissez votre formule : <strong>Assokit</strong> à "
                        . htmlspecialchars(ak_offer_price($pdo)['label']) . ", sans engagement, ou <strong>Sur-mesure</strong> sur devis.",
                        "https://assokit.fr/mon-asso-plan", "Choisir ma formule", "Assokit"
                    );
                    ak_asso_send_resend($a['email'], "🎁 Votre essai Assokit a pris fin", $html, null, null, 'Assokit');
                } catch (Throwable $e) { error_log('[cron-trial-check] email expire: ' . $e->getMessage()); }
            }
        }
    } catch (Throwable $e) { error_log('[cron-trial-check] bascule: ' . $e->getMessage()); }
}

// 1 bis. Essais terminés sans ancienne ligne « trial » (association créée hors inscription,
// suspendue puis réactivée, ligne d'essai annulée à tort…) : même bascule, sans e-mail.
// cron-job-essai fait le même rattrapage ; ici, il a lieu dans les 10 minutes.
$rattrapes = 0;
foreach (ak_trial_expired_org_ids($pdo, max(1, (int) ak_lot_place())) as $oid) {
    if (!ak_lot_encore()) break;
    ak_lot_fait();
    try {
        if (ak_trial_expire($pdo, $oid)) $rattrapes++;
    } catch (Throwable $e) { error_log('[cron-trial-check] rattrapage org #' . $oid . ': ' . get_class($e)); }
}
if ($rattrapes) echo "→ " . $rattrapes . " fin(s) d'essai rattrapée(s) (sans ancienne ligne)\n";

// 1 ter. Abonnements résiliés arrivés à échéance → plan gratuit Démarrage (comme une fin d'essai,
// sans e-mail : la date a été annoncée à la résiliation). La page la fait aussi à la première visite.
if (ak_lot_encore()) {
    $resilies = ak_sub_expire_cancelled($pdo, null, max(1, (int) ak_lot_place()));
    if ($resilies) {
        ak_lot_fait($resilies);
        echo "→ " . $resilies . " abonnement(s) résilié(s) passé(s) au plan Démarrage\n";
    }
}

// 2. Rappels J-7, J-3, J-1 et J-0
//
// J-7 et J-0 viennent de cron-job-essai, qui envoyait les mêmes rappels
// en parallèle depuis organizations : une association à J-3 recevait
// donc deux e-mails. Ce cron-ci est désormais le seul à les envoyer, à
// partir de l'ancienne table subscriptions (une ligne « trial » par essai :
// ak_legacy_sub_trial la crée quand le fondateur ouvre ou prolonge un essai).
// Le bandeau, lui, lit ak_org_plan_state(), comme les badges.
//
// Ils sont DÉPLACÉS et non supprimés : ne garder que J-3 et J-1 aurait
// fait disparaître sans bruit l'alerte d'une semaine avant et celle du
// dernier jour.
// Seuls les essais EN COURS sont relancés : ni une association suspendue ou résiliée à la main,
// ni un client payant dont l'ancienne ligne serait restée « trial », ni une association dont le
// premier prélèvement SEPA est en cours de vérification (elle a déjà payé : « choisissez votre
// formule » l'inviterait à payer une seconde fois), ni une association qui a souscrit pendant l'essai
// (carte enregistrée : elle reçoit à la place le rappel « premier prélèvement le … », section 2 bis).
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
          AND o.status NOT IN ('suspended', 'cancelled')
          AND o.trial_ends_at >= NOW()
          AND DATE(o.trial_ends_at) = DATE(DATE_ADD(NOW(), INTERVAL ? DAY))
          AND " . ak_org_not_payment_pending_sql($pdo) . "
          AND " . (isset(ak_db_columns($pdo, 'organizations')['stripe_trial_sub_id']) ? "COALESCE(o.stripe_trial_sub_id, '') = ''" : "1") . "
          AND NOT EXISTS (SELECT 1 FROM asso_subscriptions a JOIN asso_plans p ON p.id = a.plan_id
                          WHERE a.org_id = o.id AND " . ak_paid_sub_sql($pdo, 'a', 'p') . ")
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
                        $corps . "<strong>" . htmlspecialchars($row['org_name']) . "</strong>."
                        . "<br><br>Pour continuer sans interruption, choisissez votre formule : <strong>Assokit</strong> à "
                        . htmlspecialchars(ak_offer_price($pdo)['label']) . ", sans engagement. Vos données et réglages sont conservés.",
                        "https://assokit.fr/mon-asso-plan", "Choisir une formule", "Assokit"
                    );
                    ak_asso_send_resend($a['email'], $title, $html, null, null, 'Assokit');
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

// 2 bis. Abonnement souscrit pendant l'essai : UN rappel au plus 3 jours avant le premier prélèvement (date et
// montant, annulation possible sans frais d'ici là). Fenêtre « dans les 3 jours » et non « à J-3 pile » : une
// souscription faite entre 48 et 72 heures avant la fin de l'essai, ou un cron arrêté ce jour-là, n'en privait
// pas l'association. Souscription faite dans ces 3 jours : l'e-mail de confirmation (« … est souscrit », date et
// montant) en tient lieu, pas de second e-mail. Clé : la date du prélèvement (une prolongation de l'essai déplace
// le prélèvement, et donc le rappel).
if (isset(ak_db_columns($pdo, 'organizations')['stripe_trial_sub_id']) && ak_lot_encore()) {
    $charge_sql = "COALESCE(o.stripe_trial_charge_at, o.trial_ends_at)";
    $carnet = (bool) ak_db_columns($pdo, 'cron_envois');
    $tax_col = isset(ak_db_columns($pdo, 'organizations')['stripe_trial_charge_tax_cents']) ? "o.stripe_trial_charge_tax_cents" : "NULL";
    $stmt = $pdo->query("
        SELECT o.id AS org_id, o.name AS org_name, o.trial_ends_at, o.stripe_trial_sub_id, o.stripe_trial_charge_at, o.stripe_trial_charge_cents,
               $tax_col AS stripe_trial_charge_tax_cents, o.stripe_trial_pm_type
        FROM organizations o
        WHERE COALESCE(o.stripe_trial_sub_id, '') <> ''
          AND o.deleted_at IS NULL
          AND o.status NOT IN ('suspended', 'cancelled')
          AND $charge_sql > NOW()
          AND $charge_sql <= DATE_ADD(NOW(), INTERVAL 3 DAY)"
        . ($carnet ? "
          AND NOT EXISTS (SELECT 1 FROM cron_envois e WHERE e.job = 'trial-check' AND e.org_id = o.id
                          AND e.cle = CONCAT('prelev3:', DATE_FORMAT($charge_sql, '%Y-%m-%d')))
          AND NOT EXISTS (SELECT 1 FROM cron_envois e WHERE e.job = 'stripe' AND e.org_id = o.id
                          AND e.cle = LEFT(CONCAT('souscrit:', o.stripe_trial_sub_id), 60)
                          AND e.sent_at >= DATE_SUB($charge_sql, INTERVAL 3 DAY))" : "") . "
        ORDER BY $charge_sql ASC
        LIMIT " . (int) ak_lot_place());
    $rs = $stmt->fetchAll();
    echo "→ Premier prélèvement dans les 3 jours : " . count($rs) . " rappel(s)\n";
    foreach ($rs as $row) {
        if (!ak_lot_encore()) break;
        ak_lot_fait();
        $at = (string) ($row['stripe_trial_charge_at'] ?: $row['trial_ends_at']);
        $cle = 'prelev3:' . date('Y-m-d', strtotime($at));
        if (!ak_envoi_reserver($pdo, 'trial-check', (int) $row['org_id'], $cle)) continue;
        $date = date('d/m/Y', strtotime($at));
        // « TTC » d'après l'abonnement souscrit (TVA notée à la souscription), pas l'interrupteur de TVA du jour
        $amount = ak_trial_card_amount($pdo, [
            'charge_cents' => $row['stripe_trial_charge_cents'] !== null ? (int) $row['stripe_trial_charge_cents'] : null,
            'charge_tax_cents' => $row['stripe_trial_charge_tax_cents'] !== null ? (int) $row['stripe_trial_charge_tax_cents'] : null]);
        $moyen = $row['stripe_trial_pm_type'] === 'sepa_debit' ? 'sur votre compte (mandat SEPA enregistré)' : 'sur la carte enregistrée';
        // E-mail de facturation (adresse de facturation et administrateurs), comme les autres e-mails d'abonnement
        $parti = false;
        try {
            $parti = ak_stripe_notify_org($pdo, (int) $row['org_id'], '📅 Premier prélèvement de votre abonnement Assokit le ' . $date,
                '<p>Bonjour,</p><p>Votre essai gratuit se termine bientôt pour « ' . htmlspecialchars($row['org_name']) . ' », et votre abonnement Assokit est déjà souscrit.</p>'
                . '<p>Le premier prélèvement' . ($amount !== '' ? ' de <strong>' . htmlspecialchars($amount) . '</strong>' : '') . ' aura lieu le <strong>' . htmlspecialchars($date) . '</strong> ' . $moyen
                . ', puis chaque mois à la même date. Vous n’avez rien à faire.</p>'
                . '<p>Vous pouvez encore annuler sans frais avant cette date, depuis la page « Mon abonnement » : aucun montant ne sera prélevé.</p>',
                'Voir mon abonnement', 'https://assokit.fr/mon-asso-plan');
        } catch (Throwable $e) { error_log('[cron-trial-check] rappel prélèvement: ' . $e->getMessage()); }
        if (!$parti) {
            ak_envoi_rendre($pdo, 'trial-check', (int) $row['org_id'], $cle);
            echo "  ! Org #" . $row['org_id'] . " : aucun e-mail parti, rappel de prélèvement reporté\n";
        }
    }
}

// 3. Demandes de création d'espace faites dans l'app iPhone, sans décision depuis 24 h : UN rappel au
// Fondateur par demande (e-mail avec des liens Valider / Refuser neufs, et cloche). Le carnet
// cron_envois (clé « rappel-24h ») garantit qu'il ne part qu'une fois, même toutes les 10 minutes.
if (ak_lot_encore()) {
    try {
        require_once __DIR__ . '/signup-review-helpers.php';
        $rappels = ak_signup_review_remind_due($pdo, max(1, (int) ak_lot_place()));
        if ($rappels) ak_lot_fait($rappels);
        echo "→ " . $rappels . " rappel(s) de demande d'espace à valider (24 h)\n";
    } catch (Throwable $e) { error_log('[cron-trial-check] rappels demandes: ' . get_class($e)); }
}

echo $updated . " abonnement(s) bascule(s) en suspendu\n";
ak_lot_terminer($pdo);
