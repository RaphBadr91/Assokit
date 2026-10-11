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
require_once __DIR__ . '/_app-founder-plan.php';
$is_sa = app_is_founder($pdo, $user) || !empty($user['is_super_admin']) || (($user['role'] ?? '') === 'super_admin');
if (!$is_sa) plans_fail(403, 'forbidden');

// Champs entiers-nullable (quotas) et booléens (features)
$INT_FIELDS = ['limit_adherents', 'limit_invoices_total', 'limit_quotes_total', 'limit_contacts', 'limit_users',
    'limit_ai_text_per_month', 'limit_ai_image_per_month', 'limit_emails_per_month'];
$BOOL_FIELDS = ['feature_recurring_invoices', 'feature_signature_quotes', 'feature_email_diffusion',
    'feature_advanced_stats', 'feature_priority_support', 'feature_custom_domain', 'feature_dedicated_support'];

// Plans dont dépendent l'inscription (essai) et la fin d'essai (plan gratuit) : slug figé, suppression refusée
const PLANS_SYSTEM = ['pro-essai', 'demarrage'];

/**
 * Adoption : nombre d'associations par plan, d'après leur abonnement COURANT (même ligne
 * qu'ak_org_plan_state) ; sans abonnement, d'après l'ancien libellé organizations.plan.
 * @return array [plan_id => nb]
 */
function plans_adoption(PDO $pdo, array $rows): array {
    $by_id = [];
    try {
        $q = $pdo->query("
            SELECT s.plan_id, COUNT(*) AS nb
            FROM organizations o
            INNER JOIN asso_subscriptions s ON s.id = (
                SELECT s2.id FROM asso_subscriptions s2 INNER JOIN asso_plans p2 ON p2.id = s2.plan_id
                WHERE s2.org_id = o.id ORDER BY (s2.status = 'active') DESC, s2.id DESC LIMIT 1)
            WHERE o.deleted_at IS NULL
            GROUP BY s.plan_id");
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $a) $by_id[(int) $a['plan_id']] = (int) $a['nb'];
        $slug_id = [];
        foreach ($rows as $p) $slug_id[(string) ($p['slug'] ?? '')] = (int) $p['id'];
        $q = $pdo->query("
            SELECT o.plan, COUNT(*) AS nb FROM organizations o
            WHERE o.deleted_at IS NULL
              AND NOT EXISTS (SELECT 1 FROM asso_subscriptions s INNER JOIN asso_plans p ON p.id = s.plan_id WHERE s.org_id = o.id)
            GROUP BY o.plan");
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $a) {
            $pid = $slug_id[(string) $a['plan']] ?? 0;
            if ($pid) $by_id[$pid] = ($by_id[$pid] ?? 0) + (int) $a['nb'];
        }
    } catch (Throwable $e) {
        // Pas de table d'abonnement lisible : ancien comptage par organizations.plan
        try {
            $slug_id = [];
            foreach ($rows as $p) $slug_id[(string) ($p['slug'] ?? '')] = (int) $p['id'];
            foreach ($pdo->query("SELECT plan, COUNT(*) AS nb FROM organizations WHERE deleted_at IS NULL GROUP BY plan")->fetchAll(PDO::FETCH_ASSOC) as $a) {
                $pid = $slug_id[(string) $a['plan']] ?? 0;
                if ($pid) $by_id[$pid] = ($by_id[$pid] ?? 0) + (int) $a['nb'];
            }
        } catch (Throwable $e2) {}
    }
    return $by_id;
}

