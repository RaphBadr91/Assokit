<?php
/**
 * /action-cotisation - CRUD campagnes
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/activity-tracker.php';  // journal fondateur : qui a fait quoi, à la minute près
activity_log_request();
require_once __DIR__ . '/includes-cotisations.php';
require_login();
$user = current_user(); $org_id = (int)$user['org_id'];
if ($org_id <= 0) { header('Location: /dashboard'); exit; }  // compte sans association (agent de collectivité) : pas d'« org 0 » partagée
$is_admin = ($user['role'] === 'admin'); $is_coord = ($user['role'] === 'coordinator');
if (!$is_admin && !$is_coord) { http_response_code(403); die('Accès refusé.'); }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: /cotisations'); exit; }
if (!check_csrf($_POST['csrf_token'] ?? '')) {
    $_SESSION['flash_cotisation'] = ['type' => 'error', 'message' => 'Session expirée : rechargez la page puis recommencez.'];
    header('Location: /cotisations?error=csrf'); exit;
}

$action = $_POST['action'] ?? '';

if ($action === 'create' || $action === 'update') {
    $name = trim($_POST['name'] ?? '');
    $year = (int)($_POST['year'] ?? date('Y'));
    $description = trim($_POST['description'] ?? '');
    $currency = in_array($_POST['currency'] ?? '', ['EUR','USD','CHF','GBP'], true) ? $_POST['currency'] : 'EUR';
    $opens = ($_POST['opens_at'] ?? '') ?: null;
    $closes = ($_POST['closes_at'] ?? '') ?: null;
    if ($opens && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $opens)) $opens = null;
    if ($closes && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $closes)) $closes = null;
    $is_active = !empty($_POST['is_active']) ? 1 : 0;
    // « Ces paiements renouvellent l'adhésion » : '' = automatique (NULL), '1' = oui, '0' = non.
    $renews_sent = array_key_exists('renews_membership', $_POST);
    $renews_raw = (string)($_POST['renews_membership'] ?? '');
    $renews = $renews_raw === '1' ? 1 : ($renews_raw === '0' ? 0 : null);
    // Paiement en ligne (migration 2026-10-30) : type (cotisation / don), proposé ou non, montant libre et minimum
    $online_sent = array_key_exists('kind', $_POST) && ck_has_online_settings($pdo);
    $kind = ($_POST['kind'] ?? '') === 'don' ? 'don' : null;
    $online_raw = (string)($_POST['online_payment'] ?? '');
    $online = $online_raw === '0' ? 0 : null;
    $free_amount = !empty($_POST['free_amount']) ? 1 : 0;
    $min_raw = str_replace([',', ' '], ['.', ''], trim((string)($_POST['min_amount'] ?? '')));
    $min_amount = preg_match('/^\d{1,6}(\.\d{1,2})?$/', $min_raw) && (float)$min_raw > 0 ? number_format((float)$min_raw, 2, '.', '') : null;
    if ($kind === 'don') { $renews = 0; $renews_sent = true; }

    // Saisie refusée : retour au formulaire (création ou modification de CETTE campagne),
    // prérempli, avec le message. Même contrôle des dates que l'appli (app-create-campaign).
    $form_error = null;
    if ($name === '') $form_error = ['name', 'Le nom de la campagne est obligatoire.'];
    elseif ($opens && $closes && $closes < $opens) $form_error = ['dates', 'La date de clôture précède la date d’ouverture.'];
    if ($form_error) {
        $edit_id = $action === 'update' ? (int)($_POST['id'] ?? 0) : 0;
        $_SESSION['ck_campaign_form'] = [
            'id' => $edit_id,
            'data' => ['name' => $name, 'year' => $year, 'description' => $description, 'currency' => $currency,
                       'opens_at' => (string)($_POST['opens_at'] ?? ''), 'closes_at' => (string)($_POST['closes_at'] ?? ''),
                       'is_active' => $is_active, 'renews_membership' => $renews, 'kind' => $kind, 'online_payment' => $online,
                       'free_amount' => $free_amount, 'min_amount' => $min_amount],
            'tiers' => array_map(fn($i) => [
                'id' => (int)(($_POST['tier_id'] ?? [])[$i] ?? 0),
                'name' => (string)(($_POST['tier_name'] ?? [])[$i] ?? ''),
                'amount' => (string)(($_POST['tier_amount'] ?? [])[$i] ?? ''),
                'description' => (string)(($_POST['tier_desc'] ?? [])[$i] ?? ''),
            ], array_keys((array)($_POST['tier_name'] ?? []))),
            'message' => $form_error[1],
        ];
        header('Location: /cotisation-form?' . ($edit_id ? 'id=' . $edit_id . '&' : '') . 'error=' . $form_error[0]);
        exit;
    }

    if ($action === 'create') {
        $stmt = $pdo->prepare("INSERT INTO cotisation_campaigns (org_id, name, year, description, currency, opens_at, closes_at, is_active, public_token, created_by) VALUES (?,?,?,?,?,?,?,?,?,?)");
        $token = bin2hex(random_bytes(20));
        $stmt->execute([$org_id, $name, $year ?: null, $description ?: null, $currency, $opens, $closes, $is_active, $token, (int)$user['id']]);
        $campaign_id = (int)$pdo->lastInsertId();
    } else {
        $campaign_id = (int)($_POST['id'] ?? 0);
        $c = ck_load_campaign($pdo, $campaign_id, $org_id);
        if (!$c) {
            $_SESSION['flash_cotisation'] = ['type' => 'error', 'message' => 'Campagne introuvable.'];
            header('Location: /cotisations?error=notfound'); exit;
        }
        $stmt = $pdo->prepare("UPDATE cotisation_campaigns SET name=?, year=?, description=?, currency=?, opens_at=?, closes_at=?, is_active=? WHERE id=? AND org_id=?");
        $stmt->execute([$name, $year ?: null, $description ?: null, $currency, $opens, $closes, $is_active, $campaign_id, $org_id]);
    }
    if ($online_sent) {
        try {
            $pdo->prepare("UPDATE cotisation_campaigns SET kind = ?, online_payment = ?, free_amount = ?, min_amount = ? WHERE id = ? AND org_id = ?")
                ->execute([$kind, $online, $free_amount, $min_amount, $campaign_id, $org_id]);
        } catch (Throwable $e) {
            error_log('[cotisations] paiement en ligne : ' . $e->getMessage());
        }
    }
    if ($renews_sent && ck_has_renews_setting($pdo)) {
        try {
            $pdo->prepare("UPDATE cotisation_campaigns SET renews_membership = ? WHERE id = ? AND org_id = ?")
                ->execute([$renews, $campaign_id, $org_id]);
        } catch (Throwable $e) {
            error_log('[cotisations] renews_membership : ' . $e->getMessage());
        }
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
    // Supprime les tiers qui ne sont plus listés, sauf ceux déjà choisis par des paiements :
    // les effacer ferait perdre à ces paiements la formule payée.
    $used = [];
    $stmt = $pdo->prepare("SELECT t.id, t.name FROM cotisation_tiers t
                           WHERE t.campaign_id = ? AND EXISTS (SELECT 1 FROM cotisation_payments p WHERE p.tier_id = t.id)");
    $stmt->execute([$campaign_id]);
    foreach ($stmt->fetchAll() as $r) $used[(int)$r['id']] = (string)$r['name'];
    $keep = array_values(array_unique(array_merge($kept_ids, array_keys($used))));
    if (!empty($keep)) {
        $ph = implode(',', array_fill(0, count($keep), '?'));
        $params = array_merge([$campaign_id], $keep);
        $stmt = $pdo->prepare("DELETE FROM cotisation_tiers WHERE campaign_id = ? AND id NOT IN ($ph)");
        $stmt->execute($params);
    } else {
        $pdo->prepare("DELETE FROM cotisation_tiers WHERE campaign_id = ?")->execute([$campaign_id]);
    }

    $kept_used = array_diff_key($used, array_flip($kept_ids));
    if ($kept_used) {
        $names = '« ' . implode(' », « ', $kept_used) . ' »';
        $_SESSION['flash_cotisation'] = [
            'type' => 'error',
            'message' => 'Campagne enregistrée, mais ' . (count($kept_used) > 1
                ? "les tarifs $names ont été conservés : des paiements enregistrés les utilisent."
                : "le tarif $names a été conservé : des paiements enregistrés l’utilisent."),
        ];
    } else {
        $_SESSION['flash_cotisation'] = ['type' => 'success', 'message' => 'Campagne enregistrée.'];
    }

    header('Location: /cotisation/' . $campaign_id . '?saved=1');
    exit;
}

// Archiver / désarchiver une campagne : admin seul. Une campagne archivée sort de la liste
// principale (rubrique « Campagnes archivées ») ; ses paiements restent comptés.
if ($action === 'archive' || $action === 'unarchive') {
    $id = (int)($_POST['id'] ?? 0);
    $c = ck_load_campaign($pdo, $id, $org_id);
    if (!$c) {
        $_SESSION['flash_cotisation'] = ['type' => 'error', 'message' => 'Campagne introuvable.'];
        header('Location: /cotisations?error=notfound'); exit;
    }
    if (!$is_admin) {
        $_SESSION['flash_cotisation'] = ['type' => 'error', 'message' => 'Seul un administrateur peut archiver une campagne.'];
        header('Location: /cotisation/' . $id); exit;
    }
    if ($action === 'archive') {
        $pdo->prepare("UPDATE cotisation_campaigns SET archived_at = NOW() WHERE id = ? AND org_id = ? AND archived_at IS NULL")->execute([$id, $org_id]);
        $_SESSION['flash_cotisation'] = ['type' => 'success', 'message' => 'Campagne « ' . $c['name'] . ' » archivée. Elle reste consultable dans « Campagnes archivées ».'];
        header('Location: /cotisations?archived=1');
    } else {
        $pdo->prepare("UPDATE cotisation_campaigns SET archived_at = NULL WHERE id = ? AND org_id = ?")->execute([$id, $org_id]);
        $_SESSION['flash_cotisation'] = ['type' => 'success', 'message' => 'Campagne « ' . $c['name'] . ' » désarchivée.'];
        header('Location: /cotisation/' . $id);
    }
    exit;
}

header('Location: /cotisations');
