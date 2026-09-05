<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes-layout.php';
require_once __DIR__ . '/includes-attendance.php';
require_login();
$user = current_user(); $org_id = (int)$user['org_id'];
if (!in_array($user['role'], ['admin','coordinator'], true)) { http_response_code(403); die('Réservé.'); }

$id = (int)($_GET['id'] ?? 0);
$sess = att_load($pdo, $id, $org_id);
if (!$sess) { http_response_code(404); die('Introuvable.'); }
$records = att_load_records($pdo, $id);

$host = $_SERVER['HTTP_HOST'] ?? 'assokit.fr';
$public_url = "https://{$host}/emarger/" . $sess['access_token'];

render_head($sess['title']);
?>
<?= render_sidebar('emargement') ?>
<main class="main">
  <div class="at-page" style="max-width:1100px;">
    <a href="/emargement" class="at-back">← Émargement</a>

    <div class="at-detail-hero">
      <div>
        <span class="at-status <?= $sess['is_open'] ? 'open' : 'closed' ?>"><?= $sess['is_open'] ? '🟢 Ouverte' : '🔒 Fermée' ?></span>
        <h1 class="at-pg-title"><?= h($sess['title']) ?></h1>
        <div class="at-detail-meta">📅 <?= fr_format_date('%A %d %B %Y à %H:%M', strtotime($sess['starts_at'])) ?>
          <?php if ($sess['ends_at']): ?> → <?= date('H:i', strtotime($sess['ends_at'])) ?><?php endif; ?>
          <?php if ($sess['location']): ?> · 📍 <?= h($sess['location']) ?><?php endif; ?>
        </div>
      </div>
      <div class="at-detail-actions">
        <a href="/emargement-form?id=<?= (int)$sess['id'] ?>" class="at-btn-ghost">✏️ Modifier</a>
        <a href="/emargement-export?id=<?= (int)$sess['id'] ?>" class="at-btn-ghost">📥 Export CSV</a>
        <a href="/emargement-pdf?id=<?= (int)$sess['id'] ?>" target="_blank" class="at-btn-ghost">📄 PDF</a>
        <?php if ($sess['is_open']): ?>
        <form method="POST" action="/action-emargement" style="display:inline;" onsubmit="return confirm('Fermer la session ? Plus aucune signature ne sera acceptée.')">
          <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
          <input type="hidden" name="action" value="close">
          <input type="hidden" name="id" value="<?= (int)$sess['id'] ?>">
          <button type="submit" class="at-btn-primary">🔒 Fermer la session</button>
        </form>
        <?php else: ?>
        <form method="POST" action="/action-emargement" style="display:inline;">
          <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
          <input type="hidden" name="action" value="reopen">
          <input type="hidden" name="id" value="<?= (int)$sess['id'] ?>">
          <button type="submit" class="at-btn-primary">🟢 Rouvrir</button>
        </form>
        <?php endif; ?>
      </div>
    </div>

    <div class="at-cols">
      <div class="at-col-side">
        <div class="at-card at-qr-card">
          <h2>📱 QR Code & lien</h2>
          <div id="at-qr" style="text-align:center;padding:14px;background:#fff;border-radius:10px;border:1px solid #e5e7eb;"></div>
          <div class="at-url-box">
            <input type="text" readonly value="<?= h($public_url) ?>" id="at-url-<?= (int)$sess['id'] ?>">
            <button aria-label="Copier le lien d'émargement" type="button" onclick="(function(){const i=document.getElementById('at-url-<?= (int)$sess['id'] ?>');i.select();navigator.clipboard&&navigator.clipboard.writeText(i.value);this.textContent='✓';setTimeout(()=>this.textContent='📋',1500);}).call(this)">📋</button>
          </div>
          <p style="font-size:11.5px;color:#6b7280;margin:10px 0 0;line-height:1.5;">Imprime ce QR ou affiche-le sur écran. Les participants scannent et signent en quelques secondes.</p>
          <a href="<?= h($public_url) ?>" target="_blank" class="at-btn-ghost" style="display:block;text-align:center;margin-top:8px;">🔗 Ouvrir dans un onglet</a>
        </div>

        <div class="at-card">
          <h2>📊 Statistiques</h2>
          <div style="font-size:13px;line-height:1.8;">
            <div>Signatures : <strong style="color:#10B981;font-size:18px;"><?= count($records) ?></strong></div>
            <?php if (!empty($records)): ?>
              <div style="font-size:11.5px;color:#6b7280;margin-top:4px;">
                Première : <?= date('d/m H:i', strtotime(end($records)['signed_at'])) ?><br>
                Dernière : <?= date('d/m H:i', strtotime($records[0]['signed_at'])) ?>
              </div>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <div class="at-col-main">
        <div class="at-card">
          <h2>✍️ Émargements (<?= count($records) ?>)</h2>
          <?php if (empty($records)): ?>
            <p class="at-muted">Aucune signature pour le moment. Partage le QR code aux participants.</p>
          <?php else: ?>
          <table class="at-table">
            <thead><tr><th>Nom</th><th>Email / Tél</th><th>Heure</th><th>Signature</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($records as $r): ?>
              <tr>
                <td><strong><?= h($r['full_name']) ?></strong></td>
                <td>
                  <?php if ($r['email']): ?><div style="font-size:12px;"><?= h($r['email']) ?></div><?php endif; ?>
                  <?php if ($r['phone']): ?><div style="font-size:12px;color:#6b7280;"><?= h($r['phone']) ?></div><?php endif; ?>
                </td>
                <td style="font-variant-numeric:tabular-nums;"><?= date('d/m H:i:s', strtotime($r['signed_at'])) ?></td>
                <td>
                  <?php if ($r['signature_data']): ?>
                    <img src="<?= h($r['signature_data']) ?>" style="max-width:80px;max-height:30px;border:1px solid #e5e7eb;border-radius:4px;background:#fff;">
                  <?php else: ?>
                    <span style="color:#9ca3af;font-size:11px;">—</span>
                  <?php endif; ?>
                </td>
                <td>
                  <form method="POST" action="/action-emargement" style="display:inline;" onsubmit="return confirm('Supprimer cet émargement ?')">
                    <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
                    <input type="hidden" name="action" value="remove_record">
                    <input type="hidden" name="id" value="<?= (int)$sess['id'] ?>">
                    <input type="hidden" name="record_id" value="<?= (int)$r['id'] ?>">
                    <button type="submit" style="background:transparent;border:0;color:#DC2626;cursor:pointer;font-size:14px;">×</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
