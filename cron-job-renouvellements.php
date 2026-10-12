<?php
/**
 * ============================================================
 * ASSOKIT — cron-job-renouvellements.php (v3)
 * Job : Renouvellement mensuel des abonnements payés hors Stripe
 * ============================================================
 * L'échéancier reste celui des anciennes colonnes organizations
 * (monthly_price_cents, current_period_end), mais rien n'est facturé
 * sans que l'abonnement RÉEL (asso_subscriptions / asso_plans) le
 * confirme. Une association n'est facturée que si sa formule
 * courante est payante, active, réglée hors Stripe, ni résiliée, ni
 * en essai, ni offerte, ni Sur-mesure (facturé à part), mensuelle,
 * et que l'ancien montant mensuel est bien le prix de cette formule.
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

$stats = [
    'processed' => 0,
    'succeeded' => 0,
    'failed'    => 0,
    'emails'    => 0,
    'details'   => ['invoices_created' => 0, 'errors' => [], 'ignorees' => [], 'rattrapage' => []],
];

// ----- Échéancier : orgas dont la période est échue
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
      -- Ne pas facturer manuellement les organisations payant via Stripe (evite la double facturation)
      AND o.id NOT IN (
          SELECT s.org_id FROM asso_subscriptions s
          WHERE s.payment_mode = 'stripe' AND s.status <> 'cancelled' AND s.org_id IS NOT NULL
      )
    ORDER BY o.current_period_end ASC
    LIMIT 500
")->fetchAll();
$stats['processed'] = count($orgs);

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
$vatSubject = (int) ak_company('vat_subject') === 1;
$today      = date('Y-m-d');

foreach ($orgs as $org) {
    $orgId = (int) $org['org_id'];
    $label = 'Org #' . $orgId . ' (' . $org['org_name'] . ')';
    try {
        // ----- 1) L'abonnement réel autorise-t-il un renouvellement facturé ?
        $sub = cron_org_billing_state($pdo, $orgId);
        $skip = null;
        $toCheck = null;
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
        } elseif ((int) $org['monthly_price_cents'] !== $sub['price_cents']) {
            $toCheck = 'ancien montant mensuel ' . cron_format_euros((int) $org['monthly_price_cents'])
                     . ' différent du prix de la formule ' . $sub['name'] . ' (' . cron_format_euros($sub['price_cents']) . ') : '
                     . 'aucune facture, montant à confirmer';
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

        // ----- 4) Déjà facturée (run interrompu après la facture) : on ne refacture pas
        $dup = $pdo->prepare("SELECT id, invoice_number FROM invoices
                              WHERE org_id = :oid AND period_start = :ps AND status <> 'cancelled'
                              ORDER BY id DESC LIMIT 1");
        $dup->execute([':oid' => $orgId, ':ps' => $periodStart]);
        $existing = $dup->fetch();

        if (!$existing) {
            // ----- 5) Facture : TTC = prix de la formule ; TVA à 20 % si la société y est assujettie
            $ttc = $sub['price_cents'];
            $vatRate = $vatSubject ? 20.0 : null;
            $ht = $vatSubject ? (int) round($ttc / 1.2) : $ttc;
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
            // TTC = prix de la formule au centime près (TVA = TTC − HT, comme le cockpit Factures)
            if ($vatRate !== null && isset($created['amount_ttc_cents']) && (int) $created['amount_ttc_cents'] !== $ttc) {
                $pdo->prepare("UPDATE invoices SET amount_vat_cents = :vat, amount_ttc_cents = :ttc WHERE id = :id")
                    ->execute([':vat' => $ttc - (int) $created['amount_ht_cents'], ':ttc' => $ttc, ':id' => $invoiceId]);
                $pdfPath = ak_render_invoice_pdf($pdo, $invoiceId);
                if (!empty($inv['pdf_path']) && $inv['pdf_path'] !== $pdfPath) @unlink(__DIR__ . $inv['pdf_path']);
                $created['amount_ttc_cents'] = $ttc;
            }
            $amountTtc = (int) ($created['amount_ttc_cents'] ?? $ttc);
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

        // ----- 6) Nouvelle période de l'association
        $pdo->prepare("
            UPDATE organizations SET
                current_period_start = :ps,
                current_period_end   = :pe
            WHERE id = :id
        ")->execute([':ps' => $periodStart, ':pe' => $periodEnd, ':id' => $orgId]);

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
