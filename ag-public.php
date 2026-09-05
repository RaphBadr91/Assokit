<?php
/**
 * /ag-public/<token> — Page publique pour participant
 * Sans login : auth via token unique reçu par email
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes-assemblies.php';

$token = trim($_GET['t'] ?? '');
if (!preg_match('/^[a-f0-9]{32,64}$/', $token)) { http_response_code(404); die('Lien invalide.'); }

$att = ag_load_by_token($pdo, $token);
if (!$att) { http_response_code(404); die('Lien introuvable ou expiré.'); }

// Marquer comme ouvert
if (empty($att['invitation_opened_at'])) {
    try { $pdo->prepare("UPDATE assembly_attendees SET invitation_opened_at = NOW() WHERE id = ?")->execute([(int)$att['id']]); } catch (Throwable $e) {}
}

$resos = ag_load_resolutions($pdo, (int)$att['assembly_id']);
$ag_status = $att['ag_status'];
$can_sign = in_array($ag_status, ['sent','in_progress'], true);
$can_vote = ($ag_status === 'in_progress');

// Récupérer votes existants de cet attendee
$my_votes = [];
try {
    $stmt = $pdo->prepare("SELECT resolution_id, choice FROM assembly_votes WHERE attendee_id = ?");
    $stmt->execute([(int)$att['id']]);
    foreach ($stmt->fetchAll() as $v) $my_votes[(int)$v['resolution_id']] = $v['choice'];
} catch (Throwable $e) {}

$flash = '';
// Traitement POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['action'] ?? '';
    if ($act === 'sign' && $can_sign) {
        $sig = trim($_POST['signature'] ?? '');
        $st = $_POST['status_choice'] ?? 'present';
        if (!in_array($st, ['present','excused'], true)) $st = 'present';
        if ($st === 'present' && $sig && strpos($sig, 'data:image/') === 0 && strlen($sig) < 500000) {
            $pdo->prepare("UPDATE assembly_attendees SET signature_data = ?, signed_at = NOW(), status = 'present' WHERE id = ?")
                ->execute([$sig, (int)$att['id']]);
            $flash = '✓ Signature enregistrée. Merci !';
        } elseif ($st === 'excused') {
            $pdo->prepare("UPDATE assembly_attendees SET status = 'excused' WHERE id = ?")->execute([(int)$att['id']]);
            $flash = 'Excuse enregistrée.';
        } else {
            $flash = 'Signature manquante.';
        }
        $att = ag_load_by_token($pdo, $token); // reload
    }
    if ($act === 'vote' && $can_vote && !empty($att['signed_at'])) {
        $reso_id = (int)($_POST['resolution_id'] ?? 0);
        $choice = $_POST['choice'] ?? '';
        if (in_array($choice, ['for','against','abstain'], true) && $reso_id > 0) {
            // Vérifier que la résolution est ouverte
            $st = $pdo->prepare("SELECT is_open FROM assembly_resolutions WHERE id = ? AND assembly_id = ?");
            $st->execute([$reso_id, (int)$att['assembly_id']]);
            $rr = $st->fetch();
            if ($rr && $rr['is_open']) {
                $pdo->prepare("INSERT INTO assembly_votes (resolution_id, attendee_id, choice) VALUES (?,?,?)
                    ON DUPLICATE KEY UPDATE choice = VALUES(choice), voted_at = NOW()")
                    ->execute([$reso_id, (int)$att['id'], $choice]);
                $my_votes[$reso_id] = $choice;
                $flash = '✓ Vote enregistré';
            }
        }
    }
}
?><!doctype html>
<html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= h($att['ag_title']) ?> · AssoKit</title>
<style>
* { box-sizing: border-box; }
body { margin: 0; font-family: -apple-system, BlinkMacSystemFont, sans-serif; background: #f3f4f6; color: #111827; }
.pp { max-width: 720px; margin: 0 auto; padding: 22px 18px; }
.pp-hero { background: linear-gradient(135deg, #10B981, #059669); color: #fff; border-radius: 16px; padding: 24px; margin-bottom: 16px; }
.pp-hero h1 { font-size: 22px; margin: 0 0 6px; }
.pp-hero-sub { opacity: 0.92; font-size: 13.5px; }
.pp-card { background: #fff; border-radius: 12px; padding: 20px 22px; margin-bottom: 14px; border: 1px solid #e5e7eb; }
.pp-card h2 { font-size: 15px; margin: 0 0 12px; color: #111827; }
.pp-flash { background: #ECFDF5; color: #065F46; padding: 10px 14px; border-radius: 8px; margin-bottom: 14px; font-size: 13px; border: 1px solid #A7F3D0; }
.pp-greeting { font-size: 14px; color: #4b5563; margin-bottom: 12px; }
.pp-info { font-size: 13px; color: #4b5563; line-height: 1.6; }
.pp-info strong { color: #111827; }
.pp-status-pill { display: inline-block; background: #DBEAFE; color: #1E40AF; font-size: 12px; padding: 4px 12px; border-radius: 999px; font-weight: 600; margin-bottom: 10px; }
.pp-sig-canvas { border: 2px dashed #d1d5db; border-radius: 10px; background: #fff; touch-action: none; cursor: crosshair; width: 100%; height: 180px; }
.pp-sig-actions { display: flex; gap: 8px; margin-top: 10px; }
.pp-btn { padding: 10px 18px; border-radius: 8px; font-size: 13.5px; font-weight: 600; border: 0; cursor: pointer; font-family: inherit; }
.pp-btn-primary { background: #10B981; color: #fff; }
.pp-btn-primary:hover { background: #059669; }
.pp-btn-ghost { background: #fff; border: 1px solid #d1d5db; color: #374151; }
.pp-btn-ghost:hover { background: #f9fafb; }
.pp-status-choice { display: flex; gap: 10px; margin-bottom: 14px; }
.pp-status-choice label { flex: 1; padding: 12px; border: 2px solid #e5e7eb; border-radius: 10px; cursor: pointer; text-align: center; font-size: 14px; font-weight: 600; transition: all 0.15s; }
.pp-status-choice input[type="radio"] { display: none; }
.pp-status-choice input[type="radio"]:checked + span { color: #065F46; }
.pp-status-choice label:has(input:checked) { border-color: #10B981; background: #ECFDF5; }
.pp-signed { background: #ECFDF5; padding: 16px; border-radius: 10px; text-align: center; }
.pp-signed-icon { font-size: 36px; margin-bottom: 6px; }
.pp-reso { background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 10px; padding: 14px 16px; margin-bottom: 10px; }
.pp-reso-num { font-size: 10.5px; color: #6b7280; font-weight: 700; text-transform: uppercase; letter-spacing: 0.04em; }
.pp-reso-title { font-size: 15px; font-weight: 700; margin: 4px 0; color: #111827; }
.pp-reso-type { font-size: 11.5px; color: #6b7280; margin-bottom: 10px; }
.pp-vote-btns { display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px; }
.pp-vote-btn { padding: 12px 8px; border: 2px solid #e5e7eb; background: #fff; border-radius: 10px; font-size: 13px; font-weight: 600; cursor: pointer; transition: all 0.15s; font-family: inherit; }
.pp-vote-btn:hover { background: #f9fafb; }
.pp-vote-btn.for.is-active { background: #ECFDF5; border-color: #10B981; color: #065F46; }
.pp-vote-btn.against.is-active { background: #FEE2E2; border-color: #EF4444; color: #991B1B; }
.pp-vote-btn.abstain.is-active { background: #f3f4f6; border-color: #6b7280; color: #374151; }
.pp-vote-confirm { font-size: 11.5px; color: #10B981; margin-top: 6px; }
.pp-locked { padding: 12px; background: #FEF3C7; color: #92400E; border-radius: 8px; font-size: 13px; text-align: center; }
.pp-foot { text-align: center; color: #9ca3af; font-size: 11px; margin-top: 24px; padding: 14px 0; }
</style>
</head><body>
<div class="pp">

  <div class="pp-hero">
    <div class="pp-status-pill" style="background:rgba(255,255,255,0.2);color:#fff;"><?= h(ag_type_label($att['ag_type'])) ?></div>
    <h1><?= h($att['ag_title']) ?></h1>
    <div class="pp-hero-sub">📅 <?= fr_format_date('%A %d %B %Y à %H:%M', strtotime($att['scheduled_at'])) ?>
      <?php if ($att['location']): ?> · 📍 <?= h($att['location']) ?><?php endif; ?>
    </div>
  </div>

  <?php if ($flash): ?><div class="pp-flash"><?= h($flash) ?></div><?php endif; ?>

  <div class="pp-card">
    <p class="pp-greeting">Bonjour <strong><?= h($att['full_name']) ?></strong>,</p>
    <div class="pp-info">Vous êtes convoqué·e à cette assemblée. Merci de :
      <ol style="padding-left:18px;line-height:1.8;">
        <li>Indiquer votre présence et signer ci-dessous</li>
        <?php if ($can_vote): ?><li>Voter sur les résolutions une fois la séance ouverte</li><?php endif; ?>
      </ol>
    </div>
    <?php if ($att['location_url']): ?>
      <a href="<?= h($att['location_url']) ?>" target="_blank" class="pp-btn pp-btn-primary" style="display:inline-block;text-decoration:none;margin-top:6px;">🎥 Rejoindre la visioconférence</a>
    <?php endif; ?>
  </div>

  <!-- SIGNATURE -->
  <div class="pp-card">
    <h2>✍️ Émargement</h2>
    <?php if ($att['signed_at']): ?>
      <div class="pp-signed">
        <div class="pp-signed-icon">✅</div>
        <strong>Présence enregistrée le <?= date('d/m à H:i', strtotime($att['signed_at'])) ?></strong>
      </div>
    <?php elseif ($att['status'] === 'excused'): ?>
      <div class="pp-signed">
        <div class="pp-signed-icon">📝</div>
        <strong>Absence excusée enregistrée</strong>
      </div>
    <?php elseif ($can_sign): ?>
      <form method="POST" id="ag-sign-form">
        <input type="hidden" name="action" value="sign">
        <input type="hidden" name="signature" id="ag-sig-data">
        <div class="pp-status-choice">
          <label><input type="radio" name="status_choice" value="present" checked><span>✅ Je serai présent·e</span></label>
          <label><input type="radio" name="status_choice" value="excused"><span>📝 Je m'excuse</span></label>
        </div>
        <div id="ag-sig-zone">
          <p style="font-size:12.5px;color:#6b7280;margin:0 0 6px;">Signez avec votre doigt ou votre souris dans le cadre :</p>
          <canvas id="ag-sig-canvas" class="pp-sig-canvas" width="600" height="180"></canvas>
          <div class="pp-sig-actions">
            <button type="button" id="ag-sig-clear" class="pp-btn pp-btn-ghost">Effacer</button>
            <button type="submit" class="pp-btn pp-btn-primary" style="margin-left:auto;">✓ Valider ma signature</button>
          </div>
        </div>
      </form>
      <script>
      (function() {
        var canvas = document.getElementById('ag-sig-canvas');
        var ctx = canvas.getContext('2d');
        var drawing = false, hasDrawn = false;
        function resize() {
          var r = canvas.getBoundingClientRect();
          canvas.width = r.width * 2; canvas.height = 180 * 2;
          ctx.scale(2, 2);
          ctx.lineWidth = 2.5; ctx.lineCap = 'round'; ctx.strokeStyle = '#111827';
          ctx.fillStyle = '#fff'; ctx.fillRect(0, 0, canvas.width, canvas.height);
        }
        resize();
        function pos(e) { var r = canvas.getBoundingClientRect(); var t = e.touches ? e.touches[0] : e; return {x:(t.clientX-r.left), y:(t.clientY-r.top)}; }
        function start(e) { drawing = true; hasDrawn = true; var p = pos(e); ctx.beginPath(); ctx.moveTo(p.x, p.y); e.preventDefault(); }
        function move(e) { if(!drawing) return; var p = pos(e); ctx.lineTo(p.x, p.y); ctx.stroke(); e.preventDefault(); }
        function end() { drawing = false; }
        canvas.addEventListener('mousedown', start); canvas.addEventListener('mousemove', move); canvas.addEventListener('mouseup', end); canvas.addEventListener('mouseleave', end);
        canvas.addEventListener('touchstart', start); canvas.addEventListener('touchmove', move); canvas.addEventListener('touchend', end);
        document.getElementById('ag-sig-clear').addEventListener('click', function() { ctx.fillStyle='#fff'; ctx.fillRect(0,0,canvas.width,canvas.height); hasDrawn=false; });
        // Toggle signature zone
        var radios = document.querySelectorAll('input[name="status_choice"]');
        function refresh() {
          var v = document.querySelector('input[name="status_choice"]:checked').value;
          document.getElementById('ag-sig-zone').style.display = (v === 'present') ? 'block' : 'none';
        }
        radios.forEach(function(r){ r.addEventListener('change', refresh); }); refresh();
        // Submit
        document.getElementById('ag-sign-form').addEventListener('submit', function(e) {
          var v = document.querySelector('input[name="status_choice"]:checked').value;
          if (v === 'present') {
            if (!hasDrawn) { alert('Veuillez signer.'); e.preventDefault(); return false; }
            document.getElementById('ag-sig-data').value = canvas.toDataURL('image/png');
          }
        });
      })();
      </script>
    <?php else: ?>
      <div class="pp-locked">⏳ La séance n'est pas encore ouverte. Reviens quand l'organisateur lancera l'AG.</div>
    <?php endif; ?>
  </div>

  <!-- VOTES -->
  <?php if (!empty($resos)): ?>
  <div class="pp-card">
    <h2>🗳️ Résolutions à voter (<?= count($resos) ?>)</h2>
    <?php if (!$can_vote): ?>
      <div class="pp-locked">
        <?php if ($ag_status === 'closed'): ?>✅ La séance est clôturée.
        <?php elseif (!$can_sign): ?>⏳ La séance n'est pas encore ouverte.
        <?php else: ?>⏳ Attendez l'ouverture de la séance pour voter.
        <?php endif; ?>
      </div>
    <?php elseif (empty($att['signed_at'])): ?>
      <div class="pp-locked">✍️ Émargez d'abord pour pouvoir voter.</div>
    <?php else: ?>
      <?php foreach ($resos as $i => $r):
        $my = $my_votes[(int)$r['id']] ?? null;
      ?>
      <div class="pp-reso">
        <div class="pp-reso-num">Résolution n°<?= $i + 1 ?></div>
        <div class="pp-reso-title"><?= h($r['title']) ?></div>
        <div class="pp-reso-type"><?= h(ag_vote_type_label($r['vote_type'])) ?></div>
        <?php if ($r['description']): ?><p style="font-size:13px;color:#4b5563;margin:0 0 10px;"><?= h($r['description']) ?></p><?php endif; ?>
        <?php if (!$r['is_open']): ?>
          <div class="pp-locked" style="font-size:12px;padding:8px;">🔒 Vote clôturé</div>
        <?php else: ?>
        <form method="POST" class="pp-vote-form" data-rid="<?= (int)$r['id'] ?>">
          <input type="hidden" name="action" value="vote">
          <input type="hidden" name="resolution_id" value="<?= (int)$r['id'] ?>">
          <div class="pp-vote-btns">
            <button type="submit" name="choice" value="for" class="pp-vote-btn for <?= $my === 'for' ? 'is-active' : '' ?>">✓ Pour</button>
            <button type="submit" name="choice" value="against" class="pp-vote-btn against <?= $my === 'against' ? 'is-active' : '' ?>">✗ Contre</button>
            <button type="submit" name="choice" value="abstain" class="pp-vote-btn abstain <?= $my === 'abstain' ? 'is-active' : '' ?>">○ Abstention</button>
          </div>
          <?php if ($my): ?><div class="pp-vote-confirm">✓ Votre vote : <?= ['for'=>'Pour','against'=>'Contre','abstain'=>'Abstention'][$my] ?></div><?php endif; ?>
        </form>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <div class="pp-foot">AssoKit · vote sécurisé par lien personnel</div>
</div>
</body></html>
