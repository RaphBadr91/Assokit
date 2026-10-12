<?php
/**
 * api/app-cotisations.php — Campagnes de cotisations pour l'ecran natif. JSON, scope org.
 * NE MODIFIE PAS le site.
 */
ob_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes-layout.php';
ob_end_clean();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'auth']);
    exit;
}

// Compte désactivé ou supprimé depuis l'ouverture de la session : même règle que
// _app-write-boot.php (session fermée, 401 'auth' → l'appli revient à la connexion).
try {
    $user = function_exists('current_user') ? current_user() : null;
    $st = $pdo->prepare("SELECT is_active, deleted_at FROM users WHERE id = ? LIMIT 1");
    $st->execute([(int) $_SESSION['user_id']]);
    $live = $st->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('[api/app-cotisations] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'server']);
    exit;
}
$gone = !$live || (int) ($live['is_active'] ?? 1) !== 1
    || (!empty($live['deleted_at']) && strpos((string) $live['deleted_at'], '0000-00-00') !== 0);
if ($gone || !$user) {
    if ($gone) {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) @session_destroy();
    }
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'auth']);
    exit;
}

try {
    $org_id = (int) ($user['org_id'] ?? ($_SESSION['org_id'] ?? 0));

    // Parité cotisations.php : campagnes, encaissements et payeurs réservés à la gestion
    // (admin, coordinateur, fondateur, super-admin). Les autres rôles, suiveur externe compris,
    // reçoivent une liste vide : l'écran et le sélecteur de l'appli restent fonctionnels.
    if (function_exists('ak_platform_flags')) $user = ak_platform_flags($user);
    $role = (string) ($user['role'] ?? '');
    if (!in_array($role, ['admin', 'coordinator'], true) && empty($user['is_founder']) && empty($user['is_super_admin'])) {
        echo json_encode([
            'ok' => true, 'allowed' => false,
            'stats' => ['total' => 0, 'pending' => 0, 'payers' => 0, 'year' => (int) date('Y'), 'active' => 0, 'nb' => 0],
            'campaigns' => [],
            'message' => 'Réservé aux administrateurs et coordinateurs.',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $stmt = $pdo->prepare("
        SELECT c.id, c.name, c.year, c.is_active, c.closes_at,
          (SELECT COUNT(*) FROM cotisation_payments p WHERE p.campaign_id = c.id AND p.status = 'paid') AS count_paid,
          (SELECT COUNT(*) FROM cotisation_payments p WHERE p.campaign_id = c.id AND p.status = 'pending') AS count_pending,
          (SELECT COALESCE(SUM(amount),0) FROM cotisation_payments p WHERE p.campaign_id = c.id AND p.status = 'paid') AS total_paid
        FROM cotisation_campaigns c
        WHERE c.org_id = ? AND c.archived_at IS NULL
        ORDER BY c.is_active DESC, c.year DESC, c.created_at DESC
        LIMIT 100
    ");
    $stmt->execute([$org_id]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // KPI « Encaissé » : MÊME règle que cotisations.php (exercice courant, rattaché à paid_at),
    // et non la somme de toutes les campagnes toutes années (chiffre différent du site).
    $year = (int) date('Y');
    $paid_year = 0.0; $pending_year = 0.0; $payers_year = 0;
    try {
        $ks = $pdo->prepare("SELECT
            COALESCE(SUM(CASE WHEN p.status='paid' AND YEAR(p.paid_at) = ? THEN p.amount ELSE 0 END), 0) AS paid,
            COALESCE(SUM(CASE WHEN p.status='pending' AND YEAR(p.created_at) = ? THEN p.amount ELSE 0 END), 0) AS pending,
            COUNT(DISTINCT CASE WHEN p.status='paid' AND YEAR(p.paid_at) = ? THEN COALESCE(p.adherent_id, CONCAT('e:', p.payer_email)) END) AS payers
            FROM cotisation_payments p WHERE p.org_id = ?");
        $ks->execute([$year, $year, $year, $org_id]);
        if ($kr = $ks->fetch(PDO::FETCH_ASSOC)) {
            $paid_year = (float) $kr['paid']; $pending_year = (float) $kr['pending']; $payers_year = (int) $kr['payers'];
        }
    } catch (Throwable $e) {}

    // Tarifs par campagne (sélecteur « Tarif » du formulaire de paiement natif)
    $tiers_by_camp = [];
    try {
        $ids = array_map(static fn($r) => (int) $r['id'], $rows);
        if ($ids) {
            $in = implode(',', array_fill(0, count($ids), '?'));
            $ts = $pdo->prepare("SELECT id, campaign_id, name, amount FROM cotisation_tiers WHERE campaign_id IN ($in) ORDER BY position ASC, id ASC");
            $ts->execute($ids);
            foreach (($ts->fetchAll(PDO::FETCH_ASSOC) ?: []) as $t) {
                $tiers_by_camp[(int) $t['campaign_id']][] = ['id' => (int) $t['id'], 'name' => (string) $t['name'], 'amount' => (float) $t['amount']];
            }
        }
    } catch (Throwable $e) {}

    $campaigns = [];
    $active = 0;
    foreach ($rows as $r) {
        if (!empty($r['is_active'])) $active++;
        $tp = (float) ($r['total_paid'] ?? 0);
        $campaigns[] = [
            'id'      => (int) $r['id'],
            'name'    => (string) $r['name'],
            'year'    => (int) ($r['year'] ?? 0),
            'active'  => !empty($r['is_active']),
            'paid'    => (int) ($r['count_paid'] ?? 0),
            'pending' => (int) ($r['count_pending'] ?? 0),
            'total'   => $tp,
            'tiers'   => $tiers_by_camp[(int) $r['id']] ?? [],
        ];
    }

    echo json_encode([
        'ok' => true,
        'stats' => ['total' => $paid_year, 'pending' => $pending_year, 'payers' => $payers_year, 'year' => $year, 'active' => $active, 'nb' => count($campaigns)],
        'campaigns' => $campaigns,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('[api/app-cotisations] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'server']);
}
