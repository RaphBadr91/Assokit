<?php
/**
 * /emarger/<token> — Page publique signature
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes-attendance.php';

$token = trim($_GET['t'] ?? '');
if (!preg_match('/^[a-f0-9]{32,64}$/', $token)) { http_response_code(404); die('Lien invalide.'); }

$sess = att_load_by_token($pdo, $token);
if (!$sess) { http_response_code(404); die('Session introuvable.'); }

$flash = ''; $flash_ok = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$sess['is_open']) {
        $flash = 'La session est fermée. Émargement impossible.';
    } else {
        $name = trim($_POST['full_name'] ?? '');
        $email = trim($_POST['email'] ?? '') ?: null;
        $phone = trim($_POST['phone'] ?? '') ?: null;
        $sig = trim($_POST['signature'] ?? '');
        if (!$name) {
            $flash = 'Nom requis.';
        } elseif ($sess['require_signature'] && (strpos($sig, 'data:image/') !== 0 || strlen($sig) > 500000 || strlen($sig) < 200)) {
            $flash = 'Signature requise.';
        } else {
            // Anti-doublon : même nom dans les 30 dernières min
            $check = $pdo->prepare("SELECT id FROM attendance_records WHERE session_id = ? AND LOWER(full_name) = LOWER(?) AND signed_at >= DATE_SUB(NOW(), INTERVAL 30 MINUTE)");
            $check->execute([(int)$sess['id'], $name]);
            if ($check->fetch()) {
                $flash = 'Tu as déjà émargé il y a moins de 30 minutes.';
            } else {
                $ip = $_SERVER['REMOTE_ADDR'] ?? null;
                $pdo->prepare("INSERT INTO attendance_records (session_id, full_name, email, phone, signature_data, ip) VALUES (?,?,?,?,?,?)")
                    ->execute([(int)$sess['id'], $name, $email, $phone, $sess['require_signature'] ? $sig : null, $ip]);
                $flash = '✅ Émargement enregistré. Merci !';
                $flash_ok = true;
            }
        }
    }
}

$nb_signed = 0;
try { $stmt = $pdo->prepare("SELECT COUNT(*) FROM attendance_records WHERE session_id = ?"); $stmt->execute([(int)$sess['id']]); $nb_signed = (int)$stmt->fetchColumn(); } catch (Throwable $e) {}
?><!doctype html>
<html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Émargement — <?= h($sess['title']) ?></title>
<style>
*{box-sizing:border-box}body{margin:0;font-family:-apple-system,sans-serif;background:#f3f4f6;color:#111827}
.pp{max-width:560px;margin:0 auto;padding:18px}
.pp-hero{background:linear-gradient(135deg,#10B981,#059669);color:#fff;border-radius:16px;padding:22px;margin-bottom:14px}
.pp-hero h1{font-size:20px;margin:0 0 6px}
.pp-hero-sub{opacity:.92;font-size:13px}
.pp-card{background:#fff;border-radius:12px;padding:20px;margin-bottom:12px;border:1px solid #e5e7eb}
.pp-card h2{font-size:14px;margin:0 0 12px;color:#111827}
.pp-flash{padding:10px 14px;border-radius:8px;margin-bottom:14px;font-size:13px;border:1px solid}
.pp-flash.ok{background:#ECFDF5;color:#065F46;border-color:#A7F3D0}
.pp-flash.ko{background:#FEE2E2;color:#991B1B;border-color:#FCA5A5}
.pp-locked{padding:14px;background:#FEF3C7;color:#92400E;border-radius:10px;font-size:13.5px;text-align:center}
.pp-fld{margin-bottom:14px}
.pp-fld label{display:block;font-size:12px;font-weight:600;color:#374151;margin-bottom:5px;text-transform:uppercase;letter-spacing:.04em}
.pp-fld input{width:100%;padding:10px 12px;border:1px solid #e5e7eb;border-radius:8px;font-size:15px;font-family:inherit;box-sizing:border-box}
.pp-fld input:focus{outline:none;border-color:#10B981;box-shadow:0 0 0 3px rgba(16,185,129,.15)}
.pp-sig-canvas{border:2px dashed #d1d5db;border-radius:10px;background:#fff;touch-action:none;cursor:crosshair;width:100%;height:160px;display:block}
.pp-sig-actions{display:flex;gap:8px;margin-top:8px}
.pp-btn{padding:12px 18px;border-radius:8px;font-size:14px;font-weight:600;border:0;cursor:pointer;font-family:inherit;width:100%}
.pp-btn-primary{background:#10B981;color:#fff}
.pp-btn-primary:hover{background:#059669}
.pp-btn-ghost{background:#fff;border:1px solid #d1d5db;color:#374151;width:auto}
.pp-success{text-align:center;padding:30px 20px}
.pp-success-icon{font-size:54px;margin-bottom:8px}
.pp-stat{font-size:11px;color:#6b7280;text-align:center;padding:8px 0}
</style>
</head><body>
<div class="pp">
  <div class="pp-hero">
    <h1>✍️ <?= h($sess['title']) ?></h1>
    <div class="pp-hero-sub">📅 <?= fr_format_date('%A %d %B à %H:%M', strtotime($sess['starts_at'])) ?>
      <?php if ($sess['location']): ?> · 📍 <?= h($sess['location']) ?><?php endif; ?>
    </div>
  </div>

  <?php if ($flash): ?><div class="pp-flash <?= $flash_ok ? 'ok' : 'ko' ?>"><?= h($flash) ?></div><?php endif; ?>

  <?php if (!$sess['is_open']): ?>
    <div class="pp-card"><div class="pp-locked">🔒 Cette session d'émargement est fermée.</div></div>
  <?php elseif ($flash_ok): ?>
    <div class="pp-card pp-success">
      <div class="pp-success-icon">✅</div>
      <h2>Présence enregistrée</h2>
      <p style="color:#6b7280;font-size:13.5px;margin:6px 0 0;">Merci ! Tu peux fermer cette page.</p>
    </div>
  <?php else: ?>
    <div class="pp-card">
      <h2>Renseigne tes coordonnées</h2>
      <form method="POST" id="at-sig-form">
        <div class="pp-fld"><label>Nom complet *</label><input type="text" name="full_name" required maxlength="200" placeholder="Jean Dupont" autofocus></div>
        <div class="pp-fld"><label>Email (optionnel)</label><input type="email" name="email" maxlength="200" placeholder="jean@email.fr"></div>
        <div class="pp-fld"><label>Téléphone (optionnel)</label><input type="tel" name="phone" maxlength="40" placeholder="06 12 34 56 78"></div>

        <?php if ($sess['require_signature']): ?>
        <div class="pp-fld">
          <label>Signature *</label>
          <p style="font-size:12px;color:#6b7280;margin:0 0 6px;">Signe avec ton doigt ou la souris :</p>
          <canvas id="at-sig" class="pp-sig-canvas" width="600" height="160"></canvas>
          <div class="pp-sig-actions"><button type="button" id="at-sig-clear" class="pp-btn pp-btn-ghost">Effacer</button></div>
          <input type="hidden" name="signature" id="at-sig-data">
        </div>
        <?php endif; ?>

        <button type="submit" class="pp-btn pp-btn-primary">✓ Valider mon émargement</button>
      </form>
    </div>
    <?php if ($sess['require_signature']): ?>
    <script>
    (function(){
      var c = document.getElementById('at-sig'), ctx = c.getContext('2d'), drawing=false, hasDrawn=false;
      function init(){
        var r = c.getBoundingClientRect();
        c.width = r.width*2; c.height = 160*2; ctx.scale(2,2);
        ctx.lineWidth=2.5; ctx.lineCap='round'; ctx.strokeStyle='#111827';
        ctx.fillStyle='#fff'; ctx.fillRect(0,0,c.width,c.height);
      }
      init();
      function p(e){var r=c.getBoundingClientRect();var t=e.touches?e.touches[0]:e;return{x:t.clientX-r.left,y:t.clientY-r.top}}
      function start(e){drawing=true;hasDrawn=true;var pt=p(e);ctx.beginPath();ctx.moveTo(pt.x,pt.y);e.preventDefault();}
      function move(e){if(!drawing)return;var pt=p(e);ctx.lineTo(pt.x,pt.y);ctx.stroke();e.preventDefault();}
      function end(){drawing=false;}
      c.addEventListener('mousedown',start);c.addEventListener('mousemove',move);c.addEventListener('mouseup',end);c.addEventListener('mouseleave',end);
      c.addEventListener('touchstart',start);c.addEventListener('touchmove',move);c.addEventListener('touchend',end);
      document.getElementById('at-sig-clear').addEventListener('click',function(){ctx.fillStyle='#fff';ctx.fillRect(0,0,c.width,c.height);hasDrawn=false;});
      document.getElementById('at-sig-form').addEventListener('submit',function(e){
        if(!hasDrawn){alert('Merci de signer avant de valider.');e.preventDefault();return false;}
        document.getElementById('at-sig-data').value = c.toDataURL('image/png');
      });
    })();
    </script>
    <?php endif; ?>
  <?php endif; ?>

  <div class="pp-stat">AssoKit · <?= $nb_signed ?> personne<?= $nb_signed > 1 ? 's' : '' ?> émargée<?= $nb_signed > 1 ? 's' : '' ?></div>
</div>
</body></html>
