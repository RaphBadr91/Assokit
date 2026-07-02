<?php
/**
 * ============================================================
 * ASSOKIT — Mon agenda (avec sync Google Calendar)
 * ============================================================
 * 2 modes selon l'état :
 *   A) Google connecté : interface Google (prioritaire)
 *   B) Pas de Google : URL .ics universelle (fallback)
 *
 * Si admin + Google non connecté : CTA de connexion
 * Si non-admin : message expliquant qui peut connecter
 * ============================================================
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes-layout.php';
require_once __DIR__ . '/google-helper.php';

require_login();
$current = current_user();

// Générer un token ICS si absent
if (empty($current['ics_token'])) {
    $new_token = sha1($current['id'] . '-' . $current['email'] . '-' . mt_rand() . '-' . time());
    $pdo->prepare("UPDATE users SET ics_token = ? WHERE id = ?")
        ->execute([$new_token, $current['id']]);
    $current['ics_token'] = $new_token;
}

$is_admin = ($current['role'] === 'admin');
$google_ready = is_google_enabled();
$google_connection = get_org_google_connection($current['org_id']);
$has_google = !empty($google_connection);

// Charger la liste des calendriers si connecté (pour le sélecteur)
$calendars = [];
if ($has_google) {
    $calendars = list_user_calendars($google_connection);
}

// URL ICS
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'assokit.fr';
$ics_url = $protocol . '://' . $host . '/agenda-ics?token=' . $current['ics_token'];
$webcal_url = 'webcal://' . $host . '/agenda-ics?token=' . $current['ics_token'];

render_head('Mon agenda');
render_sidebar('agenda');
?>

<main class="main">

  <nav class="crumbs">
    <a href="/dashboard">Dashboard</a>
    <span class="sep">›</span>
    <a href="/agenda">Agenda</a>
    <span class="sep">›</span>
    <span class="current">Synchroniser mon agenda</span>
  </nav>

  <div class="main-head">
    <div>
      <h1 class="page-title">Synchroniser mon agenda</h1>
      <div class="page-sub">Retrouvez tous vos événements Assokit dans Apple, Google ou Outlook</div>
    </div>
  </div>

  <?php
  // ==================== MESSAGES FLASH ====================
  if (isset($_GET['connected'])):
  ?>
    <div class="alert alert-success">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>
      ✅ Google Calendar est connecté ! Choisissez maintenant le calendrier à synchroniser ci-dessous.
    </div>
  <?php elseif (isset($_GET['calendar_selected'])): ?>
    <div class="alert alert-success">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>
      🎉 Calendrier sélectionné ! Vos événements Assokit ont été poussés vers Google Calendar.
    </div>
  <?php elseif (isset($_GET['synced'])):
    $imp = (int)($_GET['imported'] ?? 0);
    $upd = (int)($_GET['updated'] ?? 0);
    $del = (int)($_GET['deleted'] ?? 0);
  ?>
    <div class="alert alert-success">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>
      ✅ Synchronisation terminée. Importés : <?= $imp ?> · Mis à jour : <?= $upd ?> · Supprimés : <?= $del ?>
    </div>
  <?php elseif (isset($_GET['disconnected'])): ?>
    <div class="alert alert-success">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>
      Google Calendar a été déconnecté.
    </div>
  <?php elseif (isset($_GET['ics_regenerated'])): ?>
    <div class="alert alert-success">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>
      Nouveau lien ICS généré ! L'ancien ne fonctionne plus.
    </div>
  <?php elseif (isset($_GET['error'])):
    $err_labels = [
      'not_admin' => 'Seul un administrateur peut connecter Google Calendar.',
      'not_configured' => 'L\'OAuth Google n\'est pas configuré côté serveur.',
      'invalid_state' => 'Session OAuth invalide. Réessayez.',
      'no_code' => 'Code d\'autorisation manquant.',
      'no_refresh_token' => 'Pas de refresh_token reçu. Révoquez l\'accès sur Google puis retentez.',
      'no_email' => 'Impossible de récupérer votre email Google.',
      'csrf' => 'Session expirée, réessayez.',
      'access_denied' => 'Vous avez refusé l\'autorisation.',
      'not_connected' => 'Pas de connexion Google active.',
      'no_calendar' => 'Aucun calendrier sélectionné.',
      'invalid_calendar' => 'Calendrier invalide.',
      'sync_failed' => 'Synchronisation échouée.',
    ];
    $err_msg = $err_labels[$_GET['error']] ?? ('Erreur : ' . $_GET['error']);
  ?>
    <div class="alert alert-error">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/></svg>
      <?= h($err_msg) ?>
    </div>
  <?php endif; ?>

  <?php if ($has_google): ?>
    <!-- ================================================== -->
    <!-- GOOGLE CALENDAR CONNECTÉ                            -->
    <!-- ================================================== -->
    <div class="form-section">
      <h2 class="form-section-title">
        <span style="display: inline-flex; align-items: center; gap: 8px;">
          <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#4285F4" stroke-width="1.8"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
          Google Calendar connecté
        </span>
      </h2>
      <p class="form-section-desc">Votre organisation est synchronisée avec Google Calendar.</p>

      <div style="background: var(--bg-2); border: 1px solid var(--border); border-radius: 10px; padding: 14px 18px; margin-bottom: 16px;">
        <div style="display: flex; gap: 14px; align-items: center; flex-wrap: wrap;">
          <div style="width: 40px; height: 40px; border-radius: 50%; background: #4285F4; color: #fff; display: flex; align-items: center; justify-content: center; flex-shrink: 0; font-weight: 600; font-size: 16px;">
            G
          </div>
          <div style="flex: 1; min-width: 200px;">
            <div style="font-size: 14px; font-weight: 500;"><?= h($google_connection['google_email']) ?></div>
            <div style="font-size: 12.5px; color: var(--ink-3); margin-top: 2px;">
              Connecté par <?= h($google_connection['connected_by_user_id'] == $current['id'] ? 'vous' : 'un autre admin') ?>
              · <?= h(date('d/m/Y', strtotime($google_connection['created_at']))) ?>
            </div>
          </div>
          <?php if ($is_admin): ?>
          <form method="POST" action="/action-google" style="margin: 0;" onsubmit="return confirm('Déconnecter Google Calendar ? Les événements déjà synchronisés resteront dans Google.');">
            <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
            <input type="hidden" name="action" value="disconnect">
            <button type="submit" class="btn btn-ghost" style="color: #B91C1C; font-size: 12.5px;">Déconnecter</button>
          </form>
          <?php endif; ?>
        </div>
      </div>

      <!-- Sélecteur de calendrier -->
      <?php if ($is_admin): ?>
      <div style="margin-bottom: 16px;">
        <label class="form-label">Calendrier à synchroniser</label>
        <?php if (!empty($calendars)): ?>
        <form method="POST" action="/action-google" style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
          <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
          <input type="hidden" name="action" value="select_calendar">
          <select name="calendar_id" class="form-select-lg" style="flex: 1; min-width: 250px;" onchange="document.getElementById('calName').value = this.options[this.selectedIndex].dataset.name || ''">
            <?php foreach ($calendars as $cal):
              $is_current = ($cal['id'] === $google_connection['google_calendar_id']);
            ?>
              <option value="<?= h($cal['id']) ?>" data-name="<?= h($cal['summary'] ?? '') ?>" <?= $is_current ? 'selected' : '' ?>>
                <?= h($cal['summary'] ?? 'Sans nom') ?>
                <?php if (!empty($cal['primary'])): ?> (principal)<?php endif; ?>
                <?php if (($cal['accessRole'] ?? '') === 'owner'): ?> · propriétaire<?php endif; ?>
              </option>
            <?php endforeach; ?>
          </select>
          <input type="hidden" name="calendar_name" id="calName" value="<?= h($google_connection['google_calendar_name'] ?? '') ?>">
          <button type="submit" class="btn btn-primary">Enregistrer</button>
        </form>
        <div class="form-hint">💡 Conseil : créez un calendrier <strong>"Assokit"</strong> dédié dans Google Calendar et partagez-le avec votre équipe.</div>
        <?php else: ?>
          <div class="alert alert-warn">⚠️ Aucun calendrier récupéré. Déconnectez puis reconnectez-vous.</div>
        <?php endif; ?>
      </div>
      <?php endif; ?>

      <!-- Statut de sync -->
      <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 10px; margin-bottom: 16px;">
        <div style="background: var(--bg); border: 1px solid var(--border); border-radius: 10px; padding: 12px 14px;">
          <div style="font-size: 11px; color: var(--ink-3); text-transform: uppercase; letter-spacing: 0.04em;">Dernier push</div>
          <div style="font-size: 13px; margin-top: 4px;"><?= $google_connection['last_push_at'] ? h(date('d/m/Y H:i', strtotime($google_connection['last_push_at']))) : 'Jamais' ?></div>
        </div>
        <div style="background: var(--bg); border: 1px solid var(--border); border-radius: 10px; padding: 12px 14px;">
          <div style="font-size: 11px; color: var(--ink-3); text-transform: uppercase; letter-spacing: 0.04em;">Dernier pull</div>
          <div style="font-size: 13px; margin-top: 4px;"><?= $google_connection['last_pull_at'] ? h(date('d/m/Y H:i', strtotime($google_connection['last_pull_at']))) : 'Jamais' ?></div>
        </div>
        <div style="background: var(--bg); border: 1px solid var(--border); border-radius: 10px; padding: 12px 14px;">
          <div style="font-size: 11px; color: var(--ink-3); text-transform: uppercase; letter-spacing: 0.04em;">Sync</div>
          <div style="font-size: 13px; margin-top: 4px;"><?= $google_connection['sync_enabled'] ? '✅ Activée' : '⏸ En pause' ?></div>
        </div>
      </div>

      <!-- Sync manuelle -->
      <?php if ($is_admin): ?>
      <form method="POST" action="/action-google" style="margin: 0;">
        <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
        <input type="hidden" name="action" value="sync_now">
        <button type="submit" class="btn btn-primary">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M23 4v6h-6"/><path d="M1 20v-6h6"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>
          Synchroniser maintenant (Google → Assokit)
        </button>
      </form>
      <div class="form-hint" style="margin-top: 10px;">Récupère les événements créés/modifiés dans Google Calendar depuis la dernière sync. La sync Assokit → Google est automatique à chaque action.</div>
      <?php endif; ?>
    </div>

    <!-- URL ICS cachée derrière un lien discret -->
    <div style="margin-top: 20px; padding: 14px 18px; background: var(--bg-2); border-radius: 10px;">
      <details>
        <summary style="font-size: 12.5px; color: var(--ink-3); cursor: pointer;">Afficher quand même l'URL d'abonnement universelle (ICS, pour Apple Calendar ou Outlook)</summary>
        <div style="margin-top: 14px;">
          <p style="font-size: 12.5px; color: var(--ink-2); margin-bottom: 10px;">L'URL ICS reste disponible si vous voulez abonner d'autres agendas (Apple, Outlook) à Assokit en plus de Google.</p>
          <div style="background: var(--bg); border: 1px solid var(--border); border-radius: 10px; padding: 10px 14px; display: flex; gap: 10px; align-items: center;">
            <input type="text" id="icsUrl" value="<?= h($ics_url) ?>" readonly
                   style="flex: 1; background: transparent; border: none; font-family: 'SF Mono', Monaco, Consolas, monospace; font-size: 12px; color: var(--ink-2); outline: none; min-width: 0;"
                   onclick="this.select();">
            <button type="button" class="btn btn-ghost" style="padding: 6px 12px; font-size: 12px;" onclick="copyIcs()">Copier</button>
          </div>
        </div>
      </details>
    </div>

  <?php else: ?>
    <!-- ================================================== -->
    <!-- GOOGLE CALENDAR NON CONNECTÉ                        -->
    <!-- ================================================== -->

    <!-- CTA Google -->
    <?php if ($google_ready && $is_admin): ?>
    <div class="form-section" style="background: linear-gradient(135deg, rgba(66,133,244,0.08) 0%, var(--bg) 100%); border: 1px solid rgba(66,133,244,0.2);">
      <h2 class="form-section-title">
        <span style="display: inline-flex; align-items: center; gap: 8px;">
          <svg width="22" height="22" viewBox="0 0 24 24" fill="#4285F4" stroke="none"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-1 17.93c-3.95-.49-7-3.85-7-7.93 0-.62.08-1.21.21-1.79L9 15v1c0 1.1.9 2 2 2v1.93zm6.9-2.54c-.26-.81-1-1.39-1.9-1.39h-1v-3c0-.55-.45-1-1-1H8v-2h2c.55 0 1-.45 1-1V7h2c1.1 0 2-.9 2-2v-.41c2.93 1.19 5 4.06 5 7.41 0 2.08-.8 3.97-2.1 5.39z"/></svg>
          Synchronisation bidirectionnelle avec Google Calendar
        </span>
      </h2>
      <p class="form-section-desc">Connectez le Google Calendar de votre organisation pour une synchronisation automatique dans les deux sens. Chaque événement créé dans Assokit apparaît immédiatement dans Google, et vice versa.</p>
      <a href="/google-connect" class="btn btn-primary" style="background: #4285F4;">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/></svg>
        Connecter Google Calendar
      </a>
      <div class="form-hint" style="margin-top: 10px;">ℹ️ Vous connectez votre propre compte Google. Vous pourrez ensuite choisir <strong>quel calendrier</strong> synchroniser avec Assokit, et éventuellement le partager avec votre équipe depuis Google Calendar.</div>
    </div>
    <?php elseif ($google_ready && !$is_admin): ?>
    <div class="alert alert-info">
      ℹ️ Seul un administrateur de votre organisation peut connecter Google Calendar. Contactez-le si vous souhaitez activer cette fonctionnalité.
    </div>
    <?php endif; ?>

    <!-- URL ICS (principale quand pas de Google) -->
    <div class="ai-hero" style="background: linear-gradient(135deg, var(--acc-light) 0%, var(--bg) 100%); border: 1px solid var(--border); margin-bottom: 24px;">
      <div class="ai-hero-icon" style="background: var(--acc);">
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
      </div>
      <div class="ai-hero-body">
        <h2 class="ai-hero-title" style="color: var(--ink);">Abonnement universel (Apple, Google, Outlook)</h2>
        <p class="ai-hero-desc">Ajoutez cette URL dans votre agenda une seule fois. Il se synchronisera automatiquement avec Assokit toutes les heures.</p>
      </div>
    </div>

    <div class="form-section">
      <h2 class="form-section-title">🔗 Votre URL d'abonnement personnelle</h2>
      <p class="form-section-desc">Cette URL est unique et privée. Ne la partagez pas.</p>

      <div style="background: var(--bg-2); border: 1px solid var(--border); border-radius: 10px; padding: 12px 14px; display: flex; gap: 10px; align-items: center; margin-bottom: 16px;">
        <input type="text" id="icsUrl" value="<?= h($ics_url) ?>" readonly
               style="flex: 1; background: transparent; border: none; font-family: 'SF Mono', Monaco, Consolas, monospace; font-size: 12.5px; color: var(--ink); outline: none; min-width: 0;"
               onclick="this.select();">
        <button type="button" class="btn btn-primary" style="padding: 8px 14px;" onclick="copyIcs()">Copier</button>
      </div>

      <div style="display: flex; gap: 10px; flex-wrap: wrap;">
        <a href="<?= h($webcal_url) ?>" class="btn btn-ghost">🍎 Ouvrir dans Apple Calendar</a>
        <a href="https://calendar.google.com/calendar/r/settings/addbyurl" target="_blank" class="btn btn-ghost">📅 Ouvrir Google Calendar</a>
      </div>
    </div>

    <!-- Instructions rapides -->
    <div class="form-section">
      <h2 class="form-section-title">📋 Instructions rapides</h2>
      <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 14px;">
        <div>
          <h3 style="font-size: 13px; font-weight: 500; margin-bottom: 6px;">🍎 Apple Calendar (Mac/iPhone)</h3>
          <ol style="font-size: 12.5px; line-height: 1.6; color: var(--ink-2); padding-left: 18px; margin: 0;">
            <li>Réglages → Calendrier → Comptes → Ajouter → Calendrier avec abonnement</li>
            <li>Collez l'URL ci-dessus</li>
          </ol>
        </div>
        <div>
          <h3 style="font-size: 13px; font-weight: 500; margin-bottom: 6px;">📅 Google Calendar</h3>
          <ol style="font-size: 12.5px; line-height: 1.6; color: var(--ink-2); padding-left: 18px; margin: 0;">
            <li>Autres agendas → + → À partir de l'URL</li>
            <li>Collez l'URL ci-dessus</li>
          </ol>
        </div>
        <div>
          <h3 style="font-size: 13px; font-weight: 500; margin-bottom: 6px;">📧 Outlook</h3>
          <ol style="font-size: 12.5px; line-height: 1.6; color: var(--ink-2); padding-left: 18px; margin: 0;">
            <li>Calendrier → Ajouter un calendrier → À partir d'Internet</li>
            <li>Collez l'URL ci-dessus</li>
          </ol>
        </div>
      </div>
    </div>

    <!-- Régénération ICS -->
    <div class="form-section">
      <h2 class="form-section-title">🔒 Sécurité</h2>
      <p class="form-section-desc">Si l'URL a été compromise, vous pouvez la régénérer. L'ancienne sera immédiatement désactivée.</p>
      <form method="POST" action="/action-google" onsubmit="return confirm('Régénérer le lien ? Tous les agendas abonnés devront être reconfigurés.');">
        <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
        <input type="hidden" name="action" value="regenerate">
        <button type="submit" class="btn btn-ghost" style="color: #B91C1C;">Régénérer mon URL ICS</button>
      </form>
    </div>

  <?php endif; ?>

</main>

<script>
function copyIcs() {
  var input = document.getElementById('icsUrl');
  input.select();
  input.setSelectionRange(0, 99999);
  try {
    document.execCommand('copy');
    alert('URL copiée ! Collez-la dans votre agenda.');
  } catch (e) {
    alert('Sélectionnez l\'URL et copiez-la avec Cmd+C / Ctrl+C.');
  }
}
</script>

<?php render_foot(); ?>
