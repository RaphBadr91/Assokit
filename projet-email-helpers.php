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
        $stmt = $pdo->prepare("SELECT id, email, first_name, last_name, org_id FROM users WHERE id = ? AND is_active = 1");
        $stmt->execute([$user_id]);
        $user = $stmt->fetch();
        if (!$user || empty($user['email'])) return false;

        // Charger projet + référent
        $stmt = $pdo->prepare("
            SELECT p.id, p.name, p.description, f.name AS folder_name, f.org_id, o.name AS org_name,
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
        // Jamais de notification ni d'e-mail à un compte d'une autre association
        if ((int)$user['org_id'] !== (int)$project['org_id']) return false;

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
        $stmt = $pdo->prepare("SELECT id, email, first_name, org_id, role FROM users WHERE id = ? AND is_active = 1");
        $stmt->execute([$user_id]);
        $user = $stmt->fetch();
        if (!$user || empty($user['email'])) return false;

        // Charger étape + projet
        $stmt = $pdo->prepare("
            SELECT s.id, s.title, s.description, s.position,
                   p.id AS project_id, p.name AS project_name,
                   f.name AS folder_name, f.org_id, o.name AS org_name
            FROM project_steps s
            JOIN projects p ON s.project_id = p.id
            JOIN folders f ON p.folder_id = f.id
            JOIN organizations o ON f.org_id = o.id
            WHERE s.id = ?
        ");
        $stmt->execute([$step_id]);
        $step = $stmt->fetch();
        if (!$step) return false;
        if ((int)$user['org_id'] !== (int)$step['org_id']) return false;
        // Un suiveur (financeur) n'est prévenu que pour un projet qu'il suit
        if (($user['role'] ?? '') === 'follower') {
            $pm = $pdo->prepare("SELECT 1 FROM project_members WHERE project_id = ? AND user_id = ?");
            $pm->execute([(int)$step['project_id'], $user_id]);
            if (!$pm->fetchColumn()) return false;
        }

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
        $stmt = $pdo->prepare("SELECT id, email, first_name, org_id FROM users WHERE id = ? AND is_active = 1");
        $stmt->execute([$mentioned_user_id]);
        $user = $stmt->fetch();
        if (!$user || empty($user['email'])) return false;

        // Charger message + auteur + projet
        $stmt = $pdo->prepare("
            SELECT m.id, m.content, m.created_at, m.author_id,
                   au.first_name AS author_first, au.last_name AS author_last,
                   p.id AS project_id, p.name AS project_name,
                   f.name AS folder_name, f.org_id, o.name AS org_name
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
        if ((int)$user['org_id'] !== (int)$msg['org_id']) return false;

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
            <div style="text-align:center;font-size:11px;color:#64748B;margin-top:16px;">
                Ou copie-colle ce lien dans ton navigateur :<br>
                <span style="color:#64748B;word-break:break-all;">' . htmlspecialchars($cta_url) . '</span>
            </div>
        </td></tr>
        <!-- Footer -->
        <tr><td style="padding:20px 30px;background:#f8fafc;border-top:1px solid #e2e8f0;text-align:center;font-size:12px;color:#64748B;">
            Email envoyé automatiquement par AssoKit · <a href="https://assokit.fr" style="color:#64748B;text-decoration:underline;">assokit.fr</a>
        </td></tr>
    </table>
</td></tr>
</table>
</body>
</html>';
}

/**
 * Identifiant @ d'une personne dans les messages de projet : « prenom.nom »
 * en minuscules (« @sarah.vincent »), espaces et apostrophes remplacés par
 * des tirets, sans les caractères que la détection des mentions ne lit pas.
 * C'est ce que l'autocomplétion insère : deux « Sarah » de l'association ne
 * se confondent plus.
 */
if (!function_exists('ak_mention_tag')) {
    function ak_mention_tag(string $first, string $last): string {
        $norm = function (string $s): string {
            $s = preg_replace("/[\\s'’]+/u", '-', mb_strtolower(trim($s)));
            $s = preg_replace('/[^a-zà-ÿ\-]+/u', '', $s);
            return trim(preg_replace('/-+/', '-', $s), '-');
        };
        $f = $norm($first);
        $l = $norm($last);
        if ($f === '') return $l;
        return $l === '' ? $f : $f . '.' . $l;
    }
}

/**
 * Personnes qu'on peut mentionner dans un projet (et que propose
 * l'autocomplétion) : comptes actifs de l'association du projet qui sont
 * dans l'équipe, son référent, ou admin/coordinateur. Un suiveur (financeur
 * externe) seulement s'il fait partie de l'équipe du projet, c'est-à-dire
 * s'il y a accès.
 * $team_only : seulement l'équipe et le référent — la liste montrée à un
 * suiveur, qui ne voit rien d'autre de l'association que ses projets.
 */
if (!function_exists('ak_mention_candidates')) {
    function ak_mention_candidates(PDO $pdo, int $project_id, bool $team_only = false): array {
        $staff = $team_only ? '' : " OR u.role IN ('admin', 'coordinator')";
        try {
            $stmt = $pdo->prepare("
                SELECT DISTINCT u.id, u.first_name, u.last_name, u.role, u.avatar_color
                FROM projects p
                JOIN folders f ON f.id = p.folder_id
                JOIN users u ON u.org_id = f.org_id
                WHERE p.id = :pid
                  AND u.is_active = 1
                  AND (u.deleted_at IS NULL OR u.deleted_at = '')
                  AND (
                      u.id IN (SELECT pm.user_id FROM project_members pm WHERE pm.project_id = :pid2)
                      OR (u.role <> 'follower' AND (u.id = p.referent_id$staff))
                  )
                ORDER BY u.first_name ASC, u.last_name ASC
            ");
            $stmt->execute([':pid' => $project_id, ':pid2' => $project_id]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            error_log('ak_mention_candidates: ' . $e->getMessage());
            return [];
        }
    }
}

/**
 * Détecte les @mentions dans un texte et renvoie les user_ids correspondants,
 * parmi ak_mention_candidates() uniquement.
 *   - « @prenom.nom » (inséré par l'autocomplétion) : la personne exacte ;
 *   - « @prenom » ou « @prenom.début-du-nom » : seulement si une seule
 *     personne correspond ; s'il y a plusieurs « Sarah », personne n'est
 *     notifié plutôt que la mauvaise.
 *
 * @return array Liste des user_id mentionnés (uniques)
 */
function ak_extract_mentions(PDO $pdo, int $project_id, string $content): array {
    if (!preg_match_all('/@([a-zA-ZÀ-ÿ][a-zA-ZÀ-ÿ\.\-]+)/u', $content, $matches)) {
        return [];
    }

    $members = ak_mention_candidates($pdo, $project_id);
    if (empty($members)) return [];

    // Index : identifiant complet, même identifiant sans séparateurs
    // (ancien « @prenomnom »), prénom seul, nom seul
    $by_tag = $by_compact = $by_first = [];
    $people = [];
    foreach ($members as $m) {
        $id = (int)$m['id'];
        $first = ak_mention_tag((string)$m['first_name'], '');
        $last = ak_mention_tag('', (string)$m['last_name']);
        $tag = ak_mention_tag((string)$m['first_name'], (string)$m['last_name']);
        if ($tag === '') continue;
        $by_tag[$tag][$id] = true;
        $by_compact[str_replace(['.', '-'], '', $tag)][$id] = true;
        if ($first !== '') $by_first[$first][$id] = true;
        $people[] = ['id' => $id, 'first' => $first, 'last' => $last];
    }
    $unique = function (?array $ids): ?int {
        return ($ids && count($ids) === 1) ? (int)array_key_first($ids) : null;
    };

    $result = [];
    foreach (array_unique($matches[1]) as $raw) {
        // « @sarah. » en fin de phrase : le point n'appartient pas à la mention
        $t = rtrim(mb_strtolower($raw), '.-');
        if ($t === '') continue;

        if (isset($by_tag[$t])) {
            if (($id = $unique($by_tag[$t])) !== null) $result[] = $id;
            continue;
        }
        if (isset($by_first[$t])) {
            if (($id = $unique($by_first[$t])) !== null) $result[] = $id;
            continue;
        }
        $compact = str_replace(['.', '-'], '', $t);
        if (isset($by_compact[$compact])) {
            if (($id = $unique($by_compact[$compact])) !== null) $result[] = $id;
            continue;
        }
        // « @sarah.v » : prénom exact + début du nom, s'il ne désigne qu'une personne
        if (strpos($t, '.') !== false) {
            [$f, $l] = explode('.', $t, 2);
            $hits = [];
            foreach ($people as $p) {
                if ($l !== '' && $p['first'] === $f && str_starts_with($p['last'], $l)) $hits[$p['id']] = true;
            }
            if (($id = $unique($hits)) !== null) $result[] = $id;
        }
    }

    return array_values(array_unique($result));
}

/**
 * Montant saisi dans un formulaire projet (site ou appli) : « 1 500,50 »,
 * « 1500.50 », « 1 500 € », « 1.500,50 », espaces insécables compris.
 * Le dernier séparateur (virgule ou point) est la décimale ; un séparateur
 * répété est celui des milliers. Vide ou illisible : 0.
 */
if (!function_exists('ak_parse_amount')) {
    function ak_parse_amount($raw): float {
        $s = str_replace([' ', "\u{00A0}", "\u{202F}", "\u{2009}", '€'], '', trim((string)$raw));
        if ($s === '') return 0.0;
        $comma = strrpos($s, ',');
        $dot = strrpos($s, '.');
        if ($comma !== false && $dot !== false) {
            $s = $comma > $dot ? str_replace(['.', ','], ['', '.'], $s) : str_replace(',', '', $s);
        } elseif ($comma !== false) {
            $s = substr_count($s, ',') > 1 ? str_replace(',', '', $s) : str_replace(',', '.', $s);
        } elseif ($dot !== false && substr_count($s, '.') > 1) {
            $s = str_replace('.', '', $s);
        }
        return is_numeric($s) ? (float)$s : 0.0;
    }
}

/**
 * Messages d'un projet lus par l'utilisateur jusqu'au message $up_to_id
 * (0 = le dernier du projet) : le badge « messages non lus » de /projets
 * revient à zéro. Met à jour la ligne de user_project_reads si elle existe,
 * sinon la crée : jamais de doublon, même sans clé unique (user_id,
 * project_id) en base. Sans effet si la table manque.
 */
if (!function_exists('ak_project_mark_seen')) {
    function ak_project_mark_seen(PDO $pdo, int $user_id, int $project_id, int $up_to_id = 0): void {
        if ($user_id <= 0 || $project_id <= 0) return;
        try {
            if ($up_to_id <= 0) {
                $st = $pdo->prepare("SELECT MAX(id) FROM project_messages WHERE project_id = ?");
                $st->execute([$project_id]);
                $up_to_id = (int)$st->fetchColumn();
            }
            if ($up_to_id <= 0) return;

            require_once __DIR__ . '/trial-helpers.php';
            $cols = ak_db_columns($pdo, 'user_project_reads');
            if (!isset($cols['user_id'], $cols['project_id'], $cols['last_read_message_id'])) return;
            $has_at = isset($cols['last_read_at']);

            $st = $pdo->prepare("SELECT COUNT(*) AS n, MAX(COALESCE(last_read_message_id, 0)) AS last_id
                                 FROM user_project_reads WHERE user_id = ? AND project_id = ?");
            $st->execute([$user_id, $project_id]);
            $row = $st->fetch(PDO::FETCH_ASSOC) ?: ['n' => 0, 'last_id' => 0];

            if ((int)$row['n'] > 0) {
                if ((int)$row['last_id'] < $up_to_id) {
                    $pdo->prepare("UPDATE user_project_reads SET last_read_message_id = ?" . ($has_at ? ", last_read_at = NOW()" : "")
                                . " WHERE user_id = ? AND project_id = ? AND (last_read_message_id IS NULL OR last_read_message_id < ?)")
                        ->execute([$up_to_id, $user_id, $project_id, $up_to_id]);
                }
                return;
            }
            $pdo->prepare("INSERT IGNORE INTO user_project_reads (user_id, project_id, last_read_message_id" . ($has_at ? ", last_read_at" : "") . ")
                           VALUES (?, ?, ?" . ($has_at ? ", NOW()" : "") . ")")
                ->execute([$user_id, $project_id, $up_to_id]);
        } catch (Throwable $e) {
            error_log('[projet] messages lus (projet ' . $project_id . ') : ' . $e->getMessage());
        }
    }
}
