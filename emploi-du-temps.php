<?php
/**
 * ============================================================
 * ASSOKIT — Emploi du temps des membres
 * ============================================================
 * Calendrier hebdo + mois pour gérer planning + vacances
 * Visible par TOUS les membres de l'asso (création/modif réservée selon rôle)
 * ============================================================
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes-layout.php';

require_login();
$user = current_user();
$org_id = (int)$user['org_id'];
$user_id = (int)$user['id'];
$role = $user['role'] ?? '';
$is_admin = in_array($role, ['admin', 'founder', 'super_admin'], true) || !empty($user['is_founder']) || !empty($user['is_super_admin']);

// Jeton CSRF pour les formulaires planning/absence (verifie cote api/schedule-save et api/absence-save)
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

if (is_follower()) {
    header('Location: /projets');
    exit;
}

// =============================================================
// AUTO-CRÉATION DES TABLES (idempotent)
// =============================================================
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `assokit_schedules` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `org_id` INT UNSIGNED NOT NULL,
        `user_id` INT UNSIGNED NOT NULL,
        `title` VARCHAR(120) DEFAULT NULL,
        `type` ENUM('present','remote','meeting','other') NOT NULL DEFAULT 'present',
        `recurrence` ENUM('weekly','once') NOT NULL DEFAULT 'weekly',
        `day_of_week` TINYINT UNSIGNED DEFAULT NULL COMMENT '1=lun,7=dim',
        `specific_date` DATE DEFAULT NULL,
        `start_time` TIME NOT NULL,
        `end_time` TIME NOT NULL,
        `location` VARCHAR(200) DEFAULT NULL,
        `notes` TEXT DEFAULT NULL,
        `color` VARCHAR(20) DEFAULT '#10b981',
        `created_by` INT UNSIGNED NOT NULL,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_org_user` (`org_id`, `user_id`),
        KEY `idx_recurrence` (`recurrence`),
        KEY `idx_date` (`specific_date`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    
    $pdo->exec("CREATE TABLE IF NOT EXISTS `assokit_absences` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `org_id` INT UNSIGNED NOT NULL,
        `user_id` INT UNSIGNED NOT NULL,
        `type` ENUM('vacation','sick','personal','other') NOT NULL DEFAULT 'vacation',
        `start_date` DATE NOT NULL,
        `end_date` DATE NOT NULL,
        `reason` VARCHAR(255) DEFAULT NULL,
        `is_approved` TINYINT(1) NOT NULL DEFAULT 0,
        `approved_by` INT UNSIGNED DEFAULT NULL,
        `approved_at` DATETIME DEFAULT NULL,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_org_user` (`org_id`, `user_id`),
        KEY `idx_dates` (`start_date`, `end_date`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (Throwable $e) {
    error_log('emploi-du-temps tables: ' . $e->getMessage());
}

// =============================================================
// PARAMÈTRES VUE (semaine ou mois)
// =============================================================
$view = in_array($_GET['view'] ?? '', ['week','month'], true) ? $_GET['view'] : 'week'; // whitelist (anti-XSS réfléchi)
$current_date = $_GET['date'] ?? date('Y-m-d');

// Ancrer sur lundi de la semaine demandée
$dt = new DateTime($current_date);
$dow = (int)$dt->format('N'); // 1=lun, 7=dim
$monday = clone $dt;
$monday->modify('-' . ($dow - 1) . ' days');

if ($view === 'month') {
    $first_of_month = (clone $dt)->modify('first day of this month');
    $last_of_month = (clone $dt)->modify('last day of this month');
    $month_start_dow = (int)$first_of_month->format('N');
    $cal_start = (clone $first_of_month)->modify('-' . ($month_start_dow - 1) . ' days');
    $cal_end = (clone $last_of_month)->modify('+' . (7 - (int)$last_of_month->format('N')) . ' days');
}

// Navigation prev/next
$prev_date = (clone $dt)->modify($view === 'week' ? '-7 days' : '-1 month')->format('Y-m-d');
$next_date = (clone $dt)->modify($view === 'week' ? '+7 days' : '+1 month')->format('Y-m-d');

// =============================================================
// FILTRE MEMBRE
// =============================================================
$filter_user = (int)($_GET['user'] ?? 0);

// Liste des membres pour le filtre
$members = $pdo->prepare("
    SELECT id, first_name, last_name, role, avatar_color
    FROM users
    WHERE org_id = ? AND is_active = 1 AND (deleted_at IS NULL OR deleted_at = '')
    ORDER BY first_name, last_name
");
$members->execute([$org_id]);
$members = $members->fetchAll();

// =============================================================
// RÉCUPÉRATION DES CRÉNEAUX
// =============================================================
$where_user = $filter_user > 0 ? ' AND s.user_id = ?' : '';
$user_param = $filter_user > 0 ? [$filter_user] : [];

// Créneaux récurrents (toutes les semaines)
$stmt = $pdo->prepare("
    SELECT s.*, u.first_name, u.last_name, u.avatar_color
    FROM assokit_schedules s
    JOIN users u ON u.id = s.user_id
    WHERE s.org_id = ? AND s.recurrence = 'weekly' $where_user
    ORDER BY s.day_of_week, s.start_time
");
$stmt->execute(array_merge([$org_id], $user_param));
$weekly_slots = $stmt->fetchAll();

// Créneaux ponctuels dans la période visible
if ($view === 'week') {
    $period_start = $monday->format('Y-m-d');
    $period_end = (clone $monday)->modify('+6 days')->format('Y-m-d');
} else {
    $period_start = $cal_start->format('Y-m-d');
    $period_end = $cal_end->format('Y-m-d');
}

$stmt = $pdo->prepare("
    SELECT s.*, u.first_name, u.last_name, u.avatar_color
    FROM assokit_schedules s
    JOIN users u ON u.id = s.user_id
    WHERE s.org_id = ? AND s.recurrence = 'once' AND s.specific_date BETWEEN ? AND ? $where_user
    ORDER BY s.specific_date, s.start_time
");
$stmt->execute(array_merge([$org_id, $period_start, $period_end], $user_param));
$once_slots = $stmt->fetchAll();

// Absences dans la période
$stmt = $pdo->prepare("
    SELECT a.*, u.first_name, u.last_name, u.avatar_color
    FROM assokit_absences a
    JOIN users u ON u.id = a.user_id
    WHERE a.org_id = ? 
      AND ((a.start_date BETWEEN ? AND ?) OR (a.end_date BETWEEN ? AND ?) OR (a.start_date <= ? AND a.end_date >= ?))
      $where_user
");
$abs_params = [$org_id, $period_start, $period_end, $period_start, $period_end, $period_start, $period_end];
if ($filter_user > 0) $abs_params[] = $filter_user;
$stmt->execute($abs_params);
$absences = $stmt->fetchAll();

// =============================================================
// HELPER : convertir les créneaux en map jour=>liste
// =============================================================
function format_time($time): string {
    return substr($time, 0, 5);
}

function build_day_map(array $weekly, array $once, array $absences, DateTime $monday): array {
    $map = []; // [Y-m-d => [slots, absences]]
    
    for ($i = 0; $i < 7; $i++) {
        $d = (clone $monday)->modify("+$i days");
        $key = $d->format('Y-m-d');
        $dow = (int)$d->format('N');
        $map[$key] = ['slots' => [], 'absences' => []];
        
        // Créneaux récurrents
        foreach ($weekly as $w) {
            if ((int)$w['day_of_week'] === $dow) {
                $map[$key]['slots'][] = $w + ['_recurring' => true];
            }
        }
        // Créneaux ponctuels
        foreach ($once as $o) {
            if ($o['specific_date'] === $key) {
                $map[$key]['slots'][] = $o + ['_recurring' => false];
            }
        }
        // Absences (overlap)
        foreach ($absences as $a) {
            if ($key >= $a['start_date'] && $key <= $a['end_date']) {
                $map[$key]['absences'][] = $a;
            }
        }
        
        // Tri par heure
        usort($map[$key]['slots'], fn($a, $b) => strcmp($a['start_time'], $b['start_time']));
    }
    
    return $map;
}

$day_map = build_day_map($weekly_slots, $once_slots, $absences, $view === 'week' ? $monday : $cal_start);

// Mois pour vue mois
$month_map = [];
if ($view === 'month') {
    $cur = clone $cal_start;
    while ($cur <= $cal_end) {
        $key = $cur->format('Y-m-d');
        $dow = (int)$cur->format('N');
        $month_map[$key] = ['slots' => [], 'absences' => []];
        foreach ($weekly_slots as $w) {
            if ((int)$w['day_of_week'] === $dow) $month_map[$key]['slots'][] = $w;
        }
        foreach ($once_slots as $o) {
            if ($o['specific_date'] === $key) $month_map[$key]['slots'][] = $o;
        }
        foreach ($absences as $a) {
            if ($key >= $a['start_date'] && $key <= $a['end_date']) $month_map[$key]['absences'][] = $a;
        }
        $cur->modify('+1 day');
    }
}

// Constantes affichage
$day_labels = ['Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi', 'Dimanche'];
$day_labels_short = ['Lun', 'Mar', 'Mer', 'Jeu', 'Ven', 'Sam', 'Dim'];
$months_fr = ['Janvier', 'Février', 'Mars', 'Avril', 'Mai', 'Juin', 'Juillet', 'Août', 'Septembre', 'Octobre', 'Novembre', 'Décembre'];

$type_labels = [
    'present' => ['label' => 'Présent', 'icon' => '🟢', 'color' => '#10b981'],
    'remote'  => ['label' => 'Télétravail', 'icon' => '🟡', 'color' => '#f59e0b'],
    'meeting' => ['label' => 'Réunion', 'icon' => '🔵', 'color' => '#3b82f6'],
    'other'   => ['label' => 'Autre', 'icon' => '⚪', 'color' => '#94a3b8'],
];
$absence_labels = [
    'vacation' => ['label' => '🌴 Vacances', 'color' => '#a855f7'],
    'sick'     => ['label' => '🤒 Maladie', 'color' => '#ef4444'],
    'personal' => ['label' => '📝 Perso', 'color' => '#6366f1'],
    'other'    => ['label' => '⚪ Autre', 'color' => '#94a3b8'],
];

render_head('Emploi du temps');
render_sidebar('emploi-du-temps');
?>

<style>
.edt-page { padding: 24px; }
.edt-head { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 12px; }
.edt-head h1 { margin: 0; font-size: 24px; font-weight: 600; letter-spacing: -0.02em; display: flex; align-items: center; gap: 11px; }
.edt-head-actions { display: flex; gap: 8px; flex-wrap: wrap; }

.edt-toolbar { background: #fff; border: 1px solid var(--c-border, #e7e5e4); border-radius: 14px; padding: 14px; margin-bottom: 16px; display: flex; flex-wrap: wrap; gap: 12px; align-items: center; justify-content: space-between; }
.edt-nav { display: flex; gap: 4px; align-items: center; }
.edt-nav-btn { padding: 8px 12px; background: #fff; border: 1px solid #e7e5e4; border-radius: 8px; cursor: pointer; font-size: 13px; color: #1c1917; text-decoration: none; transition: all .15s; }
.edt-nav-btn:hover { background: #f5f5f4; border-color: #d6d3d1; }
.edt-nav-current { padding: 8px 16px; font-weight: 600; font-size: 14px; color: #1c1917; }
.edt-views { display: flex; gap: 4px; background: #f5f5f4; border-radius: 8px; padding: 3px; }
.edt-view-btn { padding: 6px 14px; border: 0; background: transparent; cursor: pointer; border-radius: 6px; font-size: 13px; font-weight: 500; color: #57534e; text-decoration: none; }
.edt-view-btn.active { background: #fff; color: #1c1917; box-shadow: 0 1px 2px rgba(0,0,0,.08); }
.edt-filter { display: flex; gap: 8px; align-items: center; }
.edt-filter select { padding: 7px 12px; border: 1px solid #e7e5e4; border-radius: 8px; font-size: 13px; background: #fff; }

/* Vue semaine */
.edt-week { background: #fff; border: 1px solid #e7e5e4; border-radius: 14px; overflow: hidden; }
.edt-week-grid { display: grid; grid-template-columns: 80px repeat(7, 1fr); }
.edt-week-header { background: #fafaf9; padding: 12px 8px; text-align: center; border-bottom: 1px solid #e7e5e4; border-right: 1px solid #f5f5f4; font-size: 12px; }
.edt-week-header.today { background: #ecfdf5; }
.edt-week-header-day { font-weight: 600; color: #1c1917; }
.edt-week-header-date { color: #78716c; font-size: 11px; margin-top: 2px; }
.edt-week-header.today .edt-week-header-day { color: #059669; }

.edt-time-col { background: #fafaf9; border-right: 1px solid #e7e5e4; }
.edt-time-slot { height: 50px; padding: 4px 8px; font-size: 10px; color: #a8a29e; text-align: right; border-bottom: 1px solid #f5f5f4; }
.edt-day-col { border-right: 1px solid #f5f5f4; position: relative; min-height: 600px; }
.edt-day-col:last-child { border-right: 0; }

.edt-slot { position: absolute; left: 4px; right: 4px; padding: 6px 8px; border-radius: 6px; font-size: 11px; cursor: pointer; transition: all .15s; overflow: hidden; box-shadow: 0 1px 2px rgba(0,0,0,.05); border-left: 3px solid; }
.edt-slot:hover { transform: translateY(-1px); box-shadow: 0 4px 8px rgba(0,0,0,.1); z-index: 5; }
.edt-slot-title { font-weight: 600; color: #1c1917; line-height: 1.2; }
.edt-slot-time { color: #57534e; font-size: 10px; margin-top: 2px; }
.edt-slot-user { display: flex; align-items: center; gap: 4px; margin-top: 3px; font-size: 10px; color: #57534e; }
.edt-slot-recurring::after { content: '🔁'; position: absolute; top: 4px; right: 4px; font-size: 9px; }
.edt-slot.absence { border-left-color: #a855f7; background: rgba(168, 85, 247, 0.08); }

/* Vue mois */
.edt-month { background: #fff; border: 1px solid #e7e5e4; border-radius: 14px; overflow: hidden; }
.edt-month-header { display: grid; grid-template-columns: repeat(7, 1fr); background: #fafaf9; }
.edt-month-header-cell { padding: 10px; text-align: center; font-size: 12px; font-weight: 600; color: #57534e; border-right: 1px solid #f5f5f4; }
.edt-month-grid { display: grid; grid-template-columns: repeat(7, 1fr); }
.edt-month-cell { min-height: 110px; padding: 6px; border-right: 1px solid #f5f5f4; border-bottom: 1px solid #f5f5f4; cursor: pointer; transition: background .15s; }
.edt-month-cell:hover { background: #fafaf9; }
.edt-month-cell.outside { background: #fafaf9; }
.edt-month-cell.today { background: #ecfdf5; }
.edt-month-cell.outside .edt-month-day-num { color: #d6d3d1; }
.edt-month-day-num { font-size: 13px; font-weight: 500; color: #57534e; margin-bottom: 4px; }
.edt-month-cell.today .edt-month-day-num { color: #059669; font-weight: 700; }
.edt-month-mini-slot { font-size: 10px; padding: 2px 5px; margin-bottom: 2px; border-radius: 3px; background: #f0fdf4; color: #166534; border-left: 2px solid; overflow: hidden; white-space: nowrap; text-overflow: ellipsis; }
.edt-month-more { font-size: 10px; color: #78716c; padding: 2px 5px; }

/* Légende */
.edt-legend { display: flex; gap: 14px; flex-wrap: wrap; padding: 12px 16px; background: #fafaf9; border-top: 1px solid #f5f5f4; font-size: 12px; }
.edt-legend-item { display: flex; align-items: center; gap: 6px; color: #57534e; }
.edt-legend-dot { width: 10px; height: 10px; border-radius: 3px; }

/* Modal */
.edt-modal-bg { position: fixed; inset: 0; background: rgba(0,0,0,.5); z-index: 1000; display: none; align-items: center; justify-content: center; padding: 20px; }
.edt-modal-bg.open { display: flex; }
.edt-modal { position: relative; background: #fff; border-radius: 14px; max-width: 480px; width: 100%; max-height: 90vh; overflow-y: auto; padding: 24px; box-shadow: 0 20px 60px rgba(0,0,0,.3); }
.edt-modal h2 { margin: 0 0 16px; font-size: 18px; }
.edt-modal-close { position: absolute; top: 16px; right: 20px; background: none; border: 0; font-size: 22px; cursor: pointer; color: #78716c; }
.edt-form-row { margin-bottom: 14px; }
.edt-form-row label { display: block; font-size: 12.5px; font-weight: 500; color: #44403c; margin-bottom: 5px; }
.edt-form-row input, .edt-form-row select, .edt-form-row textarea { width: 100%; padding: 9px 12px; border: 1px solid #e7e5e4; border-radius: 8px; font-size: 14px; font-family: inherit; }
.edt-form-row input:focus, .edt-form-row select:focus, .edt-form-row textarea:focus { outline: none; border-color: #059669; box-shadow: 0 0 0 3px rgba(5,150,105,.1); }
.edt-form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }

.edt-tabs { display: flex; gap: 4px; background: #f5f5f4; border-radius: 8px; padding: 3px; margin-bottom: 16px; }
.edt-tab { flex: 1; padding: 8px 12px; border: 0; background: transparent; cursor: pointer; border-radius: 6px; font-size: 13px; font-weight: 500; }
.edt-tab.active { background: #fff; box-shadow: 0 1px 2px rgba(0,0,0,.08); }

.edt-modal-actions { display: flex; gap: 8px; justify-content: flex-end; margin-top: 20px; padding-top: 16px; border-top: 1px solid #f5f5f4; }
.edt-btn-primary { padding: 10px 20px; background: #059669; color: #fff; border: 0; border-radius: 8px; font-size: 14px; font-weight: 500; cursor: pointer; }
.edt-btn-primary:hover { background: #047857; }
.edt-btn-secondary { padding: 10px 20px; background: #fff; color: #44403c; border: 1px solid #e7e5e4; border-radius: 8px; font-size: 14px; font-weight: 500; cursor: pointer; }
.edt-btn-danger { padding: 10px 20px; background: #fff; color: #dc2626; border: 1px solid #fca5a5; border-radius: 8px; font-size: 14px; font-weight: 500; cursor: pointer; }

/* Empty state */
.edt-empty { text-align: center; padding: 60px 20px; color: #78716c; }
.edt-empty-icon { font-size: 48px; margin-bottom: 12px; }

@media (max-width: 900px) {
    .edt-page { padding: 14px; }
    .edt-toolbar { flex-direction: column; align-items: stretch; }
    .edt-week-grid { grid-template-columns: 50px repeat(7, minmax(80px, 1fr)); overflow-x: auto; }
    .edt-week { overflow-x: auto; }
    .edt-month-cell { min-height: 70px; }
    .edt-form-grid { grid-template-columns: 1fr; }
}
</style>

<main class="main">
<div class="edt-page">

<div class="edt-head">
    <div>
        <h1><?= ak_icon_badge('calendar','#059669',36) ?><span>Emploi du temps</span></h1>
        <div style="color: #78716c; font-size: 13px; margin-top: 4px;">
            Planning des membres de <?= h($user['org_name'] ?? 'votre association') ?>
        </div>
    </div>
    <div class="edt-head-actions">
        <button type="button" class="btn btn-primary" onclick="openSlotModal()">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            Nouveau créneau
        </button>
        <button type="button" class="btn btn-ghost" onclick="openAbsenceModal()">
            🌴 Déclarer une absence
        </button>
    </div>
</div>

<div class="edt-toolbar">
    <div class="edt-nav">
        <a href="?view=<?= $view ?>&date=<?= $prev_date ?>&user=<?= $filter_user ?>" class="edt-nav-btn">‹</a>
        <span class="edt-nav-current">
            <?php if ($view === 'week'): ?>
                Semaine du <?= $monday->format('d') ?> 
                <?php
                $sunday = (clone $monday)->modify('+6 days');
                if ($monday->format('m') === $sunday->format('m')) {
                    echo $months_fr[(int)$monday->format('n') - 1] . ' ' . $monday->format('Y');
                } else {
                    echo $months_fr[(int)$monday->format('n') - 1] . ' au ' . $sunday->format('d') . ' ' . $months_fr[(int)$sunday->format('n') - 1];
                }
                ?>
            <?php else: ?>
                <?= $months_fr[(int)$dt->format('n') - 1] ?> <?= $dt->format('Y') ?>
            <?php endif; ?>
        </span>
        <a href="?view=<?= $view ?>&date=<?= $next_date ?>&user=<?= $filter_user ?>" class="edt-nav-btn">›</a>
        <a href="?view=<?= $view ?>&date=<?= date('Y-m-d') ?>&user=<?= $filter_user ?>" class="edt-nav-btn" style="margin-left:8px;">Aujourd'hui</a>
    </div>
    
    <div class="edt-views">
        <a href="?view=week&date=<?= $current_date ?>&user=<?= $filter_user ?>" class="edt-view-btn <?= $view === 'week' ? 'active' : '' ?>">Semaine</a>
        <a href="?view=month&date=<?= $current_date ?>&user=<?= $filter_user ?>" class="edt-view-btn <?= $view === 'month' ? 'active' : '' ?>">Mois</a>
    </div>
    
    <div class="edt-filter">
        <select onchange="window.location.href='?view=<?= $view ?>&date=<?= $current_date ?>&user='+this.value">
            <option value="0">👥 Tous les membres</option>
            <?php foreach ($members as $m): ?>
                <option value="<?= (int)$m['id'] ?>" <?= $filter_user === (int)$m['id'] ? 'selected' : '' ?>>
                    <?= h($m['first_name']) ?> <?= h($m['last_name']) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
</div>

<?php if ($view === 'week'): ?>
<!-- VUE SEMAINE -->
<div class="edt-week">
    <div class="edt-week-grid">
        <div class="edt-week-header"></div>
        <?php for ($i = 0; $i < 7; $i++):
            $d = (clone $monday)->modify("+$i days");
            $is_today = $d->format('Y-m-d') === date('Y-m-d');
        ?>
        <div class="edt-week-header <?= $is_today ? 'today' : '' ?>">
            <div class="edt-week-header-day"><?= $day_labels[$i] ?></div>
            <div class="edt-week-header-date"><?= $d->format('d/m') ?></div>
        </div>
        <?php endfor; ?>
    </div>
    
    <div class="edt-week-grid">
        <div class="edt-time-col">
            <?php for ($h = 7; $h <= 20; $h++): ?>
                <div class="edt-time-slot"><?= $h ?>h</div>
            <?php endfor; ?>
        </div>
        
        <?php for ($i = 0; $i < 7; $i++):
            $d = (clone $monday)->modify("+$i days");
            $key = $d->format('Y-m-d');
            $day_data = $day_map[$key] ?? ['slots' => [], 'absences' => []];
        ?>
        <div class="edt-day-col" data-date="<?= $key ?>" onclick="if(event.target===this) openSlotModal('<?= $key ?>')">
            <?php foreach ($day_data['absences'] as $abs):
                $abs_info = $absence_labels[$abs['type']] ?? $absence_labels['other'];
            ?>
            <div class="edt-slot absence" 
                 style="top: 0; height: 100%; background: <?= $abs_info['color'] ?>15; border-left-color: <?= $abs_info['color'] ?>; opacity: 0.7;"
                 onclick="event.stopPropagation(); editAbsence(<?= (int)$abs['id'] ?>)">
                <div class="edt-slot-title"><?= h($abs_info['label']) ?></div>
                <div class="edt-slot-user"><?= ak_icon('user',11,'2') ?> <?= h($abs['first_name']) ?> <?= h(mb_substr($abs['last_name'], 0, 1)) ?>.</div>
            </div>
            <?php endforeach; ?>
            
            <?php foreach ($day_data['slots'] as $slot):
                $type_info = $type_labels[$slot['type']] ?? $type_labels['other'];
                $start_h = (float)substr($slot['start_time'], 0, 2) + (float)substr($slot['start_time'], 3, 2) / 60;
                $end_h = (float)substr($slot['end_time'], 0, 2) + (float)substr($slot['end_time'], 3, 2) / 60;
                $top = ($start_h - 7) * 50;
                $height = max(30, ($end_h - $start_h) * 50);
                $bg_color = $slot['color'] ?: $type_info['color'];
            ?>
            <div class="edt-slot <?= !empty($slot['_recurring']) ? 'edt-slot-recurring' : '' ?>" 
                 style="top: <?= $top ?>px; height: <?= $height ?>px; background: <?= $bg_color ?>15; border-left-color: <?= $bg_color ?>;"
                 onclick="event.stopPropagation(); editSlot(<?= (int)$slot['id'] ?>)">
                <div class="edt-slot-title"><?= h($slot['title'] ?: $type_info['label']) ?></div>
                <div class="edt-slot-time"><?= format_time($slot['start_time']) ?> - <?= format_time($slot['end_time']) ?></div>
                <div class="edt-slot-user"><?= ak_icon('user',11,'2') ?> <?= h($slot['first_name']) ?></div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endfor; ?>
    </div>
    
    <div class="edt-legend">
        <?php foreach ($type_labels as $t => $info): ?>
            <div class="edt-legend-item">
                <div class="edt-legend-dot" style="background: <?= $info['color'] ?>"></div>
                <?= $info['icon'] ?> <?= $info['label'] ?>
            </div>
        <?php endforeach; ?>
        <?php foreach ($absence_labels as $t => $info): ?>
            <div class="edt-legend-item">
                <div class="edt-legend-dot" style="background: <?= $info['color'] ?>"></div>
                <?= $info['label'] ?>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<?php else: // VUE MOIS ?>
<div class="edt-month">
    <div class="edt-month-header">
        <?php foreach ($day_labels_short as $d): ?>
            <div class="edt-month-header-cell"><?= $d ?></div>
        <?php endforeach; ?>
    </div>
    <div class="edt-month-grid">
        <?php
        $cur = clone $cal_start;
        while ($cur <= $cal_end):
            $key = $cur->format('Y-m-d');
            $day_data = $month_map[$key] ?? ['slots' => [], 'absences' => []];
            $is_outside = ((int)$cur->format('n') !== (int)$dt->format('n'));
            $is_today = $key === date('Y-m-d');
            $cls = '';
            if ($is_outside) $cls .= ' outside';
            if ($is_today) $cls .= ' today';
        ?>
        <div class="edt-month-cell <?= $cls ?>" onclick="openSlotModal('<?= $key ?>')">
            <div class="edt-month-day-num"><?= $cur->format('j') ?></div>
            <?php
            $count = 0;
            foreach ($day_data['absences'] as $abs):
                if ($count >= 3) break;
                $abs_info = $absence_labels[$abs['type']] ?? $absence_labels['other'];
                $count++;
            ?>
                <div class="edt-month-mini-slot" style="border-left-color: <?= $abs_info['color'] ?>; background: <?= $abs_info['color'] ?>15;">
                    <?= h($abs_info['label']) ?> <?= h($abs['first_name']) ?>
                </div>
            <?php endforeach;
            foreach ($day_data['slots'] as $slot):
                if ($count >= 3) break;
                $type_info = $type_labels[$slot['type']] ?? $type_labels['other'];
                $bg_color = $slot['color'] ?: $type_info['color'];
                $count++;
            ?>
                <div class="edt-month-mini-slot" style="border-left-color: <?= $bg_color ?>; background: <?= $bg_color ?>15;">
                    <?= format_time($slot['start_time']) ?> <?= h($slot['first_name']) ?>
                </div>
            <?php endforeach;
            $total = count($day_data['absences']) + count($day_data['slots']);
            if ($total > 3): ?>
                <div class="edt-month-more">+<?= ($total - 3) ?> autres</div>
            <?php endif; ?>
        </div>
        <?php
            $cur->modify('+1 day');
        endwhile;
        ?>
    </div>
</div>
<?php endif; ?>

<!-- MODAL CRÉNEAU -->
<div class="edt-modal-bg" id="edt-modal-slot">
    <div class="edt-modal">
        <button type="button" class="edt-modal-close" onclick="closeModal('edt-modal-slot')">×</button>
        <h2 id="edt-modal-slot-title">📅 Nouveau créneau</h2>
        
        <form id="edt-slot-form" onsubmit="saveSlot(event)">
            <input type="hidden" name="id" value="">
            <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf_token'] ?? '') ?>">
            
            <div class="edt-tabs">
                <button type="button" class="edt-tab active" onclick="switchSlotTab(this, 'weekly')">🔁 Récurrent</button>
                <button type="button" class="edt-tab" onclick="switchSlotTab(this, 'once')">📌 Ponctuel</button>
            </div>
            <input type="hidden" name="recurrence" id="slot-recurrence" value="weekly">
            
            <div class="edt-form-row">
                <label>Membre</label>
                <select name="user_id" required>
                    <?php foreach ($members as $m): ?>
                        <option value="<?= (int)$m['id'] ?>" <?= (int)$m['id'] === $user_id ? 'selected' : '' ?>>
                            <?= h($m['first_name']) ?> <?= h($m['last_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="edt-form-row">
                <label>Titre (optionnel)</label>
                <input type="text" name="title" placeholder="Ex : Permanence accueil, Réunion équipe..." maxlength="120">
            </div>
            
            <div class="edt-form-row" id="slot-day-of-week">
                <label>Jour de la semaine</label>
                <select name="day_of_week">
                    <?php for ($i = 1; $i <= 7; $i++): ?>
                        <option value="<?= $i ?>"><?= $day_labels[$i - 1] ?></option>
                    <?php endfor; ?>
                </select>
            </div>
            
            <div class="edt-form-row" id="slot-specific-date" style="display:none;">
                <label>Date</label>
                <input type="date" name="specific_date" value="<?= date('Y-m-d') ?>">
            </div>
            
            <div class="edt-form-grid">
                <div class="edt-form-row">
                    <label>De</label>
                    <input type="time" name="start_time" value="09:00" required>
                </div>
                <div class="edt-form-row">
                    <label>À</label>
                    <input type="time" name="end_time" value="12:00" required>
                </div>
            </div>
            
            <div class="edt-form-row">
                <label>Type</label>
                <select name="type">
                    <option value="present">🟢 Présent (au bureau)</option>
                    <option value="remote">🟡 Télétravail</option>
                    <option value="meeting">🔵 Réunion</option>
                    <option value="other">⚪ Autre</option>
                </select>
            </div>
            
            <div class="edt-form-row">
                <label>Lieu (optionnel)</label>
                <input type="text" name="location" placeholder="Ex : Bureau Paris, Salle 2..." maxlength="200">
            </div>
            
            <div class="edt-form-row">
                <label>Notes (optionnel)</label>
                <textarea name="notes" rows="2" placeholder="Précisions complémentaires..."></textarea>
            </div>
            
            <div class="edt-modal-actions">
                <button type="button" class="edt-btn-danger" id="btn-delete-slot" style="display:none;" onclick="deleteSlot()">Supprimer</button>
                <button type="button" class="edt-btn-secondary" onclick="closeModal('edt-modal-slot')">Annuler</button>
                <button type="submit" class="edt-btn-primary">Enregistrer</button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL ABSENCE -->
<div class="edt-modal-bg" id="edt-modal-absence">
    <div class="edt-modal">
        <button type="button" class="edt-modal-close" onclick="closeModal('edt-modal-absence')">×</button>
        <h2 id="edt-modal-absence-title">🌴 Déclarer une absence</h2>
        
        <form id="edt-absence-form" onsubmit="saveAbsence(event)">
            <input type="hidden" name="id" value="">
            <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf_token'] ?? '') ?>">
            
            <div class="edt-form-row">
                <label>Membre</label>
                <select name="user_id" required>
                    <?php foreach ($members as $m): ?>
                        <option value="<?= (int)$m['id'] ?>" <?= (int)$m['id'] === $user_id ? 'selected' : '' ?>>
                            <?= h($m['first_name']) ?> <?= h($m['last_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="edt-form-row">
                <label>Type</label>
                <select name="type">
                    <option value="vacation">🌴 Vacances</option>
                    <option value="sick">🤒 Maladie / Arrêt</option>
                    <option value="personal">📝 Congé personnel</option>
                    <option value="other">⚪ Autre</option>
                </select>
            </div>
            
            <div class="edt-form-grid">
                <div class="edt-form-row">
                    <label>Du</label>
                    <input type="date" name="start_date" value="<?= date('Y-m-d') ?>" required>
                </div>
                <div class="edt-form-row">
                    <label>Au</label>
                    <input type="date" name="end_date" value="<?= date('Y-m-d') ?>" required>
                </div>
            </div>
            
            <div class="edt-form-row">
                <label>Motif / Détails (optionnel)</label>
                <textarea name="reason" rows="2" placeholder="Ex : Vacances été 2026..." maxlength="255"></textarea>
            </div>
            
            <div class="edt-modal-actions">
                <button type="button" class="edt-btn-danger" id="btn-delete-absence" style="display:none;" onclick="deleteAbsence()">Supprimer</button>
                <button type="button" class="edt-btn-secondary" onclick="closeModal('edt-modal-absence')">Annuler</button>
                <button type="submit" class="edt-btn-primary">Enregistrer</button>
            </div>
        </form>
    </div>
</div>

<script>
// =========================================================
// JS Emploi du temps
// =========================================================
let currentSlotId = null;
let currentAbsenceId = null;

function openSlotModal(date) {
    currentSlotId = null;
    document.getElementById('edt-modal-slot-title').textContent = '📅 Nouveau créneau';
    document.getElementById('btn-delete-slot').style.display = 'none';
    const form = document.getElementById('edt-slot-form');
    form.reset();
    form.querySelector('[name=id]').value = '';
    if (date) {
        form.querySelector('[name=specific_date]').value = date;
    }
    document.getElementById('edt-modal-slot').classList.add('open');
}

function openAbsenceModal() {
    currentAbsenceId = null;
    document.getElementById('edt-modal-absence-title').textContent = '🌴 Déclarer une absence';
    document.getElementById('btn-delete-absence').style.display = 'none';
    const form = document.getElementById('edt-absence-form');
    form.reset();
    form.querySelector('[name=id]').value = '';
    document.getElementById('edt-modal-absence').classList.add('open');
}

function closeModal(id) {
    document.getElementById(id).classList.remove('open');
}

// Fermer en cliquant en dehors
document.querySelectorAll('.edt-modal-bg').forEach(m => {
    m.addEventListener('click', (e) => { if (e.target === m) m.classList.remove('open'); });
});

function switchSlotTab(btn, type) {
    document.querySelectorAll('.edt-tabs .edt-tab').forEach(t => t.classList.remove('active'));
    btn.classList.add('active');
    document.getElementById('slot-recurrence').value = type;
    if (type === 'weekly') {
        document.getElementById('slot-day-of-week').style.display = '';
        document.getElementById('slot-specific-date').style.display = 'none';
    } else {
        document.getElementById('slot-day-of-week').style.display = 'none';
        document.getElementById('slot-specific-date').style.display = '';
    }
}

async function editSlot(id) {
    try {
        const res = await fetch('/api/schedule-get.php?id=' + id);
        const data = await res.json();
        if (!data.success) { alert(data.error || 'Erreur'); return; }
        const s = data.slot;
        
        currentSlotId = id;
        document.getElementById('edt-modal-slot-title').textContent = '✏️ Modifier le créneau';
        document.getElementById('btn-delete-slot').style.display = '';
        
        const form = document.getElementById('edt-slot-form');
        form.querySelector('[name=id]').value = id;
        form.querySelector('[name=user_id]').value = s.user_id;
        form.querySelector('[name=title]').value = s.title || '';
        form.querySelector('[name=start_time]').value = s.start_time.substring(0,5);
        form.querySelector('[name=end_time]').value = s.end_time.substring(0,5);
        form.querySelector('[name=type]').value = s.type;
        form.querySelector('[name=location]').value = s.location || '';
        form.querySelector('[name=notes]').value = s.notes || '';
        
        if (s.recurrence === 'weekly') {
            switchSlotTab(document.querySelectorAll('.edt-tabs .edt-tab')[0], 'weekly');
            form.querySelector('[name=day_of_week]').value = s.day_of_week;
        } else {
            switchSlotTab(document.querySelectorAll('.edt-tabs .edt-tab')[1], 'once');
            form.querySelector('[name=specific_date]').value = s.specific_date;
        }
        
        document.getElementById('edt-modal-slot').classList.add('open');
    } catch (e) {
        alert('Erreur : ' + e.message);
    }
}

async function saveSlot(e) {
    e.preventDefault();
    const form = e.target;
    const fd = new FormData(form);
    
    try {
        const res = await fetch('/api/schedule-save.php', { method: 'POST', body: fd });
        const data = await res.json();
        if (data.success) {
            closeModal('edt-modal-slot');
            location.reload();
        } else {
            alert(data.error || 'Erreur lors de l\'enregistrement');
        }
    } catch (e) {
        alert('Erreur : ' + e.message);
    }
}

async function deleteSlot() {
    if (!confirm('Supprimer ce créneau ?')) return;
    const form = document.getElementById('edt-slot-form');
    const fd = new FormData();
    fd.append('id', form.querySelector('[name=id]').value);
    fd.append('csrf', form.querySelector('[name=csrf]').value);
    fd.append('action', 'delete');
    
    const res = await fetch('/api/schedule-save.php', { method: 'POST', body: fd });
    const data = await res.json();
    if (data.success) {
        closeModal('edt-modal-slot');
        location.reload();
    } else {
        alert(data.error || 'Erreur');
    }
}

async function editAbsence(id) {
    try {
        const res = await fetch('/api/absence-get.php?id=' + id);
        const data = await res.json();
        if (!data.success) { alert(data.error || 'Erreur'); return; }
        const a = data.absence;
        
        currentAbsenceId = id;
        document.getElementById('edt-modal-absence-title').textContent = '✏️ Modifier l\'absence';
        document.getElementById('btn-delete-absence').style.display = '';
        
        const form = document.getElementById('edt-absence-form');
        form.querySelector('[name=id]').value = id;
        form.querySelector('[name=user_id]').value = a.user_id;
        form.querySelector('[name=type]').value = a.type;
        form.querySelector('[name=start_date]').value = a.start_date;
        form.querySelector('[name=end_date]').value = a.end_date;
        form.querySelector('[name=reason]').value = a.reason || '';
        
        document.getElementById('edt-modal-absence').classList.add('open');
    } catch (e) {
        alert('Erreur : ' + e.message);
    }
}

async function saveAbsence(e) {
    e.preventDefault();
    const fd = new FormData(e.target);
    try {
        const res = await fetch('/api/absence-save.php', { method: 'POST', body: fd });
        const data = await res.json();
        if (data.success) {
            closeModal('edt-modal-absence');
            location.reload();
        } else {
            alert(data.error || 'Erreur');
        }
    } catch (e) {
        alert('Erreur : ' + e.message);
    }
}

async function deleteAbsence() {
    if (!confirm('Supprimer cette absence ?')) return;
    const form = document.getElementById('edt-absence-form');
    const fd = new FormData();
    fd.append('id', form.querySelector('[name=id]').value);
    fd.append('csrf', form.querySelector('[name=csrf]').value);
    fd.append('action', 'delete');
    
    const res = await fetch('/api/absence-save.php', { method: 'POST', body: fd });
    const data = await res.json();
    if (data.success) {
        closeModal('edt-modal-absence');
        location.reload();
    } else {
        alert(data.error || 'Erreur');
    }
}
</script>

</div>
</main>

<?php render_foot(); ?>
