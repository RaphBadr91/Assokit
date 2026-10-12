<?php
/**
 * /ag-public/<token> — Page publique pour participant
 * Sans login : auth via token unique reçu par email
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes-assemblies.php';
require_once __DIR__ . '/includes-layout.php';   // fr_format_date()

$token = is_string($_GET['t'] ?? null) ? trim($_GET['t']) : '';
if (!preg_match('/^[a-f0-9]{32,64}$/', $token)) { http_response_code(404); die('Lien invalide.'); }

$att = ag_load_by_token($pdo, $token);
if (!$att) { http_response_code(404); die('Lien introuvable ou expiré.'); }

// Marquer comme ouvert
if (empty($att['invitation_opened_at'])) {
    try { $pdo->prepare("UPDATE assembly_attendees SET invitation_opened_at = NOW() WHERE id = ?")->execute([(int)$att['id']]); } catch (Throwable $e) {}
}

$resos = ag_load_resolutions($pdo, (int)$att['assembly_id']);
$ag_status = $att['ag_status'];
// Avant la séance on RÉPOND à la convocation ; on n'émarge (présence, quorum, PV) et
// on ne vote que pendant la séance.
$can_rsvp = ($ag_status === 'sent');
$can_sign = ($ag_status === 'in_progress');
$can_vote = ($ag_status === 'in_progress');
$has_rsvp_col = ag_has_col($pdo, 'assembly_attendees', 'rsvp');

// Récupérer votes existants de cet attendee (le dernier par résolution)
$my_votes = [];
try {
    $stmt = $pdo->prepare("SELECT resolution_id, choice FROM assembly_votes WHERE attendee_id = ? ORDER BY id");
    $stmt->execute([(int)$att['id']]);
    foreach ($stmt->fetchAll() as $v) $my_votes[(int)$v['resolution_id']] = $v['choice'];
} catch (Throwable $e) {}

$flash = '';
// Traitement POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['action'] ?? '';
    // Ancien formulaire (« Je serai présent·e » + signature avant la séance) : simple réponse.
    if ($act === 'sign' && $can_rsvp) {
        $act = 'rsvp';
        $_POST['rsvp'] = ($_POST['status_choice'] ?? '') === 'excused' ? 'excused' : 'yes';
    }
    if ($act === 'rsvp' && $can_rsvp) {
        $choice = ($_POST['rsvp'] ?? '') === 'excused' ? 'excused' : 'yes';
        if (($att['status'] ?? '') === 'proxy') {
            // Pouvoir enregistré par l'organisateur : la réponse ne l'annule pas (seul
            // l'émargement en séance le remplace, le représenté votant alors lui-même).
            if ($has_rsvp_col) ak_db_update($pdo, 'assembly_attendees', (int)$att['id'], ['rsvp' => $choice], ['rsvp_at' => 'NOW()']);
            $flash = $choice === 'excused'
                ? '✓ Réponse enregistrée : votre pouvoir reste valable.'
                : '✓ Merci ! Votre pouvoir reste valable d’ici là. Si vous venez, émargez avec ce lien le jour de la séance : vous voterez alors vous-même.';
        } else {
            $data = ['status' => $choice === 'excused' ? 'excused' : 'invited', 'signed_at' => null];
            $raw = [];
            if ($has_rsvp_col) { $data['rsvp'] = $choice; $raw['rsvp_at'] = 'NOW()'; }
            ak_db_update($pdo, 'assembly_attendees', (int)$att['id'], $data, $raw);
            $flash = $choice === 'excused'
                ? '✓ Absence excusée enregistrée. Si vous pouvez finalement venir, émargez avec ce lien le jour de la séance.'
                : '✓ Merci ! Le jour de la séance, revenez sur ce lien pour émarger puis voter.';
        }
        $att = ag_load_by_token($pdo, $token); // reload
    } elseif ($act === 'sign' && $can_sign) {
        $sig = is_string($_POST['signature'] ?? null) ? trim($_POST['signature']) : '';
        if ($sig && strpos($sig, 'data:image/') === 0 && strlen($sig) < 500000) {
            $pdo->prepare("UPDATE assembly_attendees SET signature_data = ?, signed_at = NOW(), status = 'present' WHERE id = ?")
                ->execute([$sig, (int)$att['id']]);
            $flash = '✓ Signature enregistrée. Merci !';
        } else {
            $flash = 'Signature manquante.';
        }
        $att = ag_load_by_token($pdo, $token); // reload
    }
    if ($act === 'vote' && $can_vote && !empty($att['signed_at']) && $att['status'] === 'present') {
        $reso_id = (int)($_POST['resolution_id'] ?? 0);
        $choice = $_POST['choice'] ?? '';
        if (in_array($choice, ['for','against','abstain'], true) && $reso_id > 0) {
            // Vérifier que la résolution est ouverte
            $st = $pdo->prepare("SELECT is_open FROM assembly_resolutions WHERE id = ? AND assembly_id = ? AND closed_at IS NULL");
            $st->execute([$reso_id, (int)$att['assembly_id']]);
            $rr = $st->fetch();
            if ($rr && $rr['is_open']) {
                // Un seul vote par personne et par résolution : le dernier choix remplace le
                // précédent, sans dépendre d'un index unique en base. Le verrou sur la ligne du
                // participant évite deux votes simultanés (double clic).
                $pdo->beginTransaction();
                try {
                    $lock = $pdo->prepare("SELECT id FROM assembly_attendees WHERE id = ? FOR UPDATE");
                    $lock->execute([(int)$att['id']]);
                    $lock->fetchAll();
                    $pdo->prepare("DELETE FROM assembly_votes WHERE resolution_id = ? AND attendee_id = ?")->execute([$reso_id, (int)$att['id']]);
                    ak_db_insert($pdo, 'assembly_votes', ['resolution_id' => $reso_id, 'attendee_id' => (int)$att['id'], 'choice' => $choice], ['voted_at' => 'NOW()']);
                    $pdo->commit();
                    $my_votes[$reso_id] = $choice;
                    $flash = '✓ Vote enregistré';
                } catch (Throwable $e) {
                    $pdo->rollBack();
                    error_log('[ag-public] vote : ' . $e->getMessage());
                    $flash = 'Le vote n’a pas pu être enregistré. Réessayez.';
                }
            } else {
                $flash = 'Le vote sur cette résolution est clôturé.';
            }
        }
    }
}
$rsvp = ag_rsvp($att);
// Pouvoirs : qui représente ce convoqué, et pour qui il vote aussi (mandataire)
$proxy_holder_name = null;
$proxies_held = [];
if (ag_has_proxy_holder($pdo)) {
    try {
        if ($att['status'] === 'proxy' && !empty($att['proxy_attendee_id'])) {
            $st = $pdo->prepare("SELECT full_name FROM assembly_attendees WHERE id = ? AND assembly_id = ?");
            $st->execute([(int)$att['proxy_attendee_id'], (int)$att['assembly_id']]);
            $proxy_holder_name = $st->fetchColumn() ?: null;
        }
        $st = $pdo->prepare("SELECT full_name FROM assembly_attendees WHERE assembly_id = ? AND status = 'proxy' AND proxy_attendee_id = ? ORDER BY full_name");
        $st->execute([(int)$att['assembly_id'], (int)$att['id']]);
        $proxies_held = $st->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $e) { error_log('[ag-public] pouvoirs : ' . $e->getMessage()); }
}
$is_signed = !empty($att['signed_at']) && $att['status'] === 'present' && ($ag_status === 'in_progress' || ag_is_frozen($ag_status));
$visio = ag_safe_url($att['location_url'] ?? null);
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
@media (max-width: 400px) { .pp-vote-btns { grid-template-columns: 1fr; } }
.pp-vote-btn { padding: 12px 8px; border: 2px solid #e5e7eb; background: #fff; border-radius: 10px; font-size: 13px; font-weight: 600; cursor: pointer; transition: all 0.15s; font-family: inherit; }
.pp-vote-btn:hover { background: #f9fafb; }
.pp-vote-btn.for.is-active { background: #ECFDF5; border-color: #10B981; color: #065F46; }
.pp-vote-btn.against.is-active { background: #FEE2E2; border-color: #EF4444; color: #991B1B; }
.pp-vote-btn.abstain.is-active { background: #f3f4f6; border-color: #6b7280; color: #374151; }
.pp-vote-confirm { font-size: 11.5px; color: #10B981; margin-top: 6px; }
.pp-locked { padding: 12px; background: #FEF3C7; color: #92400E; border-radius: 8px; font-size: 13px; text-align: center; }
.pp-foot { text-align: center; color: #6b7280; font-size: 11px; margin-top: 24px; padding: 14px 0; }
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
    <div class="pp-info">
      <?php if (ag_is_frozen($ag_status)): ?>
        Cette assemblée est clôturée. Merci de votre participation.
      <?php elseif ($can_sign): ?>
        La séance est ouverte. Merci de :
        <ol style="padding-left:18px;line-height:1.8;">
          <li>Émarger ci-dessous (signature)</li>
          <li>Voter sur les résolutions</li>
        </ol>
      <?php else: ?>
        Vous êtes convoqué·e à cette assemblée.
        <ol style="padding-left:18px;line-height:1.8;">
          <li>Indiquez dès maintenant si vous serez présent·e</li>
          <li>Le jour de la séance, revenez sur ce lien pour émarger puis voter</li>
        </ol>
      <?php endif; ?>
    </div>
    <?php if (!empty($att['ag_description'])): ?>
      <p class="pp-info" style="white-space:pre-line;margin:6px 0 0;"><?= h($att['ag_description']) ?></p>
    <?php endif; ?>
    <?php if ($visio && !ag_is_frozen($ag_status)): ?>
      <a href="<?= h($visio) ?>" target="_blank" rel="noopener noreferrer" class="pp-btn pp-btn-primary" style="display:inline-block;text-decoration:none;margin-top:10px;">🎥 Rejoindre la visioconférence</a>
    <?php endif; ?>
  </div>

  <!-- RÉPONSE À LA CONVOCATION / ÉMARGEMENT -->
  <div class="pp-card">
    <h2><?= $can_rsvp ? '📅 Votre réponse' : '✍️ Émargement' ?></h2>
    <?php if ($is_signed): ?>
      <div class="pp-signed">
        <div class="pp-signed-icon">✅</div>
        <strong>Présence enregistrée le <?= date('d/m à H:i', strtotime($att['signed_at'])) ?></strong>
      </div>
    <?php elseif ($can_rsvp && $att['status'] === 'proxy'):
      // Représenté : sa réponse (colonne rsvp, effacée quand l'organisateur enregistre le pouvoir)
      // ne change pas le pouvoir ; « je viendrai » reste une simple annonce.
      $proxy_rsvp = $att['rsvp'] ?? null; ?>
      <p class="pp-info" style="margin:0 0 12px;">🤝 Un pouvoir est enregistré à votre nom : vous êtes représenté·e<?= $proxy_holder_name ? ' par ' . h($proxy_holder_name) : '' ?>.
        <?= $proxy_rsvp === 'yes' ? 'Vous avez indiqué que vous viendrez finalement : le jour de la séance, émargez avec ce lien pour voter vous-même.' : 'Si vous venez finalement, indiquez-le ci-dessous : le jour de la séance, vous émargerez avec ce lien et voterez vous-même.' ?></p>
      <form method="POST">
        <input type="hidden" name="action" value="rsvp">
        <div class="pp-status-choice">
          <label><input type="radio" name="rsvp" value="excused" <?= $proxy_rsvp !== 'yes' ? 'checked' : '' ?>><span>🤝 Je reste représenté·e</span></label>
          <label><input type="radio" name="rsvp" value="yes" <?= $proxy_rsvp === 'yes' ? 'checked' : '' ?>><span>✅ Je viendrai finalement</span></label>
        </div>
        <button type="submit" class="pp-btn pp-btn-primary"><?= $proxy_rsvp ? 'Modifier ma réponse' : 'Envoyer ma réponse' ?></button>
      </form>
    <?php elseif ($can_rsvp): ?>
      <?php if ($rsvp === 'yes'): ?>
        <p class="pp-info" style="margin:0 0 12px;">✅ Vous avez indiqué que vous serez présent·e. Le jour de la séance, émargez avec ce lien.</p>
      <?php elseif ($rsvp === 'excused'): ?>
        <p class="pp-info" style="margin:0 0 12px;">📝 Votre absence excusée est enregistrée. Vous pouvez encore changer d’avis.</p>
      <?php endif; ?>
      <form method="POST">
        <input type="hidden" name="action" value="rsvp">
        <div class="pp-status-choice">
          <label><input type="radio" name="rsvp" value="yes" <?= $rsvp !== 'excused' ? 'checked' : '' ?>><span>✅ Je serai présent·e</span></label>
          <label><input type="radio" name="rsvp" value="excused" <?= $rsvp === 'excused' ? 'checked' : '' ?>><span>📝 Je m'excuse</span></label>
        </div>
        <button type="submit" class="pp-btn pp-btn-primary"><?= $rsvp ? 'Modifier ma réponse' : 'Envoyer ma réponse' ?></button>
      </form>
    <?php elseif ($can_sign): ?>
      <?php if ($att['status'] === 'excused'): ?>
        <p class="pp-info" style="margin:0 0 10px;">Vous vous étiez excusé·e. Si vous êtes finalement présent·e, signez ci-dessous.</p>
      <?php elseif ($att['status'] === 'proxy'): ?>
        <p class="pp-info" style="margin:0 0 10px;">🤝 Vous êtes représenté·e<?= $proxy_holder_name ? ' par ' . h($proxy_holder_name) : '' ?>. Si vous êtes finalement présent·e, signez ci-dessous : vous voterez vous-même.</p>
      <?php endif; ?>
      <form method="POST" id="ag-sign-form">
        <input type="hidden" name="action" value="sign">
        <input type="hidden" name="signature" id="ag-sig-data">
        <p style="font-size:12.5px;color:#6b7280;margin:0 0 6px;">Signez avec votre doigt ou votre souris dans le cadre :</p>
        <canvas id="ag-sig-canvas" class="pp-sig-canvas" width="600" height="180"></canvas>
        <div class="pp-sig-actions">
          <button type="button" id="ag-sig-clear" class="pp-btn pp-btn-ghost">Effacer</button>
          <button type="submit" class="pp-btn pp-btn-primary" style="margin-left:auto;">✓ Valider ma signature</button>
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
        document.getElementById('ag-sign-form').addEventListener('submit', function(e) {
          if (!hasDrawn) { alert('Veuillez signer.'); e.preventDefault(); return false; }
          document.getElementById('ag-sig-data').value = canvas.toDataURL('image/png');
        });
      })();
      </script>
    <?php elseif (ag_is_frozen($ag_status)): ?>
      <div class="pp-locked"><?= $att['status'] === 'excused' ? '📝 Absence excusée.' : '✅ La séance est clôturée.' ?></div>
    <?php else: ?>
      <div class="pp-locked">⏳ La convocation n'est pas encore envoyée. Revenez quand l'organisateur l'aura transmise.</div>
    <?php endif; ?>
  </div>

  <!-- ORDRE DU JOUR / VOTES -->
  <?php if (!empty($resos)): ?>
  <div class="pp-card">
    <h2><?= $can_vote ? '🗳️ Résolutions à voter' : '📋 Ordre du jour' ?> (<?= count($resos) ?>)</h2>
    <?php if (!$can_vote): ?>
      <div class="pp-locked" style="margin-bottom:12px;">
        <?php if (ag_is_frozen($ag_status)): ?>✅ La séance est clôturée.
        <?php else: ?>⏳ Le vote ouvrira avec la séance.
        <?php endif; ?>
      </div>
    <?php elseif (!$is_signed): ?>
      <div class="pp-locked" style="margin-bottom:12px;">✍️ Émargez d'abord pour pouvoir voter.</div>
    <?php endif; ?>
    <?php if ($proxies_held && !ag_is_frozen($ag_status)): ?>
      <p class="pp-info" style="margin:0 0 12px;">🤝 Vous avez reçu <?= count($proxies_held) > 1 ? 'les pouvoirs' : 'le pouvoir' ?> de <strong><?= h(implode(', ', $proxies_held)) ?></strong> : chacun de vos votes compte aussi pour <?= count($proxies_held) > 1 ? 'eux' : 'cette personne' ?>.</p>
    <?php endif; ?>
    <?php foreach ($resos as $i => $r):
      $my = $my_votes[(int)$r['id']] ?? null;
    ?>
    <div class="pp-reso">
      <div class="pp-reso-num">Résolution n°<?= $i + 1 ?></div>
      <div class="pp-reso-title"><?= h($r['title']) ?></div>
      <div class="pp-reso-type"><?= h(ag_vote_type_label((string)$r['vote_type'])) ?></div>
      <?php if ($r['description']): ?><p style="font-size:13px;color:#4b5563;margin:0 0 10px;"><?= h($r['description']) ?></p><?php endif; ?>
      <?php if ($can_vote && $is_signed): ?>
        <?php if (!$r['is_open'] || !empty($r['closed_at'])): ?>
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
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <div class="pp-foot">AssoKit · vote sécurisé par lien personnel</div>
</div>
</body></html>
