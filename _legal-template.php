<?php
/**
 * Template commun pour les pages légales (mentions, CGU, confidentialité).
 * Utilise les variables $page_title et $page_content.
 */
$page_title = $page_title ?? 'Page légale';
$page_content = $page_content ?? '';
?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="index, follow">
<title><?= htmlspecialchars($page_title) ?> — Assokit</title>
<link rel="icon" type="image/svg+xml" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'%3E%3Crect x='2' y='2' width='28' height='28' rx='7' fill='%23059669'/%3E%3Ccircle cx='22' cy='22' r='4.5' fill='%23FFFFFF'/%3E%3C/svg%3E">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Geist:wght@400;500;600&display=swap" rel="stylesheet">
<style>
* { box-sizing: border-box; margin: 0; padding: 0; }
:root {
  --acc: #059669; --acc-dark: #047857;
  --ink: #0A0A0B; --ink-2: #3F3F46; --ink-3: #71717A;
  --bg: #FFFFFF; --bg-2: #FAFAF9;
  --border: rgba(0,0,0,0.08);
}
body {
  font-family: 'Geist', -apple-system, BlinkMacSystemFont, sans-serif;
  background: var(--bg); color: var(--ink);
  font-size: 15px; line-height: 1.7; letter-spacing: -0.005em;
  -webkit-font-smoothing: antialiased;
}
.nav {
  border-bottom: 1px solid var(--border); padding: 16px 0;
  background: var(--bg);
  position: sticky; top: 0; z-index: 50;
  backdrop-filter: blur(10px);
}
.nav-inner {
  max-width: 760px; margin: 0 auto; padding: 0 24px;
  display: flex; justify-content: space-between; align-items: center;
}
.logo { display: inline-flex; align-items: center; gap: 10px; font-size: 16px; font-weight: 500; color: var(--ink); text-decoration: none; letter-spacing: -0.02em; }
.logo-mark { width: 22px; height: 22px; background: var(--acc); border-radius: 6px; position: relative; }
.logo-mark::after { content: ""; position: absolute; right: 4px; bottom: 4px; width: 7px; height: 7px; background: white; border-radius: 50%; }
.back-link { font-size: 13.5px; color: var(--ink-3); text-decoration: none; }
.back-link:hover { color: var(--ink); }

.page { max-width: 760px; margin: 0 auto; padding: 48px 24px 96px; }
h1 { font-size: 32px; font-weight: 500; letter-spacing: -0.02em; margin-bottom: 8px; line-height: 1.15; }
.date { font-size: 13px; color: var(--ink-3); margin-bottom: 40px; display: block; }
h2 { font-size: 18px; font-weight: 500; letter-spacing: -0.015em; margin: 36px 0 12px; color: var(--ink); }
p { margin-bottom: 12px; color: var(--ink-2); }
p.lead { font-size: 16px; color: var(--ink); background: var(--bg-2); padding: 16px 20px; border-radius: 10px; margin-bottom: 28px; line-height: 1.55; }
ul { padding-left: 20px; margin-bottom: 14px; }
ul li { color: var(--ink-2); margin-bottom: 6px; }
strong { color: var(--ink); font-weight: 500; }
a { color: var(--acc); text-decoration: none; }
a:hover { text-decoration: underline; }

.footer {
  border-top: 1px solid var(--border);
  padding: 32px 24px; font-size: 12.5px; color: var(--ink-3);
  max-width: 760px; margin: 0 auto;
  display: flex; justify-content: space-between; flex-wrap: wrap; gap: 14px;
}
.footer-links { display: flex; gap: 16px; flex-wrap: wrap; }
.footer-links a { color: var(--ink-3); }
.footer-links a:hover { color: var(--ink); }
</style>
</head>
<body>

<nav class="nav">
  <div class="nav-inner">
    <a href="/" class="logo">
      <span class="logo-mark"></span>
      <span>Assokit</span>
    </a>
    <a href="/" class="back-link">← Retour à l'accueil</a>
  </div>
</nav>

<main class="page">
  <h1><?= htmlspecialchars($page_title) ?></h1>
  <span class="date">Dernière mise à jour : avril 2026</span>
  <?= $page_content ?>
</main>

<footer class="footer">
  <div>© 2026 Assokit. Hébergé en France 🇫🇷</div>
  <div class="footer-links">
    <a href="/mentions-legales">Mentions légales</a>
    <a href="/confidentialite">Confidentialité</a>
    <a href="/cgu">CGU</a>
    <a href="mailto:contact@assokit.fr">Contact</a>
  </div>
</footer>

</body>
</html>
