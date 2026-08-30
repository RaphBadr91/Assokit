<?php
/**
 * ============================================================
 * ASSOKIT — API recherche globale
 * ============================================================
 * GET /api-search.php?q=xxx
 * Cherche dans : projets · dossiers · adhérents · factures
 * Filtré par org_id, respecte les permissions follower
 * Retourne JSON [{type, label, sub, url, icon}, ...]
 * ============================================================
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/rate-limit-helper.php';
ak_rate_limit_or_die('api_search', 60, 60, (string)($_SESSION['user_id'] ?? ($_SERVER['REMOTE_ADDR'] ?? 'anon')));
require_login();

header('Content-Type: application/json; charset=utf-8');

$user = current_user();
$org_id = (int)$user['org_id'];
$is_admin = ($user['role'] === 'admin');
$q = trim($_GET['q'] ?? '');

if (mb_strlen($q) < 2) {
    echo json_encode(['ok' => true, 'results' => []]);
    exit;
}

$like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%';
$results = [];

// Follower : filtre projets autorisés
$follower_ids = function_exists('get_follower_project_ids') ? get_follower_project_ids() : null;
$is_follower = ($follower_ids !== null);

// === PROJETS ===
try {
    $sql = "SELECT p.id, p.name, p.location, f.name AS folder_name
            FROM projects p JOIN folders f ON f.id = p.folder_id
            WHERE f.org_id = ? AND f.archived_at IS NULL AND p.archived_at IS NULL
              AND (p.name LIKE ? OR p.location LIKE ?)";
    $params = [$org_id, $like, $like];
    if ($is_follower) {
        if (empty($follower_ids)) $sql .= " AND 1=0";
        else {
            $ph = implode(',', array_fill(0, count($follower_ids), '?'));
            $sql .= " AND p.id IN ($ph)";
            $params = array_merge($params, $follower_ids);
        }
    }
    $sql .= " ORDER BY p.name LIMIT 6";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    foreach ($stmt->fetchAll() as $r) {
        $results[] = [
            'type' => 'projet', 'icon' => '✨',
            'label' => $r['name'],
            'sub' => $r['folder_name'] . ($r['location'] ? ' · ' . $r['location'] : ''),
            'url' => '/projet/' . (int)$r['id'],
        ];
    }
} catch (Throwable $e) {}

// === DOSSIERS ===
if (!$is_follower) {
    try {
        $stmt = $pdo->prepare("SELECT id, name FROM folders WHERE org_id = ? AND archived_at IS NULL AND name LIKE ? ORDER BY name LIMIT 4");
        $stmt->execute([$org_id, $like]);
        foreach ($stmt->fetchAll() as $r) {
            $results[] = [
                'type' => 'dossier', 'icon' => '📁',
                'label' => $r['name'], 'sub' => 'Dossier',
                'url' => '/projets#f' . (int)$r['id'],
            ];
        }
    } catch (Throwable $e) {}
}

// === ADHÉRENTS ===
if (!$is_follower) {
    try {
        $stmt = $pdo->prepare("SELECT id, first_name, last_name, email FROM users
                               WHERE org_id = ? AND deleted_at IS NULL
                                 AND (first_name LIKE ? OR last_name LIKE ? OR email LIKE ?)
                               ORDER BY last_name, first_name LIMIT 6");
        $stmt->execute([$org_id, $like, $like, $like]);
        foreach ($stmt->fetchAll() as $r) {
            $results[] = [
                'type' => 'adherent', 'icon' => '👤',
                'label' => trim($r['first_name'] . ' ' . $r['last_name']),
                'sub' => $r['email'] ?: 'Adhérent',
                'url' => '/adherent/' . (int)$r['id'],
            ];
        }
    } catch (Throwable $e) {}
}

// === FACTURES (admins ou managers finance uniquement) ===
$can_finance = $is_admin || (function_exists('user_can_view_finances') && user_can_view_finances($user));
if ($can_finance) {
    try {
        $stmt = $pdo->prepare("SELECT i.id, i.number, i.amount_total, c.display_name
                               FROM asso_invoices i LEFT JOIN asso_clients c ON c.id = i.client_id
                               WHERE i.org_id = ? AND (i.number LIKE ? OR c.display_name LIKE ?)
                               ORDER BY i.issued_at DESC LIMIT 5");
        $stmt->execute([$org_id, $like, $like]);
        foreach ($stmt->fetchAll() as $r) {
            $amt = number_format((float)$r['amount_total'], 0, ',', ' ') . ' €';
            $results[] = [
                'type' => 'facture', 'icon' => '💰',
                'label' => $r['number'], 'sub' => ($r['display_name'] ?: 'Client') . ' · ' . $amt,
                'url' => '/mon-asso-facture-edit?id=' . (int)$r['id'],
            ];
        }
    } catch (Throwable $e) {}
}

echo json_encode(['ok' => true, 'q' => $q, 'results' => $results]);
