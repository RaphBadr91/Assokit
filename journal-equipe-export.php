<?php
/**
 * journal-equipe-export.php — Export CSV du journal d'activité de l'équipe
 * ------------------------------------------------------------------
 * Formule Équipe (feature_activity_log), administrateurs seulement. Mêmes filtres que
 * /journal-equipe (membre, période de 12 mois au plus, type). CSV UTF-8 avec BOM, séparateur
 * « ; » (ouverture directe dans Excel), 20 000 lignes au plus. Ni IP ni navigateur.
 * ------------------------------------------------------------------
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/app-context.php';   // ak_session_guard() : compte désactivé, association résiliée ou suspendue
ak_session_guard();
require_once __DIR__ . '/journal-equipe-helpers.php';
require_login();

$user = current_user();
$org_id = (int)($user['org_id'] ?? 0);
if ($org_id <= 0) { header('Location: /dashboard'); exit; }

$acces = je_access($pdo, $user, $org_id);
if ($acces === 'role') { http_response_code(403); exit('Le journal d’activité est réservé aux administrateurs.'); }
if ($acces === 'plan') akc_lock_page($pdo, $org_id, 'activity_log', 'Journal d’activité de l’équipe', 'journal-equipe');

$f = je_filters($_GET);
$rows = je_rows($pdo, $org_id, $f, 20000);

// Cellule sûre pour un tableur : une valeur qui commence par = + - @ serait lue comme une formule
$cell = function (string $v): string {
    $v = str_replace(["\r", "\n"], ' ', $v);
    if ($v !== '' && strpbrk($v[0], '=+-@') !== false) $v = "'" . $v;
    return $v;
};

$name = 'journal-activite-' . date('Y-m-d') . '.csv';
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $name . '"');
header('Cache-Control: no-store');
$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF");   // BOM : Excel reconnaît l'UTF-8
fputcsv($out, ['Date', 'Membre', 'Action', 'Objet', 'Type'], ';', '"', '\\');
foreach ($rows as $r) {
    fputcsv($out, [
        date('d/m/Y H:i', strtotime($r['at'])),
        $cell($r['who']),
        $cell($r['action']),
        $cell($r['target']),
        $cell($r['type']),
    ], ';', '"', '\\');
}
fclose($out);
exit;
