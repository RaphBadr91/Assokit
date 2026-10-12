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
// Un suiveur (financeur externe) ne voit que les projets qu'il suit : pas
// de notes de frais dans la structure (le menu ne lui propose pas la page).
if (is_follower()) { header('Location: /projets'); exit; }
$is_manager = can('manage_finances');
$restrict = $is_manager ? null : $uid;   // non-gestionnaire : limité à ses notes
$csrf = h($_SESSION['csrf_token'] ?? '');

// ── Traitement des actions (POST) ────────────────────────────────────
$has_receipts = nf_has_receipts($pdo);   // migration 2026-10-11-L06-finances passée ?

// Justificatif : servi ici, après le même contrôle d'accès que la note
// (auteur ou gestionnaire), jamais par une URL directe.
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['receipt'])) {
    $lid = (int)$_GET['receipt'];
    $full = null; $name = 'justificatif';
    if ($has_receipts && $lid > 0) {
        $st = $pdo->prepare("SELECT l.receipt_file, l.receipt_name, r.user_id FROM expense_report_lines l
                             JOIN expense_reports r ON r.id = l.report_id AND r.org_id = l.org_id
                             WHERE l.id = ? AND l.org_id = ?");
        $st->execute([$lid, $org_id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row && ($is_manager || (int)$row['user_id'] === $uid)) {
            $full = nf_receipt_path($org_id, $row['receipt_file']);
            $name = (string)($row['receipt_name'] ?: $name);
        }
    }
    if ($full === null) { http_response_code(404); exit('Justificatif introuvable.'); }
    $MIME_BY_EXT = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
                    'png' => 'image/png', 'webp' => 'image/webp', 'heic' => 'image/heic'];
    $mime = $MIME_BY_EXT[strtolower(pathinfo($full, PATHINFO_EXTENSION))] ?? 'application/octet-stream';
    $real = null;
    try { $real = (new finfo(FILEINFO_MIME_TYPE))->file($full) ?: null; } catch (Throwable $e) {}
    // Affichage dans la page seulement si le contenu est bien du type annoncé.
    $inline = in_array($mime, ['application/pdf', 'image/jpeg', 'image/png', 'image/webp'], true) && $real === $mime;
    if (!$inline) $mime = 'application/octet-stream';
    header('Content-Type: ' . $mime);
    header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . preg_replace('/[^\w.\- ]+/u', '_', $name) . '"');
    header('Content-Length: ' . filesize($full));
    header('X-Content-Type-Options: nosniff');
    if ($mime !== 'application/pdf') header("Content-Security-Policy: sandbox; default-src 'none'; img-src 'self' data:; style-src 'unsafe-inline'");
    header('Cache-Control: private, max-age=60');
    readfile($full);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Envoi plus gros que post_max_size : PHP vide $_POST et $_FILES (donc
    // aussi le jeton CSRF). Ce n'est pas une session expirée : on le dit et
    // on revient sur la note (son id est dans l'URL du formulaire).
    if (empty($_POST) && empty($_FILES) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        $_SESSION['nf_flash'] = ['err', 'Envoi trop volumineux (' . nf_mo(nf_receipt_max_bytes()) . ' maximum par justificatif) : rien n\'a été enregistré.'];
        $back_id = (int)($_GET['id'] ?? 0);
        header('Location: /notes-de-frais' . ($back_id > 0 ? '?id=' . $back_id : ''));
        exit;
    }
    if (!check_csrf($_POST['csrf_token'] ?? '')) { http_response_code(403); exit('CSRF'); }
    $act = $_POST['action'] ?? '';
    $rid = (int)($_POST['report_id'] ?? 0);

    // Message affiché au retour sur la page (POST → Redirect → GET)
    $flash = function(string $msg, string $kind = 'err') { $_SESSION['nf_flash'] = [$kind, $msg]; };
    $back = function(int $id) { header('Location: /notes-de-frais' . ($id ? '?id=' . $id : '')); exit; };
    // Vérifie l'accès à la note (auteur, ou gestionnaire)
    $own = function($rid) use ($pdo,$org_id,$uid,$is_manager) {
        $st = $pdo->prepare("SELECT * FROM expense_reports WHERE id=? AND org_id=?"); $st->execute([$rid,$org_id]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r) return null;
        if (!$is_manager && (int)$r['user_id'] !== $uid) return null;
        return $r;
    };
    // Justificatifs d'une note, pour effacer les fichiers avec les lignes
    $receipts_of = function(int $rid, ?int $lid = null) use ($pdo, $org_id, $has_receipts): array {
        if (!$has_receipts) return [];
        $sql = "SELECT receipt_file FROM expense_report_lines WHERE report_id=? AND org_id=? AND receipt_file IS NOT NULL";
        $args = [$rid, $org_id];
        if ($lid !== null) { $sql .= " AND id=?"; $args[] = $lid; }
        $st = $pdo->prepare($sql); $st->execute($args);
        return $st->fetchAll(PDO::FETCH_COLUMN) ?: [];
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
            if ($r['status'] !== 'draft') $back($rid);
            $mode = ($_POST['mode'] ?? 'receipt') === 'mileage' ? 'mileage' : 'receipt';
            $spent = ($_POST['spent_at'] ?? '') ?: null;
            if ($spent && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $spent)) $spent = null;
            $desc = mb_substr(trim((string)($_POST['description'] ?? '')),0,255);
            if ($mode === 'mileage') {
                $km = (int)($_POST['km'] ?? 0);
                if ($km <= 0 || $km > 100000) { $flash('Indiquez une distance valide en kilomètres (de 1 à 100 000 km).'); $back($rid); }
                $cv = max(1, min(50, (int)($_POST['vehicle_cv'] ?? 5)));
                $amt = nf_ik_amount_cents($cv, $km);
                $st = $pdo->prepare("INSERT INTO expense_report_lines (report_id,org_id,spent_at,mode,category,description,amount_ttc_cents,vat_cents,km,vehicle_cv) VALUES (?,?,?,?,?,?,?,0,?,?)");
                $st->execute([$rid,$org_id,$spent,'mileage','mileage',$desc ?: 'Indemnité kilométrique',$amt,$km,$cv]);
            } else {
                $cat = $_POST['category'] ?? 'autre';
                if (!isset(nf_categories()[$cat])) $cat = 'autre';
                $ttc = nf_parse_cents($_POST['amount_ttc'] ?? '');
                if ($ttc === null || $ttc <= 0) { $flash('Montant TTC invalide : saisissez un montant supérieur à 0, par exemple 24,50 ou 1 234,56.'); $back($rid); }
                $vat_raw = trim((string)($_POST['vat'] ?? ''));
                $vat = $vat_raw === '' ? 0 : nf_parse_cents($vat_raw);
                if ($vat === null || $vat >= $ttc) { $flash('Montant de TVA invalide : il doit être inférieur au montant TTC (laissez vide s\'il n\'y en a pas).'); $back($rid); }
                $rec = ['ok' => true];
                if ($has_receipts) {
                    $rec = nf_receipt_store($_FILES['receipt'] ?? [], $org_id);
                    if (!$rec['ok']) { $flash($rec['message']); $back($rid); }
                }
                try {
                    $st = $pdo->prepare("INSERT INTO expense_report_lines (report_id,org_id,spent_at,mode,category,description,amount_ttc_cents,vat_cents) VALUES (?,?,?,?,?,?,?,?)");
                    $st->execute([$rid,$org_id,$spent,'receipt',$cat,$desc,$ttc,$vat]);
                    if (!empty($rec['file'])) {
                        $pdo->prepare("UPDATE expense_report_lines SET receipt_file=?, receipt_name=? WHERE id=? AND org_id=?")
                            ->execute([$rec['file'], $rec['name'], (int)$pdo->lastInsertId(), $org_id]);
                    }
                } catch (Throwable $e) {
                    if (!empty($rec['file'])) nf_receipt_delete($org_id, $rec['file']);   // pas de fichier orphelin
                    throw $e;
                }
            }
            nf_recompute_total($pdo,$org_id,$rid);
            $back($rid);
        }
        if ($act === 'attach-receipt' && $has_receipts && ($r=$own($rid))) {
            // Ajouter / remplacer le justificatif d'une ligne (brouillon seulement)
            if ($r['status'] === 'draft') {
                $lid = (int)($_POST['line_id'] ?? 0);
                $old = $receipts_of($rid, $lid);
                $rec = nf_receipt_store($_FILES['receipt'] ?? [], $org_id);
                if (!$rec['ok']) $flash($rec['message']);
                elseif (empty($rec['file'])) $flash('Choisissez le fichier du justificatif.');
                else {
                    $st = $pdo->prepare("UPDATE expense_report_lines SET receipt_file=?, receipt_name=? WHERE id=? AND report_id=? AND org_id=?");
                    $st->execute([$rec['file'], $rec['name'], $lid, $rid, $org_id]);
                    if ($st->rowCount() > 0) { foreach ($old as $f) nf_receipt_delete($org_id, $f); $flash('Justificatif ajouté.', 'ok'); }
                    else nf_receipt_delete($org_id, $rec['file']);
                }
            }
            $back($rid);
        }
        if ($act === 'delete-line' && ($r=$own($rid))) {
            if ($r['status']==='draft') {
                $lid=(int)($_POST['line_id']??0);
                $files = $receipts_of($rid, $lid);
                $pdo->prepare("DELETE FROM expense_report_lines WHERE id=? AND report_id=? AND org_id=?")->execute([$lid,$rid,$org_id]);
                foreach ($files as $f) nf_receipt_delete($org_id, $f);
                nf_recompute_total($pdo,$org_id,$rid);
            }
            $back($rid);
        }
        if ($act === 'submit' && ($r=$own($rid))) {
            if ($r['status']==='draft') {
                // Une note vide ou à 0 € ne part pas en validation (le bouton
                // était seulement grisé dans la page : le serveur acceptait).
                $total = nf_recompute_total($pdo,$org_id,$rid);
                $st = $pdo->prepare("SELECT COUNT(*) FROM expense_report_lines WHERE report_id=? AND org_id=?"); $st->execute([$rid,$org_id]);
                if ((int)$st->fetchColumn() === 0 || $total <= 0) { $flash('Ajoutez au moins une dépense avant de soumettre la note.'); $back($rid); }
                $pdo->prepare("UPDATE expense_reports SET status='submitted' WHERE id=? AND org_id=? AND status='draft'")->execute([$rid,$org_id]);
                $who = trim(($user['first_name'] ?? '').' '.($user['last_name'] ?? '')) ?: 'Un membre';
                nf_notify($pdo, nf_managers($pdo, $org_id, (int)$r['user_id']), 'Note de frais à valider : '.mb_substr((string)$r['title'], 0, 120),
                    $who.' · '.nf_eur($total), $rid, $uid);
                $flash('Note soumise : les gestionnaires des finances sont prévenus.', 'ok');
            }
            $back($rid);
        }
        if ($act === 'reopen' && ($r=$own($rid))) {
            // Note refusée : son auteur la repasse en brouillon pour la corriger
            if ($r['status']==='rejected' && (int)$r['user_id'] === $uid) {
                $pdo->prepare("UPDATE expense_reports SET status='draft' WHERE id=? AND org_id=? AND status='rejected'")->execute([$rid,$org_id]);
                $flash('Note repassée en brouillon : corrigez-la puis soumettez-la à nouveau.', 'ok');
            }
            $back($rid);
        }
        if ($act === 'delete-report' && ($r=$own($rid))) {
            $mine = (int)$r['user_id'] === $uid;
            if ($r['status']==='draft' || ($r['status']==='rejected' && $mine) || $is_manager) {
                $files = $receipts_of($rid);
                $pdo->prepare("DELETE FROM expense_report_lines WHERE report_id=? AND org_id=?")->execute([$rid,$org_id]);
                $pdo->prepare("DELETE FROM expense_reports WHERE id=? AND org_id=?")->execute([$rid,$org_id]);
                foreach ($files as $f) nf_receipt_delete($org_id, $f);
                $flash('Note de frais supprimée.', 'ok');
            }
            $back(0);
        }
        if ($act === 'decide' && $is_manager) {
            $st = $pdo->prepare("SELECT * FROM expense_reports WHERE id=? AND org_id=?"); $st->execute([$rid,$org_id]);
            $cur = $st->fetch(PDO::FETCH_ASSOC);
            if ($cur) {
                $dec = $_POST['decision'] ?? '';
                // Transitions autorisées uniquement (pas de saut d'état) :
                $allowed = [
                    'approve'   => ['from'=>['submitted'],            'to'=>'approved'],
                    'reject'    => ['from'=>['submitted'],            'to'=>'rejected'],
                    'reimburse' => ['from'=>['approved'],             'to'=>'reimbursed'],
                ];
                // Sa propre note : décidée par un autre gestionnaire s'il y en a un.
                // Seul gestionnaire de la structure : permis, mais la note l'indique
                // (« validée par son auteur ») et le journal d'activité le garde.
                $self = (int)$cur['user_id'] === $uid;
                if ($self && isset($allowed[$dec]) && nf_managers($pdo, $org_id, $uid)) {
                    $flash('Votre propre note de frais doit être traitée par un autre gestionnaire des finances.');
                    $back($rid);
                }
                if (isset($allowed[$dec]) && in_array($cur['status'], $allowed[$dec]['from'], true)) {
                    // Motif obligatoire pour un refus : il est montré au demandeur.
                    $reason = mb_substr(trim((string)($_POST['reason'] ?? '')), 0, 500);
                    if ($dec === 'reject' && $reason === '') { $flash('Indiquez le motif du refus : il sera montré au demandeur.'); $back($rid); }
                    // Le motif d'un refus précédent ne vaut plus une fois la note approuvée.
                    $note = $dec === 'reject' ? $reason : ($dec === 'approve' ? null : $cur['note']);
                    $pdo->prepare("UPDATE expense_reports SET status=?, note=?, decided_by=?, decided_at=NOW() WHERE id=? AND org_id=?")
                        ->execute([$allowed[$dec]['to'],$note,$uid,$rid,$org_id]);
                    $msg = [
                        'approve'   => ['Note de frais approuvée : ', 'Le remboursement va être effectué.'],
                        'reject'    => ['Note de frais refusée : ',   'Motif : '.$reason],
                        'reimburse' => ['Note de frais remboursée : ', nf_eur((int)$cur['total_cents']).' remboursés.'],
                    ][$dec];
                    if (!$self) {
                        nf_notify($pdo, [(int)$cur['user_id']], $msg[0].mb_substr((string)$cur['title'], 0, 120), $msg[1], $rid, $uid);
                    } elseif ($dec !== 'reject') {
                        require_once __DIR__ . '/activity-tracker.php';
                        if (function_exists('activity_log_action')) {
                            activity_log_action('expense_report_self_' . $dec, ['report_id' => $rid, 'total_cents' => (int)$cur['total_cents']], (string)$rid);
                        }
                    }
                }
            }
            $back($rid);
        }
    } catch (Throwable $e) { error_log('[notes-frais] '.$e->getMessage()); $flash('L\'action n\'a pas pu être enregistrée. Réessayez.'); }
    header('Location: /notes-de-frais'); exit;
}

