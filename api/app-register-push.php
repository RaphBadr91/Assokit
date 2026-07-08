<?php
/**
 * api/app-register-push.php — Enregistre le token de notifications push (Expo) de l'app.
 * Cree la table si besoin. Retour JSON. NE MODIFIE PAS le site.
 */
require __DIR__ . '/_app-write-boot.php';

$token = trim((string) ($input['token'] ?? ''));
$platform = (string) ($input['platform'] ?? 'ios');
if ($platform !== 'android') $platform = 'ios';
if ($token === '' || stripos($token, 'ExponentPushToken') === false) {
    app_fail(422, 'invalid', 'Token invalide.');
}

try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS asso_push_tokens (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            org_id INT NOT NULL,
            token VARCHAR(255) NOT NULL,
            platform VARCHAR(20) DEFAULT 'ios',
            last_pushed_notif_id INT DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_token (token),
            KEY idx_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    // Point de depart = derniere notif existante (pour ne pas spammer l'historique au 1er enregistrement)
    $maxId = 0;
    try {
        $st = $pdo->prepare("SELECT COALESCE(MAX(id),0) FROM user_notifications WHERE user_id = ?");
        $st->execute([$uid]);
        $maxId = (int) $st->fetchColumn();
    } catch (Throwable $e) {}

    $stmt = $pdo->prepare("
        INSERT INTO asso_push_tokens (user_id, org_id, token, platform, last_pushed_notif_id, created_at, updated_at)
        VALUES (:u, :o, :t, :p, :m, NOW(), NOW())
        ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), org_id = VALUES(org_id), platform = VALUES(platform), updated_at = NOW()
    ");
    $stmt->execute([':u' => $uid, ':o' => $org_id, ':t' => $token, ':p' => $platform, ':m' => $maxId]);

    echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('[app-register-push] ' . $e->getMessage());
    app_fail(500, 'server', 'Enregistrement impossible.');
}
