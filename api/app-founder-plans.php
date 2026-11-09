<?php
/**
 * api/app-founder-plans.php — Gestion des plans tarifaires (Fondateur, natif).
 * GET            → liste des plans (tous champs + adoption).
 * POST create    → crée un plan.
 * POST update    → met à jour un plan.
 * POST delete    → supprime un plan (si aucune org ne l'utilise).
 * POST toggle    → bascule la visibilité.
 * Réservé au Fondateur, comme /fondateur-plans (sa-permissions.php : can_manage_billing_config).
 * Mêmes règles que fondateur-plans.php (plan-catalog-helpers.php). NE MODIFIE PAS le site.
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
// Compte désactivé ou supprimé depuis l'ouverture de la session : session fermée, 401
if (!app_session_account_alive($pdo)) plans_fail(401, 'auth');
require_once __DIR__ . '/_app-founder-plan.php';
// Plans et prix : Fondateur uniquement (lecture comme écriture), un super-admin simple n'y a pas accès sur le site
if (!app_is_founder($pdo, $user)) plans_fail(403, 'forbidden');

// Champs entiers-nullable (limites) et booléens (fonctions) : listes de la grille (plan-helpers.php,
// ak_plan_limit_keys / ak_plan_feature_keys, les mêmes que /fondateur-plans), sinon
// les anciennes listes. Vide = illimité, 0 = bloqué.
require_once __DIR__ . '/_app-plan.php';
app_plan_load();
$LIMIT_LABELS = function_exists('ak_plan_limit_keys') ? ak_plan_limit_keys() : [
    'limit_adherents' => 'Adhérents', 'limit_users' => 'Utilisateurs', 'limit_invoices_total' => 'Factures (total)',
    'limit_quotes_total' => 'Devis (total)', 'limit_contacts' => 'Contacts', 'limit_ai_text_per_month' => 'IA texte par mois',
    'limit_ai_image_per_month' => 'IA image par mois', 'limit_emails_per_month' => 'E-mails par mois'];
$FEATURE_LABELS = function_exists('ak_plan_feature_keys') ? ak_plan_feature_keys() : [
    'feature_recurring_invoices' => 'Factures récurrentes', 'feature_signature_quotes' => 'Devis signés',
    'feature_email_diffusion' => 'Diffusion e-mail', 'feature_advanced_stats' => 'Statistiques avancées',
    'feature_priority_support' => 'Support prioritaire', 'feature_custom_domain' => 'Domaine personnel',
    'feature_dedicated_support' => 'Support dédié'];
$INT_FIELDS  = array_keys($LIMIT_LABELS);
$BOOL_FIELDS = array_keys($FEATURE_LABELS);
// Nouvelles fonctions de la grille : 1 par défaut (colonne absente ou nouvelle ligne = comme avant)
$BOOL_DEFAULT_ON = function_exists('ak_plan_default_on_keys') ? ak_plan_default_on_keys() : [];

// Règles du catalogue, communes avec /fondateur-plans : slugs de la grille figés et jamais supprimés
// (AK_PLAN_FIXED_SLUGS), offre fondateur, essai et accès limité toujours masqués (AK_PLAN_NEVER_VISIBLE)
require_once __DIR__ . '/../plan-catalog-helpers.php';
// Plans dont dépendent l'inscription (essai), la fin d'essai (accès limité) et l'offre fondateur
// (cli-rattacher-fondateur.php, migration de la grille) : marqués « système » dans l'éditeur de l'appli
const PLANS_SYSTEM = AK_PLAN_NEVER_VISIBLE;

/** Rôle d'une ligne système, affiché dans l'éditeur de l'appli. */
function plans_system_hint(string $slug): string {
    switch ($slug) {
        case 'pro-essai': return 'Formule d’essai attribuée à l’inscription.';
        case 'demarrage': return 'Accès limité (consultation et export) attribué à la fin d’un essai non converti, à la résiliation ou en cas d’impayé.';
        case 'fondateur': return 'Offre fondateur : formule masquée des clients de la première heure.';
    }
    return ak_plan_slug_fixed($slug) ? 'Formule de la grille : son slug ne peut pas changer.' : '';
}

/**
 * Adoption : nombre d'associations par plan, d'après leur formule COURANTE (sa_org_plan_states, même
 * vérité que /fondateur-plans, la fiche association et l'appli, lecture seule) ; sans abonnement,
 * d'après l'ancien libellé organizations.plan.
 * @return array [plan_id => nb]
 */
