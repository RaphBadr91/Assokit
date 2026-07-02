<?php
/**
 * support-helper.php — Helpers pour le systeme de Support
 * ==========================================================
 *
 * Fournit :
 *   - support_get_ticket($id)           : charge un ticket avec ses infos
 *   - support_user_can_see_ticket()     : verif permissions
 *   - support_log_event()               : log un event de ticket
 *   - support_notify_new_message()      : envoie notifs + emails aux bonnes personnes
 *   - support_category_label(), etc.    : helpers d'affichage
 *
 * Categories de ticket : question, bug, feature_request, billing, account, other
 * Priorites : low, normal, high, urgent
 * Statuts : open, in_progress, waiting_user, resolved, closed
 */

if (!defined('SUPPORT_HELPER_LOADED')) {
    define('SUPPORT_HELPER_LOADED', true);
}

/**
 * Renvoie un ticket complet avec infos asso/user/assigne
 */
function support_get_ticket(int $ticket_id): ?array {
    global $pdo;
    try {
        $stmt = $pdo->prepare("
            SELECT
                t.*,
                o.name AS org_name,
                creator.first_name AS creator_first, creator.last_name AS creator_last,
                creator.email AS creator_email,
                assigned.first_name AS assigned_first, assigned.last_name AS assigned_last,
                assigned.email AS assigned_email
            FROM support_tickets t
            JOIN organizations o ON t.org_id = o.id
            LEFT JOIN users creator ON t.created_by_user_id = creator.id
            LEFT JOIN users assigned ON t.assigned_to_user_id = assigned.id
            WHERE t.id = ?
        ");
        $stmt->execute([$ticket_id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Un user peut-il voir ce ticket ?
 * - Asso : voit les tickets de son org_id
 * - SA/Fondateur : voit tout
 */
function support_user_can_see_ticket(array $user, array $ticket): bool {
    // SA ou Fondateur : acces total
    if (($user['role'] ?? '') === 'super_admin' || !empty($user['is_super_admin']) || !empty($user['is_founder'])) {
        return true;
    }
    // Sinon, l'user doit etre dans la meme asso
    return (int) ($user['org_id'] ?? 0) === (int) $ticket['org_id'];
}

/**
 * Un user peut-il modifier ce ticket (assigner, changer statut, etc.) ?
 * → Seulement les SA/Fondateur
 */
function support_user_can_manage_ticket(array $user): bool {
    return ($user['role'] ?? '') === 'super_admin'
        || !empty($user['is_super_admin'])
        || !empty($user['is_founder']);
}

/**
 * Determine de quel cote (org ou support) est l'user
 */
function support_get_user_side(array $user): string {
    if (($user['role'] ?? '') === 'super_admin' || !empty($user['is_super_admin']) || !empty($user['is_founder'])) {
        return 'support';
    }
    return 'org';
}

/**
 * Log un event dans support_ticket_events
 */
function support_log_event(int $ticket_id, int $user_id, string $event_type, ?string $from_value = null, ?string $to_value = null, ?string $note = null): void {
    global $pdo;
    try {
        $stmt = $pdo->prepare("
            INSERT INTO support_ticket_events
                (ticket_id, user_id, event_type, from_value, to_value, note, created_at)
            VALUES (?, ?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([$ticket_id, $user_id, $event_type, $from_value, $to_value, $note]);
    } catch (Throwable $e) {}
}

/**
 * Notifie les bonnes personnes apres un nouveau message.
 *
 * Si message cote 'org' : notifie tous les SA+Fondateurs (si non-assigne)
 *                         OU juste l'assigne (si assigne)
 * Si message cote 'support' : notifie tous les users de l'asso
 */
function support_notify_new_message(int $ticket_id, int $author_user_id, string $author_side, string $body): void {
    global $pdo;

    $ticket = support_get_ticket($ticket_id);
    if (!$ticket) return;

    $body_preview = mb_substr(strip_tags($body), 0, 200);

    if ($author_side === 'org') {
        // Le cote ASSO a ecrit → notifier support
        $recipients = [];
        if ($ticket['assigned_to_user_id']) {
            // Ticket assigne : notifier uniquement l'assigne
            try {
                $stmt = $pdo->prepare("SELECT id, email, first_name FROM users WHERE id = ? AND is_active = 1");
                $stmt->execute([(int) $ticket['assigned_to_user_id']]);
                $u = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($u) $recipients[] = $u;
            } catch (Throwable $e) {}
        } else {
            // Ticket non assigne : notifier tous les SA + Fondateurs actifs
            try {
                $stmt = $pdo->query("
                    SELECT id, email, first_name FROM users
                    WHERE is_active = 1
                      AND (role = 'super_admin' OR is_super_admin = 1 OR is_founder = 1)
                ");
                $recipients = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (Throwable $e) {}
        }

        foreach ($recipients as $r) {
            // Notification in-app
            if (function_exists('sa_notify')) {
                sa_notify(
                    (int) $r['id'],
                    'new_ticket_message',
                    '💬 Nouveau message — ' . $ticket['title'],
                    $ticket['org_name'] . ' : ' . $body_preview,
                    '/super-admin/support?id=' . $ticket_id,
                    (int) $ticket['org_id']
                );
            }
            // Email Resend
            if (function_exists('send_transactional_email')) {
                try {
                    send_transactional_email(
                        $r['email'],
                        '[Ticket #' . $ticket['id'] . '] ' . $ticket['title'],
                        support_render_email_new_message_to_support([
                            'recipient_first' => $r['first_name'],
                            'ticket' => $ticket,
                            'body' => $body,
                        ]),
                        ['tag' => 'support_new_message_support']
                    );
                } catch (Throwable $e) {}
            }
        }
    } else {
        // Le cote SUPPORT a ecrit → notifier tous les users de l'asso
        try {
            $stmt = $pdo->prepare("
                SELECT id, email, first_name FROM users
                WHERE org_id = ? AND is_active = 1
            ");
            $stmt->execute([(int) $ticket['org_id']]);
            $org_users = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($org_users as $u) {
                // Email Resend uniquement (notifs in-app seulement pour SA/Fondateur dans cette V1)
                if (function_exists('send_transactional_email')) {
                    try {
                        send_transactional_email(
                            $u['email'],
                            '[Ticket #' . $ticket['id'] . '] Réponse du support — ' . $ticket['title'],
                            support_render_email_new_message_to_org([
                                'recipient_first' => $u['first_name'],
                                'ticket' => $ticket,
                                'body' => $body,
                            ]),
                            ['tag' => 'support_new_message_org']
                        );
                    } catch (Throwable $e) {}
                }
            }
        } catch (Throwable $e) {}
    }
}

/**
 * Template email : nouveau message vers le support
 */
function support_render_email_new_message_to_support(array $data): string {
    $name = htmlspecialchars($data['recipient_first'] ?? '');
    $ticket = $data['ticket'];
    $body = nl2br(htmlspecialchars($data['body']));
    $cat = support_category_label($ticket['category']);
    $prio = support_priority_label($ticket['priority']);
    $org = htmlspecialchars($ticket['org_name']);
    $title = htmlspecialchars($ticket['title']);

    $content = '
      <h1 style="font-size:20px;margin:0 0 10px;font-weight:500;letter-spacing:-0.01em;color:#1C1917">💬 Nouveau message de support</h1>
      <p>Bonjour ' . $name . ',</p>
      <p><strong>' . $org . '</strong> a envoyé un nouveau message sur un ticket.</p>
      <table role="presentation" cellspacing="0" cellpadding="0" border="0" style="background:#FAFAF9;border:1px solid #E7E5E4;border-radius:10px;padding:16px;margin:14px 0;width:100%;font-size:13.5px">
        <tr><td style="padding:4px 8px;color:#78716C;width:30%">Ticket :</td><td style="padding:4px 8px"><strong>#' . (int) $ticket['id'] . ' — ' . $title . '</strong></td></tr>
        <tr><td style="padding:4px 8px;color:#78716C">Catégorie :</td><td style="padding:4px 8px">' . $cat . '</td></tr>
        <tr><td style="padding:4px 8px;color:#78716C">Priorité :</td><td style="padding:4px 8px">' . $prio . '</td></tr>
      </table>
      <div style="background:#EEEDFE;border-left:3px solid #7F77DD;padding:12px 14px;border-radius:6px;margin:14px 0;font-size:13.5px;color:#3C3489;line-height:1.6;">
        ' . $body . '
      </div>';

    if (function_exists('email_wrap')) {
        return email_wrap('Nouveau message support', $content, 'Répondre', 'https://assokit.fr/super-admin/support?id=' . (int) $ticket['id']);
    }
    return $content;
}

/**
 * Template email : nouveau message vers l'asso
 */
function support_render_email_new_message_to_org(array $data): string {
    $name = htmlspecialchars($data['recipient_first'] ?? '');
    $ticket = $data['ticket'];
    $body = nl2br(htmlspecialchars($data['body']));
    $title = htmlspecialchars($ticket['title']);

    $content = '
      <h1 style="font-size:20px;margin:0 0 10px;font-weight:500;letter-spacing:-0.01em;color:#1C1917">💬 Réponse du support Assokit</h1>
      <p>Bonjour ' . $name . ',</p>
      <p>Le support Assokit vient de répondre sur votre ticket.</p>
      <table role="presentation" cellspacing="0" cellpadding="0" border="0" style="background:#FAFAF9;border:1px solid #E7E5E4;border-radius:10px;padding:16px;margin:14px 0;width:100%;font-size:13.5px">
        <tr><td style="padding:4px 8px;color:#78716C;width:30%">Ticket :</td><td style="padding:4px 8px"><strong>#' . (int) $ticket['id'] . ' — ' . $title . '</strong></td></tr>
      </table>
      <div style="background:#D1FAE5;border-left:3px solid #059669;padding:12px 14px;border-radius:6px;margin:14px 0;font-size:13.5px;color:#065F46;line-height:1.6;">
        ' . $body . '
      </div>';

    if (function_exists('email_wrap')) {
        return email_wrap('Réponse support Assokit', $content, 'Voir la conversation', 'https://assokit.fr/support?id=' . (int) $ticket['id']);
    }
    return $content;
}

// ==================================================================
// HELPERS AFFICHAGE
// ==================================================================

function support_category_label(string $cat): string {
    return match($cat) {
        'question'        => '❓ Question',
        'bug'             => '🐛 Bug',
        'feature_request' => '💡 Demande de fonctionnalité',
        'billing'         => '💳 Facturation',
        'account'         => '👤 Compte',
        'other'           => '📌 Autre',
        default           => htmlspecialchars($cat),
    };
}

function support_priority_label(string $p): string {
    return match($p) {
        'low'     => '🟢 Basse',
        'normal'  => '⚪ Normale',
        'high'    => '🟠 Haute',
        'urgent'  => '🔴 Urgente',
        default   => htmlspecialchars($p),
    };
}

function support_priority_color(string $p): string {
    return match($p) {
        'low'     => '#10B981',
        'normal'  => '#A1A1AA',
        'high'    => '#F59E0B',
        'urgent'  => '#EF4444',
        default   => '#A1A1AA',
    };
}

function support_status_label(string $s): string {
    return match($s) {
        'open'          => '🟢 Ouvert',
        'in_progress'   => '🔵 En cours',
        'waiting_user'  => '⏳ Attente user',
        'resolved'      => '✅ Résolu',
        'closed'        => '🔒 Fermé',
        default         => htmlspecialchars($s),
    };
}

function support_status_badge_class(string $s): string {
    return match($s) {
        'open'          => 'sa-badge-green',
        'in_progress'   => 'sa-badge-violet',
        'waiting_user'  => 'sa-badge-amber',
        'resolved'      => 'sa-badge-green',
        'closed'        => 'sa-badge-gray',
        default         => 'sa-badge-gray',
    };
}
