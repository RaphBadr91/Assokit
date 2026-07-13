<?php
/**
 * api/app-founder-contact-reply.php — Répondre à une demande de contact par email (Fondateur).
 * POST { contact_id:int, body, csrf } → envoie un email au prospect + marque répondu.
 * NE MODIFIE PAS le site : dédié à l'application.
 */
require __DIR__ . '/_app-write-boot.php';
require_once __DIR__ . '/_app-founder.php';
require_once __DIR__ . '/_app-contact-token.php';
@require_once __DIR__ . '/../resend-helper.php';

$is_sa = app_is_founder($pdo, $user) || !empty($user['is_super_admin']) || (($user['role'] ?? '') === 'super_admin');
if (!$is_sa) app_fail(403, 'forbidden');

$id = (int) ($input['contact_id'] ?? 0);
$body = trim((string) ($input['body'] ?? ''));
if ($id <= 0) app_fail(400, 'contact_id');
if (mb_strlen($body) < 2) app_fail(400, 'body', 'Message trop court.');

try {
    $st = $pdo->prepare("SELECT id, firstname, email, subject FROM asso_contact_messages WHERE id = ? LIMIT 1");
    $st->execute([$id]);
    $c = $st->fetch(PDO::FETCH_ASSOC);
    if (!$c) app_fail(404, 'not_found');
    $to = (string) ($c['email'] ?? '');
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) app_fail(422, 'email', 'Email du contact invalide.');

    if (!function_exists('send_transactional_email')) app_fail(500, 'mail', 'Envoi email indisponible.');

    $first = htmlspecialchars((string) ($c['firstname'] ?? ''));
    $subj  = 'Re: ' . ((string) ($c['subject'] ?? '') !== '' ? $c['subject'] : 'Votre demande — Assokit');
    $html  = "<p>Bonjour " . ($first !== '' ? $first : '') . ",</p>"
           . "<div style='white-space:pre-wrap;line-height:1.6'>" . nl2br(htmlspecialchars($body)) . "</div>"
           . "<p style='margin-top:18px'>— L'équipe Assokit · <a href='https://assokit.fr'>assokit.fr</a></p>";
    // Reply-To à jeton : la réponse du prospect revient dans l'app (via inbound-contact.php)
    $reply_to = ak_contact_reply_address($id, $to);
    send_transactional_email($to, $subj, $html, ['tag' => 'founder_contact_reply', 'reply_to' => $reply_to]);

    // Historise la réponse du fondateur dans le fil
    ak_contact_thread_ensure($pdo);
    try { $pdo->prepare("INSERT INTO asso_contact_thread (contact_id, direction, body, from_email, read_by_founder, created_at) VALUES (?, 'out', ?, ?, 1, NOW())")->execute([$id, $body, 'support']); } catch (Throwable $e) {}

    // Marque comme répondu (si la colonne existe)
    try { $pdo->prepare("UPDATE asso_contact_messages SET status = 'replied' WHERE id = ?")->execute([$id]); } catch (Throwable $e) {}

    echo json_encode(['ok' => true, 'contact_id' => $id], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('[app-founder-contact-reply] ' . $e->getMessage());
    app_fail(500, 'server');
}
