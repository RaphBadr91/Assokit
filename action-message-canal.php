<?php
/**
 * ============================================================
 * ASSOKIT — Actions sur messages de canaux (v2 avec @mentions)
 * ============================================================
 * Gère :
 *   - send : envoyer un message dans un canal + détection @mentions
 *   - delete : supprimer son propre message (ou n'importe quel si admin)
 * 
 * v2 : détecte les @mentions et envoie email + crée notif in-app
 * ============================================================
 */
require_once __DIR__ . '/config.php';

// Chargement optionnel des helpers de notification
if (file_exists(__DIR__ . '/projet-email-helpers.php')) {
    require_once __DIR__ . '/projet-email-helpers.php';
}
if (file_exists(__DIR__ . '/notification-helpers.php')) {
    require_once __DIR__ . '/notification-helpers.php';
}

require_login();
$user = current_user();
$user_id = (int)$user['id'];
$org_id = (int)$user['org_id'];
$is_admin = ($user['role'] === 'admin');
$is_coord = ($user['role'] === 'coordinator');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /messages');
    exit;
}

if (!check_csrf($_POST['csrf_token'] ?? '')) {
    header('Location: /messages?error=csrf');
    exit;
}

$action = $_POST['action'] ?? '';
$channel_slug = $_POST['channel_slug'] ?? '';
$redirect_back = '/messages' . ($channel_slug ? '?c=' . urlencode($channel_slug) : '');

