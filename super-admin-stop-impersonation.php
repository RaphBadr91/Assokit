<?php
/**
 * super-admin-stop-impersonation.php
 * URL : /super-admin/incarner-stop
 *   - POST (bannière « Revenir à mon compte », jeton CSRF) : arrête l'incarnation en cours et
 *     redirige vers /super-admin ;
 *   - GET : page de sortie. C'est là qu'arrivent /connexion et /super-admin/incarner pendant une
 *     incarnation, pour que le Fondateur ait toujours un chemin de retour vers son compte.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/impersonation-helpers.php';

require_login();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    if (!is_impersonating()) {
        header('Location: /super-admin');
        exit;
    }
    if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    $target = null;
    try {
        $st = $pdo->prepare("SELECT first_name, last_name, email, parent_org_id FROM users WHERE id = ? LIMIT 1");
        $st->execute([(int)get_impersonated_user_id()]);
        $target = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) {}
    $remaining = max(0, IMPERSONATION_MAX_DURATION - (time() - (int)($_SESSION['impersonation_started_at'] ?? 0)));
    $home = !empty($target['parent_org_id']) ? '/mairie-dashboard' : '/dashboard';
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    ?><!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="robots" content="noindex, nofollow">
  <title>Incarnation en cours — Assokit</title>
  <style>
    *{margin:0;padding:0;box-sizing:border-box}
    body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI','Geist',Roboto,sans-serif;background:#FAFAF9;color:#1C1917;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px}
    .card{background:#fff;border:1px solid #E7E5E4;border-top:4px solid #EA580C;border-radius:16px;padding:36px;max-width:460px;width:100%;box-shadow:0 10px 30px rgba(0,0,0,0.04)}
    h1{font-size:20px;font-weight:600;margin-bottom:10px}
    p{font-size:14px;line-height:1.6;color:#44403C;margin-bottom:18px}
    .btn{display:block;width:100%;padding:12px;border-radius:10px;font-size:14px;font-weight:600;text-align:center;text-decoration:none;font-family:inherit;cursor:pointer}
    .btn-stop{background:#EA580C;color:#fff;border:none}
    .btn-stop:hover{background:#C2410C}
    .btn-ghost{margin-top:10px;background:#fff;color:#44403C;border:1px solid #E7E5E4}
    .btn-ghost:hover{border-color:#EA580C;color:#EA580C}
  </style>
</head>
<body>
<div class="card">
  <h1>🎭 Incarnation en cours</h1>
  <p>Vous voyez l’espace de <strong><?= h(trim(($target['first_name'] ?? '?') . ' ' . ($target['last_name'] ?? ''))) ?></strong>
    (<?= h($target['email'] ?? '') ?>). Fin automatique dans <?= (int)ceil($remaining / 60) ?> min.</p>
  <form method="POST" action="/super-admin/incarner-stop">
    <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
    <button type="submit" class="btn btn-stop">← Revenir à mon compte</button>
  </form>
  <a href="<?= h($home) ?>" class="btn btn-ghost">Continuer dans son espace</a>
</div>
</body>
</html>
<?php
    exit;
}

if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
    // Jeton périmé (page restée ouverte) : on repasse par la page de sortie plutôt que de bloquer
    if (is_impersonating()) {
        header('Location: /super-admin/incarner-stop');
        exit;
    }
    http_response_code(419);
    exit('Session expirée — rechargez la page.');
}

if (is_impersonating()) {
    stop_impersonation(false);
}

header('Location: /super-admin?impersonation_stopped=1');
exit;