/** Nombre d'associations (non supprimées) et de lignes d'abonnement qui utilisent ce plan. */
function plans_usage(PDO $pdo, int $pid, string $slug): array {
    $orgs = 0; $subs = 0;
    // Associations liées : ancien libellé organizations.plan OU une ligne d'abonnement sur ce plan
    try {
        $q = $pdo->prepare("SELECT COUNT(*) FROM organizations o WHERE o.deleted_at IS NULL
                            AND (o.plan = ? OR EXISTS (SELECT 1 FROM asso_subscriptions s WHERE s.org_id = o.id AND s.plan_id = ?))");
        $q->execute([$slug, $pid]); $orgs = (int) $q->fetchColumn();
    } catch (Throwable $e) {
        try { $q = $pdo->prepare("SELECT COUNT(*) FROM organizations WHERE plan = ? AND deleted_at IS NULL"); $q->execute([$slug]); $orgs = (int) $q->fetchColumn(); } catch (Throwable $e2) {}
    }
    try { $q = $pdo->prepare("SELECT COUNT(*) FROM asso_subscriptions WHERE plan_id = ?"); $q->execute([$pid]); $subs = (int) $q->fetchColumn(); } catch (Throwable $e) {}
    return [$orgs, $subs];
}

function plans_list(PDO $pdo, array $INT_FIELDS, array $BOOL_FIELDS): array {
    $order = isset(ak_db_columns($pdo, 'asso_plans')['display_order']) ? "display_order ASC, price_cents ASC" : "price_cents ASC";
    $rows = $pdo->query("SELECT * FROM asso_plans ORDER BY $order")->fetchAll(PDO::FETCH_ASSOC);
    $adoption = plans_adoption($pdo, $rows);
    $out = [];
    foreach ($rows as $p) {
        $item = [
            'id'            => (int) $p['id'],
            'slug'          => (string) ($p['slug'] ?? ''),
            'name'          => (string) ($p['name'] ?? ''),
            'label'         => ak_plan_label($p['slug'] ?? '', $p['name'] ?? null),
            'is_system'     => in_array((string) ($p['slug'] ?? ''), PLANS_SYSTEM, true),
            'tagline'       => (string) ($p['tagline'] ?? ''),
            'price_eur'     => round(((int) ($p['price_cents'] ?? 0)) / 100, 2),
            'price_label'   => (string) ($p['price_label'] ?? ''),
            'is_custom_quote' => !empty($p['is_custom_quote']),
            'is_visible'    => !empty($p['is_visible']),
            'is_featured'   => !empty($p['is_featured']),
            'is_trial'      => !empty($p['is_trial']),
            'display_order' => (int) ($p['display_order'] ?? 0),
            'adoption'      => $adoption[(int) $p['id']] ?? 0,
        ];
        foreach ($INT_FIELDS as $f) $item[$f] = (!isset($p[$f]) || $p[$f] === '') ? null : (int) $p[$f];
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
        // Refuse la suppression d'un plan système, ou d'un plan encore référencé
        // (organizations.plan OU une ligne d'abonnement : sinon l'association perdrait son plan)
        $sq = $pdo->prepare("SELECT slug FROM asso_plans WHERE id = ?"); $sq->execute([$pid]);
        $slug = (string) $sq->fetchColumn();
        if (in_array($slug, PLANS_SYSTEM, true)) plans_fail(409, 'system', 'Plan système (essai ou plan gratuit) : il ne peut pas être supprimé. Masquez-le si besoin.');
        [$used_orgs, $used_subs] = plans_usage($pdo, $pid, $slug);
        if ($used_orgs > 0 || $used_subs > 0) {
            plans_fail(409, 'in_use', $used_orgs > 0
                ? "Ce plan est utilisé par $used_orgs organisation(s)."
                : "Ce plan figure dans $used_subs abonnement(s) (historique compris) : masquez-le plutôt.");
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
        // N'écrit que les colonnes qui existent (schéma de prod non versionné)
        $pcols = ak_db_columns($pdo, 'asso_plans');
        if ($pcols) $data = array_intersect_key($data, $pcols);

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
            $oq = $pdo->prepare("SELECT slug FROM asso_plans WHERE id = ?"); $oq->execute([$pid]);
            $old_slug = $oq->fetchColumn();
            if ($old_slug === false) plans_fail(404, 'not_found', 'Plan introuvable.');
            $old_slug = (string) $old_slug;
            if ($slug !== $old_slug) {
                // L'inscription cherche « pro-essai », la fin d'essai « demarrage » : ces slugs ne bougent pas
                if (in_array($old_slug, PLANS_SYSTEM, true)) plans_fail(409, 'system', 'Le slug d’un plan système (essai ou plan gratuit) ne peut pas changer.');
                $c = $pdo->prepare("SELECT COUNT(*) FROM asso_plans WHERE slug = ? AND id <> ?");
                $c->execute([$slug, $pid]);
                if ((int) $c->fetchColumn() > 0) plans_fail(409, 'slug_used', 'Ce slug existe déjà.');
            }
            $set = implode(', ', array_map(fn($c) => "$c = :$c", array_keys($data)));
            $pdo->beginTransaction();
            $st = $pdo->prepare("UPDATE asso_plans SET $set WHERE id = :id");
            foreach ($data as $k => $v) $st->bindValue(':' . $k, $v, ($v === null ? PDO::PARAM_NULL : (is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR)));
            $st->bindValue(':id', $pid, PDO::PARAM_INT);
            $st->execute();
            // organizations.plan (ancien libellé) suit le slug, comme le fait ak_org_set_plan()
            if ($slug !== $old_slug) $pdo->prepare("UPDATE organizations SET plan = ? WHERE plan = ?")->execute([$slug, $old_slug]);
            $pdo->commit();
            echo json_encode(['ok' => true, 'plan_id' => $pid, 'plans' => plans_list($pdo, $INT_FIELDS, $BOOL_FIELDS)], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }

    plans_fail(400, 'action', 'Action inconnue.');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('[app-founder-plans] ' . $e->getMessage());
    plans_fail(500, 'server', 'Opération impossible.');
}
