<?php
/**
 * api/app-founder-plans.php — Gestion des plans tarifaires (Fondateur, natif).
 * GET            → liste des plans (tous champs + adoption).
 * POST create    → crée un plan.
 * POST update    → met à jour un plan.
 * POST delete    → supprime un plan (si aucune org ne l'utilise).
 * POST toggle    → bascule la visibilité.
 * Réservé Fondateur/Super Admin. Porte la logique de fondateur-plans.php. NE MODIFIE PAS le site.
 */
ob_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes-layout.php';
ob_end_clean();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function plans_fail($c, $e, $m = null) { http_response_code($c); echo json_encode(['ok' => false, 'error' => $e, 'message' => $m], JSON_UNESCAPED_UNICODE); exit; }

if (empty($_SESSION['user_id'])) plans_fail(401, 'auth');
$user = function_exists('current_user') ? current_user() : null;
require_once __DIR__ . '/_app-founder.php';
$is_sa = app_is_founder($pdo, $user) || !empty($user['is_super_admin']) || (($user['role'] ?? '') === 'super_admin');
if (!$is_sa) plans_fail(403, 'forbidden');

// Champs entiers-nullable (quotas) et booléens (features)
$INT_FIELDS = ['limit_adherents', 'limit_invoices_total', 'limit_quotes_total', 'limit_contacts', 'limit_users',
    'limit_ai_text_per_month', 'limit_ai_image_per_month', 'limit_emails_per_month'];
$BOOL_FIELDS = ['feature_recurring_invoices', 'feature_signature_quotes', 'feature_email_diffusion',
    'feature_advanced_stats', 'feature_priority_support', 'feature_custom_domain', 'feature_dedicated_support'];

function plans_list(PDO $pdo, array $INT_FIELDS, array $BOOL_FIELDS): array {
    $rows = $pdo->query("SELECT * FROM asso_plans ORDER BY display_order ASC, price_cents ASC")->fetchAll(PDO::FETCH_ASSOC);
    // Adoption : nb d'orgs par slug de plan
    $adoption = [];
    try {
        foreach ($pdo->query("SELECT plan, COUNT(*) AS nb FROM organizations WHERE deleted_at IS NULL GROUP BY plan")->fetchAll(PDO::FETCH_ASSOC) as $a) {
            $adoption[(string) $a['plan']] = (int) $a['nb'];
        }
    } catch (Throwable $e) {}
    $out = [];
    foreach ($rows as $p) {
        $item = [
            'id'            => (int) $p['id'],
            'slug'          => (string) ($p['slug'] ?? ''),
            'name'          => (string) ($p['name'] ?? ''),
            'tagline'       => (string) ($p['tagline'] ?? ''),
            'price_eur'     => round(((int) ($p['price_cents'] ?? 0)) / 100, 2),
            'price_label'   => (string) ($p['price_label'] ?? ''),
            'is_custom_quote' => !empty($p['is_custom_quote']),
            'is_visible'    => !empty($p['is_visible']),
            'is_featured'   => !empty($p['is_featured']),
            'is_trial'      => !empty($p['is_trial']),
            'display_order' => (int) ($p['display_order'] ?? 0),
            'adoption'      => $adoption[(string) ($p['slug'] ?? '')] ?? 0,
        ];
        foreach ($INT_FIELDS as $f) $item[$f] = ($p[$f] === null || $p[$f] === '') ? null : (int) $p[$f];
        foreach ($BOOL_FIELDS as $f) $item[$f] = !empty($p[$f]);
        $out[] = $item;
    }
    return $out;
}

// --- GET : liste ---
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    try { echo json_encode(['ok' => true, 'plans' => plans_list($pdo, $INT_FIELDS, $BOOL_FIELDS)], JSON_UNESCAPED_UNICODE); }
    catch (Throwable $e) { error_log('[app-founder-plans] ' . $e->getMessage()); plans_fail(500, 'server'); }
    exit;
}

// --- POST : CSRF ---
$raw = file_get_contents('php://input');
$input = json_decode((string) $raw, true);
if (!is_array($input)) $input = [];
$token = (string) ($input['csrf'] ?? '');
if (empty($_SESSION['csrf_token']) || !hash_equals((string) $_SESSION['csrf_token'], $token)) plans_fail(403, 'csrf', 'Session expirée, réessaie.');

$act = (string) ($input['action'] ?? '');

