<?php
/**
 * Helpers Cotisations
 */

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

/** Charge config paiement org (Stripe/RIB/chèque) */
function ck_load_org_payment(PDO $pdo, int $org_id): array {
    try {
        $stmt = $pdo->prepare("SELECT * FROM org_payment_settings WHERE org_id = ?");
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

function ck_method_label(string $m): string {
    return ['stripe' => '💳 CB (Stripe)', 'bank' => '🏦 Virement', 'check' => '✉️ Chèque', 'cash' => '💶 Espèces', 'other' => '➕ Autre'][$m] ?? $m;
}
