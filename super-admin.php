<?php
/**
 * super-admin.php — Dashboard v3 (Fondateur ULTIME)
 * ===================================================
 * Affiche :
 *   - Header + notifications si Fondateur
 *   - KPIs standards (MRR, assos, users, IA)
 *   - Alertes (impayes, essais expirants, assos en attente)
 *   - Si Fondateur : bloc "🏗️ Fondateur" + liste fondateurs
 *   - Dernieres assos creees
 *   - Si Fondateur : stats avancees (emails/SMS semaine/mois)
 *   - Quick actions adaptees au role
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes-layout.php';
require_once __DIR__ . '/superadmin-layout.php';
require_once __DIR__ . '/sa-permissions.php';
require_login();
$user = sa_require_super_admin();
$ctx = sa_get_permissions_context();

// =====================================================================
// KPIs communs
// =====================================================================
$nb_orgs_total = (int) $pdo->query("SELECT COUNT(*) FROM organizations WHERE deleted_at IS NULL")->fetchColumn();
$nb_orgs_active = (int) $pdo->query("SELECT COUNT(*) FROM organizations WHERE status = 'active' AND deleted_at IS NULL")->fetchColumn();
$nb_orgs_trial = (int) $pdo->query("SELECT COUNT(*) FROM organizations WHERE status = 'trial' AND deleted_at IS NULL")->fetchColumn();
$nb_orgs_suspended = (int) $pdo->query("SELECT COUNT(*) FROM organizations WHERE status = 'suspended' AND deleted_at IS NULL")->fetchColumn();

$nb_orgs_pending = 0;
try {
    $nb_orgs_pending = (int) $pdo->query("SELECT COUNT(*) FROM organizations WHERE validation_status = 'pending_founder' AND deleted_at IS NULL")->fetchColumn();
} catch (Throwable $e) {}

$nb_users = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role NOT IN ('super_admin') AND is_active = 1 AND deleted_at IS NULL")->fetchColumn();
$nb_super_admins = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE (role = 'super_admin' OR is_super_admin = 1) AND is_active = 1 AND deleted_at IS NULL")->fetchColumn();
$nb_founders = 0;
try { $nb_founders = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE is_founder = 1 AND is_active = 1 AND deleted_at IS NULL")->fetchColumn(); } catch (Throwable $e) {}

// MRR — calcule sur la source de verite Stripe (asso_subscriptions + asso_plans),
// et non sur la table legacy `subscriptions` (jamais repassee en 'active' lors d'un paiement Stripe).
// On ne compte que le DERNIER abonnement actif non-essai de chaque org (anti double-comptage).
$mrr = 0.00;
try {
    $stmt = $pdo->query("
        SELECT COALESCE(SUM(p.price_cents), 0) / 100 AS mrr
        FROM asso_subscriptions s
        INNER JOIN asso_plans p ON p.id = s.plan_id
        WHERE s.status = 'active'
          AND p.is_trial = 0
          AND s.id = (SELECT MAX(s2.id) FROM asso_subscriptions s2 WHERE s2.org_id = s.org_id)
    ");
    $mrr = (float) $stmt->fetchColumn();
} catch (Throwable $e) {}

// Impayes
$nb_unpaid = 0;
$total_unpaid = 0.00;
try {
    $row = $pdo->query("SELECT COUNT(*) AS nb, COALESCE(SUM(amount_ttc), 0) AS total FROM subscription_invoices WHERE status IN ('sent','overdue')")->fetch(PDO::FETCH_ASSOC);
    $nb_unpaid = (int) $row['nb'];
    $total_unpaid = (float) $row['total'];
} catch (Throwable $e) {}

// IA
$ia_stats = ['nb' => 0, 'cost' => 0.00];
try {
    $stmt = $pdo->query("SELECT COUNT(*) AS nb, COALESCE(SUM(ai_cost_euros), 0) AS cost FROM communication_campaigns WHERE ai_generated = 1");
    $ia_stats = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

// Dernieres assos
$stmt = $pdo->query("
    SELECT o.id, o.name, o.status, o.plan, o.created_at,
           COALESCE(o.validation_status, 'validated') AS validation_status,
           (SELECT COUNT(*) FROM users WHERE org_id = o.id AND deleted_at IS NULL AND is_active = 1) AS nb_users
    FROM organizations o
    WHERE o.deleted_at IS NULL
    ORDER BY o.created_at DESC
    LIMIT 5
");
$latest_orgs = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Essais expirants (7 jours)
$expiring = [];
try {
    $stmt = $pdo->query("
        SELECT id, name, trial_ends_at,
               DATEDIFF(trial_ends_at, CURDATE()) AS days_left
        FROM organizations
        WHERE status = 'trial' AND trial_ends_at IS NOT NULL
          AND trial_ends_at BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)
        ORDER BY trial_ends_at ASC
    ");
    $expiring = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

// Notifs non lues (Fondateur)
$nb_unread_notifs = 0;
if ($ctx['is_founder']) {
    $nb_unread_notifs = sa_count_unread_notifications((int) $user['id']);
}

// =====================================================================
// STATS AVANCEES (Fondateur uniquement)
// =====================================================================
$advanced_stats = null;
if ($ctx['is_founder']) {
    $advanced_stats = [
        'emails' => ['week' => 0, 'month' => 0, 'quarter' => 0, 'year' => 0],
        'sms' => ['week' => 0, 'month' => 0, 'quarter' => 0, 'year' => 0],
    ];
    try {
        // Emails
        $row = $pdo->query("
            SELECT
                SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 ELSE 0 END) AS week,
                SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 1 MONTH) THEN 1 ELSE 0 END) AS month,
                SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 3 MONTH) THEN 1 ELSE 0 END) AS quarter,
                SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 1 YEAR) THEN 1 ELSE 0 END) AS year
            FROM email_log
            WHERE status = 'sent'
        ")->fetch(PDO::FETCH_ASSOC);
        $advanced_stats['emails'] = [
            'week' => (int) $row['week'],
            'month' => (int) $row['month'],
            'quarter' => (int) $row['quarter'],
            'year' => (int) $row['year'],
        ];
    } catch (Throwable $e) {}
    try {
        $row = $pdo->query("
            SELECT
                SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 ELSE 0 END) AS week,
                SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 1 MONTH) THEN 1 ELSE 0 END) AS month,
                SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 3 MONTH) THEN 1 ELSE 0 END) AS quarter,
                SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 1 YEAR) THEN 1 ELSE 0 END) AS year
            FROM sms_log
            WHERE status IN ('sent','delivered')
        ")->fetch(PDO::FETCH_ASSOC);
        $advanced_stats['sms'] = [
            'week' => (int) $row['week'],
            'month' => (int) $row['month'],
            'quarter' => (int) $row['quarter'],
            'year' => (int) $row['year'],
        ];
    } catch (Throwable $e) {}
}

// Fondateurs (carte dashboard)
$founders = [];
try {
    $stmt = $pdo->query("
        SELECT id, first_name, last_name, email, last_login_at, created_at
        FROM users
        WHERE is_founder = 1 AND is_active = 1
        ORDER BY created_at ASC
    ");
    $founders = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

// =====================================================================
// Courbe de croissance (12 derniers mois) — données réelles
//   - assos : nombre d'organisations existantes à la fin de chaque mois
//   - mrr   : MRR (TTC) reconstitué = abonnements actifs à la fin du mois
// =====================================================================
$growth_labels = [];
$growth_assos = [];
$growth_mrr = [];
try {
    $mois_fr = ['Jan', 'Fév', 'Mar', 'Avr', 'Mai', 'Juin', 'Juil', 'Aoû', 'Sep', 'Oct', 'Nov', 'Déc'];
    $q_assos = $pdo->prepare("
        SELECT COUNT(*) FROM organizations
        WHERE created_at <= :end AND (deleted_at IS NULL OR deleted_at > :end)
    ");
    // Reconstitution MRR historique sur asso_subscriptions (source Stripe), essais exclus,
    // pour rester coherent avec la carte MRR ci-dessus.
    $q_mrr = $pdo->prepare("
        SELECT COALESCE(SUM(p.price_cents), 0) / 100
        FROM asso_subscriptions s
        INNER JOIN asso_plans p ON p.id = s.plan_id
        WHERE (s.started_at IS NULL OR s.started_at <= :end)
          AND (s.cancelled_at IS NULL OR s.cancelled_at > :end)
          AND s.status <> 'cancelled'
          AND p.is_trial = 0
    ");
    for ($i = 11; $i >= 0; $i--) {
        $end = date('Y-m-t 23:59:59', strtotime("first day of -$i month"));
        $m = (int) date('n', strtotime("first day of -$i month"));
        $growth_labels[] = $mois_fr[$m - 1];
        $q_assos->execute([':end' => $end]);
        $growth_assos[] = (int) $q_assos->fetchColumn();
        $q_mrr->execute([':end' => $end]);
        $growth_mrr[] = round((float) $q_mrr->fetchColumn(), 2);
    }
} catch (Throwable $e) {
    $growth_labels = []; $growth_assos = []; $growth_mrr = [];
}

sa_render_head('Dashboard');
sa_render_sidebar('dashboard');
?>


<?php
// ── Construction de la liste "Signal — à traiter" à partir des données réelles ──
$fc_signals = [];
if ($ctx['is_founder'] && $nb_orgs_pending > 0) {
    $fc_signals[] = [
        'tone' => 'amber', 'ic' => '🏗️',
        't' => $nb_orgs_pending . ' validation' . ($nb_orgs_pending > 1 ? 's' : '') . ' en attente',
        'd' => 'Association' . ($nb_orgs_pending > 1 ? 's' : '') . ' créée' . ($nb_orgs_pending > 1 ? 's' : '') . ' par un Super Admin',
        'go' => 'Valider →', 'url' => '/super-admin/associations?filter=pending',
    ];
}
if ($nb_unpaid > 0) {
    $fc_signals[] = [
        'tone' => 'red', 'ic' => '💰',
        't' => $nb_unpaid . ' facture' . ($nb_unpaid > 1 ? 's' : '') . ' impayée' . ($nb_unpaid > 1 ? 's' : ''),
        'd' => 'Total ' . number_format($total_unpaid, 2, ',', ' ') . ' € · à relancer',
        'go' => 'Relancer →', 'url' => '/super-admin/abonnements?filter=unpaid',
    ];
}
foreach ($expiring as $e) {
    $dl = (int) $e['days_left'];
    $fc_signals[] = [
        'tone' => 'violet', 'ic' => '⏱',
        't' => 'Essai — ' . $e['name'],
        'd' => 'Expire dans ' . $dl . ' jour' . ($dl > 1 ? 's' : ''),
        'go' => 'Convertir →', 'url' => '/super-admin/associations?id=' . (int) $e['id'],
    ];
}
?>

<style>
/* ============ Cockpit Fondateur — surcouche (scopée .fc) ============ */
.fc{ --fc-gold:#FCD34D; --fc-gold-2:#F59E0B; --fc-gold-ink:#3A2A08; --fc-gold-soft:rgba(245,158,11,.12);
  --fc-green:#34D399; --fc-green-2:#10B981; --fc-green-soft:rgba(16,185,129,.12);
  --fc-violet:#A78BFA; --fc-violet-2:#8B5CF6; --fc-violet-soft:rgba(139,92,246,.14);
  --fc-blue:#60A5FA; --fc-blue-soft:rgba(96,165,250,.12);
  --fc-red:#F87171; --fc-red-soft:rgba(248,113,113,.13); --fc-amber:#FBBF24; --fc-amber-soft:rgba(251,191,36,.12);
  --fc-panel:linear-gradient(180deg,rgba(22,33,28,.55),rgba(14,21,17,.5)); --fc-panel-2:#16211C; --fc-panel-3:#1B2822;
  --fc-line:rgba(255,255,255,.075); --fc-line-2:rgba(255,255,255,.045);
  --fc-ink:#EAF2EE; --fc-ink-2:#9DB1A8; --fc-ink-3:#7C8F87; --fc-ink-4:#5A6A62;
  --fc-r:18px; --fc-r-sm:13px; --fc-shadow:0 2px 8px rgba(0,0,0,.35),0 24px 50px -18px rgba(0,0,0,.65);
}
.fc .num{font-variant-numeric:tabular-nums}
.fc svg{display:block}
.fc .panel{background:var(--fc-panel);border:1px solid var(--fc-line);border-radius:var(--fc-r);box-shadow:var(--fc-shadow);backdrop-filter:blur(14px)}

