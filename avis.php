<?php
/**
 * avis.php — Page publique "Avis clients / Témoignages"
 * Route : /avis
 * Témoignages réels de clients Assokit + schéma Review/AggregateRating (rich results Google).
 * Les avis affichés ici correspondent exactement à ceux fournis par les clients.
 */
require_once __DIR__ . '/includes-public.php';

// --- Témoignages réels (affichés à l'écran ET utilisés pour le schéma) ---
$reviews = [
    [
        'name'   => 'Ismael',
        'org'    => 'Association Ethnic City',
        'initial'=> 'I',
        'color'  => '#7C3AED',
        'rating' => 5,
        'quote'  => "Très content de pouvoir compter sur Assokit pour toute la communication en interne à l'association, pour tous nos événements de danse et surtout pour mieux programmer notre spectacle annuel.",
        'tag'    => 'Communication & événements',
    ],
    [
        'name'   => 'Nabil',
        'org'    => 'Association BS Outing',
        'initial'=> 'N',
        'color'  => '#059669',
        'rating' => 5,
        'quote'  => "Très pratique pour la comptabilité sur nos projets et le bilan annuel avec la compta analytique. Je n'ai plus besoin de payer 3 000 € de comptable, juste de l'expert pour valider. Ça change tout !",
        'tag'    => 'Comptabilité analytique',
    ],
    [
        'name'   => 'Mario',
        'org'    => 'Association Latitude91',
        'initial'=> 'M',
        'color'  => '#1D4ED8',
        'rating' => 5,
        'quote'  => "On utilise l'outil surtout pour nos projets, avec la possibilité de tout voir sur l'avancée et l'évolution de chaque projet. En plus, on peut communiquer spécifiquement avec l'équipe concernée par un projet, et pas tout le monde : c'est un réel plus.",
        'tag'    => 'Suivi de projets',
    ],
    [
        'name'   => 'Bassim',
        'org'    => 'RB Group (TPE)',
        'initial'=> 'B',
        'color'  => '#EA580C',
        'rating' => 5,
        'quote'  => "En tant qu'entrepreneur, je note tous mes rendez-vous directement dans l'outil : c'est plus simple et plus visible. Je peux aussi créer un projet par chantier client, y ajouter des collègues et suivre l'avancement. On insère nos factures sur le projet et, avec la comptabilité à la fin, c'est vraiment top.",
        'tag'    => 'Projets & facturation',
    ],
];

$rating_count = count($reviews);
$rating_avg   = $rating_count ? round(array_sum(array_column($reviews, 'rating')) / $rating_count, 1) : 5;

$breadcrumb = build_breadcrumb_jsonld([
    ['name' => 'Accueil', 'url' => '/'],
    ['name' => 'Avis clients', 'url' => '/avis'],
]);

// Schéma Product + AggregateRating + Reviews (les avis sont bien affichés sur cette page)
$review_schema = [
    '@context'    => 'https://schema.org',
    '@type'       => 'Product',
    'name'        => 'Assokit — Logiciel de gestion association & TPE',
    'description' => "Logiciel tout-en-un pour associations loi 1901 et TPE : adhérents, cotisations, facturation, comptabilité analytique, suivi de projets, communication et IA.",
    'brand'       => ['@type' => 'Brand', 'name' => 'Assokit'],
    'aggregateRating' => [
        '@type'       => 'AggregateRating',
        'ratingValue' => (string) $rating_avg,
        'reviewCount' => (string) $rating_count,
        'bestRating'  => '5',
        'worstRating' => '1',
    ],
    'review' => array_map(fn($r) => [
        '@type'         => 'Review',
        'author'        => ['@type' => 'Person', 'name' => $r['name'] . ' — ' . $r['org']],
        'reviewRating'  => ['@type' => 'Rating', 'ratingValue' => (string) $r['rating'], 'bestRating' => '5', 'worstRating' => '1'],
        'reviewBody'    => $r['quote'],
    ], $reviews),
];

render_public_head([
    'title'         => 'Avis clients Assokit · Ce qu\'en pensent associations & TPE',
    'description'   => 'Découvrez les avis d\'associations loi 1901 et de TPE qui utilisent Assokit au quotidien : comptabilité analytique, suivi de projets, communication d\'équipe et facturation.',
    'path'          => '/avis',
    'schema_jsonld' => [$breadcrumb, $review_schema],
]);

render_public_nav('');

