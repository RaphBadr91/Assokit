<?php
/**
 * admin-plans.php
 * --------------------------------------------------------------
 * Page admin FONDATEUR : gérer les plans tarifaires
 * Liste · Créer · Modifier · Supprimer · Voir adoption
 * --------------------------------------------------------------
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes-layout.php';
require_once __DIR__ . '/plan-helpers.php';

if (session_status() !== PHP_SESSION_ACTIVE) session_start();

// === Vérification accès fondateur uniquement ===
$user_id = (int)($_SESSION['user_id'] ?? 0);
if (!$user_id) { header('Location: /connexion'); exit; }

$user = $pdo->prepare("SELECT id, email, role, org_id FROM users WHERE id = :id LIMIT 1");
$user->execute([':id' => $user_id]);
$user = $user->fetch(PDO::FETCH_ASSOC);

if (!$user || !in_array($user['role'], ['founder', 'super_admin'], true)) {
    http_response_code(403);
    render_head('Accès refusé');
    render_sidebar('admin-plans');
    echo '<main class="main"><div class="card"><h1>🚫 Accès refusé</h1><p>Cette page est réservée au fondateur.</p></div></main>';
    render_foot();
    exit;
}

$flash = $_SESSION['flash_admin_plans'] ?? null;
unset($_SESSION['flash_admin_plans']);

// === Actions POST : create / update / delete ===
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'create' || $action === 'update') {
            $data = [
                'slug'          => preg_replace('/[^a-z0-9-]/', '', strtolower(trim((string)($_POST['slug'] ?? '')))),
                'name'          => trim((string)($_POST['name'] ?? '')),
                'tagline'       => trim((string)($_POST['tagline'] ?? '')),
                'price_cents'   => (int)round(((float)($_POST['price_eur'] ?? 0)) * 100),
                'price_label'   => trim((string)($_POST['price_label'] ?? '')) ?: null,
                'is_custom_quote' => !empty($_POST['is_custom_quote']) ? 1 : 0,
                'limit_adherents'        => $_POST['limit_adherents'] === '' ? null : (int)$_POST['limit_adherents'],
                'limit_invoices_total'   => $_POST['limit_invoices_total'] === '' ? null : (int)$_POST['limit_invoices_total'],
                'limit_quotes_total'     => $_POST['limit_quotes_total'] === '' ? null : (int)$_POST['limit_quotes_total'],
                'limit_contacts'         => $_POST['limit_contacts'] === '' ? null : (int)$_POST['limit_contacts'],
                'limit_users'            => $_POST['limit_users'] === '' ? null : (int)$_POST['limit_users'],
                'limit_ai_text_per_month'  => $_POST['limit_ai_text_per_month'] === '' ? null : (int)$_POST['limit_ai_text_per_month'],
                'limit_ai_image_per_month' => $_POST['limit_ai_image_per_month'] === '' ? null : (int)$_POST['limit_ai_image_per_month'],
                'limit_emails_per_month'   => $_POST['limit_emails_per_month'] === '' ? null : (int)$_POST['limit_emails_per_month'],
                'bonus_ai_first_week'      => (int)($_POST['bonus_ai_first_week'] ?? 0),
                'feature_recurring_invoices' => !empty($_POST['feature_recurring_invoices']) ? 1 : 0,
                'feature_signature_quotes'   => !empty($_POST['feature_signature_quotes']) ? 1 : 0,
                'feature_email_diffusion'    => !empty($_POST['feature_email_diffusion']) ? 1 : 0,
                'feature_advanced_stats'     => !empty($_POST['feature_advanced_stats']) ? 1 : 0,
                'feature_priority_support'   => !empty($_POST['feature_priority_support']) ? 1 : 0,
                'feature_custom_domain'      => !empty($_POST['feature_custom_domain']) ? 1 : 0,
                'feature_dedicated_support'  => !empty($_POST['feature_dedicated_support']) ? 1 : 0,
                'is_featured' => !empty($_POST['is_featured']) ? 1 : 0,
                'is_visible'  => !empty($_POST['is_visible']) ? 1 : 0,
                'display_order' => (int)($_POST['display_order'] ?? 0),
            ];

            if ($data['slug'] === '' || $data['name'] === '') throw new Exception('Slug et nom obligatoires');

            if ($action === 'create') {
                $cols = array_keys($data);
                $placeholders = ':' . implode(', :', $cols);
                $sql = "INSERT INTO asso_plans (" . implode(', ', $cols) . ") VALUES ({$placeholders})";
                $st = $pdo->prepare($sql);
                $params = [];
                foreach ($data as $k => $v) $params[':' . $k] = $v;
                $st->execute($params);
                $_SESSION['flash_admin_plans'] = ['type' => 'success', 'msg' => "Plan '{$data['name']}' créé avec succès"];
            } else {
                $plan_id = (int)$_POST['plan_id'];
                if ($plan_id <= 0) throw new Exception('ID invalide');
                $set = [];
                $params = [':id' => $plan_id];
                foreach ($data as $k => $v) {
                    $set[] = "{$k} = :{$k}";
                    $params[':' . $k] = $v;
                }
                $sql = "UPDATE asso_plans SET " . implode(', ', $set) . " WHERE id = :id";
                $st = $pdo->prepare($sql);
                $st->execute($params);
                $_SESSION['flash_admin_plans'] = ['type' => 'success', 'msg' => "Plan '{$data['name']}' mis à jour"];
            }
        } elseif ($action === 'delete') {
            $plan_id = (int)$_POST['plan_id'];
            // Vérifier qu'aucun abonnement actif ne l'utilise
            $st = $pdo->prepare("SELECT COUNT(*) FROM asso_subscriptions WHERE plan_id = :p");
            $st->execute([':p' => $plan_id]);
            if ((int)$st->fetchColumn() > 0) {
                throw new Exception("Impossible : ce plan est utilisé par une ou plusieurs organisations");
            }
            $pdo->prepare("DELETE FROM asso_plans WHERE id = :id")->execute([':id' => $plan_id]);
            $_SESSION['flash_admin_plans'] = ['type' => 'success', 'msg' => 'Plan supprimé'];
        }
    } catch (Throwable $e) {
        $_SESSION['flash_admin_plans'] = ['type' => 'error', 'msg' => $e->getMessage()];
    }
    header('Location: /admin-plans');
    exit;
}

// === Récupération des plans ===
$plans = $pdo->query("
    SELECT p.*,
      (SELECT COUNT(*) FROM asso_subscriptions s WHERE s.plan_id = p.id AND s.status = 'active') AS active_subs
    FROM asso_plans p
    ORDER BY p.display_order ASC, p.id ASC
")->fetchAll(PDO::FETCH_ASSOC);

// Plan en édition ?
$edit_id = (int)($_GET['edit'] ?? 0);
$editing = null;
if ($edit_id > 0) {
    foreach ($plans as $p) if ((int)$p['id'] === $edit_id) { $editing = $p; break; }
}

render_head('Plans tarifaires — Admin');
render_sidebar('admin-plans');
?>

<main class="main">
  <div class="page-head" style="margin-bottom:24px;">
    <div>
      <h1 style="margin:0 0 4px;">💼 Plans tarifaires</h1>
      <p style="color:#64748b;margin:0;">Gérer le catalogue des offres Assokit</p>
    </div>
    <button onclick="document.getElementById('plan-form').scrollIntoView({behavior:'smooth'});document.querySelector('#plan-form input[name=name]').focus()" class="btn btn-primary">
      ➕ Nouveau plan
    </button>
  </div>

  <?php if ($flash): ?>
    <div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'danger' ?>" style="margin-bottom:18px;">
      <?= htmlspecialchars($flash['msg'], ENT_QUOTES, 'UTF-8') ?>
    </div>
  <?php endif; ?>

  <!-- LISTE DES PLANS -->
  <div class="card" style="padding:0;overflow:hidden;margin-bottom:24px;">
    <div style="overflow-x:auto;">
      <table style="width:100%;border-collapse:collapse;font-size:14px;min-width:900px;">
        <thead style="background:#F8FAFC;border-bottom:1px solid #E2E8F0;">
          <tr style="text-align:left;">
            <th style="padding:14px 18px;font-weight:600;">Plan</th>
            <th style="padding:14px 12px;font-weight:600;">Prix</th>
            <th style="padding:14px 12px;font-weight:600;text-align:center;">Adhérents</th>
            <th style="padding:14px 12px;font-weight:600;text-align:center;">Factures</th>
            <th style="padding:14px 12px;font-weight:600;text-align:center;">IA texte/mois</th>
            <th style="padding:14px 12px;font-weight:600;text-align:center;">Emails/mois</th>
            <th style="padding:14px 12px;font-weight:600;text-align:center;">Adoption</th>
            <th style="padding:14px 12px;font-weight:600;text-align:right;">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($plans as $p): ?>
            <tr style="border-bottom:1px solid #F1F5F9;<?= $p['is_featured'] ? 'background:#F0FDF4;' : '' ?>">
              <td style="padding:14px 18px;">
                <div style="font-weight:600;color:#0F172A;">
                  <?= htmlspecialchars($p['name'], ENT_QUOTES, 'UTF-8') ?>
                  <?= $p['is_featured'] ? ' <span style="color:#059669;">⭐</span>' : '' ?>
                  <?= !$p['is_visible'] ? ' <span style="color:#94A3B8;font-size:12px;">(masqué)</span>' : '' ?>
                </div>
                <div style="color:#94A3B8;font-size:12px;">slug: <code><?= htmlspecialchars($p['slug'], ENT_QUOTES, 'UTF-8') ?></code></div>
              </td>
              <td style="padding:14px 12px;">
                <?php if ($p['is_custom_quote']): ?>
                  <span style="color:#475569;">Sur devis</span>
                <?php elseif ($p['price_cents'] == 0): ?>
                  <span style="color:#059669;font-weight:600;">Gratuit</span>
                <?php else: ?>
                  <strong><?= number_format($p['price_cents'] / 100, 2, ',', ' ') ?> €</strong>
                  <span style="color:#94A3B8;font-size:12px;">/mois</span>
                <?php endif; ?>
              </td>
              <td style="padding:14px 12px;text-align:center;"><?= $p['limit_adherents'] === null ? '∞' : $p['limit_adherents'] ?></td>
              <td style="padding:14px 12px;text-align:center;"><?= $p['limit_invoices_total'] === null ? '∞' : $p['limit_invoices_total'] ?></td>
              <td style="padding:14px 12px;text-align:center;">
                <?= $p['limit_ai_text_per_month'] === null ? '∞' : $p['limit_ai_text_per_month'] ?>
                <?php if ($p['bonus_ai_first_week'] > 0): ?>
                  <div style="color:#7E22CE;font-size:11px;">+<?= $p['bonus_ai_first_week'] ?> semaine 1</div>
                <?php endif; ?>
              </td>
              <td style="padding:14px 12px;text-align:center;">
                <?php if (!$p['feature_email_diffusion']): ?><span style="color:#DC2626;">🔒</span><?php else: ?><?= $p['limit_emails_per_month'] === null ? '∞' : $p['limit_emails_per_month'] ?><?php endif; ?>
              </td>
              <td style="padding:14px 12px;text-align:center;">
                <span style="background:#E0F2FE;color:#0C4A6E;padding:3px 10px;border-radius:999px;font-weight:600;font-size:12px;">
                  <?= (int)$p['active_subs'] ?> org.
                </span>
              </td>
              <td style="padding:14px 12px;text-align:right;">
                <a href="?edit=<?= $p['id'] ?>" class="btn btn-sm" style="margin-right:4px;">✏️ Éditer</a>
                <?php if ((int)$p['active_subs'] === 0): ?>
                  <form method="post" style="display:inline;" onsubmit="return confirm('Supprimer définitivement ce plan ?');">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="plan_id" value="<?= $p['id'] ?>">
                    <button type="submit" class="btn btn-sm" style="color:#DC2626;">🗑</button>
                  </form>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- FORMULAIRE CRÉATION / ÉDITION -->
  <div class="card" id="plan-form">
    <h2 style="margin-top:0;font-size:18px;">
      <?= $editing ? '✏️ Modifier le plan : ' . htmlspecialchars($editing['name']) : '➕ Créer un nouveau plan' ?>
    </h2>

    <form method="post" style="display:grid;gap:16px;">
      <input type="hidden" name="action" value="<?= $editing ? 'update' : 'create' ?>">
      <?php if ($editing): ?>
        <input type="hidden" name="plan_id" value="<?= $editing['id'] ?>">
      <?php endif; ?>

      <!-- Identité -->
      <fieldset style="border:1px solid #E2E8F0;border-radius:10px;padding:18px;">
        <legend style="font-weight:600;padding:0 8px;">Identité</legend>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
          <div>
            <label style="display:block;font-size:13px;font-weight:600;margin-bottom:4px;">Nom *</label>
            <input type="text" name="name" required value="<?= htmlspecialchars($editing['name'] ?? '') ?>" style="width:100%;padding:10px;border:1px solid #E2E8F0;border-radius:8px;">
          </div>
          <div>
            <label style="display:block;font-size:13px;font-weight:600;margin-bottom:4px;">Slug * (a-z, 0-9, -)</label>
            <input type="text" name="slug" required pattern="[a-z0-9\-]+" value="<?= htmlspecialchars($editing['slug'] ?? '') ?>" style="width:100%;padding:10px;border:1px solid #E2E8F0;border-radius:8px;">
          </div>
        </div>
        <div style="margin-top:12px;">
          <label style="display:block;font-size:13px;font-weight:600;margin-bottom:4px;">Tagline</label>
          <input type="text" name="tagline" value="<?= htmlspecialchars($editing['tagline'] ?? '') ?>" style="width:100%;padding:10px;border:1px solid #E2E8F0;border-radius:8px;">
        </div>
      </fieldset>

      <!-- Prix -->
      <fieldset style="border:1px solid #E2E8F0;border-radius:10px;padding:18px;">
        <legend style="font-weight:600;padding:0 8px;">Prix</legend>
        <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;">
          <div>
            <label style="display:block;font-size:13px;font-weight:600;margin-bottom:4px;">Prix mensuel TTC (€)</label>
            <input type="number" step="0.01" min="0" name="price_eur" value="<?= $editing ? number_format($editing['price_cents'] / 100, 2, '.', '') : '0' ?>" style="width:100%;padding:10px;border:1px solid #E2E8F0;border-radius:8px;">
          </div>
          <div>
            <label style="display:block;font-size:13px;font-weight:600;margin-bottom:4px;">Label spécial</label>
            <input type="text" name="price_label" placeholder="Gratuit, Sur devis…" value="<?= htmlspecialchars($editing['price_label'] ?? '') ?>" style="width:100%;padding:10px;border:1px solid #E2E8F0;border-radius:8px;">
          </div>
          <div style="display:flex;align-items:end;padding-bottom:8px;">
            <label style="display:flex;align-items:center;gap:8px;font-size:13px;">
              <input type="checkbox" name="is_custom_quote" <?= !empty($editing['is_custom_quote']) ? 'checked' : '' ?>>
              <span>Sur devis (pas de paiement direct)</span>
            </label>
          </div>
        </div>
      </fieldset>

      <!-- Limites quotas -->
      <fieldset style="border:1px solid #E2E8F0;border-radius:10px;padding:18px;">
        <legend style="font-weight:600;padding:0 8px;">Limites & quotas <span style="color:#94A3B8;font-weight:400;font-size:12px;">(vide = illimité, 0 = bloqué)</span></legend>
        <div style="display:grid;grid-template-columns:repeat(4, 1fr);gap:12px;">
          <?php
          $fields = [
              'limit_adherents' => 'Adhérents',
              'limit_users' => 'Utilisateurs',
              'limit_invoices_total' => 'Factures (total)',
              'limit_quotes_total' => 'Devis (total)',
              'limit_contacts' => 'Contacts',
              'limit_ai_text_per_month' => 'IA texte/mois',
              'limit_ai_image_per_month' => 'IA image/mois',
              'limit_emails_per_month' => 'Emails/mois',
          ];
          foreach ($fields as $field => $label):
              $val = $editing[$field] ?? '';
          ?>
            <div>
              <label style="display:block;font-size:12px;font-weight:600;margin-bottom:4px;"><?= $label ?></label>
              <input type="number" min="0" name="<?= $field ?>" value="<?= $val === null ? '' : htmlspecialchars((string)$val) ?>" placeholder="∞" style="width:100%;padding:8px;border:1px solid #E2E8F0;border-radius:8px;font-size:13px;">
            </div>
          <?php endforeach; ?>
        </div>
        <div style="margin-top:12px;">
          <label style="display:block;font-size:13px;font-weight:600;margin-bottom:4px;">Bonus IA texte 1ère semaine (cumulé au quota mensuel)</label>
          <input type="number" min="0" name="bonus_ai_first_week" value="<?= htmlspecialchars((string)($editing['bonus_ai_first_week'] ?? 0)) ?>" style="width:200px;padding:8px;border:1px solid #E2E8F0;border-radius:8px;">
        </div>
      </fieldset>

      <!-- Features -->
      <fieldset style="border:1px solid #E2E8F0;border-radius:10px;padding:18px;">
        <legend style="font-weight:600;padding:0 8px;">Fonctionnalités incluses</legend>
        <div style="display:grid;grid-template-columns:repeat(3, 1fr);gap:8px;">
          <?php
          $features = [
              'feature_recurring_invoices' => '🔄 Factures récurrentes',
              'feature_signature_quotes' => '✍️ Signature devis',
              'feature_email_diffusion' => '📨 Diffusion email',
              'feature_advanced_stats' => '📊 Stats avancées',
              'feature_priority_support' => '⚡ Support prioritaire',
              'feature_custom_domain' => '🌐 Domaine perso',
              'feature_dedicated_support' => '👤 Support dédié',
          ];
          foreach ($features as $key => $label):
              $checked = !empty($editing[$key]);
          ?>
            <label style="display:flex;align-items:center;gap:8px;padding:8px 12px;background:#F8FAFC;border-radius:8px;font-size:13px;cursor:pointer;">
              <input type="checkbox" name="<?= $key ?>" <?= $checked ? 'checked' : '' ?>>
              <span><?= $label ?></span>
            </label>
          <?php endforeach; ?>
        </div>
      </fieldset>

      <!-- Affichage -->
      <fieldset style="border:1px solid #E2E8F0;border-radius:10px;padding:18px;">
        <legend style="font-weight:600;padding:0 8px;">Affichage</legend>
        <div style="display:grid;grid-template-columns:repeat(3, 1fr);gap:12px;">
          <label style="display:flex;align-items:center;gap:8px;font-size:13px;">
            <input type="checkbox" name="is_featured" <?= !empty($editing['is_featured']) ? 'checked' : '' ?>>
            <span>⭐ Mis en avant</span>
          </label>
          <label style="display:flex;align-items:center;gap:8px;font-size:13px;">
            <input type="checkbox" name="is_visible" <?= !isset($editing['is_visible']) || !empty($editing['is_visible']) ? 'checked' : '' ?>>
            <span>👁 Visible publiquement</span>
          </label>
          <div>
            <label style="display:block;font-size:12px;font-weight:600;margin-bottom:4px;">Ordre d'affichage</label>
            <input type="number" name="display_order" value="<?= htmlspecialchars((string)($editing['display_order'] ?? 0)) ?>" style="width:100%;padding:8px;border:1px solid #E2E8F0;border-radius:8px;">
          </div>
        </div>
      </fieldset>

      <div style="display:flex;gap:10px;">
        <button type="submit" class="btn btn-primary"><?= $editing ? '💾 Mettre à jour' : '➕ Créer le plan' ?></button>
        <?php if ($editing): ?>
          <a href="/admin-plans" class="btn">Annuler</a>
        <?php endif; ?>
      </div>
    </form>
  </div>
</main>

<?php render_foot(); ?>
