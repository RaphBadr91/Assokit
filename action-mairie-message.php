<?php
/**
 * Endpoint POST envoi message dans un channel mairie
 * URL : /action-mairie-message
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/activity-tracker.php';  // journal fondateur : qui a fait quoi, à la minute près
activity_log_request();
require_once __DIR__ . '/includes-layout.php';
require_once __DIR__ . '/includes-permissions.php';
require_once __DIR__ . '/includes-mairie-channels.php';
require_once __DIR__ . '/notification-helpers.php';
require_once __DIR__ . '/resend-helper.php';
if (function_exists('ak_session_guard')) ak_session_guard(); // agent retiré depuis sa connexion : session fermée tout de suite
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /mairie-messages');
    exit;
}
if (!check_csrf($_POST['csrf_token'] ?? '')) {
    http_response_code(403); die('CSRF token invalide');
}

$current = current_user();
$user_id = (int)$current['id'];
$channel_id = (int)($_POST['channel_id'] ?? 0);
$content = trim($_POST['content'] ?? '');

if ($channel_id <= 0) {
    $_SESSION['flash_error'] = "Channel invalide.";
    header('Location: /mairie-messages'); exit;
}
if ($content === '') {
    $_SESSION['flash_error'] = "Message vide.";
    header('Location: /mairie-messages?channel=' . $channel_id); exit;
}
if (mb_strlen($content) > 5000) {
    $_SESSION['flash_error'] = "Message trop long (max 5000 caractères).";
    header('Location: /mairie-messages?channel=' . $channel_id); exit;
}

try {
    // === Sécurité : canal mairie uniquement ; l'auteur est un agent de la mairie
    // du canal (rattachement relu en base : un agent n'a pas d'org_id) ou un
    // membre du canal appartenant à l'asso du canal ===
    $stmt = $pdo->prepare("
        SELECT c.id, c.org_id, c.parent_org_id, c.slug, c.is_mairie_channel, c.is_archived,
               (SELECT COUNT(*) FROM channel_members cm WHERE cm.channel_id = c.id AND cm.user_id = ?) AS is_member
        FROM channels c
        WHERE c.id = ? AND c.is_mairie_channel = 1
        LIMIT 1
    ");
    $stmt->execute([$user_id, $channel_id]);
    $channel = $stmt->fetch(PDO::FETCH_ASSOC);

    $parent = $channel ? current_parent_org() : null;
    $is_agent = $parent && (int)$parent['id'] === (int)$channel['parent_org_id'];
    $my_org = (int)($current['org_id'] ?? 0);
    $is_asso = $channel && $my_org > 0 && $my_org === (int)$channel['org_id'] && (int)$channel['is_member'] > 0;
    if (!$channel || (!$is_agent && !$is_asso)) {
        http_response_code(403);
        die("Vous n'êtes pas membre de ce channel.");
    }
    if ($channel['is_archived']) {
        $_SESSION['flash_error'] = "Ce channel est archivé.";
        header('Location: /mairie-messages'); exit;
    }
    $slug = (string)$channel['slug'];

    // Membres à jour (agents actifs + admins actuels de l'asso, y compris un admin
    // nommé après le rattachement) avant d'écrire et de prévenir
    try { sync_mairie_channel_members($pdo, $channel_id, (int)$channel['org_id'], (int)$channel['parent_org_id']); }
    catch (Throwable $e) { error_log('[action-mairie-message] sync: ' . $e->getMessage()); }

    // === Insérer le message ===
    $pdo->prepare("
        INSERT INTO channel_messages (channel_id, user_id, content, is_edited, is_pinned, created_at, updated_at)
        VALUES (?, ?, ?, 0, 0, NOW(), NOW())
    ")->execute([$channel_id, $user_id, $content]);

    // === Mettre à jour le timestamp du channel ===
    $pdo->prepare("UPDATE channels SET updated_at = NOW() WHERE id = ?")->execute([$channel_id]);

    // Confirmation affichée par /mairie-messages ; /messages (côté asso) ne lit pas
    // ce flash, qui ressortirait sur une autre page
    if ($is_agent) $_SESSION['flash_success'] = "Message envoyé.";

    // === Notifications aux autres membres du channel ===
    try {
        // Destinataires : comptes actifs encore légitimes (agents de la mairie du canal,
        // membres de l'asso du canal) — pas un ancien agent resté dans la liste
        $stmt = $pdo->prepare("
            SELECT u.id, u.first_name, u.last_name, u.email, u.parent_org_id
            FROM channel_members cm
            INNER JOIN users u ON u.id = cm.user_id
            WHERE cm.channel_id = ?
              AND cm.user_id != ?
              AND u.is_active = 1
              AND u.deleted_at IS NULL
              AND (u.parent_org_id = ? OR u.org_id = ?)
        ");
        $stmt->execute([$channel_id, $user_id, (int)$channel['parent_org_id'], (int)$channel['org_id']]);
        $recipients = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $author_name = trim($current['first_name'] . ' ' . $current['last_name']);
        $is_author_mairie = $is_agent;
        $author_badge = $is_author_mairie ? 'Mairie' : 'Association';

        // Récupérer le nom de l'asso et de la mairie pour le contexte
        $ctx = $pdo->prepare("SELECT o.name AS org_name, p.name AS mairie_name FROM channels c LEFT JOIN organizations o ON o.id=c.org_id LEFT JOIN parent_orgs p ON p.id=c.parent_org_id WHERE c.id=?");
        $ctx->execute([$channel_id]);
        $ctx_data = $ctx->fetch(PDO::FETCH_ASSOC) ?: ['org_name' => '', 'mairie_name' => ''];

        $message_preview = mb_substr($content, 0, 200);
        $message_full = mb_substr($content, 0, 500);
        $is_truncated = mb_strlen($content) > 500;

        $new_message_id = (int)$pdo->lastInsertId();

        foreach ($recipients as $r) {
            // Agent : messagerie mairie (?channel=id) ; association : messagerie asso, qui lit le slug (?c=)
            $is_recipient_mairie = (int)($r['parent_org_id'] ?? 0) === (int)$channel['parent_org_id'];
            $link_path = $is_recipient_mairie
                ? '/mairie-messages?channel=' . $channel_id
                : '/messages?c=' . rawurlencode($slug);
            $link_url = 'https://assokit.fr' . $link_path;

            // 1. Notification in-app
            try {
                ak_notif_create(
                    $pdo,
                    (int)$r['id'],
                    'mairie_message',
                    "Nouveau message de $author_name ($author_badge)",
                    $message_preview,
                    $link_path,
                    null,
                    null,
                    null,
                    $user_id
                );
            } catch (Throwable $e) {
                error_log('[notif-inapp] ' . $e->getMessage());
            }

            // 2. Email Resend
            if (!empty($r['email']) && function_exists('send_transactional_email') && function_exists('email_wrap')) {
                try {
                    $subject = '💬 Nouveau message de ' . $author_name;
                    $context_label = $is_author_mairie
                        ? ('🏛️ ' . htmlspecialchars($ctx_data['mairie_name']))
                        : ('🏢 ' . htmlspecialchars($ctx_data['org_name']));
                    $body_html = '<p>Bonjour <strong>' . htmlspecialchars($r['first_name']) . '</strong>,</p>'
                        . '<p><strong>' . htmlspecialchars($author_name) . '</strong> (' . $context_label . ') vous a envoyé un nouveau message :</p>'
                        . '<blockquote style="border-left:3px solid #d97706;padding:14px 18px;margin:18px 0;background:#FFFBEB;color:#1C1917;font-style:italic;border-radius:0 6px 6px 0;">'
                        . nl2br(htmlspecialchars($message_full))
                        . ($is_truncated ? '<br><span style="color:#92400E;">…(message tronqué)</span>' : '')
                        . '</blockquote>'
                        . '<p style="color:#78716C;font-size:13px;">Vous recevez cet email car vous êtes membre d\'un canal de suivi entre une association et une mairie sur Assokit.</p>';
                    $html = email_wrap('Nouveau message sur Assokit', $body_html, 'Voir et répondre', $link_url);
                    $sender_name = $is_author_mairie ? ($ctx_data['mairie_name'] ?: 'Mairie') : ($ctx_data['org_name'] ?: 'Association');
                    send_transactional_email($r['email'], $subject, $html, ['tag' => 'mairie_message', 'from_name' => $sender_name]);
                } catch (Throwable $e) {
                    error_log('[notif-email] ' . $e->getMessage());
                }
            }
        }
    } catch (Throwable $e) {
        error_log('[mairie-msg-notif] ' . $e->getMessage());
    }

    // Redirection selon le contexte : agent mairie → mairie-messages, admin asso → messages (slug)
    if ($is_agent) {
        header('Location: /mairie-messages?channel=' . $channel_id);
    } else {
        header('Location: /messages?c=' . rawurlencode($slug));
    }
    exit;

} catch (Throwable $e) {
    error_log('[action-mairie-message] ' . $e->getMessage());
    $_SESSION['flash_error'] = "Erreur lors de l'envoi : " . $e->getMessage();
    header('Location: /mairie-messages?channel=' . $channel_id);
    exit;
}
