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
 *   - ak_channel_message_notify() : prévenir les destinataires d'un message de canal
 *   - ak_channel_notifs_mark_read() : solder les notifications d'un canal lu
 *   - ak_project_notifs_mark_read() : solder les notifications de messages d'un projet lu
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
        $ok = $stmt->execute([$user_id, $project_id, $last_id]);
        // Messages lus : leurs notifications aussi (cloche et badge de l'appli)
        ak_project_notifs_mark_read($pdo, $user_id, $project_id);
        return $ok;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Notifications d'un canal de messagerie lu : passées « lues ».
 * Seulement celles de CE canal (lien /messages?c={slug} ou /messages?channel={id},
 * quel qu'en soit le type : message, mention, message de la mairie) — jamais celles
 * des autres canaux ni des projets. Renvoie le nombre de notifications soldées.
 *
 * @param array $channel ligne de channels (id, slug)
 */
function ak_channel_notifs_mark_read(PDO $pdo, int $user_id, array $channel): int {
    $channel_id = (int)($channel['id'] ?? 0);
    $slug = (string)($channel['slug'] ?? '');
    if ($user_id <= 0 || ($channel_id <= 0 && $slug === '')) return 0;
    $links = [];
    if ($slug !== '') {
        $links[] = '/messages?c=' . $slug;
        $links[] = '/messages?c=' . rawurlencode($slug);
        $links[] = '/messages?c=' . urlencode($slug);
    }
    if ($channel_id > 0) $links[] = '/messages?channel=' . $channel_id;
    $links = array_values(array_unique($links));
    try {
        $in = implode(',', array_fill(0, count($links), '?'));
        $st = $pdo->prepare("UPDATE user_notifications SET is_read = 1, read_at = NOW()
                             WHERE user_id = ? AND is_read = 0 AND link_url IN ($in)");
        $st->execute(array_merge([$user_id], $links));
        return $st->rowCount();
    } catch (Throwable $e) {
        error_log('[ak_channel_notifs_mark_read] ' . $e->getMessage());
        return 0;
    }
}

/**
 * Notifications de messages d'un projet (message, mention) passées « lues »
 * quand la discussion du projet a été lue. Les autres notifications du projet
 * (étapes, fichiers…) ne sont pas touchées. Renvoie le nombre de notifications soldées.
 */
function ak_project_notifs_mark_read(PDO $pdo, int $user_id, int $project_id): int {
    if ($user_id <= 0 || $project_id <= 0) return 0;
    try {
        $st = $pdo->prepare("UPDATE user_notifications SET is_read = 1, read_at = NOW()
                             WHERE user_id = ? AND is_read = 0
                               AND notification_type IN ('message', 'mention')
                               AND (project_id = ? OR link_url = ?)");
        $st->execute([$user_id, $project_id, '/projet/' . $project_id . '/messages']);
        return $st->rowCount();
    } catch (Throwable $e) {
        error_log('[ak_project_notifs_mark_read] ' . $e->getMessage());
        return 0;
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
 * Prévient les destinataires d'un nouveau message de canal. Même traitement
 * depuis le site (action-message-canal.php) et depuis l'appli (api/app-send-message.php) :
 *   - @prenom ou @prenom.nom : notification « mention » + e-mail à la personne citée ;
 *   - les autres personnes qui ont accès au canal : notification « message ».
 * Les suiveurs (financeurs externes) n'ont pas accès à la messagerie interne : jamais destinataires.
 * Canal « Suivi · Mairie » : les agents de la mairie membres du canal sont prévenus aussi
 * (notification + e-mail, lien vers /mairie-messages), comme action-mairie-message.php dans l'autre sens.
 *
 * @param array $channel ligne complète de channels (id, org_id, name, slug, type, is_mairie_channel, parent_org_id)
 * @param array $author  utilisateur qui a écrit (current_user())
 * @return array ['mentioned' => int, 'notified' => int]
 */
function ak_channel_message_notify(PDO $pdo, array $channel, array $author, string $content): array {
    $out = ['mentioned' => 0, 'notified' => 0];
    $channel_id = (int)($channel['id'] ?? 0);
    $org_id     = (int)($channel['org_id'] ?? 0);
    $author_id  = (int)($author['id'] ?? 0);
    if ($channel_id <= 0 || $org_id <= 0) return $out;

    $parent_org_id = (int)($channel['parent_org_id'] ?? 0);
    $is_mairie  = !empty($channel['is_mairie_channel']) && $parent_org_id > 0;
    $is_private = (($channel['type'] ?? '') === 'private');
    $chan_name  = (string)($channel['name'] ?? 'canal');
    $slug       = (string)($channel['slug'] ?? '');

    // Personnes qui ont accès au canal (comptes actifs, hors suiveurs)
    $alive = "(u.is_active IS NULL OR u.is_active = 1)
              AND (u.deleted_at IS NULL OR u.deleted_at = '')
              AND (u.role IS NULL OR u.role <> 'follower')";
    try {
        if ($is_private) {
            $st = $pdo->prepare("
                SELECT DISTINCT u.id, u.first_name, u.last_name, u.email, u.parent_org_id
                FROM channel_members cm
                JOIN users u ON u.id = cm.user_id
                WHERE cm.channel_id = ?
                  AND (u.org_id = ? OR (? = 1 AND u.parent_org_id = ?))
                  AND $alive
            ");
            $st->execute([$channel_id, $org_id, $is_mairie ? 1 : 0, $parent_org_id]);
        } else {
            $st = $pdo->prepare("SELECT u.id, u.first_name, u.last_name, u.email, u.parent_org_id
                                 FROM users u WHERE u.org_id = ? AND u.is_active = 1 AND $alive");
            $st->execute([$org_id]);
        }
        $audience = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $u) $audience[(int)$u['id']] = $u;
    } catch (Throwable $e) {
        error_log('[ak_channel_message_notify] destinataires : ' . $e->getMessage());
        return $out;
    }

    $author_name = trim(($author['first_name'] ?? '') . ' ' . ($author['last_name'] ?? '')) ?: 'Un membre';
    $link_for = function (array $u) use ($is_mairie, $channel_id, $slug): string {
        return ($is_mairie && !empty($u['parent_org_id']))
            ? '/mairie-messages?channel=' . $channel_id
            : '/messages?c=' . $slug;
    };

    // ---------- @mentions ----------
    $mentioned = [];
    if (preg_match_all('/(?:^|\s)@([a-zA-ZÀ-ÿ][a-zA-ZÀ-ÿ\.\-]+)/u', $content, $m)) {
        // Mentionnables : les personnes qui ont accès au canal, plus les admins de l'association
        // pour un canal privé (ils peuvent y écrire même sans en être membres).
        $candidates = $audience;
        if ($is_private) {
            try {
                $st = $pdo->prepare("SELECT u.id, u.first_name, u.last_name, u.email, u.parent_org_id
                                     FROM users u WHERE u.org_id = ? AND u.role = 'admin' AND u.is_active = 1 AND $alive");
                $st->execute([$org_id]);
                foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $u) $candidates[(int)$u['id']] = $u;
            } catch (Throwable $e) {}
        }
        foreach (array_unique($m[1]) as $mention) {
            $m_lower = mb_strtolower($mention);
            foreach ($candidates as $cid => $c) {
                $first_lower = mb_strtolower((string)$c['first_name']);
                $last_lower  = mb_strtolower((string)$c['last_name']);
                if ($first_lower === '') continue;
                if ($m_lower === $first_lower
                    || $m_lower === $first_lower . '.' . $last_lower
                    || str_starts_with($m_lower, $first_lower . '.')) {
                    if ($cid !== $author_id) $mentioned[$cid] = $c;
                    break;
                }
            }
        }
    }

    if ($mentioned) {
        if (!function_exists('ak_asso_send_resend') && is_file(__DIR__ . '/projet-email-helpers.php')) {
            require_once __DIR__ . '/projet-email-helpers.php';
        }
        $excerpt = mb_substr($content, 0, 200) . (mb_strlen($content) > 200 ? '…' : '');
        foreach ($mentioned as $mid => $mu) {
            $link = $link_for($mu);
            if (!empty($mu['email']) && function_exists('ak_asso_send_resend') && function_exists('ak_email_template_wrap')) {
                try {
                    $html = ak_email_template_wrap(
                        "👋 Bonjour " . htmlspecialchars((string)$mu['first_name']) . ",",
                        "<strong>" . htmlspecialchars($author_name) . "</strong> vous a mentionné(e) dans le canal #" . htmlspecialchars($chan_name) . " :" .
                        "<br><br>
                        <div style='background:#f3f4f6;padding:20px;border-radius:8px;border-left:4px solid #8B5CF6;margin:16px 0;'>
                            <div style='font-size:11px;color:#64748B;text-transform:uppercase;letter-spacing:0.05em;margin-bottom:4px;'>#" . htmlspecialchars($chan_name) . "</div>
                            <div style='font-size:14px;color:#334155;line-height:1.5;'>" . nl2br(htmlspecialchars($excerpt)) . "</div>
                        </div>",
                        'https://assokit.fr' . $link,
                        "Voir le canal",
                        "AssoKit"
                    );
                    ak_asso_send_resend((string)$mu['email'], "💬 " . $author_name . " vous a mentionné dans #" . $chan_name, $html, null, null, 'AssoKit');
                } catch (Throwable $e) {
                    error_log('[ak_channel_message_notify] e-mail mention : ' . $e->getMessage());
                }
            }
            if (ak_notif_create($pdo, (int)$mid, 'mention', $author_name . ' vous a mentionné dans #' . $chan_name,
                                $excerpt, $link, null, null, null, $author_id)) {
                $out['mentioned']++;
            }
        }
    }

    // ---------- Les autres personnes du canal ----------
    $excerpt_short = mb_substr($content, 0, 150) . (mb_strlen($content) > 150 ? '…' : '');
    $mairie_ctx = null;
    foreach ($audience as $uid => $u) {
        if ($uid === $author_id || isset($mentioned[$uid])) continue;
        $is_agent = $is_mairie && !empty($u['parent_org_id']);
        if (ak_notif_create($pdo, $uid, $is_agent ? 'mairie_message' : 'message',
                            $is_agent ? 'Nouveau message de ' . $author_name . ' (Association)' : $author_name . ' a écrit dans #' . $chan_name,
                            $excerpt_short, $link_for($u), null, null, null, $author_id)) {
            $out['notified']++;
        }
        // Agent de la mairie : il ne consulte pas l'espace de l'association, on le prévient aussi par e-mail
        if ($is_agent && !empty($u['email'])) {
            if ((!function_exists('send_transactional_email') || !function_exists('email_wrap')) && is_file(__DIR__ . '/resend-helper.php')) {
                require_once __DIR__ . '/resend-helper.php';
            }
            if (!function_exists('send_transactional_email') || !function_exists('email_wrap')) continue;
            try {
                if ($mairie_ctx === null) {
                    $st = $pdo->prepare("SELECT name FROM organizations WHERE id = ?");
                    $st->execute([$org_id]);
                    $mairie_ctx = (string)($st->fetchColumn() ?: 'Association');
                }
                $full = mb_substr($content, 0, 500);
                $body_html = '<p>Bonjour <strong>' . htmlspecialchars((string)$u['first_name']) . '</strong>,</p>'
                    . '<p><strong>' . htmlspecialchars($author_name) . '</strong> (🏢 ' . htmlspecialchars($mairie_ctx) . ') vous a envoyé un nouveau message :</p>'
                    . '<blockquote style="border-left:3px solid #d97706;padding:14px 18px;margin:18px 0;background:#FFFBEB;color:#1C1917;font-style:italic;border-radius:0 6px 6px 0;">'
                    . nl2br(htmlspecialchars($full))
                    . (mb_strlen($content) > 500 ? '<br><span style="color:#92400E;">…(message tronqué)</span>' : '')
                    . '</blockquote>'
                    . '<p style="color:#78716C;font-size:13px;">Vous recevez cet email car vous êtes membre d\'un canal de suivi entre une association et une mairie sur Assokit.</p>';
                $html = email_wrap('Nouveau message sur Assokit', $body_html, 'Voir et répondre', 'https://assokit.fr' . $link_for($u));
                send_transactional_email((string)$u['email'], '💬 Nouveau message de ' . $author_name, $html,
                                         ['tag' => 'mairie_message', 'from_name' => $mairie_ctx]);
            } catch (Throwable $e) {
                error_log('[ak_channel_message_notify] e-mail mairie : ' . $e->getMessage());
            }
        }
    }
    return $out;
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
