<?php
/**
 * Helpers Présence / Émargement
 */

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
    $stmt = $pdo->prepare("SELECT * FROM attendance_records WHERE session_id = ? ORDER BY signed_at DESC");
    $stmt->execute([$session_id]);
    return $stmt->fetchAll();
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
