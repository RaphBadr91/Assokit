<?php
/**
 * Endpoint POST envoi message dans un channel mairie
 * URL : /action-mairie-message
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes-layout.php';
require_once __DIR__ . '/includes-permissions.php';
require_once __DIR__ . '/notification-helpers.php';
require_once __DIR__ . '/resend-helper.php';
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
    // === Sécurité : vérifier que l'utilisateur est membre du channel ===
    $stmt = $pdo->prepare("
        SELECT c.id, c.org_id, c.parent_org_id, c.is_mairie_channel, c.is_archived
        FROM channels c
        INNER JOIN channel_members cm ON cm.channel_id = c.id AND cm.user_id = ?
        WHERE c.id = ?
        LIMIT 1
    ");
    $stmt->execute([$user_id, $channel_id]);
    $channel = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$channel) {
        http_response_code(403);
        die("Vous n'êtes pas membre de ce channel.");
    }
    if ($channel['is_archived']) {
        $_SESSION['flash_error'] = "Ce channel est archivé.";
        header('Location: /mairie-messages'); exit;
    }

    // === Insérer le message ===
    $pdo->prepare("
        INSERT INTO channel_messages (channel_id, user_id, content, is_edited, is_pinned, created_at, updated_at)
        VALUES (?, ?, ?, 0, 0, NOW(), NOW())
    ")->execute([$channel_id, $user_id, $content]);

    // === Mettre à jour le timestamp du channel ===
    $pdo->prepare("UPDATE channels SET updated_at = NOW() WHERE id = ?")->execute([$channel_id]);

    $_SESSION['flash_success'] = "Message envoyé.";

    // === Notifications aux autres membres du channel ===
    try {
        $stmt = $pdo->prepare("
            SELECT u.id, u.first_name, u.last_name, u.email, u.parent_org_id
            FROM channel_members cm
            INNER JOIN users u ON u.id = cm.user_id
            WHERE cm.channel_id = ?
              AND cm.user_id != ?
              AND u.deleted_at IS NULL
        ");
        $stmt->execute([$channel_id, $user_id]);
        $recipients = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $author_name = trim($current['first_name'] . ' ' . $current['last_name']);
        $is_author_mairie = is_parent_org_user($current);
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
            $is_recipient_mairie = !empty($r['parent_org_id']);
            $link_path = $is_recipient_mairie ? '/mairie-messages' : '/messages';
            $link_url = 'https://assokit.fr' . $link_path . '?channel=' . $channel_id;

            // 1. Notification in-app
            try {
                ak_notif_create(
                    $pdo,
                    (int)$r['id'],
                    'mairie_message',
                    "Nouveau message de $author_name ($author_badge)",
                    $message_preview,
                    $link_path . '?channel=' . $channel_id,
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

    // Redirection selon le contexte : agent mairie → mairie-messages, admin asso → messages
    if (is_parent_org_user($current)) {
        header('Location: /mairie-messages?channel=' . $channel_id);
    } else {
        header('Location: /messages?channel=' . $channel_id);
    }
    exit;

} catch (Throwable $e) {
    error_log('[action-mairie-message] ' . $e->getMessage());
    $_SESSION['flash_error'] = "Erreur lors de l'envoi : " . $e->getMessage();
    header('Location: /mairie-messages?channel=' . $channel_id);
    exit;
}
