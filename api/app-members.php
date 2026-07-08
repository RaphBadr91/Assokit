<?php
/**
 * api/app-members.php — Liste des membres (adherents) pour l'ecran natif de l'app.
 * JSON, authentifie par session. NE MODIFIE PAS le site.
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

$ROLE_LABELS = [
    'admin'       => 'Admin',
    'coordinator' => 'Coordinateur',
    'referent'    => 'Référent',
    'member'      => 'Membre',
];

try {
    $user = function_exists('current_user') ? current_user() : null;
    $org_id = (int) ($user['org_id'] ?? ($_SESSION['org_id'] ?? 0));

    $stmt = $pdo->prepare("
        SELECT u.id, u.first_name, u.last_name, u.email, u.role,
               u.city, u.avatar_color, u.adhesion_valid_until
        FROM users u
        WHERE u.org_id = ? AND u.is_active = 1
          AND (u.deleted_at IS NULL OR u.deleted_at = '')
        ORDER BY
          CASE u.role
            WHEN 'admin' THEN 1
            WHEN 'coordinator' THEN 2
            WHEN 'referent' THEN 3
            ELSE 4
          END,
          u.last_name ASC, u.first_name ASC
        LIMIT 500
    ");
    $stmt->execute([$org_id]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $now = time();
    $members = [];
    foreach ($rows as $r) {
        $fn = trim((string) ($r['first_name'] ?? ''));
        $ln = trim((string) ($r['last_name'] ?? ''));
        $full = trim($fn . ' ' . $ln);
        $ini = strtoupper((function_exists('mb_substr') ? mb_substr($fn, 0, 1) : substr($fn, 0, 1))
                        . (function_exists('mb_substr') ? mb_substr($ln, 0, 1) : substr($ln, 0, 1)));
        if ($ini === '') $ini = '?';

        // Statut d'adhesion
        $valid = true;
        $vu = $r['adhesion_valid_until'] ?? null;
        if (!empty($vu) && $vu !== '0000-00-00' && $vu !== '0000-00-00 00:00:00') {
            $valid = (strtotime($vu) >= $now);
        }

        $role = (string) ($r['role'] ?? 'member');
        $members[] = [
            'id'       => (int) $r['id'],
            'name'     => $full !== '' ? $full : (string) $r['email'],
            'initials' => $ini,
            'email'    => (string) ($r['email'] ?? ''),
            'city'     => (string) ($r['city'] ?? ''),
            'role'     => $role,
            'role_label' => $ROLE_LABELS[$role] ?? ucfirst($role),
            'color'    => function_exists('folder_color_hex') ? folder_color_hex((string) ($r['avatar_color'] ?? 'blue')) : '#3B82F6',
            'up_to_date' => $valid,
        ];
    }

    echo json_encode(['ok' => true, 'members' => $members], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('[api/app-members] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'server']);
}
