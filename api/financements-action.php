<?php
/**
 * api/financements-action.php
 * ------------------------------------------------------------------
 * Endpoint JSON du radar de subventions.
 * Actions : save-profile | refresh | save | unsave | dismiss | restore
 * Sécurité : login + rôle admin + CSRF ; org_id vient de la session.
 * 100 % déterministe (aucun LLM).
 * ------------------------------------------------------------------
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../financements-engine.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['ok'=>false,'error'=>'method']); exit; }

require_login();
$user   = current_user();
$org_id = (int)($user['org_id'] ?? 0);
$uid    = (int)($user['id'] ?? 0);
if ($org_id <= 0) { http_response_code(403); echo json_encode(['ok'=>false,'error'=>'no_org']); exit; }

$role = strtolower((string)($user['role'] ?? ''));
$is_priv = in_array($role, ['admin','founder'], true) || !empty($user['is_founder']) || !empty($user['is_super_admin']);
if (!$is_priv) { http_response_code(403); echo json_encode(['ok'=>false,'error'=>'forbidden']); exit; }

$body = json_decode(file_get_contents('php://input'), true);
if (!is_array($body)) $body = $_POST;

$csrf = (string)($body['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
if (!check_csrf($csrf)) { http_response_code(403); echo json_encode(['ok'=>false,'error'=>'csrf']); exit; }

// Anti-abus léger (fail-open : opérations DB peu coûteuses).
if (function_exists('ak_rate_limit_or_die')) {
    ak_rate_limit_or_die('fin_action', 60, 60, (string)$uid);
}

$action = (string)($body['action'] ?? '');

/** Vérifie qu'un match appartient bien à l'org (anti-IDOR). */
$match_belongs = function(int $catalog_id) use ($pdo, $org_id): bool {
    $st = $pdo->prepare("SELECT 1 FROM grant_matches WHERE org_id = ? AND catalog_id = ? LIMIT 1");
    $st->execute([$org_id, $catalog_id]);
    return (bool)$st->fetchColumn();
};

try {
    switch ($action) {

        case 'save-profile': {
            $sectorsRef = array_keys(fin_sectors_catalog());
            $sectorsIn  = $body['sectors'] ?? [];
            if (!is_array($sectorsIn)) $sectorsIn = [];
            $sectors = array_values(array_intersect($sectorsRef, array_map('strval', $sectorsIn)));
            $sectorsCsv = implode(',', $sectors);

            $triState = function($v) {
                if ($v === null || $v === '' || $v === 'null') return null;
                return ((int)$v === 1 || $v === true || $v === '1' || $v === 'true') ? 1 : 0;
            };
            $is_qpv = $triState($body['is_qpv'] ?? null);
            $is_zrr = $triState($body['is_zrr'] ?? null);
            $is_ig  = $triState($body['is_interet_general'] ?? null);
            $region = trim((string)($body['region_code'] ?? '')) ?: null;
            $dept   = trim((string)($body['dept_code'] ?? '')) ?: null;

            $st = $pdo->prepare(
                "INSERT INTO org_grant_profile (org_id, region_code, dept_code, sectors, is_qpv, is_zrr, is_interet_general, updated_at)
                 VALUES (:o,:r,:d,:s,:q,:z,:g,NOW())
                 ON DUPLICATE KEY UPDATE region_code=VALUES(region_code), dept_code=VALUES(dept_code),
                   sectors=VALUES(sectors), is_qpv=VALUES(is_qpv), is_zrr=VALUES(is_zrr),
                   is_interet_general=VALUES(is_interet_general), updated_at=NOW()"
            );
            $st->execute([':o'=>$org_id, ':r'=>$region, ':d'=>$dept, ':s'=>$sectorsCsv, ':q'=>$is_qpv, ':z'=>$is_zrr, ':g'=>$is_ig]);

            $kept = fin_compute_matches($pdo, $org_id);
            echo json_encode(['ok'=>true, 'matches'=>$kept, 'stats'=>fin_stats($pdo, $org_id)]);
            break;
        }

        case 'refresh': {
            $kept = fin_compute_matches($pdo, $org_id);
            echo json_encode(['ok'=>true, 'matches'=>$kept, 'stats'=>fin_stats($pdo, $org_id)]);
            break;
        }

        case 'save-prefs': {
            $bit = fn($v) => (!empty($v) && $v !== '0' && $v !== 'false') ? 1 : 0;
            $score = (int)($body['min_match_score'] ?? 60);
            if ($score < 0) $score = 0; if ($score > 100) $score = 100;
            $st = $pdo->prepare(
                "INSERT INTO grant_alert_prefs (org_id, notify_new_match, min_match_score, notify_deadlines, channel_email, channel_app, updated_at)
                 VALUES (:o,:nm,:sc,:nd,:ce,:ca,NOW())
                 ON DUPLICATE KEY UPDATE notify_new_match=VALUES(notify_new_match), min_match_score=VALUES(min_match_score),
                   notify_deadlines=VALUES(notify_deadlines), channel_email=VALUES(channel_email),
                   channel_app=VALUES(channel_app), updated_at=NOW()"
            );
            $st->execute([
                ':o'=>$org_id,
                ':nm'=>$bit($body['notify_new_match'] ?? 1),
                ':sc'=>$score,
                ':nd'=>$bit($body['notify_deadlines'] ?? 1),
                ':ce'=>$bit($body['channel_email'] ?? 1),
                ':ca'=>$bit($body['channel_app'] ?? 1),
            ]);
            echo json_encode(['ok'=>true]);
            break;
        }

        case 'save':
        case 'unsave':
        case 'dismiss':
        case 'restore': {
            $catalog_id = (int)($body['catalog_id'] ?? 0);
            if ($catalog_id <= 0 || !$match_belongs($catalog_id)) {
                http_response_code(404); echo json_encode(['ok'=>false,'error'=>'not_found']); break;
            }
            $col = ($action === 'save' || $action === 'unsave') ? 'saved' : 'dismissed';
            $val = ($action === 'save' || $action === 'dismiss') ? 1 : 0;
            $st = $pdo->prepare("UPDATE grant_matches SET $col = ? WHERE org_id = ? AND catalog_id = ?");
            $st->execute([$val, $org_id, $catalog_id]);
            echo json_encode(['ok'=>true, 'stats'=>fin_stats($pdo, $org_id)]);
            break;
        }

        default:
            http_response_code(400); echo json_encode(['ok'=>false,'error'=>'unknown_action']);
    }
} catch (Throwable $e) {
    error_log('[financements-action] '.$e->getMessage());
    http_response_code(500); echo json_encode(['ok'=>false,'error'=>'internal']);
}
