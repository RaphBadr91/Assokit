<?php
/**
 * ============================================================
 * ASSOKIT — cron-job-renouvellements.php (v3)
 * Job : Renouvellement mensuel des abonnements payés hors Stripe
 * ============================================================
 * Deux échéanciers :
 *  - comptes historiques : anciennes colonnes organizations
 *    (monthly_price_cents, current_period_end) ; l'ancien montant
 *    mensuel doit être le prix de la formule ;
 *  - abonnements actuels (formule passée sur « paiement sur facture »
 *    par le Fondateur : conversion d'essai, Abonnements) : ligne
 *    courante d'asso_subscriptions, échéance current_period_end (à
 *    défaut celle d'organizations). Sans échéance, l'abonnement est
 *    signalé dans « errors » (à facturer à la main), jamais oublié.
 * Rien n'est facturé sans que l'abonnement RÉEL (asso_subscriptions /
 * asso_plans) le confirme : formule courante payante, active, réglée
 * hors Stripe, ni résiliée, ni en essai, ni offerte, ni Sur-mesure
 * (facturé à part), mensuelle.
 * Montant : asso_plans.price_cents est le prix HT (« 49,99 € HT/mois ») ;
 * TVA = ak_manual_invoice_vat_rate() (includes-pricing.php : taux de la
 * plateforme, celui de Stripe, si la société est assujettie), TTC = HT
 * + TVA calculé par ak_create_invoice.
 * Sinon : aucune facture, et la raison figure dans le détail du run
 * (« ignorees » si rien n'est dû, « errors » si le Fondateur doit
 * trancher : montant différent, formule annuelle, formule gratuite
 * jamais choisie par personne, etc.).
 *
 * La facture est une vraie facture Assokit, la même que celles du
 * cockpit Factures (ak_create_invoice) : numéro de la séquence unique
 * AK-AAAA-NNNNNN, HT / TVA / TTC, émetteur et client figés, PDF.
 * Échéance à 15 jours de l'ÉMISSION. Une échéance dépassée de
 * plusieurs mois ne déclenche pas de rafale de factures antidatées :
 * seule la période en cours est facturée, les périodes sautées sont
 * signalées dans « rattrapage ».
 * ============================================================
 */

if (!defined('CRON_EXECUTING')) {
    http_response_code(403);
    exit('Forbidden');
}

/** @var PDO $pdo */
/** @var int $runId */

require_once __DIR__ . '/trial-helpers.php';
require_once __DIR__ . '/company-helpers.php';
require_once __DIR__ . '/org-billing-helpers.php';
require_once __DIR__ . '/invoice-helpers.php';
require_once __DIR__ . '/includes-pricing.php';   // ak_manual_invoice_vat_rate()

$stats = [
    'processed' => 0,
    'succeeded' => 0,
    'failed'    => 0,
    'emails'    => 0,
    'details'   => ['invoices_created' => 0, 'errors' => [], 'ignorees' => [], 'rattrapage' => []],
];

$today = date('Y-m-d');
$subCols = ak_db_columns($pdo, 'asso_subscriptions');
// Ne pas facturer manuellement les organisations payant via Stripe (évite la double facturation)
$notStripeSql = "o.id NOT IN (
          SELECT s.org_id FROM asso_subscriptions s
          WHERE s.payment_mode = 'stripe' AND s.status <> 'cancelled' AND s.org_id IS NOT NULL
      )";

