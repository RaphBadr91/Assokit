<?php
/**
 * ============================================================
 * ASSOKIT — Page Agenda
 * ============================================================
 * Affiche les événements de l'organisation avec :
 *   - Vue mensuelle (grille calendrier)
 *   - Vue liste chronologique
 *   - Navigation mois précédent/suivant
 *   - Bouton "Nouvel événement"
 * ============================================================
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes-layout.php';

require_login();

$current = current_user();
$org_id = (int)$current['org_id'];

// Paramètres de vue
$view = $_GET['view'] ?? 'month';
if (!in_array($view, ['month', 'list'], true)) $view = 'month';

$ym = $_GET['ym'] ?? date('Y-m');
if (!preg_match('/^\d{4}-\d{2}$/', $ym)) $ym = date('Y-m');
list($year, $month) = array_map('intval', explode('-', $ym));

// Calcul des dates du mois affiché
$first_day_of_month = mktime(0, 0, 0, $month, 1, $year);
$last_day_of_month = mktime(23, 59, 59, $month, date('t', $first_day_of_month), $year);

// Pour la vue calendrier : début = premier lundi avant le 1er du mois
$weekday_of_first = (int)date('N', $first_day_of_month); // 1 = lundi, 7 = dimanche
$grid_start = strtotime(date('Y-m-d', $first_day_of_month) . ' -' . ($weekday_of_first - 1) . ' days');
$grid_end = strtotime(date('Y-m-d', $grid_start) . ' +41 days'); // 6 semaines × 7 jours

// Pour la vue liste : on prend une fenêtre plus large (ce mois + suivant + précédent)
$list_start = strtotime(date('Y-m-01', $first_day_of_month) . ' -1 month');
$list_end = strtotime(date('Y-m-t', $first_day_of_month) . ' +2 months');

// Chargement des événements
$range_start = $view === 'month' ? date('Y-m-d', $grid_start) : date('Y-m-d', $list_start);
$range_end = $view === 'month' ? date('Y-m-d', $grid_end) : date('Y-m-d', $list_end);

// Filtrage follower : seulement les événements liés à ses projets
$follower_pids = get_follower_project_ids();
$events_sql = "
    SELECT e.*,
           p.name AS project_name, f.color_theme AS folder_color,
           u.first_name AS author_first, u.last_name AS author_last
    FROM events e
    LEFT JOIN projects p ON e.project_id = p.id
    LEFT JOIN folders f ON p.folder_id = f.id
    LEFT JOIN users u ON e.created_by = u.id
    WHERE e.org_id = ?
      AND e.starts_at <= ?
      AND e.ends_at >= ?
";
$events_params = [$org_id, $range_end . ' 23:59:59', $range_start . ' 00:00:00'];

if ($follower_pids !== null) {
    if (empty($follower_pids)) {
        $events_sql .= " AND 1 = 0";
    } else {
        $placeholders = implode(',', array_fill(0, count($follower_pids), '?'));
        $events_sql .= " AND e.project_id IN ($placeholders)";
        $events_params = array_merge($events_params, $follower_pids);
    }
}

$events_sql .= " ORDER BY e.starts_at ASC";
$stmt = $pdo->prepare($events_sql);
$stmt->execute($events_params);
$events = $stmt->fetchAll();

// Couleur effective d'un événement en HEX (ou '' pour utiliser une classe ev-*).
// - color_theme = hex (#xxxxxx) : couleur Google explicite -> utilisée telle quelle
// - événement importé de Google sans couleur : couleur stable dérivée du titre
//   (comme un vrai agenda coloré : chaque type d'événement a sa couleur constante)
function event_hex($evt) {
    $c = (string)($evt['color_theme'] ?? '');
    if ($c !== '' && $c[0] === '#') return $c;
    if (($evt['sync_origin'] ?? '') === 'google') {
        $palette = ['#7986CB', '#33B679', '#8E24AA', '#E67C73', '#F6BF26', '#039BE5', '#3F51B5', '#0B8043', '#D50000', '#F4511E'];
        $base = function_exists('mb_strtolower') ? mb_strtolower(trim((string)($evt['title'] ?? ''))) : strtolower(trim((string)($evt['title'] ?? '')));
        return $base === '' ? $palette[0] : $palette[abs(crc32($base)) % count($palette)];
    }
    return '';
}

// Classe de couleur (utilisée seulement pour les événements internes, sans hex)
function event_color_class($evt) {
    if (event_hex($evt) !== '') return ''; // couleur gérée en inline
    $c = (string)($evt['color_theme'] ?? '');
    if ($c !== '') return 'ev-' . $c;
    if (!empty($evt['folder_color'])) return 'ev-' . $evt['folder_color'];
    $type_colors = [
        'meeting' => 'ev-blue',
        'workshop' => 'ev-purple',
        'public' => 'ev-green',
        'internal' => 'ev-teal',
        'deadline' => 'ev-red',
        'other' => 'ev-amber',
    ];
    return $type_colors[$evt['event_type']] ?? 'ev-blue';
}

// Pastille pleine + texte blanc pour un hex
function event_color_style($evt) {
    $hex = event_hex($evt);
    return $hex !== '' ? 'background:' . htmlspecialchars($hex) . ';color:#fff;' : '';
}
// Barre latérale (vue liste) pour un hex
function event_bar_style($evt) {
    $hex = event_hex($evt);
    return $hex !== '' ? 'background:' . htmlspecialchars($hex) . ';' : '';
}

// Groupe les événements par date (pour la vue calendrier)
$events_by_day = [];
foreach ($events as $e) {
    $start = strtotime($e['starts_at']);
    $end = strtotime($e['ends_at']);
    // Événement qui s'étend sur plusieurs jours : on l'affiche sur chaque jour
    for ($d = $start; $d <= $end; $d = strtotime('+1 day', $d)) {
        $key = date('Y-m-d', $d);
        $events_by_day[$key][] = $e;
    }
}

// Pour la vue liste : groupe par date
$events_by_day_list = [];
foreach ($events as $e) {
    $key = date('Y-m-d', strtotime($e['starts_at']));
    $events_by_day_list[$key][] = $e;
}
ksort($events_by_day_list);

// Helpers format dates FR
function month_name_fr($m) {
    $names = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
    return $names[$m - 1] ?? '';
}
function day_name_fr($ts) {
    $days = ['Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi', 'Dimanche'];
    return $days[(int)date('N', $ts) - 1];
}
function day_name_short_fr($ts) {
    $days = ['Lun.', 'Mar.', 'Mer.', 'Jeu.', 'Ven.', 'Sam.', 'Dim.'];
    return $days[(int)date('N', $ts) - 1];
}
function format_hhmm($datetime_str) {
    return date('H:i', strtotime($datetime_str));
}

// Navigation mois
$prev_ym = date('Y-m', strtotime($ym . '-01 -1 month'));
$next_ym = date('Y-m', strtotime($ym . '-01 +1 month'));
$today_ym = date('Y-m');

render_head('Agenda');
render_sidebar('agenda');
?>

<style>
/* ============================================================
   AGENDA 2.0 — surcouche premium Liquid Glass (maquette)
   ============================================================ */
