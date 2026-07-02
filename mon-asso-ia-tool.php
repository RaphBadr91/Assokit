<?php
/**
 * mon-asso-ia-tool.php
 * --------------------------------------------------------------
 * Page outil IA — formulaire dynamique selon le catalogue
 * v2 (PHASE 4.6) : affichage quota restant + blocage si épuisé/interdit
 * URL : /mon-asso-ia-tool?type=KEY[&gen=ID]
 * --------------------------------------------------------------
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes-layout.php';
require_once __DIR__ . '/asso-ai-helpers.php';
require_once __DIR__ . '/asso-ai-quotas.php';

require_login();
$user = current_user();
$org_id  = (int)($user['org_id'] ?? 0);
$user_id = (int)($user['id'] ?? 0);
if ($org_id <= 0) { header('Location: /'); exit; }

$type = (string)($_GET['type'] ?? '');
$catalog = ak_ai_tools_catalog();
if (!isset($catalog[$type])) { header('Location: /mon-asso-ia'); exit; }
$tool = $catalog[$type];

$folder_def = $tool['folder'] ? ak_ai_folder($tool['folder']) : ['label' => 'Outils transverses', 'color' => '#475569', 'icon' => '🔧'];
$color = $folder_def['color'];

// Quota actuel
$quota = ak_ai_quota_check($pdo, $user, $type);

// Préchargement
$prefill = [];
$prefill_output = '';
$prefill_gen_id = 0;
$gen_id = (int)($_GET['gen'] ?? 0);
if ($gen_id > 0) {
    $prev = ak_ai_load_generation($pdo, $gen_id, $org_id);
    if ($prev) {
        $prefill = json_decode($prev['input_data'] ?? '{}', true) ?: [];
        $prefill_output = (string)($prev['output_text'] ?? '');
        $prefill_gen_id = (int)$prev['id'];
    }
}

$settings = ak_ai_get_settings($pdo, $org_id);

render_head('Communication IA — ' . $tool['label']);
render_sidebar('ia');
?>

<main class="main">
  <style>
    .iat-page { font-family: 'Geist', system-ui, sans-serif; color: #0F172A; }
    .iat-breadcrumb { font-size: 13px; color: #64748B; margin-bottom: 14px; }
    .iat-breadcrumb a { color: #64748B; text-decoration: none; }
    .iat-breadcrumb a:hover { color: #0F172A; }

    .iat-header { display: flex; align-items: center; gap: 14px; margin-bottom: 22px; flex-wrap: wrap; }
    .iat-header .ico { width: 48px; height: 48px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 24px; background: <?= h($color) ?>22; color: <?= h($color) ?>; }
    .iat-header h1 { margin: 0; font-size: 24px; font-weight: 700; letter-spacing: -0.02em; }
    .iat-header .sub { color: #64748B; font-size: 13px; margin-top: 2px; }

    /* Quota badge */
    .iat-quota {
      margin-left: auto; padding: 8px 14px; border-radius: 999px;
      font-size: 13px; font-weight: 600; display: inline-flex; align-items: center; gap: 8px;
    }
    .iat-quota.ok { background: #D1FAE5; color: #065F46; border: 1px solid #A7F3D0; }
    .iat-quota.warn { background: #FEF3C7; color: #92400E; border: 1px solid #FDE68A; }
    .iat-quota.empty { background: #FEE2E2; color: #991B1B; border: 1px solid #FECACA; }
    .iat-quota.unlimited { background: linear-gradient(135deg, #FEF3C7, #FDE68A); color: #78350F; border: 1px solid #FCD34D; }

    /* Bandeau blocage */
    .iat-blocked {
      background: #FEE2E2; border: 1px solid #FECACA; border-radius: 12px;
      padding: 16px 20px; margin-bottom: 18px; color: #991B1B; display: flex; align-items: center; gap: 14px;
    }
    .iat-blocked .ico { font-size: 28px; }
    .iat-blocked h3 { margin: 0; font-size: 15px; font-weight: 700; }
    .iat-blocked p { margin: 4px 0 0; font-size: 13px; opacity: 0.9; }

    .iat-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 18px; }
    @media (max-width: 980px) { .iat-grid { grid-template-columns: 1fr; } }

    .iat-card { background: white; border: 1px solid #E2E8F0; border-radius: 14px; padding: 22px; }
    .iat-card h2 { margin: 0 0 14px; font-size: 15px; font-weight: 700; display: flex; align-items: center; gap: 8px; }

    .iat-field { display: flex; flex-direction: column; gap: 6px; margin-bottom: 14px; }
    .iat-field label { font-size: 13px; font-weight: 600; color: #334155; }
    .iat-field .hint { font-size: 12px; color: #64748B; }
    .iat-field input, .iat-field select, .iat-field textarea {
      padding: 10px 12px; border: 1px solid #E2E8F0; border-radius: 8px; font-size: 14px;
      font-family: inherit; resize: vertical;
    }
    .iat-field input:focus, .iat-field select:focus, .iat-field textarea:focus {
      outline: none; border-color: <?= h($color) ?>;
      box-shadow: 0 0 0 3px <?= h($color) ?>22;
    }

    .iat-radio { display: flex; flex-wrap: wrap; gap: 8px; }
    .iat-radio label { padding: 7px 11px; border: 1px solid #E2E8F0; border-radius: 8px; cursor: pointer; font-size: 12px; font-weight: 500; }
    .iat-radio input { display: none; }
    .iat-radio input:checked + span { background: <?= h($color) ?>22; border-color: <?= h($color) ?>; color: <?= h($color) ?>; padding: 7px 11px; border-radius: 6px; font-weight: 600; }

    .iat-btn { padding: 12px 18px; border-radius: 10px; font-size: 14px; font-weight: 600; border: none; cursor: pointer; display: inline-flex; align-items: center; justify-content: center; gap: 8px; text-decoration: none; }
    .iat-btn-primary { background: <?= h($color) ?>; color: white; }
    .iat-btn-primary:hover:not(:disabled) { filter: brightness(0.92); }
    .iat-btn-primary:disabled { opacity: 0.4; cursor: not-allowed; background: #94A3B8 !important; }
    .iat-btn-ghost { background: white; color: #475569; border: 1px solid #E2E8F0; }
    .iat-btn-ghost:hover { background: #F8FAFC; }
    .iat-btn-diffuse { background: linear-gradient(135deg, #7E22CE, #EC4899); color: white; border: none; }

    .iat-result-card { min-height: 300px; display: flex; flex-direction: column; }
    .iat-result-empty { flex: 1; display: flex; flex-direction: column; align-items: center; justify-content: center; text-align: center; padding: 40px 16px; color: #94A3B8; }
    .iat-result-empty .big { font-size: 48px; margin-bottom: 12px; opacity: 0.4; }
    .iat-result-loading { flex: 1; display: flex; flex-direction: column; align-items: center; justify-content: center; padding: 40px 16px; color: #64748B; }
    .iat-spinner { width: 36px; height: 36px; border: 3px solid #E2E8F0; border-top-color: <?= h($color) ?>; border-radius: 50%; animation: spin 0.8s linear infinite; margin-bottom: 14px; }
    @keyframes spin { to { transform: rotate(360deg); } }

    .iat-output { background: #F8FAFC; border: 1px solid #E2E8F0; border-radius: 10px; padding: 18px; font-size: 14px; line-height: 1.65; white-space: pre-wrap; word-wrap: break-word; max-height: 560px; overflow-y: auto; flex: 1; }
    .iat-output strong { font-weight: 700; }
    .iat-output em { font-style: italic; }
    .iat-output h1, .iat-output h2, .iat-output h3 { margin: 12px 0 6px; font-weight: 700; }
    .iat-output h1 { font-size: 18px; } .iat-output h2 { font-size: 16px; } .iat-output h3 { font-size: 14px; }
    .iat-output ul { margin: 8px 0; padding-left: 20px; }
    .iat-output li { margin: 4px 0; }

    .iat-actions { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 14px; }
    .iat-actions .iat-btn { padding: 9px 14px; font-size: 13px; }
    .iat-error { background: #FEE2E2; border: 1px solid #FECACA; color: #991B1B; padding: 12px 14px; border-radius: 10px; font-size: 13px; }
  </style>

  <div class="iat-page">

    <div class="iat-breadcrumb">
      <a href="/mon-asso-ia">Communication IA</a>
      &nbsp;›&nbsp;
      <?= $tool['folder'] ? h($folder_def['label']) : 'Outils transverses' ?>
      &nbsp;›&nbsp;
      <strong style="color: #0F172A;"><?= h($tool['label']) ?></strong>
    </div>

    <div class="iat-header">
      <div class="ico"><?= $tool['icon'] ?></div>
      <div>
        <h1><?= h($tool['label']) ?></h1>
        <div class="sub"><?= h($tool['desc']) ?></div>
      </div>
      <?php
        // Badge quota
        if ($quota['unlimited']) {
            $qcls = 'unlimited'; $qtxt = '👑 Illimité';
        } elseif (!$quota['allowed']) {
            $qcls = 'empty'; $qtxt = '🔒 ' . ($quota['limit'] > 0 ? "{$quota['used']}/{$quota['limit']} aujourd'hui" : 'Non autorisé');
        } elseif ($quota['remaining'] <= 1) {
            $qcls = 'warn'; $qtxt = "⏳ {$quota['remaining']} restant aujourd'hui ({$quota['used']}/{$quota['limit']})";
        } else {
            $qcls = 'ok'; $qtxt = "✓ {$quota['remaining']} restant{$quota['remaining']} aujourd'hui ({$quota['used']}/{$quota['limit']})";
            // pluriel propre
            $plural = $quota['remaining'] > 1 ? 's' : '';
            $qtxt = "✓ {$quota['remaining']} restant{$plural} aujourd'hui ({$quota['used']}/{$quota['limit']})";
        }
      ?>
      <div class="iat-quota <?= h($qcls) ?>" id="quotaBadge"><?= h($qtxt) ?></div>
    </div>

    <?php if (!$quota['allowed']): ?>
      <div class="iat-blocked">
        <div class="ico">🔒</div>
        <div>
          <h3>Accès refusé</h3>
          <p><?= h($quota['reason'] ?? '') ?></p>
        </div>
      </div>
    <?php endif; ?>

    <div class="iat-grid">

      <!-- COLONNE FORMULAIRE -->
      <div class="iat-card">
        <h2>📝 Paramètres</h2>
        <form id="iaForm" data-tool="<?= h($type) ?>">

          <?php foreach ($tool['fields'] as $field):
            $fname = $field['name'];
            $fval = $prefill[$fname] ?? ($field['default'] ?? '');
            $fdef = $field['type'];
            $req = !empty($field['required']) ? 'required' : '';
          ?>

            <?php if ($fdef === 'tone'): ?>
              <div class="iat-field">
                <label><?= h($field['label']) ?></label>
                <div class="iat-radio">
                  <?php foreach (['chaleureux'=>'Chaleureux','professionnel'=>'Pro','dynamique'=>'Dynamique','inspirant'=>'Inspirant','engage'=>'Engagé','humoristique'=>'Humour'] as $v=>$l):
                    $checked = ($fval ?: $settings['default_tone']) === $v;
                  ?>
                    <label><input type="radio" name="<?= h($fname) ?>" value="<?= h($v) ?>" <?= $checked?'checked':'' ?>><span><?= h($l) ?></span></label>
                  <?php endforeach; ?>
                </div>
              </div>

            <?php elseif ($fdef === 'length'): ?>
              <div class="iat-field">
                <label><?= h($field['label']) ?></label>
                <div class="iat-radio">
                  <?php foreach (['short'=>'Court','medium'=>'Moyen','long'=>'Long'] as $v=>$l):
                    $checked = ($fval ?: $settings['default_length']) === $v;
                  ?>
                    <label><input type="radio" name="<?= h($fname) ?>" value="<?= h($v) ?>" <?= $checked?'checked':'' ?>><span><?= h($l) ?></span></label>
                  <?php endforeach; ?>
                </div>
              </div>

            <?php elseif ($fdef === 'select'): ?>
              <div class="iat-field">
                <label><?= h($field['label']) ?> <?= $req?'*':'' ?></label>
                <select name="<?= h($fname) ?>" <?= $req ?>>
                  <?php foreach (($field['options'] ?? []) as $v => $l): ?>
                    <option value="<?= h($v) ?>" <?= $fval===$v?'selected':'' ?>><?= h($l) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>

            <?php elseif ($fdef === 'textarea'): ?>
              <div class="iat-field">
                <label><?= h($field['label']) ?> <?= $req?'*':'' ?></label>
                <textarea name="<?= h($fname) ?>" rows="<?= (int)($field['rows'] ?? 3) ?>" <?= $req ?>
                          placeholder="<?= h($field['placeholder'] ?? '') ?>"><?= h((string)$fval) ?></textarea>
              </div>

            <?php elseif ($fdef === 'number'): ?>
              <div class="iat-field">
                <label><?= h($field['label']) ?> <?= $req?'*':'' ?></label>
                <input type="number" name="<?= h($fname) ?>"
                       min="<?= (int)($field['min'] ?? 0) ?>"
                       max="<?= (int)($field['max'] ?? 999) ?>"
                       value="<?= h((string)$fval) ?>" <?= $req ?>>
              </div>

            <?php else: ?>
              <div class="iat-field">
                <label><?= h($field['label']) ?> <?= $req?'*':'' ?></label>
                <input type="text" name="<?= h($fname) ?>"
                       value="<?= h((string)$fval) ?>"
                       placeholder="<?= h($field['placeholder'] ?? '') ?>" <?= $req ?>>
              </div>
            <?php endif; ?>

          <?php endforeach; ?>

          <button type="submit" class="iat-btn iat-btn-primary" style="width:100%;margin-top:8px;" <?= !$quota['allowed'] ? 'disabled' : '' ?>>
            <?php if (!$quota['allowed']): ?>
              🔒 Génération bloquée
            <?php else: ?>
              ✨ Générer
            <?php endif; ?>
          </button>
        </form>
      </div>

      <!-- COLONNE RÉSULTAT -->
      <div class="iat-card iat-result-card">
        <h2>✨ Résultat</h2>

        <div id="iaResultEmpty" class="iat-result-empty" <?= $prefill_output ? 'style="display:none;"' : '' ?>>
          <div class="big">🪄</div>
          <div>Remplissez le formulaire à gauche puis cliquez sur <strong>Générer</strong>.</div>
        </div>

        <div id="iaResultLoading" class="iat-result-loading" style="display:none;">
          <div class="iat-spinner"></div>
          <div>Génération en cours…<br><span style="font-size:12px;color:#94A3B8;">Quelques secondes seulement.</span></div>
        </div>

        <div id="iaResultError" class="iat-error" style="display:none;"></div>

        <div id="iaResultBox" <?= $prefill_output ? '' : 'style="display:none;"' ?>>
          <div id="iaOutput" class="iat-output"><?= ak_ai_md_to_html($prefill_output) ?></div>
          <div class="iat-actions">
            <button type="button" class="iat-btn iat-btn-ghost" onclick="copyResult()">📋 Copier</button>
            <a id="dlMd"   class="iat-btn iat-btn-ghost" href="#" target="_blank">⬇ MD</a>
            <a id="dlTxt"  class="iat-btn iat-btn-ghost" href="#" target="_blank">⬇ TXT</a>
            <a id="dlHtml" class="iat-btn iat-btn-ghost" href="#" target="_blank">⬇ HTML</a>
            <a id="diffuse" class="iat-btn iat-btn-diffuse" href="#">📨 Diffuser par email</a>
          </div>
        </div>
      </div>
    </div>
  </div>
</main>

<script>
const FORM = document.getElementById('iaForm');
const RES_EMPTY = document.getElementById('iaResultEmpty');
const RES_LOAD  = document.getElementById('iaResultLoading');
const RES_ERR   = document.getElementById('iaResultError');
const RES_BOX   = document.getElementById('iaResultBox');
const RES_OUT   = document.getElementById('iaOutput');
const DL_MD     = document.getElementById('dlMd');
const DL_TXT    = document.getElementById('dlTxt');
const DL_HTML   = document.getElementById('dlHtml');
const DIFFUSE   = document.getElementById('diffuse');
const QUOTA_BADGE = document.getElementById('quotaBadge');
let lastRawOutput = <?= json_encode($prefill_output, JSON_UNESCAPED_UNICODE) ?>;
let lastGenId = <?= (int)$prefill_gen_id ?>;

function setActionLinks(genId) {
  if (!genId) return;
  DL_MD.href   = '/mon-asso-ia-download?id=' + genId + '&fmt=md';
  DL_TXT.href  = '/mon-asso-ia-download?id=' + genId + '&fmt=txt';
  DL_HTML.href = '/mon-asso-ia-download?id=' + genId + '&fmt=html';
  DIFFUSE.href = '/mon-asso-ia-diffusion?gen=' + genId;
}
setActionLinks(lastGenId);

function mdToHtmlSimple(md) {
  let h = md
    .replace(/[&]/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
    .replace(/^### (.+)$/gm, '<h3>$1</h3>')
    .replace(/^## (.+)$/gm,  '<h2>$1</h2>')
    .replace(/^# (.+)$/gm,   '<h1>$1</h1>')
    .replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>')
    .replace(/\*(.+?)\*/g,     '<em>$1</em>')
    .replace(/^- (.+)$/gm, '<li>$1</li>');
  h = h.replace(/(<li>.*?<\/li>(\n|$))+/gs, m => '<ul>' + m + '</ul>');
  h = h.replace(/\n/g, '<br>');
  return h;
}

function copyResult() {
  if (!lastRawOutput) return;
  navigator.clipboard.writeText(lastRawOutput).then(() => alert('✅ Copié !'));
}

function updateQuotaBadge(q) {
  if (!q || !QUOTA_BADGE) return;
  let cls, txt;
  if (q.unlimited) { cls = 'unlimited'; txt = '👑 Illimité'; }
  else if (!q.allowed) { cls = 'empty'; txt = '🔒 ' + (q.limit > 0 ? q.used + '/' + q.limit + ' aujourd\'hui' : 'Non autorisé'); }
  else if (q.remaining <= 1) { cls = 'warn'; txt = '⏳ ' + q.remaining + ' restant aujourd\'hui (' + q.used + '/' + q.limit + ')'; }
  else { cls = 'ok'; txt = '✓ ' + q.remaining + ' restants aujourd\'hui (' + q.used + '/' + q.limit + ')'; }
  QUOTA_BADGE.className = 'iat-quota ' + cls;
  QUOTA_BADGE.textContent = txt;

  // Désactive le bouton si plus de quota
  const submitBtn = FORM.querySelector('button[type=submit]');
  if (!q.allowed && !q.unlimited) {
    submitBtn.disabled = true;
    submitBtn.innerHTML = '🔒 Quota épuisé';
  }
}

FORM.addEventListener('submit', async (e) => {
  e.preventDefault();
  const submitBtn = FORM.querySelector('button[type=submit]');
  if (submitBtn.disabled) return;
  submitBtn.disabled = true;
  submitBtn.innerHTML = '⏳ Génération…';
  RES_EMPTY.style.display = 'none';
  RES_BOX.style.display = 'none';
  RES_ERR.style.display = 'none';
  RES_LOAD.style.display = 'flex';

  try {
    const fd = new FormData(FORM);
    fd.append('tool', '<?= h($type) ?>');
    const r = await fetch('/mon-asso-ia-generate', { method: 'POST', body: fd });
    const data = await r.json();
    RES_LOAD.style.display = 'none';

    if (data.quota) updateQuotaBadge(data.quota);

    if (data.ok) {
      lastRawOutput = data.text;
      lastGenId = data.gen_id;
      RES_OUT.innerHTML = mdToHtmlSimple(data.text);
      RES_BOX.style.display = '';
      setActionLinks(lastGenId);
    } else {
      RES_ERR.textContent = '⚠️ ' + (data.error || 'Erreur inconnue');
      RES_ERR.style.display = 'block';
    }
  } catch (err) {
    RES_LOAD.style.display = 'none';
    RES_ERR.textContent = '⚠️ Erreur réseau : ' + err.message;
    RES_ERR.style.display = 'block';
  } finally {
    if (!submitBtn.disabled || !submitBtn.innerHTML.includes('épuisé')) {
      submitBtn.disabled = false;
      submitBtn.innerHTML = '✨ Générer';
    }
  }
});
</script>

<?php render_foot(); ?>
