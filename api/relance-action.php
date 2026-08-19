<?php
/**
 * api/relance-action.php
 * ------------------------------------------------------------------
 * Endpoint JSON des relances intelligentes.
 * Actions : send-invoice | send-invoice-custom | send-membership |
 *           send-membership-custom | ai-draft | save-prefs | batch
 * Sécurité : login + can('manage_finances') + CSRF ; org_id = session.
 * ------------------------------------------------------------------
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../relances-engine.php';
require_once __DIR__ . '/../rate-limit-helper.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['ok'=>false,'error'=>'method']); exit; }

require_login();
$user   = current_user();
$org_id = (int)($user['org_id'] ?? 0);
$uid    = (int)($user['id'] ?? 0);
if ($org_id <= 0) { http_response_code(403); echo json_encode(['ok'=>false,'error'=>'no_org']); exit; }
if (!function_exists('can') || !can('manage_finances')) { http_response_code(403); echo json_encode(['ok'=>false,'error'=>'forbidden']); exit; }

$body = json_decode(file_get_contents('php://input'), true);
if (!is_array($body)) $body = $_POST;
$csrf = (string)($body['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
if (!check_csrf($csrf)) { http_response_code(403); echo json_encode(['ok'=>false,'error'=>'csrf']); exit; }

// Anti-abus : l'envoi d'emails et les appels IA sont coûteux -> fail-closed.
if (!function_exists('ak_rate_limit_or_die')) { http_response_code(500); echo json_encode(['ok'=>false,'error'=>'rl_unavailable']); exit; }

$action = (string)($body['action'] ?? '');

try {
    switch ($action) {

        case 'send-invoice': {
            ak_rate_limit_or_die('relance_send', 40, 60, (string)$uid);
            $iid = (int)($body['invoice_id'] ?? 0);
            $stage = max(1, min(3, (int)($body['stage'] ?? 1)));
            // Vérif appartenance org
            $chk = $pdo->prepare("SELECT 1 FROM asso_invoices WHERE id=? AND org_id=?");
            $chk->execute([$iid, $org_id]);
            if (!$chk->fetchColumn()) { http_response_code(404); echo json_encode(['ok'=>false,'error'=>'not_found']); break; }
            $res = ak_asso_invoice_send_email($pdo, $iid, 'dunning'.$stage, $uid);
            echo json_encode(['ok'=>!empty($res['success']), 'message'=>$res['message'] ?? '']);
            break;
        }

        case 'send-invoice-custom': {
            ak_rate_limit_or_die('relance_send', 40, 60, (string)$uid);
            $iid = (int)($body['invoice_id'] ?? 0);
            $stage = max(1, min(3, (int)($body['stage'] ?? 1)));
            $subject = trim((string)($body['subject'] ?? ''));
            $msg = trim((string)($body['body'] ?? ''));
            if ($subject === '' || mb_strlen($msg) < 10) { echo json_encode(['ok'=>false,'error'=>'empty']); break; }
            $res = ak_rel_send_invoice_custom($pdo, $org_id, $iid, $stage, mb_substr($subject,0,255), mb_substr($msg,0,4000), $uid);
            echo json_encode(['ok'=>!empty($res['success']), 'message'=>$res['message'] ?? '']);
            break;
        }

        case 'send-membership': {
            ak_rate_limit_or_die('relance_send', 40, 60, (string)$uid);
            $mid = (int)($body['user_id'] ?? 0);
            $stage = max(1, min(3, (int)($body['stage'] ?? 1)));
            $res = ak_rel_send_membership($pdo, $org_id, $mid, $stage, $uid);
            echo json_encode(['ok'=>!empty($res['success']), 'message'=>$res['message'] ?? '']);
            break;
        }

        case 'send-membership-custom': {
            ak_rate_limit_or_die('relance_send', 40, 60, (string)$uid);
            $mid = (int)($body['user_id'] ?? 0);
            $stage = max(1, min(3, (int)($body['stage'] ?? 1)));
            $subject = trim((string)($body['subject'] ?? ''));
            $msg = trim((string)($body['body'] ?? ''));
            if ($subject === '' || mb_strlen($msg) < 10) { echo json_encode(['ok'=>false,'error'=>'empty']); break; }
            $res = ak_rel_send_membership($pdo, $org_id, $mid, $stage, $uid, mb_substr($msg,0,4000), mb_substr($subject,0,255));
            echo json_encode(['ok'=>!empty($res['success']), 'message'=>$res['message'] ?? '']);
            break;
        }

        case 'ai-draft': {
            // Reformulation IA — coûteux : quota strict.
            ak_rate_limit_or_die('relance_ai', 15, 60, (string)$uid);
            ak_rate_limit_or_die('relance_ai_org', 60, 60, 'org'.$org_id);
            require_once __DIR__ . '/../asso-ai-helpers.php';
            if (!function_exists('ak_ai_call_api')) { echo json_encode(['ok'=>false,'error'=>'ai_unavailable']); break; }

            $type = (string)($body['target'] ?? '');
            $id   = (int)($body['id'] ?? 0);
            $stage= max(1, min(3, (int)($body['stage'] ?? 1)));
            $tone = in_array(($body['tone'] ?? ''), ['chaleureux','neutre','ferme'], true) ? $body['tone'] : 'neutre';

            // Faits déterministes (jamais inventés par l'IA)
            $facts = null;
            if ($type === 'invoice') {
                $st = $pdo->prepare("SELECT i.invoice_number, i.amount_ttc_cents, DATE(i.due_at) due_at,
                        DATEDIFF(CURDATE(), DATE(i.due_at)) retard, c.display_name client, o.name org
                        FROM asso_invoices i LEFT JOIN asso_clients c ON c.id=i.client_id
                        LEFT JOIN organizations o ON o.id=i.org_id WHERE i.id=? AND i.org_id=?");
                $st->execute([$id, $org_id]);
                $f = $st->fetch(PDO::FETCH_ASSOC);
                if ($f) $facts = [
                    'nature'=>'facture', 'destinataire'=>$f['client'] ?: 'le client', 'emetteur'=>$f['org'],
                    'reference'=>$f['invoice_number'], 'montant'=>ak_rel_eur((int)$f['amount_ttc_cents']),
                    'echeance'=>date('d/m/Y', strtotime($f['due_at'])), 'retard_jours'=>(int)$f['retard'],
                ];
            } elseif ($type === 'membership') {
                $st = $pdo->prepare("SELECT u.first_name, u.last_name, DATE(u.adhesion_valid_until) fin,
                        DATEDIFF(CURDATE(), DATE(u.adhesion_valid_until)) retard, o.name org
                        FROM users u JOIN organizations o ON o.id=u.org_id WHERE u.id=? AND u.org_id=?");
                $st->execute([$id, $org_id]);
                $f = $st->fetch(PDO::FETCH_ASSOC);
                if ($f) $facts = [
                    'nature'=>'cotisation', 'destinataire'=>trim($f['first_name'].' '.$f['last_name']) ?: 'l\'adhérent',
                    'emetteur'=>$f['org'], 'echeance'=>$f['fin'] ? date('d/m/Y', strtotime($f['fin'])) : '—',
                    'retard_jours'=>(int)$f['retard'],
                ];
            }
            if (!$facts) { echo json_encode(['ok'=>false,'error'=>'not_found']); break; }

            $stageLbl = ak_rel_stage_meta($stage)[0];
            $toneLbl = ['chaleureux'=>'chaleureux et bienveillant','neutre'=>'courtois et professionnel','ferme'=>'ferme mais respectueux'][$tone];
            $system = "Tu rédiges des messages de relance en français pour une association ou une TPE. "
                . "Ton : $toneLbl. Style sobre, sans exagération, 4 à 8 lignes maximum. "
                . "RÈGLE ABSOLUE : n'invente AUCUN chiffre, montant, date ni référence ; utilise EXACTEMENT ceux fournis. "
                . "N'ajoute pas de menace juridique sauf si le stade est « Mise en demeure ». "
                . "Réponds UNIQUEMENT par un objet JSON strict : {\"subject\":\"...\",\"body\":\"...\"} sans texte autour.";
            $userMsg = "Stade de relance : $stageLbl.\nFaits (à utiliser tels quels) :\n".json_encode($facts, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT)
                . "\n\nRédige l'objet et le corps du message (signé par l'émetteur). Tutoiement interdit, vouvoiement.";

            $r = ak_ai_call_api($system, $userMsg);
            if (empty($r['ok'])) { echo json_encode(['ok'=>false,'error'=>'ai_error','detail'=>mb_substr((string)($r['error']??''),0,140)]); break; }
            $txt = trim((string)$r['text']);
            // Extraire le JSON même si entouré de texte.
            if (preg_match('/\{.*\}/s', $txt, $mm)) $txt = $mm[0];
            $parsed = json_decode($txt, true);
            if (!is_array($parsed) || empty($parsed['body'])) { echo json_encode(['ok'=>false,'error'=>'ai_parse']); break; }
            echo json_encode(['ok'=>true, 'subject'=>mb_substr((string)($parsed['subject'] ?? ''),0,255), 'body'=>mb_substr((string)$parsed['body'],0,4000)], JSON_UNESCAPED_UNICODE);
            break;
        }

        case 'save-prefs': {
            $bit = fn($v)=> (!empty($v) && $v!=='0' && $v!=='false') ? 1 : 0;
            $maxs = max(1, min(3, (int)($body['max_stage'] ?? 2)));
            $st = $pdo->prepare("INSERT INTO org_relance_prefs (org_id, auto_invoices, auto_memberships, max_stage, updated_at)
                                 VALUES (:o,:ai,:am,:ms,NOW())
                                 ON DUPLICATE KEY UPDATE auto_invoices=VALUES(auto_invoices),
                                   auto_memberships=VALUES(auto_memberships), max_stage=VALUES(max_stage), updated_at=NOW()");
            $st->execute([':o'=>$org_id, ':ai'=>$bit($body['auto_invoices'] ?? 0), ':am'=>$bit($body['auto_memberships'] ?? 0), ':ms'=>$maxs]);
            echo json_encode(['ok'=>true]);
            break;
        }

        case 'batch': {
            // Envoie toutes les relances recommandées (plafond de sécurité).
            ak_rate_limit_or_die('relance_batch', 4, 300, (string)$uid);
            $sent = 0; $failed = 0; $cap = 100;
            foreach (ak_rel_invoice_targets($pdo, $org_id) as $t) {
                if (!$t['due_now']) continue;
                if ($sent >= $cap) break;
                $res = ak_asso_invoice_send_email($pdo, (int)$t['id'], 'dunning'.$t['stage'], $uid);
                if (!empty($res['success'])) $sent++; else $failed++;
            }
            foreach (ak_rel_membership_targets($pdo, $org_id) as $t) {
                if (!$t['due_now']) continue;
                if ($sent >= $cap) break;
                $res = ak_rel_send_membership($pdo, $org_id, (int)$t['id'], (int)$t['stage'], $uid);
                if (!empty($res['success'])) $sent++; else $failed++;
            }
            echo json_encode(['ok'=>true, 'sent'=>$sent, 'failed'=>$failed]);
            break;
        }

        default:
            http_response_code(400); echo json_encode(['ok'=>false,'error'=>'unknown_action']);
    }
} catch (Throwable $e) {
    error_log('[relance-action] '.$e->getMessage());
    http_response_code(500); echo json_encode(['ok'=>false,'error'=>'internal']);
}