.main{max-width:1260px}
.main .page-title{font-size:32px;font-weight:800;letter-spacing:-.03em;line-height:1;color:var(--ink)}
.main .page-sub{font-size:13.5px;color:var(--ink-2);margin-top:10px}
.cal-toolbar{margin-bottom:16px}
.cal-nav{gap:14px}
.cal-nav-btn{width:40px;height:40px;border-radius:12px;border:1px solid var(--glass-border);background:var(--glass);backdrop-filter:blur(10px);-webkit-backdrop-filter:blur(10px);box-shadow:var(--shadow-card);display:inline-flex;align-items:center;justify-content:center;color:var(--ink)}
.cal-month-title{font-size:19px;font-weight:750;letter-spacing:-.02em;color:var(--ink)}
.cal-today-btn{border-radius:11px}
.cal-view-switch{background:var(--glass);border:1px solid var(--glass-border);border-radius:12px;padding:3px;backdrop-filter:blur(10px);-webkit-backdrop-filter:blur(10px);box-shadow:var(--shadow-card)}
.cal-view-btn{border-radius:9px;font-weight:600;color:var(--ink-3)}
.cal-view-btn.active{background:var(--bg);color:var(--ink);box-shadow:var(--shadow-card)}
/* grille en verre */
.cal-grid{background:var(--glass);backdrop-filter:blur(22px) saturate(1.5);-webkit-backdrop-filter:blur(22px) saturate(1.5);border:1px solid var(--glass-border);border-radius:var(--radius-lg,18px);box-shadow:var(--shadow-card);overflow:hidden}
.cal-weekday{font-size:11px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:var(--ink-3)}
.cal-event-pill{border-radius:7px;font-weight:600}
.cal-day.today .cal-day-num{background:var(--acc);color:#fff;border-radius:50%}
.cal-day-more{font-size:11px;color:var(--ink-3);font-weight:600}
/* liste en verre */
.cal-list{background:var(--glass);backdrop-filter:blur(22px) saturate(1.5);-webkit-backdrop-filter:blur(22px) saturate(1.5);border:1px solid var(--glass-border);border-radius:var(--radius-lg,18px);box-shadow:var(--shadow-card);overflow:hidden}
</style>

<main class="main">

  <nav class="crumbs">
    <a href="/dashboard">Dashboard</a>
    <span class="sep">›</span>
    <span class="current">Agenda</span>
  </nav>

  <div class="main-head">
    <div>
      <h1 class="page-title">Votre agenda</h1>
      <div class="page-sub"><?= count($events) ?> événement<?= count($events) > 1 ? 's' : '' ?> affiché<?= count($events) > 1 ? 's' : '' ?></div>
    </div>
    <div class="head-actions">
      <a href="/mon-agenda" class="btn btn-ghost" title="Synchroniser avec Apple, Google ou Outlook">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
        Synchroniser
      </a>
      <a href="/nouveau-evenement" class="btn btn-primary">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
        Nouvel événement
      </a>
    </div>
  </div>

  <!-- Toolbar : navigation + vue -->
  <div class="cal-toolbar">
    <div class="cal-nav">
      <a href="?view=<?= $view ?>&ym=<?= $prev_ym ?>" class="cal-nav-btn" title="Mois précédent">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="15 18 9 12 15 6"/></svg>
      </a>
      <div class="cal-month-title"><?= h(month_name_fr($month)) ?> <?= $year ?></div>
      <a href="?view=<?= $view ?>&ym=<?= $next_ym ?>" class="cal-nav-btn" title="Mois suivant">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 18 15 12 9 6"/></svg>
      </a>
      <?php if ($ym !== $today_ym): ?>
        <a href="?view=<?= $view ?>" class="cal-today-btn">Aujourd'hui</a>
      <?php endif; ?>
    </div>

    <div class="cal-view-switch">
      <a href="?view=month&ym=<?= $ym ?>" class="cal-view-btn <?= $view === 'month' ? 'active' : '' ?>">Mois</a>
      <a href="?view=list&ym=<?= $ym ?>" class="cal-view-btn <?= $view === 'list' ? 'active' : '' ?>">Liste</a>
    </div>
  </div>

  <?php if ($view === 'month'): ?>
    <!-- ===== VUE MENSUELLE ===== -->
    <div class="cal-grid">
      <div class="cal-weekdays">
        <?php foreach (['Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi', 'Dimanche'] as $wd): ?>
          <div class="cal-weekday"><?= h($wd) ?></div>
        <?php endforeach; ?>
      </div>
      <div class="cal-days">
        <?php
        $day_cursor = $grid_start;
        $today_str = date('Y-m-d');
        for ($i = 0; $i < 42; $i++):
          $day_str = date('Y-m-d', $day_cursor);
          $day_month = (int)date('n', $day_cursor);
          $is_other_month = ($day_month !== $month);
          $is_today = ($day_str === $today_str);
          $day_events = $events_by_day[$day_str] ?? [];
        ?>
        <div class="cal-day <?= $is_other_month ? 'other-month' : '' ?> <?= $is_today ? 'today' : '' ?>">
          <span class="cal-day-num"><?= (int)date('j', $day_cursor) ?></span>
          <div class="cal-day-events">
            <?php foreach (array_slice($day_events, 0, 3) as $e):
              $color_class = event_color_class($e);
              $color_style = event_color_style($e);
              $time = $e['is_all_day'] ? '' : format_hhmm($e['starts_at']) . ' ';
            ?>
              <a href="/evenement/<?= (int)$e['id'] ?>" class="cal-event-pill <?= $color_class ?>" style="<?= $color_style ?>" title="<?= h($e['title']) ?>">
                <span class="cal-event-time"><?= h($time) ?></span><?= h(mb_substr($e['title'], 0, 30)) ?>
              </a>
            <?php endforeach; ?>
            <?php if (count($day_events) > 3): ?>
              <div class="cal-day-more">+ <?= count($day_events) - 3 ?> autre<?= count($day_events) - 3 > 1 ? 's' : '' ?></div>
            <?php endif; ?>
          </div>
        </div>
        <?php
          $day_cursor = strtotime('+1 day', $day_cursor);
        endfor;
        ?>
      </div>
    </div>

  <?php else: ?>
    <!-- ===== VUE LISTE ===== -->
    <?php if (empty($events_by_day_list)): ?>
      <div class="panel"><div class="empty-state">Aucun événement sur cette période.</div></div>
    <?php else: ?>
      <div class="cal-list">
        <?php foreach ($events_by_day_list as $day_str => $day_events):
          $ts = strtotime($day_str);
          $is_today = ($day_str === date('Y-m-d'));
          $is_past = ($ts < strtotime(date('Y-m-d')));
        ?>
        <div class="cal-list-day <?= $is_today ? 'today' : '' ?> <?= $is_past ? 'past' : '' ?>">
          <div class="cal-list-day-head">
            <div class="cal-list-day-date"><?= (int)date('j', $ts) ?></div>
            <div class="cal-list-day-info">
              <div class="cal-list-day-name"><?= h(day_name_fr($ts)) ?><?= $is_today ? ' · Aujourd\'hui' : '' ?></div>
              <div class="cal-list-day-month"><?= h(month_name_fr((int)date('n', $ts))) ?> <?= date('Y', $ts) ?></div>
            </div>
            <div class="cal-list-day-badge"><?= count($day_events) ?> événement<?= count($day_events) > 1 ? 's' : '' ?></div>
          </div>
          <div class="cal-list-events">
            <?php foreach ($day_events as $e):
              $color_class = event_color_class($e);
              $bar_style = event_bar_style($e);
              $start_time = $e['is_all_day'] ? 'Journée' : format_hhmm($e['starts_at']);
              $end_time = $e['is_all_day'] ? '' : format_hhmm($e['ends_at']);
            ?>
            <a href="/evenement/<?= (int)$e['id'] ?>" class="cal-list-event">
              <div class="cal-list-event-time">
                <?= h($start_time) ?>
                <?php if ($end_time && $end_time !== $start_time): ?>
                  <small>→ <?= h($end_time) ?></small>
                <?php endif; ?>
              </div>
              <div class="cal-list-event-bar <?= $color_class ?>" style="<?= $bar_style ?>"></div>
              <div class="cal-list-event-body">
                <div class="cal-list-event-title"><?= h($e['title']) ?></div>
                <div class="cal-list-event-meta">
                  <?php if ($e['location']): ?>
                    <span><?= h($e['location']) ?></span>
                  <?php endif; ?>
                  <?php if ($e['project_name']): ?>
                    <?php if ($e['location']): ?><span class="dot">·</span><?php endif; ?>
                    <span style="display:inline-flex; align-items:center; gap:4px;"><?= ak_icon('folder',13) ?> <?= h($e['project_name']) ?></span>
                  <?php endif; ?>
                </div>
              </div>
              <svg class="cal-list-event-arrow" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><polyline points="9 18 15 12 9 6"/></svg>
            </a>
            <?php endforeach; ?>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  <?php endif; ?>

  <!-- Bandeau sync (Phase 2 active) -->
  <div style="margin-top: 32px; padding: 20px 24px; background: var(--acc-light); border: 1px solid rgba(5, 150, 105, 0.2); border-radius: 12px;">
    <div style="display: flex; gap: 14px; align-items: center; flex-wrap: wrap;">
      <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="var(--acc-dark)" stroke-width="1.8" style="flex-shrink: 0;"><path d="M23 4v6h-6"/><path d="M1 20v-6h6"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>
      <div style="flex: 1; min-width: 200px;">
        <div style="font-size: 14px; font-weight: 500; margin-bottom: 2px; color: var(--acc-dark);">Retrouvez votre agenda sur iPhone, Android, Mac…</div>
        <div style="font-size: 12.5px; color: var(--ink-2); line-height: 1.5;">
          Synchronisez Assokit avec Apple Calendar, Google Calendar ou Outlook en 2 minutes. Tous vos événements apparaissent automatiquement sur votre téléphone.
        </div>
      </div>
      <a href="/mon-agenda" class="btn btn-primary" style="white-space: nowrap;">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/></svg>
        Synchroniser mon agenda
      </a>
    </div>
  </div>

</main>

<?php render_foot(); ?>