// ----- 1) Échéancier historique : orgas dont la période (organizations) est échue
$orgs = $pdo->query("
    SELECT
        o.id                   AS org_id,
        o.name                 AS org_name,
        o.monthly_price_cents,
        o.current_period_end
    FROM organizations o
    WHERE o.status = 'active'
      AND o.current_period_end IS NOT NULL
      AND o.current_period_end <= CURDATE()
      AND o.monthly_price_cents > 0
      AND o.deleted_at IS NULL
      AND o.slug NOT LIKE 'demo-%'
      AND $notStripeSql
    ORDER BY o.current_period_end ASC
    LIMIT 500
")->fetchAll();
foreach ($orgs as &$o) { $o['legacy'] = true; }
unset($o);

// ----- 2) Abonnements actuels payés sur facture (hors échéancier historique) : ligne courante
// d'asso_subscriptions active, sur une formule à prix mensuel, sans abonnement Stripe. L'échéance
// (ligne, à défaut organizations) et le reste des conditions sont vérifiés plus bas.
if (isset($subCols['payment_mode'])) {
    try {
        $curRowSql = "s.id = (SELECT s2.id FROM asso_subscriptions s2 JOIN asso_plans p2 ON p2.id = s2.plan_id
                              WHERE s2.org_id = s.org_id ORDER BY " . ak_sub_order('s2') . " LIMIT 1)";
        $stripeRowSql = isset($subCols['stripe_subscription_id']) ? "AND COALESCE(s.stripe_subscription_id, '') = ''" : '';
        $legacyIds = array_map(fn($r) => (int) $r['org_id'], $orgs);
        $cur = $pdo->query("
            SELECT o.id AS org_id, o.name AS org_name, NULL AS monthly_price_cents, o.current_period_end
            FROM organizations o
            JOIN asso_subscriptions s ON s.org_id = o.id
            JOIN asso_plans p ON p.id = s.plan_id
            WHERE o.status = 'active'
              AND o.deleted_at IS NULL
              AND o.slug NOT LIKE 'demo-%'
              AND NOT (COALESCE(o.monthly_price_cents, 0) > 0 AND o.current_period_end IS NOT NULL)
              AND s.status = 'active'
              AND COALESCE(s.payment_mode, '') NOT IN ('stripe', 'free_grant')
              $stripeRowSql
              AND COALESCE(p.price_cents, 0) > 0
              AND NOT (" . ak_plan_trial_sql($pdo, 'p') . ")
              AND $curRowSql
              AND $notStripeSql
            ORDER BY o.id ASC
            LIMIT 500
        ")->fetchAll();
        foreach ($cur as $r) {
            if (in_array((int) $r['org_id'], $legacyIds, true)) continue;
            $r['legacy'] = false;
            $orgs[] = $r;
        }
    } catch (Throwable $e) {
        $stats['details']['errors'][] = 'Abonnements payés sur facture illisibles (' . $e->getMessage() . ') : aucun renouvellement de ces abonnements';
        error_log('[CRON renouvellements] abonnements: ' . $e->getMessage());
    }
}

if (!$orgs) return $stats;

// Une seule numérotation pour toutes les factures Assokit (cockpit et cron)
// (sans séquence recalée, aucune facture : un numéro en double est interdit)
$seqError = null;
try {
    cron_invoice_sequence_sync($pdo);
} catch (Throwable $e) {
    $seqError = $e->getMessage();
    error_log('[CRON renouvellements] sequence: ' . $seqError);
}

// Société non immatriculée : les factures du cockpit sont des spécimens (filigrane « MODE TEST »).
// Un spécimen n'est pas une facture : rien n'est envoyé aux associations.
$testMode   = ak_invoice_is_test_mode();
// TVA des factures hors Stripe : taux de la plateforme si la société est assujettie (null : franchise)
$vatRate    = ak_manual_invoice_vat_rate($pdo);
// Première période facturée HT + TVA pour les clients historiques sur facture (préavis de 30 jours)
if (!defined('AK_HT_PRICE_FROM')) define('AK_HT_PRICE_FROM', '2026-11-12');

foreach ($orgs as $org) {
    $orgId = (int) $org['org_id'];
    $label = 'Org #' . $orgId . ' (' . $org['org_name'] . ')';
    $legacy = !empty($org['legacy']);
    try {
        // ----- 1) L'abonnement réel autorise-t-il un renouvellement facturé ?
        $sub = cron_org_billing_state($pdo, $orgId);
        $skip = null;
        $toCheck = null;
        // Abonnement actuel : échéance de la ligne, à défaut celle d'organizations. Pas encore échue :
        // rien à faire aujourd'hui. Aucune échéance : signalé, à facturer à la main.
        if (!$legacy) {
            $due = $sub ? ($sub['period_end'] ?: ($org['current_period_end'] ?: null)) : null;
            if ($sub && $due !== null && substr((string) $due, 0, 10) > $today) continue;
            $org['current_period_end'] = $due !== null ? substr((string) $due, 0, 10) : null;
        }
        $stats['processed']++;
        if (!$sub) {
            $toCheck = 'aucun abonnement Assokit enregistré : formule inconnue, à facturer à la main';
        } elseif ($sub['status'] === 'cancelled' || $sub['cancel_at_period_end']) {
            $skip = 'abonnement résilié';
        } elseif ($sub['is_trial']) {
            $skip = 'en essai';
        } elseif ($sub['is_stripe']) {
            $skip = 'payé par Stripe';
        } elseif ($sub['payment_mode'] === 'free_grant') {
            $skip = 'abonnement offert';
        } elseif ($sub['is_custom']) {
            $skip = 'formule ' . $sub['name'] . ', facturée à part';
        } elseif (!$sub['is_paid'] && $sub['status'] === 'active' && !$sub['plan_changed']) {
            // Ancien montant payant, formule gratuite sans aucun changement enregistré : la ligne Démarrage
            // créée d'office pour un compte historique, pas un retour voulu au gratuit
            $toCheck = 'ancien montant mensuel ' . cron_format_euros((int) $org['monthly_price_cents'])
                     . ' mais formule gratuite ' . $sub['name'] . ' (ligne créée automatiquement ?) : '
                     . 'aucune facture, formule à vérifier (cockpit, « Plan »)';
        } elseif (!$sub['is_paid']) {
            $skip = $sub['status'] === 'active' ? 'formule gratuite (' . $sub['name'] . ')' : 'abonnement non actif (' . $sub['status'] . ')';
        } elseif (in_array($sub['billing_cycle'], ['yearly', 'annual', 'annuel'], true)) {
            $toCheck = 'abonnement annuel : pas de facture mensuelle, à facturer à la main';
        } elseif ($legacy && (int) $org['monthly_price_cents'] !== $sub['price_cents']) {
            $toCheck = 'ancien montant mensuel ' . cron_format_euros((int) $org['monthly_price_cents'])
                     . ' différent du prix de la formule ' . $sub['name'] . ' (' . cron_format_euros($sub['price_cents']) . ' HT) : '
                     . 'aucune facture, montant à confirmer';
        } elseif (!$legacy && $org['current_period_end'] === null) {
            $toCheck = 'formule ' . $sub['name'] . ' (' . cron_format_euros($sub['price_cents']) . ' HT/mois) payée sur facture, '
                     . 'sans échéance enregistrée : à facturer à la main, puis poser la fin de période (Abonnements)';
        } elseif ($testMode) {
            $toCheck = 'société non immatriculée (mode test, SIREN vide) : une facture spécimen n\'est pas envoyée';
        } elseif ($seqError !== null) {
            $toCheck = 'numérotation des factures indisponible (' . $seqError . ') : aucune facture';
        }
        if ($skip !== null) {
            $stats['details']['ignorees'][] = $label . ' — ' . $skip;
            continue;
        }
        if ($toCheck !== null) {
            $stats['failed']++;
            $stats['details']['errors'][] = $label . ' — ' . $toCheck;
            continue;
        }

        // ----- 2) Destinataire
        $admin = cron_get_org_admin($pdo, $orgId);
        if (!$admin) {
            $stats['failed']++;
            $stats['details']['errors'][] = $label . ' — aucun admin';
            continue;
        }

        // ----- 3) Période à facturer : la suivante, ou la période en cours si l'échéance
        // a plus d'une période de retard (jamais une facture par mois manqué).
        $periodStart = date('Y-m-d', strtotime($org['current_period_end'] . ' +1 day'));
        $periodEnd   = date('Y-m-d', strtotime($periodStart . ' +1 month -1 day'));
        $firstMissed = $periodStart;
        $missed = 0;
        while ($periodEnd < $today && $missed < 120) {
            $periodStart = date('Y-m-d', strtotime($periodEnd . ' +1 day'));
            $periodEnd   = date('Y-m-d', strtotime($periodStart . ' +1 month -1 day'));
            $missed++;
        }

        // ----- 4) Déjà facturée (run interrompu après la facture, ou facture d'abonnement émise à la
        // main depuis le cockpit Factures pendant cette période) : on ne refacture pas
        $dup = $pdo->prepare("SELECT id, invoice_number FROM invoices
                              WHERE org_id = :oid AND period_start BETWEEN :ps AND :pe AND status <> 'cancelled'
                              ORDER BY id DESC LIMIT 1");
        $dup->execute([':oid' => $orgId, ':ps' => $periodStart, ':pe' => $periodEnd]);
        $existing = $dup->fetch();

        if (!$existing) {
            // ----- 5) Facture : HT = prix de la formule (asso_plans.price_cents est HT, comme la grille,
            // le cockpit et Stripe) ; TVA de la plateforme si la société y est assujettie ; TTC = HT + TVA
            // (ak_create_invoice). 49,99 € HT → 59,99 € TTC à 20 %, le prix payé par carte.
            $ht = (int) $sub['price_cents'];
            // Clients historiques facturés jusqu'ici 49,99 € TTC : passer à 49,99 € HT + TVA est une
            // hausse de prix, applicable 30 jours après leur information (CGU). Avant AK_HT_PRICE_FROM,
            // leur facture garde l'ancien total TTC (HT recalculé à partir de ce total).
            if ($legacy && $vatRate !== null && (float) $vatRate > 0 && $periodStart < AK_HT_PRICE_FROM) {
                $ht = (int) round($ht / (1 + (float) $vatRate / 100));
            }
            $inv = ak_create_invoice($pdo, $orgId, [
                'amount_ht_cents' => $ht,
                'vat_rate'        => $vatRate,
                'description'     => 'Abonnement Assokit — formule ' . $sub['name'],
                'plan_code'       => $sub['slug'],
                'period_start'    => $periodStart,
                'period_end'      => $periodEnd,
                'due_days'        => 15,
            ]);
            $invoiceId = (int) $inv['id'];
            $invoiceNumber = $inv['invoice_number'];

            // Colonnes lues par les relances (cron-job-impayes) : montant, échéance, destinataire
            $row = $pdo->prepare("SELECT amount_ht_cents, amount_ttc_cents, due_at FROM invoices WHERE id = :id");
            $row->execute([':id' => $invoiceId]);
            $created = $row->fetch() ?: [];
            $amountTtc = (int) ($created['amount_ttc_cents'] ?? $ht);
            ak_db_update($pdo, 'invoices', $invoiceId, [
                'amount_cents' => $amountTtc,
                'due_date'     => !empty($created['due_at']) ? date('Y-m-d', strtotime($created['due_at'])) : date('Y-m-d', strtotime('+15 days')),
                'user_id'      => (int) $admin['id'],
            ]);
            $stats['details']['invoices_created']++;
        } else {
            $stats['details']['ignorees'][] = $label . ' — période du ' . cron_format_date_fr($periodStart)
                . ' déjà facturée (' . $existing['invoice_number'] . ') : échéancier recalé, aucune nouvelle facture';
        }

        // ----- 6) Nouvelle période de l'association, et prochaine échéance de l'abonnement (lue par
        // « Mon abonnement », le cockpit et le prochain passage de ce job)
        $pdo->prepare("
            UPDATE organizations SET
                current_period_start = :ps,
                current_period_end   = :pe
            WHERE id = :id
        ")->execute([':ps' => $periodStart, ':pe' => $periodEnd, ':id' => $orgId]);
        if (isset($subCols['current_period_end']) && !empty($sub['sub_id'])) {
            $pdo->prepare("UPDATE asso_subscriptions SET current_period_end = :pe WHERE id = :id")
                ->execute([':pe' => $periodEnd . ' 23:59:59', ':id' => (int) $sub['sub_id']]);
        }

        if ($missed > 0) {
            $stats['details']['rattrapage'][] = $label . ' — ' . $missed . ' période(s) non facturée(s) du '
                . cron_format_date_fr($firstMissed) . ' au ' . cron_format_date_fr(date('Y-m-d', strtotime($periodStart . ' -1 day')))
                . ' : à facturer à la main si elles sont dues';
        }
        if ($existing) continue;

        // ----- 7) Envoi email
        $vars = [
            'first_name'     => $admin['first_name'] ?: 'bonjour',
            'asso_name'      => $org['org_name'] ?: 'votre association',
            'amount'         => cron_format_euros($amountTtc),
            'invoice_number' => $invoiceNumber,
            'period_end'     => cron_format_date_fr($periodEnd),
            'billing_url'    => cron_billing_url(),
        ];
        $email = cron_email_render('renouvellement', $vars);

        $ok = cron_send_email(
            $pdo, $runId,
            $admin['email'], 'renouvellement',
            $email['subject'], $email['html'],
            [
                'user_id'    => (int) $admin['id'],
                'invoice_id' => $invoiceId,
                'org_id'     => $orgId,
            ]
        );

        if ($ok) {
            $stats['emails']++;
            $stats['succeeded']++;
        } else {
            // Facture bien créée, mais email raté → on compte en succès partiel
            $stats['succeeded']++;
            $stats['details']['errors'][] = $label . ' — facture ' . $invoiceNumber . ' créée, email KO';
        }

    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $stats['failed']++;
        $stats['details']['errors'][] = $label . ' — ' . $ex->getMessage();
        error_log('[CRON renouvellements] ' . $ex->getMessage());
    }
}

return $stats;
