<?php
/**
 * Helpers Assemblées Générales
 */

function ag_type_label(string $t): string {
    return [
        'ag_ord' => 'AG Ordinaire', 'ag_ext' => 'AG Extraordinaire',
        'ca' => 'Conseil d\'Administration', 'bureau' => 'Bureau'
    ][$t] ?? $t;
}

function ag_status_meta(string $s): array {
    return [
        'draft'       => ['Brouillon',     '#6b7280', '#f3f4f6', '📝'],
        'sent'        => ['Convoquée',     '#3B82F6', '#DBEAFE', '📨'],
        'in_progress' => ['En cours',      '#10B981', '#ECFDF5', '🟢'],
        'closed'      => ['Clôturée',      '#8B5CF6', '#EDE9FE', '✅'],
        'archived'    => ['Archivée',      '#9ca3af', '#f9fafb', '📦'],
    ][$s] ?? ['—', '#6b7280', '#f3f4f6', '?'];
}

function ag_vote_type_label(string $t): string {
    return [
        'simple' => 'Majorité simple (>50%)',
        'qualifie_2_3' => 'Majorité qualifiée (2/3)',
        'qualifie_3_4' => 'Majorité qualifiée (3/4)',
        'unanime' => 'Unanimité',
        'consultatif' => 'Consultatif (sans effet)'
    ][$t] ?? $t;
}

function ag_load(PDO $pdo, int $id, int $org_id): ?array {
    $stmt = $pdo->prepare("SELECT * FROM assemblies WHERE id = ? AND org_id = ?");
    $stmt->execute([$id, $org_id]);
    return $stmt->fetch() ?: null;
}

function ag_load_by_token(PDO $pdo, string $token): ?array {
    $stmt = $pdo->prepare("SELECT a.*, ass.org_id, ass.title AS ag_title, ass.scheduled_at, ass.location, ass.location_url, ass.status AS ag_status,
        ass.description AS ag_description, ass.type AS ag_type
        FROM assembly_attendees a JOIN assemblies ass ON ass.id = a.assembly_id
        WHERE a.access_token = ? LIMIT 1");
    $stmt->execute([$token]);
    return $stmt->fetch() ?: null;
}

function ag_load_attendees(PDO $pdo, int $assembly_id): array {
    $stmt = $pdo->prepare("SELECT * FROM assembly_attendees WHERE assembly_id = ? ORDER BY full_name");
    $stmt->execute([$assembly_id]);
    return $stmt->fetchAll();
}

function ag_load_resolutions(PDO $pdo, int $assembly_id): array {
    $stmt = $pdo->prepare("SELECT * FROM assembly_resolutions WHERE assembly_id = ? ORDER BY position, id");
    $stmt->execute([$assembly_id]);
    return $stmt->fetchAll();
}

function ag_resolution_stats(PDO $pdo, int $resolution_id): array {
    $r = ['for' => 0, 'against' => 0, 'abstain' => 0, 'total' => 0];
    $stmt = $pdo->prepare("SELECT choice, COUNT(*) c FROM assembly_votes WHERE resolution_id = ? GROUP BY choice");
    $stmt->execute([$resolution_id]);
    foreach ($stmt->fetchAll() as $row) {
        $r[$row['choice']] = (int)$row['c'];
    }
    $r['total'] = $r['for'] + $r['against'] + $r['abstain'];
    return $r;
}

function ag_compute_result(string $vote_type, array $stats): string {
    $expr = $stats['for'] + $stats['against']; // abstentions exclues du calcul majoritaire
    if ($expr === 0) return 'rejected';
    $ratio = $stats['for'] / $expr;
    switch ($vote_type) {
        case 'simple':       return $ratio > 0.5 ? 'adopted' : 'rejected';
        case 'qualifie_2_3': return $ratio >= (2/3) ? 'adopted' : 'rejected';
        case 'qualifie_3_4': return $ratio >= 0.75 ? 'adopted' : 'rejected';
        case 'unanime':      return ($stats['against'] === 0 && $stats['for'] > 0) ? 'adopted' : 'rejected';
        case 'consultatif':  return $ratio > 0.5 ? 'adopted' : 'rejected';
    }
    return 'rejected';
}

function ag_quorum_status(array $assembly, array $attendees): array {
    $present = 0;
    foreach ($attendees as $a) if (in_array($a['status'], ['present','proxy'], true)) $present++;
    $req = (int)($assembly['quorum_required'] ?? 0);
    if ($req <= 0) return ['ok' => true, 'present' => $present, 'required' => 0, 'percent' => 100];
    return [
        'ok' => $present >= $req,
        'present' => $present,
        'required' => $req,
        'percent' => $req > 0 ? round(($present / $req) * 100) : 100,
    ];
}

function ag_org_stats(PDO $pdo, int $org_id): array {
    $r = ['total' => 0, 'upcoming' => 0, 'in_progress' => 0, 'closed' => 0];
    try {
        $stmt = $pdo->prepare("SELECT
            COUNT(*) total,
            SUM(CASE WHEN status IN ('draft','sent') AND scheduled_at >= NOW() THEN 1 ELSE 0 END) upcoming,
            SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END) in_progress,
            SUM(CASE WHEN status = 'closed' THEN 1 ELSE 0 END) closed
            FROM assemblies WHERE org_id = ? AND archived_at IS NULL");
        $stmt->execute([$org_id]);
        if ($x = $stmt->fetch()) $r = array_merge($r, array_map('intval', $x));
    } catch (Throwable $e) {}
    return $r;
}

function ag_random_token(): string {
    return bin2hex(random_bytes(20));
}
