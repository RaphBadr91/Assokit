<?php
/**
 * devis-public.php — Page publique signature client
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/asso-invoice-helpers.php';
require_once __DIR__ . '/asso-quote-helpers.php';

$uuid = $_GET['uuid'] ?? '';
if (empty($uuid) || !preg_match('/^[a-f0-9-]{36}$/i', $uuid)) {
    http_response_code(404); die('Devis introuvable.');
}

$stmt = $pdo->prepare("
    SELECT q.*, c.display_name AS client_name, c.email AS client_email,
           o.name AS org_name, o.billing_email AS org_billing_email
    FROM asso_quotes q
    LEFT JOIN asso_clients c ON c.id = q.client_id
    LEFT JOIN organizations o ON o.id = q.org_id
    WHERE q.public_uuid = :u LIMIT 1
");
$stmt->execute([':u' => $uuid]);
$quote = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$quote) { http_response_code(404); die('Devis introuvable.'); }

$stmt = $pdo->prepare("SELECT * FROM asso_quote_lines WHERE quote_id = :id ORDER BY line_order");
$stmt->execute([':id' => $quote['id']]);
$lines = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Tracker la consultation
try {
    $pdo->prepare("UPDATE asso_quotes SET last_viewed_at = NOW(), view_count = view_count + 1 WHERE id = :id")
        ->execute([':id' => $quote['id']]);
} catch (Throwable $e) {}

if (empty($_SESSION['csrf_token_public'])) {
    $_SESSION['csrf_token_public'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token_public'];
$flash = $_SESSION['flash_public'] ?? null;
unset($_SESSION['flash_public']);

$h = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$is_signed = !empty($quote['signed_at']);
$is_refused = !empty($quote['refused_at']);
$is_expired = strtotime($quote['expires_at']) < time();
$can_sign = !$is_signed && !$is_refused && !$is_expired && $quote['status'] !== 'cancelled' && $quote['status'] !== 'converted';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Devis <?= $h($quote['quote_number']) ?> — <?= $h($quote['org_name']) ?></title>
<style>
* { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background: #FAF5FF; color: #1F2937; line-height: 1.5; min-height: 100vh; padding: 20px; }
.wrapper { max-width: 800px; margin: 0 auto; }
.card { background: white; border-radius: 12px; box-shadow: 0 4px 20px rgba(0,0,0,0.05); overflow: hidden; margin-bottom: 16px; }
.header { background: linear-gradient(135deg, #7E22CE 0%, #A855F7 100%); color: white; padding: 30px 28px; }
.header h1 { font-size: 22px; font-weight: 700; margin-bottom: 4px; }
.header .number { font-size: 14px; opacity: 0.9; letter-spacing: 0.5px; }
.header .org { margin-top: 14px; font-size: 13px; opacity: 0.85; }
.status-banner { padding: 14px 28px; font-size: 14px; font-weight: 600; }
.status-signed { background: #D1FAE5; color: #065F46; }
.status-pending { background: #FEF3C7; color: #92400E; }
.status-expired { background: #FEE2E2; color: #991B1B; }
.status-refused { background: #FEE2E2; color: #991B1B; }
.status-converted { background: #F3E8FF; color: #6B21A8; }
.body { padding: 28px; }
.section { margin-bottom: 24px; }
.section h2 { font-size: 12px; text-transform: uppercase; color: #6B7280; letter-spacing: 0.5px; font-weight: 600; margin-bottom: 8px; }
.grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 24px; }
.label { color: #6B7280; font-size: 13px; }
.value { color: #111827; font-weight: 500; font-size: 14px; }
.amount { font-size: 32px; font-weight: 700; color: #7E22CE; font-variant-numeric: tabular-nums; margin: 4px 0; }
table { width: 100%; border-collapse: collapse; margin-top: 8px; font-size: 13px; }
table thead { background: #F3F4F6; }
table th { padding: 10px 12px; text-align: left; font-size: 11px; color: #6B7280; text-transform: uppercase; font-weight: 600; }
table th.num { text-align: right; }
table td { padding: 12px; border-bottom: 1px solid #F3F4F6; }
table td.num { text-align: right; font-variant-numeric: tabular-nums; }
.totals { margin-top: 14px; padding: 14px; background: #FAF5FF; border-radius: 8px; }
.totals-row { display: flex; justify-content: space-between; padding: 4px 0; font-size: 14px; }
.totals-row.ttc { background: #7E22CE; color: white; margin: 8px -14px -14px; padding: 14px; border-radius: 0 0 8px 8px; font-size: 16px; font-weight: 700; }
.actions { padding: 22px 28px; border-top: 1px solid #E5E7EB; display: flex; gap: 12px; flex-wrap: wrap; }
.btn { padding: 12px 22px; border-radius: 8px; border: none; font-size: 14px; font-weight: 600; cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; gap: 6px; }
.btn-primary { background: #7E22CE; color: white; }
.btn-primary:hover { background: #6B21A8; }
.btn-secondary { background: #F3F4F6; color: #374151; }
.btn-refuse { background: #FEE2E2; color: #991B1B; }
.alert { padding: 14px 18px; border-radius: 10px; margin-bottom: 16px; font-size: 14px; }
.alert-success { background: #D1FAE5; color: #065F46; border-left: 4px solid #10B981; }
.alert-error { background: #FEE2E2; color: #991B1B; border-left: 4px solid #DC2626; }
.modal-bg { display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); z-index: 1000; align-items: center; justify-content: center; padding: 20px; }
.modal-bg.active { display: flex; }
.modal { background: white; border-radius: 12px; max-width: 600px; width: 100%; padding: 24px; max-height: 90vh; overflow-y: auto; }
.modal h3 { margin-bottom: 14px; font-size: 18px; }
.modal label { display: block; font-size: 12px; color: #6B7280; margin: 12px 0 4px; font-weight: 500; }
.modal input, .modal select, .modal textarea { width: 100%; padding: 10px; border: 1px solid #E5E7EB; border-radius: 8px; font-size: 14px; font-family: inherit; }
.modal-actions { margin-top: 18px; display: flex; gap: 10px; justify-content: flex-end; }
.tab-buttons { display: flex; gap: 6px; margin: 12px 0; }
.tab-btn { flex: 1; padding: 10px; background: #F3F4F6; border: 1px solid #E5E7EB; border-radius: 6px; cursor: pointer; font-size: 13px; font-weight: 500; }
.tab-btn.active { background: #7E22CE; color: white; border-color: #7E22CE; }
canvas#sign-canvas { width: 100%; border: 2px dashed #D1D5DB; border-radius: 8px; touch-action: none; cursor: crosshair; background: #FAFAFA; }
.canvas-actions { display: flex; gap: 8px; margin-top: 6px; }
.checkbox-area { padding: 14px; background: #FAF5FF; border-radius: 8px; }
.checkbox-area label { display: flex !important; align-items: center; gap: 10px; font-size: 14px; color: #1F2937; cursor: pointer; margin: 0 !important; }
.checkbox-area input[type="checkbox"] { width: 18px; height: 18px; cursor: pointer; }
.footer { text-align: center; color: #9CA3AF; font-size: 12px; margin-top: 30px; padding: 14px; }
</style>
</head>
<body>
<div class="wrapper">
    <div class="card">
        <div class="header">
            <h1>Devis <?= $h($quote['quote_number']) ?></h1>
            <div class="number">N° <?= $h($quote['quote_number']) ?></div>
            <div class="org">Émis par <strong><?= $h($quote['org_name']) ?></strong></div>
        </div>

        <?php if ($is_signed): ?>
            <div class="status-banner status-signed">✓ Devis accepté et signé le <?= $h(date('d/m/Y', strtotime($quote['signed_at']))) ?></div>
        <?php elseif ($quote['status'] === 'converted'): ?>
            <div class="status-banner status-converted">→ Devis converti en facture</div>
        <?php elseif ($is_refused): ?>
            <div class="status-banner status-refused">✗ Devis refusé</div>
        <?php elseif ($is_expired): ?>
            <div class="status-banner status-expired">⚠ Devis expiré (validité dépassée)</div>
        <?php else: ?>
            <div class="status-banner status-pending">⏳ En attente de votre acceptation — valable jusqu'au <?= $h(date('d/m/Y', strtotime($quote['expires_at']))) ?></div>
        <?php endif; ?>

        <div class="body">
            <?php if ($flash): ?>
                <div class="alert <?= $flash['type'] === 'success' ? 'alert-success' : 'alert-error' ?>"><?= $h($flash['message']) ?></div>
            <?php endif; ?>

            <div class="section grid-2">
                <div>
                    <h2>📅 Dates</h2>
                    <div class="label">Émis le</div>
                    <div class="value"><?= $h(date('d/m/Y', strtotime($quote['issued_at']))) ?></div>
                    <div class="label" style="margin-top:8px;">Valable jusqu'au</div>
                    <div class="value"><?= $h(date('d/m/Y', strtotime($quote['expires_at']))) ?></div>
                </div>
                <div>
                    <h2>👤 Adressé à</h2>
                    <div class="value"><?= $h($quote['client_name']) ?></div>
                    <div class="label"><?= $h($quote['client_email']) ?></div>
                </div>
            </div>

            <div class="section">
                <h2>💶 Montant</h2>
                <div class="amount"><?= $h(ak_asso_fmt_cents((int)$quote['amount_ttc_cents'])) ?></div>
                <div class="label">TTC</div>
            </div>

            <?php if (!empty($lines)): ?>
            <div class="section">
                <h2>📦 Désignations</h2>
                <table>
                    <thead><tr><th>Description</th><th class="num">Qté</th><th class="num">PU HT</th><th class="num">TVA</th><th class="num">Total HT</th></tr></thead>
                    <tbody>
                        <?php foreach ($lines as $line): ?>
                        <tr>
                            <td><?= $h($line['designation']) ?></td>
                            <td class="num"><?= $h(rtrim(rtrim(number_format((float)$line['quantity'], 2, ',', ' '), '0'), ',')) ?></td>
                            <td class="num"><?= $h(ak_asso_fmt_cents((int)$line['unit_price_ht_cents'])) ?></td>
                            <td class="num"><?= !empty($line['vat_rate']) ? $h(number_format((float)$line['vat_rate'], 1, ',', '')) . '%' : '—' ?></td>
                            <td class="num"><?= $h(ak_asso_fmt_cents((int)$line['total_ht_cents'])) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <div class="totals">
                    <div class="totals-row"><span>Total HT</span><span><?= $h(ak_asso_fmt_cents((int)$quote['amount_ht_cents'])) ?></span></div>
                    <?php if ((int)$quote['amount_vat_cents'] > 0): ?>
                        <div class="totals-row"><span>TVA</span><span><?= $h(ak_asso_fmt_cents((int)$quote['amount_vat_cents'])) ?></span></div>
                    <?php else: ?>
                        <div class="totals-row" style="color:#6B7280;font-size:12px;"><span>TVA non applicable</span><span>0,00 €</span></div>
                    <?php endif; ?>
                    <div class="totals-row ttc"><span>⭐ TOTAL TTC</span><span><?= $h(ak_asso_fmt_cents((int)$quote['amount_ttc_cents'])) ?></span></div>
                </div>
            </div>
            <?php endif; ?>

            <?php if (!empty($quote['terms'])): ?>
            <div class="section">
                <h2>📋 Conditions</h2>
                <div style="background:#FEF3C7; padding:12px 14px; border-radius:8px; border-left:3px solid #F59E0B; font-size:13px; white-space:pre-wrap;"><?= $h($quote['terms']) ?></div>
            </div>
            <?php endif; ?>
        </div>

        <?php if ($can_sign): ?>
        <div class="actions">
            <?php if (!empty($quote['pdf_path'])): ?>
                <a href="<?= $h($quote['pdf_path']) ?>" target="_blank" class="btn btn-secondary">📥 Télécharger le PDF</a>
            <?php endif; ?>
            <button onclick="document.getElementById('modal-sign').classList.add('active')" class="btn btn-primary">✓ Accepter et signer</button>
            <button onclick="document.getElementById('modal-refuse').classList.add('active')" class="btn btn-refuse">✗ Refuser</button>
        </div>
        <?php else: ?>
        <div class="actions">
            <?php if (!empty($quote['pdf_path'])): ?>
                <a href="<?= $h($quote['pdf_path']) ?>" target="_blank" class="btn btn-secondary">📥 Télécharger le PDF</a>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>

    <div class="footer">Devis sécurisé par <strong>Assokit</strong> · <a href="https://assokit.fr" style="color:#7E22CE;">assokit.fr</a></div>
</div>

<?php if ($can_sign): ?>
<!-- MODAL SIGNATURE -->
<div id="modal-sign" class="modal-bg">
    <div class="modal">
        <h3>✓ Accepter et signer ce devis</h3>
        <p style="font-size:13px; color:#6B7280; margin-bottom:14px;">
            Vous attestez accepter ce devis et ses conditions. Signature légalement engageante.
        </p>
        <form method="POST" action="/devis-sign" id="sign-form">
            <input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>">
            <input type="hidden" name="uuid" value="<?= $h($uuid) ?>">
            <input type="hidden" name="signature_image" id="signature_image_input" value="">

            <label>Votre nom complet *</label>
            <input type="text" name="signature_name" required maxlength="200" placeholder="Marie Dupont">

            <div class="tab-buttons">
                <button type="button" class="tab-btn active" id="tab-checkbox" onclick="selectMode('checkbox')">☑ Case à cocher</button>
                <button type="button" class="tab-btn" id="tab-drawn" onclick="selectMode('drawn')">✍ Signature dessinée</button>
            </div>

            <input type="hidden" name="signature_type" id="signature_type" value="checkbox">

            <div id="mode-checkbox" class="checkbox-area">
                <label>
                    <input type="checkbox" id="accept-checkbox" required>
                    <span>J'accepte ce devis et ses conditions générales</span>
                </label>
            </div>

            <div id="mode-drawn" style="display:none;">
                <label style="margin-top:8px;">Signez avec votre souris ou votre doigt :</label>
                <canvas id="sign-canvas" width="500" height="180"></canvas>
                <div class="canvas-actions">
                    <button type="button" onclick="clearCanvas()" class="btn btn-secondary" style="padding:6px 14px; font-size:12px;">🗑 Effacer</button>
                </div>
            </div>

            <div class="modal-actions">
                <button type="button" onclick="document.getElementById('modal-sign').classList.remove('active')" class="btn btn-secondary">Annuler</button>
                <button type="submit" class="btn btn-primary" id="submit-sign">✓ Signer le devis</button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL REFUS -->
<div id="modal-refuse" class="modal-bg">
    <div class="modal">
        <h3>✗ Refuser ce devis</h3>
        <p style="font-size:13px; color:#6B7280; margin-bottom:14px;">L'émetteur sera notifié de votre refus.</p>
        <form method="POST" action="/devis-sign">
            <input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>">
            <input type="hidden" name="uuid" value="<?= $h($uuid) ?>">
            <input type="hidden" name="action" value="refuse">

            <label>Raison du refus (optionnel)</label>
            <textarea name="refuse_reason" rows="3" placeholder="Pourquoi refusez-vous ce devis ?"></textarea>

            <div class="modal-actions">
                <button type="button" onclick="document.getElementById('modal-refuse').classList.remove('active')" class="btn btn-secondary">Annuler</button>
                <button type="submit" class="btn btn-refuse">✗ Confirmer le refus</button>
            </div>
        </form>
    </div>
</div>

<script>
let canvas, ctx, drawing = false, hasDrawn = false;

function selectMode(mode) {
    document.getElementById('tab-checkbox').classList.toggle('active', mode === 'checkbox');
    document.getElementById('tab-drawn').classList.toggle('active', mode === 'drawn');
    document.getElementById('mode-checkbox').style.display = mode === 'checkbox' ? 'block' : 'none';
    document.getElementById('mode-drawn').style.display = mode === 'drawn' ? 'block' : 'none';
    document.getElementById('signature_type').value = mode;
    document.getElementById('accept-checkbox').required = (mode === 'checkbox');

    if (mode === 'drawn' && !canvas) initCanvas();
}

function initCanvas() {
    canvas = document.getElementById('sign-canvas');
    ctx = canvas.getContext('2d');
    ctx.lineWidth = 2;
    ctx.lineCap = 'round';
    ctx.strokeStyle = '#1F2937';

    const start = (e) => { drawing = true; const pt = getPos(e); ctx.beginPath(); ctx.moveTo(pt.x, pt.y); };
    const move = (e) => { if (!drawing) return; e.preventDefault(); const pt = getPos(e); ctx.lineTo(pt.x, pt.y); ctx.stroke(); hasDrawn = true; };
    const stop = () => { drawing = false; };

    canvas.addEventListener('mousedown', start);
    canvas.addEventListener('mousemove', move);
    canvas.addEventListener('mouseup', stop);
    canvas.addEventListener('mouseleave', stop);
    canvas.addEventListener('touchstart', start, {passive:false});
    canvas.addEventListener('touchmove', move, {passive:false});
    canvas.addEventListener('touchend', stop);

    function getPos(e) {
        const rect = canvas.getBoundingClientRect();
        const scaleX = canvas.width / rect.width;
        const scaleY = canvas.height / rect.height;
        const t = e.touches ? e.touches[0] : e;
        return { x: (t.clientX - rect.left) * scaleX, y: (t.clientY - rect.top) * scaleY };
    }
}

function clearCanvas() {
    if (!canvas) return;
    ctx.clearRect(0, 0, canvas.width, canvas.height);
    hasDrawn = false;
}

document.getElementById('sign-form').addEventListener('submit', function(e) {
    const mode = document.getElementById('signature_type').value;
    if (mode === 'drawn') {
        if (!hasDrawn) { e.preventDefault(); alert('Merci de signer dans la zone dédiée.'); return; }
        document.getElementById('signature_image_input').value = canvas.toDataURL('image/png');
    }
});
</script>
<?php endif; ?>
</body>
</html>
