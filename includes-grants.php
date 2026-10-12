<?php
/**
 * Helpers Subventions
 */

function gr_status_meta(string $s): array {
    return [
        'draft'     => ['Brouillon',  '#6b7280', '#f3f4f6', '📝'],
        'submitted' => ['Déposé',     '#3B82F6', '#DBEAFE', '📤'],
        'in_review' => ['Instruction','#F59E0B', '#FEF3C7', '🔍'],
        'granted'   => ['Accordé',    '#10B981', '#ECFDF5', '✅'],
        'rejected'  => ['Refusé',     '#EF4444', '#FEE2E2', '❌'],
        'reported'  => ['Bilan rendu','#8B5CF6', '#EDE9FE', '📊'],
        'archived'  => ['Archivé',    '#9ca3af', '#f9fafb', '📦'],
    ][$s] ?? ['—', '#6b7280', '#f3f4f6', '?'];
}

/**
 * Nombre de jours calendaires de $from à $to (négatif si $to est passé).
 * Les dates sont lues en UTC : un écart en secondes divisé par 86 400
 * perd ou gagne une heure les jours de changement d'heure, et le rappel
 * « J-7 » partait alors à J-6 ou à J-8.
 */
if (!function_exists('gr_days_between')) {
function gr_days_between(string $from, string $to): ?int {
    $from = substr(trim($from), 0, 10); $to = substr(trim($to), 0, 10);
    foreach ([$from, $to] as $d) {   // « 0000-00-00 » = pas de date
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $d, $p) || !checkdate((int)$p[2], (int)$p[3], (int)$p[1])) return null;
    }
    try {
        $utc = new DateTimeZone('UTC');
        return (int)(new DateTimeImmutable($from, $utc))->diff(new DateTimeImmutable($to, $utc))->format('%r%a');
    } catch (Throwable $e) { return null; }
}
}

/**
 * Accès aux dossiers de subvention (liste, fiche, formulaire, appli) :
 * administrateur ou coordinateur, les rôles que les actions acceptent
 * déjà (action-subvention.php, api/app-create-grant.php). Les dossiers
 * portent les montants, les contacts des financeurs et les notes
 * internes : ni un suiveur (financeur externe), ni un membre, ni un
 * bénévole n'y ont accès.
 */
if (!function_exists('gr_can_manage')) {
function gr_can_manage(?array $user): bool {
    if (!$user) return false;
    $role = (string)($user['role'] ?? '');
    if ($role === 'follower') return false;
    return in_array($role, ['admin', 'coordinator', 'founder', 'super_admin'], true)
        || !empty($user['is_founder']) || !empty($user['is_super_admin']);
}
}

/**
 * Pages des dossiers (liste, fiche, formulaire) : renvoie vers le tableau
 * de bord, avec un message, qui n'y a pas accès.
 */
if (!function_exists('gr_require_manage')) {
function gr_require_manage(?array $user): void {
    if (gr_can_manage($user)) return;
    // Un suiveur n'a que ses projets (le tableau de bord l'y renvoie déjà,
    // et le message resterait en attente sans être lu).
    if (($user['role'] ?? '') === 'follower') { header('Location: /projets'); exit; }
    $_SESSION['flash_error'] = 'Les dossiers de subvention sont réservés aux administrateurs et aux coordinateurs.';
    header('Location: /dashboard');
    exit;
}
}

/**
 * Jours restants avant la date limite de dépôt (négatif : dépassée),
 * seulement pour un dossier pas encore déposé. Un dossier déposé
 * (submitted, in_review…) n'est ni « en retard » ni « urgent » au titre
 * du dépôt : null. Jours calendaires, comme DATEDIFF() du tableau de bord.
 */
if (!function_exists('gr_days_to_apply')) {
function gr_days_to_apply(array $g): ?int {
    if (($g['status'] ?? '') !== 'draft' || !empty($g['submitted_at']) || empty($g['deadline_apply'])) return null;
    return gr_days_between(date('Y-m-d'), (string)$g['deadline_apply']);
}
}

/**
 * URL affichable comme lien : http(s) uniquement. filter_var() accepte
 * aussi « javascript://… », qui deviendrait un lien exécutable.
 */
if (!function_exists('gr_http_url')) {
function gr_http_url($url): ?string {
    $url = trim((string)$url);
    if ($url === '' || !preg_match('#^https?://#i', $url) || !filter_var($url, FILTER_VALIDATE_URL)) return null;
    return $url;
}
}

/** Dossier archivé (depuis le site ou l'appli). */
function gr_is_archived(array $g): bool {
    return !empty($g['archived_at']) || ($g['status'] ?? '') === 'archived';
}

/**
 * Archive un dossier. Le statut est remplacé par « archived » : on garde
 * l'ancien dans le journal pour pouvoir le rétablir au désarchivage.
 */
function gr_archive(PDO $pdo, array $g, ?int $user_id): void {
    $prev = (string)($g['status'] ?? '');
    $pdo->prepare("UPDATE grants SET archived_at = NOW(), status = 'archived' WHERE id = ? AND org_id = ?")
        ->execute([(int)$g['id'], (int)$g['org_id']]);
    $label = '📦 Dossier archivé';
    if ($prev !== '' && $prev !== 'archived') $label .= ' (statut : ' . gr_status_meta($prev)[0] . ')';
    gr_log($pdo, (int)$g['id'], $user_id, 'archive', $label);
}

