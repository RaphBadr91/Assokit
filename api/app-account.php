<?php
/**
 * api/app-account.php — Infos du compte + org pour l'ecran Parametres natif. JSON.
 * NE MODIFIE PAS le site.
 */
ob_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes-layout.php';
ob_end_clean();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'auth']);
    exit;
}

try {
    $user = function_exists('current_user') ? current_user() : null;
    $uid = (int) ($user['id'] ?? ($_SESSION['user_id'] ?? 0));
    $org_id = (int) ($user['org_id'] ?? ($_SESSION['org_id'] ?? 0));

    $st = $pdo->prepare("SELECT first_name, last_name, email, phone, city, role, is_founder, is_platform_admin FROM users WHERE id = ? LIMIT 1");
    $st->execute([$uid]);
    $u = $st->fetch(PDO::FETCH_ASSOC) ?: [];

    $org_name = ''; $org_logo = null;
    try {
        $st = $pdo->prepare("SELECT name, logo_path FROM organizations WHERE id = ? LIMIT 1");
        $st->execute([$org_id]);
        $o = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        $org_name = (string) ($o['name'] ?? '');
        $lp = trim((string) ($o['logo_path'] ?? ''));
        if ($lp !== '') $org_logo = (strpos($lp, 'http') === 0) ? $lp : 'https://assokit.fr/' . ltrim($lp, '/');
    } catch (Throwable $e) {}

    $can_delete = empty($u['is_founder']) && empty($u['is_platform_admin']);

    echo json_encode([
        'ok' => true,
        'account' => [
            'first_name' => (string) ($u['first_name'] ?? ''),
            'last_name'  => (string) ($u['last_name'] ?? ''),
            'email'      => (string) ($u['email'] ?? ''),
            'phone'      => (string) ($u['phone'] ?? ''),
            'city'       => (string) ($u['city'] ?? ''),
            'role'       => (string) ($u['role'] ?? 'member'),
            'can_delete' => $can_delete,
        ],
        'org' => ['name' => $org_name, 'logo' => $org_logo, 'is_admin' => (($u['role'] ?? '') === 'admin')],
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('[api/app-account] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'server']);
}
