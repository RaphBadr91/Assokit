<?php
/**
 * indexnow-key.php — Sert le fichier de validation IndexNow à la racine du site
 * 
 * IndexNow exige qu'un fichier {key}.txt existe à la racine du domaine et contienne
 * uniquement la clé. Ce fichier sert dynamiquement la clé stockée en BDD.
 * 
 * Routing à ajouter dans le .htaccess RACINE de assokit.fr :
 *   RewriteRule ^([a-f0-9]{32})\.txt$ indexnow-key.php?key=$1 [L]
 * 
 * EXEMPLE d'URL servie : https://assokit.fr/abc123def456...txt
 */

require_once __DIR__ . '/admin-blog/config.php';
require_once __DIR__ . '/admin-blog/includes/db.php';

$requested = trim((string)($_GET['key'] ?? ''));
$stored    = trim((string) config_get('indexnow_key', ''));

header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: public, max-age=3600');

if ($requested && $stored && hash_equals($stored, $requested)) {
    echo $stored;
    exit;
}

// Clé non trouvée : 404 propre
http_response_code(404);
echo 'Not found';
