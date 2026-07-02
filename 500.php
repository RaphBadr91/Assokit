<?php
/**
 * 500.php — Erreur serveur SEO-friendly
 */
http_response_code(500);
?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Erreur serveur (500) · Assokit</title>
<meta name="robots" content="noindex,nofollow">
<style>
* { box-sizing: border-box; margin: 0; padding: 0; }
body {
    font-family: 'Inter', system-ui, sans-serif;
    background: linear-gradient(135deg, #FAF8F5 0%, #FEF3C7 100%);
    min-height: 100vh;
    display: flex; align-items: center; justify-content: center;
    color: #0F172A; padding: 20px;
}
.wrap {
    max-width: 600px; width: 100%; text-align: center;
    background: white; border-radius: 18px; padding: 48px 32px;
    box-shadow: 0 4px 24px rgba(15, 23, 42, 0.06); border: 1px solid #E2E8F0;
}
.code { font-size: 96px; font-weight: 800; color: #DC2626; line-height: 1; }
h1 { font-size: 24px; margin: 12px 0; }
p { color: #64748B; font-size: 15px; line-height: 1.6; margin-bottom: 28px; }
.btn {
    display: inline-block; background: #0F172A; color: white;
    padding: 12px 24px; border-radius: 10px; text-decoration: none;
    font-weight: 600; font-size: 14px;
}
</style>
</head>
<body>
<div class="wrap">
    <div style="font-size:64px;margin-bottom:16px;">⚠️</div>
    <div class="code">500</div>
    <h1>Erreur serveur temporaire</h1>
    <p>Notre service rencontre une difficulté momentanée. Nous travaillons pour résoudre cela rapidement. Réessayez dans quelques instants.</p>
    <a href="/" class="btn">← Retour à l'accueil</a>
</div>
</body>
</html>
