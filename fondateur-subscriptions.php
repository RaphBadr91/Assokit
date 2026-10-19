<?php
/**
 * fondateur-subscriptions.php
 * --------------------------------------------------------------
 * Page admin FONDATEUR : assigner / changer les plans des organisations
 * --------------------------------------------------------------
 * Plan et statut affichés = ak_org_plan_state() (trial-helpers.php), la même
 * vérité que la fiche association, l'appli et l'espace de l'association.
 * Tout changement de plan passe par ak_org_set_plan() : abonnement courant,
 * organizations.plan et état d'essai changent ensemble.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes-layout.php';
require_once __DIR__ . '/plan-helpers.php';
require_once __DIR__ . '/trial-helpers.php';
require_once __DIR__ . '/sa-auth-helpers.php';
require_once __DIR__ . '/sa-plan-state-helpers.php';
require_once __DIR__ . '/signup-review-helpers.php';   // demandes d'espace de l'app iPhone : jamais ouvertes par un plan

if (session_status() !== PHP_SESSION_ACTIVE) session_start();

$user_id = (int)($_SESSION['user_id'] ?? 0);
if (!$user_id) { header('Location: /connexion'); exit; }
sa_leave_impersonation_first();   // incarnation en cours : page « Revenir à mon compte », pas un 403

$user = $pdo->prepare("SELECT id, email, role, org_id, is_founder, is_super_admin FROM users WHERE id = :id LIMIT 1");
$user->execute([':id' => $user_id]);
$user = $user->fetch(PDO::FETCH_ASSOC);

// [FIX] Contrôle élargi : accepte role='founder'/'super_admin' OU is_founder=1 OU is_super_admin=1
$has_access = $user && (
    in_array($user['role'] ?? '', ['founder', 'super_admin'], true)
    || !empty($user['is_founder'])
    || !empty($user['is_super_admin'])
);

if (!$has_access) {
    http_response_code(403);
    render_head('Accès refusé');
    render_sidebar('fondateur-subscriptions');
    echo '<main class="main"><div class="card"><h1>🚫 Accès refusé</h1></div></main>';
    render_foot();
    exit;
}

// Confirmation d'identité du cockpit (/super-admin-login), prolongée à chaque page
sa_auth_gate();

$flash = $_SESSION['flash_fondateur_subs'] ?? null;
unset($_SESSION['flash_fondateur_subs']);

// === Actions ===
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!check_csrf($_POST['csrf_token'] ?? '')) { http_response_code(403); die('Requete invalide (jeton CSRF manquant ou expire).'); }
    $action = $_POST['action'] ?? '';
    $sub_cols = ak_db_columns($pdo, 'asso_subscriptions');
    // Ligne d'abonnement courante (même ordre que ak_get_current_plan / ak_org_plan_state)
    $current_sub_id = function (int $org_id) use ($pdo): int {
        $st = $pdo->prepare("SELECT s.id FROM asso_subscriptions s JOIN asso_plans p ON p.id = s.plan_id
                             WHERE s.org_id = ? ORDER BY (s.status = 'active') DESC, s.id DESC LIMIT 1");
        $st->execute([$org_id]);
        return (int)$st->fetchColumn();
    };
    $org_name = function (int $org_id) use ($pdo): ?string {
        $st = $pdo->prepare("SELECT name FROM organizations WHERE id = ?");
        $st->execute([$org_id]);
        $n = $st->fetchColumn();
        return $n === false ? null : (string)$n;
    };
    // Demande de création d'espace faite dans l'app iPhone, encore en attente : un plan (même l'essai)
    // l'ouvrirait sans la validation du Fondateur ni l'e-mail d'activation (valider / refuser depuis
    // la fiche de l'association ou l'e-mail)
    $is_request = function (int $org_id) use ($pdo): bool {
        $st = $pdo->prepare("SELECT * FROM organizations WHERE id = ?");
        $st->execute([$org_id]);
        return ak_signup_review_pending($st->fetch(PDO::FETCH_ASSOC) ?: []);
    };
    try {
        if ($action === 'change_plan') {
            $org_id = (int)($_POST['org_id'] ?? 0);
            $plan_id = (int)($_POST['plan_id'] ?? 0);
            $status = (string)($_POST['status'] ?? 'active');
            if (!in_array($status, ['active', 'overdue', 'cancelled'], true)) $status = 'active';
            $period_end = trim((string)($_POST['current_period_end'] ?? ''));
            if ($period_end !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $period_end)) throw new RuntimeException('Date de fin invalide.');
            $name = $org_name($org_id);
            if ($name === null) throw new RuntimeException('Organisation introuvable.');
            if ($is_request($org_id)) throw new RuntimeException("« {$name} » : " . ak_signup_review_block_message());
            $pl = $pdo->prepare("SELECT id, slug" . (isset(ak_db_columns($pdo, 'asso_plans')['is_trial']) ? ", is_trial" : ", 0 AS is_trial") . " FROM asso_plans WHERE id = ?");
            $pl->execute([$plan_id]);
            $plan = $pl->fetch(PDO::FETCH_ASSOC);
            if (!$plan) throw new RuntimeException('Plan inconnu.');
            $to_trial = !empty($plan['is_trial']) || $plan['slug'] === 'pro-essai';
            $before = ak_org_plan_state($pdo, $org_id);
            // La date du formulaire est pré-remplie : on ne la prend en compte que si elle a été modifiée
            $old_date = $before['is_trial'] || $before['trial_expired']
                ? ($before['trial_ends_at'] ? date('Y-m-d', strtotime((string)$before['trial_ends_at'])) : '')
                : '';
            if ($old_date === '') {
                $cid = $current_sub_id($org_id);
                if ($cid) {
                    $pe = $pdo->query("SELECT current_period_end FROM asso_subscriptions WHERE id = " . $cid)->fetchColumn();
                    $old_date = $pe && strtotime((string)$pe) ? date('Y-m-d', strtotime((string)$pe)) : '';
                }
            }
            $date_changed = $period_end !== $old_date;
            if ($to_trial && $date_changed && $period_end !== '' && strtotime($period_end . ' 23:59:59') <= time()) {
                throw new RuntimeException('La fin d’essai doit être dans le futur (ou prolongez l’essai depuis la fiche de l’association).');
            }
            // Retard / annulation déjà en place sur la ligne courante : « Appliquer » sans changement
            // ne doit ni repousser la tolérance de 15 jours, ni changer la date d'annulation.
            $cur_row = [];
            if ($cid0 = $current_sub_id($org_id)) {
                $keep = array_values(array_filter(['grace_period_end', 'cancelled_at'], fn($c) => isset($sub_cols[$c])));
                if ($keep) $cur_row = $pdo->query("SELECT " . implode(', ', $keep) . " FROM asso_subscriptions WHERE id = " . (int)$cid0)->fetch(PDO::FETCH_ASSOC) ?: [];
            }
            $same_plan = (int)$before['plan_id'] === $plan_id;

            $pdo->beginTransaction();
            if ($to_trial && $date_changed && $period_end !== '') {
                // Fin d'essai choisie : ak_org_set_plan conserve une fin future déjà posée
                $pdo->prepare("UPDATE organizations SET trial_ends_at = ? WHERE id = ?")->execute([$period_end . ' 23:59:59', $org_id]);
            }
            ak_org_set_plan($pdo, $org_id, $plan_id);
            if (!$to_trial && ($cid = $current_sub_id($org_id))) {
                $sets = []; $args = [];
                if (isset($sub_cols['current_period_end'])) {
                    // Sans date choisie, l'échéance est celle posée par ak_org_set_plan : jamais la fin d'essai,
                    // fin du premier mois pour une formule mensuelle payée sur facture (renouvellements)
                    if ($date_changed) { $sets[] = 'current_period_end = ?'; $args[] = $period_end !== '' ? $period_end . ' 23:59:59' : null; }
                }
                if ($status !== 'active') {
                    $sets[] = 'status = ?'; $args[] = $status;
                    $unchanged = $same_plan && $before['sub_status'] === $status;
                    if ($status === 'overdue' && isset($sub_cols['grace_period_end'])) {
                        $sets[] = 'grace_period_end = ?';
                        $args[] = $unchanged && !empty($cur_row['grace_period_end']) ? $cur_row['grace_period_end'] : (new DateTime('+15 days'))->format('Y-m-d H:i:s');
                    }
                    if ($status === 'cancelled' && isset($sub_cols['cancelled_at'])) {
                        if ($unchanged && !empty($cur_row['cancelled_at'])) { $sets[] = 'cancelled_at = ?'; $args[] = $cur_row['cancelled_at']; }
                        else $sets[] = 'cancelled_at = NOW()';
                    }
                }
                if ($sets) {
                    $args[] = $cid;
                    $pdo->prepare("UPDATE asso_subscriptions SET " . implode(', ', $sets) . " WHERE id = ?")->execute($args);
                }
                // Sortie d'essai vers un plan payant : plus de mode « Offert » hérité de l'inscription
                sa_trial_exit_cleanup($pdo, $org_id, $before, ak_org_plan_state($pdo, $org_id), false);
            }
            $pdo->commit();
            $after = ak_org_plan_state($pdo, $org_id);
            $_SESSION['flash_fondateur_subs'] = ['type' => 'success', 'msg' => "« {$name} » : {$before['plan_label']} → {$after['plan_label']} ({$after['badge']})."];
        } elseif ($action === 'mark_overdue') {
            $org_id = (int)($_POST['org_id'] ?? 0);
            $name = $org_name($org_id);
            if ($name === null) throw new RuntimeException('Organisation introuvable.');
            if ($is_request($org_id)) throw new RuntimeException("« {$name} » : " . ak_signup_review_block_message());
            $state = ak_org_plan_state($pdo, $org_id);
            if ($state['is_trial'] || $state['trial_expired']) throw new RuntimeException("« {$name} » est en essai gratuit : il n’y a pas de paiement en retard.");
            $cid = $current_sub_id($org_id);
            if (!$cid) throw new RuntimeException("« {$name} » n’a pas d’abonnement.");
            $grace_end = (new DateTime('+15 days'))->format('Y-m-d H:i:s');
            $pdo->prepare("UPDATE asso_subscriptions SET status = 'overdue'" . (isset($sub_cols['grace_period_end']) ? ", grace_period_end = :g" : "") . " WHERE id = :id")
                ->execute((isset($sub_cols['grace_period_end']) ? [':g' => $grace_end] : []) + [':id' => $cid]);
            $_SESSION['flash_fondateur_subs'] = ['type' => 'success', 'msg' => "« {$name} » marquée en retard de paiement — tolérance jusqu’au " . date('d/m/Y', strtotime($grace_end)) . '.'];
        } elseif ($action === 'downgrade_to_demarrage') {
            $org_id = (int)($_POST['org_id'] ?? 0);
            $name = $org_name($org_id);
            if ($name === null) throw new RuntimeException('Organisation introuvable.');
            if ($is_request($org_id)) throw new RuntimeException("« {$name} » : " . ak_signup_review_block_message());
            $demarrage_id = (int)$pdo->query("SELECT id FROM asso_plans WHERE slug='demarrage' LIMIT 1")->fetchColumn();
            if ($demarrage_id <= 0) throw new RuntimeException('Plan Démarrage introuvable.');
            $before = ak_org_plan_state($pdo, $org_id);
            $pdo->beginTransaction();
            ak_org_set_plan($pdo, $org_id, $demarrage_id);
            if ($cid = $current_sub_id($org_id)) {
                $sets = [];
                if (isset($sub_cols['downgraded_at'])) $sets[] = 'downgraded_at = NOW()';
                if (($before['is_trial'] || $before['trial_expired']) && isset($sub_cols['current_period_end'])) $sets[] = 'current_period_end = NULL';
                if ($sets) $pdo->prepare("UPDATE asso_subscriptions SET " . implode(', ', $sets) . " WHERE id = ?")->execute([$cid]);
            }
            $pdo->commit();
            $_SESSION['flash_fondateur_subs'] = ['type' => 'success', 'msg' => "« {$name} » est passée au plan Démarrage."];
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if (!($e instanceof RuntimeException) || $e instanceof PDOException) error_log('[fondateur-subscriptions] ' . get_class($e) . ' ' . $e->getMessage());
        $msg = ($e instanceof RuntimeException && !($e instanceof PDOException)) ? $e->getMessage()
             : ($e instanceof InvalidArgumentException ? 'Plan inconnu.' : 'Erreur technique : rien n’a été modifié.');
        $_SESSION['flash_fondateur_subs'] = ['type' => 'error', 'msg' => $msg];
    }
    header('Location: /fondateur-subscriptions');
    exit;
}

// === Filtres ===
$q = trim((string)($_GET['q'] ?? ''));
$filter_plan = (int)($_GET['plan_id'] ?? 0);
$filter_status = trim((string)($_GET['status'] ?? ''));

$status_filters = [
    'active' => 'Actif', 'trial' => 'En essai', 'trial_expired' => 'Essai terminé',
    'pending' => 'Paiement en retard / en attente', 'suspended' => 'Suspendue', 'cancelled' => 'Résiliée / annulé',
];
if (!isset($status_filters[$filter_status])) $filter_status = '';

$where = ['1=1'];
$params = [];
if ($q !== '') {
    $where[] = '(o.name LIKE :q OR o.id = :qid)';
    $params[':q'] = '%' . $q . '%';
    $params[':qid'] = (int)$q;
}
if (isset(ak_db_columns($pdo, 'organizations')['deleted_at'])) $where[] = 'o.deleted_at IS NULL';

// Une ligne par organisation : son abonnement COURANT (même ordre que ak_get_current_plan)
$sql = "
    SELECT
      o.id AS org_id, o.name AS org_name, o.slug AS org_slug,
      s.id AS sub_id, s.status, s.started_at, s.current_period_end, s.grace_period_end
    FROM organizations o
    LEFT JOIN asso_subscriptions s ON s.id = (
        SELECT s2.id FROM asso_subscriptions s2 JOIN asso_plans p2 ON p2.id = s2.plan_id
        WHERE s2.org_id = o.id ORDER BY (s2.status = 'active') DESC, s2.id DESC LIMIT 1)
    WHERE " . implode(' AND ', $where) . "
    ORDER BY o.id DESC
";
$st = $pdo->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

// Plan et statut : même vérité que partout ailleurs (ak_org_plan_state), filtrés sur cette vérité
$states = sa_org_plan_states($pdo, array_column($rows, 'org_id'));
$rows = array_values(array_filter($rows, function ($r) use ($states, $filter_plan, $filter_status) {
    $ps = $states[(int)$r['org_id']] ?? null;
    if (!$ps) return false;
    if ($filter_plan > 0 && (int)$ps['plan_id'] !== $filter_plan) return false;
    return match ($filter_status) {
        'active'        => $ps['badge_tone'] === 'active',
        'trial'         => $ps['badge_tone'] === 'trial',
        'trial_expired' => $ps['badge_tone'] === 'expired',
        'pending'       => $ps['badge_tone'] === 'pending',
        'suspended'     => $ps['badge_tone'] === 'suspended',
        'cancelled'     => $ps['badge_tone'] === 'cancelled' || $ps['sub_status'] === 'cancelled',
        default         => true,
    };
}));
$total_rows = count($rows);
$rows = array_slice($rows, 0, 200);

$plans_list = sa_plans_list($pdo);
$tone_colors = [
    'trial'     => ['bg' => '#EDE9FE', 'fg' => '#5B21B6'],
    'expired'   => ['bg' => '#FEF3C7', 'fg' => '#92400E'],
    'active'    => ['bg' => '#D1FAE5', 'fg' => '#065F46'],
    'pending'   => ['bg' => '#FED7AA', 'fg' => '#9A3412'],
    'suspended' => ['bg' => '#FEE2E2', 'fg' => '#991B1B'],
    'cancelled' => ['bg' => '#F1F5F9', 'fg' => '#64748B'],
];

render_head('Abonnements — Admin');
render_sidebar('fondateur-subscriptions');
?>

<main class="main">
  <div class="page-head" style="margin-bottom:24px;">
    <div>
      <h1 style="margin:0 0 4px;">📊 Abonnements actifs</h1>
      <p style="color:#64748b;margin:0;">Gérer les plans des <?= (int)$total_rows ?> organisation<?= $total_rows > 1 ? 's' : '' ?><?= $total_rows > count($rows) ? ' (les ' . count($rows) . ' plus récentes affichées)' : '' ?> · un essai PRO est gratuit (0 €) jusqu’à sa conversion</p>
    </div>
    <?php if (($user['role'] ?? '') === 'founder' || !empty($user['is_founder'])): ?>
    <a href="/fondateur-plans" class="btn">⚙️ Gérer les plans</a>
    <?php endif; ?>
  </div>

  <?php if ($flash): ?>
    <div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'danger' ?>" style="margin-bottom:18px;">
      <?= htmlspecialchars($flash['msg'], ENT_QUOTES, 'UTF-8') ?>
    </div>
  <?php endif; ?>

  <!-- Filtres -->
  <div class="card" style="padding:14px 18px;margin-bottom:18px;">
    <form method="get" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
      <input type="search" name="q" value="<?= htmlspecialchars($q, ENT_QUOTES, 'UTF-8') ?>" placeholder="🔍 Rechercher (nom ou ID)..." style="flex:1;min-width:220px;padding:9px 12px;border:1px solid #E2E8F0;border-radius:8px;">
      <select name="plan_id" style="padding:9px 12px;border:1px solid #E2E8F0;border-radius:8px;">
        <option value="0">Tous les plans</option>
        <?php foreach ($plans_list as $p): ?>
          <option value="<?= (int)$p['id'] ?>" <?= $filter_plan === (int)$p['id'] ? 'selected' : '' ?>><?= h($p['label']) ?></option>
        <?php endforeach; ?>
      </select>
      <select name="status" style="padding:9px 12px;border:1px solid #E2E8F0;border-radius:8px;">
        <option value="">Tous statuts</option>
        <?php foreach ($status_filters as $k => $lbl): ?>
          <option value="<?= h($k) ?>" <?= $filter_status === $k ? 'selected' : '' ?>><?= h($lbl) ?></option>
        <?php endforeach; ?>
      </select>
      <button type="submit" class="btn btn-primary">Filtrer</button>
      <?php if ($q || $filter_plan || $filter_status): ?>
        <a href="/fondateur-subscriptions" style="color:#64748B;font-size:13px;">↺ Reset</a>
      <?php endif; ?>
    </form>
  </div>

  <!-- Liste -->
  <div class="card" style="padding:0;overflow:hidden;">
    <div style="overflow-x:auto;">
      <table style="width:100%;border-collapse:collapse;font-size:14px;min-width:1000px;">
        <thead style="background:#F8FAFC;border-bottom:1px solid #E2E8F0;">
          <tr style="text-align:left;">
            <th style="padding:14px 18px;font-weight:600;">Organisation</th>
            <th style="padding:14px 12px;font-weight:600;">Plan actuel</th>
            <th style="padding:14px 12px;font-weight:600;">Statut</th>
            <th style="padding:14px 12px;font-weight:600;">Fin de période</th>
            <th style="padding:14px 12px;font-weight:600;">Usage</th>
            <th style="padding:14px 12px;font-weight:600;text-align:right;">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $row):
            $usage = $row['org_id'] ? ak_get_usage($pdo, (int)$row['org_id']) : null;
            $ps = $states[(int)$row['org_id']];
            $in_trial = $ps['is_trial'] || $ps['trial_expired'];
            $sc = $tone_colors[$ps['badge_tone']] ?? ['bg' => '#F1F5F9', 'fg' => '#64748B'];
            $trial_end = $ps['trial_ends_at'] ? date('d/m/Y', strtotime((string)$ps['trial_ends_at'])) : '';
            // Date pré-remplie : fin d'essai pendant un essai, sinon fin de période de l'abonnement
            $form_date = $in_trial
                ? ($ps['trial_ends_at'] ? date('Y-m-d', strtotime((string)$ps['trial_ends_at'])) : '')
                : ($row['current_period_end'] && strtotime((string)$row['current_period_end']) ? date('Y-m-d', strtotime((string)$row['current_period_end'])) : '');
          ?>
            <tr style="border-bottom:1px solid #F1F5F9;">
              <td style="padding:14px 18px;">
                <div style="font-weight:600;color:#0F172A;"><a href="/super-admin/associations?id=<?= (int)$row['org_id'] ?>" style="color:inherit;"><?= h($row['org_name'] ?? '?') ?></a></div>
                <div style="color:#64748B;font-size:12px;">#<?= (int)$row['org_id'] ?> · <?= h($row['org_slug'] ?? '') ?></div>
              </td>
              <td style="padding:14px 12px;">
                <?php if ($ps['plan_id']): ?>
                  <strong<?= $ps['badge_tone'] === 'trial' ? ' style="color:#5B21B6;"' : '' ?>><?= h($ps['plan_label']) ?></strong>
                  <div style="color:<?= $in_trial ? '#6D28D9' : '#64748B' ?>;font-size:12px;"><?= h(sa_plan_state_price($ps)) ?></div>
                <?php else: ?>
                  <span style="color:#DC2626;">Aucun abonnement</span>
                  <?php if ($ps['plan_label'] !== '—'): ?><div style="color:#64748B;font-size:12px;">ancien plan : <?= h($ps['plan_label']) ?></div><?php endif; ?>
                <?php endif; ?>
              </td>
              <td style="padding:14px 12px;">
                <span title="<?= h($ps['badge']) ?>" style="background:<?= $sc['bg'] ?>;color:<?= $sc['fg'] ?>;padding:3px 10px;border-radius:999px;font-size:12px;font-weight:<?= $in_trial ? '800' : '600' ?>;white-space:nowrap;"><?= h($in_trial ? sa_plan_state_short($ps) : $ps['badge']) ?></span>
                <?php if ($ps['badge_tone'] === 'pending' && $row['grace_period_end']): ?>
                  <div style="color:#EA580C;font-size:11px;margin-top:3px;">Grâce → <?= date('d/m/Y', strtotime($row['grace_period_end'])) ?></div>
                <?php endif; ?>
                <?php if ($ps['sub_status'] === 'cancelled' && $ps['badge_tone'] !== 'cancelled'): ?>
                  <div style="color:#DC2626;font-size:11px;margin-top:3px;">Abonnement annulé</div>
                <?php endif; ?>
              </td>
              <td style="padding:14px 12px;font-size:13px;">
                <?php if ($in_trial): ?>
                  <?= $trial_end !== '' ? ($ps['trial_expired'] ? 'Essai terminé le ' : 'Fin d’essai ') . h($trial_end) : '—' ?>
                <?php else: ?>
                  <?= $row['current_period_end'] && strtotime((string)$row['current_period_end']) ? date('d/m/Y', strtotime((string)$row['current_period_end'])) : '—' ?>
                <?php endif; ?>
              </td>
              <td style="padding:14px 12px;font-size:12px;">
                <?php if ($usage): ?>
                  <div>👥 <?= (int)$usage['adherents'] ?> · 📋 <?= (int)$usage['invoices_total'] ?> · 🤖 <?= (int)$usage['ai_text_month'] ?>/mois</div>
                  <div style="color:#64748B;">📨 <?= (int)$usage['emails_month'] ?> e-mails ce mois</div>
                <?php endif; ?>
              </td>
              <td style="padding:14px 12px;text-align:right;">
                <details style="display:inline-block;text-align:left;">
                  <summary style="cursor:pointer;background:#F1F5F9;padding:6px 12px;border-radius:6px;font-size:12px;list-style:none;">⚙️ Modifier</summary>
                  <div style="position:absolute;right:16px;background:white;border:1px solid #E2E8F0;border-radius:10px;padding:14px;box-shadow:0 8px 24px rgba(0,0,0,0.08);margin-top:6px;min-width:280px;max-width:calc(100vw - 32px);z-index:10;">
                    <form method="post" style="display:flex;flex-direction:column;gap:8px;">
                      <input type="hidden" name="action" value="change_plan">
                      <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token'] ?? '') ?>">
                      <input type="hidden" name="org_id" value="<?= (int)$row['org_id'] ?>">
                      <label style="font-size:12px;font-weight:600;">Plan</label>
                      <select name="plan_id" style="padding:8px;border:1px solid #E2E8F0;border-radius:6px;">
                        <?php if (!$ps['plan_id']): ?><option value="" selected disabled>— Choisir un plan —</option><?php endif; ?>
                        <?php foreach ($plans_list as $p): ?>
                          <option value="<?= (int)$p['id'] ?>" <?= (int)$ps['plan_id'] === (int)$p['id'] ? 'selected' : '' ?>>
                            <?= h($p['option']) ?>
                          </option>
                        <?php endforeach; ?>
                      </select>
                      <label style="font-size:12px;font-weight:600;">Statut (plans hors essai)</label>
                      <select name="status" style="padding:8px;border:1px solid #E2E8F0;border-radius:6px;">
                        <option value="active" <?= !in_array($ps['sub_status'], ['overdue', 'cancelled'], true) ? 'selected' : '' ?>>Actif</option>
                        <option value="overdue" <?= $ps['sub_status'] === 'overdue' ? 'selected' : '' ?>>En retard</option>
                        <option value="cancelled" <?= $ps['sub_status'] === 'cancelled' ? 'selected' : '' ?>>Annulé</option>
                      </select>
                      <label style="font-size:12px;font-weight:600;"><?= $in_trial ? 'Fin d’essai' : 'Fin de période' ?></label>
                      <input type="date" name="current_period_end" value="<?= h($form_date) ?>" style="padding:8px;border:1px solid #E2E8F0;border-radius:6px;">
                      <div style="font-size:11px;color:#64748B;line-height:1.4;">Plan d’essai : statut « Essai » automatique, la date fixe la fin d’essai. Un autre plan met fin à l’essai.</div>
                      <button type="submit" class="btn btn-primary btn-sm">💾 Appliquer</button>
                    </form>
                    <hr style="margin:10px 0;border:none;border-top:1px solid #E2E8F0;">
                    <?php if (!$in_trial): ?>
                    <form method="post" onsubmit="return confirm('Marquer en retard de paiement avec 15j de grâce ?');" style="margin-bottom:6px;">
                      <input type="hidden" name="action" value="mark_overdue">
                      <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token'] ?? '') ?>">
                      <input type="hidden" name="org_id" value="<?= (int)$row['org_id'] ?>">
                      <button type="submit" class="btn btn-sm" style="width:100%;color:#EA580C;">⏳ Marquer en retard (15j)</button>
                    </form>
                    <?php endif; ?>
                    <form method="post" onsubmit="return confirm('Downgrade au plan Démarrage ? L\'org perdra ses fonctionnalités premium.');">
                      <input type="hidden" name="action" value="downgrade_to_demarrage">
                      <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token'] ?? '') ?>">
                      <input type="hidden" name="org_id" value="<?= (int)$row['org_id'] ?>">
                      <button type="submit" class="btn btn-sm" style="width:100%;color:#DC2626;">↓ Downgrade Démarrage</button>
                    </form>
                  </div>
                </details>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if (empty($rows)): ?>
            <tr><td colspan="6" style="padding:40px;text-align:center;color:#64748B;">Aucune organisation trouvée</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</main>

<?php render_foot(); ?>
