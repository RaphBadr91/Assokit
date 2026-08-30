<?php
/**
 * Super Admin — Détail/gestion d'une mairie
 * URL : /super-admin-mairie-detail?id={mairie_id}
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes-layout.php';
require_once __DIR__ . '/includes-permissions.php';
require_login();
require_platform_admin();

$mairie_id = (int)($_GET['id'] ?? 0);
if ($mairie_id <= 0) { header('Location: /super-admin-mairies'); exit; }

$mairie = get_parent_org($mairie_id);
if (!$mairie) { http_response_code(404); die('Mairie introuvable'); }

if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$csrf = htmlspecialchars($_SESSION['csrf_token']);

$nb_orgs = count_orgs_for_parent_org($mairie_id);
$orgs = get_orgs_for_parent_org($mairie_id);
$quota_pct = $mairie['validated_quota'] > 0 ? min(100, round($nb_orgs / $mairie['validated_quota'] * 100)) : 0;

$users_stmt = $pdo->prepare("SELECT id, first_name, last_name, email, parent_org_role, created_at FROM users WHERE parent_org_id = ? ORDER BY created_at DESC");
$users_stmt->execute([$mairie_id]);
$users_mairie = $users_stmt->fetchAll(PDO::FETCH_ASSOC);

// Asso non-liées (pour le dropdown de liaison)
$orgs_available = $pdo->query("
    SELECT id, name, billing_address_city, siret
    FROM organizations
    WHERE parent_org_id IS NULL
    ORDER BY name ASC
    LIMIT 500
")->fetchAll(PDO::FETCH_ASSOC);

$type_emoji = match($mairie['type']) {
    'mairie' => '🏛', 'departement' => '🏢', 'region' => '🌍',
    'drac' => '🎭', 'caf' => '👨‍👩‍👧', 'federation' => '🤝', default => '🏢'
};
$status_meta = match($mairie['status']) {
    'active' => ['bg'=>'#D1FAE5','txt'=>'#059669','label'=>'🟢 Active'],
    'pending' => ['bg'=>'#FEF3C7','txt'=>'#B45309','label'=>'🟠 En attente'],
    'suspended' => ['bg'=>'#FEE2E2','txt'=>'#B91C1C','label'=>'🔴 Suspendue'],
    'rejected' => ['bg'=>'#F4F4F5','txt'=>'#52525B','label'=>'⚫ Rejetée'],
    default => ['bg'=>'#F4F4F5','txt'=>'#52525B','label'=>'?']
};

render_head('Mairie : ' . $mairie['name']);
render_sidebar('super-admin');
?>

<div style="max-width:1200px;margin:0 auto;padding:24px;">

  <?php if (!empty($_SESSION['flash_success'])): ?>
    <div style="background:#D1FAE5;border:1px solid #10B981;color:#065F46;padding:12px 16px;border-radius:8px;margin-bottom:16px;font-weight:600;">✅ <?= htmlspecialchars($_SESSION['flash_success']) ?></div>
    <?php unset($_SESSION['flash_success']); ?>
  <?php endif; ?>
  <?php if (!empty($_SESSION['flash_error'])): ?>
    <div style="background:#FEE2E2;border:1px solid #DC2626;color:#991B1B;padding:12px 16px;border-radius:8px;margin-bottom:16px;font-weight:600;">❌ <?= htmlspecialchars($_SESSION['flash_error']) ?></div>
    <?php unset($_SESSION['flash_error']); ?>
  <?php endif; ?>

  <div style="margin-bottom:18px;">
    <a href="/super-admin-mairies" style="color:#3F3F46;text-decoration:none;font-size:13px;">← Retour aux mairies</a>
  </div>

  <!-- HEADER MAIRIE -->
  <div style="background:#fff;border:1px solid #E5E7EB;border-radius:14px;padding:24px 28px;margin-bottom:20px;">
    <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:24px;">
      <div style="flex:1;">
        <div style="display:flex;align-items:center;gap:14px;margin-bottom:8px;">
          <span style="font-size:36px;"><?= $type_emoji ?></span>
          <div>
            <h1 style="margin:0;font-size:24px;color:#0A0A0B;font-weight:700;"><?= h($mairie['name']) ?></h1>
            <div style="margin-top:4px;display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
              <span style="background:<?= $status_meta['bg'] ?>;color:<?= $status_meta['txt'] ?>;padding:4px 12px;border-radius:6px;font-size:12px;font-weight:600;"><?= $status_meta['label'] ?></span>
              <span style="font-size:13px;color:#71717A;">Type : <strong style="color:#3F3F46;text-transform:capitalize;"><?= h($mairie['type']) ?></strong></span>
              <?php if ($mairie['siret']): ?><span style="font-size:13px;color:#71717A;">SIRET <?= h($mairie['siret']) ?></span><?php endif; ?>
            </div>
          </div>
        </div>
        <div style="font-size:13.5px;color:#52525B;line-height:1.7;margin-top:8px;">
          <?php if ($mairie['address_city']): ?>📍 <?= h(trim(($mairie['address_street'] ?? '') . ' · ' . ($mairie['address_zip'] ?? '') . ' ' . $mairie['address_city'])) ?><br><?php endif; ?>
          <?php if ($mairie['contact_email']): ?>✉ <a href="mailto:<?= h($mairie['contact_email']) ?>" style="color:#059669;text-decoration:none;"><?= h($mairie['contact_email']) ?></a><?php endif; ?>
          <?php if ($mairie['contact_phone']): ?> · 📞 <?= h($mairie['contact_phone']) ?><?php endif; ?>
          <?php if ($mairie['contact_first_name']): ?> · 👤 <?= h($mairie['contact_first_name'] . ' ' . $mairie['contact_last_name']) ?><?php endif; ?>
        </div>
      </div>

      <!-- ACTIONS RAPIDES -->
      <div style="display:flex;gap:8px;flex-direction:column;min-width:170px;">
        <?php if ($mairie['status'] === 'pending'): ?>
          <form method="POST" action="/action-mairie.php" style="margin:0;">
            <input type="hidden" name="action" value="validate">
            <input type="hidden" name="mairie_id" value="<?= $mairie_id ?>">
            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
            <button type="submit" style="width:100%;background:#059669;color:#fff;padding:10px 16px;border:none;border-radius:8px;cursor:pointer;font-weight:600;font-size:13.5px;">✓ Valider la mairie</button>
          </form>
        <?php endif; ?>
        <?php if ($mairie['status'] === 'active'): ?>
          <form method="POST" action="/action-mairie.php" style="margin:0;" onsubmit="return confirm('Suspendre cette mairie ?');">
            <input type="hidden" name="action" value="suspend">
            <input type="hidden" name="mairie_id" value="<?= $mairie_id ?>">
            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
            <button type="submit" style="width:100%;background:#F59E0B;color:#fff;padding:10px 16px;border:none;border-radius:8px;cursor:pointer;font-weight:600;font-size:13.5px;">⏸ Suspendre</button>
          </form>
        <?php endif; ?>
        <?php if (in_array($mairie['status'], ['suspended','rejected'], true)): ?>
          <form method="POST" action="/action-mairie.php" style="margin:0;">
            <input type="hidden" name="action" value="reactivate">
            <input type="hidden" name="mairie_id" value="<?= $mairie_id ?>">
            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
            <button type="submit" style="width:100%;background:#059669;color:#fff;padding:10px 16px;border:none;border-radius:8px;cursor:pointer;font-weight:600;font-size:13.5px;">▶ Réactiver</button>
          </form>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- ÉDITION IDENTITÉ (repliable) -->
  <details style="background:#fff;border:1px solid #E5E7EB;border-radius:12px;margin-bottom:20px;overflow:hidden;">
    <summary style="padding:16px 24px;cursor:pointer;font-weight:600;font-size:14px;color:#0A0A0B;list-style:none;display:flex;align-items:center;gap:10px;user-select:none;">
      <span style="font-size:18px;">✏️</span> Modifier l'identité de la collectivité
      <span style="margin-left:auto;font-size:12px;color:#71717A;font-weight:400;">cliquer pour déplier</span>
    </summary>
    <div style="padding:0 24px 24px;border-top:1px solid #F4F4F5;">
      <form method="POST" action="/action-mairie.php" style="margin-top:18px;">
        <input type="hidden" name="action" value="update_identity">
        <input type="hidden" name="mairie_id" value="<?= $mairie_id ?>">
        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;">
          <div style="grid-column:1/-1;">
            <label style="display:block;font-size:12px;font-weight:600;color:#3F3F46;margin-bottom:5px;">Nom de la collectivité *</label>
            <input name="name" required value="<?= h($mairie['name']) ?>" style="width:100%;padding:9px 12px;border:1px solid #D4D4D8;border-radius:7px;font-size:14px;box-sizing:border-box;">
          </div>
          <div>
            <label style="display:block;font-size:12px;font-weight:600;color:#3F3F46;margin-bottom:5px;">Type</label>
            <select name="type" style="width:100%;padding:9px 12px;border:1px solid #D4D4D8;border-radius:7px;font-size:14px;background:#fff;box-sizing:border-box;">
              <?php foreach (['mairie'=>'Mairie','departement'=>'Département','region'=>'Région','drac'=>'DRAC','caf'=>'CAF','federation'=>'Fédération','autre'=>'Autre'] as $val=>$lbl): ?>
                <option value="<?= $val ?>" <?= $mairie['type']===$val?'selected':'' ?>><?= $lbl ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div>
            <label style="display:block;font-size:12px;font-weight:600;color:#3F3F46;margin-bottom:5px;">SIRET</label>
            <input name="siret" maxlength="20" value="<?= h($mairie['siret']) ?>" style="width:100%;padding:9px 12px;border:1px solid #D4D4D8;border-radius:7px;font-size:14px;box-sizing:border-box;">
          </div>
          <div>
            <label style="display:block;font-size:12px;font-weight:600;color:#3F3F46;margin-bottom:5px;">Contact — Prénom</label>
            <input name="contact_first_name" maxlength="100" value="<?= h($mairie['contact_first_name']) ?>" style="width:100%;padding:9px 12px;border:1px solid #D4D4D8;border-radius:7px;font-size:14px;box-sizing:border-box;">
          </div>
          <div>
            <label style="display:block;font-size:12px;font-weight:600;color:#3F3F46;margin-bottom:5px;">Contact — Nom</label>
            <input name="contact_last_name" maxlength="100" value="<?= h($mairie['contact_last_name']) ?>" style="width:100%;padding:9px 12px;border:1px solid #D4D4D8;border-radius:7px;font-size:14px;box-sizing:border-box;">
          </div>
          <div>
            <label style="display:block;font-size:12px;font-weight:600;color:#3F3F46;margin-bottom:5px;">Email de contact</label>
            <input type="email" name="contact_email" maxlength="255" value="<?= h($mairie['contact_email']) ?>" style="width:100%;padding:9px 12px;border:1px solid #D4D4D8;border-radius:7px;font-size:14px;box-sizing:border-box;">
          </div>
          <div>
            <label style="display:block;font-size:12px;font-weight:600;color:#3F3F46;margin-bottom:5px;">Téléphone</label>
            <input name="contact_phone" maxlength="30" value="<?= h($mairie['contact_phone']) ?>" style="width:100%;padding:9px 12px;border:1px solid #D4D4D8;border-radius:7px;font-size:14px;box-sizing:border-box;">
          </div>
          <div style="grid-column:1/-1;">
            <label style="display:block;font-size:12px;font-weight:600;color:#3F3F46;margin-bottom:5px;">Adresse</label>
            <input name="address_street" maxlength="255" value="<?= h($mairie['address_street']) ?>" placeholder="N° et rue" style="width:100%;padding:9px 12px;border:1px solid #D4D4D8;border-radius:7px;font-size:14px;box-sizing:border-box;">
          </div>
          <div>
            <label style="display:block;font-size:12px;font-weight:600;color:#3F3F46;margin-bottom:5px;">Code postal</label>
            <input name="address_zip" maxlength="10" value="<?= h($mairie['address_zip']) ?>" style="width:100%;padding:9px 12px;border:1px solid #D4D4D8;border-radius:7px;font-size:14px;box-sizing:border-box;">
          </div>
          <div>
            <label style="display:block;font-size:12px;font-weight:600;color:#3F3F46;margin-bottom:5px;">Ville</label>
            <input name="address_city" maxlength="100" value="<?= h($mairie['address_city']) ?>" style="width:100%;padding:9px 12px;border:1px solid #D4D4D8;border-radius:7px;font-size:14px;box-sizing:border-box;">
          </div>
          <div>
            <label style="display:block;font-size:12px;font-weight:600;color:#3F3F46;margin-bottom:5px;">Département</label>
            <input name="department" maxlength="100" value="<?= h($mairie['department']) ?>" style="width:100%;padding:9px 12px;border:1px solid #D4D4D8;border-radius:7px;font-size:14px;box-sizing:border-box;">
          </div>
          <div>
            <label style="display:block;font-size:12px;font-weight:600;color:#3F3F46;margin-bottom:5px;">Région</label>
            <input name="region" maxlength="100" value="<?= h($mairie['region']) ?>" style="width:100%;padding:9px 12px;border:1px solid #D4D4D8;border-radius:7px;font-size:14px;box-sizing:border-box;">
          </div>
        </div>

        <div style="display:flex;justify-content:flex-end;margin-top:18px;">
          <button type="submit" style="background:#0A0A0B;color:#fff;padding:10px 22px;border:none;border-radius:8px;font-weight:600;font-size:13.5px;cursor:pointer;">💾 Enregistrer l'identité</button>
        </div>
      </form>
    </div>
  </details>

  <!-- STATS + QUOTA -->
  <div style="display:grid;grid-template-columns:1fr 1fr 2fr;gap:14px;margin-bottom:20px;">
    <div style="background:#fff;border:1px solid #E5E7EB;border-radius:10px;padding:18px;">
      <div style="font-size:11px;color:#71717A;text-transform:uppercase;letter-spacing:0.05em;font-weight:600;">Associations</div>
      <div style="font-size:30px;font-weight:700;color:#0A0A0B;margin-top:4px;"><?= $nb_orgs ?> <span style="font-size:14px;color:#71717A;font-weight:500;">/ <?= (int)$mairie['validated_quota'] ?></span></div>
    </div>
    <div style="background:#fff;border:1px solid #E5E7EB;border-radius:10px;padding:18px;">
      <div style="font-size:11px;color:#71717A;text-transform:uppercase;letter-spacing:0.05em;font-weight:600;">Utilisateurs</div>
      <div style="font-size:30px;font-weight:700;color:#0A0A0B;margin-top:4px;"><?= count($users_mairie) ?></div>
    </div>
    <div style="background:#fff;border:1px solid #E5E7EB;border-radius:10px;padding:18px;">
      <div style="font-size:11px;color:#71717A;text-transform:uppercase;letter-spacing:0.05em;font-weight:600;">Modifier le quota</div>
      <form method="POST" action="/action-mairie.php" style="margin-top:6px;display:flex;gap:8px;align-items:center;">
        <input type="hidden" name="action" value="update_quota">
        <input type="hidden" name="mairie_id" value="<?= $mairie_id ?>">
        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
        <input type="number" name="validated_quota" value="<?= (int)$mairie['validated_quota'] ?>" min="0" max="100000" style="flex:1;padding:8px 12px;border:1px solid #D4D4D8;border-radius:7px;font-size:14px;">
        <button type="submit" style="background:#0A0A0B;color:#fff;padding:8px 16px;border:none;border-radius:7px;font-weight:600;font-size:13px;cursor:pointer;">Modifier</button>
      </form>
      <?php if ($mairie['validated_quota'] > 0): ?>
        <div style="margin-top:10px;height:5px;background:#F4F4F5;border-radius:3px;overflow:hidden;">
          <div style="width:<?= $quota_pct ?>%;height:100%;background:<?= $quota_pct > 80 ? '#DC2626' : ($quota_pct > 50 ? '#F59E0B' : '#10B981') ?>;"></div>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- UTILISATEURS / AGENTS -->
  <div style="background:#fff;border:1px solid #E5E7EB;border-radius:14px;padding:20px 24px;margin-bottom:20px;">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;">
      <h2 style="margin:0;font-size:16px;color:#0A0A0B;font-weight:700;">👥 Agents de la mairie (<?= count($users_mairie) ?>)</h2>
    </div>

    <!-- FORM AJOUT AGENT -->
    <details style="margin-bottom:14px;background:#F0FDF4;border:1px solid #D1FAE5;border-radius:8px;padding:12px 14px;">
      <summary style="cursor:pointer;font-weight:600;color:#059669;font-size:13.5px;">+ Ajouter un agent à cette mairie</summary>
      <form method="POST" action="/action-mairie.php" style="margin-top:14px;display:grid;grid-template-columns:1fr 1fr 2fr 1fr auto;gap:8px;align-items:end;">
        <input type="hidden" name="action" value="add_user">
        <input type="hidden" name="mairie_id" value="<?= $mairie_id ?>">
        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
        <div><label style="font-size:11px;color:#3F3F46;font-weight:600;">Prénom *</label><input name="first_name" required style="width:100%;padding:8px 10px;border:1px solid #D4D4D8;border-radius:6px;font-size:13px;"></div>
        <div><label style="font-size:11px;color:#3F3F46;font-weight:600;">Nom *</label><input name="last_name" required style="width:100%;padding:8px 10px;border:1px solid #D4D4D8;border-radius:6px;font-size:13px;"></div>
        <div><label style="font-size:11px;color:#3F3F46;font-weight:600;">Email *</label><input type="email" name="email" required style="width:100%;padding:8px 10px;border:1px solid #D4D4D8;border-radius:6px;font-size:13px;"></div>
        <div><label style="font-size:11px;color:#3F3F46;font-weight:600;">Rôle</label><select name="parent_org_role" style="width:100%;padding:8px 10px;border:1px solid #D4D4D8;border-radius:6px;font-size:13px;background:#fff;"><option value="admin">Admin</option><option value="agent">Agent</option></select></div>
        <button type="submit" style="background:#059669;color:#fff;padding:9px 14px;border:none;border-radius:6px;cursor:pointer;font-weight:600;font-size:13px;">+ Ajouter</button>
      </form>
    </details>

    <?php if (empty($users_mairie)): ?>
      <div style="text-align:center;padding:24px;color:#71717A;font-size:13.5px;background:#FAFAF9;border-radius:8px;">
        Aucun agent. Ajoute un agent ci-dessus pour permettre la connexion à la mairie.
      </div>
    <?php else: ?>
      <table style="width:100%;border-collapse:collapse;">
        <thead><tr style="border-bottom:1px solid #E5E7EB;font-size:11px;text-transform:uppercase;letter-spacing:0.05em;color:#71717A;">
          <th style="text-align:left;padding:8px 6px;">Nom</th><th style="text-align:left;padding:8px 6px;">Email</th><th style="text-align:left;padding:8px 6px;">Rôle</th><th style="text-align:left;padding:8px 6px;">Créé le</th><th style="text-align:right;padding:8px 6px;">Action</th>
        </tr></thead>
        <tbody>
        <?php foreach ($users_mairie as $u): ?>
          <tr style="border-bottom:0.5pt solid #F4F4F5;">
            <td style="padding:10px 6px;font-size:13.5px;color:#0A0A0B;font-weight:600;"><?= h(trim($u['first_name'] . ' ' . $u['last_name'])) ?></td>
            <td style="padding:10px 6px;font-size:13px;color:#3F3F46;"><?= h($u['email']) ?></td>
            <td style="padding:10px 6px;"><span style="background:#F4F4F5;padding:2px 8px;border-radius:4px;font-size:11.5px;color:#3F3F46;"><?= h($u['parent_org_role'] ?: '—') ?></span></td>
            <td style="padding:10px 6px;font-size:12px;color:#71717A;"><?= date('d/m/Y', strtotime($u['created_at'])) ?></td>
            <td style="padding:10px 6px;text-align:right;">
              <form method="POST" action="/action-mairie.php" style="margin:0;display:inline;" onsubmit="return confirm('Retirer cet agent de la mairie ? (Le compte sera désactivé)');">
                <input type="hidden" name="action" value="remove_user">
                <input type="hidden" name="mairie_id" value="<?= $mairie_id ?>">
                <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                <button type="submit" style="background:#FEE2E2;color:#B91C1C;padding:4px 10px;border:none;border-radius:5px;cursor:pointer;font-size:11px;font-weight:600;">Retirer</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>

  <!-- ASSOCIATIONS RATTACHÉES -->
  <div style="background:#fff;border:1px solid #E5E7EB;border-radius:14px;padding:20px 24px;margin-bottom:20px;">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;">
      <h2 style="margin:0;font-size:16px;color:#0A0A0B;font-weight:700;">🏢 Associations rattachées (<?= $nb_orgs ?>)</h2>
    </div>

    <!-- FORM LIER UNE ASSO -->
    <details style="margin-bottom:14px;background:#F0FDF4;border:1px solid #D1FAE5;border-radius:8px;padding:12px 14px;">
      <summary style="cursor:pointer;font-weight:600;color:#059669;font-size:13.5px;">+ Lier une association existante (<?= count($orgs_available) ?> dispo)</summary>
      <form method="POST" action="/action-mairie.php" style="margin-top:14px;display:flex;gap:8px;align-items:end;">
        <input type="hidden" name="action" value="link_org">
        <input type="hidden" name="mairie_id" value="<?= $mairie_id ?>">
        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
        <div style="flex:1;">
          <label style="font-size:11px;color:#3F3F46;font-weight:600;">Choisis une asso non-rattachée</label>
          <select name="org_id" required style="width:100%;padding:8px 10px;border:1px solid #D4D4D8;border-radius:6px;font-size:13px;background:#fff;">
            <option value="">— Sélectionner —</option>
            <?php foreach ($orgs_available as $oa): ?>
              <option value="<?= (int)$oa['id'] ?>"><?= h($oa['name']) ?><?php if ($oa['billing_address_city']): ?> · <?= h($oa['billing_address_city']) ?><?php endif; ?><?php if ($oa['siret']): ?> · SIRET <?= h($oa['siret']) ?><?php endif; ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <button type="submit" style="background:#059669;color:#fff;padding:9px 14px;border:none;border-radius:6px;cursor:pointer;font-weight:600;font-size:13px;">🔗 Lier</button>
      </form>
    </details>

    <?php if (empty($orgs)): ?>
      <div style="text-align:center;padding:24px;color:#71717A;font-size:13.5px;background:#FAFAF9;border-radius:8px;">
        Aucune asso rattachée. Lie une association ci-dessus.
      </div>
    <?php else: ?>
      <div style="display:grid;gap:8px;">
        <?php foreach ($orgs as $o): ?>
          <div style="display:flex;justify-content:space-between;align-items:center;padding:12px 14px;border:1px solid #E5E7EB;border-radius:8px;">
            <div>
              <strong style="font-size:14px;color:#0A0A0B;"><?= h($o['name']) ?></strong>
              <?php if ($o['billing_address_city']): ?><span style="color:#71717A;font-size:12.5px;"> · <?= h($o['billing_address_city']) ?></span><?php endif; ?>
            </div>
            <div style="display:flex;gap:6px;">
              <a href="/super-admin/associations?id=<?= (int)$o['id'] ?>" style="background:#F4F4F5;color:#3F3F46;padding:5px 10px;border-radius:5px;text-decoration:none;font-size:12px;">Voir</a>
              <form method="POST" action="/action-mairie.php" style="margin:0;" onsubmit="return confirm('Délier cette association de la mairie ?');">
                <input type="hidden" name="action" value="unlink_org">
                <input type="hidden" name="mairie_id" value="<?= $mairie_id ?>">
                <input type="hidden" name="org_id" value="<?= (int)$o['id'] ?>">
                <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                <button type="submit" style="background:#FEE2E2;color:#B91C1C;padding:5px 10px;border:none;border-radius:5px;cursor:pointer;font-size:12px;font-weight:600;">Délier</button>
              </form>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

  <!-- NOTES & MÉTADONNÉES -->
  <div style="background:#FAFAF9;border:1px solid #E5E7EB;border-radius:14px;padding:18px 22px;">
    <h3 style="margin:0 0 10px;font-size:14px;color:#3F3F46;font-weight:700;">📋 Métadonnées</h3>
    <div style="font-size:12.5px;color:#52525B;line-height:1.85;">
      <strong>ID :</strong> #<?= $mairie['id'] ?><br>
      <strong>Créée le :</strong> <?= date('d/m/Y H:i', strtotime($mairie['created_at'])) ?><br>
      <?php if ($mairie['validated_at']): ?><strong>Validée le :</strong> <?= date('d/m/Y H:i', strtotime($mairie['validated_at'])) ?><br><?php endif; ?>
      <?php if ($mairie['notes']): ?><br><strong>Notes :</strong><br><span style="white-space:pre-line;"><?= h($mairie['notes']) ?></span><?php endif; ?>
    </div>
  </div>

</div>
