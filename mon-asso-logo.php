<?php
/**
 * mon-asso-logo.php
 * Upload du logo de l'asso pour ses factures PDF
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes-layout.php';

require_login();
$user = current_user();
if (empty($user['org_id'])) { http_response_code(403); die('Aucune asso.'); }
$org_id = (int)$user['org_id'];

$is_admin = false;
try {
    $stmt = $pdo->prepare("SELECT role FROM users WHERE id = :id");
    $stmt->execute([':id' => (int)$user['id']]);
    $is_admin = in_array($stmt->fetchColumn(), ['admin', 'coordinator'], true);
} catch (Throwable $e) {}
if (!$is_admin) { http_response_code(403); die('Accès refusé.'); }

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token'];

$flash = $_SESSION['flash_asso_logo'] ?? null;
unset($_SESSION['flash_asso_logo']);

// Traitement POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        http_response_code(419); exit('CSRF');
    }

    $action = $_POST['action'] ?? 'upload';

    if ($action === 'delete') {
        // Suppression logo
        try {
            $stmt = $pdo->prepare("SELECT logo_path FROM organizations WHERE id = :id");
            $stmt->execute([':id' => $org_id]);
            $current = $stmt->fetchColumn();

            if ($current) {
                $full = __DIR__ . $current;
                if (file_exists($full)) @unlink($full);
            }

            $pdo->prepare("UPDATE organizations SET logo_path = NULL, logo_uploaded_at = NULL WHERE id = :id")
                ->execute([':id' => $org_id]);

            $_SESSION['flash_asso_logo'] = ['type' => 'success', 'message' => '🗑 Logo supprimé.'];
        } catch (Throwable $e) {
            $_SESSION['flash_asso_logo'] = ['type' => 'error', 'message' => 'Erreur : ' . $e->getMessage()];
        }
        header('Location: /mon-asso-logo'); exit;
    }

    // ─── UPLOAD ───
    if (empty($_FILES['logo']) || $_FILES['logo']['error'] !== UPLOAD_ERR_OK) {
        $_SESSION['flash_asso_logo'] = ['type' => 'error', 'message' => 'Aucun fichier reçu.'];
        header('Location: /mon-asso-logo'); exit;
    }

    $file = $_FILES['logo'];
    $size = $file['size'];

    // Limite 5 Mo
    if ($size > 5 * 1024 * 1024) {
        $_SESSION['flash_asso_logo'] = ['type' => 'error', 'message' => 'Fichier trop volumineux (max 5 Mo).'];
        header('Location: /mon-asso-logo'); exit;
    }

    // Type
    $allowed = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/jpg' => 'jpg', 'image/gif' => 'gif'];
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    if (!isset($allowed[$mime])) {
        $_SESSION['flash_asso_logo'] = ['type' => 'error', 'message' => 'Format invalide. Utilise PNG, JPG ou GIF.'];
        header('Location: /mon-asso-logo'); exit;
    }

    $ext = $allowed[$mime];

    // Dossier
    $dir = __DIR__ . '/uploads/asso-logos';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);

    // Supprime l'ancien si présent
    $stmt = $pdo->prepare("SELECT logo_path FROM organizations WHERE id = :id");
    $stmt->execute([':id' => $org_id]);
    $old = $stmt->fetchColumn();
    if ($old) {
        $old_full = __DIR__ . $old;
        if (file_exists($old_full)) @unlink($old_full);
    }

    // Nouveau nom
    $filename = 'asso-' . $org_id . '-' . bin2hex(random_bytes(8)) . '.' . $ext;
    $full_path = $dir . '/' . $filename;

    if (!move_uploaded_file($file['tmp_name'], $full_path)) {
        $_SESSION['flash_asso_logo'] = ['type' => 'error', 'message' => 'Erreur lors de l\'enregistrement.'];
        header('Location: /mon-asso-logo'); exit;
    }

    @chmod($full_path, 0644);

    // ─────────────────────────────────────────────
    // REDIMENSIONNEMENT physique pour avoir un logo pro
    // (max 400px de large, hauteur auto)
    // ─────────────────────────────────────────────
    if (extension_loaded('gd')) {
        try {
            $info = @getimagesize($full_path);
            if ($info && $info[0] > 400) {
                $src_w = $info[0];
                $src_h = $info[1];
                $new_w = 400;
                $new_h = (int) round($src_h * ($new_w / $src_w));

                $src = null;
                switch ($mime) {
                    case 'image/png': $src = @imagecreatefrompng($full_path); break;
                    case 'image/jpeg':
                    case 'image/jpg': $src = @imagecreatefromjpeg($full_path); break;
                    case 'image/gif': $src = @imagecreatefromgif($full_path); break;
                }

                if ($src) {
                    $dst = imagecreatetruecolor($new_w, $new_h);
                    // Conserver la transparence pour PNG/GIF
                    if ($mime === 'image/png' || $mime === 'image/gif') {
                        imagealphablending($dst, false);
                        imagesavealpha($dst, true);
                        $transparent = imagecolorallocatealpha($dst, 255, 255, 255, 127);
                        imagefilledrectangle($dst, 0, 0, $new_w, $new_h, $transparent);
                    }
                    imagecopyresampled($dst, $src, 0, 0, 0, 0, $new_w, $new_h, $src_w, $src_h);

                    switch ($mime) {
                        case 'image/png': imagepng($dst, $full_path, 8); break;
                        case 'image/jpeg':
                        case 'image/jpg': imagejpeg($dst, $full_path, 90); break;
                        case 'image/gif': imagegif($dst, $full_path); break;
                    }
                    imagedestroy($src);
                    imagedestroy($dst);
                }
            }
        } catch (Throwable $e) {
            error_log('[ASSO LOGO RESIZE] ' . $e->getMessage());
            // Pas grave si ça plante, on garde le fichier original
        }
    }

    $relative = '/uploads/asso-logos/' . $filename;
    $pdo->prepare("UPDATE organizations SET logo_path = :p, logo_uploaded_at = NOW() WHERE id = :id")
        ->execute([':p' => $relative, ':id' => $org_id]);

    $_SESSION['flash_asso_logo'] = ['type' => 'success', 'message' => '✅ Logo uploadé avec succès. Il apparaîtra sur toutes vos prochaines factures.'];
    header('Location: /mon-asso-logo'); exit;
}

// Récup logo actuel
$stmt = $pdo->prepare("SELECT name, logo_path, logo_uploaded_at FROM organizations WHERE id = :id");
$stmt->execute([':id' => $org_id]);
$org = $stmt->fetch(PDO::FETCH_ASSOC);

render_head('Logo association');
render_sidebar('factures');
?>

<div class="main">
    <nav class="crumbs">
        <a href="/mon-asso-factures">Mes factures</a>
        <span class="sep">›</span>
        <span class="current">Logo</span>
    </nav>

    <div class="main-head">
        <div>
            <h1 class="page-title">🎨 Logo de l'association</h1>
            <div class="page-sub">Sera affiché sur tous vos PDF de factures</div>
        </div>
        <div>
            <a href="/mon-asso-factures" class="btn btn-ghost">← Retour</a>
        </div>
    </div>

    <?php if ($flash): ?>
        <div class="alert <?= $flash['type'] === 'success' ? 'alert-success' : 'alert-error' ?>" style="margin-bottom:16px;">
            <?= h($flash['message']) ?>
        </div>
    <?php endif; ?>

    <!-- Aperçu -->
    <div class="card" style="padding:30px; margin-bottom:16px; text-align:center; background:linear-gradient(135deg, #ECFDF5 0%, #D1FAE5 100%);">
        <h3 style="margin:0 0 14px 0; font-size:14px; color:#065F46; text-transform:uppercase; letter-spacing:0.5px;">Logo actuel</h3>
        <?php if (!empty($org['logo_path'])): ?>
            <div style="background:white; padding:30px; border-radius:12px; max-width:400px; margin:0 auto; box-shadow:0 2px 8px rgba(0,0,0,0.08);">
                <img src="<?= h($org['logo_path']) ?>" alt="Logo" style="max-width:100%; max-height:200px; height:auto; display:block; margin:0 auto;">
            </div>
            <div style="margin-top:12px; font-size:12px; color:#065F46;">
                Uploadé le <?= h(date('d/m/Y H:i', strtotime($org['logo_uploaded_at']))) ?>
            </div>
            <form method="POST" action="/mon-asso-logo" style="margin-top:14px; display:inline;">
                <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                <input type="hidden" name="action" value="delete">
                <button type="submit" onclick="return confirm('Supprimer le logo ?')" class="btn btn-ghost" style="background:#FEE2E2; color:#991B1B; border-color:#FCA5A5;">
                    🗑 Supprimer le logo
                </button>
            </form>
        <?php else: ?>
            <div style="font-size:48px; margin-bottom:8px;">🖼</div>
            <div style="color:#065F46;">Pas encore de logo. Uploade-en un ci-dessous.</div>
        <?php endif; ?>
    </div>

    <!-- Upload -->
    <div class="card" style="padding:22px;">
        <h3 style="margin:0 0 16px 0; font-size:15px;">📤 Uploader un nouveau logo</h3>

        <form method="POST" action="/mon-asso-logo" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
            <input type="hidden" name="action" value="upload">

            <div style="border:2px dashed #D1D5DB; border-radius:12px; padding:40px; text-align:center; background:#F9FAFB; margin-bottom:16px;">
                <div style="font-size:36px; margin-bottom:8px;">📂</div>
                <input type="file" name="logo" accept="image/png,image/jpeg,image/jpg,image/gif" required style="margin:0 auto; display:block;">
                <div style="margin-top:8px; font-size:12px; color:#6B7280;">
                    Formats acceptés : PNG, JPG, GIF · Max 5 Mo
                </div>
            </div>

            <div class="alert alert-info" style="background:#EFF6FF; border-left:4px solid #3B82F6; padding:12px 16px; margin-bottom:16px; border-radius:8px; font-size:13px; color:#1E40AF;">
                <strong>💡 Conseils pour un bon logo :</strong>
                <ul style="margin:8px 0 0 18px; padding:0; list-style:disc;">
                    <li>Format <strong>PNG transparent</strong> recommandé</li>
                    <li>Dimensions idéales : <strong>400 x 200 px</strong> (ratio 2:1)</li>
                    <li>Le logo sera affiché en haut à gauche du PDF</li>
                    <li>Sa taille max sur la facture : <strong>22 mm de hauteur</strong> (rendu pro)</li>
                </ul>
            </div>

            <div style="display:flex; gap:10px; justify-content:flex-end;">
                <a href="/mon-asso-factures" class="btn btn-ghost">Annuler</a>
                <button type="submit" class="btn btn-primary" style="padding:10px 20px;">📤 Uploader le logo</button>
            </div>
        </form>
    </div>

</div>

<?php render_foot(); ?>
