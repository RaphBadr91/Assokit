<?php
/**
 * ============================================================
 * ASSOKIT — Helpers pour le système de notifications
 * ============================================================
 * Fonctions disponibles :
 *   - ak_notif_create() : créer une notification
 *   - ak_notif_count_unread() : compter les non-lues d'un user
 *   - ak_notif_get_recent() : liste des dernières
 *   - ak_notif_mark_read() : marquer comme lue
 *   - ak_notif_mark_all_read() : marquer toutes comme lues
 *   - ak_project_unread_count() : nb messages non lus dans un projet
 *   - ak_project_mark_read() : marquer projet comme lu
 *   - ak_user_unread_by_project() : map [project_id => count]
 * ============================================================
 */

/**
 * Crée une notification pour un user.
 */
function ak_notif_create(
    PDO $pdo,
    int $user_id,
    string $type,
    string $title,
    string $body = '',
    ?string $link_url = null,
    ?int $project_id = null,
    ?int $message_id = null,
    ?int $step_id = null,
    ?int $actor_id = null
): bool {
    if ($user_id <= 0 || $type === '' || $title === '') return false;
    
    try {
        $stmt = $pdo->prepare("
            INSERT INTO user_notifications 
                (user_id, notification_type, title, body, link_url, 
                 project_id, message_id, step_id, actor_id)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        return $stmt->execute([
            $user_id, $type, mb_substr($title, 0, 200), 
            $body ? mb_substr($body, 0, 500) : null,
            $link_url, $project_id, $message_id, $step_id, $actor_id
        ]);
    } catch (Throwable $e) {
        error_log('[ak_notif_create] ' . $e->getMessage());
        return false;
    }
}

/**
 * Compte les notifications non lues d'un user.
 */
function ak_notif_count_unread(PDO $pdo, int $user_id): int {
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM user_notifications 
            WHERE user_id = ? AND is_read = 0
        ");
        $stmt->execute([$user_id]);
        return (int)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

/**
 * Récupère les dernières notifications d'un user.
 */
function ak_notif_get_recent(PDO $pdo, int $user_id, int $limit = 10, bool $only_unread = false): array {
    try {
        $where_unread = $only_unread ? 'AND n.is_read = 0' : '';
        $stmt = $pdo->prepare("
            SELECT n.id, n.notification_type, n.title, n.body, n.link_url,
                   n.is_read, n.read_at, n.created_at,
                   n.project_id, n.message_id, n.step_id, n.actor_id,
                   u.first_name AS actor_first, u.last_name AS actor_last,
                   u.avatar_color AS actor_color
            FROM user_notifications n
            LEFT JOIN users u ON n.actor_id = u.id
            WHERE n.user_id = ? $where_unread
            ORDER BY n.created_at DESC
            LIMIT $limit
        ");
        $stmt->execute([$user_id]);
        return $stmt->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Marque une notification comme lue.
 */
function ak_notif_mark_read(PDO $pdo, int $notif_id, int $user_id): bool {
    try {
        $stmt = $pdo->prepare("
            UPDATE user_notifications 
            SET is_read = 1, read_at = NOW() 
            WHERE id = ? AND user_id = ?
        ");
        return $stmt->execute([$notif_id, $user_id]);
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Marque TOUTES les notifications d'un user comme lues.
 */
function ak_notif_mark_all_read(PDO $pdo, int $user_id): bool {
    try {
        $stmt = $pdo->prepare("
            UPDATE user_notifications 
            SET is_read = 1, read_at = NOW() 
            WHERE user_id = ? AND is_read = 0
        ");
        return $stmt->execute([$user_id]);
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Compte les messages non lus dans un projet pour un user.
 */
function ak_project_unread_count(PDO $pdo, int $user_id, int $project_id): int {
    try {
        $stmt = $pdo->prepare("
            SELECT last_read_message_id FROM user_project_reads
            WHERE user_id = ? AND project_id = ?
        ");
        $stmt->execute([$user_id, $project_id]);
        $last_read = (int)$stmt->fetchColumn();
        
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM project_messages
            WHERE project_id = ? AND id > ? AND author_id != ?
              AND message_type = 'text'
        ");
        $stmt->execute([$project_id, $last_read, $user_id]);
        return (int)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

/**
 * Marque un projet comme lu jusqu'au dernier message.
 */
function ak_project_mark_read(PDO $pdo, int $user_id, int $project_id): bool {
    try {
        $stmt = $pdo->prepare("
            SELECT MAX(id) FROM project_messages WHERE project_id = ?
        ");
        $stmt->execute([$project_id]);
        $last_id = (int)$stmt->fetchColumn();
        
        if ($last_id <= 0) return true;
        
        $stmt = $pdo->prepare("
            INSERT INTO user_project_reads (user_id, project_id, last_read_message_id)
            VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE 
                last_read_message_id = GREATEST(last_read_message_id, VALUES(last_read_message_id)),
                last_read_at = NOW()
        ");
        return $stmt->execute([$user_id, $project_id, $last_id]);
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Map [project_id => unread_count] pour tous les projets de l'org.
 * Optimisé : 1 seule requête SQL.
 */
function ak_user_unread_by_project(PDO $pdo, int $user_id, int $org_id): array {
    try {
        $stmt = $pdo->prepare("
            SELECT 
                p.id AS project_id,
                COUNT(m.id) AS unread_count
            FROM projects p
            JOIN folders f ON p.folder_id = f.id
            LEFT JOIN user_project_reads upr 
                ON upr.user_id = :uid AND upr.project_id = p.id
            LEFT JOIN project_messages m 
                ON m.project_id = p.id 
                AND m.id > COALESCE(upr.last_read_message_id, 0)
                AND m.author_id != :uid2
                AND m.message_type = 'text'
            WHERE f.org_id = :org_id 
              AND p.archived_at IS NULL
            GROUP BY p.id
            HAVING unread_count > 0
        ");
        $stmt->execute([':uid' => $user_id, ':uid2' => $user_id, ':org_id' => $org_id]);
        
        $result = [];
        foreach ($stmt->fetchAll() as $row) {
            $result[(int)$row['project_id']] = (int)$row['unread_count'];
        }
        return $result;
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Helper d'affichage : icône et couleur selon type de notif
 */
function ak_notif_icon(string $type): array {
    $map = [
        'message'      => ['icon' => '💬', 'color' => '#3B82F6', 'label' => 'Message'],
        'mention'      => ['icon' => '🏷️', 'color' => '#EF4444', 'label' => 'Mention'],
        'team_added'   => ['icon' => '👥', 'color' => '#10B981', 'label' => 'Équipe'],
        'step_assigned'=> ['icon' => '📝', 'color' => '#F59E0B', 'label' => 'Étape'],
    ];
    return $map[$type] ?? ['icon' => '🔔', 'color' => '#64748B', 'label' => 'Notification'];
}

/**
 * Format relatif : "il y a 5 min", "hier 14:30", etc.
 */
function ak_notif_time_ago(string $datetime): string {
    try {
        $dt = new DateTime($datetime);
        $now = new DateTime();
        $diff = $now->getTimestamp() - $dt->getTimestamp();
        
        if ($diff < 60) return "à l'instant";
        if ($diff < 3600) return "il y a " . floor($diff / 60) . " min";
        if ($diff < 86400) return "il y a " . floor($diff / 3600) . "h";
        if ($diff < 604800) return "il y a " . floor($diff / 86400) . "j";
        
        return $dt->format('d/m/Y');
    } catch (Throwable $e) {
        return $datetime;
    }
}
