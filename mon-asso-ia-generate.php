<?php
/**
 * mon-asso-ia-generate.php
 * --------------------------------------------------------------
 * Endpoint AJAX — génère un contenu IA et le sauvegarde
 * v2 (PHASE 4.6) : vérifie le quota AVANT l'appel API
 * --------------------------------------------------------------
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/rate-limit-helper.php';
ak_rate_limit_or_die('ia_generate', 20, 60, (string)($_SESSION['user_id'] ?? ($_SERVER['REMOTE_ADDR'] ?? 'anon')));
require_once __DIR__ . '/asso-ai-helpers.php';
require_once __DIR__ . '/asso-ai-quotas.php';

// === [PACK 6.2] Helpers du système de plans (silencieux si absent) ===
@require_once __DIR__ . '/plan-helpers.php';

header('Content-Type: application/json; charset=utf-8');

require_login();
$user = current_user();
$org_id  = (int)($user['org_id'] ?? 0);
$user_id = (int)($user['id'] ?? 0);
if ($org_id <= 0 || $user_id <= 0) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Non autorisé']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Méthode non autorisée']);
    exit;
}

// Anti-CSRF : cet endpoint déclenche des appels IA payants + écriture BDD.
if (!check_csrf($_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''))) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'csrf']);
    exit;
}

$tool = (string)($_POST['tool'] ?? '');
$catalog = ak_ai_tools_catalog();
if (!isset($catalog[$tool])) {
    echo json_encode(['ok' => false, 'error' => 'Outil inconnu']);
    exit;
}
$tool_def = $catalog[$tool];

// === [PACK 6.2] VÉRIFICATION DU PLAN AVANT QUOTA INTERNE ===
// Si plan-helpers.php est disponible, on vérifie d'abord les limites du plan tarifaire
if (function_exists('ak_can_use_ai_text') && function_exists('ak_can_use_ai_image')) {
    // Détection texte vs image (les outils image commencent par "image-" dans le catalogue)
    $is_image_tool = (strpos($tool, 'image-') === 0);

    $plan_check = $is_image_tool
        ? ak_can_use_ai_image($pdo, $org_id)
        : ak_can_use_ai_text($pdo, $org_id);

    if (!$plan_check['ok']) {
        echo json_encode([
            'ok'    => false,
            'error' => $plan_check['reason'] . '. Passez au plan supérieur pour continuer.',
            'quota' => [
                'allowed' => false,
                'reason'  => $plan_check['reason'],
                'limit'   => $plan_check['limit'] ?? 0,
                'current' => $plan_check['current'] ?? 0,
            ],
            'blocked_by_plan'  => true,
            'blocked_by_quota' => true,
            'upgrade_url'      => '/mon-asso-plan',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

// === [PHASE 4.6] VÉRIFICATION QUOTA AVANT TOUT ===
$quota = ak_ai_quota_check($pdo, $user, $tool);
if (!$quota['allowed']) {
    echo json_encode([
        'ok'        => false,
        'error'     => $quota['reason'] ?? 'Accès refusé.',
        'quota'     => $quota,
        'blocked_by_quota' => true,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// === Whitelist DYNAMIQUE des champs ===
$inputs = [];
$required_missing = [];
foreach (($tool_def['fields'] ?? []) as $field) {
    $name = $field['name'] ?? null;
    if (!$name) continue;
    if (isset($_POST[$name])) {
        $val = $_POST[$name];
        if (is_string($val)) $val = trim($val);
        $inputs[$name] = $val;
    }
    if (!empty($field['required']) && empty($inputs[$name])) {
        $required_missing[] = $field['label'] ?? $name;
    }
}

if (!empty($required_missing)) {
    echo json_encode([
        'ok'    => false,
        'error' => 'Champs obligatoires manquants : ' . implode(', ', $required_missing),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $org      = ak_ai_get_org_context($pdo, $org_id);
    $settings = ak_ai_get_settings($pdo, $org_id);
    $prompt   = ak_ai_build_prompt($tool, $inputs, $org, $settings);

    $result = ak_ai_call_api($prompt['system'], $prompt['user']);

    // Sauvegarde (succès ou erreur, pour traçabilité)
    $gen_id = ak_ai_save_generation($pdo, $org_id, $user_id, $tool, $inputs, $result);

    // Recalcule le quota après cette génération (pour affichage côté client)
    $quota_after = ak_ai_quota_check($pdo, $user, $tool);

    if ($result['ok']) {
        echo json_encode([
            'ok'         => true,
            'text'       => $result['text'],
            'gen_id'     => $gen_id,
            'tokens_in'  => $result['tokens_in'],
            'tokens_out' => $result['tokens_out'],
            'model'      => $result['model'],
            'quota'      => $quota_after,
        ], JSON_UNESCAPED_UNICODE);
    } else {
        echo json_encode([
            'ok'     => false,
            'error'  => $result['error'] ?? 'Erreur de génération',
            'gen_id' => $gen_id,
            'quota'  => $quota_after,
        ], JSON_UNESCAPED_UNICODE);
    }
} catch (Throwable $e) {
    error_log('[mon-asso-ia-generate] ' . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'Erreur serveur : ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
