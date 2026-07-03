<?php
/**
 * application.php — Page publique "Installer l'application Assokit" (PWA)
 * Route : /application (via .htaccess generique /x -> x.php)
 */
require_once __DIR__ . '/includes-public.php';

render_public_head([
    'title'       => 'L\'application Assokit',
    'description' => 'Installez Assokit sur votre iPhone ou Android : acc&egrave;s rapide, plein &eacute;cran, m&ecirc;me hors-ligne. G&eacute;rez votre association depuis votre t&eacute;l&eacute;phone.',
    'path'        => '/application',
]);
render_public_nav('');
?>
<section class="pub-hero" style="padding: 50px 0 20px;">
  <div class="pub-container" style="text-align:center;">
    <img src="/icons/icon-512.png" alt="Assokit" width="76" height="76" style="border-radius:18px;box-shadow:0 8px 24px rgba(5,150,105,.22);margin-bottom:18px;">
    <h1 class="pub-h1" style="font-size:38px;">Assokit dans votre poche</h1>
    <p class="pub-tagline" style="max-width:560px;margin:10px auto 0;">Installez Assokit sur votre t&eacute;l&eacute;phone en 10 secondes : ic&ocirc;ne sur l'&eacute;cran d'accueil, plein &eacute;cran, et un acc&egrave;s ultra-rapide &agrave; vos projets, adh&eacute;rents et factures &mdash; m&ecirc;me hors-ligne.</p>
  </div>
</section>

<section class="pub-section" style="padding-top:24px;">
  <div class="pub-container">
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:20px;max-width:820px;margin:0 auto;">

      <!-- iOS -->
      <div style="background:#fff;border:1px solid var(--c-border);border-radius:16px;padding:26px;">
        <div style="font-size:15px;font-weight:700;color:var(--c-encre);display:flex;align-items:center;gap:8px;margin-bottom:16px;">
          <span style="font-size:22px;">&#63743;</span> iPhone &amp; iPad
        </div>
        <ol style="margin:0;padding-left:20px;color:var(--c-text-2);font-size:14.5px;line-height:1.9;">
          <li>Ouvrez <strong>assokit.fr</strong> dans <strong>Safari</strong>.</li>
          <li>Appuyez sur l'ic&ocirc;ne <strong>Partager</strong> <span style="white-space:nowrap;">(&#9650; dans un carr&eacute;)</span>.</li>
          <li>Choisissez <strong>&laquo;&nbsp;Sur l'&eacute;cran d'accueil&nbsp;&raquo;</strong>.</li>
          <li>Appuyez sur <strong>Ajouter</strong>. C'est fait&nbsp;!</li>
        </ol>
      </div>

      <!-- Android -->
      <div style="background:#fff;border:1px solid var(--c-border);border-radius:16px;padding:26px;">
        <div style="font-size:15px;font-weight:700;color:var(--c-encre);display:flex;align-items:center;gap:8px;margin-bottom:16px;">
          <span style="font-size:22px;">&#129302;</span> Android
        </div>
        <ol style="margin:0;padding-left:20px;color:var(--c-text-2);font-size:14.5px;line-height:1.9;">
          <li>Ouvrez <strong>assokit.fr</strong> dans <strong>Chrome</strong>.</li>
          <li>Une banni&egrave;re <strong>&laquo;&nbsp;Installer Assokit&nbsp;&raquo;</strong> appara&icirc;t &mdash; appuyez dessus.</li>
          <li>Sinon, menu <strong>&#8942;</strong> puis <strong>&laquo;&nbsp;Installer l'application&nbsp;&raquo;</strong>.</li>
          <li>Confirmez. L'ic&ocirc;ne s'ajoute &agrave; votre &eacute;cran d'accueil&nbsp;!</li>
        </ol>
      </div>

    </div>

    <div style="max-width:820px;margin:22px auto 0;background:var(--c-creme);border-radius:14px;padding:20px 24px;font-size:14px;color:var(--c-text-2);line-height:1.7;">
      &#128274; <strong style="color:var(--c-encre);">M&ecirc;me application, m&ecirc;me s&eacute;curit&eacute;.</strong> L'application est la version install&eacute;e de votre espace Assokit&nbsp;: vos donn&eacute;es restent h&eacute;berg&eacute;es en France, en HTTPS, conformes RGPD. Aucune installation depuis un store n'est n&eacute;cessaire.
    </div>

    <div style="text-align:center;margin-top:30px;">
      <a href="/connexion" class="pub-btn pub-btn-primary pub-btn-lg">Ouvrir mon espace</a>
    </div>
  </div>
</section>
<?php
render_public_footer();
if (function_exists('render_public_foot')) { render_public_foot(); }
