<?php
/**
 * admin-subscriptions.php
 * --------------------------------------------------------------
 * Page admin FONDATEUR : assigner / changer les plans des organisations
 * --------------------------------------------------------------
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes-layout.php';
require_once __DIR__ . '/plan-helpers.php';

if (session_status() !== PHP_SESSION_ACTIVE) session_start();

$user_id = (int)($_SESSION['user_id'] ?? 0);
if (!$user_id) { header('Location: /connexion'); exit; }

$user = $pdo->prepare("SELECT id, email, role, org_id FROM users WHERE id = :id LIMIT 1");
$user->execute([':id' => $user_id]);
$user = $user->fetch(PDO::FETCH_ASSOC);

if (!$user || !in_array($user['role'], ['founder', 'super_admin'], true)) {
    http_response_code(403);
    render_head('Accès refusé');
    render_sidebar('admin-subscriptions');
    echo '<main class="main"><div class="card"><h1>🚫 Accès refusé</h1></div></main>';
    render_foot();
    exit;
}

$flash = $_SESSION['flash_admin_subs'] ?? null;
unset($_SESSION['flash_admin_subs']);

// === Actions ===
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'change_plan') {
            $org_id = (int)$_POST['org_id'];
            $plan_id = (int)$_POST['plan_id'];
            $status = (string)($_POST['status'] ?? 'active');
            $period_end = trim((string)($_POST['current_period_end'] ?? ''));
            $period_end = $period_end !== '' ? $period_end : null;

            // Récupère le plan actuel pour le mettre en previous_plan_id
            $cur = $pdo->prepare("SELECT plan_id FROM asso_subscriptions WHERE org_id = :o LIMIT 1");
            $cur->execute([':o' => $org_id]);
            $previous = $cur->fetchColumn();

            $st = $pdo->prepare("
                INSERT INTO asso_subscriptions (org_id, plan_id, status, started_at, current_period_end, previous_plan_id)
                VALUES (:o, :p, :s, NOW(), :e, :prev)
                ON DUPLICATE KEY UPDATE
                    plan_id = VALUES(plan_id),
                    status = VALUES(status),
                    current_period_end = VALUES(current_period_end),
                    previous_plan_id = VALUES(previous_plan_id),
                    grace_period_end = NULL,
                    downgraded_at = NULL,
                    cancelled_at = NULL,
                    updated_at = NOW()
            ");
            $st->execute([
                ':o' => $org_id,
                ':p' => $plan_id,
                ':s' => $status,
                ':e' => $period_end,
                ':prev' => $previous ?: null,
            ]);
            $_SESSION['flash_admin_subs'] = ['type' => 'success', 'msg' => "Plan changé pour l'organisation #{$org_id}"];
        } elseif ($action === 'mark_overdue') {
            $org_id = (int)$_POST['org_id'];
            $grace_end = (new DateTime('+15 days'))->format('Y-m-d H:i:s');
            $pdo->prepare("UPDATE asso_subscriptions SET status='overdue', grace_period_end=:g WHERE org_id=:o")
                ->execute([':g' => $grace_end, ':o' => $org_id]);
            $_SESSION['flash_admin_subs'] = ['type' => 'success', 'msg' => "Org #{$org_id} marquée 'overdue' — tolérance jusqu'au {$grace_end}"];
        } elseif ($action === 'downgrade_to_demarrage') {
            $org_id = (int)$_POST['org_id'];
            $demarrage_id = (int)$pdo->query("SELECT id FROM asso_plans WHERE slug='demarrage' LIMIT 1")->fetchColumn();
            $pdo->prepare("
                UPDATE asso_subscriptions
                SET plan_id = :p, status = 'active', grace_period_end = NULL,
                    downgraded_at = NOW(), updated_at = NOW()
                WHERE org_id = :o
            ")->execute([':p' => $demarrage_id, ':o' => $org_id]);
            $_SESSION['flash_admin_subs'] = ['type' => 'success', 'msg' => "Org #{$org_id} downgradée au plan Démarrage"];
        }
    } catch (Throwable $e) {
        $_SESSION['flash_admin_subs'] = ['type' => 'error', 'msg' => $e->getMessage()];
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
    LEFT JOIN asso_subscriptions s ON s.org_id = o.id
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
    <a href="/admin-plans" class="btn">⚙️ Gérer les plans</a>
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
                <div style="color:#94A3B8;font-size:12px;">#<?= $row['org_id'] ?> · <?= htmlspecialchars($row['org_slug'] ?? '') ?></div>
              </td>
              <td style="padding:14px 12px;">
                <?php if ($row['plan_name']): ?>
                  <strong><?= htmlspecialchars($row['plan_name']) ?></strong>
                  <?php if ($row['price_cents'] > 0): ?>
                    <div style="color:#94A3B8;font-size:12px;"><?= number_format($row['price_cents']/100, 2, ',', ' ') ?>€/mois</div>
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
                  <div style="color:#94A3B8;">📨 <?= $usage['emails_month'] ?> emails ce mois</div>
                <?php endif; ?>
              </td>
              <td style="padding:14px 12px;text-align:right;">
                <details style="display:inline-block;text-align:left;">
                  <summary style="cursor:pointer;background:#F1F5F9;padding:6px 12px;border-radius:6px;font-size:12px;list-style:none;">⚙️ Modifier</summary>
                  <div style="position:absolute;background:white;border:1px solid #E2E8F0;border-radius:10px;padding:14px;box-shadow:0 8px 24px rgba(0,0,0,0.08);margin-top:6px;min-width:280px;z-index:10;">
                    <form method="post" style="display:flex;flex-direction:column;gap:8px;">
                      <input type="hidden" name="action" value="change_plan">
                      <input type="hidden" name="org_id" value="<?= $row['org_id'] ?>">
                      <label style="font-size:12px;font-weight:600;">Plan</label>
                      <select name="plan_id" style="padding:8px;border:1px solid #E2E8F0;border-radius:6px;">
                        <?php foreach ($plans_list as $p): ?>
                          <option value="<?= $p['id'] ?>" <?= (int)$row['plan_id'] === (int)$p['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($p['name']) ?>
                          </option>
                        <?php endforeach; ?>
                      </select>
                      <label style="font-size:12px;font-weight:600;">Statut</label>
                      <select name="status" style="padding:8px;border:1px solid #E2E8F0;border-radius:6px;">
                        <option value="active" <?= $row['status'] === 'active' ? 'selected' : '' ?>>Actif</option>
                        <option value="trial" <?= $row['status'] === 'trial' ? 'selected' : '' ?>>Essai</option>
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
                      <input type="hidden" name="org_id" value="<?= $row['org_id'] ?>">
                      <button type="submit" class="btn btn-sm" style="width:100%;color:#EA580C;">⏳ Marquer en retard (15j)</button>
                    </form>
                    <form method="post" onsubmit="return confirm('Downgrade au plan Démarrage ? L\'org perdra ses fonctionnalités premium.');">
                      <input type="hidden" name="action" value="downgrade_to_demarrage">
                      <input type="hidden" name="org_id" value="<?= $row['org_id'] ?>">
                      <button type="submit" class="btn btn-sm" style="width:100%;color:#DC2626;">↓ Downgrade Démarrage</button>
                    </form>
                  </div>
                </details>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if (empty($rows)): ?>
            <tr><td colspan="6" style="padding:40px;text-align:center;color:#94A3B8;">Aucune organisation trouvée</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</main>

<?php render_foot(); ?>