// ── Affichage ────────────────────────────────────────────────────────
$view_id = (int)($_GET['id'] ?? 0);
$report = $view_id ? nf_load_report($pdo, $org_id, $view_id, $restrict) : null;
$bm = nf_bareme_meta();
$nf_flash = $_SESSION['nf_flash'] ?? null;
unset($_SESSION['nf_flash']);

render_head('Notes de frais');
echo render_sidebar('notes-de-frais');
?>
<main class="main">
  <div class="nf-page">
    <?php if (is_array($nf_flash) && isset($nf_flash[1])): ?>
      <div class="nf-flash <?= $nf_flash[0] === 'ok' ? 'ok' : 'err' ?>" role="status"><?= ak_icon($nf_flash[0] === 'ok' ? 'check-circle' : 'alert-tri', 15) ?> <?= h($nf_flash[1]) ?></div>
    <?php endif; ?>
    <?php if ($report):
      $sm = nf_status_meta($report['status']);
      $editable = ($report['status'] === 'draft');
      $is_author = ((int)$report['user_id'] === $uid);
      $ncols = 5 + ($has_receipts ? 1 : 0);
      // Gestionnaire et auteur : un autre gestionnaire décide, s'il y en a un.
      $can_decide = $is_manager && (!$is_author || !nf_managers($pdo, $org_id, $uid));
      $self_decided = !empty($report['decided_by']) && (int)$report['decided_by'] === (int)$report['user_id']
                      && in_array($report['status'], ['approved','reimbursed'], true);
    ?>
      <a href="/notes-de-frais" class="nf-back"><?= ak_icon('arrow-left',13) ?> Toutes les notes</a>
      <div class="nf-head">
        <h1 class="nf-title"><?= ak_icon_badge('briefcase','#059669',36) ?><span><?= h($report['title']) ?></span></h1>
        <span class="nf-status" style="background:<?= $sm[2] ?>;color:<?= $sm[1] ?>;"><?= h($sm[0]) ?></span>
      </div>

      <?php if (!empty($report['note']) && in_array($report['status'], ['rejected','draft','submitted'], true)): ?>
        <div class="nf-reason">
          <strong><?= $report['status'] === 'rejected' ? 'Motif du refus' : 'Motif du refus précédent' ?><?= !empty($report['decided_at']) ? ' ('.h(date('d/m/Y', strtotime($report['decided_at']))).')' : '' ?> :</strong>
          <?= nl2br(h($report['note'])) ?>
        </div>
      <?php endif; ?>
      <?php if ($report['status'] === 'rejected' && $is_author): ?>
        <div class="nf-actions">
          <form method="POST"><input type="hidden" name="csrf_token" value="<?= $csrf ?>"><input type="hidden" name="action" value="reopen"><input type="hidden" name="report_id" value="<?= (int)$report['id'] ?>"><button class="nf-btn primary"><?= ak_icon('edit',14) ?> Corriger et soumettre à nouveau</button></form>
          <form method="POST" onsubmit="return confirm('Supprimer définitivement cette note de frais ?')"><input type="hidden" name="csrf_token" value="<?= $csrf ?>"><input type="hidden" name="action" value="delete-report"><input type="hidden" name="report_id" value="<?= (int)$report['id'] ?>"><button class="nf-btn ghost"><?= ak_icon('trash',14) ?> Supprimer la note</button></form>
        </div>
      <?php endif; ?>

      <div class="nf-tablewrap">
        <table class="nf-table">
          <thead><tr><th>Date</th><th>Type</th><th>Description</th><?php if ($has_receipts): ?><th>Justificatif</th><?php endif; ?><th class="r">Montant TTC</th><?php if ($editable): ?><th></th><?php endif; ?></tr></thead>
          <tbody>
          <?php if (empty($report['lines'])): ?>
            <tr><td colspan="<?= $ncols ?>" class="nf-muted">Aucune ligne pour l'instant.</td></tr>
          <?php else: foreach ($report['lines'] as $l): ?>
            <tr>
              <td><?= $l['spent_at'] ? h(date('d/m/Y', strtotime($l['spent_at']))) : '—' ?></td>
              <td><?= $l['mode']==='mileage' ? h((int)$l['km'].' km · '.(int)$l['vehicle_cv'].' CV') : h(nf_categories()[$l['category']] ?? $l['category']) ?></td>
              <td><?= h($l['description'] ?: '—') ?></td>
              <?php if ($has_receipts): ?>
              <td>
                <?php if (!empty($l['receipt_file'])): ?>
                  <a href="/notes-de-frais?receipt=<?= (int)$l['id'] ?>" target="_blank" rel="noopener" class="nf-receipt-link" title="<?= h($l['receipt_name'] ?: 'Justificatif') ?>"><?= ak_icon('file',13) ?> Voir</a>
                <?php elseif ($l['mode'] === 'mileage'): ?>
                  <span class="nf-muted">—</span>
                <?php else: ?>
                  <span class="nf-missing">Aucun</span>
                <?php endif; ?>
                <?php if ($editable && $l['mode'] !== 'mileage'): ?>
                  <form method="POST" action="/notes-de-frais?id=<?= (int)$report['id'] ?>" enctype="multipart/form-data" class="nf-attach">
                    <input type="hidden" name="csrf_token" value="<?= $csrf ?>"><input type="hidden" name="action" value="attach-receipt"><input type="hidden" name="report_id" value="<?= (int)$report['id'] ?>"><input type="hidden" name="line_id" value="<?= (int)$l['id'] ?>">
                    <label class="nf-attach-btn" title="<?= !empty($l['receipt_file']) ? 'Remplacer le justificatif' : 'Joindre le justificatif' ?>"><?= ak_icon('upload',12) ?> <?= !empty($l['receipt_file']) ? 'Remplacer' : 'Joindre' ?><input type="file" name="receipt" accept=".pdf,.jpg,.jpeg,.png,.webp,.heic,image/*,application/pdf" onchange="nfAttach(this)" hidden></label>
                  </form>
                <?php endif; ?>
              </td>
              <?php endif; ?>
              <td class="r"><?= nf_eur((int)$l['amount_ttc_cents']) ?><?php if ((int)$l['vat_cents']>0): ?> <span class="nf-muted">(dont TVA <?= nf_eur((int)$l['vat_cents']) ?>)</span><?php endif; ?></td>
              <?php if ($editable): ?>
              <td class="r"><form method="POST" onsubmit="return confirm('Supprimer cette ligne ?')" style="display:inline;"><input type="hidden" name="csrf_token" value="<?= $csrf ?>"><input type="hidden" name="action" value="delete-line"><input type="hidden" name="report_id" value="<?= (int)$report['id'] ?>"><input type="hidden" name="line_id" value="<?= (int)$l['id'] ?>"><button class="nf-x" title="Supprimer"><?= ak_icon('trash',13,'2') ?></button></form></td>
              <?php endif; ?>
            </tr>
          <?php endforeach; endif; ?>
          </tbody>
          <tfoot><tr><td colspan="<?= $has_receipts ? 4 : 3 ?>">Total</td><td class="r"><strong><?= nf_eur((int)$report['total_cents']) ?></strong></td><?php if ($editable): ?><td></td><?php endif; ?></tr></tfoot>
        </table>
      </div>

      <?php if ($editable): ?>
      <div class="nf-card">
        <div class="nf-card-h"><?= ak_icon('plus',15) ?> Ajouter une ligne</div>
        <form method="POST" action="/notes-de-frais?id=<?= (int)$report['id'] ?>" class="nf-lineform" enctype="multipart/form-data">
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
          <?php if ($has_receipts): ?>
          <div class="nf-row nf-receipt">
            <label>Justificatif (photo du ticket ou PDF)
              <input type="file" name="receipt" accept=".pdf,.jpg,.jpeg,.png,.webp,.heic,image/*,application/pdf" onchange="nfSize(this)">
            </label>
            <span class="nf-ik-note"><?= ak_icon('info',12) ?> PDF, JPG, PNG, WebP ou HEIC · <?= h(nf_mo(nf_receipt_max_bytes())) ?> max</span>
          </div>
          <?php endif; ?>
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

      <div class="nf-actions" style="margin-top:14px;">
        <form method="POST" onsubmit="return confirm('Soumettre cette note pour validation ?')">
          <input type="hidden" name="csrf_token" value="<?= $csrf ?>"><input type="hidden" name="action" value="submit"><input type="hidden" name="report_id" value="<?= (int)$report['id'] ?>">
          <button class="nf-btn primary" <?= (empty($report['lines']) || (int)$report['total_cents'] <= 0)?'disabled':'' ?>><?= ak_icon('send',14) ?> Soumettre pour validation</button>
        </form>
        <form method="POST" onsubmit="return confirm('Supprimer ce brouillon et toutes ses lignes ?')">
          <input type="hidden" name="csrf_token" value="<?= $csrf ?>"><input type="hidden" name="action" value="delete-report"><input type="hidden" name="report_id" value="<?= (int)$report['id'] ?>">
          <button class="nf-btn ghost"><?= ak_icon('trash',14) ?> Supprimer le brouillon</button>
        </form>
      </div>
      <?php endif; ?>

      <?php if ($self_decided): ?>
        <p class="nf-selfnote"><?= ak_icon('info',13) ?> <?= $report['status'] === 'reimbursed' ? 'Remboursement enregistré' : 'Note approuvée' ?> par son auteur, seul gestionnaire des finances de la structure à ce moment-là.</p>
      <?php endif; ?>
      <?php if ($is_manager && !$can_decide && in_array($report['status'], ['submitted','approved'], true)): ?>
        <p class="nf-selfnote"><?= ak_icon('info',13) ?> C'est votre note : un autre gestionnaire des finances l'approuve et enregistre son remboursement.</p>
      <?php elseif ($can_decide && $report['status']==='submitted'): ?>
      <div class="nf-decide">
        <form method="POST" style="display:inline;"><input type="hidden" name="csrf_token" value="<?= $csrf ?>"><input type="hidden" name="action" value="decide"><input type="hidden" name="decision" value="approve"><input type="hidden" name="report_id" value="<?= (int)$report['id'] ?>"><button class="nf-btn ok"><?= ak_icon('check-circle',14) ?> Approuver</button></form>
      </div>
      <form method="POST" class="nf-reject">
        <input type="hidden" name="csrf_token" value="<?= $csrf ?>"><input type="hidden" name="action" value="decide"><input type="hidden" name="decision" value="reject"><input type="hidden" name="report_id" value="<?= (int)$report['id'] ?>">
        <label>Motif du refus (montré au demandeur)
          <textarea name="reason" rows="2" maxlength="500" required placeholder="ex : justificatif illisible, dépense hors du projet…"></textarea>
        </label>
        <button class="nf-btn danger"><?= ak_icon('close',14,'2') ?> Refuser</button>
      </form>
      <?php elseif ($can_decide && $report['status']==='approved'): ?>
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
.nf-muted { color:#64748B; font-size:11.5px; }
.nf-x { border:0; background:none; color:#64748B; cursor:pointer; padding:2px; } .nf-x:hover { color:#EF4444; }
.nf-card { background:#fff; border:1px solid #E5E7EB; border-radius:12px; padding:14px 16px; margin-top:8px; }
.nf-card-h { font-weight:750; color:#0F172A; font-size:14px; margin-bottom:12px; display:flex; align-items:center; gap:7px; }
.nf-modes { display:flex; gap:16px; margin-bottom:12px; font-size:13.5px; color:#334155; }
.nf-modes label { display:inline-flex; align-items:center; gap:6px; cursor:pointer; }
.nf-row { display:flex; gap:12px; flex-wrap:wrap; margin-bottom:12px; align-items:flex-end; }
.nf-row label { display:flex; flex-direction:column; gap:4px; font-size:12px; color:#475569; font-weight:600; flex:1; min-width:130px; }
.nf-row input, .nf-row select { padding:8px 10px; border:1px solid #E2E8F0; border-radius:8px; font-family:inherit; font-size:13.5px; }
.nf-ik-note { display:inline-flex; align-items:center; gap:5px; font-size:12px; color:#64748B; align-self:center; }
.nf-decide { margin-top:14px; display:flex; gap:8px; }
.nf-selfnote { display:flex; align-items:center; gap:6px; font-size:13px; color:#475569; background:#F8FAFC; border:1px solid #E2E8F0; border-radius:10px; padding:9px 12px; margin:12px 0 0; }
.nf-selfnote svg { flex:none; }
.nf-actions { display:flex; gap:8px; flex-wrap:wrap; margin:10px 0; }
.nf-btn.ghost { background:#fff; border-color:#E2E8F0; color:#475569; } .nf-btn.ghost:hover { border-color:#FCA5A5; color:#991B1B; }
.nf-flash { display:flex; align-items:center; gap:8px; padding:10px 14px; border-radius:10px; font-size:13.5px; margin-bottom:14px; }
.nf-flash.ok { background:#ECFDF5; color:#065F46; border:1px solid #A7F3D0; } .nf-flash.err { background:#FEF2F2; color:#991B1B; border:1px solid #FECACA; }
.nf-flash svg { flex:none; }
.nf-reason { background:#FEF2F2; border:1px solid #FECACA; color:#7F1D1D; border-radius:10px; padding:10px 14px; font-size:13.5px; line-height:1.5; margin:8px 0; }
.nf-reject { margin-top:10px; display:flex; gap:10px; align-items:flex-end; flex-wrap:wrap; background:#fff; border:1px solid #E5E7EB; border-radius:12px; padding:12px 14px; }
.nf-reject label { flex:1; min-width:220px; display:flex; flex-direction:column; gap:4px; font-size:12px; color:#475569; font-weight:600; }
.nf-reject textarea { padding:8px 10px; border:1px solid #E2E8F0; border-radius:8px; font-family:inherit; font-size:13.5px; resize:vertical; }
.nf-receipt-link { display:inline-flex; align-items:center; gap:4px; color:#047857; font-weight:600; text-decoration:none; font-size:12.5px; }
.nf-missing { color:#B45309; font-size:12px; font-weight:600; }
.nf-attach { display:inline; margin-left:6px; }
.nf-attach-btn { display:inline-flex; align-items:center; gap:3px; font-size:11.5px; color:#475569; cursor:pointer; border:1px dashed #CBD5E1; border-radius:6px; padding:1px 6px; }
.nf-attach-btn:hover { border-color:#10B981; color:#047857; }
.nf-empty { text-align:center; padding:44px 20px; background:#fff; border:2px dashed #E5E7EB; border-radius:14px; color:#64748B; }
.nf-empty p { color:#64748B; margin:10px 0 0; font-size:14px; }
.nf-note { font-size:12px; color:#64748B; margin-top:18px; line-height:1.5; display:flex; align-items:flex-start; gap:6px; }
.nf-note svg { flex:none; margin-top:2px; }
</style>
<script>
// Justificatif : 10 Mo au plus, ou la limite de l'hébergement si elle est
// plus basse (au-delà, le serveur rejette tout l'envoi)
var NF_MAX = <?= (int)nf_receipt_max_bytes() ?>, NF_MAX_LBL = <?= json_encode(nf_mo(nf_receipt_max_bytes()), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;
function nfSize(i){
  if (i.files && i.files[0] && i.files[0].size > NF_MAX) { alert('Justificatif trop lourd (' + NF_MAX_LBL + ' maximum).'); i.value = ''; return false; }
  return true;
}
function nfAttach(i){ if (nfSize(i) && i.value) i.form.submit(); }
function nfMode(el){
  var mileage = el.value === 'mileage';
  document.querySelectorAll('.nf-receipt').forEach(function(r){ r.style.display = mileage ? 'none' : 'flex'; });
  document.querySelector('.nf-mileage').style.display = mileage ? 'flex' : 'none';
}
</script>
<?php render_foot(); ?>
