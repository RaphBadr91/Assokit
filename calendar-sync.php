<?php
/**
 * /calendar-sync — Génère/copie/révoque l'URL d'abonnement iCal personnel
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes-layout.php';
require_login();
$user = current_user();

// Action create / revoke
$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && check_csrf($_POST['csrf_token'] ?? '')) {
    $action = $_POST['action'] ?? '';
    if ($action === 'create') {
        $token = bin2hex(random_bytes(20));
        $label = trim($_POST['label'] ?? '') ?: null;
        $stmt = $pdo->prepare("INSERT INTO user_calendar_tokens (user_id, token, label) VALUES (?, ?, ?)");
        $stmt->execute([(int)$user['id'], $token, $label]);
        $msg = 'Lien créé.';
    }
    if ($action === 'revoke') {
        $tid = (int)($_POST['id'] ?? 0);
        $pdo->prepare("UPDATE user_calendar_tokens SET revoked_at = NOW() WHERE id = ? AND user_id = ?")
            ->execute([$tid, (int)$user['id']]);
        $msg = 'Lien révoqué.';
    }
}

// Tokens actifs
$stmt = $pdo->prepare("SELECT * FROM user_calendar_tokens WHERE user_id = ? AND revoked_at IS NULL ORDER BY created_at DESC");
$stmt->execute([(int)$user['id']]);
$tokens = $stmt->fetchAll();

$host = $_SERVER['HTTP_HOST'] ?? 'assokit.fr';
$proto = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';

render_head('Synchronisation calendrier');
?>
<?= render_sidebar('agenda') ?>
<main class="main">
  <div class="cs-page">
    <a href="/agenda" class="cs-back">← Agenda</a>
    <h1 class="cs-title">📅 Synchroniser avec Google / Apple / Outlook</h1>
    <p class="cs-sub">Génère un lien d'abonnement personnel. Tes événements AssoKit s'afficheront dans ton calendrier favori, mis à jour automatiquement.</p>

    <?php if ($msg): ?><div class="cs-flash">✅ <?= h($msg) ?></div><?php endif; ?>

    <?php if (empty($tokens)): ?>
    <form method="POST" class="cs-create">
      <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
      <input type="hidden" name="action" value="create">
      <label>Nom du calendrier (optionnel)</label>
      <input type="text" name="label" maxlength="80" placeholder="AssoKit — Mon calendrier">
      <button type="submit" class="cs-btn-primary">🔗 Générer mon lien d'abonnement</button>
    </form>
    <?php else: ?>
    <?php foreach ($tokens as $t):
      $url = $proto . '://' . $host . '/ical/' . $t['token'] . '.ics';
      $webcal = preg_replace('/^https?:\/\//', 'webcal://', $url);
      // URLs de souscription rapide
      $google_add = 'https://calendar.google.com/calendar/r/settings/addbyurl?cid=' . urlencode($url);
      $outlook_add = 'https://outlook.live.com/calendar/0/addfromweb?url=' . urlencode($url);
    ?>
    <div class="cs-card">
      <div class="cs-card-head">
        <div>
          <div class="cs-card-name"><?= h($t['label'] ?: 'Mon calendrier AssoKit') ?></div>
          <div class="cs-card-meta">
            Créé le <?= date('d/m/Y', strtotime($t['created_at'])) ?>
            <?php if ($t['fetch_count'] > 0): ?> · <?= (int)$t['fetch_count'] ?> sync<?= $t['fetch_count'] > 1 ? 's' : '' ?><?php endif; ?>
            <?php if ($t['last_used_at']): ?> · Dernière : <?= date('d/m H:i', strtotime($t['last_used_at'])) ?><?php endif; ?>
          </div>
        </div>
        <form method="POST" onsubmit="return confirm('Révoquer ce lien ? Le calendrier ne se mettra plus à jour.')">
          <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
          <input type="hidden" name="action" value="revoke">
          <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
          <button type="submit" class="cs-btn-revoke">🗑️</button>
        </form>
      </div>

      <div class="cs-url-row">
        <input type="text" id="cs-url-<?= (int)$t['id'] ?>" readonly value="<?= h($url) ?>">
        <button type="button" class="cs-copy" onclick="(function(){const i=document.getElementById('cs-url-<?= (int)$t['id'] ?>');i.select();document.execCommand('copy');navigator.clipboard&&navigator.clipboard.writeText(i.value);this.textContent='✓';setTimeout(()=>this.textContent='📋',1500);}).call(this)">📋</button>
      </div>

      <div class="cs-providers">
        <a href="<?= h($google_add) ?>" target="_blank" rel="noopener" class="cs-prov cs-prov-google">
          <span class="cs-prov-icon">📅</span><span>Ajouter à Google Calendar</span>
        </a>
        <a href="<?= h($webcal) ?>" class="cs-prov cs-prov-apple">
          <span class="cs-prov-icon">🍎</span><span>Ajouter à Apple Calendar</span>
        </a>
        <a href="<?= h($outlook_add) ?>" target="_blank" rel="noopener" class="cs-prov cs-prov-outlook">
          <span class="cs-prov-icon">📧</span><span>Ajouter à Outlook</span>
        </a>
      </div>

      <details class="cs-help-d">
        <summary>📖 Comment ça marche / instructions manuelles</summary>
        <div class="cs-help-body">
          <p><strong>Google Calendar (web)</strong> : Settings → "Add calendar" → "From URL" → coller le lien.</p>
          <p><strong>Apple Calendar (Mac)</strong> : File → New Calendar Subscription → coller le lien (commence par <code>webcal://</code>).</p>
          <p><strong>iPhone / iPad</strong> : Réglages → Calendrier → Comptes → Ajouter un compte → Autre → Ajouter un cal. avec abonnement.</p>
          <p><strong>Outlook</strong> : Calendar → Add calendar → "Subscribe from web" → coller le lien.</p>
          <p>Le calendrier se met à jour automatiquement (5 à 60 min selon le provider). Si tu révoques le lien, il ne se mettra plus à jour.</p>
        </div>
      </details>
    </div>
    <?php endforeach; ?>

    <form method="POST" class="cs-create-mini">
      <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
      <input type="hidden" name="action" value="create">
      <input type="text" name="label" maxlength="80" placeholder="Créer un autre lien (ex : pour mon iPhone)">
      <button type="submit" class="cs-btn-ghost">+ Nouveau lien</button>
    </form>
    <?php endif; ?>
  </div>
</main>
<style>
.cs-page { max-width: 760px; margin: 0 auto; padding: 24px 22px; }
.cs-back { color: #6b7280; text-decoration: none; font-size: 13px; display: inline-block; margin-bottom: 12px; }
.cs-back:hover { color: #10B981; }
.cs-title { font-size: 24px; margin: 0 0 6px; color: #111827; }
.cs-sub { color: #6b7280; margin: 0 0 22px; font-size: 14px; line-height: 1.55; }
.cs-flash { background: #ECFDF5; color: #065F46; border: 1px solid #A7F3D0; padding: 10px 14px; border-radius: 8px; margin-bottom: 14px; font-size: 13px; }
.cs-create, .cs-create-mini { background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; padding: 18px 20px; margin-bottom: 14px; }
.cs-create label { display: block; font-size: 12px; font-weight: 600; color: #374151; margin-bottom: 6px; text-transform: uppercase; letter-spacing: 0.04em; }
.cs-create input, .cs-create-mini input { width: 100%; padding: 9px 12px; border: 1px solid #e5e7eb; border-radius: 8px; font-size: 14px; font-family: inherit; box-sizing: border-box; margin-bottom: 12px; }
.cs-create-mini { display: flex; gap: 8px; align-items: center; }
.cs-create-mini input { margin-bottom: 0; }
.cs-btn-primary { padding: 10px 18px; background: #10B981; color: #fff; border: 0; border-radius: 8px; font-size: 13px; font-weight: 600; cursor: pointer; font-family: inherit; }
.cs-btn-primary:hover { background: #059669; }
.cs-btn-ghost { padding: 9px 14px; background: #fff; border: 1px solid #e5e7eb; color: #4b5563; border-radius: 8px; font-size: 13px; cursor: pointer; font-family: inherit; white-space: nowrap; }
.cs-btn-ghost:hover { background: #f9fafb; }
.cs-btn-revoke { padding: 6px 10px; background: transparent; border: 1px solid #e5e7eb; border-radius: 7px; cursor: pointer; font-size: 14px; color: #DC2626; }
.cs-btn-revoke:hover { background: #FEF2F2; border-color: #DC2626; }
.cs-card { background: #fff; border: 1px solid #e5e7eb; border-radius: 14px; padding: 18px 22px; margin-bottom: 14px; }
.cs-card-head { display: flex; justify-content: space-between; align-items: flex-start; gap: 14px; margin-bottom: 14px; }
.cs-card-name { font-size: 15px; font-weight: 700; color: #111827; }
.cs-card-meta { font-size: 12px; color: #6b7280; margin-top: 3px; }
.cs-url-row { display: flex; gap: 6px; margin-bottom: 14px; }
.cs-url-row input { flex: 1; padding: 8px 12px; background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 7px; font-family: ui-monospace, monospace; font-size: 11.5px; color: #4b5563; }
.cs-copy { padding: 8px 12px; background: #6366F1; color: #fff; border: 0; border-radius: 7px; cursor: pointer; }
.cs-copy:hover { background: #4F46E5; }
.cs-providers { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 8px; }
.cs-prov { display: flex; align-items: center; gap: 8px; padding: 10px 14px; background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 8px; text-decoration: none; color: #111827; font-size: 13px; font-weight: 500; transition: all 0.15s; }
.cs-prov:hover { background: #fff; border-color: #10B981; transform: translateY(-1px); box-shadow: 0 4px 10px rgba(0,0,0,0.05); }
.cs-prov-icon { font-size: 16px; }
.cs-help-d { margin-top: 14px; padding-top: 14px; border-top: 1px solid #f3f4f6; }
.cs-help-d summary { cursor: pointer; font-size: 12.5px; color: #6b7280; font-weight: 500; }
.cs-help-d[open] summary { margin-bottom: 10px; color: #111827; }
.cs-help-body p { font-size: 12.5px; color: #4b5563; line-height: 1.6; margin: 6px 0; }
.cs-help-body code { background: #f3f4f6; padding: 1px 5px; border-radius: 4px; font-size: 11.5px; }
@media (max-width: 540px) { .cs-create-mini { flex-direction: column; } .cs-create-mini input, .cs-create-mini button { width: 100%; } }
</style>
<?= render_foot() ?>
