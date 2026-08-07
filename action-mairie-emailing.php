<?php
/**
 * Endpoint POST : envoi d'une campagne emailing mairie
 * URL : /action-mairie-emailing
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes-layout.php';
require_once __DIR__ . '/includes-permissions.php';
require_once __DIR__ . '/resend-helper.php';
require_login();
require_parent_org_user();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /mairie-emailing'); exit;
}
if (!check_csrf($_POST['csrf_token'] ?? '')) {
    http_response_code(403); die('CSRF token invalide');
}

$current = current_user();
$parent_org = current_parent_org();
$parent_org_id = (int)$parent_org['id'];
$user_id = (int)$current['id'];

$subject = trim($_POST['subject'] ?? '');
$content = trim($_POST['content'] ?? '');
$audience = $_POST['audience'] ?? 'all';
$org_ids = $_POST['org_ids'] ?? [];

// Validation
if ($subject === '' || $content === '') {
    $_SESSION['flash_error'] = "L'objet et le message sont obligatoires.";
    header('Location: /mairie-emailing'); exit;
}
if (mb_strlen($subject) > 255) $subject = mb_substr($subject, 0, 255);
if (mb_strlen($content) > 20000) $content = mb_substr($content, 0, 20000);

try {
    // === Déterminer les destinataires (admins des asso liées) ===
    if ($audience === 'selected') {
        $org_ids = array_filter(array_map('intval', (array)$org_ids));
        if (!$org_ids) {
            $_SESSION['flash_error'] = "Aucune association valide sélectionnée.";
            header('Location: /mairie-emailing'); exit;
        }
        $placeholders = implode(',', array_fill(0, count($org_ids), '?'));
        // Vérifier que ces orgs appartiennent bien à la mairie (sécurité)
        $stmt = $pdo->prepare("
            SELECT u.id, u.email, u.first_name, u.last_name, u.org_id, o.name AS org_name
            FROM users u
            INNER JOIN organizations o ON o.id = u.org_id
            WHERE u.org_id IN ($placeholders)
              AND o.parent_org_id = ?
              AND u.role = 'admin'
              AND u.deleted_at IS NULL
              AND u.email IS NOT NULL AND u.email != ''
        ");
        $stmt->execute([...$org_ids, $parent_org_id]);
    } else {
        // Tous les admins de toutes les asso liées
        $stmt = $pdo->prepare("
            SELECT u.id, u.email, u.first_name, u.last_name, u.org_id, o.name AS org_name
            FROM users u
            INNER JOIN organizations o ON o.id = u.org_id
            WHERE o.parent_org_id = ?
              AND u.role = 'admin'
              AND u.deleted_at IS NULL
              AND u.email IS NOT NULL AND u.email != ''
        ");
        $stmt->execute([$parent_org_id]);
    }
    $recipients = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!$recipients) {
        $_SESSION['flash_error'] = "Aucun destinataire trouvé.";
        header('Location: /mairie-emailing'); exit;
    }

    // === Créer la campagne ===
    $filter_json = json_encode(['audience' => $audience, 'org_ids' => $audience === 'selected' ? array_values($org_ids) : 'all']);
    $pdo->prepare("
        INSERT INTO mairie_campaigns (parent_org_id, created_by, subject, content, recipients_filter_json, recipients_count, status, created_at)
        VALUES (?, ?, ?, ?, ?, ?, 'sending', NOW())
    ")->execute([$parent_org_id, $user_id, $subject, $content, $filter_json, count($recipients)]);
    $campaign_id = (int)$pdo->lastInsertId();

    // === Envoyer ===
    $sent = 0; $failed = 0;
    $mairie_name = $parent_org['name'];

    foreach ($recipients as $r) {
        // Insérer le destinataire
        $pdo->prepare("
            INSERT INTO mairie_campaign_recipients (campaign_id, user_id, org_id, email, first_name, last_name, org_name, status, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', NOW())
        ")->execute([$campaign_id, $r['id'], $r['org_id'], $r['email'], $r['first_name'], $r['last_name'], $r['org_name']]);
        $recipient_id = (int)$pdo->lastInsertId();

        // Construire l'email
        $body_html = '<p>Bonjour <strong>' . htmlspecialchars($r['first_name']) . '</strong>,</p>'
            . '<div style="margin:18px 0;line-height:1.6;color:#1C1917;">' . nl2br(htmlspecialchars($content)) . '</div>'
            . '<p style="color:#78716C;font-size:13px;border-top:1px solid #E7E5E4;padding-top:14px;margin-top:24px;">'
            . 'Cet email vous a été envoyé par <strong>' . htmlspecialchars($mairie_name) . '</strong> '
            . 'car votre association <strong>' . htmlspecialchars($r['org_name']) . '</strong> est suivie par cette collectivité sur Assokit.</p>';

        $html = function_exists('email_wrap')
            ? email_wrap($subject, $body_html)
            : '<html><body>' . $body_html . '</body></html>';

        // Envoyer via Resend
        try {
            $result = send_transactional_email($r['email'], $subject, $html, [
                'tag' => 'mairie_campaign',
                'from_name' => $mairie_name,
                'reply_to' => !empty($parent_org['contact_email']) ? $parent_org['contact_email'] : null,
            ]);
            if (!empty($result['success'])) {
                $sent++;
                $pdo->prepare("UPDATE mairie_campaign_recipients SET status='sent', resend_id=?, sent_at=NOW() WHERE id=?")
                    ->execute([$result['id'] ?? null, $recipient_id]);
            } else {
                $failed++;
                $pdo->prepare("UPDATE mairie_campaign_recipients SET status='failed', error_message=? WHERE id=?")
                    ->execute([mb_substr($result['error'] ?? 'Erreur inconnue', 0, 500), $recipient_id]);
            }
        } catch (Throwable $e) {
            $failed++;
            $pdo->prepare("UPDATE mairie_campaign_recipients SET status='failed', error_message=? WHERE id=?")
                ->execute([mb_substr($e->getMessage(), 0, 500), $recipient_id]);
        }
    }

    // === Mettre à jour la campagne ===
    $final_status = ($failed === 0) ? 'sent' : (($sent === 0) ? 'failed' : 'sent');
    $pdo->prepare("UPDATE mairie_campaigns SET status=?, sent_count=?, failed_count=?, sent_at=NOW() WHERE id=?")
        ->execute([$final_status, $sent, $failed, $campaign_id]);

    if ($failed === 0) {
        $_SESSION['flash_success'] = "Campagne envoyée à $sent destinataire(s) avec succès.";
    } else {
        $_SESSION['flash_success'] = "Campagne envoyée : $sent réussi(s), $failed échec(s).";
    }
    header('Location: /mairie-emailing'); exit;

} catch (Throwable $e) {
    error_log('[action-mairie-emailing] ' . $e->getMessage());
    $_SESSION['flash_error'] = "Erreur lors de l'envoi : " . $e->getMessage();
    header('Location: /mairie-emailing'); exit;
}