/* command bar */
.fc-cmd{display:flex;align-items:flex-start;justify-content:space-between;gap:20px;flex-wrap:wrap;margin-bottom:22px}
.fc-hello{font-size:28px;font-weight:800;letter-spacing:-.03em;display:flex;align-items:center;gap:12px;flex-wrap:wrap;color:var(--fc-ink)}
.fc-seal{font-size:11.5px;font-weight:800;letter-spacing:.05em;padding:5px 12px;border-radius:999px;background:linear-gradient(135deg,#FCD34D,#F59E0B);color:var(--fc-gold-ink);box-shadow:0 8px 18px -6px rgba(245,158,11,.55);display:inline-flex;gap:6px;align-items:center}
.fc-seal.violet{background:var(--fc-violet-soft);color:#C4B5FD;box-shadow:none}
.fc-hello-sub{color:var(--fc-ink-2);font-size:13.5px;margin-top:9px;display:flex;align-items:center;gap:8px}
.fc-hello-sub .dot{width:6px;height:6px;border-radius:50%;background:var(--fc-green);box-shadow:0 0 0 4px var(--fc-green-soft)}
.fc-hello-sub strong{color:var(--fc-ink)}
.fc-cmd-actions{display:flex;gap:10px;align-items:center;flex-wrap:wrap}
.fc-btn{display:inline-flex;align-items:center;gap:8px;padding:11px 16px;border-radius:12px;font-size:13.5px;font-weight:650;cursor:pointer;border:0;font-family:inherit}
.fc-btn svg{width:16px;height:16px;stroke:currentColor;fill:none;stroke-width:2}
.fc-btn-ghost{background:var(--fc-panel-2);border:1px solid var(--fc-line);color:var(--fc-ink);position:relative}
.fc-btn-ghost:hover{background:var(--fc-panel-3)}
.fc-btn-ghost .bdg{position:absolute;top:-6px;right:-6px;background:var(--fc-red);color:#fff;font-size:10px;font-weight:700;min-width:18px;height:18px;padding:0 4px;border-radius:999px;display:grid;place-items:center}
.fc-btn-gold{background:linear-gradient(140deg,#FCD34D,#F59E0B);color:var(--fc-gold-ink);box-shadow:0 10px 22px -8px rgba(245,158,11,.6),inset 0 1px 0 rgba(255,255,255,.35)}
.fc-btn-gold:hover{transform:translateY(-1px)}

/* KPIs */
.fc-kpis{display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin-bottom:16px}
.fc-kpi{position:relative;overflow:hidden;padding:18px 18px 14px;border-radius:var(--fc-r)}
.fc-kpi::before{content:"";position:absolute;inset:0 0 auto 0;height:3px}
.fc-kpi.k-gold::before{background:linear-gradient(90deg,#FCD34D,#F59E0B)}
.fc-kpi.k-green::before{background:linear-gradient(90deg,#34D399,#10B981)}
.fc-kpi.k-violet::before{background:linear-gradient(90deg,#C4B5FD,#8B5CF6)}
.fc-kpi.k-blue::before{background:linear-gradient(90deg,#93C5FD,#3B82F6)}
.fc-kpi-top{display:flex;align-items:center;justify-content:space-between;gap:10px}
.fc-kpi-lab{font-size:11px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:var(--fc-ink-3)}
.fc-kpi-ic{width:30px;height:30px;border-radius:9px;display:grid;place-items:center;font-size:15px}
.k-gold .fc-kpi-ic{background:var(--fc-gold-soft)} .k-green .fc-kpi-ic{background:var(--fc-green-soft)}
.k-violet .fc-kpi-ic{background:var(--fc-violet-soft)} .k-blue .fc-kpi-ic{background:var(--fc-blue-soft)}
.fc-kpi-val{font-size:30px;font-weight:800;letter-spacing:-.035em;margin-top:12px;line-height:1;color:var(--fc-ink)}
.k-gold .fc-kpi-val{color:var(--fc-gold)} .k-green .fc-kpi-val{color:var(--fc-green)}
.fc-kpi-val small{font-size:17px;font-weight:700;color:var(--fc-ink-3);letter-spacing:0}
.fc-kpi-foot{display:flex;align-items:center;justify-content:space-between;gap:8px;margin-top:10px}
.fc-kpi-sub{font-size:12px;color:var(--fc-ink-3)}
.fc-kpi-trend{font-size:11.5px;font-weight:700;padding:2px 8px;border-radius:999px;display:inline-flex;gap:3px;align-items:center;white-space:nowrap}
.fc-kpi-trend.up{background:var(--fc-green-soft);color:var(--fc-green)}
.fc-kpi-trend.red{background:var(--fc-red-soft);color:var(--fc-red)}
.fc-spark{width:100%;height:30px;margin-top:12px;overflow:visible}

/* band */
.fc-band{display:grid;grid-template-columns:1.55fr 1fr;gap:16px;margin-bottom:16px}
.fc-phead{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:16px 18px 12px}
.fc-ptitle{font-size:13px;font-weight:750;letter-spacing:-.01em;display:flex;align-items:center;gap:8px;color:var(--fc-ink)}
.fc-ptitle .ic{width:22px;height:22px;border-radius:7px;display:grid;place-items:center;font-size:12px}
.fc-plink{font-size:12px;color:var(--fc-ink-3);font-weight:600}
.fc-plink:hover{color:var(--fc-ink)}
.fc-chart-legend{display:flex;gap:16px;padding:0 18px 6px;flex-wrap:wrap}
.fc-lg{display:flex;align-items:center;gap:7px;font-size:11.5px;color:var(--fc-ink-2)}
.fc-lg i{width:20px;height:3px;border-radius:2px;display:inline-block}
.fc-chart-wrap{padding:2px 14px 16px}
.fc-chart-wrap canvas{width:100%;height:180px;display:block}

/* signal */
.fc-signal{padding:6px 14px 14px;display:flex;flex-direction:column;gap:8px}
.fc-sig{display:flex;gap:12px;align-items:center;padding:12px 14px;border-radius:var(--fc-r-sm);background:var(--fc-panel-2);border:1px solid var(--fc-line-2);text-decoration:none}
.fc-sig .sic{width:32px;height:32px;border-radius:9px;display:grid;place-items:center;font-size:15px;flex:none}
.fc-sig.amber .sic{background:var(--fc-amber-soft)} .fc-sig.red .sic{background:var(--fc-red-soft)} .fc-sig.violet .sic{background:var(--fc-violet-soft)}
.fc-sig-b{flex:1;min-width:0}
.fc-sig-t{font-size:13px;font-weight:650;color:var(--fc-ink);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.fc-sig-d{font-size:11.5px;color:var(--fc-ink-3);margin-top:2px;line-height:1.4}
.fc-sig-go{font-size:11.5px;font-weight:700;align-self:center;white-space:nowrap}
.fc-sig.amber .fc-sig-go{color:var(--fc-amber)} .fc-sig.red .fc-sig-go{color:var(--fc-red)} .fc-sig.violet .fc-sig-go{color:var(--fc-violet)}
.fc-sig-ok{display:flex;flex-direction:column;align-items:center;gap:8px;padding:34px 16px;color:var(--fc-ink-3);text-align:center}
.fc-sig-ok .em{font-size:32px}

/* diffusion */
.fc-diff{display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:16px}
.fc-diff-card{padding:16px 18px 18px;border-radius:var(--fc-r)}
.fc-diff-h{display:flex;align-items:center;gap:9px;font-size:12.5px;font-weight:700;margin-bottom:14px;color:var(--fc-ink)}
.fc-diff-h .ic{width:24px;height:24px;border-radius:7px;display:grid;place-items:center;font-size:13px}
.fc-diff-e .ic{background:var(--fc-violet-soft)} .fc-diff-s .ic{background:var(--fc-green-soft)}
.fc-periods{display:grid;grid-template-columns:repeat(4,1fr);gap:9px}
.fc-per{padding:11px 12px;border-radius:11px;background:var(--fc-panel-2);border:1px solid var(--fc-line-2)}
.fc-per-l{font-size:10px;font-weight:700;letter-spacing:.05em;text-transform:uppercase;color:var(--fc-ink-4)}
.fc-per-v{font-size:21px;font-weight:750;margin-top:5px;letter-spacing:-.02em}
.fc-diff-e .fc-per-v{color:#C4B5FD} .fc-diff-s .fc-per-v{color:#6EE7B7}
.fc-diff-e .fc-per-v.zero,.fc-diff-s .fc-per-v.zero{color:var(--fc-ink-4)}

/* section head */
.fc-sech{display:flex;align-items:center;justify-content:space-between;gap:12px;margin:26px 0 12px}
.fc-sect{font-size:16px;font-weight:750;letter-spacing:-.02em;display:flex;align-items:center;gap:9px;color:var(--fc-ink)}

/* table */
.fc-tbl-wrap{padding:4px 8px 8px;overflow-x:auto}
.fc-tbl-wrap table{width:100%;border-collapse:separate;border-spacing:0;min-width:640px}
.fc-tbl-wrap thead th{font-size:10.5px;font-weight:700;letter-spacing:.05em;text-transform:uppercase;color:var(--fc-ink-4);text-align:left;padding:10px 14px}
.fc-tbl-wrap tbody td{padding:13px 14px;border-top:1px solid var(--fc-line-2);font-size:13px;vertical-align:middle;color:var(--fc-ink-2)}
.fc-tbl-wrap tbody tr:hover td{background:rgba(255,255,255,.02)}
.fc-org-n{font-weight:650;color:var(--fc-ink)} .fc-org-id{font-size:11px;color:var(--fc-ink-4);margin-top:1px}
.fc-chip{display:inline-flex;align-items:center;gap:5px;font-size:11px;font-weight:700;padding:3px 9px;border-radius:999px}
.fc-chip-green{background:var(--fc-green-soft);color:var(--fc-green)}
.fc-chip-violet{background:var(--fc-violet-soft);color:var(--fc-violet)}
.fc-chip-red{background:var(--fc-red-soft);color:var(--fc-red)}
.fc-chip-gray{background:var(--fc-panel-3);color:var(--fc-ink-2)}
.fc-chip.dot::before{content:"";width:6px;height:6px;border-radius:50%;background:currentColor;display:inline-block}
.fc-tbtn{font-size:12px;font-weight:650;color:var(--fc-ink-2);padding:6px 12px;border-radius:9px;border:1px solid var(--fc-line);background:var(--fc-panel-2);display:inline-block}
.fc-tbtn:hover{color:var(--fc-ink);border-color:var(--fc-violet-2)}

/* power actions */
.fc-power{display:grid;grid-template-columns:repeat(4,1fr);gap:14px}
.fc-act{padding:18px;border-radius:var(--fc-r);border:1px solid var(--fc-line);background:var(--fc-panel-2);position:relative;overflow:hidden;transition:transform .14s,border-color .14s;display:block;text-decoration:none}
.fc-act:hover{transform:translateY(-3px);border-color:var(--fc-violet-2)}
.fc-act-ic{width:38px;height:38px;border-radius:11px;display:grid;place-items:center;font-size:18px;background:var(--fc-panel-3);margin-bottom:12px}
.fc-act-t{font-size:14px;font-weight:700;letter-spacing:-.01em;color:var(--fc-ink)}
.fc-act-d{font-size:12px;color:var(--fc-ink-3);margin-top:4px;line-height:1.45}
.fc-act-tag{position:absolute;top:13px;right:13px;font-size:9.5px;font-weight:800;letter-spacing:.04em;padding:3px 8px;border-radius:6px}
.fc-act.gold{background:linear-gradient(140deg,rgba(252,211,77,.09),rgba(245,158,11,.06));border-color:rgba(245,158,11,.3)}
.fc-act.gold:hover{border-color:var(--fc-gold-2)} .fc-act.gold .fc-act-ic{background:var(--fc-gold-soft)}
.fc-act.gold .fc-act-tag{background:linear-gradient(135deg,#FCD34D,#F59E0B);color:var(--fc-gold-ink)}
.fc-act.violet{background:linear-gradient(140deg,rgba(139,92,246,.10),rgba(167,139,250,.05));border-color:rgba(139,92,246,.3)}
.fc-act.violet .fc-act-ic{background:var(--fc-violet-soft)} .fc-act.violet .fc-act-tag{background:var(--fc-violet-soft);color:var(--fc-violet)}

/* founder seal */
.fc-fseal{margin-top:16px;padding:20px 22px;border-radius:var(--fc-r);position:relative;overflow:hidden;background:linear-gradient(135deg,rgba(252,211,77,.08),rgba(245,158,11,.05));border:1px solid rgba(245,158,11,.28);display:flex;align-items:center;gap:16px;flex-wrap:wrap}
.fc-fseal .ghost{position:absolute;right:-10px;bottom:-24px;font-size:120px;opacity:.05;transform:rotate(-12deg)}
.fc-fseal .av{width:52px;height:52px;border-radius:15px;background:linear-gradient(140deg,#FCD34D,#F59E0B);display:grid;place-items:center;font-size:18px;font-weight:800;color:var(--fc-gold-ink);flex:none;box-shadow:0 10px 22px -8px rgba(245,158,11,.6)}
.fc-fseal .fn{font-size:16px;font-weight:750;color:var(--fc-ink)}
.fc-fseal .fe{font-size:12.5px;color:var(--fc-ink-3);margin-top:1px}
.fc-fseal .fb{display:flex;gap:6px;margin-top:8px;flex-wrap:wrap}
.fc-fbadge{font-size:10px;font-weight:700;padding:3px 9px;border-radius:999px}
.fc-fbadge.gold{background:var(--fc-gold-soft);color:var(--fc-gold)} .fc-fbadge.violet{background:var(--fc-violet-soft);color:var(--fc-violet)}
.fc-fseal .flog{margin-left:auto;text-align:right;font-size:11.5px;color:var(--fc-ink-4)}

@media (max-width:1080px){ .fc-kpis{grid-template-columns:1fr 1fr} .fc-band{grid-template-columns:1fr} .fc-power{grid-template-columns:1fr 1fr} }
@media (max-width:640px){ .fc-kpis{grid-template-columns:1fr 1fr} .fc-diff{grid-template-columns:1fr} .fc-power{grid-template-columns:1fr 1fr} }
</style>

<div class="fc">
  <!-- ===== command bar ===== -->
  <div class="fc-cmd">
    <div>
      <div class="fc-hello">
        Bienvenue <?= h($user['first_name']) ?>
        <?php if ($ctx['is_founder']): ?>
          <span class="fc-seal">🏗️ FONDATEUR</span>
        <?php else: ?>
          <span class="fc-seal violet">👑 Super Admin</span>
        <?php endif; ?>
      </div>
      <div class="fc-hello-sub">
        <span class="dot"></span>
        <?php if ($ctx['is_founder']): ?>
          Pouvoir absolu sur la plateforme Assokit · <?= date('d/m/Y') ?>
        <?php else: ?>
          Vue d'ensemble de la plateforme au <?= date('d/m/Y') ?>
        <?php endif; ?>
      </div>
    </div>
    <div class="fc-cmd-actions">
      <?php if ($ctx['is_founder'] && $nb_unread_notifs > 0): ?>
        <a href="/super-admin/notifications" class="fc-btn fc-btn-ghost">
          <svg viewBox="0 0 24 24"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.7 21a2 2 0 0 1-3.4 0"/></svg>
          Notifications <span class="bdg"><?= (int) $nb_unread_notifs ?></span>
        </a>
      <?php endif; ?>
      <a href="/super-admin/nouvelle-asso" class="fc-btn fc-btn-gold">
        <svg viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg> Créer une association
      </a>
    </div>
  </div>

  <!-- ===== KPIs ===== -->
  <section class="fc-kpis">
    <div class="panel fc-kpi k-gold">
      <div class="fc-kpi-top"><span class="fc-kpi-lab">MRR (TTC)</span><span class="fc-kpi-ic">💶</span></div>
      <div class="fc-kpi-val num"><?= number_format($mrr, 2, ',', ' ') ?> <small>€</small></div>
      <svg class="fc-spark" viewBox="0 0 120 30" preserveAspectRatio="none"><defs><linearGradient id="fcgg" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#F59E0B" stop-opacity=".35"/><stop offset="1" stop-color="#F59E0B" stop-opacity="0"/></linearGradient></defs><path d="M0 24 L20 22 L40 23 L60 17 L80 15 L100 9 L120 6 L120 30 L0 30 Z" fill="url(#fcgg)"/><path d="M0 24 L20 22 L40 23 L60 17 L80 15 L100 9 L120 6" fill="none" stroke="#FCD34D" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><circle cx="120" cy="6" r="2.6" fill="#FCD34D"/></svg>
      <div class="fc-kpi-foot"><span class="fc-kpi-sub">Revenu mensuel récurrent</span></div>
    </div>

    <div class="panel fc-kpi k-green">
      <div class="fc-kpi-top"><span class="fc-kpi-lab">Associations</span><span class="fc-kpi-ic">🏛️</span></div>
      <div class="fc-kpi-val num"><?= (int) $nb_orgs_total ?></div>
      <svg class="fc-spark" viewBox="0 0 120 30" preserveAspectRatio="none"><path d="M0 26 L20 25 L40 24 L60 22 L80 19 L100 15 L120 9" fill="none" stroke="#34D399" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><circle cx="120" cy="9" r="2.6" fill="#34D399"/></svg>
      <div class="fc-kpi-foot"><span class="fc-kpi-sub"><?= (int) $nb_orgs_active ?> actives · <?= (int) $nb_orgs_trial ?> essai</span><?php if ($nb_orgs_suspended > 0): ?><span class="fc-kpi-trend red"><?= (int) $nb_orgs_suspended ?> susp.</span><?php endif; ?></div>
    </div>

    <div class="panel fc-kpi k-violet">
      <div class="fc-kpi-top"><span class="fc-kpi-lab">Utilisateurs</span><span class="fc-kpi-ic">👥</span></div>
      <div class="fc-kpi-val num"><?= number_format($nb_users, 0, ',', ' ') ?></div>
      <svg class="fc-spark" viewBox="0 0 120 30" preserveAspectRatio="none"><path d="M0 25 L20 24 L40 21 L60 20 L80 16 L100 12 L120 7" fill="none" stroke="#A78BFA" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><circle cx="120" cy="7" r="2.6" fill="#A78BFA"/></svg>
      <div class="fc-kpi-foot"><span class="fc-kpi-sub">tous rôles confondus</span></div>
    </div>

    <div class="panel fc-kpi k-blue">
      <div class="fc-kpi-top"><span class="fc-kpi-lab">Générations IA</span><span class="fc-kpi-ic">✨</span></div>
      <div class="fc-kpi-val num"><?= number_format((int) $ia_stats['nb'], 0, ',', ' ') ?></div>
      <svg class="fc-spark" viewBox="0 0 120 30" preserveAspectRatio="none"><path d="M0 22 L20 24 L40 20 L60 23 L80 17 L100 18 L120 11" fill="none" stroke="#60A5FA" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><circle cx="120" cy="11" r="2.6" fill="#60A5FA"/></svg>
      <div class="fc-kpi-foot"><span class="fc-kpi-sub"><?= number_format((float) $ia_stats['cost'], 2, ',', ' ') ?> € dépensés</span></div>
    </div>
  </section>

  <!-- ===== growth + signal ===== -->
  <section class="fc-band">
    <div class="panel">
      <div class="fc-phead">
        <div class="fc-ptitle"><span class="ic" style="background:var(--fc-gold-soft)">📈</span> Croissance plateforme — 12 mois</div>
        <a href="/super-admin/stats" class="fc-plink">Stats approfondies →</a>
      </div>
      <div class="fc-chart-legend">
        <span class="fc-lg"><i style="background:linear-gradient(90deg,#FCD34D,#F59E0B)"></i> MRR (€)</span>
        <span class="fc-lg"><i style="background:#34D399"></i> Associations</span>
      </div>
      <div class="fc-chart-wrap"><canvas id="fcGrowth" width="740" height="180"></canvas></div>
    </div>

    <div class="panel">
      <div class="fc-phead">
        <div class="fc-ptitle"><span class="ic" style="background:var(--fc-amber-soft)">🛰️</span> Signal — à traiter</div>
        <a href="/super-admin/associations" class="fc-plink">Tout voir →</a>
      </div>
      <div class="fc-signal">
        <?php if (empty($fc_signals)): ?>
          <div class="fc-sig-ok"><span class="em">✅</span><div><strong style="color:var(--fc-ink)">Tout est sous contrôle</strong><br><span style="font-size:12px">Aucune action urgente en attente.</span></div></div>
        <?php else: foreach (array_slice($fc_signals, 0, 5) as $s): ?>
          <a href="<?= h($s['url']) ?>" class="fc-sig <?= h($s['tone']) ?>">
            <div class="sic"><?= $s['ic'] ?></div>
            <div class="fc-sig-b"><div class="fc-sig-t"><?= h($s['t']) ?></div><div class="fc-sig-d"><?= h($s['d']) ?></div></div>
            <div class="fc-sig-go"><?= h($s['go']) ?></div>
          </a>
        <?php endforeach; endif; ?>
      </div>
    </div>
  </section>

  <!-- ===== diffusion (Fondateur) ===== -->
  <?php if ($ctx['is_founder'] && $advanced_stats): ?>
  <section class="fc-diff">
    <div class="panel fc-diff-card fc-diff-e">
      <div class="fc-diff-h"><span class="ic">📧</span> Emails envoyés — plateforme</div>
      <div class="fc-periods">
        <?php foreach (['week'=>'Semaine','month'=>'Mois','quarter'=>'Trimestre','year'=>'Année'] as $k=>$lbl): $v=(int)$advanced_stats['emails'][$k]; ?>
          <div class="fc-per"><div class="fc-per-l"><?= $lbl ?></div><div class="fc-per-v num <?= $v===0?'zero':'' ?>"><?= number_format($v,0,',',' ') ?></div></div>
        <?php endforeach; ?>
      </div>
    </div>
    <div class="panel fc-diff-card fc-diff-s">
      <div class="fc-diff-h"><span class="ic">💬</span> SMS envoyés — plateforme</div>
      <div class="fc-periods">
        <?php foreach (['week'=>'Semaine','month'=>'Mois','quarter'=>'Trimestre','year'=>'Année'] as $k=>$lbl): $v=(int)$advanced_stats['sms'][$k]; ?>
          <div class="fc-per"><div class="fc-per-l"><?= $lbl ?></div><div class="fc-per-v num <?= $v===0?'zero':'' ?>"><?= number_format($v,0,',',' ') ?></div></div>
        <?php endforeach; ?>
      </div>
    </div>
  </section>
  <?php endif; ?>

  <!-- ===== dernières associations ===== -->
  <div class="fc-sech"><div class="fc-sect">🏛️ Dernières associations créées</div><a href="/super-admin/associations" class="fc-plink">Voir tout →</a></div>
  <?php if (empty($latest_orgs)): ?>
    <div class="panel" style="padding:40px;text-align:center;color:var(--fc-ink-3)">
      <div style="font-size:34px;margin-bottom:8px">🏛️</div>
      <div style="font-weight:700;color:var(--fc-ink)">Aucune association</div>
      <div style="font-size:12.5px;margin-top:4px">Créez la première avec le bouton en haut.</div>
    </div>
  <?php else: ?>
    <div class="panel fc-tbl-wrap">
      <table>
        <thead><tr><th>Association</th><th>Validation</th><th>Statut</th><th>Plan</th><th>Utilisateurs</th><th>Créée</th><th></th></tr></thead>
        <tbody>
          <?php foreach ($latest_orgs as $o): ?>
          <tr>
            <td><div class="fc-org-n"><?= h($o['name']) ?></div><div class="fc-org-id">ID #<?= (int) $o['id'] ?></div></td>
            <td>
              <?php if ($o['validation_status'] === 'pending_founder'): ?>
                <span class="fc-chip fc-chip-violet">🏗️ En attente</span>
              <?php elseif ($o['validation_status'] === 'rejected'): ?>
                <span class="fc-chip fc-chip-red">✕ Refusée</span>
              <?php else: ?>
                <span class="fc-chip fc-chip-green">✓ Validée</span>
              <?php endif; ?>
            </td>
            <td>
              <span class="fc-chip dot <?= match($o['status']) { 'active'=>'fc-chip-green','trial'=>'fc-chip-violet','suspended'=>'fc-chip-red','cancelled'=>'fc-chip-gray',default=>'fc-chip-gray' } ?>">
                <?= match($o['status']) { 'active'=>'Active','trial'=>'Essai','suspended'=>'Suspendue','cancelled'=>'Résiliée',default=>h($o['status']) } ?>
              </span>
            </td>
            <td><span class="fc-chip fc-chip-gray"><?= h(ucfirst($o['plan'])) ?></span></td>
            <td class="num"><?= (int) $o['nb_users'] ?></td>
            <td class="num"><?= date('d/m/Y', strtotime($o['created_at'])) ?></td>
            <td><a href="/super-admin/associations?id=<?= (int) $o['id'] ?>" class="fc-tbtn">Gérer →</a></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>

  <!-- ===== actions rapides ===== -->
  <div class="fc-sech"><div class="fc-sect">⚡ Actions rapides</div></div>
  <section class="fc-power">
    <a href="/super-admin/nouvelle-asso" class="fc-act">
      <div class="fc-act-ic">➕</div><div class="fc-act-t">Nouvelle association</div>
      <div class="fc-act-d"><?= $ctx['is_founder'] ? 'Création directe' : 'Soumettre à validation' ?></div>
    </a>
    <a href="/super-admin/abonnements" class="fc-act">
      <div class="fc-act-ic">💳</div><div class="fc-act-t">Gérer les paiements</div>
      <div class="fc-act-d">Factures, paiements, impayés</div>
    </a>
    <a href="/super-admin-mairies" class="fc-act gold">
      <span class="fc-act-tag">🏛️ MULTI-ASSO</span><div class="fc-act-ic">🏛️</div>
      <div class="fc-act-t">Collectivités &amp; Mairies</div><div class="fc-act-d">Mairies, CAF, départements…</div>
    </a>
    <a href="/admin-cron-login" class="fc-act violet">
      <span class="fc-act-tag">🔒 RÉAUTH 15 MIN</span><div class="fc-act-ic">🛡️</div>
      <div class="fc-act-t">Cockpit CRON</div><div class="fc-act-d">Relances, essais, renouvellements</div>
    </a>
    <?php if ($ctx['is_founder']): ?>
    <a href="/fondateur-cockpit/societe" class="fc-act gold">
      <span class="fc-act-tag">🏗️ FONDATEUR</span><div class="fc-act-ic">⚙️</div>
      <div class="fc-act-t">Paramètres société</div><div class="fc-act-d">Infos légales, TVA, IBAN, logo</div>
    </a>
    <a href="/super-admin/super-admins" class="fc-act">
      <div class="fc-act-ic">👑</div><div class="fc-act-t">Super admins (<?= (int) $nb_super_admins ?>)</div>
      <div class="fc-act-d">Créer / gérer les SA</div>
    </a>
    <a href="/super-admin/stats" class="fc-act gold">
      <div class="fc-act-ic">📊</div><div class="fc-act-t">Statistiques approfondies</div>
      <div class="fc-act-d">Emails, SMS, usage plateforme</div>
    </a>
    <?php else: ?>
    <a href="/super-admin/associations" class="fc-act">
      <div class="fc-act-ic">🏛️</div><div class="fc-act-t">Gérer les assos</div>
      <div class="fc-act-d">Support, modifications</div>
    </a>
    <?php endif; ?>
  </section>

  <!-- ===== sceau Fondateur ===== -->
  <?php if (!empty($founders)): $f0 = $founders[0]; ?>
  <div class="fc-fseal">
    <div class="ghost">🏗️</div>
    <div class="av"><?= h(strtoupper(mb_substr($f0['first_name'] ?? 'F', 0, 1) . mb_substr($f0['last_name'] ?? '', 0, 1))) ?></div>
    <div>
      <div class="fn"><?= h(trim(($f0['first_name'] ?? '') . ' ' . ($f0['last_name'] ?? ''))) ?></div>
      <div class="fe"><?= h($f0['email'] ?? '') ?></div>
      <div class="fb"><span class="fc-fbadge gold">🏗️ FONDATEUR</span><span class="fc-fbadge violet">👑 Super Admin</span></div>
    </div>
    <?php if (!empty($f0['last_login_at'])): ?>
      <div class="flog">Dernière connexion<br><?= date('d/m/Y · H:i', strtotime($f0['last_login_at'])) ?></div>
    <?php endif; ?>
  </div>
  <?php endif; ?>
</div>

<script>
(function(){
  var cv=document.getElementById('fcGrowth');if(!cv)return;var ctx=cv.getContext('2d');
  var W=cv.width,H=cv.height,padL=6,padR=10,padT=14,padB=22;
  var mrr=<?= json_encode($growth_mrr) ?>;
  var assos=<?= json_encode($growth_assos) ?>;
  var labels=<?= json_encode($growth_labels, JSON_UNESCAPED_UNICODE) ?>;
  if (!mrr.length) return;
  var maxM=Math.max(10, Math.ceil(Math.max.apply(null, mrr.concat([1])) * 1.15));
  var maxA=Math.max(2, Math.ceil(Math.max.apply(null, assos.concat([1])) * 1.2));
  function x(i){return padL+(W-padL-padR)*(i/(mrr.length-1));}
  function yM(v){return padT+(H-padT-padB)*(1-v/maxM);}
  function yA(v){return padT+(H-padT-padB)*(1-v/maxA);}
  ctx.clearRect(0,0,W,H);
  ctx.strokeStyle='rgba(255,255,255,.05)';ctx.lineWidth=1;
  for(var g=0;g<=3;g++){var yy=padT+(H-padT-padB)*g/3;ctx.beginPath();ctx.moveTo(padL,yy);ctx.lineTo(W-padR,yy);ctx.stroke();}
  var grad=ctx.createLinearGradient(0,padT,0,H-padB);
  grad.addColorStop(0,'rgba(245,158,11,.34)');grad.addColorStop(1,'rgba(245,158,11,0)');
  ctx.beginPath();ctx.moveTo(x(0),yM(mrr[0]));
  for(var i=1;i<mrr.length;i++)ctx.lineTo(x(i),yM(mrr[i]));
  ctx.lineTo(x(mrr.length-1),H-padB);ctx.lineTo(x(0),H-padB);ctx.closePath();ctx.fillStyle=grad;ctx.fill();
  ctx.beginPath();ctx.moveTo(x(0),yM(mrr[0]));
  for(i=1;i<mrr.length;i++)ctx.lineTo(x(i),yM(mrr[i]));
  ctx.strokeStyle='#FCD34D';ctx.lineWidth=2.4;ctx.lineJoin='round';ctx.lineCap='round';ctx.stroke();
  ctx.beginPath();ctx.arc(x(mrr.length-1),yM(mrr[mrr.length-1]),3.4,0,7);ctx.fillStyle='#FCD34D';ctx.fill();
  ctx.beginPath();ctx.moveTo(x(0),yA(assos[0]));
  for(i=1;i<assos.length;i++)ctx.lineTo(x(i),yA(assos[i]));
  ctx.strokeStyle='#34D399';ctx.lineWidth=2;ctx.setLineDash([4,4]);ctx.stroke();ctx.setLineDash([]);
  ctx.fillStyle='rgba(255,255,255,.28)';ctx.font='10px sans-serif';ctx.textAlign='center';
  for(i=0;i<labels.length;i++)ctx.fillText(labels[i],x(i),H-6);
})();
</script>

<?php sa_render_foot(); ?>
