<?php
/**
 * Helpers Agenda : présence / émargement, et visibilité des événements
 * (ak_event_*, partagés par le site, l'appli et les flux .ics).
 */

if (!function_exists('ak_event_visibility_sql')) {
    /**
     * Restriction des événements visibles par un utilisateur, à ajouter au WHERE
     * d'une requête déjà limitée à l'association (alias $a de la table events).
     * Renvoie [fragment « AND … », paramètres à lier dans cet ordre].
     *  - admin, coordinateur, super admin : tout l'agenda de l'association ;
     *  - suiveur (financeur externe) : seulement les événements des projets qu'il suit ;
     *  - autres rôles : tout, sauf les événements « Équipe du projet uniquement » d'un projet
     *    dont ils ne sont ni membres ni référents (on voit toujours ceux qu'on a créés).
     * $user : current_user() ou une ligne users (id, role), ex. le titulaire d'un lien d'abonnement.
     */
    function ak_event_visibility_sql(PDO $pdo, array $user, string $a = 'e'): array {
        $uid = (int)($user['id'] ?? 0);
        $role = (string)($user['role'] ?? '');
        if (in_array($role, ['admin', 'coordinator', 'super_admin'], true) || !empty($user['is_super_admin'])) return ['', []];

        if ($role === 'follower') {
            // Utilisateur connecté : même liste que /agenda ; lien d'abonnement : ses projets.
            $cur = function_exists('current_user') ? current_user() : null;
            if ($cur && (int)$cur['id'] === $uid && function_exists('get_follower_project_ids')) {
                $ids = get_follower_project_ids() ?? [];
            } else {
                try {
                    $s = $pdo->prepare("SELECT project_id FROM project_members WHERE user_id = ?");
                    $s->execute([$uid]);
                    $ids = $s->fetchAll(PDO::FETCH_COLUMN);
                } catch (Throwable $e) { $ids = []; }
            }
            $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
            if (!$ids) return [' AND 1 = 0', []];
            return [" AND $a.project_id IN (" . implode(',', array_fill(0, count($ids), '?')) . ")", $ids];
        }

        return [" AND ($a.visibility IS NULL OR $a.visibility <> 'project_only'"
              . " OR $a.created_by = ?"
              . " OR $a.project_id IN (SELECT pm_vis.project_id FROM project_members pm_vis WHERE pm_vis.user_id = ?)"
              . " OR $a.project_id IN (SELECT pr_vis.id FROM projects pr_vis WHERE pr_vis.referent_id = ?))",
              [$uid, $uid, $uid]];
    }
}

if (!function_exists('ak_event_can_create')) {
    /**
     * Créer un événement dans l'agenda de l'association : tout compte de l'association,
     * sauf un suiveur (financeur externe), qui ne voit que les projets qu'il suit.
     * Règle commune au site (nouveau-evenement.php) et à l'appli (api/app-create-event.php).
     */
    function ak_event_can_create(array $user): bool {
        return (int)($user['id'] ?? 0) > 0 && ($user['role'] ?? '') !== 'follower';
    }
}

if (!function_exists('ak_event_can_edit')) {
    /**
     * Modifier ou supprimer un événement : admin, coordinateur, compte ayant le droit
     * « 📅 Gérer les événements », ou créateur de l'événement. Jamais un suiveur.
     * Règle commune au site (evenement.php, action-evenement.php) et à l'appli
     * (api/app-event.php, api/app-event-action.php).
     */
    function ak_event_can_edit(array $user, array $event): bool {
        $uid = (int)($user['id'] ?? 0);
        $role = (string)($user['role'] ?? '');
        if ($uid <= 0 || $role === 'follower') return false;
        if (in_array($role, ['admin', 'coordinator', 'super_admin'], true) || !empty($user['is_super_admin'])) return true;
        if (!empty($user['can_manage_events'])) return true;
        // Utilisateur connecté dont la ligne ne porterait pas la colonne : même lecture que can()
        if (!array_key_exists('can_manage_events', $user) && function_exists('can') && function_exists('current_user')) {
            $cur = current_user();
            if ($cur && (int)$cur['id'] === $uid && can('manage_events')) return true;
        }
        return (int)($event['created_by'] ?? 0) === $uid;
    }
}

