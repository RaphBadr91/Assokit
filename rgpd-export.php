<?php
/**
 * ASSOKIT — Export RGPD des données personnelles (art. 20 portabilité)
 * URL : /rgpd-export — télécharge un JSON des données du user connecté
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes-layout.php';
require_login();

$current = current_user();
$uid   = (int)$current['id'];
$email = $current['email'] ?? '';

$export = [
    'export_genere_le' => date('c'),
    'plateforme'       => 'Assokit',
    'mention'          => "Export de vos données personnelles conformément au RGPD (art. 15 & 20).",
];

// Helper : requête sûre (ignore si table absente)
$grab = function(string $sql, array $params) use ($pdo) {
    try {
        $st = $pdo->prepare($sql);
        $st->execute($params);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { return []; }
};

// 1) Profil (sans secrets)
try {
    $st = $pdo->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
    $st->execute([$uid]);
    $u = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    foreach (['password_hash','ics_token','must_change_password'] as $secret) unset($u[$secret]);
    $export['profil'] = $u;
} catch (Throwable $e) { $export['profil'] = []; }

// 2) Données liées (métadonnées, pas de secrets)
$export['mes_messages']        = $grab("SELECT id, project_id, body, created_at FROM project_messages WHERE user_id = ? ORDER BY created_at DESC LIMIT 1000", [$uid]);
$export['mes_fichiers']        = $grab("SELECT id, project_id, filename, filesize_bytes, created_at FROM project_files WHERE uploaded_by = ? ORDER BY id DESC LIMIT 1000", [$uid]);
$export['mes_documents_ia']    = $grab("SELECT id, project_id, doc_type, title, created_at FROM ai_generated_docs WHERE user_id = ? ORDER BY id DESC LIMIT 1000", [$uid]);
$export['historique_activite'] = $grab("SELECT event_type, event_action, ip, created_at FROM assokit_activity_log WHERE user_email = ? ORDER BY created_at DESC LIMIT 500", [$email]);

$filename = 'assokit-mes-donnees-' . date('Ymd') . '.json';
header('Content-Type: application/json; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('X-Content-Type-Options: nosniff');
echo json_encode($export, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
exit;