// =========================================================
// ACTION : Envoyer un message
// =========================================================
if ($action === 'send') {
    $channel_id = (int)($_POST['channel_id'] ?? 0);
    $content = trim($_POST['content'] ?? '');

    if ($channel_id <= 0 || $content === '') {
        header('Location: ' . $redirect_back);
        exit;
    }

    // Limite de longueur
    if (mb_strlen($content) > 8000) {
        header('Location: ' . $redirect_back . '&error=too_long');
        exit;
    }

    // Charger le canal et vérifier qu'il appartient à l'org
    $stmt = $pdo->prepare("SELECT * FROM channels WHERE id = ? AND org_id = ? AND is_archived = 0");
    $stmt->execute([$channel_id, $org_id]);
    $channel = $stmt->fetch();

    if (!$channel) {
        header('Location: /messages?error=notfound');
        exit;
    }

    // Vérifier les droits d'écriture selon le type de canal
    $can_write = false;
    if ($channel['type'] === 'announce') {
        $can_write = $is_admin || $is_coord;
    } elseif ($channel['type'] === 'private') {
        if ($is_admin) {
            $can_write = true;
        } else {
            $check = $pdo->prepare("SELECT id FROM channel_members WHERE channel_id = ? AND user_id = ?");
            $check->execute([$channel_id, $user_id]);
            $can_write = (bool)$check->fetch();
        }
    } else {
        // Public : tout le monde peut écrire
        $can_write = true;
    }

    if (!$can_write) {
        header('Location: ' . $redirect_back . '&error=no_write');
        exit;
    }

    // [reply-to] Récupérer le message cité (optionnel) + vérif sécurité
    $reply_to_id = !empty($_POST['reply_to_message_id']) ? (int)$_POST['reply_to_message_id'] : null;
    if ($reply_to_id) {
        $check_reply = $pdo->prepare("SELECT id FROM channel_messages WHERE id = ? AND channel_id = ? AND deleted_at IS NULL");
        $check_reply->execute([$reply_to_id, $channel_id]);
        if (!$check_reply->fetch()) {
            $reply_to_id = null; // silently drop si hors canal ou supprime
        }
    }

    // Insertion du message
    $stmt = $pdo->prepare("
        INSERT INTO channel_messages (channel_id, user_id, content, reply_to_message_id)
        VALUES (?, ?, ?, ?)
    ");
    $stmt->execute([$channel_id, $user_id, $content, $reply_to_id]);

    $new_msg_id = (int)$pdo->lastInsertId();

    // Marquer comme lu pour l'auteur
    $pdo->prepare("
        INSERT INTO channel_reads (channel_id, user_id, last_read_message_id, last_read_at)
        VALUES (?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE last_read_message_id = VALUES(last_read_message_id), last_read_at = NOW()
    ")->execute([$channel_id, $user_id, $new_msg_id]);

    // ==========================================================
    // [NEW v2] DÉTECTER LES @MENTIONS + EMAIL + NOTIFS
    // ==========================================================
    if (preg_match_all('/(?:^|\s)@([a-zA-ZÀ-ÿ][a-zA-ZÀ-ÿ\.\-]+)/u', $content, $matches)) {
        $mentions_raw = array_unique($matches[1]);
        
        // Charger les membres potentiels selon le type de canal
        if ($channel['type'] === 'private') {
            $stmt = $pdo->prepare("
                SELECT DISTINCT u.id, u.first_name, u.last_name, u.email
                FROM users u
                WHERE u.is_active = 1 AND u.org_id = :org
                  AND (
                      u.id IN (SELECT user_id FROM channel_members WHERE channel_id = :cid)
                      OR u.role = 'admin'
                  )
            ");
            $stmt->execute([':org' => $org_id, ':cid' => $channel_id]);
        } else {
            $stmt = $pdo->prepare("
                SELECT id, first_name, last_name, email
                FROM users
                WHERE is_active = 1 AND org_id = ?
            ");
            $stmt->execute([$org_id]);
        }
        $candidates = $stmt->fetchAll();
        
        // Faire le matching prenom/prenom.nom
        $matched_user_ids = [];
        foreach ($mentions_raw as $mention) {
            $m_lower = mb_strtolower($mention);
            foreach ($candidates as $c) {
                $first_lower = mb_strtolower($c['first_name']);
                $last_lower = mb_strtolower($c['last_name']);
                if ($m_lower === $first_lower 
                    || $m_lower === $first_lower . '.' . $last_lower
                    || str_starts_with($m_lower, $first_lower . '.')) {
                    if ((int)$c['id'] !== $user_id) {
                        $matched_user_ids[(int)$c['id']] = $c;
                    }
                    break;
                }
            }
        }
        
        // Pour chaque mentionné : envoyer email + créer notif
        $author_name = trim($user['first_name'] . ' ' . $user['last_name']);
        $excerpt = mb_substr($content, 0, 200);
        if (mb_strlen($content) > 200) $excerpt .= '…';
        
        foreach ($matched_user_ids as $mentioned_id => $mentioned_user) {
            // 1. Email (réutilise ak_asso_send_resend si dispo)
            if (!empty($mentioned_user['email']) && function_exists('ak_asso_send_resend') && function_exists('ak_email_template_wrap')) {
                try {
                    $project_url = 'https://assokit.fr/messages?c=' . urlencode($channel['slug']);
                    $subject = "💬 " . $author_name . " vous a mentionné dans #" . $channel['name'];
                    
                    $html = ak_email_template_wrap(
                        "👋 Bonjour " . htmlspecialchars($mentioned_user['first_name']) . ",",
                        "<strong>" . htmlspecialchars($author_name) . "</strong> vous a mentionné(e) dans le canal #" . htmlspecialchars($channel['name']) . " :" .
                        "<br><br>
                        <div style='background:#f3f4f6;padding:20px;border-radius:8px;border-left:4px solid #8B5CF6;margin:16px 0;'>
                            <div style='font-size:11px;color:#64748B;text-transform:uppercase;letter-spacing:0.05em;margin-bottom:4px;'>#" . htmlspecialchars($channel['name']) . "</div>
                            <div style='font-size:14px;color:#334155;line-height:1.5;'>" . nl2br(htmlspecialchars($excerpt)) . "</div>
                        </div>",
                        $project_url,
                        "Voir le canal",
                        "AssoKit"
                    );
                    
                    ak_asso_send_resend($mentioned_user['email'], $subject, $html, null, null, 'AssoKit');
                } catch (Throwable $e) {
                    error_log('[action-message-canal] Email mention : ' . $e->getMessage());
                }
            }
            
            // 2. Notif in-app
            if (function_exists('ak_notif_create')) {
                try {
                    ak_notif_create(
                        $pdo, (int)$mentioned_id, 'mention',
                        $author_name . ' vous a mentionné dans #' . $channel['name'],
                        $excerpt,
                        '/messages?c=' . $channel['slug'],
                        null, null, null, $user_id
                    );
                } catch (Throwable $e) {
                    error_log('[action-message-canal] Notif mention : ' . $e->getMessage());
                }
            }
        }
    }
    // ==========================================================
    // [NEW v2] NOTIFS IN-APP POUR LES AUTRES MEMBRES DU CANAL
    // (pour qu'ils voient qu'il y a un nouveau message)
    // ==========================================================
    if (function_exists('ak_notif_create')) {
        try {
            $author_name = $author_name ?? trim($user['first_name'] . ' ' . $user['last_name']);
            $excerpt_short = mb_substr($content, 0, 150);
            if (mb_strlen($content) > 150) $excerpt_short .= '…';
            
            // Liste des destinataires (selon type canal)
            if ($channel['type'] === 'private') {
                $stmt = $pdo->prepare("
                    SELECT user_id FROM channel_members 
                    WHERE channel_id = ? AND user_id != ?
                ");
                $stmt->execute([$channel_id, $user_id]);
            } else {
                // Public/announce : tous les actifs de l'org sauf l'auteur
                $stmt = $pdo->prepare("
                    SELECT id AS user_id FROM users 
                    WHERE org_id = ? AND is_active = 1 AND id != ?
                ");
                $stmt->execute([$org_id, $user_id]);
            }
            $member_ids = array_column($stmt->fetchAll(), 'user_id');
            
            // Filtrer ceux déjà mentionnés (eux ont une notif "mention" prioritaire)
            $already_mentioned = isset($matched_user_ids) ? array_keys($matched_user_ids) : [];
            $member_ids = array_diff($member_ids, $already_mentioned);
            
            foreach ($member_ids as $mid) {
                ak_notif_create(
                    $pdo, (int)$mid, 'message',
                    $author_name . ' a écrit dans #' . $channel['name'],
                    $excerpt_short,
                    '/messages?c=' . $channel['slug'],
                    null, null, null, $user_id
                );
            }
        } catch (Throwable $e) {
            error_log('[action-message-canal] Notifs canal : ' . $e->getMessage());
        }
    }

    header('Location: ' . $redirect_back);
    exit;
}

// =========================================================
// ACTION : Supprimer un message
// =========================================================
if ($action === 'delete') {
    $message_id = (int)($_POST['message_id'] ?? 0);
    if ($message_id <= 0) {
        header('Location: ' . $redirect_back);
        exit;
    }

    // Charger le message + vérifier qu'il est dans un canal de l'org
    $stmt = $pdo->prepare("
        SELECT cm.*, c.org_id
        FROM channel_messages cm
        JOIN channels c ON cm.channel_id = c.id
        WHERE cm.id = ?
    ");
    $stmt->execute([$message_id]);
    $msg = $stmt->fetch();

    if (!$msg || $msg['org_id'] != $org_id) {
        header('Location: ' . $redirect_back . '&error=notfound');
        exit;
    }

    // Permissions : auteur du message OU admin
    $can_delete = ($msg['user_id'] == $user_id) || $is_admin;
    if (!$can_delete) {
        header('Location: ' . $redirect_back . '&error=permission');
        exit;
    }

    // Soft delete (on garde l'historique pour les comptes-rendus IA passés)
    $pdo->prepare("UPDATE channel_messages SET deleted_at = NOW() WHERE id = ?")
        ->execute([$message_id]);

    header('Location: ' . $redirect_back . '&deleted=1');
    exit;
}

header('Location: /messages');
exit;
