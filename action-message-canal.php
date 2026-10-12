<?php
/**
 * ============================================================
 * ASSOKIT — Actions sur messages de canaux (v2 avec @mentions)
 * ============================================================
 * Gère :
 *   - send : envoyer un message dans un canal + détection @mentions
 *   - delete : supprimer son propre message (ou n'importe quel si admin, sauf ceux
 *              des agents de la mairie sur le canal « Suivi · Mairie »)
 * 
 * v2 : détecte les @mentions et envoie email + crée notif in-app
 * ============================================================
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/activity-tracker.php';  // journal fondateur : qui a fait quoi, à la minute près
activity_log_request();

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

$action = $_POST['action'] ?? '';
$channel_slug = (string)($_POST['channel_slug'] ?? '');
$redirect_back = '/messages' . ($channel_slug !== '' ? '?c=' . urlencode($channel_slug) : '');
$redirect_sep = $channel_slug !== '' ? '&' : '?';

if (!check_csrf($_POST['csrf_token'] ?? '')) {
    // Retour sur le canal d'origine : le message saisi y est remis dans la zone de saisie
    header('Location: ' . $redirect_back . $redirect_sep . 'error=csrf');
    exit;
}

// Suiveurs (financeurs externes) : pas d'accès à la messagerie interne (même règle que le menu et messages.php)
if (is_follower()) {
    header('Location: /projets');
    exit;
}

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
        header('Location: ' . $redirect_back . $redirect_sep . 'error=too_long');
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
        header('Location: ' . $redirect_back . $redirect_sep . 'error=no_write');
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

    // Insertion du message (date posée explicitement : le fil est trié dessus)
    $stmt = $pdo->prepare("
        INSERT INTO channel_messages (channel_id, user_id, content, reply_to_message_id, created_at)
        VALUES (?, ?, ?, ?, NOW())
    ");
    $stmt->execute([$channel_id, $user_id, $content, $reply_to_id]);

    $new_msg_id = (int)$pdo->lastInsertId();

    // Marquer comme lu pour l'auteur
    $pdo->prepare("
        INSERT INTO channel_reads (channel_id, user_id, last_read_message_id, last_read_at)
        VALUES (?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE last_read_message_id = GREATEST(COALESCE(last_read_message_id, 0), VALUES(last_read_message_id)), last_read_at = NOW()
    ")->execute([$channel_id, $user_id, $new_msg_id]);

    // ==========================================================
    // @MENTIONS (notif + e-mail) PUIS NOTIFS IN-APP POUR LES AUTRES MEMBRES DU CANAL
    // Helper partagé avec l'appli (api/app-send-message.php) : même traitement partout,
    // agents de la mairie compris pour le canal « Suivi · Mairie ».
    // ==========================================================
    if (function_exists('ak_channel_message_notify')) {
        try {
            ak_channel_message_notify($pdo, $channel, $user, $content);
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
        SELECT cm.*, c.org_id, c.is_mairie_channel, u.org_id AS author_org_id
        FROM channel_messages cm
        JOIN channels c ON cm.channel_id = c.id
        LEFT JOIN users u ON u.id = cm.user_id
        WHERE cm.id = ?
    ");
    $stmt->execute([$message_id]);
    $msg = $stmt->fetch();

    if (!$msg || $msg['org_id'] != $org_id) {
        header('Location: ' . $redirect_back . $redirect_sep . 'error=notfound');
        exit;
    }

    // Permissions : auteur du message OU admin. Sur le canal « Suivi · Mairie », les messages
    // des agents de la mairie (hors de l'asso) ne peuvent être supprimés que par leur auteur.
    $is_mairie_agent_msg = !empty($msg['is_mairie_channel']) && (int)($msg['author_org_id'] ?? 0) !== $org_id;
    $can_delete = ($msg['user_id'] == $user_id) || ($is_admin && !$is_mairie_agent_msg);
    if (!$can_delete) {
        header('Location: ' . $redirect_back . $redirect_sep . 'error=permission');
        exit;
    }

    // Soft delete (on garde l'historique pour les comptes-rendus IA passés)
    $pdo->prepare("UPDATE channel_messages SET deleted_at = NOW() WHERE id = ?")
        ->execute([$message_id]);

    header('Location: ' . $redirect_back . $redirect_sep . 'deleted=1');
    exit;
}

header('Location: /messages');
exit;