/**
 * Statut à rétablir au désarchivage : celui noté lors de l'archivage,
 * sinon le plus avancé que les dates du dossier permettent d'affirmer.
 */
function gr_status_before_archive(PDO $pdo, array $g): string {
    if (($g['status'] ?? '') !== 'archived' && !empty($g['status'])) return (string)$g['status'];
    try {
        $st = $pdo->prepare("SELECT action_label FROM grant_activity_log WHERE grant_id = ? AND action_type = 'archive' ORDER BY id DESC LIMIT 1");
        $st->execute([(int)$g['id']]);
        $label = (string)$st->fetchColumn();
        if (preg_match('/\(statut : (.+)\)\s*$/u', $label, $mm)) {
            foreach (['draft','submitted','in_review','granted','rejected','reported'] as $s) {
                if (gr_status_meta($s)[0] === $mm[1]) return $s;
            }
        }
    } catch (Throwable $e) {}
    if (!empty($g['reported_at'])) return 'reported';
    if ((float)($g['amount_granted'] ?? 0) > 0) return 'granted';
    if (!empty($g['submitted_at'])) return 'submitted';
    return 'draft';
}

/** Désarchive un dossier et renvoie le statut rétabli. */
function gr_unarchive(PDO $pdo, array $g, ?int $user_id): string {
    $status = gr_status_before_archive($pdo, $g);
    $pdo->prepare("UPDATE grants SET archived_at = NULL, status = ? WHERE id = ? AND org_id = ?")
        ->execute([$status, (int)$g['id'], (int)$g['org_id']]);
    // Type « status_change », déjà connu du journal (sa colonne n'admet
    // peut-être pas de valeur nouvelle en production).
    gr_log($pdo, (int)$g['id'], $user_id, 'status_change', '♻️ Dossier désarchivé (statut : ' . gr_status_meta($status)[0] . ')');
    return $status;
}

function gr_funder_label(string $t): string {
    return [
        'etat' => '🏛️ État', 'region' => '🌍 Région', 'departement' => '🏞️ Département',
        'commune' => '🏘️ Commune', 'epci' => '🌐 EPCI / Métropole', 'caf' => '👨‍👩‍👧 CAF',
        'fondation' => '💎 Fondation', 'entreprise' => '🏢 Entreprise / Mécénat',
        'europe' => '🇪🇺 Europe', 'autre' => '➕ Autre'
    ][$t] ?? $t;
}

function gr_load(PDO $pdo, int $id, int $org_id): ?array {
    $stmt = $pdo->prepare("SELECT g.*, p.name AS project_name FROM grants g LEFT JOIN projects p ON p.id = g.project_id WHERE g.id = ? AND g.org_id = ?");
    $stmt->execute([$id, $org_id]);
    return $stmt->fetch() ?: null;
}

function gr_load_steps(PDO $pdo, int $grant_id): array {
    $stmt = $pdo->prepare("SELECT * FROM grant_steps WHERE grant_id = ? ORDER BY position ASC, id ASC");
    $stmt->execute([$grant_id]);
    return $stmt->fetchAll();
}

function gr_log(PDO $pdo, int $grant_id, ?int $user_id, string $type, string $label): void {
    try {
        $pdo->prepare("INSERT INTO grant_activity_log (grant_id, user_id, action_type, action_label) VALUES (?,?,?,?)")
            ->execute([$grant_id, $user_id, $type, $label]);
    } catch (Throwable $e) {}
}

/**
 * Dossier accordé : « Accordé », ou « Bilan rendu » (le bilan d'une
 * subvention accordée). Le total « Accordé » baissait au moment où
 * l'association rendait son bilan.
 */
if (!function_exists('gr_is_granted')) {
function gr_is_granted(string $status): bool {
    return $status === 'granted' || $status === 'reported';
}
}

function gr_org_stats(PDO $pdo, int $org_id): array {
    $r = ['count' => 0, 'requested' => 0.0, 'granted' => 0.0, 'pending' => 0, 'upcoming' => 0];
    try {
        // Échéances 30 j : dossiers encore à déposer (un dossier déposé n'a
        // plus d'échéance de dépôt, même règle que gr_days_to_apply()).
        // Un dossier au statut « archived » sans date d'archivage reste un
        // dossier archivé (gr_is_archived()) : hors des compteurs.
        $stmt = $pdo->prepare("SELECT COUNT(*) c, COALESCE(SUM(amount_requested),0) req, COALESCE(SUM(CASE WHEN status IN ('granted','reported') THEN amount_granted ELSE 0 END),0) gr,
            SUM(CASE WHEN status IN ('submitted','in_review') THEN 1 ELSE 0 END) pend,
            SUM(CASE WHEN deadline_apply IS NOT NULL AND deadline_apply >= CURDATE() AND deadline_apply <= DATE_ADD(CURDATE(), INTERVAL 30 DAY) AND status = 'draft' AND submitted_at IS NULL THEN 1 ELSE 0 END) up
            FROM grants WHERE org_id = ? AND archived_at IS NULL AND COALESCE(status, '') <> 'archived'");
        $stmt->execute([$org_id]);
        if ($x = $stmt->fetch()) {
            $r['count'] = (int)$x['c']; $r['requested'] = (float)$x['req']; $r['granted'] = (float)$x['gr'];
            $r['pending'] = (int)$x['pend']; $r['upcoming'] = (int)$x['up'];
        }
    } catch (Throwable $e) {}
    return $r;
}
