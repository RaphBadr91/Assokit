<?php
/**
 * mon-asso-ia-diffusion.php
 * --------------------------------------------------------------
 * Page Diffusion email — Pack PHASE 4.5
 * Permet d'envoyer une génération (ou un texte libre) à des destinataires
 * sélectionnés par rôle / projet / liste manuelle.
 * --------------------------------------------------------------
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes-layout.php';
require_once __DIR__ . '/asso-ai-helpers.php';

require_login();
$user = current_user();
if (!in_array(($user['role'] ?? ''), ['admin', 'coordinator'], true)) {
    http_response_code(403);
    header('Location: /dashboard');
    exit;
}
$org_id  = (int)($user['org_id'] ?? 0);
$user_id = (int)($user['id'] ?? 0);
if ($org_id <= 0) { header('Location: /'); exit; }

$page_error = null;
$gen_prefill = null;
$gen_id = (int)($_GET['gen'] ?? 0);

if ($gen_id > 0) {
    $gen_prefill = ak_ai_load_generation($pdo, $gen_id, $org_id);
}

// Récupère rôles disponibles + projets pour les sélecteurs
$roles_available = [];
$projects_available = [];
$recent_generations = [];
$recent_diffusions = [];

try {
    $st = $pdo->prepare("SELECT DISTINCT role FROM users WHERE org_id = :o AND role IS NOT NULL AND role != '' AND (deleted_at IS NULL) ORDER BY role");
    $st->execute([':o' => $org_id]);
    $roles_available = $st->fetchAll(PDO::FETCH_COLUMN);
} catch (Throwable $e) { error_log('[diffusion roles] ' . $e->getMessage()); }

try {
    // Projets via la jointure folders.org_id (vu dans dashboard.php)
    $st = $pdo->prepare("
        SELECT p.id, p.name FROM projects p
        INNER JOIN folders f ON f.id = p.folder_id
        WHERE f.org_id = :o AND p.status IN ('active','warning')
        ORDER BY p.name ASC LIMIT 100
    ");
    $st->execute([':o' => $org_id]);
    $projects_available = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { error_log('[diffusion projects] ' . $e->getMessage()); }

try {
    $st = $pdo->prepare("
        SELECT id, tool_type, title, created_at FROM asso_ai_generations
        WHERE org_id = :o AND status = 'success'
        ORDER BY created_at DESC LIMIT 30
    ");
    $st->execute([':o' => $org_id]);
    $recent_generations = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { /* silent */ }

