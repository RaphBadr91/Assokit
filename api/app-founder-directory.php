<?php
/**
 * api/app-founder-directory.php — Annuaire national des associations (Fondateur, natif).
 *
 * GET (sans param)          → arbre de comptage : régions → départements → catégories.
 * GET ?region=..            → départements de la région (comptés).
 * GET ?dept=..&category=..  → liste des associations (paginée).
 * GET ?stats=1              → total + couverture.
 * POST action=set_email     → renseigne un email LÉGITIME sur une asso de l'annuaire.
 * POST action=to_prospect   → promeut une asso (avec email) vers la file de prospection conforme.
 *
 * Données publiques (RNA/INSEE) sans email. Réservé Fondateur/Super Admin. NE MODIFIE PAS le site.
 */
ob_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes-layout.php';
ob_end_clean();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
require_once __DIR__ . '/_app-directory.php';
require_once __DIR__ . '/_app-prospect.php';

function dir_fail($c, $e, $m = null) { http_response_code($c); echo json_encode(['ok' => false, 'error' => $e, 'message' => $m], JSON_UNESCAPED_UNICODE); exit; }

if (empty($_SESSION['user_id'])) dir_fail(401, 'auth');
$user = function_exists('current_user') ? current_user() : null;
require_once __DIR__ . '/_app-founder.php';
$is_sa = app_is_founder($pdo, $user) || !empty($user['is_super_admin']) || (($user['role'] ?? '') === 'super_admin');
if (!$is_sa) dir_fail(403, 'forbidden');

ak_directory_tables_ensure($pdo);
$CATS = ak_directory_categories();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method !== 'POST') {
    try {
        // Vue liste : département + catégorie
        if (!empty($_GET['dept'])) {
            $dept = (string) $_GET['dept'];
            $cat  = (string) ($_GET['category'] ?? '');
            $page = max(1, (int) ($_GET['page'] ?? 1));
            $per  = 50; $off = ($page - 1) * $per;
            $where = "dept_code = ?"; $args = [$dept];
            if ($cat !== '' && isset($CATS[$cat])) { $where .= " AND category = ?"; $args[] = $cat; }
            $st = $pdo->prepare("SELECT id, org_name, category, city, zip, address, email, phone, website
                                 FROM asso_directory WHERE $where ORDER BY org_name ASC LIMIT $per OFFSET $off");
            $st->execute($args);
            $rows = array_map(fn($r) => [
                'id' => (int) $r['id'], 'org' => (string) $r['org_name'],
                'category' => (string) $r['category'], 'cat_label' => $CATS[$r['category']]['label'] ?? $r['category'],
                'city' => (string) ($r['city'] ?? ''), 'zip' => (string) ($r['zip'] ?? ''),
                'address' => (string) ($r['address'] ?? ''), 'email' => (string) ($r['email'] ?? ''),
                'phone' => (string) ($r['phone'] ?? ''), 'website' => (string) ($r['website'] ?? ''),
            ], $st->fetchAll(PDO::FETCH_ASSOC));
            $cnt = $pdo->prepare("SELECT COUNT(*) FROM asso_directory WHERE $where");
            $cnt->execute($args);
            echo json_encode(['ok' => true, 'view' => 'list', 'dept' => $dept, 'category' => $cat,
                              'page' => $page, 'total' => (int) $cnt->fetchColumn(), 'assos' => $rows], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // Vue région : ses départements comptés (+ répartition par catégorie)
        if (!empty($_GET['region'])) {
            $region = (string) $_GET['region'];
            $st = $pdo->prepare("SELECT dept_code, dept_name, category, COUNT(*) n
                                 FROM asso_directory WHERE region = ?
                                 GROUP BY dept_code, dept_name, category ORDER BY dept_code");
            $st->execute([$region]);
            $depts = [];
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $dc = (string) $r['dept_code'];
                if (!isset($depts[$dc])) $depts[$dc] = ['code' => $dc, 'name' => (string) $r['dept_name'], 'total' => 0, 'by_cat' => []];
                $depts[$dc]['by_cat'][(string) $r['category']] = (int) $r['n'];
                $depts[$dc]['total'] += (int) $r['n'];
            }
            echo json_encode(['ok' => true, 'view' => 'region', 'region' => $region, 'depts' => array_values($depts)], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // Vue racine : régions comptées
        $tree = [];
        foreach ($pdo->query("SELECT region, COUNT(*) n FROM asso_directory WHERE region IS NOT NULL AND region <> '' GROUP BY region ORDER BY region")->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $tree[] = ['region' => (string) $r['region'], 'total' => (int) $r['n']];
        }
        $total = (int) $pdo->query("SELECT COUNT(*) FROM asso_directory")->fetchColumn();
        $with_email = (int) $pdo->query("SELECT COUNT(*) FROM asso_directory WHERE email IS NOT NULL AND email <> ''")->fetchColumn();
        $cat_labels = [];
        foreach ($CATS as $k => $v) $cat_labels[$k] = $v['label'];
        echo json_encode(['ok' => true, 'view' => 'root', 'regions' => $tree, 'total' => $total,
                          'with_email' => $with_email, 'categories' => $cat_labels], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) { error_log('[app-founder-directory] ' . $e->getMessage()); dir_fail(500, 'server'); }
    exit;
}

// --- POST ---
$raw = file_get_contents('php://input');
$input = json_decode((string) $raw, true);
if (!is_array($input)) $input = [];
$token = (string) ($input['csrf'] ?? '');
if (empty($_SESSION['csrf_token']) || !hash_equals((string) $_SESSION['csrf_token'], $token)) dir_fail(403, 'csrf', 'Session expirée.');
$act = (string) ($input['action'] ?? '');

try {
    if ($act === 'set_email') {
        $id = (int) ($input['id'] ?? 0);
        $email = strtolower(trim((string) ($input['email'] ?? '')));
        if ($id <= 0) dir_fail(400, 'id');
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) dir_fail(400, 'email', 'Email invalide.');
        $pdo->prepare("UPDATE asso_directory SET email = ? WHERE id = ?")->execute([$email ?: null, $id]);
        echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($act === 'to_prospect') {
        // Promeut une asso de l'annuaire (avec email légitime) vers la prospection conforme.
        ak_prospect_tables_ensure($pdo);
        $id = (int) ($input['id'] ?? 0);
        if ($id <= 0) dir_fail(400, 'id');
        $st = $pdo->prepare("SELECT org_name, email, city FROM asso_directory WHERE id = ? LIMIT 1");
        $st->execute([$id]);
        $d = $st->fetch(PDO::FETCH_ASSOC);
        if (!$d) dir_fail(404, 'not_found');
        $email = strtolower(trim((string) ($d['email'] ?? '')));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) dir_fail(400, 'no_email', 'Renseigne d\'abord un email légitime pour cette association.');
        $ins = $pdo->prepare("INSERT INTO asso_prospects (name, org_name, type, email, city, source, status, created_at)
                              VALUES (?, ?, 'asso', ?, ?, 'annuaire', 'new', NOW())
                              ON DUPLICATE KEY UPDATE id = id");
        $ins->execute([null, (string) $d['org_name'], $email, (string) ($d['city'] ?? '')]);
        echo json_encode(['ok' => true, 'promoted' => $ins->rowCount() > 0], JSON_UNESCAPED_UNICODE);
        exit;
    }

    dir_fail(400, 'action', 'Action inconnue.');
} catch (Throwable $e) {
    error_log('[app-founder-directory] ' . $e->getMessage());
    dir_fail(500, 'server', 'Opération impossible.');
}
