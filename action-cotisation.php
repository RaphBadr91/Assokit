<?php
/**
 * /action-cotisation - CRUD campagnes
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes-cotisations.php';
require_login();
$user = current_user(); $org_id = (int)$user['org_id'];
$is_admin = ($user['role'] === 'admin'); $is_coord = ($user['role'] === 'coordinator');
if (!$is_admin && !$is_coord) { http_response_code(403); die('Accès refusé.'); }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: /cotisations'); exit; }
if (!check_csrf($_POST['csrf_token'] ?? '')) { header('Location: /cotisations?error=csrf'); exit; }

$action = $_POST['action'] ?? '';

if ($action === 'create' || $action === 'update') {
    $name = trim($_POST['name'] ?? '');
    if ($name === '') { header('Location: /cotisation-form?error=name'); exit; }
    $year = (int)($_POST['year'] ?? date('Y'));
    $description = trim($_POST['description'] ?? '');
    $currency = in_array($_POST['currency'] ?? '', ['EUR','USD','CHF','GBP'], true) ? $_POST['currency'] : 'EUR';
    $opens = $_POST['opens_at'] ?: null;
    $closes = $_POST['closes_at'] ?: null;
    if ($opens && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $opens)) $opens = null;
    if ($closes && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $closes)) $closes = null;
    $is_active = !empty($_POST['is_active']) ? 1 : 0;

    if ($action === 'create') {
        $stmt = $pdo->prepare("INSERT INTO cotisation_campaigns (org_id, name, year, description, currency, opens_at, closes_at, is_active, public_token, created_by) VALUES (?,?,?,?,?,?,?,?,?,?)");
        $token = bin2hex(random_bytes(20));
        $stmt->execute([$org_id, $name, $year ?: null, $description ?: null, $currency, $opens, $closes, $is_active, $token, (int)$user['id']]);
        $campaign_id = (int)$pdo->lastInsertId();
    } else {
        $campaign_id = (int)($_POST['id'] ?? 0);
        $c = ck_load_campaign($pdo, $campaign_id, $org_id);
        if (!$c) { header('Location: /cotisations?error=notfound'); exit; }
        $stmt = $pdo->prepare("UPDATE cotisation_campaigns SET name=?, year=?, description=?, currency=?, opens_at=?, closes_at=?, is_active=? WHERE id=? AND org_id=?");
        $stmt->execute([$name, $year ?: null, $description ?: null, $currency, $opens, $closes, $is_active, $campaign_id, $org_id]);
    }

    // Tiers : sync (delete+insert simplifié, ou update si id existe)
    $tier_ids = $_POST['tier_id'] ?? [];
    $tier_names = $_POST['tier_name'] ?? [];
    $tier_amounts = $_POST['tier_amount'] ?? [];
    $tier_descs = $_POST['tier_desc'] ?? [];
    $kept_ids = [];
    foreach ($tier_names as $i => $tname) {
        $tname = trim($tname);
        if ($tname === '') continue;
        $tamount = (float)str_replace(',', '.', $tier_amounts[$i] ?? 0);
        $tdesc = trim($tier_descs[$i] ?? '');
        $tid = (int)($tier_ids[$i] ?? 0);
        if ($tid > 0) {
            $stmt = $pdo->prepare("UPDATE cotisation_tiers SET name=?, amount=?, description=?, position=? WHERE id=? AND campaign_id=?");
            $stmt->execute([$tname, $tamount, $tdesc ?: null, $i, $tid, $campaign_id]);
            $kept_ids[] = $tid;
        } else {
            $stmt = $pdo->prepare("INSERT INTO cotisation_tiers (campaign_id, name, amount, description, position) VALUES (?,?,?,?,?)");
            $stmt->execute([$campaign_id, $tname, $tamount, $tdesc ?: null, $i]);
            $kept_ids[] = (int)$pdo->lastInsertId();
        }
    }
    // Supprime les tiers qui ne sont plus listés
    if (!empty($kept_ids)) {
        $ph = implode(',', array_fill(0, count($kept_ids), '?'));
        $params = array_merge([$campaign_id], $kept_ids);
        $stmt = $pdo->prepare("DELETE FROM cotisation_tiers WHERE campaign_id = ? AND id NOT IN ($ph)");
        $stmt->execute($params);
    } else {
        $pdo->prepare("DELETE FROM cotisation_tiers WHERE campaign_id = ?")->execute([$campaign_id]);
    }

    header('Location: /cotisation/' . $campaign_id . '?saved=1');
    exit;
}

if ($action === 'archive' && $is_admin) {
    $id = (int)($_POST['id'] ?? 0);
    $pdo->prepare("UPDATE cotisation_campaigns SET archived_at = NOW() WHERE id = ? AND org_id = ?")->execute([$id, $org_id]);
    header('Location: /cotisations?archived=1');
    exit;
}

header('Location: /cotisations');
