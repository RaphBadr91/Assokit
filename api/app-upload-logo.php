<?php
/**
 * api/app-upload-logo.php — Upload du logo de l'org depuis l'app (natif, base64).
 * Reproduit mon-asso-logo.php (admin/coord, GD resize, organizations.logo_path).
 * NE MODIFIE PAS le site.
 */
require __DIR__ . '/_app-write-boot.php';
@require_once __DIR__ . '/../rate-limit-helper.php';
if (function_exists('ak_rate_limit_or_die')) ak_rate_limit_or_die('app_logo', 5, 60, (string) $uid);

$role = (string) ($user['role'] ?? '');
if (!in_array($role, ['admin', 'coordinator'], true)) {
    app_fail(403, 'role', 'Réservé aux administrateurs.');
}

$b64 = (string) ($input['image'] ?? '');
$mime = (string) ($input['mime'] ?? 'image/jpeg');
if ($b64 === '') app_fail(422, 'invalid', 'Aucune image.');

$data = base64_decode($b64, true);
if ($data === false || strlen($data) < 100) app_fail(422, 'invalid', 'Image illisible.');
if (strlen($data) > 5 * 1024 * 1024) app_fail(422, 'toobig', 'Image trop lourde (max 5 Mo).');

// Type réel détecté sur les octets (le MIME annoncé par le client n'est pas fiable).
$info = function_exists('getimagesizefromstring') ? @getimagesizefromstring($data) : false;
$real = is_array($info) ? (string) ($info['mime'] ?? '') : '';
$ext_map = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/gif' => 'gif'];
if (!isset($ext_map[$real])) app_fail(422, 'invalid', 'Format d\'image non supporté (PNG, JPEG ou GIF).');
$ext = $ext_map[$real];

try {
    $dir = __DIR__ . '/../uploads/asso-logos';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $name = 'asso-' . $org_id . '-' . bin2hex(random_bytes(8)) . '.' . $ext;
    $abs = $dir . '/' . $name;

    // Redimensionnement GD (max 400px) si disponible
    $saved = false;
    if (function_exists('imagecreatefromstring')) {
        $src = @imagecreatefromstring($data);
        if ($src !== false) {
            $w = imagesx($src); $h = imagesy($src);
            $max = 400;
            if ($w > $max || $h > $max) {
                $ratio = min($max / $w, $max / $h);
                $nw = (int) round($w * $ratio); $nh = (int) round($h * $ratio);
                $dst = imagecreatetruecolor($nw, $nh);
                if ($ext === 'png' || $ext === 'gif') {
                    imagecolortransparent($dst, imagecolorallocatealpha($dst, 0, 0, 0, 127));
                    imagealphablending($dst, false); imagesavealpha($dst, true);
                }
                imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
                $src = $dst;
            }
            if ($ext === 'png') $saved = imagepng($src, $abs);
            elseif ($ext === 'gif') $saved = imagegif($src, $abs);
            else $saved = imagejpeg($src, $abs, 88);
        }
    }
    // Jamais d'écriture des octets bruts : si GD ne sait pas relire/réencoder l'image, on refuse.
    if (!$saved) app_fail(422, 'invalid', 'Image illisible.');
    @chmod($abs, 0644);

    $web = '/uploads/asso-logos/' . $name;
    $pdo->prepare("UPDATE organizations SET logo_path = ?, logo_uploaded_at = NOW() WHERE id = ?")->execute([$web, $org_id]);

    echo json_encode(['ok' => true, 'logo' => 'https://assokit.fr' . $web, 'message' => 'Logo mis à jour.'], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('[app-upload-logo] ' . $e->getMessage());
    app_fail(500, 'server', 'Impossible d\'enregistrer le logo.');
}