/** Rendu de N étoiles pleines (SVG) */
function avis_stars(int $n): string {
    $star = '<svg viewBox="0 0 24 24" width="18" height="18" fill="#F59E0B" aria-hidden="true"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/></svg>';
    return str_repeat($star, max(0, min(5, $n)));
}
?>
<style>
.avis-hero { background:linear-gradient(180deg,#F0FDF4 0%,#fff 100%); padding:60px 0 34px; }
.avis-hero .pub-eyebrow { color:var(--c-emeraude-dark); }
.avis-summary { display:inline-flex; align-items:center; gap:14px; background:#fff; border:1px solid var(--c-border); border-radius:999px; padding:12px 22px; margin-top:22px; box-shadow:0 6px 20px rgba(5,150,105,.08); }
.avis-summary-stars { display:inline-flex; gap:2px; }
.avis-summary-num { font-weight:800; font-size:19px; color:var(--c-encre); }
.avis-summary-txt { font-size:14px; color:var(--c-text-2); }
.avis-grid { display:grid; grid-template-columns:repeat(2,1fr); gap:22px; max-width:960px; margin:0 auto; }
.avis-card { background:#fff; border:1px solid var(--c-border); border-radius:18px; padding:26px 26px 22px; display:flex; flex-direction:column; gap:14px; box-shadow:0 4px 16px rgba(15,23,42,.04); transition:transform .15s, box-shadow .15s; }
.avis-card:hover { transform:translateY(-3px); box-shadow:0 14px 34px rgba(15,23,42,.09); }
.avis-card-stars { display:inline-flex; gap:2px; }
.avis-card-quote { font-size:15.5px; line-height:1.6; color:#1F2937; margin:0; flex:1; }
.avis-card-quote::before { content:"\201C"; color:var(--c-emeraude); font-weight:800; }
.avis-card-quote::after { content:"\201D"; color:var(--c-emeraude); font-weight:800; }
.avis-card-foot { display:flex; align-items:center; gap:13px; border-top:1px solid var(--c-border-soft); padding-top:15px; }
.avis-avatar { width:44px; height:44px; border-radius:12px; display:flex; align-items:center; justify-content:center; color:#fff; font-weight:700; font-size:18px; flex:none; }
.avis-who-name { font-size:15px; font-weight:700; color:var(--c-encre); line-height:1.2; }
.avis-who-org { font-size:13px; color:var(--c-text-2); margin-top:2px; }
.avis-tag { margin-left:auto; font-size:11px; font-weight:700; color:var(--c-emeraude-dark); background:#ECFDF5; border:1px solid #D1FAE5; padding:5px 11px; border-radius:999px; white-space:nowrap; }
@media (max-width:760px){ .avis-grid{ grid-template-columns:1fr; } .avis-tag{ display:none; } }
</style>

<section class="avis-hero">
  <div class="pub-container pub-text-center">
    <div class="pub-breadcrumb" style="justify-content:center;margin-bottom:22px;">
      <a href="/">Accueil</a><span class="pub-breadcrumb-sep">›</span><strong style="color:var(--c-encre);">Avis clients</strong>
    </div>
    <span class="pub-eyebrow">⭐ Ils utilisent Assokit au quotidien</span>
    <h1 class="pub-h1" style="max-width:780px;margin-left:auto;margin-right:auto;">Ce qu'en disent les <em>associations & TPE</em></h1>
    <p class="pub-tagline" style="max-width:600px;">Des retours concrets de structures qui pilotent leurs projets, leur compta et leur communication avec Assokit.</p>
    <div class="avis-summary">
      <span class="avis-summary-stars"><?= avis_stars(5) ?></span>
      <span class="avis-summary-num"><?= number_format($rating_avg, 1, ',', ' ') ?>/5</span>
      <span class="avis-summary-txt">sur <?= (int) $rating_count ?> avis clients</span>
    </div>
  </div>
</section>

<section class="pub-section" style="padding-top:26px;">
  <div class="pub-container">
    <div class="avis-grid">
<?php foreach ($reviews as $r): ?>
      <article class="avis-card">
        <span class="avis-card-stars" aria-label="<?= (int) $r['rating'] ?> étoiles sur 5"><?= avis_stars((int) $r['rating']) ?></span>
        <p class="avis-card-quote"><?= pub_h($r['quote']) ?></p>
        <div class="avis-card-foot">
          <span class="avis-avatar" style="background:<?= pub_h($r['color']) ?>;"><?= pub_h($r['initial']) ?></span>
          <span>
            <span class="avis-who-name"><?= pub_h($r['name']) ?></span>
            <span class="avis-who-org"><?= pub_h($r['org']) ?></span>
          </span>
          <span class="avis-tag"><?= pub_h($r['tag']) ?></span>
        </div>
      </article>
<?php endforeach; ?>
    </div>
  </div>
</section>

<!-- CTA -->
<section class="pub-section pub-section-creme">
  <div class="pub-container">
    <div class="pub-cta-section">
      <h2>Envie de rejoindre ces structures ?</h2>
      <p>30 minutes en visio, on regarde ensemble si Assokit est fait pour vous. Sans engagement.</p>
      <div style="display:flex;gap:12px;flex-wrap:wrap;justify-content:center;margin-top:8px;">
        <a href="/contact" class="pub-btn pub-btn-primary pub-btn-lg">Réserver une démo gratuite</a>
        <a href="/tarifs" class="pub-btn pub-btn-ghost pub-btn-lg">Voir les tarifs</a>
      </div>
    </div>
  </div>
</section>

<?php
render_public_footer();
render_public_foot();