try {
    if ($act === 'delete') {
        $pid = (int) ($input['plan_id'] ?? 0);
        if ($pid <= 0) plans_fail(400, 'plan_id');
        // Refuse la suppression si des orgs utilisent ce plan (par slug)
        $slug = (string) $pdo->query("SELECT slug FROM asso_plans WHERE id = " . $pid)->fetchColumn();
        if ($slug !== '') {
            $used = (int) $pdo->query("SELECT COUNT(*) FROM organizations WHERE plan = " . $pdo->quote($slug) . " AND deleted_at IS NULL")->fetchColumn();
            if ($used > 0) plans_fail(409, 'in_use', "Ce plan est utilisé par $used organisation(s).");
        }
        $pdo->prepare("DELETE FROM asso_plans WHERE id = ?")->execute([$pid]);
        echo json_encode(['ok' => true, 'deleted' => $pid, 'plans' => plans_list($pdo, $INT_FIELDS, $BOOL_FIELDS)], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($act === 'toggle') {
        $pid = (int) ($input['plan_id'] ?? 0);
        if ($pid <= 0) plans_fail(400, 'plan_id');
        $pdo->prepare("UPDATE asso_plans SET is_visible = 1 - COALESCE(is_visible,0) WHERE id = ?")->execute([$pid]);
        echo json_encode(['ok' => true, 'plans' => plans_list($pdo, $INT_FIELDS, $BOOL_FIELDS)], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($act === 'create' || $act === 'update') {
        $slug = preg_replace('/[^a-z0-9-]/', '', strtolower(trim((string) ($input['slug'] ?? ''))));
        $name = trim((string) ($input['name'] ?? ''));
        if ($slug === '' || $name === '') plans_fail(400, 'required', 'Slug et nom obligatoires.');

        $data = [
            'slug'          => $slug,
            'name'          => $name,
            'tagline'       => trim((string) ($input['tagline'] ?? '')),
            'price_cents'   => (int) round(((float) ($input['price_eur'] ?? 0)) * 100),
            'price_label'   => trim((string) ($input['price_label'] ?? '')) ?: null,
            'is_custom_quote' => !empty($input['is_custom_quote']) ? 1 : 0,
            'is_featured'   => !empty($input['is_featured']) ? 1 : 0,
            'is_visible'    => !empty($input['is_visible']) ? 1 : 0,
            'display_order' => (int) ($input['display_order'] ?? 0),
        ];
        foreach ($INT_FIELDS as $f) {
            $v = $input[$f] ?? null;
            $data[$f] = ($v === '' || $v === null) ? null : (int) $v;
        }
        foreach ($BOOL_FIELDS as $f) $data[$f] = !empty($input[$f]) ? 1 : 0;

        if ($act === 'create') {
            // Unicité du slug
            $c = $pdo->prepare("SELECT COUNT(*) FROM asso_plans WHERE slug = ?");
            $c->execute([$slug]);
            if ((int) $c->fetchColumn() > 0) plans_fail(409, 'slug_used', 'Ce slug existe déjà.');
            $cols = array_keys($data);
            $ph = implode(', ', array_map(fn($c) => ':' . $c, $cols));
            $st = $pdo->prepare("INSERT INTO asso_plans (" . implode(', ', $cols) . ") VALUES ($ph)");
            foreach ($data as $k => $v) $st->bindValue(':' . $k, $v, ($v === null ? PDO::PARAM_NULL : (is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR)));
            $st->execute();
            $newId = (int) $pdo->lastInsertId();
            echo json_encode(['ok' => true, 'plan_id' => $newId, 'plans' => plans_list($pdo, $INT_FIELDS, $BOOL_FIELDS)], JSON_UNESCAPED_UNICODE);
            exit;
        } else {
            $pid = (int) ($input['plan_id'] ?? 0);
            if ($pid <= 0) plans_fail(400, 'plan_id');
            $set = implode(', ', array_map(fn($c) => "$c = :$c", array_keys($data)));
            $st = $pdo->prepare("UPDATE asso_plans SET $set WHERE id = :id");
            foreach ($data as $k => $v) $st->bindValue(':' . $k, $v, ($v === null ? PDO::PARAM_NULL : (is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR)));
            $st->bindValue(':id', $pid, PDO::PARAM_INT);
            $st->execute();
            echo json_encode(['ok' => true, 'plan_id' => $pid, 'plans' => plans_list($pdo, $INT_FIELDS, $BOOL_FIELDS)], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }

    plans_fail(400, 'action', 'Action inconnue.');
} catch (Throwable $e) {
    error_log('[app-founder-plans] ' . $e->getMessage());
    plans_fail(500, 'server', 'Opération impossible.');
}