</main>
<style>
.at-detail-hero { display:flex; justify-content:space-between; align-items:flex-start; gap:18px; margin-bottom:18px; flex-wrap:wrap; }
.at-detail-hero .at-status { display:inline-block; margin-bottom:8px; }
.at-detail-hero h1 { margin:0 0 6px; }
.at-detail-meta { color:#6b7280; font-size:13.5px; }
.at-detail-actions { display:flex; gap:8px; flex-wrap:wrap; }
.at-cols { display:grid; grid-template-columns:300px 1fr; gap:16px; }
.at-card { background:#fff; border:1px solid #e5e7eb; border-radius:12px; padding:16px 18px; margin-bottom:14px; }
.at-card h2 { font-size:13px; margin:0 0 12px; color:#065F46; padding-bottom:6px; border-bottom:1px solid #f3f4f6; font-weight:700; text-transform:uppercase; letter-spacing:0.04em; }
.at-muted { color:#9ca3af; font-size:13px; }
.at-url-box { display:flex; gap:6px; margin-top:10px; }
.at-url-box input { flex:1; padding:7px 10px; background:#f9fafb; border:1px solid #e5e7eb; border-radius:7px; font-family:ui-monospace,monospace; font-size:11px; color:#4b5563; }
.at-url-box button { padding:7px 12px; background:#6366F1; color:#fff; border:0; border-radius:7px; cursor:pointer; }
.at-table { width:100%; border-collapse:collapse; }
.at-table th { text-align:left; padding:8px 10px; font-size:11px; color:#6b7280; text-transform:uppercase; letter-spacing:0.04em; border-bottom:1px solid #e5e7eb; }
.at-table td { padding:9px 10px; font-size:13px; border-bottom:1px solid #f3f4f6; }
@media (max-width: 720px) { .at-cols { grid-template-columns:1fr; } }
</style>
<script src="https://cdn.jsdelivr.net/npm/qrcode@1.5.3/build/qrcode.min.js"></script>
<script>
QRCode.toCanvas(document.createElement('canvas'), <?= json_encode($public_url) ?>, { width: 220, margin: 1, color: { dark: '#10B981', light: '#fff' } }, function(err, canvas) {
  if (!err) document.getElementById('at-qr').appendChild(canvas);
});
</script>
<?= render_foot() ?>
