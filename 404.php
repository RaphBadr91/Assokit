<?php
/**
 * 404.php — Page 404 SEO-friendly
 * Ne perd pas le PageRank, propose des liens utiles, garde les visiteurs sur le site.
 */

require_once __DIR__ . '/seo-helpers.php';

http_response_code(404);
?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<?php
ak_seo_render([
    'title' => 'Page introuvable (404)',
    'description' => 'La page que vous cherchez n\'existe plus ou n\'a jamais existé. Retrouvez tout Assokit depuis l\'accueil.',
    'canonical' => '/404',
    'noindex' => true,
]);
?>
<style>
* { box-sizing: border-box; margin: 0; padding: 0; }
body {
    font-family: 'Inter', system-ui, -apple-system, sans-serif;
    background: linear-gradient(135deg, #FAF8F5 0%, #F0FDF4 100%);
    min-height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #0F172A;
    padding: 20px;
}
.wrap {
    max-width: 600px;
    width: 100%;
    text-align: center;
    background: white;
    border-radius: 18px;
    padding: 48px 32px;
    box-shadow: 0 4px 24px rgba(15, 23, 42, 0.06);
    border: 1px solid #E2E8F0;
}
.code {
    font-size: 96px;
    font-weight: 800;
    color: #059669;
    line-height: 1;
    letter-spacing: -0.04em;
    margin-bottom: 8px;
}
h1 {
    font-size: 24px;
    font-weight: 700;
    margin-bottom: 12px;
}
p {
    color: #64748B;
    font-size: 15px;
    line-height: 1.6;
    margin-bottom: 28px;
}
.btn {
    display: inline-block;
    background: linear-gradient(180deg, #059669 0%, #047857 100%);
    color: white;
    padding: 12px 24px;
    border-radius: 10px;
    text-decoration: none;
    font-weight: 600;
    font-size: 14px;
    margin: 4px;
    transition: transform 0.15s;
}
.btn:hover { transform: translateY(-1px); }
.btn-ghost {
    background: white;
    color: #475569;
    border: 1px solid #E2E8F0;
}
.links {
    margin-top: 28px;
    padding-top: 24px;
    border-top: 1px solid #F1F5F9;
}
.links-title {
    font-size: 11px;
    color: #94A3B8;
    text-transform: uppercase;
    letter-spacing: 0.08em;
    margin-bottom: 14px;
    font-weight: 700;
}
.links a {
    display: inline-block;
    margin: 0 10px;
    color: #059669;
    text-decoration: none;
    font-size: 14px;
    font-weight: 500;
}
.links a:hover { text-decoration: underline; }
.emoji { font-size: 64px; margin-bottom: 16px; }
</style>
</head>
<body>
<div class="wrap">
    <div class="emoji">🌿</div>
    <div class="code">404</div>
    <h1>Cette page n'existe pas</h1>
    <p>La page que vous cherchez a peut-être été déplacée ou n'a jamais existé. Pas de panique, voici quelques pistes pour continuer votre visite.</p>

    <a href="/" class="btn">← Retour à l'accueil</a>
    <a href="/contact" class="btn btn-ghost">Nous contacter</a>

    <div class="links">
        <div class="links-title">Liens populaires</div>
        <a href="/tarifs">Tarifs</a>
        <a href="/signup">Inscription gratuite</a>
        <a href="/fonctionnalites">Fonctionnalités</a>
        <a href="/connexion">Se connecter</a>
    </div>
</div>
</body>
</html>
