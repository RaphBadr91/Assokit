<?php
/**
 * Page Paramètres côté mairie
 * URL : /mairie-parametres
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes-layout.php';
require_once __DIR__ . '/includes-permissions.php';
require_login();
require_parent_org_user();

$current = current_user();
$parent_org = current_parent_org();
$parent_org_id = (int)$parent_org['id'];
$user_id = (int)$current['id'];
$is_mairie_admin = (($current['parent_org_role'] ?? '') === 'admin') || is_platform_admin($current);

if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$csrf = $_SESSION['csrf_token'];

// Recharger les données fraiches de la mairie
$stmt = $pdo->prepare("SELECT * FROM parent_orgs WHERE id = ?");
$stmt->execute([$parent_org_id]);
$mairie = $stmt->fetch(PDO::FETCH_ASSOC) ?: $parent_org;

// Quota / nb asso
$stmt = $pdo->prepare("SELECT COUNT(*) FROM organizations WHERE parent_org_id = ?");
$stmt->execute([$parent_org_id]);
$nb_orgs = (int)$stmt->fetchColumn();
$quota = (int)$mairie['validated_quota'];

// Agents de la mairie
$stmt = $pdo->prepare("
    SELECT id, first_name, last_name, email, parent_org_role, last_login_at, created_at
    FROM users
    WHERE parent_org_id = ? AND deleted_at IS NULL
    ORDER BY id ASC
");
$stmt->execute([$parent_org_id]);
$agents = $stmt->fetchAll(PDO::FETCH_ASSOC);

$TYPE_LABELS = [
    'mairie' => 'Mairie', 'departement' => 'Département', 'region' => 'Région',
    'drac' => 'DRAC', 'caf' => 'CAF', 'federation' => 'Fédération', 'autre' => 'Autre',
];

render_head('Paramètres · Mairie');
render_sidebar('parametres');
?>

<style>
.set-page { padding: 20px 28px 40px; max-width: 1000px; margin: 0 auto; }
.set-page h1 { font-size: 1.5rem; margin: 0 0 6px; }
.set-page .lead { color: #6a6f76; font-size: .95rem; margin: 0 0 24px; }

.set-card { background: #fff; border: 1px solid #e9ecef; border-radius: 14px; padding: 24px; margin-bottom: 22px; box-shadow: 0 1px 3px rgba(0,0,0,.04); }
.set-card h2 { font-size: 1.1rem; margin: 0 0 4px; display: flex; align-items: center; gap: 8px; }
.set-card .card-desc { color: #6b7280; font-size: .85rem; margin: 0 0 18px; }

.set-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
.set-grid .full { grid-column: 1 / -1; }
.set-field label { display: block; font-weight: 600; font-size: .82rem; color: #374151; margin-bottom: 5px; }
.set-field input, .set-field select { width: 100%; padding: 9px 13px; border: 1px solid #d1d5db; border-radius: 8px; font-size: .9rem; font-family: inherit; box-sizing: border-box; outline: none; transition: border-color .15s; }
.set-field input:focus, .set-field select:focus { border-color: #f59e0b; box-shadow: 0 0 0 3px rgba(245,158,11,.12); }
.set-field input:disabled { background: #f3f4f6; color: #6b7280; cursor: not-allowed; }

.set-actions { display: flex; justify-content: flex-end; padding-top: 16px; margin-top: 8px; border-top: 1px solid #f3f4f6; }
.set-btn { padding: 10px 22px; border-radius: 10px; font-weight: 600; cursor: pointer; font-size: .9rem; border: 0; transition: transform .1s; }
.set-btn.primary { background: linear-gradient(135deg, #1a1a1a 0%, #2d2d2d 100%); color: #fff; }
.set-btn.primary:hover { transform: translateY(-1px); }
.set-btn.ghost { background: #fff; border: 1px solid #d1d5db; color: #374151; }
.set-btn.danger { background: #fee2e2; color: #991b1b; }
.set-btn.danger:hover { background: #fecaca; }

.set-quota-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 14px; }
.set-quota-stat { background: #f9fafb; border-radius: 10px; padding: 16px; text-align: center; }
.set-quota-stat .num { font-size: 1.6rem; font-weight: 700; color: #1a1a1a; }
.set-quota-stat .lbl { font-size: .78rem; color: #6b7280; margin-top: 4px; }
.set-quota-bar { height: 8px; background: #e5e7eb; border-radius: 99px; margin-top: 14px; overflow: hidden; }
.set-quota-bar > div { height: 100%; background: linear-gradient(90deg, #34d399, #059669); border-radius: 99px; }

.set-agents-table { width: 100%; border-collapse: collapse; }
.set-agents-table th, .set-agents-table td { padding: 11px 12px; text-align: left; border-bottom: 1px solid #f3f4f6; font-size: .87rem; }
.set-agents-table th { font-size: .72rem; color: #6b7280; text-transform: uppercase; letter-spacing: .4px; background: #f9fafb; }
.set-role-badge { padding: 2px 9px; border-radius: 10px; font-size: .7rem; font-weight: 700; text-transform: uppercase; }
.set-role-badge.admin { background: #fef3c7; color: #92400e; }
.set-role-badge.agent { background: #e0e7ff; color: #3730a3; }

.set-flash { padding: 12px 18px; border-radius: 10px; margin-bottom: 18px; font-size: .92rem; }
.set-flash.success { background: #d1fae5; color: #065f46; }
.set-flash.error { background: #fee2e2; color: #991b1b; }

.set-status-pill { display:inline-block; padding: 3px 12px; border-radius: 12px; font-size: .76rem; font-weight: 700; }
.set-status-pill.active { background:#d1fae5; color:#065f46; }
.set-status-pill.suspended { background:#fee2e2; color:#991b1b; }

.set-addagent-row { display: grid; grid-template-columns: 1fr 1fr 1.4fr auto auto; gap: 8px; align-items: end; margin-top: 14px; padding-top: 16px; border-top: 1px dashed #e5e7eb; }

@media (max-width: 768px) {
  .set-page { padding: 14px; }
  .set-grid, .set-quota-grid, .set-addagent-row { grid-template-columns: 1fr; }
}
</style>

<div class="set-page">
  <h1>⚙️ Paramètres</h1>
  <p class="lead">Gérez les informations de votre collectivité, vos agents et votre compte.</p>

  <?php if (!empty($_SESSION['flash_success'])): ?>
    <div class="set-flash success">✅ <?= h($_SESSION['flash_success']) ?></div>
    <?php unset($_SESSION['flash_success']); ?>
  <?php endif; ?>
  <?php if (!empty($_SESSION['flash_error'])): ?>
    <div class="set-flash error">❌ <?= h($_SESSION['flash_error']) ?></div>
    <?php unset($_SESSION['flash_error']); ?>
  <?php endif; ?>

  <!-- IDENTITÉ + COORDONNÉES -->
  <div class="set-card">
    <h2>🏛️ Identité de la collectivité</h2>
    <p class="card-desc">Ces informations apparaissent dans vos communications avec les associations.</p>

    <form method="POST" action="/action-mairie-settings">
      <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
      <input type="hidden" name="action" value="update_profile">

      <div class="set-grid">
        <div class="set-field full">
          <label>Nom de la collectivité</label>
          <input type="text" name="name" value="<?= h($mairie['name']) ?>" maxlength="255" required <?= $is_mairie_admin ? '' : 'disabled' ?>>
        </div>
        <div class="set-field">
          <label>Type</label>
          <select name="type" <?= $is_mairie_admin ? '' : 'disabled' ?>>
            <?php foreach ($TYPE_LABELS as $val => $lbl): ?>
              <option value="<?= $val ?>" <?= $mairie['type'] === $val ? 'selected' : '' ?>><?= $lbl ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="set-field">
          <label>SIRET</label>
          <input type="text" name="siret" value="<?= h($mairie['siret']) ?>" maxlength="20" <?= $is_mairie_admin ? '' : 'disabled' ?>>
        </div>

        <div class="set-field">
          <label>Contact — Prénom</label>
          <input type="text" name="contact_first_name" value="<?= h($mairie['contact_first_name']) ?>" maxlength="100" <?= $is_mairie_admin ? '' : 'disabled' ?>>
        </div>
        <div class="set-field">
          <label>Contact — Nom</label>
          <input type="text" name="contact_last_name" value="<?= h($mairie['contact_last_name']) ?>" maxlength="100" <?= $is_mairie_admin ? '' : 'disabled' ?>>
        </div>
        <div class="set-field">
          <label>Email de contact</label>
          <input type="email" name="contact_email" value="<?= h($mairie['contact_email']) ?>" maxlength="255" <?= $is_mairie_admin ? '' : 'disabled' ?>>
        </div>
        <div class="set-field">
          <label>Téléphone</label>
          <input type="text" name="contact_phone" value="<?= h($mairie['contact_phone']) ?>" maxlength="30" <?= $is_mairie_admin ? '' : 'disabled' ?>>
        </div>

        <div class="set-field full">
          <label>Adresse</label>
          <input type="text" name="address_street" value="<?= h($mairie['address_street']) ?>" maxlength="255" placeholder="N° et rue" <?= $is_mairie_admin ? '' : 'disabled' ?>>
        </div>
        <div class="set-field">
          <label>Code postal</label>
          <input type="text" name="address_zip" value="<?= h($mairie['address_zip']) ?>" maxlength="10" <?= $is_mairie_admin ? '' : 'disabled' ?>>
        </div>
        <div class="set-field">
          <label>Ville</label>
          <input type="text" name="address_city" value="<?= h($mairie['address_city']) ?>" maxlength="100" <?= $is_mairie_admin ? '' : 'disabled' ?>>
        </div>
        <div class="set-field">
          <label>Département</label>
          <input type="text" name="department" value="<?= h($mairie['department']) ?>" maxlength="100" <?= $is_mairie_admin ? '' : 'disabled' ?>>
        </div>
        <div class="set-field">
          <label>Région</label>
          <input type="text" name="region" value="<?= h($mairie['region']) ?>" maxlength="100" <?= $is_mairie_admin ? '' : 'disabled' ?>>
        </div>
      </div>

      <?php if ($is_mairie_admin): ?>
      <div class="set-actions">
        <button type="submit" class="set-btn primary">Enregistrer les modifications</button>
      </div>
      <?php else: ?>
      <p style="color:#9ca3af;font-size:.82rem;margin-top:14px;">Seuls les administrateurs de la collectivité peuvent modifier ces informations.</p>
      <?php endif; ?>
    </form>
  </div>

  <!-- ABONNEMENT / QUOTA -->
  <div class="set-card">
    <h2>📦 Abonnement</h2>
    <p class="card-desc">Votre pack d'associations et le statut de votre compte.</p>

    <div class="set-quota-grid">
      <div class="set-quota-stat">
        <div class="num"><?= $nb_orgs ?></div>
        <div class="lbl">Associations liées</div>
      </div>
      <div class="set-quota-stat">
        <div class="num"><?= $quota > 0 ? $quota : '∞' ?></div>
        <div class="lbl">Quota du pack</div>
      </div>
      <div class="set-quota-stat">
        <div class="num"><span class="set-status-pill <?= h($mairie['status']) ?>"><?= $mairie['status'] === 'active' ? 'Actif' : h($mairie['status']) ?></span></div>
        <div class="lbl">Statut</div>
      </div>
    </div>
    <?php if ($quota > 0): ?>
      <div class="set-quota-bar"><div style="width: <?= min(100, round($nb_orgs / $quota * 100)) ?>%;"></div></div>
    <?php endif; ?>
    <p style="color:#9ca3af;font-size:.8rem;margin-top:14px;margin-bottom:0;">Pour modifier votre quota ou votre abonnement, contactez Assokit à <a href="mailto:contact@assokit.fr" style="color:#059669;">contact@assokit.fr</a>.</p>
  </div>

  <!-- AGENTS -->
  <div class="set-card">
    <h2>👥 Agents de la collectivité</h2>
    <p class="card-desc"><?= count($agents) ?> agent<?= count($agents) > 1 ? 's' : '' ?> ayant accès à cet espace.</p>

    <div style="overflow-x:auto;">
      <table class="set-agents-table">
        <thead>
          <tr><th>Nom</th><th>Email</th><th>Rôle</th><th>Dernière connexion</th><?php if ($is_mairie_admin): ?><th></th><?php endif; ?></tr>
        </thead>
        <tbody>
          <?php foreach ($agents as $a): ?>
            <tr>
              <td><strong><?= h($a['first_name'] . ' ' . $a['last_name']) ?></strong><?= (int)$a['id'] === $user_id ? ' <span style="color:#9ca3af;font-size:.78rem;">(vous)</span>' : '' ?></td>
              <td><?= h($a['email']) ?></td>
              <td><span class="set-role-badge <?= h($a['parent_org_role'] ?: 'agent') ?>"><?= h($a['parent_org_role'] ?: 'agent') ?></span></td>
              <td style="color:#6b7280;"><?= $a['last_login_at'] ? h(date('d/m/Y H:i', strtotime($a['last_login_at']))) : '<span style="color:#d1d5db;">Jamais</span>' ?></td>
              <?php if ($is_mairie_admin): ?>
                <td style="text-align:right;">
                  <?php if ((int)$a['id'] !== $user_id): ?>
                    <form method="POST" action="/action-mairie-settings" style="margin:0;" onsubmit="return confirm('Retirer cet agent ? Il perdra l\'accès à cet espace.');">
                      <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                      <input type="hidden" name="action" value="remove_agent">
                      <input type="hidden" name="agent_id" value="<?= (int)$a['id'] ?>">
                      <button type="submit" class="set-btn danger" style="padding:5px 12px;font-size:.8rem;">Retirer</button>
                    </form>
                  <?php endif; ?>
                </td>
              <?php endif; ?>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <?php if ($is_mairie_admin): ?>
    <form method="POST" action="/action-mairie-settings">
      <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
      <input type="hidden" name="action" value="add_agent">
      <div class="set-addagent-row">
        <div class="set-field"><label>Prénom</label><input type="text" name="first_name" required maxlength="100"></div>
        <div class="set-field"><label>Nom</label><input type="text" name="last_name" required maxlength="100"></div>
        <div class="set-field"><label>Email</label><input type="email" name="email" required maxlength="255"></div>
        <div class="set-field">
          <label>Rôle</label>
          <select name="parent_org_role"><option value="agent">Agent</option><option value="admin">Admin</option></select>
        </div>
        <button type="submit" class="set-btn primary" style="height:38px;">Ajouter</button>
      </div>
    </form>
    <?php endif; ?>
  </div>

  <!-- MON COMPTE -->
  <div class="set-card">
    <h2>🔑 Mon mot de passe</h2>
    <p class="card-desc">Modifiez le mot de passe de votre compte personnel.</p>

    <form method="POST" action="/action-mairie-settings">
      <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
      <input type="hidden" name="action" value="change_password">
      <div class="set-grid">
        <div class="set-field"><label>Mot de passe actuel</label><input type="password" name="current_password" required></div>
        <div class="set-field"></div>
        <div class="set-field"><label>Nouveau mot de passe</label><input type="password" name="new_password" required minlength="8"></div>
        <div class="set-field"><label>Confirmer</label><input type="password" name="confirm_password" required minlength="8"></div>
      </div>
      <div class="set-actions">
        <button type="submit" class="set-btn primary">Changer mon mot de passe</button>
      </div>
    </form>
  </div>

</div>

<?php render_foot(); ?>
