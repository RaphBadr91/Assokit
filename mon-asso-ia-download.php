<?php
/**
 * mon-asso-ia-download.php
 * --------------------------------------------------------------
 * Téléchargement d'une génération en MD / TXT / HTML
 * URL : /mon-asso-ia-download?id=ID&fmt=md|txt|html
 * --------------------------------------------------------------
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/rate-limit-helper.php';
ak_rate_limit_or_die('ia_download', 30, 60, (string)($_SESSION['user_id'] ?? ($_SERVER['REMOTE_ADDR'] ?? 'anon')));
require_once __DIR__ . '/asso-ai-helpers.php';

require_login();
$user = current_user();
$org_id = (int)($user['org_id'] ?? 0);
if ($org_id <= 0) { header('Location: /'); exit; }

$id  = (int)($_GET['id'] ?? 0);
$fmt = (string)($_GET['fmt'] ?? 'md');
if (!in_array($fmt, ['md', 'txt', 'html'], true)) $fmt = 'md';

$gen = ak_ai_load_generation($pdo, $id, $org_id);
if (!$gen) { http_response_code(404); echo 'Génération introuvable.'; exit; }

$tool = ak_ai_tool($gen['tool_type']);
$slug = preg_replace('/[^a-zA-Z0-9_-]+/', '-', $gen['title'] ?: $tool['label']);
$slug = trim($slug, '-') ?: 'generation';
$slug = mb_substr($slug, 0, 80);
$date = date('Y-m-d', strtotime($gen['created_at']));
$base_filename = $date . '_' . $gen['tool_type'] . '_' . $slug;

$content = (string)$gen['output_text'];

if ($fmt === 'md') {
    header('Content-Type: text/markdown; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $base_filename . '.md"');
    echo "<!-- Généré par Assokit Copilote IA · " . htmlspecialchars($date, ENT_QUOTES, 'UTF-8') . " -->\n\n";
    echo $content;
} elseif ($fmt === 'txt') {
    header('Content-Type: text/plain; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $base_filename . '.txt"');
    // Strip markdown basique
    $txt = preg_replace('/\*\*(.+?)\*\*/s', '$1', $content);
    $txt = preg_replace('/\*(.+?)\*/s',     '$1', $txt);
    $txt = preg_replace('/^#+\s*/m',        '',  $txt);
    $txt = preg_replace('/^- /m',           '• ', $txt);
    echo $txt;
} else { // html
    header('Content-Type: text/html; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $base_filename . '.html"');
    $html_body = ak_ai_md_to_html($content);
    $title_h   = htmlspecialchars($gen['title'] ?: $tool['label'], ENT_QUOTES, 'UTF-8');
    echo "<!DOCTYPE html>\n<html lang=\"fr\"><head><meta charset=\"UTF-8\"><title>{$title_h}</title>";
    echo "<style>body{font-family:Geist,system-ui,sans-serif;max-width:780px;margin:40px auto;padding:0 20px;line-height:1.65;color:#0F172A;}h1,h2,h3{margin-top:24px;}ul{padding-left:22px;}.meta{color:#64748B;font-size:13px;margin-bottom:24px;border-bottom:1px solid #E2E8F0;padding-bottom:14px;}</style>";
    echo "</head><body>";
    echo "<div class=\"meta\">{$title_h} · " . htmlspecialchars($tool['label'], ENT_QUOTES, 'UTF-8') . " · " . htmlspecialchars($date, ENT_QUOTES, 'UTF-8') . "</div>";
    echo $html_body;
    echo "</body></html>";
}
exit;
