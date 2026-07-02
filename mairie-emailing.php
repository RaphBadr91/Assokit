<?php
/**
 * Page Emailing côté mairie : composer + historique
 * URL : /mairie-emailing
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

if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$csrf = $_SESSION['csrf_token'];

// === Asso liées (pour le sélecteur d'audience) ===
$stmt = $pdo->prepare("
    SELECT o.id, o.name,
           (SELECT COUNT(*) FROM users u WHERE u.org_id = o.id AND u.role = 'admin' AND u.deleted_at IS NULL) AS nb_admins
    FROM organizations o
    WHERE o.parent_org_id = ?
    ORDER BY o.name ASC
");
$stmt->execute([$parent_org_id]);
$orgs = $stmt->fetchAll(PDO::FETCH_ASSOC);

$total_admins = 0;
foreach ($orgs as $o) $total_admins += (int)$o['nb_admins'];

// === Historique des campagnes ===
$stmt = $pdo->prepare("
    SELECT c.id, c.subject, c.recipients_count, c.sent_count, c.failed_count, c.status, c.sent_at, c.created_at,
           (SELECT CONCAT(u.first_name, ' ', u.last_name) FROM users u WHERE u.id = c.created_by) AS author_name
    FROM mairie_campaigns c
    WHERE c.parent_org_id = ?
    ORDER BY c.created_at DESC
    LIMIT 30
");
$stmt->execute([$parent_org_id]);
$campaigns = $stmt->fetchAll(PDO::FETCH_ASSOC);

render_head('Emailing · Mairie');
render_sidebar('emailing');
?>

<style>
.eml-page { padding: 20px 28px 40px; max-width: 1200px; margin: 0 auto; }
.eml-page h1 { font-size: 1.5rem; margin: 0 0 6px; }
.eml-page .lead { color: #6a6f76; font-size: .95rem; margin: 0 0 24px; }

.eml-card { background: #fff; border: 1px solid #e9ecef; border-radius: 14px; padding: 24px; margin-bottom: 22px; box-shadow: 0 1px 3px rgba(0,0,0,.04); }
.eml-card h2 { font-size: 1.1rem; margin: 0 0 14px; display: flex; align-items: center; gap: 8px; }

.eml-field { margin-bottom: 16px; }
.eml-field label { display: block; font-weight: 600; font-size: .85rem; color: #374151; margin-bottom: 6px; }
.eml-field input[type=text], .eml-field textarea { width: 100%; padding: 10px 14px; border: 1px solid #d1d5db; border-radius: 8px; font-size: .92rem; font-family: inherit; box-sizing: border-box; outline: none; transition: border-color .15s; }
.eml-field input[type=text]:focus, .eml-field textarea:focus { border-color: #f59e0b; box-shadow: 0 0 0 3px rgba(245, 158, 11, .12); }
.eml-field textarea { min-height: 180px; resize: vertical; }
.eml-field-hint { font-size: .78rem; color: #6b7280; margin-top: 4px; }

.eml-audience-options { display: flex; gap: 12px; margin-bottom: 12px; flex-wrap: wrap; }
.eml-audience-options label { display: flex; align-items: center; gap: 8px; padding: 10px 16px; border: 1px solid #d1d5db; border-radius: 10px; cursor: pointer; transition: all .15s; flex: 1; min-width: 220px; }
.eml-audience-options label:hover { border-color: #f59e0b; background: #fffbeb; }
.eml-audience-options input[type=radio]:checked + .eml-audience-label { color: #f59e0b; font-weight: 700; }
.eml-audience-options label:has(input[type=radio]:checked) { border-color: #f59e0b; background: #fffbeb; }
.eml-audience-label { font-size: .92rem; font-weight: 600; }
.eml-audience-meta { display: block; font-size: .76rem; color: #6b7280; font-weight: 400; margin-top: 2px; }

.eml-orgs-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); gap: 10px; padding: 14px; background: #f9fafb; border-radius: 10px; margin-top: 8px; }
.eml-orgs-grid label { display: flex; align-items: center; gap: 10px; padding: 8px 12px; background: #fff; border-radius: 8px; cursor: pointer; font-size: .88rem; }
.eml-orgs-grid label:hover { background: #fffbeb; }
.eml-orgs-grid input[type=checkbox]:disabled + span { color: #9ca3af; }
.eml-orgs-meta { font-size: .72rem; color: #6b7280; }

.eml-actions { display: flex; gap: 10px; justify-content: flex-end; align-items: center; padding-top: 8px; border-top: 1px solid #f3f4f6; margin-top: 4px; }
.eml-actions .send-info { flex: 1; font-size: .86rem; color: #6b7280; }
.eml-actions .send-info strong { color: #1a1a1a; }
.eml-btn { padding: 10px 24px; border-radius: 10px; font-weight: 600; cursor: pointer; font-size: .92rem; border: 0; transition: transform .1s, box-shadow .15s; }
.eml-btn.primary { background: linear-gradient(135deg, #1a1a1a 0%, #2d2d2d 100%); color: #fff; }
.eml-btn.primary:hover { transform: translateY(-1px); box-shadow: 0 4px 10px rgba(0,0,0,.15); }
.eml-btn.primary:disabled { opacity: .55; cursor: not-allowed; }

.eml-history-table { width: 100%; border-collapse: collapse; }
.eml-history-table th, .eml-history-table td { padding: 12px 14px; text-align: left; border-bottom: 1px solid #f3f4f6; font-size: .88rem; }
.eml-history-table th { font-size: .75rem; color: #6b7280; text-transform: uppercase; letter-spacing: .4px; font-weight: 700; background: #f9fafb; }
.eml-history-table tr:hover { background: #fcfcfd; }
.eml-history-empty { text-align: center; padding: 50px 20px; color: #9ca3af; }
.eml-history-empty .icon { font-size: 3rem; opacity: .4; display: block; margin-bottom: 12px; }

.eml-status-badge { padding: 3px 9px; border-radius: 12px; font-size: .72rem; font-weight: 700; text-transform: uppercase; letter-spacing: .3px; display: inline-block; }
.eml-status-badge.sent { background: #d1fae5; color: #065f46; }
.eml-status-badge.sending { background: #fef3c7; color: #92400e; }
.eml-status-badge.draft { background: #e0e7ff; color: #3730a3; }
.eml-status-badge.failed { background: #fee2e2; color: #991b1b; }

.eml-flash { padding: 12px 18px; border-radius: 10px; margin-bottom: 18px; font-size: .92rem; }
.eml-flash.success { background: #d1fae5; color: #065f46; }
.eml-flash.error { background: #fee2e2; color: #991b1b; }

@media (max-width: 768px) {
  .eml-page { padding: 14px; }
  .eml-orgs-grid { grid-template-columns: 1fr; }
  .eml-history-table th, .eml-history-table td { padding: 8px; font-size: .82rem; }
}
</style>

<div class="eml-page">

  <h1>✉️ Emailing</h1>
  <p class="lead">Envoyez un email à toutes vos associations affiliées ou à une sélection précise.</p>

  <?php if (!empty($_SESSION['flash_success'])): ?>
    <div class="eml-flash success">✅ <?= h($_SESSION['flash_success']) ?></div>
    <?php unset($_SESSION['flash_success']); ?>
  <?php endif; ?>
  <?php if (!empty($_SESSION['flash_error'])): ?>
    <div class="eml-flash error">❌ <?= h($_SESSION['flash_error']) ?></div>
    <?php unset($_SESSION['flash_error']); ?>
  <?php endif; ?>

  <!-- COMPOSER -->
  <div class="eml-card">
    <h2>📝 Nouvelle campagne</h2>

    <?php if (!$orgs): ?>
      <p style="color:#9ca3af;padding:20px 0;text-align:center;">Aucune association liée pour le moment. Liez d'abord des associations à votre mairie.</p>
    <?php else: ?>

    <form method="POST" action="/action-mairie-emailing" id="emlForm">
      <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">

      <div class="eml-field">
        <label for="eml-subject">Objet de l'email</label>
        <input type="text" name="subject" id="eml-subject" maxlength="255" required placeholder="Ex : Rappel — Réunion d'information du 15 juin">
      </div>

      <div class="eml-field">
        <label for="eml-content">Message</label>
        <textarea name="content" id="eml-content" required maxlength="20000" placeholder="Bonjour,&#10;&#10;Nous vous rappelons que la réunion annuelle aura lieu le 15 juin à 18h en mairie...&#10;&#10;Cordialement,&#10;<?= h($parent_org['name']) ?>"></textarea>
        <p class="eml-field-hint">Les sauts de ligne sont préservés. Pas besoin de HTML, on s'occupe de la mise en forme.</p>
      </div>

      <div class="eml-field">
        <label>Destinataires</label>
        <div class="eml-audience-options">
          <label>
            <input type="radio" name="audience" value="all" checked onchange="toggleOrgsSelector(false)">
            <span class="eml-audience-label">
              Tous les administrateurs
              <span class="eml-audience-meta"><?= $total_admins ?> destinataire<?= $total_admins > 1 ? 's' : '' ?> (toutes asso confondues)</span>
            </span>
          </label>
          <label>
            <input type="radio" name="audience" value="selected" onchange="toggleOrgsSelector(true)">
            <span class="eml-audience-label">
              Sélection d'associations
              <span class="eml-audience-meta">Cochez les asso ci-dessous</span>
            </span>
          </label>
        </div>

        <div class="eml-orgs-grid" id="eml-orgs-grid" style="display:none;">
          <?php foreach ($orgs as $o): ?>
            <label>
              <input type="checkbox" name="org_ids[]" value="<?= (int)$o['id'] ?>" <?= ((int)$o['nb_admins'] === 0) ? 'disabled' : '' ?>>
              <span>
                <?= h($o['name']) ?>
                <span class="eml-orgs-meta"><?= (int)$o['nb_admins'] ?> admin<?= $o['nb_admins'] > 1 ? 's' : '' ?></span>
              </span>
            </label>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="eml-actions">
        <span class="send-info" id="emlSendInfo">
          📧 <strong id="emlRecipientCount"><?= $total_admins ?></strong> destinataire<?= $total_admins > 1 ? 's' : '' ?> sélectionné<?= $total_admins > 1 ? 's' : '' ?>
        </span>
        <button type="submit" class="eml-btn primary" id="emlSubmitBtn" onclick="return confirmSend()">Envoyer la campagne</button>
      </div>
    </form>

    <?php endif; ?>
  </div>

  <!-- HISTORIQUE -->
  <div class="eml-card">
    <h2>📊 Historique des campagnes</h2>

    <?php if (!$campaigns): ?>
      <div class="eml-history-empty">
        <span class="icon">📬</span>
        Aucune campagne envoyée pour le moment.
      </div>
    <?php else: ?>
      <div style="overflow-x:auto;">
        <table class="eml-history-table">
          <thead>
            <tr>
              <th>Date</th>
              <th>Objet</th>
              <th>Auteur</th>
              <th style="text-align:center;">Destinataires</th>
              <th style="text-align:center;">Envoyés</th>
              <th style="text-align:center;">Échecs</th>
              <th>Statut</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($campaigns as $c): ?>
              <tr>
                <td><?= h(date('d/m/Y H:i', strtotime($c['sent_at'] ?: $c['created_at']))) ?></td>
                <td><strong><?= h($c['subject']) ?></strong></td>
                <td><?= h($c['author_name']) ?></td>
                <td style="text-align:center;"><?= (int)$c['recipients_count'] ?></td>
                <td style="text-align:center;color:#065f46;font-weight:600;"><?= (int)$c['sent_count'] ?></td>
                <td style="text-align:center;color:<?= $c['failed_count'] > 0 ? '#991b1b' : '#9ca3af' ?>;font-weight:<?= $c['failed_count'] > 0 ? '600' : '400' ?>;"><?= (int)$c['failed_count'] ?></td>
                <td><span class="eml-status-badge <?= h($c['status']) ?>"><?= h($c['status']) ?></span></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>

</div>

<script>
const orgsData = <?= json_encode(array_column($orgs, 'nb_admins', 'id')) ?>;
const totalAdmins = <?= $total_admins ?>;

function toggleOrgsSelector(show) {
  document.getElementById('eml-orgs-grid').style.display = show ? 'grid' : 'none';
  updateRecipientCount();
}

function updateRecipientCount() {
  const isAll = document.querySelector('input[name="audience"]:checked').value === 'all';
  let count = 0;
  if (isAll) {
    count = totalAdmins;
  } else {
    document.querySelectorAll('input[name="org_ids[]"]:checked').forEach(cb => {
      count += parseInt(orgsData[cb.value] || 0);
    });
  }
  document.getElementById('emlRecipientCount').textContent = count;
  document.getElementById('emlSubmitBtn').disabled = (count === 0);
}

document.querySelectorAll('input[name="org_ids[]"]').forEach(cb => cb.addEventListener('change', updateRecipientCount));

function confirmSend() {
  const count = parseInt(document.getElementById('emlRecipientCount').textContent);
  if (count === 0) { alert('Aucun destinataire sélectionné.'); return false; }
  return confirm(`Confirmer l'envoi de cette campagne à ${count} destinataire(s) ?`);
}
</script>