try {
    $st = $pdo->prepare("
        SELECT id, subject, recipients_count, sent_count, failed_count, status, created_at
        FROM asso_ai_diffusions WHERE org_id = :o ORDER BY created_at DESC LIMIT 10
    ");
    $st->execute([':o' => $org_id]);
    $recent_diffusions = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { /* silent (table peut-être absente) */ }

$prefill_subject = $gen_prefill['title'] ?? '';
$prefill_body    = $gen_prefill['output_text'] ?? '';

render_head('Diffusion email');
render_sidebar('ia');
?>

<main class="main">
  <style>
    .dif-page { font-family: 'Geist', system-ui, sans-serif; color: #0F172A; }
    .dif-breadcrumb { font-size: 13px; color: #64748B; margin-bottom: 14px; }
    .dif-breadcrumb a { color: #64748B; text-decoration: none; }
    .dif-header { display: flex; align-items: center; gap: 14px; margin-bottom: 22px; }
    .dif-header .ico { width: 48px; height: 48px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 24px; background: linear-gradient(135deg, #7E22CE22, #EC489922); color: #7E22CE; }
    .dif-header h1 { margin: 0; font-size: 24px; font-weight: 700; }
    .dif-header .sub { color: #64748B; font-size: 13px; margin-top: 2px; }

    .dif-grid { display: grid; grid-template-columns: 2fr 1fr; gap: 18px; }
    @media (max-width: 980px) { .dif-grid { grid-template-columns: 1fr; } }

    .dif-card { background: white; border: 1px solid #E2E8F0; border-radius: 14px; padding: 22px; margin-bottom: 16px; }
    .dif-card h2 { margin: 0 0 14px; font-size: 15px; font-weight: 700; display: flex; align-items: center; gap: 8px; }

    .dif-field { display: flex; flex-direction: column; gap: 6px; margin-bottom: 14px; }
    .dif-field label { font-size: 13px; font-weight: 600; color: #334155; }
    .dif-field .hint { font-size: 12px; color: #64748B; }
    .dif-field input[type=text], .dif-field select, .dif-field textarea {
      padding: 10px 12px; border: 1px solid #E2E8F0; border-radius: 8px; font-size: 14px;
      font-family: inherit; resize: vertical;
    }
    .dif-field input:focus, .dif-field select:focus, .dif-field textarea:focus {
      outline: none; border-color: #7E22CE; box-shadow: 0 0 0 3px rgba(126,34,206,.12);
    }

    .dif-checks { display: flex; flex-wrap: wrap; gap: 8px; }
    .dif-checks label { padding: 7px 12px; border: 1px solid #E2E8F0; border-radius: 8px; cursor: pointer; font-size: 13px; display: inline-flex; align-items: center; gap: 6px; user-select: none; transition: all 0.1s; }
    .dif-checks label:hover { background: #F8FAFC; }
    .dif-checks input { display: none; }
    .dif-checks input:checked + span { font-weight: 600; }
    .dif-checks label:has(input:checked) { background: #7E22CE15; border-color: #7E22CE; color: #7E22CE; }

    .dif-counter { background: linear-gradient(135deg, #F8FAFC, #EFF6FF); border: 2px dashed #CBD5E1; border-radius: 12px; padding: 18px; text-align: center; margin: 14px 0; }
    .dif-counter .num { font-size: 32px; font-weight: 700; color: #7E22CE; }
    .dif-counter .lbl { font-size: 13px; color: #64748B; }

    .dif-btn { padding: 12px 18px; border-radius: 10px; font-size: 14px; font-weight: 600; border: none; cursor: pointer; display: inline-flex; align-items: center; justify-content: center; gap: 8px; }
    .dif-btn-primary { background: linear-gradient(135deg, #7E22CE, #EC4899); color: white; }
    .dif-btn-primary:disabled { opacity: 0.5; cursor: not-allowed; }
    .dif-btn-ghost { background: white; color: #475569; border: 1px solid #E2E8F0; text-decoration: none; }

    .dif-source-tabs { display: flex; gap: 8px; margin-bottom: 14px; }
    .dif-source-tabs button { flex: 1; padding: 10px; border: 1px solid #E2E8F0; background: white; border-radius: 8px; font-size: 13px; font-weight: 600; cursor: pointer; color: #64748B; }
    .dif-source-tabs button.active { background: #7E22CE15; border-color: #7E22CE; color: #7E22CE; }

    .dif-rec-section { padding: 14px; background: #F8FAFC; border-radius: 10px; margin-bottom: 12px; }
    .dif-rec-section h3 { margin: 0 0 8px; font-size: 13px; font-weight: 700; color: #334155; }

    .dif-history-item { padding: 12px; border-bottom: 1px solid #F1F5F9; font-size: 13px; }
    .dif-history-item:last-child { border-bottom: none; }
    .dif-history-item .subj { font-weight: 600; }
    .dif-history-item .meta { font-size: 11px; color: #94A3B8; margin-top: 2px; }
    .dif-pill { display: inline-block; padding: 2px 8px; border-radius: 999px; font-size: 10px; font-weight: 600; }
    .dif-pill.sent { background: #D1FAE5; color: #065F46; }
    .dif-pill.partial { background: #FEF3C7; color: #92400E; }
    .dif-pill.failed { background: #FEE2E2; color: #991B1B; }
    .dif-pill.pending { background: #DBEAFE; color: #1E40AF; }

    .dif-warning { background: #FEF3C7; border: 1px solid #FDE68A; border-radius: 10px; padding: 12px 14px; color: #92400E; font-size: 13px; margin-bottom: 14px; }
    .dif-success { background: #D1FAE5; border: 1px solid #A7F3D0; border-radius: 10px; padding: 12px 14px; color: #065F46; font-size: 13px; margin-bottom: 14px; }
  </style>

  <div class="dif-page">
    <div class="dif-breadcrumb"><a href="/mon-asso-ia">Communication IA</a> &nbsp;›&nbsp; <strong style="color:#0F172A;">Diffusion email</strong></div>

    <div class="dif-header">
      <div class="ico">📨</div>
      <div>
        <h1>Diffusion email</h1>
        <div class="sub">Envoyez vos contenus IA (ou n'importe quel texte) à vos adhérents, bénévoles ou contacts.</div>
      </div>
    </div>

    <?php if (isset($_GET['sent'])): ?>
      <div class="dif-success">✅ Diffusion envoyée avec succès. Voir l'historique en bas de page.</div>
    <?php endif; ?>

    <form method="post" action="/mon-asso-ia-diffusion-send" id="difForm">
      <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token'] ?? '') ?>">
      <div class="dif-grid">

        <!-- COLONNE GAUCHE : CONTENU + DESTINATAIRES -->
        <div>
          <div class="dif-card">
            <h2>📝 Contenu</h2>

            <div class="dif-source-tabs">
              <button type="button" class="active" onclick="switchSource('gen')">Depuis une génération IA</button>
              <button type="button" onclick="switchSource('manual')">Texte libre</button>
            </div>

            <div id="src-gen">
              <div class="dif-field">
                <label>Choisir une génération récente</label>
                <select name="generation_id" id="genSelect" onchange="loadGen(this.value)">
                  <option value="">— Sélectionner —</option>
                  <?php foreach ($recent_generations as $g):
                    $tdef = ak_ai_tool($g['tool_type']);
                  ?>
                    <option value="<?= (int)$g['id'] ?>" <?= ($gen_id == $g['id']) ? 'selected' : '' ?>>
                      <?= $tdef['icon'] ?> <?= h($g['title']) ?: h($tdef['label']) ?>
                      (<?= h(date('d/m', strtotime($g['created_at']))) ?>)
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>

            <div class="dif-field">
              <label>Objet de l'email *</label>
              <input type="text" name="subject" id="subject" required maxlength="255" value="<?= h($prefill_subject) ?>">
            </div>

            <div class="dif-field">
              <label>Corps de l'email (markdown supporté) *</label>
              <textarea name="body_md" id="body" rows="14" required placeholder="Le contenu sera rendu en HTML pour l'envoi. Vous pouvez modifier librement avant envoi."><?= h($prefill_body) ?></textarea>
              <span class="hint">Le markdown sera automatiquement converti en HTML. Les destinataires verront un email mis en forme.</span>
            </div>
          </div>

          <div class="dif-card">
            <h2>👥 Destinataires</h2>

            <?php if (!empty($roles_available)): ?>
            <div class="dif-rec-section">
              <h3>Par rôle</h3>
              <div class="dif-checks" id="rolesChecks">
                <?php foreach ($roles_available as $role): ?>
                  <label>
                    <input type="checkbox" name="roles[]" value="<?= h($role) ?>" onchange="updateCount()">
                    <span><?= h(ucfirst($role)) ?></span>
                  </label>
                <?php endforeach; ?>
              </div>
            </div>
            <?php endif; ?>

            <?php if (!empty($projects_available)): ?>
            <div class="dif-rec-section">
              <h3>Par projet (membres référents/équipe)</h3>
              <div class="dif-checks" id="projectsChecks">
                <?php foreach ($projects_available as $p): ?>
                  <label>
                    <input type="checkbox" name="project_ids[]" value="<?= (int)$p['id'] ?>" onchange="updateCount()">
                    <span><?= h(mb_strimwidth($p['name'], 0, 40, '…')) ?></span>
                  </label>
                <?php endforeach; ?>
              </div>
            </div>
            <?php endif; ?>

            <div class="dif-rec-section">
              <h3>Emails manuels (1 par ligne ou séparés par virgules)</h3>
              <div class="dif-field" style="margin:0;">
                <textarea name="manual_emails" id="manualEmails" rows="3" placeholder="contact@asso.fr&#10;benevole@example.com" oninput="debouncedCount()"></textarea>
              </div>
            </div>

            <div class="dif-counter">
              <div class="num" id="recipientCount">0</div>
              <div class="lbl">destinataire(s) sélectionné(s)</div>
            </div>

            <button type="submit" class="dif-btn dif-btn-primary" id="sendBtn" style="width:100%;" disabled>
              📨 Envoyer la diffusion
            </button>
          </div>
        </div>

        <!-- COLONNE DROITE : APERÇU + HISTORIQUE -->
        <div>
          <div class="dif-card">
            <h2>👁 Aperçu email</h2>
            <div id="emailPreview" style="background:#F8FAFC;border:1px solid #E2E8F0;border-radius:8px;padding:16px;font-size:13px;line-height:1.6;max-height:380px;overflow-y:auto;">
              <div style="color:#94A3B8;text-align:center;padding:30px 12px;">L'aperçu s'affichera ici dès que vous saisirez l'objet et le corps.</div>
            </div>
          </div>

          <?php if (!empty($recent_diffusions)): ?>
          <div class="dif-card">
            <h2>📋 Diffusions récentes</h2>
            <?php foreach ($recent_diffusions as $d):
              $st = $d['status']; $cls = $st;
              if ($st === 'sent') $cls = 'sent';
              elseif ($st === 'partial') $cls = 'partial';
              elseif ($st === 'failed') $cls = 'failed';
              else $cls = 'pending';
            ?>
              <div class="dif-history-item">
                <div class="subj"><?= h(mb_strimwidth($d['subject'], 0, 50, '…')) ?></div>
                <div class="meta">
                  <?= h(date('d/m/Y H:i', strtotime($d['created_at']))) ?>
                  · <?= (int)$d['sent_count'] ?>/<?= (int)$d['recipients_count'] ?> envoyés
                  <?php if ((int)$d['failed_count'] > 0): ?> · ⚠️ <?= (int)$d['failed_count'] ?> échec(s)<?php endif; ?>
                  <span class="dif-pill <?= h($cls) ?>" style="margin-left:6px;"><?= h($st) ?></span>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>

        </div>
      </div>
    </form>
  </div>

</main>

<script>
const generations = <?= json_encode(array_map(fn($g) => ['id' => (int)$g['id'], 'title' => $g['title'] ?? ''], $recent_generations), JSON_UNESCAPED_UNICODE) ?>;

function switchSource(s) {
  document.querySelectorAll('.dif-source-tabs button').forEach(b => b.classList.remove('active'));
  event.target.classList.add('active');
  document.getElementById('src-gen').style.display = (s === 'gen') ? '' : 'none';
}

async function loadGen(id) {
  if (!id) return;
  try {
    const r = await fetch('/mon-asso-ia-diffusion-loadgen?id=' + encodeURIComponent(id));
    const data = await r.json();
    if (data.ok) {
      document.getElementById('subject').value = data.title || '';
      document.getElementById('body').value = data.output || '';
      updatePreview();
    }
  } catch (e) {}
}

function mdToHtml(md) {
  let h = md
    .replace(/[&]/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
    .replace(/^### (.+)$/gm, '<h3>$1</h3>')
    .replace(/^## (.+)$/gm,  '<h2>$1</h2>')
    .replace(/^# (.+)$/gm,   '<h1>$1</h1>')
    .replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>')
    .replace(/\*(.+?)\*/g, '<em>$1</em>')
    .replace(/^- (.+)$/gm, '<li>$1</li>');
  h = h.replace(/(<li>.*?<\/li>(\n|$))+/gs, m => '<ul>' + m + '</ul>');
  return h.replace(/\n/g, '<br>');
}

function updatePreview() {
  const subj = document.getElementById('subject').value;
  const body = document.getElementById('body').value;
  const prev = document.getElementById('emailPreview');
  if (!subj && !body) {
    prev.innerHTML = '<div style="color:#94A3B8;text-align:center;padding:30px 12px;">L\'aperçu s\'affichera ici…</div>';
    return;
  }
  prev.innerHTML = '<div style="font-weight:700;font-size:14px;margin-bottom:10px;border-bottom:1px solid #E2E8F0;padding-bottom:8px;">Objet : ' +
    (subj.replace(/</g,'&lt;').replace(/>/g,'&gt;')) + '</div>' + mdToHtml(body);
}
document.getElementById('subject').addEventListener('input', updatePreview);
document.getElementById('body').addEventListener('input', updatePreview);
updatePreview();

let countTimer = null;
function debouncedCount() { clearTimeout(countTimer); countTimer = setTimeout(updateCount, 400); }

async function updateCount() {
  const fd = new FormData();
  document.querySelectorAll('input[name="roles[]"]:checked').forEach(c => fd.append('roles[]', c.value));
  document.querySelectorAll('input[name="project_ids[]"]:checked').forEach(c => fd.append('project_ids[]', c.value));
  fd.append('manual_emails', document.getElementById('manualEmails').value);
  try {
    const r = await fetch('/mon-asso-ia-recipients-count', { method: 'POST', body: fd });
    const d = await r.json();
    const n = d.ok ? d.count : 0;
    document.getElementById('recipientCount').textContent = n;
    document.getElementById('sendBtn').disabled = (n === 0);
  } catch (e) {
    document.getElementById('recipientCount').textContent = '?';
  }
}
updateCount();

document.getElementById('difForm').addEventListener('submit', (e) => {
  const n = parseInt(document.getElementById('recipientCount').textContent || '0', 10);
  if (n === 0) { e.preventDefault(); alert('Sélectionnez au moins un destinataire.'); return; }
  if (n > 200) { e.preventDefault(); alert('Maximum 200 destinataires par envoi (sécurité). Restreignez votre sélection.'); return; }
  if (!confirm('Envoyer la diffusion à ' + n + ' destinataire(s) ?')) { e.preventDefault(); return; }
  document.getElementById('sendBtn').disabled = true;
  document.getElementById('sendBtn').innerHTML = '⏳ Envoi en cours…';
});
</script>

<?php render_foot(); ?>
