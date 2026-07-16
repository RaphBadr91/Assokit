<?php
/**
 * api/app-founder-prospects.php — Prospection conforme, cockpit Fondateur (natif).
 * GET             → liste + statistiques de campagne.
 * POST import     → importe des contacts (que TU as le droit de démarcher) : coller emails/CSV.
 * POST queue      → met des prospects en file d'envoi (séquence).
 * POST status     → change le statut (répondu / RDV pris / désinscrit).
 * POST delete     → supprime un prospect.
 * Réservé Fondateur/Super Admin. NE MODIFIE PAS le site.
 */
ob_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes-layout.php';
ob_end_clean();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
require_once __DIR__ . '/_app-prospect.php';

function pr_fail($c, $e, $m = null) { http_response_code($c); echo json_encode(['ok' => false, 'error' => $e, 'message' => $m], JSON_UNESCAPED_UNICODE); exit; }

if (empty($_SESSION['user_id'])) pr_fail(401, 'auth');
$user = function_exists('current_user') ? current_user() : null;
require_once __DIR__ . '/_app-founder.php';
$is_sa = app_is_founder($pdo, $user) || !empty($user['is_super_admin']) || (($user['role'] ?? '') === 'super_admin');
if (!$is_sa) pr_fail(403, 'forbidden');

ak_prospect_tables_ensure($pdo);

