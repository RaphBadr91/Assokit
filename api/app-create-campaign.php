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

// Année : absente ou vide = année en cours ; sinon 4 chiffres entre 2020 et 2050, refusée avec un
// message clair au lieu d'être remplacée en silence par l'année en cours (« deux mille »).
$year_raw = $input['year'] ?? '';
$year_raw = is_int($year_raw) ? (string) $year_raw : (is_string($year_raw) ? trim($year_raw) : null);
if ($year_raw === null || ($year_raw !== '' && !preg_match('/^\d{4}$/', $year_raw))) {
    app_fail(422, 'invalid', 'Année invalide : saisissez l’année sur 4 chiffres (ex : ' . date('Y') . ').');
}
$year = $year_raw === '' ? (int) date('Y') : (int) $year_raw;
if ($year < 2020 || $year > 2050) app_fail(422, 'invalid', 'Année invalide : choisissez une année entre 2020 et 2050.');

$description = trim((string) ($input['description'] ?? ''));
$_cur = (string) ($input['currency'] ?? 'EUR');
$currency = in_array($_cur, ['EUR', 'USD', 'CHF', 'GBP'], true) ? $_cur : 'EUR';

// Dates : vide = aucune ; AAAA-MM-JJ ou JJ/MM/AAAA ; illisible = 422 (au lieu d'être ignorée en silence)
$opens  = app_parse_date($input['opens_at'] ?? '', 'Date d’ouverture');
$closes = app_parse_date($input['closes_at'] ?? '', 'Date de clôture');
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

// Tarifs (nom obligatoire ; montant vide = 0) — normalise la virgule décimale. Un montant illisible
// (« abc ») est refusé avec le nom du tarif, au lieu de devenir 0,00 € en silence.
$tiers = [];
foreach ((array) ($input['tiers'] ?? []) as $t) {
    if (!is_array($t)) continue;
    $tn = trim((string) (is_scalar($t['name'] ?? null) ? $t['name'] : ''));
    if ($tn === '') continue;
    $ta = $t['amount'] ?? '';
    $ta = is_scalar($ta) ? str_replace([' ', "\u{00A0}", "\u{202F}", ','], ['', '', '', '.'], trim((string) $ta)) : null;
    if ($ta === null || ($ta !== '' && !is_numeric($ta))) {
        app_fail(422, 'invalid', 'Montant invalide pour le tarif « ' . mb_substr($tn, 0, 120) . ' » : saisissez un nombre (ex : 25 ou 25,50).');
    }
    $tiers[] = [
        'name'        => mb_substr($tn, 0, 120),
        'amount'      => $ta === '' ? 0.0 : max(0, (float) $ta),
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
