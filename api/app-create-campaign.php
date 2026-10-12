<?php
/**
 * api/app-create-campaign.php — Création d'une campagne de cotisation depuis l'app.
 * Reproduit fidèlement action-cotisation.php (action=create) : campagne + tarifs.
 * Renvoie du JSON. Rôle requis : admin ou coordinateur (parité web).
 * NE MODIFIE PAS le site.
 */
require __DIR__ . '/_app-write-boot.php';

if (!in_array($user['role'] ?? '', ['admin', 'coordinator'], true)
    && empty($user['is_founder']) && empty($user['is_super_admin'])) {
    app_fail(403, 'role', 'Rôle insuffisant pour créer une campagne.');
}

$name = trim((string) ($input['name'] ?? ''));
if ($name === '') app_fail(422, 'invalid', 'Le nom de la campagne est obligatoire.');
$name = mb_substr($name, 0, 150);

$year = (int) ($input['year'] ?? date('Y'));
if ($year < 2020 || $year > 2050) $year = (int) date('Y');

$description = trim((string) ($input['description'] ?? ''));
$_cur = (string) ($input['currency'] ?? 'EUR');
$currency = in_array($_cur, ['EUR', 'USD', 'CHF', 'GBP'], true) ? $_cur : 'EUR';

$dt = static function ($v) {
    $v = trim((string) $v);
    return ($v !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) ? $v : null;
};
$opens  = $dt($input['opens_at'] ?? '');
$closes = $dt($input['closes_at'] ?? '');
// Une clôture ne peut pas précéder l'ouverture
if ($opens && $closes && strtotime($closes) < strtotime($opens)) {
    app_fail(422, 'invalid', 'La date de clôture précède la date d\'ouverture.');
}
$is_active = !empty($input['is_active']) ? 1 : 0;
// « Ces paiements renouvellent l'adhésion » (comme action-cotisation.php) : '' ou absent =
// automatique (NULL), 1 = oui, 0 = non. Enregistré seulement si la colonne existe.
$renews_sent = array_key_exists('renews_membership', $input);
$rv = $renews_sent ? $input['renews_membership'] : '';
$renews_raw = is_bool($rv) ? (string) (int) $rv : (is_scalar($rv) ? trim((string) $rv) : '');
$renews = $renews_raw === '1' ? 1 : ($renews_raw === '0' ? 0 : null);

// Tarifs (nom + montant obligatoires) — normalise la virgule décimale
$num = static fn($v) => (float) str_replace([' ', ','], ['', '.'], (string) $v);
$tiers = [];
foreach ((array) ($input['tiers'] ?? []) as $t) {
    $tn = trim((string) ($t['name'] ?? ''));
    if ($tn === '') continue;
    $tiers[] = [
        'name'        => mb_substr($tn, 0, 120),
        'amount'      => max(0, $num($t['amount'] ?? 0)),
        'description' => trim((string) ($t['description'] ?? '')) ?: null,
    ];
}
$tiers = array_slice($tiers, 0, 30);

try {
    $pdo->beginTransaction();

    $token = bin2hex(random_bytes(20));
    $pdo->prepare("INSERT INTO cotisation_campaigns
        (org_id, name, year, description, currency, opens_at, closes_at, is_active, public_token, created_by)
        VALUES (?,?,?,?,?,?,?,?,?,?)")
        ->execute([$org_id, $name, $year ?: null, $description ?: null, $currency, $opens, $closes, $is_active, $token, $uid]);
    $campaign_id = (int) $pdo->lastInsertId();

    if ($renews_sent && $renews !== null) {
        require_once __DIR__ . '/../trial-helpers.php';
        if (isset(ak_db_columns($pdo, 'cotisation_campaigns')['renews_membership'])) {
            $pdo->prepare("UPDATE cotisation_campaigns SET renews_membership = ? WHERE id = ? AND org_id = ?")
                ->execute([$renews, $campaign_id, $org_id]);
        }
    }

    if ($tiers) {
        $ins = $pdo->prepare("INSERT INTO cotisation_tiers (campaign_id, name, amount, description, position) VALUES (?,?,?,?,?)");
        foreach ($tiers as $i => $t) $ins->execute([$campaign_id, $t['name'], $t['amount'], $t['description'], $i]);
    }

    $pdo->commit();

    echo json_encode([
        'ok'      => true,
        'id'      => $campaign_id,
        'message' => 'Campagne « ' . $name . ' » créée' . ($tiers ? ' avec ' . count($tiers) . ' tarif(s).' : '.'),
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) { try { $pdo->rollBack(); } catch (Throwable $e2) {} }
    error_log('[app-create-campaign] ' . $e->getMessage());
    app_fail(500, 'server', 'Impossible de créer la campagne.');
}