function pr_stats(PDO $pdo): array {
    $by = ['new' => 0, 'queued' => 0, 'contacted' => 0, 'engaged' => 0, 'replied' => 0, 'booked' => 0, 'unsubscribed' => 0, 'bounced' => 0];
    try { foreach ($pdo->query("SELECT status, COUNT(*) n FROM asso_prospects GROUP BY status")->fetchAll(PDO::FETCH_ASSOC) as $r) $by[(string) $r['status']] = (int) $r['n']; } catch (Throwable $e) {}
    $ev = ['sent' => 0, 'click' => 0, 'reply' => 0, 'unsubscribe' => 0];
    try { foreach ($pdo->query("SELECT type, COUNT(*) n FROM asso_prospect_events GROUP BY type")->fetchAll(PDO::FETCH_ASSOC) as $r) if (isset($ev[(string) $r['type']])) $ev[(string) $r['type']] = (int) $r['n']; } catch (Throwable $e) {}
    $today = 0;
    try { $today = (int) $pdo->query("SELECT COUNT(*) FROM asso_prospect_events WHERE type='sent' AND created_at >= CURDATE()")->fetchColumn(); } catch (Throwable $e) {}
    $total = array_sum($by);
    return [
        'total' => $total, 'by_status' => $by, 'events' => $ev,
        'sent_today' => $today, 'daily_cap' => AK_PROSPECT_DAILY_CAP,
        'sending_enabled' => (bool) AK_PROSPECT_SENDING_ENABLED,
    ];
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method !== 'POST') {
    try {
        $rows = [];
        foreach ($pdo->query("SELECT id, name, org_name, type, email, city, status, step, next_send_at, created_at
                              FROM asso_prospects ORDER BY created_at DESC LIMIT 200")->fetchAll(PDO::FETCH_ASSOC) as $p) {
            $rows[] = [
                'id' => (int) $p['id'], 'name' => (string) ($p['name'] ?? ''), 'org' => (string) ($p['org_name'] ?? ''),
                'type' => (string) $p['type'], 'email' => (string) $p['email'], 'city' => (string) ($p['city'] ?? ''),
                'status' => (string) $p['status'], 'step' => (int) $p['step'],
                'next' => !empty($p['next_send_at']) ? date('d/m/Y', strtotime($p['next_send_at'])) : '',
                'created' => !empty($p['created_at']) ? date('d/m/Y', strtotime($p['created_at'])) : '',
            ];
        }
        echo json_encode(['ok' => true, 'prospects' => $rows, 'stats' => pr_stats($pdo)], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) { error_log('[app-founder-prospects] ' . $e->getMessage()); pr_fail(500, 'server'); }
    exit;
}

$raw = file_get_contents('php://input');
$input = json_decode((string) $raw, true);
if (!is_array($input)) $input = [];
$token = (string) ($input['csrf'] ?? '');
if (empty($_SESSION['csrf_token']) || !hash_equals((string) $_SESSION['csrf_token'], $token)) pr_fail(403, 'csrf', 'Session expirée.');
$act = (string) ($input['action'] ?? '');

try {
    if ($act === 'import') {
        $type = (($input['type'] ?? 'asso') === 'tpe') ? 'tpe' : 'asso';
        $source = mb_substr(trim((string) ($input['source'] ?? 'import')), 0, 80);
        $text = (string) ($input['text'] ?? '');
        $lines = preg_split('/[\r\n]+/', $text);
        $added = 0; $skipped = 0;
        $ins = $pdo->prepare("INSERT INTO asso_prospects (name, org_name, type, email, city, source, status, created_at)
                              VALUES (?, ?, ?, ?, ?, ?, 'new', NOW())
                              ON DUPLICATE KEY UPDATE id = id");
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') continue;
            // Formats acceptés : "email" | "email;nom;organisation;ville" | "email,nom,orga,ville"
            $parts = preg_split('/[;,\t]/', $line);
            $email = strtolower(trim($parts[0] ?? ''));
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { $skipped++; continue; }
            $name = trim($parts[1] ?? '');
            $org  = trim($parts[2] ?? '');
            $city = trim($parts[3] ?? '');
            $ins->execute([$name ?: null, $org ?: null, $type, $email, $city ?: null, $source]);
            if ($ins->rowCount() > 0) $added++; else $skipped++;
        }
        echo json_encode(['ok' => true, 'added' => $added, 'skipped' => $skipped, 'stats' => pr_stats($pdo)], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($act === 'queue') {
        // Met en file les prospects 'new' (jamais les désinscrits/répondus)
        $ids = $input['ids'] ?? null;
        if (is_array($ids) && count($ids)) {
            $in = implode(',', array_map('intval', $ids));
            $pdo->exec("UPDATE asso_prospects SET status='queued', step=0, next_send_at=NOW() WHERE id IN ($in) AND status='new'");
        } else {
            $pdo->exec("UPDATE asso_prospects SET status='queued', step=0, next_send_at=NOW() WHERE status='new'");
        }
        echo json_encode(['ok' => true, 'stats' => pr_stats($pdo)], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($act === 'status') {
        $id = (int) ($input['id'] ?? 0);
        $status = (string) ($input['status'] ?? '');
        if ($id <= 0 || !in_array($status, ['new', 'replied', 'booked', 'unsubscribed'], true)) pr_fail(400, 'params');
        $next = ($status === 'new') ? 'NULL' : 'NULL';
        $pdo->prepare("UPDATE asso_prospects SET status = ?, next_send_at = NULL WHERE id = ?")->execute([$status, $id]);
        ak_prospect_event($pdo, $id, $status === 'booked' ? 'booked' : ($status === 'replied' ? 'reply' : 'status'), null, 'manual');
        echo json_encode(['ok' => true, 'stats' => pr_stats($pdo)], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($act === 'delete') {
        $id = (int) ($input['id'] ?? 0);
        if ($id <= 0) pr_fail(400, 'id');
        $pdo->prepare("DELETE FROM asso_prospects WHERE id = ?")->execute([$id]);
        $pdo->prepare("DELETE FROM asso_prospect_events WHERE prospect_id = ?")->execute([$id]);
        echo json_encode(['ok' => true, 'stats' => pr_stats($pdo)], JSON_UNESCAPED_UNICODE);
        exit;
    }

    pr_fail(400, 'action', 'Action inconnue.');
} catch (Throwable $e) {
    error_log('[app-founder-prospects] ' . $e->getMessage());
    pr_fail(500, 'server', 'Opération impossible.');
}
