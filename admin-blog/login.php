<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/auth.php';

send_security_headers();
auth_start_session();

// Si déjà connecté, redirige
if (auth_is_logged_in()) {
    header('Location: /admin-blog/index.php');
    exit;
}

$error = '';
$redirect = $_GET['redirect'] ?? '/admin-blog/index.php';
$redirect = filter_var($redirect, FILTER_VALIDATE_URL) ? '/admin-blog/index.php' : $redirect;
if (strpos($redirect, '/admin-blog/') !== 0) {
    $redirect = '/admin-blog/index.php';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check($_POST['_csrf'] ?? '')) {
        $error = 'Session expirée, recharge la page.';
    } else {
        $password = $_POST['password'] ?? '';
        if (auth_login($password)) {
            header("Location: {$redirect}");
            exit;
        }
        $error = 'Mot de passe incorrect.';
    }
}
?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<title>Connexion · Assokit Admin</title>
<link rel="stylesheet" href="/admin-blog/assets/admin.css">
</head>
<body class="auth-page">
<div class="auth-card">
    <h1>🔐 Admin Assokit</h1>
    <p class="dim">Espace fondateur uniquement.</p>

    <?php if ($error): ?>
        <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="POST" autocomplete="off">
        <input type="hidden" name="_csrf" value="<?= htmlspecialchars(csrf_token()) ?>">
        <label class="form-label">
            Mot de passe
            <input type="password" name="password" required autofocus>
        </label>
        <button type="submit" class="btn-primary">Se connecter</button>
    </form>

    <p class="auth-footer">
        <a href="/" class="dim">← Retour au site public</a>
    </p>
</div>
</body>
</html>
