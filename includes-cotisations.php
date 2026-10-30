<?php
/**
 * Helpers Cotisations
 */
require_once __DIR__ . '/trial-helpers.php';   // ak_db_columns() : réglage renews_membership (migration L02)

/** Charge une campagne et vérifie l'org */
function ck_load_campaign(PDO $pdo, int $id, int $org_id): ?array {
    $stmt = $pdo->prepare("SELECT * FROM cotisation_campaigns WHERE id = ? AND org_id = ?");
    $stmt->execute([$id, $org_id]);
    return $stmt->fetch() ?: null;
}

/** Tiers d'une campagne */
function ck_load_tiers(PDO $pdo, int $campaign_id): array {
    $stmt = $pdo->prepare("SELECT * FROM cotisation_tiers WHERE campaign_id = ? ORDER BY position ASC, id ASC");
    $stmt->execute([$campaign_id]);
    return $stmt->fetchAll();
}

/** Stats d'une campagne (total, payé, en attente, count) */
function ck_campaign_stats(PDO $pdo, int $campaign_id): array {
    $s = ['count_paid' => 0, 'count_pending' => 0, 'amount_paid' => 0.0, 'amount_pending' => 0.0];
    $stmt = $pdo->prepare("SELECT status, COUNT(*) AS c, COALESCE(SUM(amount),0) AS s FROM cotisation_payments WHERE campaign_id = ? GROUP BY status");
    $stmt->execute([$campaign_id]);
    foreach ($stmt->fetchAll() as $r) {
        if ($r['status'] === 'paid')    { $s['count_paid']    = (int)$r['c']; $s['amount_paid']    = (float)$r['s']; }
        if ($r['status'] === 'pending') { $s['count_pending'] = (int)$r['c']; $s['amount_pending'] = (float)$r['s']; }
    }
    return $s;
}