if (!function_exists('ak_event_valid_date')) {
    /** Date AAAA-MM-JJ réelle (un 30 février ou un mois 13 ne passent pas). */
    function ak_event_valid_date($d): bool {
        return is_string($d) && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $d, $m) === 1 && checkdate((int)$m[2], (int)$m[3], (int)$m[1]);
    }
}

if (!function_exists('ak_event_valid_time')) {
    /** Heure HH:MM (00:00 à 23:59, secondes tolérées). Un champ vidé ou à moitié saisi n'en est pas une. */
    function ak_event_valid_time($t): bool {
        return is_string($t) && preg_match('/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', $t) === 1;
    }
}

if (!function_exists('ak_event_plain_text')) {
    /** Description d'événement en texte brut (balises Google/Outlook/éditeur → sauts de ligne, entités décodées). */
    function ak_event_plain_text(?string $description): string {
        $s = preg_replace('#<br\s*/?>|</p>|</div>|</li>|</h[1-6]>#i', "\n", (string)$description);
        return trim(html_entity_decode(strip_tags($s), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }
}

function att_random_token(): string {
    return bin2hex(random_bytes(20));
}

function att_load(PDO $pdo, int $id, int $org_id): ?array {
    $stmt = $pdo->prepare("SELECT * FROM attendance_sessions WHERE id = ? AND org_id = ?");
    $stmt->execute([$id, $org_id]);
    return $stmt->fetch() ?: null;
}

function att_load_by_token(PDO $pdo, string $token): ?array {
    $stmt = $pdo->prepare("SELECT * FROM attendance_sessions WHERE access_token = ? LIMIT 1");
    $stmt->execute([$token]);
    return $stmt->fetch() ?: null;
}

function att_load_records(PDO $pdo, int $session_id): array {
    $stmt = $pdo->prepare("SELECT * FROM attendance_records WHERE session_id = ? ORDER BY signed_at DESC, id DESC");
    $stmt->execute([$session_id]);
    $rows = $stmt->fetchAll();
    // Émargements anciens enregistrés sans heure : on prend l'heure de création de la ligne.
    $fixed = false;
    foreach ($rows as &$r) {
        if (empty($r['signed_at']) && !empty($r['created_at'])) { $r['signed_at'] = $r['created_at']; $fixed = true; }
    }
    unset($r);
    if ($fixed) usort($rows, fn($a, $b) => strcmp((string)$b['signed_at'], (string)$a['signed_at']) ?: ((int)$b['id'] <=> (int)$a['id']));
    return $rows;
}

/** Date/heure d'émargement formatée, ou « — » si elle est inconnue. */
function att_fmt_time(?string $dt, string $format): string {
    return !empty($dt) && strtotime($dt) ? date($format, strtotime($dt)) : '—';
}

function att_org_stats(PDO $pdo, int $org_id): array {
    $r = ['total' => 0, 'open' => 0, 'today' => 0, 'this_week' => 0, 'records_total' => 0];
    try {
        $stmt = $pdo->prepare("SELECT
            COUNT(*) total,
            SUM(CASE WHEN is_open = 1 AND archived_at IS NULL THEN 1 ELSE 0 END) open_n,
            SUM(CASE WHEN DATE(starts_at) = CURDATE() THEN 1 ELSE 0 END) today_n,
            SUM(CASE WHEN starts_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) THEN 1 ELSE 0 END) week_n
            FROM attendance_sessions WHERE org_id = ? AND archived_at IS NULL");
        $stmt->execute([$org_id]);
        if ($x = $stmt->fetch()) {
            $r['total'] = (int)$x['total'];
            $r['open'] = (int)$x['open_n'];
            $r['today'] = (int)$x['today_n'];
            $r['this_week'] = (int)$x['week_n'];
        }
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM attendance_records ar JOIN attendance_sessions s ON s.id = ar.session_id WHERE s.org_id = ?");
        $stmt->execute([$org_id]);
        $r['records_total'] = (int)$stmt->fetchColumn();
    } catch (Throwable $e) {}
    return $r;
}