function plans_adoption(PDO $pdo, array $rows): array {
    $by_id = [];
    $slug_id = [];
    foreach ($rows as $p) $slug_id[(string) ($p['slug'] ?? '')] = (int) $p['id'];
    $del = isset(ak_db_columns($pdo, 'organizations')['deleted_at']) ? " WHERE deleted_at IS NULL" : "";
    try {
        if (!function_exists('sa_org_plan_states') && is_file(__DIR__ . '/../sa-plan-state-helpers.php')) require_once __DIR__ . '/../sa-plan-state-helpers.php';
        if (!function_exists('sa_org_plan_states')) throw new RuntimeException('sa_org_plan_states');
        $orgs = $pdo->query("SELECT id, plan FROM organizations$del")->fetchAll(PDO::FETCH_KEY_PAIR);
        foreach (sa_org_plan_states($pdo, array_keys($orgs)) as $oid => $ps) {
            $pid = (int) ($ps['plan_id'] ?? 0);
            if (!$pid) $pid = $slug_id[(string) ($orgs[$oid] ?? '')] ?? 0;
            if ($pid) $by_id[$pid] = ($by_id[$pid] ?? 0) + 1;
        }
    } catch (Throwable $e) {
        // États illisibles : ancien comptage par organizations.plan
        $by_id = [];
        try {
            foreach ($pdo->query("SELECT plan, COUNT(*) AS nb FROM organizations$del GROUP BY plan")->fetchAll(PDO::FETCH_ASSOC) as $a) {
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
            'slug_fixed'    => ak_plan_slug_fixed((string) ($p['slug'] ?? '')),
            'never_visible' => ak_plan_never_visible((string) ($p['slug'] ?? '')),
            'system_hint'   => plans_system_hint((string) ($p['slug'] ?? '')),
            'grille'        => !empty($p['grille_version']),
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
        // Colonne absente (schéma de prod non versionné) : limite vide, fonction de la grille active
        foreach ($INT_FIELDS as $f) $item[$f] = (!isset($p[$f]) || $p[$f] === '') ? null : (int) $p[$f];
        foreach ($BOOL_FIELDS as $f) $item[$f] = array_key_exists($f, $p) ? !empty($p[$f]) : in_array($f, $GLOBALS['BOOL_DEFAULT_ON'], true);
        $item['bonus_ai_first_week'] = (int) ($p['bonus_ai_first_week'] ?? 0);
        $out[] = $item;
    }
    return $out;
}

// --- GET : liste ---
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    $fields = ['limits' => [], 'features' => []];
    foreach ($LIMIT_LABELS as $k => $l) $fields['limits'][] = ['key' => $k, 'label' => $l];
    // default : valeur d'une nouvelle formule (celle de la colonne en base : 1 pour les fonctions de la grille)
    foreach ($FEATURE_LABELS as $k => $l) $fields['features'][] = ['key' => $k, 'label' => $l,
        'default' => in_array($k, $BOOL_DEFAULT_ON, true) || $k === 'feature_email_diffusion'];
    try { echo json_encode(['ok' => true, 'plans' => plans_list($pdo, $INT_FIELDS, $BOOL_FIELDS), 'fields' => $fields], JSON_UNESCAPED_UNICODE); }
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
        $sq = $pdo->prepare("SELECT * FROM asso_plans WHERE id = ?"); $sq->execute([$pid]);
        $old = $sq->fetch(PDO::FETCH_ASSOC);
        if (!$old) plans_fail(404, 'not_found', 'Plan introuvable.');
        $slug = (string) ($old['slug'] ?? '');
        // Les 7 lignes de la grille (Essentiel, Pro, Équipe, Sur-mesure, essai, accès limité, offre fondateur)
        $none = [];
        try { ak_plan_catalog_guard($pdo, $old, $none, 'delete'); }
        catch (AkPlanCatalogException $e) { plans_fail(409, 'system', $e->getMessage()); }
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
        $sq = $pdo->prepare("SELECT slug FROM asso_plans WHERE id = ?"); $sq->execute([$pid]);
        $slug = $sq->fetchColumn();
        if ($slug === false) plans_fail(404, 'not_found', 'Plan introuvable.');
        // Offre fondateur, essai, accès limité : jamais proposés à la souscription
        if (ak_plan_never_visible((string) $slug)) {
            // Déjà masquée : refus. Visible par erreur (base modifiée à la main) : on la masque.
            $vq = $pdo->prepare("SELECT COALESCE(is_visible,0) FROM asso_plans WHERE id = ?"); $vq->execute([$pid]);
            if ((int) $vq->fetchColumn() === 0) plans_fail(409, 'never_visible', 'Cette formule reste toujours masquée : l’offre fondateur, l’essai et l’accès limité ne se souscrivent jamais.');
            $pdo->prepare("UPDATE asso_plans SET is_visible = 0 WHERE id = ?")->execute([$pid]);
        } else {
            $pdo->prepare("UPDATE asso_plans SET is_visible = 1 - COALESCE(is_visible,0) WHERE id = ?")->execute([$pid]);
        }
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
            // Champ non envoyé (ancienne version de l'appli) : la valeur en base ne bouge pas
            if (!array_key_exists($f, $input)) continue;
            $v = $input[$f];
            $data[$f] = ($v === '' || $v === null) ? null : max(0, (int) $v);
        }
        foreach ($BOOL_FIELDS as $f) {
            if (array_key_exists($f, $input)) $data[$f] = !empty($input[$f]) ? 1 : 0;
            elseif ($act === 'create') $data[$f] = (in_array($f, $BOOL_DEFAULT_ON, true) || $f === 'feature_email_diffusion') ? 1 : 0;
        }
        if (array_key_exists('bonus_ai_first_week', $input)) $data['bonus_ai_first_week'] = max(0, (int) $input['bonus_ai_first_week']);
        // grille_version n'est jamais écrite ici : une relance de la migration n'écrase pas ces réglages
        // N'écrit que les colonnes qui existent (schéma de prod non versionné)
        $pcols = ak_db_columns($pdo, 'asso_plans');
        if ($pcols) $data = array_intersect_key($data, $pcols);

        if ($act === 'create') {
            // Unicité du slug ; offre fondateur, essai, accès limité toujours masqués
            try { ak_plan_catalog_guard($pdo, null, $data, 'create'); }
            catch (AkPlanCatalogException $e) { plans_fail(409, ak_plan_slug_fixed($slug) ? 'system' : 'slug_used', $e->getMessage()); }
            if ($pcols) $data = array_intersect_key($data, $pcols);
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
            $oq = $pdo->prepare("SELECT * FROM asso_plans WHERE id = ?"); $oq->execute([$pid]);
            $old = $oq->fetch(PDO::FETCH_ASSOC);
            if (!$old) plans_fail(404, 'not_found', 'Plan introuvable.');
            $old_slug = (string) ($old['slug'] ?? '');
            // L'inscription cherche « pro-essai », la fin d'essai « demarrage », la migration et les liens
            // ?plan= les slugs de la grille : ces slugs ne bougent pas ; un slug reste unique
            try { ak_plan_catalog_guard($pdo, $old, $data, 'update'); }
            catch (AkPlanCatalogException $e) { plans_fail(409, $slug === $old_slug ? 'locked' : (ak_plan_slug_fixed($old_slug) || ak_plan_slug_fixed($slug) ? 'system' : 'slug_used'), $e->getMessage()); }
            if ($pcols) $data = array_intersect_key($data, $pcols);
            $warning = ak_plan_catalog_price_warning($old, $data);
            $set = implode(', ', array_map(fn($c) => "$c = :$c", array_keys($data)));
            $pdo->beginTransaction();
            $st = $pdo->prepare("UPDATE asso_plans SET $set WHERE id = :id");
            foreach ($data as $k => $v) $st->bindValue(':' . $k, $v, ($v === null ? PDO::PARAM_NULL : (is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR)));
            $st->bindValue(':id', $pid, PDO::PARAM_INT);
            $st->execute();
            // organizations.plan (ancien libellé) suit le slug, comme le fait ak_org_set_plan()
            ak_plan_slug_rename($pdo, $old_slug, $slug);
            $pdo->commit();
            $out = ['ok' => true, 'plan_id' => $pid, 'plans' => plans_list($pdo, $INT_FIELDS, $BOOL_FIELDS)];
            // Prix changé sur une formule qui a déjà un prix Stripe : à relancer dans Configuration Stripe
            if ($warning !== '') $out['warning'] = $warning;
            echo json_encode($out, JSON_UNESCAPED_UNICODE);
            exit;
        }
    }

    plans_fail(400, 'action', 'Action inconnue.');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('[app-founder-plans] ' . $e->getMessage());
    plans_fail(500, 'server', 'Opération impossible.');
}
