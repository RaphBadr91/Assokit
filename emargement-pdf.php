<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/rate-limit-helper.php';
ak_rate_limit_or_die('pdf_emargement', 15, 60, (string)($_SESSION['user_id'] ?? ($_SERVER['REMOTE_ADDR'] ?? 'anon')));
require_once __DIR__ . '/includes-layout.php';
require_once __DIR__ . '/includes-attendance.php';
require_login();
$user = current_user(); $org_id = (int)$user['org_id'];
if (!in_array($user['role'], ['admin','coordinator'], true)) { http_response_code(403); die('Réservé.'); }

$id = (int)($_GET['id'] ?? 0);
$sess = att_load($pdo, $id, $org_id);
if (!$sess) { http_response_code(404); die('Introuvable.'); }
$records = att_load_records($pdo, $id);

$org_name = '';
try { $stmt = $pdo->prepare("SELECT name FROM organizations WHERE id = ?"); $stmt->execute([$org_id]); $org_name = $stmt->fetchColumn() ?: ''; } catch (Throwable $e) {}

$use_mpdf = false;
foreach ([__DIR__ . '/vendor/autoload.php', __DIR__ . '/lib/mpdf/autoload.php'] as $p) {
    if (file_exists($p)) { require_once $p; if (class_exists('\Mpdf\Mpdf')) { $use_mpdf = true; break; } }
}

ob_start();
?><!doctype html>
<html><head><meta charset="utf-8"><title>Feuille d'émargement</title>
<style>
@page { margin: 18mm 14mm; }
body { font-family: Helvetica, sans-serif; font-size: 10pt; color: #111; }
h1 { font-size: 16pt; color: #065F46; border-bottom: 2px solid #10B981; padding-bottom: 5px; margin: 0 0 12px; }
.meta { background: #f9fafb; padding: 10px 14px; border-radius: 6px; margin-bottom: 14px; font-size: 9.5pt; }
.meta strong { color: #065F46; }
table { width: 100%; border-collapse: collapse; }
th, td { padding: 6px 8px; border: 1px solid #d1d5db; text-align: left; font-size: 9pt; }
th { background: #f9fafb; font-weight: 700; color: #065F46; }
td.sig { width: 110px; height: 38px; }
td.sig img { max-width: 100px; max-height: 32px; }
.foot { margin-top: 20px; padding-top: 10px; border-top: 1px solid #d1d5db; font-size: 8.5pt; color: #6b7280; }
</style>
</head><body>
<h1>Feuille d'émargement — <?= h($sess['title']) ?></h1>
<div class="meta">
<strong>Association :</strong> <?= h($org_name) ?><br>
<strong>Date :</strong> <?= fr_format_date('%A %d %B %Y à %H:%M', strtotime($sess['starts_at'])) ?>
<?php if ($sess['ends_at']): ?> → <?= date('H:i', strtotime($sess['ends_at'])) ?><?php endif; ?><br>
<?php if ($sess['location']): ?><strong>Lieu :</strong> <?= h($sess['location']) ?><br><?php endif; ?>
<strong>Nombre de présents :</strong> <?= count($records) ?>
</div>

<table>
<thead><tr><th style="width:30px;">#</th><th>Nom complet</th><th>Email</th><th>Téléphone</th><th>Heure</th><th>Signature</th></tr></thead>
<tbody>
<?php foreach ($records as $i => $r): ?>
<tr>
  <td><?= $i + 1 ?></td>
  <td><strong><?= h($r['full_name']) ?></strong></td>
  <td><?= h($r['email'] ?? '') ?></td>
  <td><?= h($r['phone'] ?? '') ?></td>
  <td><?= date('H:i', strtotime($r['signed_at'])) ?></td>
  <td class="sig"><?php if ($r['signature_data']): ?><img src="<?= h($r['signature_data']) ?>"><?php endif; ?></td>
</tr>
<?php endforeach; ?>
<?php if (empty($records)): for ($i = 1; $i <= 15; $i++): ?>
<tr><td><?= $i ?></td><td>&nbsp;</td><td></td><td></td><td></td><td class="sig"></td></tr>
<?php endfor; endif; ?>
</tbody>
</table>

<div class="foot">Document généré le <?= date('d/m/Y à H:i') ?> via AssoKit · feuille à conserver comme preuve de présence</div>
</body></html>
<?php
$html = ob_get_clean();

if ($use_mpdf) {
    $mpdf = new \Mpdf\Mpdf(['mode' => 'utf-8', 'format' => 'A4']);
    $mpdf->WriteHTML($html);
    $mpdf->Output('emargement-' . preg_replace('/[^a-z0-9]+/i', '-', $sess['title']) . '.pdf', 'D');
    exit;
}
header('Content-Type: text/html; charset=UTF-8');
echo $html;
echo '<script>setTimeout(function(){window.print();}, 400);</script>';
exit;
