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

function gr_org_stats(PDO $pdo, int $org_id): array {
    $r = ['count' => 0, 'requested' => 0.0, 'granted' => 0.0, 'pending' => 0, 'upcoming' => 0];
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) c, COALESCE(SUM(amount_requested),0) req, COALESCE(SUM(CASE WHEN status='granted' THEN amount_granted ELSE 0 END),0) gr,
            SUM(CASE WHEN status IN ('submitted','in_review') THEN 1 ELSE 0 END) pend,
            SUM(CASE WHEN deadline_apply IS NOT NULL AND deadline_apply >= CURDATE() AND deadline_apply <= DATE_ADD(CURDATE(), INTERVAL 30 DAY) AND status IN ('draft','submitted') THEN 1 ELSE 0 END) up
            FROM grants WHERE org_id = ? AND archived_at IS NULL");
        $stmt->execute([$org_id]);
        if ($x = $stmt->fetch()) {
            $r['count'] = (int)$x['c']; $r['requested'] = (float)$x['req']; $r['granted'] = (float)$x['gr'];
            $r['pending'] = (int)$x['pend']; $r['upcoming'] = (int)$x['up'];
        }
    } catch (Throwable $e) {}
    return $r;
}
