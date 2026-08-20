<?php
/**
 * notes-de-frais.php — Notes de frais (liste + détail + actions)
 * ------------------------------------------------------------------
 * Auto-portée (pattern POST→Redirect→GET, CSRF). Tout scopé org_id ;
 * un utilisateur ne voit/édite que ses notes, les gestionnaires
 * (can('manage_finances')) voient tout et décident (approuver/rembourser).
 * ------------------------------------------------------------------
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes-layout.php';
require_once __DIR__ . '/notes-frais-engine.php';
require_login();

$user = current_user();
$org_id = (int)($user['org_id'] ?? 0);
$uid = (int)($user['id'] ?? 0);
if ($org_id <= 0) { header('Location: /dashboard'); exit; }
$is_manager = can('manage_finances');
$restrict = $is_manager ? null : $uid;   // non-gestionnaire : limité à ses notes
$csrf = h($_SESSION['csrf_token'] ?? '');

// ── Traitement des actions (POST) ────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!check_csrf($_POST['csrf_token'] ?? '')) { http_response_code(403); exit('CSRF'); }
    $act = $_POST['action'] ?? '';
    $rid = (int)($_POST['report_id'] ?? 0);

    $cents = function($s){ return (int)round(((float)str_replace([',',' '],['.',''], (string)$s)) * 100); };
    // Vérifie l'accès à la note (auteur, ou gestionnaire)
    $own = function($rid) use ($pdo,$org_id,$uid,$is_manager) {
        $st = $pdo->prepare("SELECT * FROM expense_reports WHERE id=? AND org_id=?"); $st->execute([$rid,$org_id]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r) return null;
        if (!$is_manager && (int)$r['user_id'] !== $uid) return null;
        return $r;
    };

    try {
        if ($act === 'create-report') {
            $title = trim((string)($_POST['title'] ?? '')); if ($title==='') $title = 'Note de frais';
            $st = $pdo->prepare("INSERT INTO expense_reports (org_id,user_id,title,status) VALUES (?,?,?,'draft')");
            $st->execute([$org_id,$uid,mb_substr($title,0,160)]);
            $newid = (int)$pdo->lastInsertId();
            header('Location: /notes-de-frais?id='.$newid); exit;
        }
        if ($act === 'add-line' && ($r=$own($rid))) {
            if ($r['status'] !== 'draft') { header('Location: /notes-de-frais?id='.$rid); exit; }
            $mode = ($_POST['mode'] ?? 'receipt') === 'mileage' ? 'mileage' : 'receipt';
            $spent = ($_POST['spent_at'] ?? '') ?: null;
            if ($spent && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $spent)) $spent = null;
            $desc = mb_substr(trim((string)($_POST['description'] ?? '')),0,255);
            if ($mode === 'mileage') {
                $km = max(0, min(100000, (int)($_POST['km'] ?? 0)));
                $cv = max(1, min(50, (int)($_POST['vehicle_cv'] ?? 5)));
                $amt = nf_ik_amount_cents($cv, $km);
                $st = $pdo->prepare("INSERT INTO expense_report_lines (report_id,org_id,spent_at,mode,category,description,amount_ttc_cents,vat_cents,km,vehicle_cv) VALUES (?,?,?,?,?,?,?,0,?,?)");
                $st->execute([$rid,$org_id,$spent,'mileage','mileage',$desc ?: 'Indemnité kilométrique',$amt,$km,$cv]);
            } else {
                $cat = $_POST['category'] ?? 'autre';
                if (!isset(nf_categories()[$cat])) $cat = 'autre';
                $ttc = max(0, $cents($_POST['amount_ttc'] ?? 0));
                $vat = max(0, $cents($_POST['vat'] ?? 0)); if ($vat > $ttc) $vat = 0;
                $st = $pdo->prepare("INSERT INTO expense_report_lines (report_id,org_id,spent_at,mode,category,description,amount_ttc_cents,vat_cents) VALUES (?,?,?,?,?,?,?,?)");
                $st->execute([$rid,$org_id,$spent,'receipt',$cat,$desc,$ttc,$vat]);
            }
            nf_recompute_total($pdo,$org_id,$rid);
            header('Location: /notes-de-frais?id='.$rid); exit;
        }
        if ($act === 'delete-line' && ($r=$own($rid))) {
            if ($r['status']==='draft') {
                $lid=(int)($_POST['line_id']??0);
                $pdo->prepare("DELETE FROM expense_report_lines WHERE id=? AND report_id=? AND org_id=?")->execute([$lid,$rid,$org_id]);
                nf_recompute_total($pdo,$org_id,$rid);
            }
            header('Location: /notes-de-frais?id='.$rid); exit;
        }
        if ($act === 'submit' && ($r=$own($rid))) {
            if ($r['status']==='draft') $pdo->prepare("UPDATE expense_reports SET status='submitted' WHERE id=? AND org_id=?")->execute([$rid,$org_id]);
            header('Location: /notes-de-frais?id='.$rid); exit;
        }
        if ($act === 'delete-report' && ($r=$own($rid))) {
            if ($r['status']==='draft' || $is_manager) {
                $pdo->prepare("DELETE FROM expense_report_lines WHERE report_id=? AND org_id=?")->execute([$rid,$org_id]);
                $pdo->prepare("DELETE FROM expense_reports WHERE id=? AND org_id=?")->execute([$rid,$org_id]);
            }
            header('Location: /notes-de-frais'); exit;
        }
        if ($act === 'decide' && $is_manager) {
            $st = $pdo->prepare("SELECT status FROM expense_reports WHERE id=? AND org_id=?"); $st->execute([$rid,$org_id]);
            $cur = $st->fetchColumn();
            if ($cur !== false) {
                $dec = $_POST['decision'] ?? '';
                // Transitions autorisées uniquement (pas de saut d'état) :
                $allowed = [
                    'approve'   => ['from'=>['submitted'],            'to'=>'approved'],
                    'reject'    => ['from'=>['submitted'],            'to'=>'rejected'],
                    'reimburse' => ['from'=>['approved'],             'to'=>'reimbursed'],
                ];
                if (isset($allowed[$dec]) && in_array($cur, $allowed[$dec]['from'], true)) {
                    $pdo->prepare("UPDATE expense_reports SET status=?, decided_by=?, decided_at=NOW() WHERE id=? AND org_id=?")
                        ->execute([$allowed[$dec]['to'],$uid,$rid,$org_id]);
                }
            }
            header('Location: /notes-de-frais?id='.$rid); exit;
        }
    } catch (Throwable $e) { error_log('[notes-frais] '.$e->getMessage()); }
    header('Location: /notes-de-frais'); exit;
}

// ── Affichage ────────────────────────────────────────────────────────
$view_id = (int)($_GET['id'] ?? 0);
$report = $view_id ? nf_load_report($pdo, $org_id, $view_id, $restrict) : null;
$bm = nf_bareme_meta();

render_head('Notes de frais');
echo render_sidebar('notes-de-frais');
?>
<main class="main">
  <div class="nf-page">
    <?php if ($report):
      $sm = nf_status_meta($report['status']);
      $editable = ($report['status'] === 'draft');
    ?>
      <a href="/notes-de-frais" class="nf-back"><?= ak_icon('arrow-left',13) ?> Toutes les notes</a>
      <div class="nf-head">
        <h1 class="nf-title"><?= ak_icon_badge('briefcase','#059669',36) ?><span><?= h($report['title']) ?></span></h1>
        <span class="nf-status" style="background:<?= $sm[2] ?>;color:<?= $sm[1] ?>;"><?= h($sm[0]) ?></span>
      </div>

      <div class="nf-tablewrap">
        <table class="nf-table">
          <thead><tr><th>Date</th><th>Type</th><th>Description</th><th class="r">Montant TTC</th><?php if ($editable): ?><th></th><?php endif; ?></tr></thead>
          <tbody>
          <?php if (empty($report['lines'])): ?>
            <tr><td colspan="5" class="nf-muted">Aucune ligne pour l'instant.</td></tr>
          <?php else: foreach ($report['lines'] as $l): ?>
            <tr>
              <td><?= $l['spent_at'] ? h(date('d/m/Y', strtotime($l['spent_at']))) : '—' ?></td>
              <td><?= $l['mode']==='mileage' ? h((int)$l['km'].' km · '.(int)$l['vehicle_cv'].' CV') : h(nf_categories()[$l['category']] ?? $l['category']) ?></td>
              <td><?= h($l['description'] ?: '—') ?></td>
              <td class="r"><?= nf_eur((int)$l['amount_ttc_cents']) ?><?php if ((int)$l['vat_cents']>0): ?> <span class="nf-muted">(dont TVA <?= nf_eur((int)$l['vat_cents']) ?>)</span><?php endif; ?></td>
              <?php if ($editable): ?>
              <td class="r"><form method="POST" onsubmit="return confirm('Supprimer cette ligne ?')" style="display:inline;"><input type="hidden" name="csrf_token" value="<?= $csrf ?>"><input type="hidden" name="action" value="delete-line"><input type="hidden" name="report_id" value="<?= (int)$report['id'] ?>"><input type="hidden" name="line_id" value="<?= (int)$l['id'] ?>"><button class="nf-x" title="Supprimer"><?= ak_icon('trash',13,'2') ?></button></form></td>
              <?php endif; ?>
            </tr>
          <?php endforeach; endif; ?>
          </tbody>
          <tfoot><tr><td colspan="3">Total</td><td class="r"><strong><?= nf_eur((int)$report['total_cents']) ?></strong></td><?php if ($editable): ?><td></td><?php endif; ?></tr></tfoot>
        </table>
      </div>

      <?php if ($editable): ?>
      <div class="nf-card">
        <div class="nf-card-h"><?= ak_icon('plus',15) ?> Ajouter une ligne</div>
        <form method="POST" class="nf-lineform">
          <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
          <input type="hidden" name="action" value="add-line">
          <input type="hidden" name="report_id" value="<?= (int)$report['id'] ?>">
          <div class="nf-modes">
            <label><input type="radio" name="mode" value="receipt" checked onchange="nfMode(this)"> Justificatif</label>
            <label><input type="radio" name="mode" value="mileage" onchange="nfMode(this)"> Indemnité kilométrique</label>
          </div>
          <div class="nf-row">
            <label>Date <input type="date" name="spent_at"></label>
            <label>Description <input type="text" name="description" maxlength="255" placeholder="ex : Péage A6, repas réunion…"></label>
          </div>
          <div class="nf-row nf-receipt">
            <label>Catégorie
              <select name="category">
                <?php foreach (nf_categories() as $k=>$v): if($k==='mileage')continue; ?><option value="<?= h($k) ?>"><?= h($v) ?></option><?php endforeach; ?>
              </select>
            </label>
            <label>Montant TTC (€) <input type="text" name="amount_ttc" inputmode="decimal" placeholder="ex : 24,50"></label>
            <label>dont TVA (€) <input type="text" name="vat" inputmode="decimal" placeholder="ex : 4,08"></label>
          </div>
          <div class="nf-row nf-mileage" style="display:none;">
            <label>Distance (km) <input type="number" name="km" min="0" step="1" placeholder="ex : 42"></label>
            <label>Puissance (CV)
              <select name="vehicle_cv"><?php for($c=3;$c<=7;$c++):?><option value="<?= $c ?>" <?= $c===5?'selected':'' ?>><?= $c===3?'≤3':($c===7?'≥7':$c) ?> CV</option><?php endfor;?></select>
            </label>
            <span class="nf-ik-note"><?= ak_icon('info',12) ?> Barème <?= h($bm['ref']) ?> · calcul automatique</span>
          </div>
          <button class="nf-btn primary"><?= ak_icon('plus',14) ?> Ajouter</button>
        </form>
      </div>

      <form method="POST" onsubmit="return confirm('Soumettre cette note pour validation ?')" style="margin-top:14px;">
        <input type="hidden" name="csrf_token" value="<?= $csrf ?>"><input type="hidden" name="action" value="submit"><input type="hidden" name="report_id" value="<?= (int)$report['id'] ?>">
        <button class="nf-btn primary" <?= empty($report['lines'])?'disabled':'' ?>><?= ak_icon('send',14) ?> Soumettre pour validation</button>
      </form>
      <?php endif; ?>

      <?php if ($is_manager && $report['status']==='submitted'): ?>
      <div class="nf-decide">
        <form method="POST" style="display:inline;"><input type="hidden" name="csrf_token" value="<?= $csrf ?>"><input type="hidden" name="action" value="decide"><input type="hidden" name="decision" value="approve"><input type="hidden" name="report_id" value="<?= (int)$report['id'] ?>"><button class="nf-btn ok"><?= ak_icon('check-circle',14) ?> Approuver</button></form>
        <form method="POST" style="display:inline;"><input type="hidden" name="csrf_token" value="<?= $csrf ?>"><input type="hidden" name="action" value="decide"><input type="hidden" name="decision" value="reject"><input type="hidden" name="report_id" value="<?= (int)$report['id'] ?>"><button class="nf-btn danger"><?= ak_icon('close',14,'2') ?> Refuser</button></form>
      </div>
      <?php elseif ($is_manager && $report['status']==='approved'): ?>
      <form method="POST" style="margin-top:12px;"><input type="hidden" name="csrf_token" value="<?= $csrf ?>"><input type="hidden" name="action" value="decide"><input type="hidden" name="decision" value="reimburse"><input type="hidden" name="report_id" value="<?= (int)$report['id'] ?>"><button class="nf-btn primary"><?= ak_icon('wallet',14) ?> Marquer remboursée</button></form>
      <?php endif; ?>

      <p class="nf-note"><?= ak_icon('info',13) ?> <?= h($bm['note']) ?></p>

    <?php else: // liste ?>
      <div class="nf-head">
        <h1 class="nf-title"><?= ak_icon_badge('briefcase','#059669',36) ?><span>Notes de frais</span></h1>
      </div>
      <p class="nf-sub">Remboursements de frais (justificatifs + indemnités kilométriques) pour les bénévoles et salariés. <?= $is_manager ? 'Vous voyez toutes les notes de la structure.' : 'Vous voyez vos notes.' ?></p>

      <form method="POST" class="nf-new">
        <input type="hidden" name="csrf_token" value="<?= $csrf ?>"><input type="hidden" name="action" value="create-report">
        <input type="text" name="title" maxlength="160" placeholder="Titre (ex : Frais déplacement mars)" class="nf-new-input">
        <button class="nf-btn primary"><?= ak_icon('plus',14) ?> Nouvelle note</button>
      </form>

      <?php $reports = nf_list_reports($pdo, $org_id, $restrict); ?>
      <?php if (empty($reports)): ?>
        <div class="nf-empty"><?= ak_icon('briefcase',44,'1.5') ?><p>Aucune note de frais pour l'instant.</p></div>
      <?php else: ?>
        <div class="nf-list">
          <?php foreach ($reports as $r): $sm = nf_status_meta($r['status']); ?>
            <a href="/notes-de-frais?id=<?= (int)$r['id'] ?>" class="nf-item">
              <div class="nf-item-main">
                <div class="nf-item-title"><?= h($r['title']) ?></div>
                <div class="nf-item-meta"><?= h(trim(($r['first_name']??'').' '.($r['last_name']??''))) ?: 'Utilisateur' ?> · <?= h(date('d/m/Y', strtotime($r['created_at']))) ?></div>
              </div>
              <div class="nf-item-amt"><?= nf_eur((int)$r['total_cents']) ?></div>
              <span class="nf-status" style="background:<?= $sm[2] ?>;color:<?= $sm[1] ?>;"><?= h($sm[0]) ?></span>
            </a>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</main>

<style>
.nf-page { max-width:900px; margin:0 auto; padding:24px 22px; }
.nf-back { display:inline-flex; align-items:center; gap:5px; color:#64748B; text-decoration:none; font-size:13px; margin-bottom:12px; }
.nf-back:hover { color:#334155; }
.nf-head { display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap; margin-bottom:6px; }
.nf-title { font-size:22px; margin:0; color:#0F172A; display:flex; align-items:center; gap:11px; }
.nf-sub { color:#64748B; font-size:14px; margin:2px 0 16px; }
.nf-status { font-size:11.5px; font-weight:700; padding:4px 11px; border-radius:999px; white-space:nowrap; }
.nf-new { display:flex; gap:8px; margin-bottom:16px; flex-wrap:wrap; }
.nf-new-input { flex:1; min-width:220px; padding:9px 12px; border:1px solid #E2E8F0; border-radius:9px; font-family:inherit; font-size:14px; }
.nf-btn { display:inline-flex; align-items:center; gap:6px; padding:9px 15px; border-radius:9px; font-size:13.5px; font-weight:650; cursor:pointer; border:1px solid transparent; font-family:inherit; }
.nf-btn svg { flex:none; }
.nf-btn.primary { background:linear-gradient(135deg,#10B981,#059669); color:#fff; }
.nf-btn.primary:disabled { opacity:.5; cursor:not-allowed; }
.nf-btn.ok { background:#D1FAE5; color:#065F46; } .nf-btn.danger { background:#FEE2E2; color:#991B1B; }
.nf-list { display:flex; flex-direction:column; gap:8px; }
.nf-item { display:flex; align-items:center; gap:14px; background:#fff; border:1px solid #E5E7EB; border-radius:11px; padding:12px 15px; text-decoration:none; color:inherit; }
.nf-item:hover { border-color:#CBD5E1; }
.nf-item-main { flex:1; min-width:0; } .nf-item-title { font-weight:700; color:#0F172A; font-size:14px; } .nf-item-meta { font-size:12px; color:#64748B; margin-top:2px; }
.nf-item-amt { font-weight:750; color:#0F172A; font-size:14px; }
.nf-tablewrap { overflow-x:auto; background:#fff; border:1px solid #E5E7EB; border-radius:12px; margin:12px 0; }
.nf-table { width:100%; border-collapse:collapse; font-size:13px; min-width:480px; }
.nf-table th { text-align:left; padding:9px 12px; border-bottom:2px solid #E2E8F0; color:#64748B; font-size:11px; text-transform:uppercase; background:#F8FAFC; }
.nf-table td { padding:9px 12px; border-bottom:1px solid #F1F5F9; } .nf-table .r { text-align:right; }
.nf-table tfoot td { border-top:2px solid #E2E8F0; background:#F8FAFC; }
.nf-muted { color:#94A3B8; font-size:11.5px; }
.nf-x { border:0; background:none; color:#94A3B8; cursor:pointer; padding:2px; } .nf-x:hover { color:#EF4444; }
.nf-card { background:#fff; border:1px solid #E5E7EB; border-radius:12px; padding:14px 16px; margin-top:8px; }
.nf-card-h { font-weight:750; color:#0F172A; font-size:14px; margin-bottom:12px; display:flex; align-items:center; gap:7px; }
.nf-modes { display:flex; gap:16px; margin-bottom:12px; font-size:13.5px; color:#334155; }
.nf-modes label { display:inline-flex; align-items:center; gap:6px; cursor:pointer; }
.nf-row { display:flex; gap:12px; flex-wrap:wrap; margin-bottom:12px; align-items:flex-end; }
.nf-row label { display:flex; flex-direction:column; gap:4px; font-size:12px; color:#475569; font-weight:600; flex:1; min-width:130px; }
.nf-row input, .nf-row select { padding:8px 10px; border:1px solid #E2E8F0; border-radius:8px; font-family:inherit; font-size:13.5px; }
.nf-ik-note { display:inline-flex; align-items:center; gap:5px; font-size:12px; color:#64748B; align-self:center; }
.nf-decide { margin-top:14px; display:flex; gap:8px; }
.nf-empty { text-align:center; padding:44px 20px; background:#fff; border:2px dashed #E5E7EB; border-radius:14px; color:#94A3B8; }
.nf-empty p { color:#64748B; margin:10px 0 0; font-size:14px; }
.nf-note { font-size:12px; color:#94A3B8; margin-top:18px; line-height:1.5; display:flex; align-items:flex-start; gap:6px; }
.nf-note svg { flex:none; margin-top:2px; }
</style>
<script>
function nfMode(el){
  var mileage = el.value === 'mileage';
  document.querySelector('.nf-receipt').style.display = mileage ? 'none' : 'flex';
  document.querySelector('.nf-mileage').style.display = mileage ? 'flex' : 'none';
}
</script>
<?php render_foot(); ?>