/** Paiements d'une campagne (avec adhérent éventuel) */
function ck_load_payments(PDO $pdo, int $campaign_id, ?string $status = null): array {
    $sql = "SELECT p.*, t.name AS tier_name, a.first_name AS a_first, a.last_name AS a_last
            FROM cotisation_payments p
            LEFT JOIN cotisation_tiers t ON t.id = p.tier_id
            LEFT JOIN users a ON a.id = p.adherent_id
            WHERE p.campaign_id = ?";
    $params = [$campaign_id];
    if ($status) { $sql .= " AND p.status = ?"; $params[] = $status; }
    $sql .= " ORDER BY p.created_at DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/**
 * Fin d'adhésion couverte par un paiement de cette campagne : 12 mois de date à date
 * à compter du paiement, ou la fin de la campagne si elle est plus lointaine (saison,
 * année suivante réglée d'avance). Fin de campagne = clôture, sinon 31/12 de son année.
 * null si la campagne était déjà terminée à la date du paiement (arriéré d'une année passée).
 */
function ck_membership_end(array $campaign, string $paid_date): ?string {
    $paid_date = substr($paid_date, 0, 10);
    $ts = strtotime($paid_date . ' +1 year -1 day');
    if (!$ts) return null;
    $end = date('Y-m-d', $ts);

    $campaign_end = substr((string)($campaign['closes_at'] ?? ''), 0, 10);
    if ($campaign_end === '' || $campaign_end === '0000-00-00') {
        $year = (int)($campaign['year'] ?? 0);
        $campaign_end = $year >= 2000 ? $year . '-12-31' : '';
    }
    if ($campaign_end !== '') {
        if ($campaign_end < $paid_date) return null;
        if ($campaign_end > $end) $end = $campaign_end;
    }
    return $end;
}

/** Le réglage « renouvelle l'adhésion » existe-t-il en base (migration 2026-10-11-L02-adherents.sql) ? */
function ck_has_renews_setting(PDO $pdo): bool {
    return isset(ak_db_columns($pdo, 'cotisation_campaigns')['renews_membership']);
}

/**
 * Un paiement de cette campagne renouvelle-t-il l'adhésion ? Réglage de la campagne (Oui / Non) ;
 * « Automatique » (NULL) ou réglage absent : d'après son nom (« Adhésions 2026 », « Cotisation
 * annuelle »…). Une participation aux frais d'ateliers, une billetterie ou un don ne rend pas
 * la personne « à jour » de son adhésion.
 */
function ck_campaign_renews_membership(array $campaign): bool {
    // Campagne de dons (migration 2026-10-30) : un don ne rend jamais « à jour » de son adhésion
    if (($campaign['kind'] ?? '') === 'don') return false;
    $v = $campaign['renews_membership'] ?? null;
    if ($v !== null && $v !== '') return (bool)(int)$v;
    return (bool)preg_match('/adh[ée]sion|cotisation|membership/iu', (string)($campaign['name'] ?? ''));
}

/**
 * Paiement encaissé pour un adhérent, sur une campagne d'adhésion (ck_campaign_renews_membership) :
 * son adhésion (users.adhesion_valid_until, lue par les relances, l'annuaire et l'appli) est
 * prolongée selon ck_membership_end(). Ne raccourcit jamais une date déjà plus lointaine, et deux
 * paiements le même jour ne la prolongent pas deux fois. Une annulation ne la réduit pas (l'admin
 * la corrige sur la fiche si besoin).
 *
 * @return string|null la nouvelle date de fin (Y-m-d) si elle a changé
 */
function ck_extend_membership(PDO $pdo, int $org_id, ?int $adherent_id, array $campaign, ?string $paid_at = null): ?string {
    if (!$adherent_id) return null;
    if (!ck_campaign_renews_membership($campaign)) return null;
    $end = ck_membership_end($campaign, $paid_at ?: date('Y-m-d'));
    if ($end === null) return null;
    try {
        $st = $pdo->prepare("UPDATE users SET adhesion_valid_until = ?
                             WHERE id = ? AND org_id = ?
                               AND (adhesion_valid_until IS NULL OR adhesion_valid_until < ?)");
        $st->execute([$end, $adherent_id, $org_id, $end]);
        return $st->rowCount() > 0 ? $end : null;
    } catch (Throwable $e) {
        error_log('[cotisations] prolongation adhésion : ' . $e->getMessage());
        return null;
    }
}

/** Symbole de la devise d'une campagne (EUR → €, GBP → £, USD → $, sinon le code ISO) */
function ck_currency_symbol(?string $currency): string {
    $code = strtoupper(trim((string)$currency));
    if ($code === '') return '€';
    return ['EUR' => '€', 'USD' => '$', 'GBP' => '£'][$code] ?? $code;
}

/** Montant dans la devise de la campagne : « 12,50 € », « 12,50 CHF » */
function ck_money($amount, ?string $currency, int $decimals = 2): string {
    return number_format((float)$amount, $decimals, ',', ' ') . ' ' . ck_currency_symbol($currency);
}

/**
 * Formulaire envoyé deux fois (double clic, retour arrière) : le jeton à usage unique
 * du formulaire est mémorisé en session ; un second envoi du même jeton est refusé.
 * true = premier envoi (ou formulaire sans jeton : ancien onglet), false = doublon.
 */
function ck_form_nonce_consume(?string $nonce): bool {
    $nonce = (string)$nonce;
    if ($nonce === '' || !preg_match('/^[a-f0-9]{16,64}$/', $nonce)) return true;
    $used = $_SESSION['ck_used_nonces'] ?? [];
    if (in_array($nonce, $used, true)) return false;
    $used[] = $nonce;
    $_SESSION['ck_used_nonces'] = array_slice($used, -30);
    return true;
}

/** Charge config paiement org (Stripe/RIB/chèque) */
function ck_load_org_payment(PDO $pdo, int $org_id): array {
    try {
        $stmt = $pdo->prepare("SELECT * FROM org_payment_settings WHERE org_id = ? ORDER BY id DESC LIMIT 1");
        $stmt->execute([$org_id]);
        $r = $stmt->fetch();
        if ($r) return $r;
    } catch (Throwable $e) {}
    return ['stripe_enabled' => 0, 'stripe_mode' => 'test', 'bank_iban' => null];
}

function ck_status_badge(string $status): array {
    return [
        'paid'      => ['Payé',     '#10B981', '#ECFDF5'],
        'pending'   => ['En attente','#F59E0B', '#FEF3C7'],
        'refunded'  => ['Remboursé','#6B7280', '#F3F4F6'],
        'cancelled' => ['Annulé',   '#EF4444', '#FEE2E2'],
    ][$status] ?? ['—','#6B7280','#F3F4F6'];
}

/** Réglages « paiement en ligne » d'une campagne (type, montant libre) : migration 2026-10-30 passée ? */
function ck_has_online_settings(PDO $pdo): bool {
    return isset(ak_db_columns($pdo, 'cotisation_campaigns')['kind']);
}

/** Structure de type TPE / entreprise (inscription) : pas de campagne de dons (une entreprise ne reçoit pas de dons). */
function ck_org_is_tpe(PDO $pdo, int $org_id): bool {
    if ($org_id <= 0 || !isset(ak_db_columns($pdo, 'organizations')['org_type'])) return false;
    try {
        $q = $pdo->prepare("SELECT org_type FROM organizations WHERE id = ?");
        $q->execute([$org_id]);
        return (string)$q->fetchColumn() === 'tpe';
    } catch (Throwable $e) {
        return false;
    }
}

/** La campagne (de cette structure) est-elle déjà une campagne de dons ? */
function ck_campaign_was_don(PDO $pdo, int $campaign_id, int $org_id): bool {
    if ($campaign_id <= 0 || !ck_has_online_settings($pdo)) return false;
    $q = $pdo->prepare("SELECT kind FROM cotisation_campaigns WHERE id = ? AND org_id = ?");
    $q->execute([$campaign_id, $org_id]);
    return (string)$q->fetchColumn() === 'don';
}

function ck_method_label(string $m): string {
    return ['stripe' => '💳 CB (Stripe)', 'bank' => '🏦 Virement', 'check' => '✉️ Chèque', 'cash' => '💶 Espèces', 'other' => '➕ Autre'][$m] ?? $m;
}
