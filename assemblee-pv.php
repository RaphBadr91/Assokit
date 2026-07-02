<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes-layout.php';
require_once __DIR__ . '/includes-assemblies.php';
require_login();
$user = current_user(); $org_id = (int)$user['org_id'];
if ($user['role'] !== 'admin') { http_response_code(403); die('Réservé.'); }

$id = (int)($_GET['id'] ?? 0);
$ag = ag_load($pdo, $id, $org_id);
if (!$ag) { http_response_code(404); die('AG introuvable.'); }

$attendees = ag_load_attendees($pdo, $id);
$resos = ag_load_resolutions($pdo, $id);

// Org info
$org_name = '';
try { $stmt = $pdo->prepare("SELECT name FROM organizations WHERE id = ?"); $stmt->execute([$org_id]); $org_name = $stmt->fetchColumn() ?: ''; } catch (Throwable $e) {}

$nb_present = 0; $nb_excused = 0; $nb_absent = 0;
foreach ($attendees as $a) {
    if (in_array($a['status'], ['present','proxy'], true)) $nb_present++;
    elseif ($a['status'] === 'excused') $nb_excused++;
    else $nb_absent++;
}

// Tente mPDF si dispo, sinon HTML imprimable
$use_mpdf = false;
$mpdf_paths = [__DIR__ . '/vendor/autoload.php', __DIR__ . '/lib/mpdf/autoload.php'];
foreach ($mpdf_paths as $p) { if (file_exists($p)) { require_once $p; if (class_exists('\Mpdf\Mpdf')) { $use_mpdf = true; break; } } }

ob_start();
?><!doctype html>
<html><head><meta charset="utf-8"><title>PV — <?= h($ag['title']) ?></title>
<style>
@page { margin: 22mm 18mm; }
body { font-family: 'Helvetica', sans-serif; font-size: 11pt; color: #111; line-height: 1.5; }
h1 { font-size: 18pt; color: #065F46; border-bottom: 2px solid #10B981; padding-bottom: 6px; margin: 0 0 14px; }
h2 { font-size: 13pt; color: #065F46; margin: 22px 0 8px; padding-bottom: 4px; border-bottom: 1px solid #d1fae5; }
.meta { background: #f9fafb; padding: 12px 16px; border-radius: 6px; margin-bottom: 18px; font-size: 10pt; }
.meta strong { color: #065F46; }
table { width: 100%; border-collapse: collapse; margin: 10px 0; }
th, td { padding: 6px 8px; border-bottom: 1px solid #e5e7eb; text-align: left; font-size: 10pt; }
th { background: #f9fafb; font-weight: 600; }
.reso { margin-bottom: 14px; padding: 10px 12px; background: #f9fafb; border-left: 3px solid #10B981; border-radius: 4px; }
.reso-title { font-size: 11pt; font-weight: 700; }
.reso-result { display: inline-block; padding: 2px 9px; border-radius: 12px; font-size: 9pt; font-weight: 600; }
.adopted { background: #ECFDF5; color: #065F46; }
.rejected { background: #FEE2E2; color: #991B1B; }
.pending { background: #f3f4f6; color: #6b7280; }
.foot { margin-top: 30px; padding-top: 14px; border-top: 1px solid #d1d5db; font-size: 9pt; color: #6b7280; }
</style>
</head><body>
<h1>Procès-verbal — <?= h($ag['title']) ?></h1>
<div class="meta">
<strong>Association :</strong> <?= h($org_name) ?><br>
<strong>Type :</strong> <?= h(ag_type_label($ag['type'])) ?><br>
<strong>Date :</strong> <?= fr_format_date('%A %d %B %Y à %H:%M', strtotime($ag['scheduled_at'])) ?><br>
<?php if ($ag['location']): ?><strong>Lieu :</strong> <?= h($ag['location']) ?><br><?php endif; ?>
<?php if ($ag['opened_at']): ?><strong>Ouverture :</strong> <?= date('d/m/Y H:i', strtotime($ag['opened_at'])) ?><br><?php endif; ?>
<?php if ($ag['closed_at']): ?><strong>Clôture :</strong> <?= date('d/m/Y H:i', strtotime($ag['closed_at'])) ?><br><?php endif; ?>
</div>

<h2>Présences</h2>
<p>Présents : <strong><?= $nb_present ?></strong> · Excusés : <strong><?= $nb_excused ?></strong> · Absents : <strong><?= $nb_absent ?></strong> · Total convoqués : <strong><?= count($attendees) ?></strong>
<?php if ($ag['quorum_required']): $q = ag_quorum_status($ag, $attendees); ?>
 · Quorum requis : <strong><?= (int)$ag['quorum_required'] ?></strong> · <?= $q['ok'] ? '<span style="color:#10B981;">✓ Atteint</span>' : '<span style="color:#EF4444;">✗ Non atteint</span>' ?>
<?php endif; ?></p>

<table>
<thead><tr><th>Nom</th><th>Statut</th><th>Émargement</th></tr></thead>
<tbody>
<?php foreach ($attendees as $a):
  $stl = ['invited'=>'Invité','present'=>'Présent','excused'=>'Excusé','absent'=>'Absent','proxy'=>'Pouvoir'][$a['status']] ?? '—';
?>
<tr>
  <td><?= h($a['full_name']) ?></td>
  <td><?= $stl ?></td>
  <td><?= $a['signed_at'] ? date('d/m H:i', strtotime($a['signed_at'])) : '—' ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>

<h2>Résolutions et votes</h2>
<?php foreach ($resos as $i => $r):
  $stats = ag_resolution_stats($pdo, (int)$r['id']);
  $cls = $r['result'];
  $rlbl = ['adopted'=>'ADOPTÉE','rejected'=>'REJETÉE','pending'=>'En attente'][$r['result']] ?? '—';
?>
<div class="reso">
  <div class="reso-title">Résolution n°<?= $i + 1 ?> — <?= h($r['title']) ?>
    <span class="reso-result <?= $cls ?>"><?= $rlbl ?></span>
  </div>
  <div style="font-size:9.5pt;color:#6b7280;margin:4px 0;"><?= h(ag_vote_type_label($r['vote_type'])) ?></div>
  <?php if ($r['description']): ?><p style="font-size:10pt;"><?= h($r['description']) ?></p><?php endif; ?>
  <p style="font-size:10pt;margin:6px 0 0;">
    Pour : <strong><?= $stats['for'] ?></strong> ·
    Contre : <strong><?= $stats['against'] ?></strong> ·
    Abstention : <strong><?= $stats['abstain'] ?></strong> ·
    Total exprimés : <strong><?= $stats['total'] ?></strong>
  </p>
</div>
<?php endforeach; ?>

<div class="foot">
PV généré automatiquement le <?= date('d/m/Y à H:i') ?> via AssoKit.<br>
Document à valider et signer par le président et le secrétaire de séance.
</div>
</body></html>
<?php
$html = ob_get_clean();

// Marquer généré
$pdo->prepare("UPDATE assemblies SET pv_generated_at = NOW() WHERE id = ?")->execute([$id]);

if ($use_mpdf) {
    $mpdf = new \Mpdf\Mpdf(['mode' => 'utf-8', 'format' => 'A4']);
    $mpdf->WriteHTML($html);
    $mpdf->Output('PV-' . preg_replace('/[^a-z0-9]+/i', '-', $ag['title']) . '.pdf', 'D');
    exit;
} else {
    // Fallback : HTML imprimable
    header('Content-Type: text/html; charset=UTF-8');
    echo $html;
    echo '<script>setTimeout(function(){window.print();}, 400);</script>';
    exit;
}
