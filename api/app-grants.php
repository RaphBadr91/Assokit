<?php
/**
 * api/app-grants.php — Subventions (grants) pour l'ecran natif. JSON, scope org.
 * NE MODIFIE PAS le site.
 */
ob_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes-layout.php';
require_once __DIR__ . '/../includes-grants.php';
ob_end_clean();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'auth']);
    exit;
}

$G_META = [
    'draft'     => ['label' => 'Brouillon',   'kind' => 'draft'],
    'submitted' => ['label' => 'Déposé',      'kind' => 'wait'],
    'in_review' => ['label' => 'Instruction', 'kind' => 'wait'],
    'granted'   => ['label' => 'Accordé',     'kind' => 'done'],
    'rejected'  => ['label' => 'Refusé',      'kind' => 'late'],
    'reported'  => ['label' => 'Bilan rendu', 'kind' => 'done'],
    'archived'  => ['label' => 'Archivé',     'kind' => 'off'],
];
$FUNDER = ['etat' => 'État', 'region' => 'Région', 'departement' => 'Département', 'commune' => 'Commune', 'epci' => 'EPCI', 'caf' => 'CAF', 'fondation' => 'Fondation', 'entreprise' => 'Entreprise', 'europe' => 'Europe', 'autre' => 'Autre'];

try {
    $user = function_exists('current_user') ? current_user() : null;
    $org_id = (int) ($user['org_id'] ?? ($_SESSION['org_id'] ?? 0));

    // Administrateur ou coordinateur seulement, comme /subventions : les
    // dossiers portent les montants, les financeurs et leurs contacts.
    // Mêmes clés qu'une liste vide (l'appli lit stats et grants).
    if (!gr_can_manage($user)) {
        echo json_encode([
            'ok' => true, 'allowed' => false,
            'message' => 'Les dossiers de subvention sont réservés aux administrateurs et aux coordinateurs.',
            'stats' => ['requested' => 0, 'granted' => 0, 'pending' => 0, 'nb' => 0],
            'grants' => [],
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $stmt = $pdo->prepare("
        SELECT g.id, g.name, g.funder, g.funder_type, g.amount_requested, g.amount_granted,
               g.status, g.deadline_apply, g.submitted_at, p.name AS project_name
        FROM grants g LEFT JOIN projects p ON p.id = g.project_id
        WHERE g.org_id = ? AND g.archived_at IS NULL AND COALESCE(g.status, '') <> 'archived'
        ORDER BY CASE WHEN g.deadline_apply IS NOT NULL AND g.deadline_apply >= CURDATE() THEN 0 ELSE 1 END,
                 g.deadline_apply ASC, g.created_at DESC
        LIMIT 200
    ");
    $stmt->execute([$org_id]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $grants = [];
    $requested = 0; $granted = 0; $pending = 0;
    foreach ($rows as $r) {
        $status = (string) ($r['status'] ?? 'draft');
        $meta = $G_META[$status] ?? ['label' => ucfirst($status), 'kind' => 'wait'];
        $requested += (float) ($r['amount_requested'] ?? 0);
        // « Bilan rendu » reste une subvention accordée (même règle que le site).
        $is_granted = in_array($status, ['granted', 'reported'], true);
        if ($is_granted) $granted += (float) ($r['amount_granted'] ?? 0);
        if (in_array($status, ['submitted', 'in_review'], true)) $pending++;

        $dl = '';
        if (!empty($r['deadline_apply']) && $r['deadline_apply'] !== '0000-00-00') {
            $ts = strtotime((string) $r['deadline_apply']);
            if ($ts) $dl = date('d/m/Y', $ts);
        }
        $grants[] = [
            'id'         => (int) $r['id'],
            'name'       => (string) $r['name'],
            'funder'     => (string) ($r['funder'] ?? ''),
            'funder_type'=> $FUNDER[$r['funder_type'] ?? ''] ?? '',
            'requested'  => (float) ($r['amount_requested'] ?? 0),
            'granted'    => (float) ($r['amount_granted'] ?? 0),
            'status'     => $status,
            'is_granted' => $is_granted,   // montant à afficher : accordé si vrai, sinon demandé
            'status_label' => $meta['label'],
            'status_kind'  => $meta['kind'],
            'deadline'   => $dl,
            // Jours avant la date limite de dépôt (négatif : dépassée), pour un
            // dossier pas encore déposé ; null sinon (même règle que le site).
            'deadline_days' => gr_days_to_apply($r),
            'project'    => (string) ($r['project_name'] ?? ''),
        ];
    }

    echo json_encode([
        'ok' => true,
        'allowed' => true,
        'stats' => ['requested' => $requested, 'granted' => $granted, 'pending' => $pending, 'nb' => count($grants)],
        'grants' => $grants,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('[api/app-grants] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'server']);
}
