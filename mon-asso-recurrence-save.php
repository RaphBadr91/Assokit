<?php
/**
 * mon-asso-recurrence-save.php
 * --------------------------------------------------------------
 * Sauvegarde POST (création ou édition) — Pack PHASE 3
 * --------------------------------------------------------------
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/asso-recurrence-helpers.php';

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_login();
$user = current_user();
if (!$user) { header('Location: /login'); exit; }
$org_id  = (int)($user['org_id'] ?? 0);
$user_id = (int)($user['id'] ?? 0);
if ($org_id <= 0) { header('Location: /'); exit; }

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: /mon-asso-recurrences'); exit; }

$rec_id = (int)($_POST['id'] ?? 0);
$is_edit = $rec_id > 0;

$title           = trim((string)($_POST['title'] ?? ''));
$client_id       = (int)($_POST['client_id'] ?? 0); $client_id = $client_id > 0 ? $client_id : null;
$frequency       = (string)($_POST['frequency'] ?? 'monthly');
$interval_count  = max(1, (int)($_POST['interval_count'] ?? 1));
$day_of_month_in = trim((string)($_POST['day_of_month'] ?? ''));
$day_of_month    = ($day_of_month_in === '') ? null : max(1, min(31, (int)$day_of_month_in));
$start_date      = (string)($_POST['start_date'] ?? date('Y-m-d'));
$end_date_in     = trim((string)($_POST['end_date'] ?? ''));
$end_date        = ($end_date_in === '') ? null : $end_date_in;
$max_in          = trim((string)($_POST['max_occurrences'] ?? ''));
$max_occurrences = ($max_in === '') ? null : max(1, (int)$max_in);
$auto_send       = !empty($_POST['auto_send']) ? 1 : 0;
$notify_admin    = !empty($_POST['notify_admin']) ? 1 : 0;
$vat_mode        = (string)($_POST['vat_mode'] ?? 'none');
$notes           = trim((string)($_POST['notes'] ?? ''));
$next_run_in     = trim((string)($_POST['next_run_date'] ?? ''));
$status          = (string)($_POST['status'] ?? 'active');

// Validations basiques
if (!in_array($frequency, ['daily','weekly','monthly','quarterly','yearly'], true)) $frequency = 'monthly';
if (!in_array($vat_mode,  ['none','5.5','10','20'], true)) $vat_mode = 'none';
if (!in_array($status,    ['active','paused','ended','cancelled'], true)) $status = 'active';
if ($title === '') { $_SESSION['flash_error'] = 'Le titre est obligatoire.'; header('Location: /mon-asso-recurrences'); exit; }
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start_date)) $start_date = date('Y-m-d');

// Lignes
$raw_lines = $_POST['lines'] ?? [];
$lines = [];
foreach ($raw_lines as $l) {
    $label = trim((string)($l['label'] ?? ''));
    if ($label === '') continue;
    $qty   = max(0, (float)($l['quantity'] ?? 1));
    $price = (float)($l['unit_price'] ?? 0);
    $unit_cents = (int)round($price * 100);
    $lines[] = [
        'label'                => mb_substr($label, 0, 500),
        'quantity'             => $qty,
        'unit_price_cents_ttc' => $unit_cents,
    ];
}
if (empty($lines)) {
    $_SESSION['flash_error'] = 'Au moins une ligne avec une description est requise.';
    header('Location: ' . ($is_edit ? "/mon-asso-recurrence-edit?id={$rec_id}" : '/mon-asso-recurrence-new'));
    exit;
}
$template_data = json_encode(['lines' => $lines, 'meta' => ['saved_at' => date('c')]], JSON_UNESCAPED_UNICODE);

// Calcul next_run_date
if ($next_run_in !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $next_run_in)) {
    $next_run = $next_run_in;
} else {
    // Première fois : next = start_date (avec ajustement jour si applicable)
    if ($day_of_month && in_array($frequency, ['monthly','quarterly','yearly'], true)) {
        $dt = new DateTimeImmutable($start_date);
        $year = (int)$dt->format('Y'); $month = (int)$dt->format('m');
        $lastDay = (int)(new DateTimeImmutable("$year-$month-01"))->modify('last day of this month')->format('d');
        $day = min($day_of_month, $lastDay);
        $candidate = sprintf('%04d-%02d-%02d', $year, $month, $day);
        $next_run = strtotime($candidate) >= strtotime($start_date) ? $candidate : ak_recurrence_calc_next_date($frequency, $interval_count, $candidate, $day_of_month);
    } else {
        $next_run = $start_date;
    }
}

try {
    if ($is_edit) {
        $existing = ak_recurrence_load($pdo, $rec_id, $org_id);
        if (!$existing) { header('Location: /mon-asso-recurrences'); exit; }
        $sql = "UPDATE asso_invoice_recurrences SET
                title = :title, client_id = :client_id, frequency = :freq, interval_count = :ic,
                day_of_month = :dom, start_date = :sd, end_date = :ed, max_occurrences = :max,
                next_run_date = :nrd, auto_send = :as, notify_admin = :na,
                status = :st, vat_mode = :vat, notes = :notes, template_data = :tpl,
                updated_at = NOW()
                WHERE id = :id AND org_id = :o";
        $st = $pdo->prepare($sql);
        $st->execute([
            ':title' => $title, ':client_id' => $client_id, ':freq' => $frequency, ':ic' => $interval_count,
            ':dom' => $day_of_month, ':sd' => $start_date, ':ed' => $end_date, ':max' => $max_occurrences,
            ':nrd' => $next_run, ':as' => $auto_send, ':na' => $notify_admin,
            ':st' => $status, ':vat' => $vat_mode, ':notes' => $notes, ':tpl' => $template_data,
            ':id' => $rec_id, ':o' => $org_id,
        ]);
        $_SESSION['flash_success'] = 'Récurrence mise à jour.';
        header('Location: /mon-asso-recurrence-edit?id=' . $rec_id);
        exit;
    } else {
        $sql = "INSERT INTO asso_invoice_recurrences
                (org_id, client_id, title, frequency, interval_count, day_of_month,
                 start_date, end_date, max_occurrences, occurrences_count, next_run_date,
                 auto_send, notify_admin, status, vat_mode, notes, template_data,
                 created_by, created_at, updated_at)
                VALUES
                (:o, :c, :title, :freq, :ic, :dom,
                 :sd, :ed, :max, 0, :nrd,
                 :as, :na, :st, :vat, :notes, :tpl,
                 :cb, NOW(), NOW())";
        $st = $pdo->prepare($sql);
        $st->execute([
            ':o' => $org_id, ':c' => $client_id, ':title' => $title, ':freq' => $frequency, ':ic' => $interval_count,
            ':dom' => $day_of_month, ':sd' => $start_date, ':ed' => $end_date, ':max' => $max_occurrences,
            ':nrd' => $next_run, ':as' => $auto_send, ':na' => $notify_admin,
            ':st' => $status, ':vat' => $vat_mode, ':notes' => $notes, ':tpl' => $template_data,
            ':cb' => $user_id ?: null,
        ]);
        $new_id = (int)$pdo->lastInsertId();
        $_SESSION['flash_success'] = 'Récurrence créée avec succès. Première facture prévue le ' . date('d/m/Y', strtotime($next_run)) . '.';
        header('Location: /mon-asso-recurrence-edit?id=' . $new_id);
        exit;
    }
} catch (Throwable $e) {
    $_SESSION['flash_error'] = 'Erreur : ' . $e->getMessage();
    header('Location: ' . ($is_edit ? "/mon-asso-recurrence-edit?id={$rec_id}" : '/mon-asso-recurrence-new'));
    exit;
}
