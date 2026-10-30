<?php
/**
 * admin-subscriptions.php
 * --------------------------------------------------------------
 * Page admin FONDATEUR : assigner / changer les plans des organisations
 * --------------------------------------------------------------
 * Ancienne page (la page maintenue est /fondateur-subscriptions) : ses actions
 * passent désormais par ak_org_set_plan() (trial-helpers.php), comme partout.
 * Écrire asso_subscriptions directement laissait l'association « en essai »
 * (organizations.status, trial_ends_at, ancien libellé, ancienne table) alors
 * qu'elle était passée sur un plan payant, et inversement.
 * Les actions ne portent que sur la ligne d'abonnement COURANTE (même ordre
 * que ak_get_current_plan), jamais sur toutes les lignes de l'association.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes-layout.php';
require_once __DIR__ . '/plan-helpers.php';
require_once __DIR__ . '/trial-helpers.php';
require_once __DIR__ . '/sa-auth-helpers.php';
require_once __DIR__ . '/signup-review-helpers.php';   // demandes d'espace de l'app iPhone : jamais ouvertes par un plan

if (session_status() !== PHP_SESSION_ACTIVE) session_start();

$user_id = (int)($_SESSION['user_id'] ?? 0);
if (!$user_id) { header('Location: /connexion'); exit; }
sa_leave_impersonation_first();   // incarnation en cours : page « Revenir à mon compte », pas un 403

$user = $pdo->prepare("SELECT id, email, role, org_id, is_founder, is_super_admin FROM users WHERE id = :id LIMIT 1");
$user->execute([':id' => $user_id]);
$user = $user->fetch(PDO::FETCH_ASSOC);

// Même contrôle que /fondateur-subscriptions : le rôle seul refusait les comptes
// Fondateur / Super Admin repérés par leurs drapeaux (role = 'admin', is_founder = 1).
$has_access = $user && (
    in_array($user['role'] ?? '', ['founder', 'super_admin'], true)
    || !empty($user['is_founder'])
    || !empty($user['is_super_admin'])
);

if (!$has_access) {
    http_response_code(403);
    render_head('Accès refusé');
    render_sidebar('admin-subscriptions');
    echo '<main class="main"><div class="card"><h1>🚫 Accès refusé</h1></div></main>';
    render_foot();
    exit;
}

// Confirmation d'identité du cockpit (/super-admin-login), prolongée à chaque page
sa_auth_gate();

$flash = $_SESSION['flash_admin_subs'] ?? null;
unset($_SESSION['flash_admin_subs']);

// === Actions ===
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!check_csrf($_POST['csrf_token'] ?? '')) { http_response_code(403); die('Requete invalide (jeton CSRF manquant ou expire).'); }
    $action = $_POST['action'] ?? '';
    $sub_cols = ak_db_columns($pdo, 'asso_subscriptions');
    // Ligne d'abonnement courante (même ordre que ak_get_current_plan / ak_org_plan_state)
    $current_sub_id = function (int $org_id) use ($pdo): int {
        $st = $pdo->prepare("SELECT s.id FROM asso_subscriptions s JOIN asso_plans p ON p.id = s.plan_id
                             WHERE s.org_id = ? ORDER BY " . ak_sub_order('s') . " LIMIT 1");
        $st->execute([$org_id]);
        return (int)$st->fetchColumn();
    };
    $org_exists = function (int $org_id) use ($pdo): bool {
        $st = $pdo->prepare("SELECT 1 FROM organizations WHERE id = ?");
        $st->execute([$org_id]);
        return (bool)$st->fetchColumn();
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
            // L'essai vient du plan (plan d'essai), jamais d'un statut posé à la main sur un autre plan
            $status = (string)($_POST['status'] ?? 'active');
            if (!in_array($status, ['active', 'overdue', 'cancelled'], true)) $status = 'active';
            $period_end = trim((string)($_POST['current_period_end'] ?? ''));
            if ($period_end !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $period_end)) throw new RuntimeException('Date de fin invalide.');
            if (!$org_exists($org_id)) throw new RuntimeException('Organisation introuvable.');
            if ($is_request($org_id)) throw new RuntimeException("Org #{$org_id} : " . ak_signup_review_block_message());
            $pl = $pdo->prepare("SELECT slug" . (isset(ak_db_columns($pdo, 'asso_plans')['is_trial']) ? ", is_trial" : ", 0 AS is_trial") . " FROM asso_plans WHERE id = ?");
            $pl->execute([$plan_id]);
            $plan = $pl->fetch(PDO::FETCH_ASSOC);
            if (!$plan) throw new RuntimeException('Plan inconnu.');
            $to_trial = !empty($plan['is_trial']) || $plan['slug'] === 'pro-essai';
            $before = ak_org_plan_state($pdo, $org_id);
            // La date du formulaire est pré-remplie : elle ne compte que si elle a été modifiée
            $old_date = '';
            if ($cid = $current_sub_id($org_id)) {
                $pe = $pdo->query("SELECT current_period_end FROM asso_subscriptions WHERE id = " . (int)$cid)->fetchColumn();
                $old_date = $pe && strtotime((string)$pe) ? date('Y-m-d', strtotime((string)$pe)) : '';
            }
            $date_changed = $period_end !== $old_date;
            if ($to_trial && $date_changed && $period_end !== '' && strtotime($period_end . ' 23:59:59') <= time()) {
                throw new RuntimeException('La fin d’essai doit être dans le futur.');
            }

            $pdo->beginTransaction();
            if ($to_trial && $date_changed && $period_end !== '') {
                // Fin d'essai choisie : ak_org_set_plan conserve une fin future déjà posée. Abonnement souscrit pendant
                // l'essai : la date du premier prélèvement suit chez Stripe, sinon rien n'est changé (AkTrialBlocked)
                ak_org_set_trial_end($pdo, $org_id, $period_end . ' 23:59:59');
            }
            ak_org_set_plan($pdo, $org_id, $plan_id);
            if (!$to_trial && ($cid = $current_sub_id($org_id))) {
                $sets = []; $args = [];
                if ($date_changed && isset($sub_cols['current_period_end'])) { $sets[] = 'current_period_end = ?'; $args[] = $period_end !== '' ? $period_end . ' 23:59:59' : null; }
                if ($status !== 'active') {
                    $sets[] = 'status = ?'; $args[] = $status;
                    if ($status === 'overdue' && isset($sub_cols['grace_period_end'])) { $sets[] = 'grace_period_end = ?'; $args[] = (new DateTime('+15 days'))->format('Y-m-d H:i:s'); }
                    if ($status === 'cancelled' && isset($sub_cols['cancelled_at'])) $sets[] = 'cancelled_at = NOW()';
                }
                if ($sets) {
                    $args[] = $cid;
                    $pdo->prepare("UPDATE asso_subscriptions SET " . implode(', ', $sets) . " WHERE id = ?")->execute($args);
                }
            }
            $pdo->commit();
            $after = ak_org_plan_state($pdo, $org_id);
            $msg = "Organisation #{$org_id} : {$before['plan_label']} → {$after['plan_label']} ({$after['badge']}).";
            if ($sid = ak_stripe_left_on_free($pdo, $org_id, $sfree)) {
                $msg .= $sfree ? " Attention : l’abonnement Stripe {$sid} tourne encore, annulez-le dans Stripe."
                               : " Attention : l’abonnement Stripe {$sid} facture encore l’ancienne formule, annulez-le dans Stripe.";
            }
            $_SESSION['flash_admin_subs'] = ['type' => 'success', 'msg' => $msg];
        } elseif ($action === 'mark_overdue') {
            $org_id = (int)($_POST['org_id'] ?? 0);
            if (!$org_exists($org_id)) throw new RuntimeException('Organisation introuvable.');
            if ($is_request($org_id)) throw new RuntimeException("Org #{$org_id} : " . ak_signup_review_block_message());
            $state = ak_org_plan_state($pdo, $org_id);
            if ($state['is_trial'] || $state['trial_expired']) throw new RuntimeException("Org #{$org_id} est en essai gratuit : il n’y a pas de paiement en retard.");
            $cid = $current_sub_id($org_id);
            if (!$cid) throw new RuntimeException("Org #{$org_id} n’a pas d’abonnement.");
            $grace_end = (new DateTime('+15 days'))->format('Y-m-d H:i:s');
            $pdo->prepare("UPDATE asso_subscriptions SET status = 'overdue'" . (isset($sub_cols['grace_period_end']) ? ", grace_period_end = :g" : "") . " WHERE id = :id")
                ->execute((isset($sub_cols['grace_period_end']) ? [':g' => $grace_end] : []) + [':id' => $cid]);
            $_SESSION['flash_admin_subs'] = ['type' => 'success', 'msg' => "Org #{$org_id} marquée 'overdue' — tolérance jusqu'au " . date('d/m/Y', strtotime($grace_end))];
        } elseif ($action === 'downgrade_to_demarrage') {
            $org_id = (int)($_POST['org_id'] ?? 0);
            if (!$org_exists($org_id)) throw new RuntimeException('Organisation introuvable.');
            if ($is_request($org_id)) throw new RuntimeException("Org #{$org_id} : " . ak_signup_review_block_message());
            $demarrage_id = (int)$pdo->query("SELECT id FROM asso_plans WHERE slug='demarrage' LIMIT 1")->fetchColumn();
            if ($demarrage_id <= 0) throw new RuntimeException('Plan Démarrage introuvable.');
            $pdo->beginTransaction();
            ak_org_set_plan($pdo, $org_id, $demarrage_id);
            if (isset($sub_cols['downgraded_at']) && ($cid = $current_sub_id($org_id))) {
                $pdo->prepare("UPDATE asso_subscriptions SET downgraded_at = NOW() WHERE id = ?")->execute([$cid]);
            }
            $pdo->commit();
            $msg = "Org #{$org_id} downgradée au plan Démarrage";
            if ($sid = ak_stripe_left_on_free($pdo, $org_id)) $msg .= " — attention : l’abonnement Stripe {$sid} tourne encore, annulez-le dans Stripe.";
            $_SESSION['flash_admin_subs'] = ['type' => 'success', 'msg' => $msg];
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if (!($e instanceof RuntimeException) || $e instanceof PDOException) error_log('[admin-subscriptions] ' . get_class($e) . ' ' . $e->getMessage());
        $msg = ($e instanceof RuntimeException && !($e instanceof PDOException)) ? $e->getMessage()
             : ($e instanceof InvalidArgumentException ? 'Plan inconnu.' : 'Erreur technique : rien n’a été modifié.');
        $_SESSION['flash_admin_subs'] = ['type' => 'error', 'msg' => $msg];
    }
    header('Location: /admin-subscriptions');
    exit;
}

// === Filtres ===
$q = trim((string)($_GET['q'] ?? ''));
$filter_plan = (int)($_GET['plan_id'] ?? 0);
$filter_status = trim((string)($_GET['status'] ?? ''));

$where = ['1=1'];
$params = [];
if ($q !== '') {
    $where[] = '(o.name LIKE :q OR o.id = :qid)';
    $params[':q'] = '%' . $q . '%';
    $params[':qid'] = (int)$q;
}
if ($filter_plan > 0) {
    $where[] = 's.plan_id = :pid';
    $params[':pid'] = $filter_plan;
}
if ($filter_status !== '') {
    $where[] = 's.status = :st';
    $params[':st'] = $filter_status;
}

$sql = "
    SELECT
      o.id AS org_id, o.name AS org_name, o.slug AS org_slug,
      s.id AS sub_id, s.status, s.started_at, s.current_period_end, s.grace_period_end,
      p.id AS plan_id, p.name AS plan_name, p.slug AS plan_slug, p.price_cents
    FROM organizations o
    LEFT JOIN asso_subscriptions s ON s.id = (
        SELECT s2.id FROM asso_subscriptions s2 JOIN asso_plans p2 ON p2.id = s2.plan_id
        WHERE s2.org_id = o.id ORDER BY " . ak_sub_order('s2') . " LIMIT 1)
    LEFT JOIN asso_plans p ON p.id = s.plan_id
    WHERE " . implode(' AND ', $where) . "
    ORDER BY o.id ASC
    LIMIT 200
";
$st = $pdo->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

$plans_list = $pdo->query("SELECT id, name, slug, price_cents FROM asso_plans ORDER BY display_order ASC")->fetchAll(PDO::FETCH_ASSOC);

render_head('Abonnements — Admin');
render_sidebar('admin-subscriptions');
?>

<main class="main">
  <div class="page-head" style="margin-bottom:24px;">
    <div>
      <h1 style="margin:0 0 4px;">📊 Abonnements actifs</h1>
      <p style="color:#64748b;margin:0;">Gérer les plans des <?= count($rows) ?> organisation<?= count($rows) > 1 ? 's' : '' ?></p>
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
          <option value="<?= $p['id'] ?>" <?= $filter_plan === (int)$p['id'] ? 'selected' : '' ?>><?= htmlspecialchars($p['name']) ?></option>
        <?php endforeach; ?>
      </select>
      <select name="status" style="padding:9px 12px;border:1px solid #E2E8F0;border-radius:8px;">
        <option value="">Tous statuts</option>
        <option value="active" <?= $filter_status === 'active' ? 'selected' : '' ?>>Actif</option>
        <option value="overdue" <?= $filter_status === 'overdue' ? 'selected' : '' ?>>En retard de paiement</option>
        <option value="trial" <?= $filter_status === 'trial' ? 'selected' : '' ?>>Essai</option>
        <option value="cancelled" <?= $filter_status === 'cancelled' ? 'selected' : '' ?>>Annulé</option>
      </select>
      <button type="submit" class="btn btn-primary">Filtrer</button>
      <?php if ($q || $filter_plan || $filter_status): ?>
        <a href="/admin-subscriptions" style="color:#64748B;font-size:13px;">↺ Reset</a>
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
            $statusColors = [
                'active' => ['bg' => '#D1FAE5', 'fg' => '#065F46', 'label' => 'Actif'],
                'overdue' => ['bg' => '#FED7AA', 'fg' => '#9A3412', 'label' => '⏳ En retard'],
                'trial' => ['bg' => '#E0F2FE', 'fg' => '#0C4A6E', 'label' => 'Essai'],
                'cancelled' => ['bg' => '#FEE2E2', 'fg' => '#991B1B', 'label' => 'Annulé'],
                'expired' => ['bg' => '#F1F5F9', 'fg' => '#64748B', 'label' => 'Expiré'],
                'pending_payment' => ['bg' => '#FEF3C7', 'fg' => '#92400E', 'label' => 'En attente'],
            ];
            $sc = $statusColors[$row['status']] ?? ['bg' => '#F1F5F9', 'fg' => '#64748B', 'label' => '—'];
          ?>
            <tr style="border-bottom:1px solid #F1F5F9;">
              <td style="padding:14px 18px;">
                <div style="font-weight:600;color:#0F172A;"><?= htmlspecialchars($row['org_name'] ?? '?') ?></div>
                <div style="color:#64748B;font-size:12px;">#<?= $row['org_id'] ?> · <?= htmlspecialchars($row['org_slug'] ?? '') ?></div>
              </td>
              <td style="padding:14px 12px;">
                <?php if ($row['plan_name']): ?>
                  <strong><?= htmlspecialchars($row['plan_name']) ?></strong>
                  <?php if ($row['price_cents'] > 0): ?>
                    <div style="color:#64748B;font-size:12px;"><?= number_format($row['price_cents']/100, 2, ',', ' ') ?>€/mois</div>
                  <?php endif; ?>
                <?php else: ?>
                  <span style="color:#DC2626;">Aucun plan</span>
                <?php endif; ?>
              </td>
              <td style="padding:14px 12px;">
                <span style="background:<?= $sc['bg'] ?>;color:<?= $sc['fg'] ?>;padding:3px 10px;border-radius:999px;font-size:12px;font-weight:600;"><?= $sc['label'] ?></span>
                <?php if ($row['grace_period_end']): ?>
                  <div style="color:#EA580C;font-size:11px;margin-top:3px;">Grâce → <?= date('d/m/Y', strtotime($row['grace_period_end'])) ?></div>
                <?php endif; ?>
              </td>
              <td style="padding:14px 12px;font-size:13px;">
                <?= $row['current_period_end'] ? date('d/m/Y', strtotime($row['current_period_end'])) : '—' ?>
              </td>
              <td style="padding:14px 12px;font-size:12px;">
                <?php if ($usage): ?>
                  <div>👥 <?= $usage['adherents'] ?> · 📋 <?= $usage['invoices_total'] ?> · 🤖 <?= $usage['ai_text_month'] ?>/mois</div>
                  <div style="color:#64748B;">📨 <?= $usage['emails_month'] ?> emails ce mois</div>
                <?php endif; ?>
              </td>
              <td style="padding:14px 12px;text-align:right;">
                <details style="display:inline-block;text-align:left;">
                  <summary style="cursor:pointer;background:#F1F5F9;padding:6px 12px;border-radius:6px;font-size:12px;list-style:none;">⚙️ Modifier</summary>
                  <div style="position:absolute;background:white;border:1px solid #E2E8F0;border-radius:10px;padding:14px;box-shadow:0 8px 24px rgba(0,0,0,0.08);margin-top:6px;min-width:280px;z-index:10;">
                    <form method="post" style="display:flex;flex-direction:column;gap:8px;">
                      <input type="hidden" name="action" value="change_plan">
                      <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token'] ?? '') ?>">
                      <input type="hidden" name="org_id" value="<?= $row['org_id'] ?>">
                      <label style="font-size:12px;font-weight:600;">Plan</label>
                      <select name="plan_id" style="padding:8px;border:1px solid #E2E8F0;border-radius:6px;">
                        <?php foreach ($plans_list as $p): ?>
                          <option value="<?= $p['id'] ?>" <?= (int)$row['plan_id'] === (int)$p['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($p['name']) ?>
                          </option>
                        <?php endforeach; ?>
                      </select>
                      <label style="font-size:12px;font-weight:600;">Statut <span style="font-weight:400;color:#64748B;">(l’essai vient du plan d’essai)</span></label>
                      <select name="status" style="padding:8px;border:1px solid #E2E8F0;border-radius:6px;">
                        <option value="active" <?= in_array($row['status'], ['active', 'trial'], true) ? 'selected' : '' ?>>Actif</option>
                        <option value="overdue" <?= $row['status'] === 'overdue' ? 'selected' : '' ?>>En retard</option>
                        <option value="cancelled" <?= $row['status'] === 'cancelled' ? 'selected' : '' ?>>Annulé</option>
                      </select>
                      <label style="font-size:12px;font-weight:600;">Fin période</label>
                      <input type="date" name="current_period_end" value="<?= $row['current_period_end'] ? date('Y-m-d', strtotime($row['current_period_end'])) : '' ?>" style="padding:8px;border:1px solid #E2E8F0;border-radius:6px;">
                      <button type="submit" class="btn btn-primary btn-sm">💾 Appliquer</button>
                    </form>
                    <hr style="margin:10px 0;border:none;border-top:1px solid #E2E8F0;">
                    <form method="post" onsubmit="return confirm('Marquer en retard de paiement avec 15j de grâce ?');" style="margin-bottom:6px;">
                      <input type="hidden" name="action" value="mark_overdue">
                      <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token'] ?? '') ?>">
                      <input type="hidden" name="org_id" value="<?= $row['org_id'] ?>">
                      <button type="submit" class="btn btn-sm" style="width:100%;color:#EA580C;">⏳ Marquer en retard (15j)</button>
                    </form>
                    <form method="post" onsubmit="return confirm('Downgrade au plan Démarrage ? L\'org perdra ses fonctionnalités premium.');">
                      <input type="hidden" name="action" value="downgrade_to_demarrage">
                      <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token'] ?? '') ?>">
                      <input type="hidden" name="org_id" value="<?= $row['org_id'] ?>">
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
