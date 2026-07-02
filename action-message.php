<?php
/**
 * ============================================================
 * ASSOKIT — Action : envoyer un message dans un projet (v3)
 * ============================================================
 * NOUVEAUTÉ v3 : si appelé via AJAX (X-Requested-With), réponse JSON
 * Sinon, comportement classique (redirect)
 * Détecte les @mentions et envoie email aux mentionnés.
 * ============================================================
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/projet-email-helpers.php';

require_login();

$is_ajax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) 
    && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

function respond_error(string $msg, int $code = 400, bool $is_ajax = false, int $project_id = 0): void {
    if ($is_ajax) {
        http_response_code($code);
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'error' => $msg]);
        exit;
    }
    http_response_code($code);
    die($msg);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    if ($is_ajax) {
        respond_error('method_not_allowed', 405, true);
    }
    header('Location: /projets');
    exit;
}

if (!check_csrf($_POST['csrf_token'] ?? '')) {
    respond_error('csrf', 400, $is_ajax);
}

$project_id = (int)($_POST['project_id'] ?? 0);
$content = trim($_POST['content'] ?? '');

if ($project_id <= 0) {
    respond_error('invalid_project', 400, $is_ajax);
}

if ($content === '' || mb_strlen($content) > 5000) {
    if ($is_ajax) {
        respond_error('invalid_content', 400, true);
    }
    header('Location: /projet/' . $project_id . '/messages');
    exit;
}

$user = current_user();

// Vérifier appartenance org
$stmt = $pdo->prepare("
    SELECT p.id FROM projects p
    JOIN folders f ON p.folder_id = f.id
    WHERE p.id = ? AND f.org_id = ?
");
$stmt->execute([$project_id, $user['org_id']]);
if (!$stmt->fetch()) {
    respond_error('forbidden', 403, $is_ajax);
}

// =============================================================
// INSÉRER LE MESSAGE
// =============================================================
try {
    $pdo->prepare("
        INSERT INTO project_messages (project_id, author_id, content, message_type)
        VALUES (?, ?, ?, 'text')
    ")->execute([$project_id, $user['id'], $content]);
    $message_id = (int)$pdo->lastInsertId();
} catch (Throwable $e) {
    respond_error('insert_failed', 500, $is_ajax);
}

// =============================================================
// DÉTECTER LES @MENTIONS ET ENVOYER LES EMAILS
// =============================================================
$mentioned_user_ids = [];
try {
    $mentioned_user_ids = ak_extract_mentions($pdo, $project_id, $content);
    $mentioned_user_ids = array_filter($mentioned_user_ids, fn($id) => $id !== (int)$user['id']);
    
    foreach ($mentioned_user_ids as $mentioned_uid) {
        try {
            ak_email_message_mention($pdo, $message_id, $mentioned_uid);
        } catch (Throwable $e) {
            error_log('[action-message] Email mention : ' . $e->getMessage());
        }
    }
} catch (Throwable $e) {
    error_log('[action-message] Mentions : ' . $e->getMessage());
}

// =============================================================
// CRÉER NOTIFS IN-APP POUR LES AUTRES MEMBRES DU PROJET
// (Sauf ceux déjà mentionnés qui ont une notif "mention")
// =============================================================
if (file_exists(__DIR__ . '/notification-helpers.php')) {
    require_once __DIR__ . '/notification-helpers.php';
    
    if (function_exists('ak_notif_create')) {
        try {
            // Charger nom du projet + auteur
            $stmt = $pdo->prepare("
                SELECT p.name AS project_name, p.referent_id
                FROM projects p WHERE p.id = ?
            ");
            $stmt->execute([$project_id]);
            $proj = $stmt->fetch();
            $author_name = trim($user['first_name'] . ' ' . $user['last_name']);
            
            // Liste des membres à notifier (équipe + référent)
            $stmt = $pdo->prepare("
                SELECT DISTINCT u.id 
                FROM users u
                WHERE u.is_active = 1
                  AND u.id != :author
                  AND (
                      u.id IN (SELECT user_id FROM project_members WHERE project_id = :pid1)
                      OR u.id = :ref
                  )
            ");
            $stmt->execute([
                ':author' => $user['id'],
                ':pid1' => $project_id,
                ':ref' => (int)($proj['referent_id'] ?? 0),
            ]);
            $members_to_notify = array_column($stmt->fetchAll(), 'id');
            
            // Filtrer ceux déjà mentionnés (ils ont une notif type 'mention' plus prioritaire)
            $members_to_notify = array_diff($members_to_notify, $mentioned_user_ids);
            
            // Créer une notif "message" pour chacun
            $excerpt = mb_substr($content, 0, 200);
            if (mb_strlen($content) > 200) $excerpt .= '…';
            
            foreach ($members_to_notify as $member_id) {
                ak_notif_create(
                    $pdo, (int)$member_id, 'message',
                    $author_name . ' a écrit dans « ' . ($proj['project_name'] ?? '?') . ' »',
                    $excerpt,
                    '/projet/' . $project_id . '/messages',
                    $project_id, $message_id, null, (int)$user['id']
                );
            }
        } catch (Throwable $e) {
            error_log('[action-message] Notifs in-app : ' . $e->getMessage());
        }
    }
}

// =============================================================
// RÉPONSE
// =============================================================
if ($is_ajax) {
    // Renvoyer le message tout juste créé pour affichage immédiat
    $stmt = $pdo->prepare("
        SELECT m.id, m.content, m.created_at, m.author_id,
               u.first_name, u.last_name, u.avatar_color
        FROM project_messages m
        JOIN users u ON m.author_id = u.id
        WHERE m.id = ?
    ");
    $stmt->execute([$message_id]);
    $msg = $stmt->fetch();
    
    header('Content-Type: application/json');
    echo json_encode([
        'ok' => true,
        'message' => [
            'id'         => (int)$msg['id'],
            'author_id'  => (int)$msg['author_id'],
            'first_name' => $msg['first_name'],
            'last_name'  => $msg['last_name'],
            'avatar_color' => $msg['avatar_color'] ?? 'blue',
            'initials'   => mb_strtoupper(mb_substr($msg['first_name'], 0, 1) . mb_substr($msg['last_name'], 0, 1)),
            'content'    => $msg['content'],
            'created_at' => $msg['created_at'],
            'time_label' => "à l'instant",
            'is_self'    => true,
        ],
        'mentions_sent' => count($mentioned_user_ids ?? []),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// Mode classique
header('Location: /projet/' . $project_id . '/messages');
exit;
