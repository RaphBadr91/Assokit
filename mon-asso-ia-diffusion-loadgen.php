<?php
/**
 * mon-asso-ia-diffusion-loadgen.php
 * --------------------------------------------------------------
 * Endpoint AJAX — charge une génération (titre + corps) pour préremplissage
 * --------------------------------------------------------------
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/asso-ai-helpers.php';

header('Content-Type: application/json; charset=utf-8');

require_login();
$user = current_user();
$org_id = (int)($user['org_id'] ?? 0);
if ($org_id <= 0) { echo json_encode(['ok' => false]); exit; }

$id = (int)($_GET['id'] ?? 0);
$gen = ak_ai_load_generation($pdo, $id, $org_id);
if (!$gen) { echo json_encode(['ok' => false]); exit; }

echo json_encode([
    'ok'     => true,
    'title'  => $gen['title']       ?? '',
    'output' => $gen['output_text'] ?? '',
], JSON_UNESCAPED_UNICODE);
