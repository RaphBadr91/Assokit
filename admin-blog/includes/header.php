<?php
/** Header commun de l'admin */
if (!isset($page_title)) {
    $page_title = 'Admin Blog';
}
$current_path = $_SERVER['SCRIPT_NAME'] ?? '';
function nav_active(string $page): string {
    return strpos($_SERVER['SCRIPT_NAME'] ?? '', '/' . $page) !== false ? ' active' : '';
}
?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<title><?= htmlspecialchars($page_title) ?> · Assokit Admin</title>
<link rel="stylesheet" href="/admin-blog/assets/admin.css">
<link rel="icon" type="image/svg+xml" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>🔧</text></svg>">
</head>
<body>

<header class="topbar">
    <div class="topbar-inner">
        <a href="/admin-blog/" class="brand">
            <span class="brand-icon">🔧</span>
            <span class="brand-text">Assokit Admin</span>
        </a>
        <nav class="topnav">
            <a href="/admin-blog/index.php" class="nav-link<?= nav_active('index.php') ?>">📊 Dashboard</a>
            <a href="/admin-blog/articles.php" class="nav-link<?= nav_active('articles.php') ?>">📰 Articles</a>
            <a href="/admin-blog/generate.php" class="nav-link<?= nav_active('generate.php') ?>">✨ Générer</a>
            <a href="/admin-blog/topic-suggest.php" class="nav-link<?= nav_active('topic-suggest.php') ?>">🤖 Suggérer IA</a>
            <a href="/admin-blog/topics.php" class="nav-link<?= nav_active('topics.php') ?>">💡 Sujets</a>
            <a href="/admin-blog/seo.php" class="nav-link<?= nav_active('seo.php') ?>">🔍 SEO</a>
            <a href="/admin-blog/settings.php" class="nav-link<?= nav_active('settings.php') ?>">⚙️ Paramètres</a>
        </nav>
        <div class="topbar-right">
            <a href="https://assokit.fr/blog" target="_blank" class="btn-ghost-sm">↗ Voir le blog</a>
            <a href="/admin-blog/logout.php" class="btn-ghost-sm">Déconnexion</a>
        </div>
    </div>
</header>

<main class="page">
