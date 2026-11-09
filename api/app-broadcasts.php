<?php
/**
 * api/app-broadcasts.php — Diffusions email (communication) pour l'ecran natif. JSON, scope org.
 * NE MODIFIE PAS le site.
 */
require_once __DIR__ . '/_app-read-boot.php';   // session, compte actif (sinon 401 'auth'), en-têtes JSON, $user, $uid, $org_id

$B_META = [
    'sent'    => ['label' => 'Envoyée',   'kind' => 'done'],
    'partial' => ['label' => 'Envoi partiel', 'kind' => 'wait'],
    'draft'   => ['label' => 'Brouillon', 'kind' => 'draft'],
    'sending' => ['label' => 'En cours',  'kind' => 'wait'],
    'failed'  => ['label' => 'Échec',     'kind' => 'late'],
];

function bfd($v) {
    if (empty($v) || $v === '0000-00-00 00:00:00') return '';
    $ts = strtotime((string) $v); return $ts ? date('d/m/Y', $ts) : '';
}

try {
    // Même règle que le site (/communication : require_capability('access_marketing')) :
    // sans ce droit, ni les objets des diffusions ni les brouillons ne sont visibles.
    if (!function_exists('can') || !can('access_marketing')) {
        echo json_encode(['ok' => true, 'allowed' => false, 'stats' => ['nb' => 0, 'sent' => 0], 'items' => [],
                          'message' => 'Réservé à l\'équipe communication.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $rows = [];
    try {
        $stmt = $pdo->prepare("
            SELECT b.id, b.subject, b.status, b.sent_at, b.created_at, b.nb_sent, b.nb_failed
            FROM communication_broadcasts b
            WHERE b.org_id = ?
            ORDER BY b.created_at DESC LIMIT 100
        ");
        $stmt->execute([$org_id]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {}

    $items = [];
    $sent = 0;
    foreach ($rows as $r) {
        $status = (string) ($r['status'] ?? 'draft');
        $meta = $B_META[$status] ?? ['label' => ucfirst($status), 'kind' => 'draft'];
        $went_out = $status === 'sent' || $status === 'partial';   // au moins un e-mail parti
        if ($went_out) $sent++;
        $items[] = [
            'id'           => (int) $r['id'],
            'subject'      => (string) ($r['subject'] ?? '(sans objet)'),
            'status_label' => $meta['label'],
            'status_kind'  => $meta['kind'],
            'nb_sent'      => (int) ($r['nb_sent'] ?? 0),
            'nb_failed'    => (int) ($r['nb_failed'] ?? 0),
            'date'         => $went_out ? bfd($r['sent_at'] ?? null) : bfd($r['created_at'] ?? null),
        ];
    }

    // Volume d'e-mails groupés du mois (formule, api/_app-plan.php) : information seulement, l'envoi se
    // fait sur le site, qui applique le quota (ak_email_quota_block). Jamais de prix ni de « Passer à… ».
    $email_quota = null;
    try {
        require_once __DIR__ . '/_app-plan.php';
        if (app_plan_load() && app_plan_live($pdo) && function_exists('ak_get_current_plan') && function_exists('ak_emails_sent_month')) {
            $plan = ak_get_current_plan($pdo, $org_id);
            $lim = ($plan && array_key_exists('limit_emails_per_month', $plan) && $plan['limit_emails_per_month'] !== null && $plan['limit_emails_per_month'] !== '')
                 ? (int) $plan['limit_emails_per_month'] : null;
            $diff_on = !$plan || !array_key_exists('feature_email_diffusion', $plan) || !empty($plan['feature_email_diffusion']);
            if ($lim !== null && $lim > 0 && $diff_on) {
                $used = ak_emails_sent_month($pdo, $org_id);
                $email_quota = [
                    'used'  => $used,
                    'limit' => $lim,
                    'full'  => $used >= $lim,
                    'text'  => 'E-mails envoyés ce mois-ci : ' . app_plan_int($used) . ' sur ' . app_plan_int($lim),
                    'note'  => $used >= $lim
                        ? 'Le volume d’e-mails de ce mois est atteint. Il sera de nouveau disponible le ' . app_plan_next_month() . '. Les factures et devis envoyés un par un ne sont pas concernés.'
                        : 'Sont comptés : diffusions, invitations, convocations d’AG et relances. Les factures et devis envoyés un par un ne sont jamais comptés.',
                ];
            } elseif (!$diff_on) {
                $email_quota = ['used' => 0, 'limit' => 0, 'full' => true,
                                'text' => app_plan_readonly($pdo, $org_id, $user) ? app_plan_readonly_message() : 'Les envois groupés d’e-mails ne sont pas inclus dans votre formule.',
                                'note' => ''];
            }
        }
    } catch (Throwable $e) { $email_quota = null; }

    echo json_encode(['ok' => true, 'stats' => ['nb' => count($items), 'sent' => $sent], 'items' => $items, 'email_quota' => $email_quota], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('[api/app-broadcasts] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'server']);
}
