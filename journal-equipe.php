<?php
/**
 * journal-equipe.php — Journal d'activité de l'équipe (/journal-equipe)
 * ------------------------------------------------------------------
 * Formule Équipe (feature_activity_log), administrateurs seulement.
 * Qui a fait quoi, et quand : actions de l'équipe (création, modification, suppression,
 * envoi), comptes et accès, factures, questions au copilote. Jamais les pages consultées,
 * ni l'adresse IP, ni le navigateur. 30 jours par défaut, 12 mois au plus.
 * Export CSV : journal-equipe-export.php (mêmes filtres).
 * ------------------------------------------------------------------
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes-layout.php';
require_once __DIR__ . '/journal-equipe-helpers.php';
require_login();

$user = current_user();
$org_id = (int)($user['org_id'] ?? 0);
if ($org_id <= 0) { header('Location: /dashboard'); exit; }

$acces = je_access($pdo, $user, $org_id);
if ($acces === 'role') {
    $_SESSION['flash_error'] = 'Le journal d’activité est réservé aux administrateurs.';
    header('Location: /dashboard'); exit;
}
if ($acces === 'plan') {
    akc_lock_page($pdo, $org_id, 'activity_log', 'Journal d’activité de l’équipe', 'journal-equipe');
}

$f = je_filters($_GET);
$members = je_members($pdo, $org_id);
$limit = 500;
$rows = je_rows($pdo, $org_id, $f, $limit + 1);
$more = count($rows) > $limit;
$rows = array_slice($rows, 0, $limit);
$qs = http_build_query(array_filter(['periode' => $f['days'], 'type' => $f['type'], 'membre' => $f['membre'] ?: null]));

render_head('Journal d’activité');
render_sidebar('journal-equipe');
?>
<main class="main">
  <div class="je-page">
    <div class="je-head">
      <div>
        <h1 class="je-title"><?= ak_icon_badge('list', '#059669', 34) ?><span>Journal d’activité de l’équipe</span></h1>
        <p class="je-sub">Qui a fait quoi, et quand : créations, modifications, suppressions et envois réalisés dans Assokit. Les pages consultées n’y figurent pas, ni l’adresse IP ni le navigateur.</p>
      </div>
      <a class="je-btn" href="/journal-equipe-export?<?= h($qs) ?>"><?= ak_icon('download', 15) ?> Exporter (CSV)</a>
    </div>

    <form class="je-filters" method="get" action="/journal-equipe">
      <label>Membre
        <select name="membre">
          <option value="">Toute l’équipe</option>
          <?php foreach ($members as $mid => $nom): ?><option value="<?= (int)$mid ?>"<?= $f['membre'] === (int)$mid ? ' selected' : '' ?>><?= h($nom) ?></option><?php endforeach; ?>
        </select>
      </label>
      <label>Période
        <select name="periode">
          <?php foreach (je_periods() as $d => $lbl): ?><option value="<?= (int)$d ?>"<?= $f['days'] === $d ? ' selected' : '' ?>><?= h($lbl) ?></option><?php endforeach; ?>
        </select>
      </label>
      <label>Type
        <select name="type">
          <option value="">Tous</option>
          <?php foreach (je_types() as $k => $lbl): ?><option value="<?= h($k) ?>"<?= $f['type'] === $k ? ' selected' : '' ?>><?= h($lbl) ?></option><?php endforeach; ?>
        </select>
      </label>
      <button type="submit" class="je-btn primary">Filtrer</button>
    </form>

    <?php if (!$rows): ?>
      <div class="je-empty"><?= ak_icon('inbox', 34, '1.5') ?><p>Aucune action sur cette période.</p></div>
    <?php else: ?>
      <div class="je-table-wrap">
        <table class="je-table">
          <thead><tr><th>Date</th><th>Membre</th><th>Action</th><th>Objet</th><th>Type</th></tr></thead>
          <tbody>
          <?php foreach ($rows as $r): ?>
            <tr>
              <td data-lbl="Date"><?= h(date('d/m/Y H:i', strtotime($r['at']))) ?></td>
              <td data-lbl="Membre"><?= h($r['who']) ?></td>
              <td data-lbl="Action"><?= h($r['action']) ?></td>
              <td data-lbl="Objet"><?= h($r['target'] !== '' ? $r['target'] : '—') ?></td>
              <td data-lbl="Type"><span class="je-type"><?= h($r['type']) ?></span></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php if ($more): ?><p class="je-note">Seules les <?= $limit ?> actions les plus récentes sont affichées : l’export CSV contient toute la période (20 000 lignes au plus).</p><?php endif; ?>
    <?php endif; ?>
    <p class="je-note">Le journal remonte à 12 mois au plus. Il est réservé aux administrateurs de votre structure.</p>
  </div>
</main>
<style>
.je-page { max-width: 1100px; margin: 0 auto; padding: 24px 16px; }
.je-head { display: flex; justify-content: space-between; align-items: flex-start; gap: 14px; flex-wrap: wrap; margin-bottom: 16px; }
.je-title { display: flex; align-items: center; gap: 10px; font-size: 22px; margin: 0 0 6px; color: #0F172A; }
.je-sub { color: #64748B; font-size: 14px; margin: 0; max-width: 720px; line-height: 1.55; }
.je-btn { display: inline-flex; align-items: center; gap: 7px; border: 1px solid #E2E8F0; background: #fff; color: #334155; border-radius: 10px; padding: 9px 14px; font-weight: 600; font-size: 13.5px; text-decoration: none; cursor: pointer; font-family: inherit; }
.je-btn.primary { background: #059669; border-color: #059669; color: #fff; }
.je-filters { display: flex; flex-wrap: wrap; gap: 10px; align-items: flex-end; background: #fff; border: 1px solid #E2E8F0; border-radius: 14px; padding: 12px 14px; margin-bottom: 14px; }
.je-filters label { display: flex; flex-direction: column; gap: 4px; font-size: 12px; font-weight: 600; color: #475569; min-width: 0; }
.je-filters select { font: inherit; font-size: 13.5px; padding: 7px 10px; border: 1px solid #E2E8F0; border-radius: 9px; background: #fff; max-width: 100%; }
.je-table-wrap { background: #fff; border: 1px solid #E2E8F0; border-radius: 14px; overflow: hidden; }
.je-table { width: 100%; border-collapse: collapse; font-size: 13.5px; }
.je-table th { text-align: left; font-size: 11.5px; text-transform: uppercase; letter-spacing: .04em; color: #64748B; background: #F8FAFC; padding: 10px 12px; border-bottom: 1px solid #E2E8F0; }
.je-table td { padding: 10px 12px; border-bottom: 1px solid #F1F5F9; color: #1E293B; vertical-align: top; }
.je-table tr:last-child td { border-bottom: 0; }
.je-type { font-size: 11.5px; font-weight: 600; color: #475569; background: #F1F5F9; border-radius: 6px; padding: 2px 8px; white-space: nowrap; }
.je-empty { text-align: center; color: #64748B; background: #fff; border: 1px dashed #CBD5E1; border-radius: 14px; padding: 40px 16px; }
.je-note { color: #64748B; font-size: 12.5px; margin: 12px 0 0; }
@media (max-width: 640px) {
  .je-table thead { display: none; }
  .je-table tr { display: block; border-bottom: 1px solid #E2E8F0; padding: 8px 0; }
  .je-table td { display: flex; gap: 10px; border: 0; padding: 4px 12px; }
  .je-table td::before { content: attr(data-lbl); flex: 0 0 72px; font-size: 11.5px; font-weight: 600; color: #64748B; }
  .je-filters label { flex: 1 1 140px; }
}
</style>
<?php render_foot(); ?>
