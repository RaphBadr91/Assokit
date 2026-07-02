<?php
/**
 * ============================================================
 * ASSOKIT — Helpers email pour les projets
 * ============================================================
 * Envoie des notifications email pour :
 *   - Ajout d'un membre à un projet
 *   - Assignation d'une étape à un membre
 *   - @mention dans un message projet
 * 
 * Réutilise la fonction ak_asso_send_resend() de
 * asso-invoice-email-helpers.php
 * ============================================================
 */

require_once __DIR__ . '/asso-invoice-email-helpers.php';

// Charger le helper notifications si disponible
if (file_exists(__DIR__ . '/notification-helpers.php')) {
    require_once __DIR__ . '/notification-helpers.php';
}

/**
 * Envoie un email à un membre quand il est ajouté à un projet.
 */
function ak_email_project_team_added(PDO $pdo, int $project_id, int $user_id, ?int $added_by_id = null): bool {
    try {
        // Charger user
        $stmt = $pdo->prepare("SELECT id, email, first_name, last_name FROM users WHERE id = ? AND is_active = 1");
        $stmt->execute([$user_id]);
        $user = $stmt->fetch();
        if (!$user || empty($user['email'])) return false;

        // Charger projet + référent
        $stmt = $pdo->prepare("
            SELECT p.id, p.name, p.description, f.name AS folder_name, o.name AS org_name,
                   u.first_name AS ref_first, u.last_name AS ref_last
            FROM projects p
            JOIN folders f ON p.folder_id = f.id
            JOIN organizations o ON f.org_id = o.id
            LEFT JOIN users u ON p.referent_id = u.id
            WHERE p.id = ?
        ");
        $stmt->execute([$project_id]);
        $project = $stmt->fetch();
        if (!$project) return false;

        // Adder name (qui a ajouté)
        $adder_name = '';
        if ($added_by_id) {
            $stmt = $pdo->prepare("SELECT first_name, last_name FROM users WHERE id = ?");
            $stmt->execute([$added_by_id]);
            $adder = $stmt->fetch();
            if ($adder) $adder_name = $adder['first_name'] . ' ' . $adder['last_name'];
        }

        // ========== CRÉER UNE NOTIFICATION IN-APP ==========
        if (function_exists('ak_notif_create')) {
            $notif_title = $adder_name 
                ? "$adder_name vous a ajouté au projet « " . $project['name'] . " »"
                : "Vous avez été ajouté au projet « " . $project['name'] . " »";
            ak_notif_create(
                $pdo, $user_id, 'team_added',
                $notif_title,
                $project['folder_name'] . ' · ' . $project['org_name'],
                '/projet/' . $project_id,
                $project_id, null, null, $added_by_id
            );
        }
        // ========== FIN NOTIF IN-APP ==========

        $project_url = 'https://assokit.fr/projet/' . $project_id;
        $referent_name = trim(($project['ref_first'] ?? '') . ' ' . ($project['ref_last'] ?? ''));

        $subject = "Vous avez été ajouté au projet « " . $project['name'] . " »";

        $html = ak_email_template_wrap(
            "👋 Bonjour " . htmlspecialchars($user['first_name']) . ",",
            ($adder_name ? "<strong>" . htmlspecialchars($adder_name) . "</strong> vous a ajouté(e) à l'équipe du projet :" : "Vous avez été ajouté(e) à l'équipe du projet :") .
            "<br><br>
            <div style='background:#f3f4f6;padding:20px;border-radius:8px;border-left:4px solid #3B82F6;margin:16px 0;'>
                <div style='font-size:18px;font-weight:600;color:#1e293b;margin-bottom:6px;'>" . htmlspecialchars($project['name']) . "</div>
                <div style='font-size:13px;color:#64748B;'>
                    Dossier : " . htmlspecialchars($project['folder_name']) . " · " . htmlspecialchars($project['org_name']) . "
                </div>
                " . ($referent_name ? "<div style='font-size:13px;color:#64748B;margin-top:6px;'>👤 Référent : <strong>" . htmlspecialchars($referent_name) . "</strong></div>" : "") . "
                " . ($project['description'] ? "<div style='font-size:14px;color:#334155;margin-top:10px;line-height:1.5;'>" . nl2br(htmlspecialchars(mb_substr($project['description'], 0, 300))) . "</div>" : "") . "
            </div>
            
            <p>Vous pouvez désormais accéder au projet, voir son avancement, ses messages, ses fichiers et participer aux étapes.</p>",
            $project_url,
            "Voir le projet",
            $project['org_name']
        );

        return ak_asso_send_resend($user['email'], $subject, $html, null, null, 'AssoKit · ' . $project['org_name']);
    } catch (Throwable $e) {
        error_log('ak_email_project_team_added: ' . $e->getMessage());
        return false;
    }
}

/**
 * Envoie un email à un membre quand une étape lui est assignée.
 */
function ak_email_step_assigned(PDO $pdo, int $step_id, int $user_id, ?int $assigned_by_id = null): bool {
    try {
        // Charger user
        $stmt = $pdo->prepare("SELECT id, email, first_name FROM users WHERE id = ? AND is_active = 1");
        $stmt->execute([$user_id]);
        $user = $stmt->fetch();
        if (!$user || empty($user['email'])) return false;

        // Charger étape + projet
        $stmt = $pdo->prepare("
            SELECT s.id, s.title, s.description, s.position,
                   p.id AS project_id, p.name AS project_name,
                   f.name AS folder_name, o.name AS org_name
            FROM project_steps s
            JOIN projects p ON s.project_id = p.id
            JOIN folders f ON p.folder_id = f.id
            JOIN organizations o ON f.org_id = o.id
            WHERE s.id = ?
        ");
        $stmt->execute([$step_id]);
        $step = $stmt->fetch();
        if (!$step) return false;

        // Assigner name
        $assigner_name = '';
        if ($assigned_by_id) {
            $stmt = $pdo->prepare("SELECT first_name, last_name FROM users WHERE id = ?");
            $stmt->execute([$assigned_by_id]);
            $a = $stmt->fetch();
            if ($a) $assigner_name = $a['first_name'] . ' ' . $a['last_name'];
        }

        // ========== CRÉER UNE NOTIFICATION IN-APP ==========
        if (function_exists('ak_notif_create')) {
            $notif_title = $assigner_name 
                ? "$assigner_name vous a assigné à une étape de « " . $step['project_name'] . " »"
                : "Une étape vous a été assignée — « " . $step['project_name'] . " »";
            ak_notif_create(
                $pdo, $user_id, 'step_assigned',
                $notif_title,
                "📝 Étape #" . (int)$step['position'] . " : " . $step['title'],
                '/projet/' . $step['project_id'],
                (int)$step['project_id'], null, $step_id, $assigned_by_id
            );
        }
        // ========== FIN NOTIF IN-APP ==========

        $project_url = 'https://assokit.fr/projet/' . $step['project_id'];
        $subject = "📝 Une étape vous a été assignée — " . $step['project_name'];

        $html = ak_email_template_wrap(
            "👋 Bonjour " . htmlspecialchars($user['first_name']) . ",",
            ($assigner_name ? "<strong>" . htmlspecialchars($assigner_name) . "</strong> vous a assigné(e) à une étape du projet :" : "Une nouvelle étape vous a été assignée dans le projet :") .
            "<br><br>
            <div style='background:#f3f4f6;padding:20px;border-radius:8px;border-left:4px solid #10B981;margin:16px 0;'>
                <div style='font-size:11px;color:#64748B;text-transform:uppercase;letter-spacing:0.05em;margin-bottom:4px;'>Étape #" . (int)$step['position'] . "</div>
                <div style='font-size:18px;font-weight:600;color:#1e293b;margin-bottom:6px;'>" . htmlspecialchars($step['title']) . "</div>
                <div style='font-size:13px;color:#64748B;'>Projet : <strong>" . htmlspecialchars($step['project_name']) . "</strong> · " . htmlspecialchars($step['folder_name']) . "</div>
                " . ($step['description'] ? "<div style='font-size:14px;color:#334155;margin-top:10px;line-height:1.5;'>" . nl2br(htmlspecialchars($step['description'])) . "</div>" : "") . "
            </div>
            
            <p>Vous pouvez consulter le projet, suivre la progression et marquer l'étape comme terminée quand elle sera réalisée.</p>",
            $project_url,
            "Voir l'étape",
            $step['org_name']
        );

        return ak_asso_send_resend($user['email'], $subject, $html, null, null, 'AssoKit · ' . $step['org_name']);
    } catch (Throwable $e) {
        error_log('ak_email_step_assigned: ' . $e->getMessage());
        return false;
    }
}

/**
 * Envoie un email à un membre quand il est mentionné (@) dans un message.
 */
function ak_email_message_mention(PDO $pdo, int $message_id, int $mentioned_user_id): bool {
    try {
        // Charger user mentionné
        $stmt = $pdo->prepare("SELECT id, email, first_name FROM users WHERE id = ? AND is_active = 1");
        $stmt->execute([$mentioned_user_id]);
        $user = $stmt->fetch();
        if (!$user || empty($user['email'])) return false;

        // Charger message + auteur + projet
        $stmt = $pdo->prepare("
            SELECT m.id, m.content, m.created_at, m.author_id,
                   au.first_name AS author_first, au.last_name AS author_last,
                   p.id AS project_id, p.name AS project_name,
                   f.name AS folder_name, o.name AS org_name
            FROM project_messages m
            JOIN users au ON m.author_id = au.id
            JOIN projects p ON m.project_id = p.id
            JOIN folders f ON p.folder_id = f.id
            JOIN organizations o ON f.org_id = o.id
            WHERE m.id = ?
        ");
        $stmt->execute([$message_id]);
        $msg = $stmt->fetch();
        if (!$msg) return false;

        $project_url = 'https://assokit.fr/projet/' . $msg['project_id'] . '/messages';
        $author_name = $msg['author_first'] . ' ' . $msg['author_last'];
        $subject = "💬 " . $author_name . " vous a mentionné dans " . $msg['project_name'];

        $excerpt = mb_substr($msg['content'], 0, 400);
        if (mb_strlen($msg['content']) > 400) $excerpt .= '…';

        // ========== CRÉER UNE NOTIFICATION IN-APP ==========
        if (function_exists('ak_notif_create')) {
            ak_notif_create(
                $pdo, $mentioned_user_id, 'mention',
                $author_name . ' vous a mentionné dans « ' . $msg['project_name'] . ' »',
                mb_substr($msg['content'], 0, 200),
                '/projet/' . $msg['project_id'] . '/messages',
                (int)$msg['project_id'], $message_id, null, (int)$msg['author_id'] ?? null
            );
        }
        // ========== FIN NOTIF IN-APP ==========

        $html = ak_email_template_wrap(
            "👋 Bonjour " . htmlspecialchars($user['first_name']) . ",",
            "<strong>" . htmlspecialchars($author_name) . "</strong> vous a mentionné(e) dans un message du projet :" .
            "<br><br>
            <div style='background:#f3f4f6;padding:20px;border-radius:8px;border-left:4px solid #8B5CF6;margin:16px 0;'>
                <div style='font-size:11px;color:#64748B;text-transform:uppercase;letter-spacing:0.05em;margin-bottom:4px;'>" . htmlspecialchars($msg['project_name']) . "</div>
                <div style='font-size:14px;color:#334155;line-height:1.5;'>" . nl2br(htmlspecialchars($excerpt)) . "</div>
            </div>",
            $project_url,
            "Répondre dans le projet",
            $msg['org_name']
        );

        return ak_asso_send_resend($user['email'], $subject, $html, null, null, 'AssoKit · ' . $msg['org_name']);
    } catch (Throwable $e) {
        error_log('ak_email_message_mention: ' . $e->getMessage());
        return false;
    }
}

/**
 * Template HTML standard pour les emails projet.
 */
function ak_email_template_wrap(string $greeting, string $body_html, string $cta_url, string $cta_label, string $org_name): string {
    return '
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<title>AssoKit</title>
</head>
<body style="margin:0;padding:0;background:#f8fafc;font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif;color:#1e293b;line-height:1.6;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#f8fafc;padding:40px 20px;">
<tr><td align="center">
    <table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" style="background:#fff;border-radius:14px;overflow:hidden;max-width:600px;box-shadow:0 1px 3px rgba(0,0,0,0.06);">
        <!-- Header -->
        <tr><td style="padding:24px 30px;border-bottom:1px solid #e2e8f0;">
            <div style="font-size:18px;font-weight:700;color:#0F172A;">📁 AssoKit</div>
            <div style="font-size:12px;color:#64748B;margin-top:2px;">' . htmlspecialchars($org_name) . '</div>
        </td></tr>
        <!-- Body -->
        <tr><td style="padding:30px;">
            <div style="font-size:16px;font-weight:500;color:#0F172A;margin-bottom:16px;">' . $greeting . '</div>
            <div style="font-size:14px;color:#334155;">' . $body_html . '</div>
            <!-- CTA -->
            <div style="text-align:center;margin:30px 0 10px;">
                <a href="' . htmlspecialchars($cta_url) . '" style="background:#0F172A;color:#fff;text-decoration:none;padding:13px 30px;border-radius:8px;display:inline-block;font-weight:600;font-size:14px;">' . htmlspecialchars($cta_label) . '</a>
            </div>
            <div style="text-align:center;font-size:11px;color:#94A3B8;margin-top:16px;">
                Ou copie-colle ce lien dans ton navigateur :<br>
                <span style="color:#64748B;word-break:break-all;">' . htmlspecialchars($cta_url) . '</span>
            </div>
        </td></tr>
        <!-- Footer -->
        <tr><td style="padding:20px 30px;background:#f8fafc;border-top:1px solid #e2e8f0;text-align:center;font-size:12px;color:#94A3B8;">
            Email envoyé automatiquement par AssoKit · <a href="https://assokit.fr" style="color:#64748B;text-decoration:underline;">assokit.fr</a>
        </td></tr>
    </table>
</td></tr>
</table>
</body>
</html>';
}

/**
 * Détecte les @mentions dans un texte et renvoie les user_ids correspondants.
 * Recherche par "@prenom" ou "@prenom.nom" dans la liste des membres du projet.
 * 
 * @return array Liste des user_id mentionnés (uniques)
 */
function ak_extract_mentions(PDO $pdo, int $project_id, string $content): array {
    if (!preg_match_all('/@([a-zA-ZÀ-ÿ][a-zA-ZÀ-ÿ\.\-]+)/u', $content, $matches)) {
        return [];
    }

    $mentions = array_unique($matches[1]);
    if (empty($mentions)) return [];

    // Charger membres du projet (équipe + référent)
    $stmt = $pdo->prepare("
        SELECT DISTINCT u.id, u.first_name, u.last_name
        FROM users u
        WHERE u.is_active = 1
          AND (
              u.id IN (SELECT user_id FROM project_members WHERE project_id = ?)
              OR u.id = (SELECT referent_id FROM projects WHERE id = ?)
              OR u.org_id = (SELECT f.org_id FROM projects p JOIN folders f ON p.folder_id = f.id WHERE p.id = ?)
          )
    ");
    $stmt->execute([$project_id, $project_id, $project_id]);
    $members = $stmt->fetchAll();

    $result = [];
    foreach ($mentions as $mention) {
        $mention_lower = mb_strtolower($mention);
        foreach ($members as $m) {
            $first_lower = mb_strtolower($m['first_name']);
            $last_lower = mb_strtolower($m['last_name']);
            $full = $first_lower . '.' . $last_lower;
            $full2 = $first_lower . $last_lower;
            
            if ($mention_lower === $first_lower 
                || $mention_lower === $full 
                || $mention_lower === $full2
                || str_starts_with($mention_lower, $first_lower . '.')) {
                $result[] = (int)$m['id'];
                break;
            }
        }
    }

    return array_unique($result);
}
