<?php
/**
 * index.php — PATCH 6.1.b
 * --------------------------------------------------------------
 * - Bloc Promesse n°3 reformulé (suivi projets + fini WhatsApp)
 * - Mini-descriptions SEO en italique sous les titres des promesses
 * - Reste inchangé du PATCH 6.1
 * --------------------------------------------------------------
 */
require_once __DIR__ . '/includes-public.php';

// === FAQ (source unique : alimente le schema FAQPage ET la section visible) ===
$home_faqs = [
    [
        'q' => "Qu'est-ce qu'Assokit ?",
        'a' => "Assokit est un logiciel de gestion tout-en-un pour les associations loi 1901 et les TPE. Il réunit dans un seul outil les adhérents, les cotisations, la facturation, les devis, la comptabilité analytique, le suivi de projets, la communication et l'intelligence artificielle.",
    ],
    [
        'q' => "Assokit convient-il aux associations loi 1901 ?",
        'a' => "Oui. Assokit gère les adhérents, les cotisations, les assemblées générales, l'émargement, les subventions et la communication, avec un coach IA dédié à la vie associative — sans plug-in ni paperasse.",
    ],
    [
        'q' => "Assokit fonctionne-t-il aussi pour les TPE et les indépendants ?",
        'a' => "Oui. Les TPE, PME et indépendants profitent de la facturation, des devis, du suivi des paiements et de la comptabilité analytique, le tout sans avoir besoin d'un logiciel supplémentaire.",
    ],
    [
        'q' => "Combien coûte Assokit ?",
        'a' => "Assokit propose un essai gratuit de 14 jours, sans carte bancaire et sans engagement, puis des formules Essentiel (29,99€) et Pro (49,99€ HT) adaptées à votre activité. Vous testez toutes les fonctionnalités avant de choisir.",
    ],
    [
        'q' => "La comptabilité analytique est-elle incluse ?",
        'a' => "Oui, la comptabilité analytique est incluse dès l'offre Pro, soit environ 900 € d'économie par an par rapport à un expert-comptable. Votre comptable n'intervient plus que pour valider les comptes.",
    ],
    [
        'q' => "Mes données sont-elles sécurisées et hébergées en France ?",
        'a' => "Oui. Vos données sont hébergées en France, conformes au RGPD, protégées par la double authentification (2FA) et sauvegardées régulièrement.",
    ],
    [
        'q' => "Existe-t-il une application mobile Assokit ?",
        'a' => "Oui, Assokit dispose d'une application mobile qui vous permet de piloter votre association ou votre TPE directement depuis votre téléphone.",
    ],
];
$faq_schema = ['@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => []];
foreach ($home_faqs as $f) {
    $faq_schema['mainEntity'][] = [
        '@type' => 'Question',
        'name'  => $f['q'],
        'acceptedAnswer' => ['@type' => 'Answer', 'text' => $f['a']],
    ];
}

render_public_head([
    // === SEO HOMEPAGE — VALIDÉ ===
    // Title  (54 chars) : Assokit · Logiciel Association & TPE Tout-en-Un · IA
    // Meta D (136 chars): Gérez votre association & TPE en un seul outil. Facturation, IA, projets, adhérents. Plus de temps pour ce qui compte. Essai gratuit 14 jours.
    'title_full'  => 'Assokit · Logiciel Association & TPE Tout-en-Un · IA',
    'description' => 'Gérez votre association & TPE en un seul outil. Facturation, IA, projets, adhérents. Plus de temps pour ce qui compte. Essai gratuit 14 jours.',
    'path'        => '/',
    // Image OG dédiée (1200x630) pour partage WhatsApp/Facebook/LinkedIn/Twitter
    'og_image'    => 'https://assokit.fr/assets/og-assokit-home.png',
    'schema_jsonld' => [
        [
            '@context' => 'https://schema.org',
            '@type'    => 'SoftwareApplication',
            'name'     => 'Assokit',
            'operatingSystem' => 'Web',
            'applicationCategory' => 'BusinessApplication',
            'description' => 'Logiciel tout-en-un pour les associations loi 1901 et les TPE : facturation, trésorerie, communication IA, adhérents, suivi de projets.',
            // 'offers' RETIRÉ : avec 'offers', Google classe la page en « extrait de
            // produit » et réclame 'review'/'aggregateRating' (Search Console). Sans
            // avis notés réels affichés, fabriquer un rating serait non conforme.
            // Le prix reste en contenu réel sur /tarifs. Pas d'aggregateRating ici.
        ],
        $faq_schema,
    ],
]);

render_public_nav('');
?>

<!-- ============================================================ -->
<!-- HERO -->
<!-- ============================================================ -->

<style>
/* ============ HARMONISATION ASSOKIT v2 ============ */

/* Promesses globales (sections) */
.pub-promise-strong {
  font-size: 17px;
  font-weight: 600;
  color: #064E3B;
  max-width: 760px;
  margin: 18px auto 0;
  line-height: 1.55;
  text-align: center;
  letter-spacing: -0.005em;
}
.pub-promise-strong strong {
  color: #047857;
  font-weight: 800;
  background: linear-gradient(120deg, transparent 0%, transparent 35%, #D1FAE5 35%, #D1FAE5 100%);
  padding: 0 4px;
}

/* Promesses hero */
.pub-hero-promise {
  font-size: 19px;
  font-weight: 700;
  color: #064E3B;
  margin: 10px 0 18px;
  letter-spacing: -0.01em;
  line-height: 1.35;
}
.pub-hero-promise strong {
  background: linear-gradient(120deg, transparent 0%, transparent 30%, #A7F3D0 30%, #A7F3D0 100%);
  padding: 0 5px;
  border-radius: 3px;
}
.pub-hero-motto {
  font-size: 14px;
  color: #6b7280;
  margin: 16px 0 0;
  font-weight: 500;
  letter-spacing: 0.01em;
}
.pub-hero-motto em {
  font-style: normal;
  color: #10B981;
  font-weight: 700;
}

/* Splitter responsive impeccable */
.ak-splitter { padding: 60px 0 32px; }
.ak-split-intro {
  text-align: center;
  font-size: 16px;
  color: #4b5563;
  margin: 0 auto 32px;
  max-width: 640px;
  line-height: 1.55;
  padding: 0 16px;
}
.ak-split-intro strong { color: #111827; font-weight: 700; }
.ak-split-grid {
  display: grid;
  grid-template-columns: repeat(2, 1fr);
  gap: 20px;
  max-width: 1080px;
  margin: 0 auto;
}
.ak-split-card {
  display: block;
  background: #fff;
  border: 2px solid #e5e7eb;
  border-radius: 22px;
  padding: 36px 32px;
  text-decoration: none;
  color: inherit;
  transition: transform 0.25s ease, border-color 0.25s ease, box-shadow 0.25s ease;
  position: relative;
  overflow: hidden;
}
.ak-split-card::before {
  content: "";
  position: absolute;
  top: 0; left: 0; right: 0;
  height: 5px;
  background: var(--ak-split-tone, #10B981);
}
.ak-split-asso { --ak-split-tone: #10B981; }
.ak-split-tpe { --ak-split-tone: #6366F1; }
.ak-split-card:hover {
  transform: translateY(-6px);
  border-color: var(--ak-split-tone);
  box-shadow: 0 20px 44px rgba(0,0,0,0.10);
}
.ak-split-ico {
  width: 64px; height: 64px;
  border-radius: 16px;
  background: color-mix(in srgb, var(--ak-split-tone) 14%, #fff);
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 32px;
  margin-bottom: 18px;
}
.ak-split-card h2 {
  font-size: 24px;
  margin: 0 0 8px;
  color: #111827;
  line-height: 1.25;
  font-weight: 800;
  letter-spacing: -0.015em;
}
.ak-split-card h2 em {
  color: var(--ak-split-tone);
  font-style: normal;
}
.ak-split-punch {
  font-size: 14.5px !important;
  font-weight: 700 !important;
  color: var(--ak-split-tone) !important;
  margin: 0 0 14px !important;
  line-height: 1.45 !important;
}
.ak-split-punch strong { color: #111827; }
.ak-split-card > p:not(.ak-split-punch) {
  font-size: 14.5px;
  color: #4b5563;
  line-height: 1.6;
  margin: 0 0 16px;
}
.ak-split-targets {
  list-style: none;
  padding: 0;
  margin: 0 0 20px;
  display: flex;
  flex-direction: column;
  gap: 8px;
  padding-top: 16px;
  border-top: 1px solid #f3f4f6;
}
.ak-split-targets li {
  font-size: 13.5px;
  color: #4b5563;
  padding-left: 24px;
  position: relative;
  line-height: 1.45;
}
.ak-split-targets li::before {
  content: "✓";
  position: absolute;
  left: 0;
  top: 0;
  width: 16px;
  height: 16px;
  background: color-mix(in srgb, var(--ak-split-tone) 14%, #fff);
  color: var(--ak-split-tone);
  font-weight: 700;
  font-size: 11px;
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  margin-top: 2px;
}
.ak-split-cta {
  display: inline-flex;
  align-items: center;
  color: var(--ak-split-tone);
  font-weight: 700;
  font-size: 14.5px;
  transition: transform 0.2s;
  padding-top: 4px;
  border-top: 1px solid #f3f4f6;
  width: 100%;
  padding-top: 16px;
}
.ak-split-card:hover .ak-split-cta { transform: translateX(6px); }

/* Anti-concurrence */
.ak-anticonc-sec { padding: 12px 0 48px; }
.ak-anticonc {
  display: flex;
  gap: 20px;
  align-items: center;
  max-width: 940px;
  margin: 0 auto;
  padding: 24px 28px;
  background: linear-gradient(135deg, #ECFDF5 0%, #F0F9FF 100%);
  border: 1px solid #A7F3D0;
  border-radius: 18px;
  box-shadow: 0 4px 14px rgba(16, 185, 129, 0.06);
}
.ak-anticonc-icon {
  font-size: 38px;
  flex-shrink: 0;
  width: 56px;
  height: 56px;
  background: #fff;
  border-radius: 14px;
  display: flex;
  align-items: center;
  justify-content: center;
  box-shadow: 0 4px 12px rgba(16,185,129,0.10);
}
.ak-anticonc p {
  margin: 0;
  font-size: 15px;
  color: #065F46;
  line-height: 1.6;
  font-weight: 500;
}
.ak-anticonc strong { color: #064E3B; font-weight: 700; }

/* Pack Asso grid */
.pa-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
  gap: 20px;
  margin-top: 36px;
  max-width: 1200px;
  margin-left: auto;
  margin-right: auto;
}
.pa-card {
  background: #fff;
  border: 1px solid #e5e7eb;
  border-radius: 20px;
  padding: 28px 26px;
  transition: transform 0.25s ease, box-shadow 0.25s ease;
  border-top: 4px solid var(--pa-tone, #10B981);
}
.pa-card:hover { transform: translateY(-4px); box-shadow: 0 18px 40px rgba(0,0,0,0.07); }
.pa-card-emerald { --pa-tone: #10B981; }
.pa-card-blue { --pa-tone: #3B82F6; }
.pa-card-amber { --pa-tone: #F59E0B; }
.pa-card-violet { --pa-tone: #8B5CF6; }
.pa-icon {
  width: 58px; height: 58px;
  border-radius: 14px;
  background: color-mix(in srgb, var(--pa-tone) 12%, #fff);
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 30px;
  margin-bottom: 18px;
}
.pa-card h3 {
  font-size: 18.5px;
  margin: 0 0 10px;
  color: #111827;
  line-height: 1.3;
  font-weight: 800;
  letter-spacing: -0.01em;
}
.pa-card p {
  font-size: 14px;
  color: #4b5563;
  line-height: 1.6;
  margin: 0 0 14px;
}
.pa-features {
  list-style: none;
  padding: 14px 0 0;
  margin: 0;
  display: flex;
  flex-direction: column;
  gap: 7px;
  border-top: 1px solid #f3f4f6;
}
.pa-features li { font-size: 13px; color: #374151; line-height: 1.5; }

/* ============ RESPONSIVE MOBILE ============ */
@media (max-width: 900px) {
  .ak-split-grid { grid-template-columns: 1fr; gap: 16px; }
  .ak-split-card { padding: 28px 24px; }
  .ak-split-card h2 { font-size: 20px; }
}
@media (max-width: 720px) {
  .pub-hero-promise { font-size: 16px; }
  .pub-hero-motto { font-size: 13px; }
  .pub-promise-strong { font-size: 15px; margin-top: 12px; padding: 0 16px; }
  .ak-splitter { padding: 40px 0 24px; }
  .ak-split-intro { font-size: 14.5px; margin-bottom: 22px; }
  .ak-anticonc {
    flex-direction: column;
    text-align: center;
    padding: 22px 20px;
    gap: 14px;
  }
  .ak-anticonc p { font-size: 14px; }
  .pa-grid { gap: 14px; margin-top: 24px; }
  .pa-card { padding: 22px 20px; }
}
@media (max-width: 480px) {
  .ak-split-card { padding: 24px 20px; }
  .ak-split-ico { width: 56px; height: 56px; font-size: 28px; }
  .ak-split-card h2 { font-size: 19px; }
  .pa-icon { width: 52px; height: 52px; font-size: 26px; }
}
</style>

<style>
/* ============ ASSOKIT PREMIUM v3 ============ */

/* ---- HERO décoré ---- */
.ak-hero-v2 { position: relative; overflow: hidden; }
.ak-hero-bg {
  position: absolute; inset: 0;
  pointer-events: none;
  z-index: 0;
}
.ak-hero-blob {
  position: absolute;
  border-radius: 50%;
  filter: blur(80px);
  opacity: 0.45;
  animation: akFloat 14s ease-in-out infinite;
}
.ak-hero-blob-1 {
  width: 420px; height: 420px;
  top: -120px; right: -80px;
  background: radial-gradient(circle, #A7F3D0 0%, #6EE7B7 60%, transparent 100%);
}
.ak-hero-blob-2 {
  width: 360px; height: 360px;
  bottom: -100px; left: -100px;
  background: radial-gradient(circle, #DBEAFE 0%, #93C5FD 60%, transparent 100%);
  opacity: 0.35;
  animation-delay: -7s;
}
@keyframes akFloat {
  0%, 100% { transform: translate(0, 0) scale(1); }
  50% { transform: translate(20px, -30px) scale(1.08); }
}
.ak-hero-grid {
  position: absolute; inset: 0;
  background-image:
    linear-gradient(rgba(16,185,129,0.04) 1px, transparent 1px),
    linear-gradient(90deg, rgba(16,185,129,0.04) 1px, transparent 1px);
  background-size: 32px 32px;
  mask-image: radial-gradient(ellipse at center, black 30%, transparent 75%);
  -webkit-mask-image: radial-gradient(ellipse at center, black 30%, transparent 75%);
}
.ak-hero-v2 .pub-container { position: relative; z-index: 1; }

/* ---- Badge "Nouveau" animé ---- */
.ak-badge-new {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  padding: 8px 16px 8px 10px;
  background: linear-gradient(135deg, #ECFDF5, #DBEAFE);
  border: 1px solid #A7F3D0;
  border-radius: 999px;
  font-size: 12.5px;
  font-weight: 600;
  color: #065F46;
  margin-bottom: 22px;
  box-shadow: 0 4px 12px rgba(16,185,129,0.10);
}
.ak-badge-new strong {
  color: #047857;
  font-weight: 800;
  letter-spacing: 0.04em;
  font-size: 11px;
}
.ak-badge-new > span:not(.ak-badge-dot) { color: #9CA3AF; }
.ak-badge-dot {
  width: 8px; height: 8px;
  border-radius: 50%;
  background: #10B981;
  box-shadow: 0 0 0 0 rgba(16,185,129,0.7);
  animation: akPulseDot 2s infinite;
}
@keyframes akPulseDot {
  0% { box-shadow: 0 0 0 0 rgba(16,185,129,0.7); }
  70% { box-shadow: 0 0 0 10px rgba(16,185,129,0); }
  100% { box-shadow: 0 0 0 0 rgba(16,185,129,0); }
}

/* ---- H1 premium avec gradient ---- */
.ak-hero-v2 .pub-h1 em {
  background: linear-gradient(135deg, #10B981 0%, #059669 50%, #047857 100%);
  -webkit-background-clip: text;
  background-clip: text;
  -webkit-text-fill-color: transparent;
  font-style: normal;
  font-weight: 800;
}

/* ---- Splitter ULTRA premium ---- */
.ak-splitter::before {
  content: "✦";
  display: block;
  text-align: center;
  color: #10B981;
  font-size: 22px;
  letter-spacing: 8px;
  margin-bottom: 8px;
  opacity: 0.5;
}
.ak-split-card {
  background: linear-gradient(180deg, #fff 0%, #fff 70%, #fafaf7 100%);
  position: relative;
}
.ak-split-card::after {
  content: "";
  position: absolute;
  inset: 0;
  border-radius: 22px;
  pointer-events: none;
  background: radial-gradient(circle at top right, color-mix(in srgb, var(--ak-split-tone) 8%, transparent), transparent 60%);
  opacity: 0;
  transition: opacity 0.3s;
}
.ak-split-card:hover::after { opacity: 1; }
.ak-split-ico {
  background: linear-gradient(135deg,
    color-mix(in srgb, var(--ak-split-tone) 22%, #fff) 0%,
    color-mix(in srgb, var(--ak-split-tone) 8%, #fff) 100%);
  box-shadow:
    0 6px 18px color-mix(in srgb, var(--ak-split-tone) 25%, transparent),
    inset 0 1px 0 rgba(255,255,255,0.6);
}
.ak-split-stats {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 10px;
  padding: 14px 0;
  margin: 16px 0;
  border-top: 1px dashed #e5e7eb;
  border-bottom: 1px dashed #e5e7eb;
}
.ak-split-stat { text-align: center; }
.ak-split-stat strong {
  display: block;
  font-size: 18px;
  font-weight: 800;
  color: var(--ak-split-tone);
  line-height: 1.1;
  letter-spacing: -0.01em;
}
.ak-split-stat span {
  display: block;
  font-size: 10.5px;
  color: #6b7280;
  margin-top: 3px;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  font-weight: 600;
}

/* ---- Anti-concurrence refondu ---- */
.ak-anticonc {
  background: linear-gradient(135deg, #064E3B 0%, #047857 100%);
  border: none;
  color: #fff;
  padding: 28px 32px;
  position: relative;
  overflow: hidden;
}
.ak-anticonc::before {
  content: "";
  position: absolute;
  top: -50%; right: -10%;
  width: 50%; height: 200%;
  background: radial-gradient(circle, rgba(167,243,208,0.15), transparent 70%);
  pointer-events: none;
}
.ak-anticonc-icon {
  background: rgba(255,255,255,0.12) !important;
  backdrop-filter: blur(8px);
  box-shadow: 0 8px 24px rgba(0,0,0,0.18) !important;
  font-size: 32px !important;
}
.ak-anticonc p {
  color: #ECFDF5 !important;
  font-size: 15.5px !important;
  position: relative;
  z-index: 1;
}
.ak-anticonc strong { color: #fff !important; }

/* ---- Promesses ornementées ---- */
.pub-promise-strong {
  position: relative;
  padding-top: 20px;
}
.pub-promise-strong::before {
  content: "✦ · ✦";
  display: block;
  text-align: center;
  color: #10B981;
  font-size: 12px;
  letter-spacing: 6px;
  margin-bottom: 14px;
  opacity: 0.45;
}

/* ---- Pack Asso cards ENRICHIES ---- */
.pa-card {
  position: relative;
  background: linear-gradient(180deg, #fff 0%, #fff 75%, color-mix(in srgb, var(--pa-tone) 5%, #fff) 100%);
}
.pa-card::before {
  content: "";
  position: absolute;
  top: 0; left: 0; right: 0;
  height: 4px;
  background: linear-gradient(90deg, var(--pa-tone), color-mix(in srgb, var(--pa-tone) 60%, #fff));
  border-radius: 20px 20px 0 0;
}
.pa-icon {
  background: linear-gradient(135deg,
    color-mix(in srgb, var(--pa-tone) 18%, #fff) 0%,
    color-mix(in srgb, var(--pa-tone) 6%, #fff) 100%);
  box-shadow:
    0 6px 16px color-mix(in srgb, var(--pa-tone) 22%, transparent),
    inset 0 1px 0 rgba(255,255,255,0.7);
}
.pa-card:hover {
  transform: translateY(-6px);
  box-shadow: 0 22px 50px rgba(0,0,0,0.10);
}
.pa-features li {
  position: relative;
  padding-left: 4px;
}

/* ---- Section eyebrow premium ---- */
.pub-section-eyebrow {
  display: inline-block;
  padding: 5px 14px;
  background: linear-gradient(135deg, #ECFDF5, #DBEAFE);
  color: #047857;
  font-size: 11px;
  font-weight: 800;
  border-radius: 999px;
  letter-spacing: 0.08em;
  text-transform: uppercase;
  margin-bottom: 10px;
  border: 1px solid rgba(16,185,129,0.15);
}

/* ---- RESPONSIVE ULTRA ---- */
@media (max-width: 900px) {
  .ak-hero-blob { opacity: 0.25; }
}
@media (max-width: 720px) {
  .ak-badge-new { font-size: 11.5px; padding: 7px 12px 7px 8px; }
  .ak-badge-new strong { font-size: 10px; }
  .ak-split-stats { gap: 6px; padding: 12px 0; margin: 12px 0; }
  .ak-split-stat strong { font-size: 16px; }
  .ak-split-stat span { font-size: 9.5px; }
  .ak-anticonc { padding: 24px 22px; }
  .pub-promise-strong::before { font-size: 11px; letter-spacing: 4px; }
}
@media (max-width: 480px) {
  .ak-split-stats { grid-template-columns: 1fr; gap: 8px; }
  .ak-split-stat { display: flex; justify-content: space-between; text-align: left; align-items: baseline; }
  .ak-split-stat strong { font-size: 17px; }
}
</style>

<style>
/* ============ ASSOKIT ULTIMATE v4 ============ */

/* ---- Sparkles flottants hero ---- */
.ak-hero-sparkle {
  position: absolute;
  width: 18px; height: 18px;
  opacity: 0.5;
  animation: akSparkle 6s ease-in-out infinite;
  filter: drop-shadow(0 2px 6px rgba(0,0,0,0.10));
}
.ak-hero-sparkle-1 { top: 14%; right: 18%; animation-delay: 0s; }
.ak-hero-sparkle-2 { top: 32%; right: 8%; width: 14px; height: 14px; animation-delay: -2s; }
.ak-hero-sparkle-3 { bottom: 22%; right: 26%; width: 12px; height: 12px; animation-delay: -4s; }
@keyframes akSparkle {
  0%, 100% { transform: scale(1) rotate(0deg); opacity: 0.3; }
  50% { transform: scale(1.4) rotate(180deg); opacity: 0.8; }
}

/* ---- Badge cleaner premium ---- */
.ak-badge-new {
  background: rgba(255,255,255,0.85);
  backdrop-filter: blur(8px);
  -webkit-backdrop-filter: blur(8px);
  border: 1px solid rgba(167,243,208,0.5);
  box-shadow:
    0 4px 18px rgba(16,185,129,0.10),
    inset 0 1px 0 rgba(255,255,255,0.8);
  padding: 9px 18px 9px 12px;
}
.ak-badge-text strong {
  background: linear-gradient(135deg, #10B981, #6366F1);
  -webkit-background-clip: text;
  background-clip: text;
  -webkit-text-fill-color: transparent;
  font-weight: 800;
}
.ak-badge-dot {
  position: relative;
}
.ak-badge-dot::after {
  content: "";
  position: absolute;
  inset: -2px;
  border-radius: 50%;
  background: linear-gradient(135deg, #10B981, #6EE7B7);
  z-index: -1;
}

/* ---- H1 mega premium ---- */
.ak-hero-v2 .pub-h1 {
  font-weight: 800;
  letter-spacing: -0.025em;
  line-height: 1.08;
}
.ak-hero-v2 .pub-h1 em {
  background: linear-gradient(135deg, #10B981 0%, #059669 40%, #047857 100%);
  -webkit-background-clip: text;
  background-clip: text;
  -webkit-text-fill-color: transparent;
  font-style: normal;
  font-weight: 800;
  position: relative;
  display: inline-block;
}
.ak-hero-v2 .pub-h1 em::after {
  content: "";
  position: absolute;
  bottom: -2px;
  left: 0; right: 0;
  height: 3px;
  background: linear-gradient(90deg, transparent, #10B981, #6EE7B7, transparent);
  border-radius: 2px;
  opacity: 0.4;
}

/* ---- CTA Buttons shimmer ---- */
.pub-btn-primary {
  position: relative;
  overflow: hidden;
  background: linear-gradient(135deg, #10B981 0%, #059669 100%) !important;
  border: none !important;
  box-shadow:
    0 4px 14px rgba(16,185,129,0.35),
    inset 0 1px 0 rgba(255,255,255,0.25) !important;
  transition: all 0.3s !important;
}
.pub-btn-primary::after {
  content: "";
  position: absolute;
  top: 0; left: -100%;
  width: 60%; height: 100%;
  background: linear-gradient(90deg, transparent, rgba(255,255,255,0.4), transparent);
  transform: skewX(-20deg);
  transition: left 0.6s ease;
}
.pub-btn-primary:hover::after { left: 130%; }
.pub-btn-primary:hover {
  transform: translateY(-2px);
  box-shadow:
    0 8px 22px rgba(16,185,129,0.45),
    inset 0 1px 0 rgba(255,255,255,0.3) !important;
}

.pub-btn-ghost {
  background: rgba(255,255,255,0.85) !important;
  backdrop-filter: blur(8px);
  border: 1.5px solid #e5e7eb !important;
  transition: all 0.25s !important;
}
.pub-btn-ghost:hover {
  border-color: #10B981 !important;
  color: #10B981 !important;
  transform: translateY(-2px);
  box-shadow: 0 8px 18px rgba(16,185,129,0.12) !important;
}

/* ---- Trust badges premium ---- */
.pub-hero-trust {
  margin-top: 28px;
  padding-top: 24px;
  border-top: 1px dashed #e5e7eb;
  flex-wrap: wrap;
  gap: 14px !important;
}
.pub-hero-trust span {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  font-size: 13px;
  color: #4b5563;
  font-weight: 500;
  padding: 6px 12px;
  background: rgba(255,255,255,0.6);
  backdrop-filter: blur(6px);
  border-radius: 999px;
  border: 1px solid rgba(229,231,235,0.6);
  transition: all 0.2s;
}
.pub-hero-trust span:hover {
  background: #fff;
  border-color: #10B981;
  color: #047857;
  transform: translateY(-1px);
}

/* ---- Splitter glow border ---- */
.ak-split-card {
  background: rgba(255,255,255,0.95);
  backdrop-filter: blur(8px);
}
.ak-split-card::before {
  height: 6px;
  background: linear-gradient(90deg,
    var(--ak-split-tone) 0%,
    color-mix(in srgb, var(--ak-split-tone) 70%, #fff) 50%,
    var(--ak-split-tone) 100%);
}
.ak-split-card:hover {
  border-color: var(--ak-split-tone);
  box-shadow:
    0 22px 50px color-mix(in srgb, var(--ak-split-tone) 14%, transparent),
    0 0 0 4px color-mix(in srgb, var(--ak-split-tone) 8%, transparent);
}
.ak-split-ico {
  position: relative;
}
.ak-split-ico::after {
  content: "";
  position: absolute;
  inset: -8px;
  border-radius: 22px;
  background: radial-gradient(circle, color-mix(in srgb, var(--ak-split-tone) 20%, transparent) 0%, transparent 70%);
  z-index: -1;
  opacity: 0;
  transition: opacity 0.3s;
}
.ak-split-card:hover .ak-split-ico::after { opacity: 1; }

/* ---- Stats counter premium ---- */
.ak-split-stats {
  background: linear-gradient(135deg,
    color-mix(in srgb, var(--ak-split-tone) 5%, #fff) 0%,
    transparent 100%);
  border-radius: 12px;
  padding: 14px 12px;
  border-top: none;
  border-bottom: none;
  border: 1px dashed color-mix(in srgb, var(--ak-split-tone) 25%, #e5e7eb);
}
.ak-split-stat strong {
  font-size: 20px;
  background: linear-gradient(135deg, var(--ak-split-tone), color-mix(in srgb, var(--ak-split-tone) 70%, #000));
  -webkit-background-clip: text;
  background-clip: text;
  -webkit-text-fill-color: transparent;
}

/* ---- Splitter CTA arrow animated ---- */
.ak-split-cta {
  font-weight: 800;
  font-size: 15px;
  position: relative;
}
.ak-split-card:hover .ak-split-cta { transform: translateX(8px); }

/* ---- Anti-conc avec stars ---- */
.ak-anticonc::after {
  content: "";
  position: absolute;
  inset: 0;
  background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='40' height='40' viewBox='0 0 40 40'%3E%3Ccircle cx='20' cy='20' r='1' fill='rgba(255,255,255,0.05)'/%3E%3C/svg%3E");
  pointer-events: none;
}

/* ---- Pack Asso cards luxe ---- */
.pa-card {
  position: relative;
  isolation: isolate;
}
.pa-card::after {
  content: "";
  position: absolute;
  inset: 0;
  background: radial-gradient(circle at top right,
    color-mix(in srgb, var(--pa-tone) 10%, transparent) 0%,
    transparent 60%);
  opacity: 0;
  transition: opacity 0.3s;
  border-radius: 20px;
  z-index: -1;
}
.pa-card:hover::after { opacity: 1; }
.pa-card:hover {
  border-color: color-mix(in srgb, var(--pa-tone) 30%, #e5e7eb);
}
.pa-icon {
  position: relative;
}
.pa-icon::after {
  content: "";
  position: absolute;
  inset: -4px;
  border-radius: 18px;
  background: radial-gradient(circle, color-mix(in srgb, var(--pa-tone) 18%, transparent), transparent 70%);
  z-index: -1;
  opacity: 0;
  transition: opacity 0.3s;
}
.pa-card:hover .pa-icon::after { opacity: 1; }

/* ---- Section eyebrow LUXE ---- */
.pub-section-eyebrow {
  position: relative;
  padding: 6px 16px 6px 26px;
  background: linear-gradient(135deg, #ECFDF5, #DBEAFE);
  font-size: 11px;
  letter-spacing: 0.10em;
  border: 1px solid rgba(16,185,129,0.18);
  box-shadow: 0 2px 8px rgba(16,185,129,0.06);
}
.pub-section-eyebrow::before {
  content: "";
  position: absolute;
  left: 12px;
  top: 50%;
  transform: translateY(-50%);
  width: 6px; height: 6px;
  border-radius: 50%;
  background: #10B981;
  box-shadow: 0 0 0 3px rgba(16,185,129,0.15);
}

/* ---- H2 sections premium ---- */
.pub-h2 em {
  background: linear-gradient(135deg, #10B981 0%, #059669 100%);
  -webkit-background-clip: text;
  background-clip: text;
  -webkit-text-fill-color: transparent;
  font-style: normal;
  font-weight: 800;
}

/* ---- Section avec dividers wave subtils ---- */
.pub-section + .pub-section:not(.pub-section-creme):not(.ak-cta-final) {
  position: relative;
}
.pub-section.pub-section-creme {
  position: relative;
}

/* ---- Témoignages avec étoiles dorées ---- */
.pub-testimonial-stars {
  background: linear-gradient(135deg, #F59E0B, #FBBF24);
  -webkit-background-clip: text;
  background-clip: text;
  -webkit-text-fill-color: transparent;
  font-size: 16px !important;
  letter-spacing: 2px;
}

/* ---- Promesses ornées avec gradient ---- */
.pub-promise-strong strong {
  background: linear-gradient(120deg,
    rgba(16,185,129,0) 0%,
    rgba(16,185,129,0) 8%,
    #D1FAE5 8%,
    #A7F3D0 100%);
}

/* ---- Anti-conc glow ---- */
.ak-anticonc {
  box-shadow:
    0 20px 50px rgba(6,78,59,0.20),
    inset 0 1px 0 rgba(255,255,255,0.10);
}
.ak-anticonc-icon {
  border: 1px solid rgba(255,255,255,0.15) !important;
}

/* ---- CTA final décoration extra ---- */
.ak-cta-card::before {
  background: radial-gradient(circle, rgba(167, 243, 208, 0.28) 0%, transparent 70%);
}
.ak-cta-title em {
  background: linear-gradient(135deg, #A7F3D0, #6EE7B7);
  -webkit-background-clip: text;
  background-clip: text;
  -webkit-text-fill-color: transparent;
  font-style: normal;
}

/* ---- Animations fade-in subtle au load ---- */
.ak-hero-v2 .pub-hero-text > * {
  animation: akFadeUp 0.7s ease-out backwards;
}
.ak-hero-v2 .pub-hero-text > *:nth-child(1) { animation-delay: 0.05s; }
.ak-hero-v2 .pub-hero-text > *:nth-child(2) { animation-delay: 0.15s; }
.ak-hero-v2 .pub-hero-text > *:nth-child(3) { animation-delay: 0.25s; }
.ak-hero-v2 .pub-hero-text > *:nth-child(4) { animation-delay: 0.35s; }
.ak-hero-v2 .pub-hero-text > *:nth-child(5) { animation-delay: 0.45s; }
.ak-hero-v2 .pub-hero-text > *:nth-child(6) { animation-delay: 0.55s; }
.ak-hero-v2 .pub-hero-text > *:nth-child(7) { animation-delay: 0.65s; }
@keyframes akFadeUp {
  0% { opacity: 0; transform: translateY(16px); }
  100% { opacity: 1; transform: translateY(0); }
}

/* ---- Responsive Premium ---- */
@media (max-width: 720px) {
  .ak-hero-sparkle { display: none; }
  .pub-hero-trust span { font-size: 12px; padding: 5px 10px; }
  .ak-badge-new { font-size: 11.5px; }
  .ak-split-stat strong { font-size: 17px; }
}
@media (prefers-reduced-motion: reduce) {
  .ak-hero-blob, .ak-hero-sparkle, .ak-badge-dot, .ak-hero-v2 .pub-hero-text > * {
    animation: none !important;
  }
}
</style>

<section class="pub-hero ak-hero-v2">
  <div class="ak-hero-bg" aria-hidden="true">
    <div class="ak-hero-blob ak-hero-blob-1"></div>
    <div class="ak-hero-blob ak-hero-blob-2"></div>
    <div class="ak-hero-grid"></div>
    <svg class="ak-hero-sparkle ak-hero-sparkle-1" viewBox="0 0 24 24" fill="none"><path d="M12 0L13.5 8L22 9.5L13.5 11L12 19L10.5 11L2 9.5L10.5 8L12 0Z" fill="#10B981"/></svg>
    <svg class="ak-hero-sparkle ak-hero-sparkle-2" viewBox="0 0 24 24" fill="none"><path d="M12 0L13.5 8L22 9.5L13.5 11L12 19L10.5 11L2 9.5L10.5 8L12 0Z" fill="#6366F1"/></svg>
    <svg class="ak-hero-sparkle ak-hero-sparkle-3" viewBox="0 0 24 24" fill="none"><path d="M12 0L13.5 8L22 9.5L13.5 11L12 19L10.5 11L2 9.5L10.5 8L12 0Z" fill="#F59E0B"/></svg>
  </div>
  <div class="pub-container">
    <div class="pub-hero-inner">
      <div class="pub-hero-text">
        <span class="ak-badge-new">
          <span class="ak-badge-dot"></span>
          <span class="ak-badge-text">Le logiciel de gestion moderne avec <strong>IA intégrée</strong></span>
        </span>
        <h1 class="pub-h1">Gérez votre <em>association</em> ou votre <em>TPE</em> avec un seul outil intelligent.</h1>
        <p class="pub-hero-promise">Toute votre gestion. Un seul espace. <strong>Zéro complexité.</strong></p>
        <p class="pub-tagline">
          Adhérents, clients, devis, factures, relances, emails, projets, bilans et documents IA : <strong>Assokit centralise votre gestion quotidienne</strong> dans une plateforme française, simple et sécurisée.
        </p>

        <div class="pub-hero-cta">
          <a href="/signup" class="pub-btn pub-btn-primary pub-btn-lg">Démarrer l'essai gratuit 14 jours →</a>
          <a href="/contact?demo=1" class="pub-btn pub-btn-ghost pub-btn-lg">Réserver une démo</a>
        </div>

        <div class="pub-hero-trust">
          <span>🇫🇷 Hébergé en France</span>
          <span>🔒 Conforme RGPD</span>
          <span>⚡ Support &lt;24h</span>
          <span>🏢 Édité par RBPS</span>
          <span>💚 Données sécurisées</span>
        </div>
        <p class="pub-hero-motto">Simple à prendre en main. Puissant au quotidien. <em>Humain quand il le faut.</em></p>
      </div>

      <div class="pub-hero-visual">
        <div class="pub-hero-deco pub-hero-deco-1"></div>
        <div class="pub-hero-deco pub-hero-deco-2"></div>
        <div class="pub-hero-card">
          <div class="pub-hero-card-head">
            <div class="pub-hero-card-dots"><span></span><span></span><span></span></div>
            <div class="pub-hero-card-title">Tableau de bord · Assokit</div>
          </div>
          <div class="pub-hero-stat-grid">
            <div class="pub-hero-stat">
              <div class="pub-hero-stat-label">Adhérents</div>
              <div class="pub-hero-stat-value">247</div>
              <div class="pub-hero-stat-trend">↑ +12 ce mois</div>
            </div>
            <div class="pub-hero-stat">
              <div class="pub-hero-stat-label">Trésorerie</div>
              <div class="pub-hero-stat-value">14,2K€</div>
              <div class="pub-hero-stat-trend">↑ +8%</div>
            </div>
            <div class="pub-hero-stat">
              <div class="pub-hero-stat-label">Projets</div>
              <div class="pub-hero-stat-value">8</div>
              <div class="pub-hero-stat-trend">3 actifs</div>
            </div>
          </div>
          <div class="pub-hero-row">
            <div class="pub-hero-row-ico" style="background:#D1FAE5;color:#059669;">📋</div>
            <div class="pub-hero-row-info">
              <div class="pub-hero-row-title">Facture #assokit-2026-000042</div>
              <div class="pub-hero-row-sub">Émise · 850 € · échéance 30j</div>
            </div>
            <span class="pub-hero-row-pill" style="background:#FEF3C7;color:#92400E;">En attente</span>
          </div>
          <div class="pub-hero-row">
            <div class="pub-hero-row-ico" style="background:#FEE2E2;color:#991B1B;">🎬</div>
            <div class="pub-hero-row-info">
              <div class="pub-hero-row-title">Tournage Vidéo · Lycée Pierre Mendès</div>
              <div class="pub-hero-row-sub">À suivre de près · Échéance 15j</div>
            </div>
            <span class="pub-hero-row-pill" style="background:#FEF3C7;color:#92400E;">En cours</span>
          </div>
          <div class="pub-hero-row" style="margin-bottom:0;">
            <div class="pub-hero-row-ico" style="background:#E0F2FE;color:#0C4A6E;">🎥</div>
            <div class="pub-hero-row-info">
              <div class="pub-hero-row-title">Accompagnement Vidéo</div>
              <div class="pub-hero-row-sub">Avancement 45% · 4 étapes restantes</div>
            </div>
            <span class="pub-hero-row-pill" style="background:#D1FAE5;color:#065F46;">Actif</span>
          </div>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- ============================================================ -->
<!-- BLOC SIGNATURE -->
<!-- ============================================================ -->
<!-- ============================================================ -->
<!-- SPLITTER : 2 chemins (Asso / TPE) -->
<!-- ============================================================ -->
<section class="pub-section ak-splitter">
  <div class="pub-container">
    <p class="ak-split-intro">Deux usages. Une même promesse : <strong>vous faire gagner du temps</strong>.</p>
    <div class="ak-split-grid">
      <a href="/fonctionnalites#asso" class="ak-split-card ak-split-asso">
        <div class="ak-split-ico">🏛️</div>
        <h2>Vous êtes une <em>association</em> ?</h2>
        <p class="ak-split-punch">Moins de paperasse. <strong>Plus d'impact associatif.</strong></p>
        <p>Gérez vos <strong>adhérents, projets, AG, émargements, subventions, documents</strong> et emails plus simplement, avec l'IA.</p>
        <ul class="ak-split-targets">
          <li>Associations loi 1901</li>
          <li>Clubs sportifs</li>
          <li>Associations culturelles</li>
          <li>Associations étudiantes &amp; solidaires</li>
          <li>Fédérations &amp; réseaux</li>
        </ul>
        <div class="ak-split-stats">
          <div class="ak-split-stat"><strong>200+</strong><span>assos en France</span></div>
          <div class="ak-split-stat"><strong>14j</strong><span>essai gratuit</span></div>
          <div class="ak-split-stat"><strong>4,9/5</strong><span>satisfaction</span></div>
        </div>
        <span class="ak-split-cta">Découvrir Assokit pour associations →</span>
      </a>
      <a href="/fonctionnalites#tpe" class="ak-split-card ak-split-tpe">
        <div class="ak-split-ico">🛠️</div>
        <h2>Vous êtes une <em>TPE</em> ou indépendant ?</h2>
        <p>Créez vos <strong>devis, factures, relances, communications clients</strong> et automatisez votre suivi commercial avec l'IA.</p>
        <ul class="ak-split-targets">
          <li>TPE locales</li>
          <li>Indépendants &amp; freelances</li>
          <li>Artisans &amp; commerçants</li>
          <li>Consultants, coachs &amp; thérapeutes</li>
          <li>Petites structures de service</li>
        </ul>
        <div class="ak-split-stats">
          <div class="ak-split-stat"><strong>+35%</strong><span>recouvrement</span></div>
          <div class="ak-split-stat"><strong>1h+</strong><span>économisée/jour</span></div>
          <div class="ak-split-stat"><strong>0€</strong><span>setup</span></div>
        </div>
        <span class="ak-split-cta">Découvrir Assokit pour TPE →</span>
      </a>
    </div>
  </div>
  
</section>

<!-- ============================================================ -->
<!-- ANTI-CONCURRENCE : pourquoi Assokit -->
<!-- ============================================================ -->
<section class="ak-anticonc-sec">
  <div class="pub-container">
    <div class="ak-anticonc">
      <div class="ak-anticonc-icon">💡</div>
      <p>Contrairement aux outils limités à la collecte de dons ou aux simples tableurs, <strong>Assokit centralise la gestion complète de votre structure</strong> : contacts, adhérents, factures, projets, emails, relances et IA. Un seul outil, un seul abonnement, zéro paperasse.</p>
    </div>
  </div>
  
</section>

<section class="pub-section pub-section-creme">
  <div class="pub-container-narrow pub-text-center">
    <span class="pub-section-eyebrow">L'allié quotidien</span>
    <h2 class="pub-h2">Tout ce qu'il faut pour faire <em>avancer votre projet</em>.</h2>
    <p class="pub-promise-strong">Assokit remplace les <strong>tableurs dispersés</strong>, les <strong>relances oubliées</strong> et les outils qui ne se parlent pas.</p>
    <p class="pub-section-lead" style="max-width:680px;margin:0 auto;">
      Assokit, c'est l'allié quotidien des <strong>associations loi 1901</strong> et des <strong>petites entreprises engagées</strong>.<br>
      Facturation, trésorerie, communication, adhérents, courriers officiels — tout ce qu'il faut pour faire avancer votre projet ou votre entreprise, réuni dans un outil pensé en France, simple à prendre en main, et accompagné par une vraie équipe humaine.
    </p>
  </div>
</section>

<!-- ============================================================ -->
<!-- 7 PROMESSES (mini-desc en italique + bodies sans redondance) -->
<!-- ============================================================ -->
<section class="pub-section">
  <div class="pub-container">
    <div class="pub-section-head">
      <span class="pub-section-eyebrow">Pourquoi Assokit</span>
      <h2 class="pub-h2">Sept raisons de <em>choisir Assokit</em>.</h2>
      <p class="pub-section-lead">Plus qu'un logiciel : <strong>un copilote pour gérer, relancer, communiquer et avancer</strong>.</p>
    </div>

    <div class="pub-promises">

      <!-- 1 — Fini les impayés -->
      <div class="pub-promise">
        <span class="pub-promise-num">01</span>
        <div class="pub-promise-ico">💸</div>
        <h3>Fini les impayés qui dorment</h3>
        <p class="pub-promise-tagline">Relances automatiques · récupération sans effort · trésorerie au rendez-vous.</p>
        <p>À J+15, J+30 puis J+45, vos clients ou adhérents reçoivent un email parfaitement rédigé, à votre nom. Vous voyez les paiements arriver. Le reste se fait tout seul, en arrière-plan.</p>
      </div>

      <!-- 2 — IA -->
      <div class="pub-promise">
        <span class="pub-promise-num">02</span>
        <div class="pub-promise-ico">🤖</div>
        <h3>L'IA travaille avec vous, pas à votre place</h3>
        <p class="pub-promise-tagline">19 outils IA · vous décidez, elle exécute · qualité française.</p>
        <p>Newsletter, compte-rendu, statuts, lettre aux adhérents, post réseaux sociaux… Chaque outil produit un brouillon prêt en 30 secondes. Vous corrigez, validez, envoyez. Le ton reste le vôtre.</p>
      </div>

      <!-- 3 — NOUVEAU : Suivi de projets -->
      <div class="pub-promise">
        <span class="pub-promise-num">03</span>
        <div class="pub-promise-ico">📊</div>
        <h3>Suivi de projets simple pour vos adhérents et équipes</h3>
        <p class="pub-promise-tagline">Pensé pour les associations et les TPE qui font bouger les choses.</p>
        <p>Avancez sereinement sur ce qui compte vraiment. Tableaux de progression, échéances visibles, équipes assignées. <strong>Fini les groupes WhatsApp pas pro</strong> : ici tout est lisible et clair, pour tout le monde.</p>
      </div>

      <!-- 4 — Équipe humaine -->
      <div class="pub-promise">
        <span class="pub-promise-num">04</span>
        <div class="pub-promise-ico">💬</div>
        <h3>Une équipe humaine derrière l'écran</h3>
        <p class="pub-promise-tagline">Réponse en moins de 24h · pas de bot · vraies personnes.</p>
        <p>Une équipe basée à Évry, joignable par email à tout moment. Pas de ticket qui se perd, pas de chatbot qui boucle. Quelqu'un lit votre message, comprend votre contexte, vous répond.</p>
      </div>

      <!-- 5 — Données -->
      <div class="pub-promise">
        <span class="pub-promise-num">05</span>
        <div class="pub-promise-ico">🔒</div>
        <h3>Vos données restent les vôtres</h3>
        <p class="pub-promise-tagline">Hébergement français · RGPD natif · zéro revente.</p>
        <p>Vos données dorment dans des serveurs basés en France, chez O2switch. Aucune sortie hors UE, aucun partenaire commercial caché. Vous restez propriétaire de tout, à 100%, et exportable à tout moment.</p>
      </div>

      <!-- 6 — Évolutif -->
      <div class="pub-promise">
        <span class="pub-promise-num">06</span>
        <div class="pub-promise-ico">📈</div>
        <h3>Un outil qui grandit avec vous</h3>
        <p class="pub-promise-tagline">10 ou 2000 adhérents · 1 ou 50 projets · même outil.</p>
        <p>Vous démarrez petit, vous grandissez tranquillement. Aucun palier qui vous oblige à migrer, aucune fonction bloquée derrière un mur. Le même Assokit vous accompagne du jour 1 au jour 1000.</p>
      </div>

      <!-- 7 — Marque & Domaine personnalisé -->
      <div class="pub-promise">
        <span class="pub-promise-num">07</span>
        <div class="pub-promise-ico">🌐</div>
        <h3>Votre marque, partout</h3>
        <p class="pub-promise-tagline">Sous-domaine inclus · domaine perso à partir de +10€/mois · plateforme à votre image.</p>
        <p>Vos adhérents accèdent à <strong>tonasso.assokit.fr</strong> avec votre logo et vos couleurs. Ajoutez votre propre domaine (<strong>adherents.tonasso.fr</strong>) et vos emails à votre nom pour <strong>+10€/mois HT seulement</strong>.</p>
      </div>

    </div>
  </div>
</section>

<!-- ============================================================ -->
<!-- PACK ASSO — Vie associative complète -->
<!-- ============================================================ -->
<!-- COMPTA ANALYTIQUE (ajout) -->
<section class="pub-section">
  <div class="pub-container">
    <div class="pub-section-head">
      <span class="pub-section-eyebrow">Comptabilité analytique incluse</span>
      <h2 class="pub-h2">Votre compta analytique, <em>incluse — sans comptable</em></h2>
      <p class="pub-section-lead">La comptabilité analytique coûte ~900 €/an chez un comptable. Dès l'offre Pro, elle est <strong>incluse</strong> — votre expert-comptable n'intervient plus que pour valider les comptes.</p>
    </div>
    <div class="ake-compare">
      <article class="ake-card ake-card--sans">
        <div class="ake-card__top">
          <span class="ake-ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 21h18"/><path d="M5 21V7l8-4v18"/><path d="M19 21V11l-6-4"/><path d="M9 9v.01M9 12v.01M9 15v.01M9 18v.01"/></svg></span>
          <div><h3>Sans AssoKit</h3><span>Compta analytique externalisée</span></div>
        </div>
        <div class="ake-price"><b>~900&nbsp;€</b><em>/ an en moyenne</em></div>
        <ul class="ake-list">
          <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="m15 9-6 6M9 9l6 6"/></svg> Prestation facturée chaque année</li>
          <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="m15 9-6 6M9 9l6 6"/></svg> Allers-retours, délais, ressaisie</li>
          <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="m15 9-6 6M9 9l6 6"/></svg> Aucune vision en temps réel</li>
        </ul>
      </article>
      <div class="ake-vs">VS</div>
      <article class="ake-card ake-card--avec">
        <span class="ake-tag">OFFRE PRO</span>
        <div class="ake-card__top">
          <span class="ake-ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 12A10 10 0 1 1 12 2"/><path d="M22 4 12 14.01l-3-3"/></svg></span>
          <div><h3>Avec AssoKit</h3><span>Incluse dès l'offre Pro</span></div>
        </div>
        <div class="ake-price"><b>Incluse</b><em><span class="ake-strike">~900&nbsp;€</span> dans votre abonnement</em></div>
        <ul class="ake-list">
          <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg> Bilan analytique <strong>illimité</strong>, par projet &amp; poste</li>
          <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg> Mis à jour en temps réel, export PDF / Excel</li>
          <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg> Votre expert-comptable valide en quelques clics</li>
        </ul>
      </article>
    </div>
    <div class="pub-text-center" style="margin-top:34px;">
      <a class="pub-btn pub-btn-primary pub-btn-lg" href="/comptabilite-analytique" style="margin:4px 6px;">Économisez jusqu'à 900&nbsp;€/an <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M13 6l6 6-6 6"/></svg></a>
      <a class="pub-btn pub-btn-ghost pub-btn-lg" href="/tarifs" style="margin:4px 6px;">Voir les tarifs</a>
    </div>
  </div>
</section>

<section class="pub-section">
  <div class="pub-container">
    <div class="pub-section-head">
      <span class="pub-section-eyebrow">Pack Asso</span>
      <h2 class="pub-h2">La vie associative <em>complète</em>, en un seul outil.</h2>
      <p class="pub-promise-strong">Tout ce qui prend du temps dans une association devient <strong>plus simple, plus clair et mieux suivi</strong>.</p>
      <p class="pub-section-lead">Quatre modules pensés pour les associations actives : AG, émargement, subventions, coach IA. Sans plug-in, sans paperasse.</p>
    </div>

    <div class="pa-grid">
      <div class="pa-card pa-card-emerald">
        <div class="pa-icon">🏛️</div>
        <h3>Assemblées Générales en ligne</h3>
        <p>Convoquez vos adhérents par email, suivez les présents/excusés, organisez des votes en ligne (majorité simple, qualifiée 2/3, 3/4, unanime). <strong>Signature électronique</strong> tactile, génération automatique du PV PDF.</p>
        <ul class="pa-features">
          <li>✓ Convocation par email avec lien personnel</li>
          <li>✓ Quorum auto-calculé en temps réel</li>
          <li>✓ Votes anonymisés et résultats live</li>
          <li>✓ PV PDF généré en 1 clic</li>
        </ul>
      </div>

      <div class="pa-card pa-card-blue">
        <div class="pa-icon">✍️</div>
        <h3>Émargement par QR code</h3>
        <p>Affichez un QR code à l'entrée d'un cours, d'une formation ou d'une réunion. Vos participants <strong>scannent et signent en 5 secondes</strong>, vous récupérez la feuille de présence en PDF ou CSV.</p>
        <ul class="pa-features">
          <li>✓ QR code généré automatiquement</li>
          <li>✓ Signature manuscrite tactile (mobile)</li>
          <li>✓ Anti-doublon, IP enregistrée</li>
          <li>✓ Export PDF feuille officielle</li>
        </ul>
      </div>

      <div class="pa-card pa-card-amber">
        <div class="pa-icon">💶</div>
        <h3>Subventions & rappels</h3>
        <p>Suivez vos demandes de subvention de A à Z : dépôt, suivi, accord, bilan. <strong>Rappels automatiques</strong> J-7 avant échéance, J-30 avant bilan. Relancez les financeurs en 1 clic depuis votre BAL.</p>
        <ul class="pa-features">
          <li>✓ État, région, département, EPCI, fondations…</li>
          <li>✓ Statut visuel et checklist</li>
          <li>✓ Rappels email J-7 / J-1 / J-30</li>
          <li>✓ Activity log par subvention</li>
        </ul>
      </div>

      <div class="pa-card pa-card-violet">
        <div class="pa-icon">✨</div>
        <h3>Coach Assokit IA hebdomadaire</h3>
        <p>Chaque lundi à 8h, recevez par email votre <strong>rapport hebdo personnalisé</strong> : ce qui a bougé, les alertes, les 3 actions prioritaires de la semaine. Comme un consultant qui connaît votre asso.</p>
        <ul class="pa-features">
          <li>✓ Analyse projets, retards, deadlines</li>
          <li>✓ 3 recommandations actionnables</li>
          <li>✓ Email automatique aux admins</li>
          <li>✓ Historique consultable à tout moment</li>
        </ul>
      </div>
    </div>

    <div class="pub-text-center" style="margin-top:36px;">
      <a href="/signup" class="pub-btn pub-btn-primary pub-btn-lg">Démarrer l'essai gratuit →</a>
    </div>
  </div>

  
</section>

<!-- ============================================================ -->
<!-- FONCTIONNALITÉS (résumé homepage) -->
<!-- ============================================================ -->
<section class="pub-section">
  <div class="pub-container">
    <div class="pub-section-head">
      <span class="pub-section-eyebrow">Fonctionnalités</span>
      <h2 class="pub-h2">Tout ce qu'il faut, <em>réuni au même endroit</em>.</h2>
      <p class="pub-promise-strong">Moins de logiciels ouverts. <strong>Plus de décisions prises.</strong></p>
      <p class="pub-section-lead">Quatre modules clés et tous les essentiels pour piloter votre quotidien sans jamais changer d'outil.</p>
    </div>

    <div class="pub-features">
      <div class="pub-feature">
        <div class="pub-feature-ico" style="background:#FEE2E2;color:#991B1B;">🗂️</div>
        <h3>Projets & équipes</h3>
        <p>Suivi des projets, équipes assignées, échéances, progression visuelle. Tout le monde voit où on en est.</p>
      </div>
      <div class="pub-feature">
        <div class="pub-feature-ico" style="background:#D1FAE5;color:#059669;">📋</div>
        <h3>Facturation, devis & relances auto</h3>
        <p>Devis avec signature, factures multi-lignes, <strong>relances automatiques J+15/30/45</strong>, factures récurrentes.</p>
      </div>
      <div class="pub-feature">
        <div class="pub-feature-ico" style="background:#F3E8FF;color:#7E22CE;">🤖</div>
        <h3>IA Communication & Emailing</h3>
        <p>19 outils IA dans 6 thématiques + diffusion email ciblée par rôle, par projet ou par liste.</p>
      </div>
      <div class="pub-feature">
        <div class="pub-feature-ico" style="background:#FEF3C7;color:#92400E;">📊</div>
        <h3>Tableau de bord intelligent</h3>
        <p>KPIs en temps réel, graphiques, top clients, statistiques avancées par projet et par période.</p>
      </div>
      <div class="pub-feature">
        <div class="pub-feature-ico" style="background:#E0F2FE;color:#0C4A6E;">👥</div>
        <h3>Adhérents & bénévoles</h3>
        <p>Annuaire intelligent, rôles personnalisés, espaces dédiés, relances cotisations automatiques.</p>
      </div>
      <div class="pub-feature">
        <div class="pub-feature-ico" style="background:#FCE7F3;color:#9D174D;">💰</div>
        <h3>Trésorerie & comptabilité</h3>
        <p>Suivi de votre solde, dépenses par projet, export comptable. Plus jamais de tableur cassé.</p>
      </div>
    </div>

    <div class="pub-text-center pub-mt-lg">
      <a href="/fonctionnalites" class="pub-btn pub-btn-primary pub-btn-lg">Voir toutes les fonctionnalités →</a>
    </div>
  </div>
</section>

<!-- ============================================================ -->
<!-- TESTIMONIALS -->
<!-- ============================================================ -->
<section class="pub-section pub-section-creme">
  <div class="pub-container">
    <div class="pub-section-head">
      <span class="pub-section-eyebrow">Ils en parlent</span>
      <h2 class="pub-h2">Adopté par des structures <em>qui font</em>.</h2>
      <p class="pub-promise-strong">Des associations et petites entreprises qui ont <strong>remplacé le désordre par une organisation claire</strong>.</p>
    </div>

    <div class="pub-testimonials">
      <div class="pub-testimonial">
        <div class="pub-testimonial-stars">★★★★★</div>
        <p>« On gérait tout sur trois tableurs et un Drive. En une semaine, Assokit a remplacé tout ça. L'IA pour les newsletters, c'est bluffant. »</p>
        <div class="pub-testimonial-author">
          <div class="pub-testimonial-avatar" style="background:#059669;">CM</div>
          <div>
            <div class="pub-testimonial-name">Claire M.</div>
            <div class="pub-testimonial-role">Présidente · Asso culturelle</div>
          </div>
        </div>
      </div>

      <div class="pub-testimonial">
        <div class="pub-testimonial-stars">★★★★★</div>
        <p>« Je suis artisan, je n'avais pas le temps pour la facturation. Assokit envoie les relances, génère mes courriers. Je gagne 8h par semaine. »</p>
        <div class="pub-testimonial-author">
          <div class="pub-testimonial-avatar" style="background:#7E22CE;">TR</div>
          <div>
            <div class="pub-testimonial-name">Thomas R.</div>
            <div class="pub-testimonial-role">Artisan ébéniste · TPE</div>
          </div>
        </div>
      </div>

      <div class="pub-testimonial">
        <div class="pub-testimonial-stars">★★★★★</div>
        <p>« Le suivi de projets remplace nos 3 groupes WhatsApp. Tout le monde voit l'avancement, plus personne ne dit "j'ai pas vu". »</p>
        <div class="pub-testimonial-author">
          <div class="pub-testimonial-avatar" style="background:#F59E0B;">SB</div>
          <div>
            <div class="pub-testimonial-name">Sophie B.</div>
            <div class="pub-testimonial-role">Trésorière · Asso sportive</div>
          </div>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- ============================================================ -->
<!-- FAQ (rich results Google : FAQPage) -->
<!-- ============================================================ -->
<section class="pub-section pub-section-creme ak-faq" id="faq">
  <div class="pub-container">
    <div class="pub-section-head">
      <span class="pub-section-eyebrow">Questions fréquentes</span>
      <h2 class="pub-h2">Tout ce que vous devez savoir sur Assokit</h2>
      <p class="pub-section-lead">Logiciel association loi 1901 et TPE, tarifs, sécurité, application mobile : les réponses aux questions les plus posées.</p>
    </div>
    <div class="ak-faq-list">
      <?php foreach ($home_faqs as $i => $f): ?>
      <details class="ak-faq-item"<?= $i === 0 ? ' open' : '' ?>>
        <summary class="ak-faq-q"><?= pub_h($f['q']) ?><span class="ak-faq-chevron" aria-hidden="true">›</span></summary>
        <div class="ak-faq-a"><p><?= pub_h($f['a']) ?></p></div>
      </details>
      <?php endforeach; ?>
    </div>
    <p class="ak-faq-more">Une autre question ? <a href="/contact">Contactez-nous</a> — réponse sous 24 h.</p>
  </div>
</section>

<style>
.ak-faq-list { max-width: 820px; margin: 0 auto; display: flex; flex-direction: column; gap: 12px; }
.ak-faq-item { background: #fff; border: 1px solid #E7EDEA; border-radius: 16px; padding: 4px 20px; box-shadow: 0 6px 18px -14px rgba(11,59,42,.25); transition: border-color .2s; }
.ak-faq-item[open] { border-color: #A7F3D0; }
.ak-faq-q { cursor: pointer; list-style: none; font-size: 17px; font-weight: 700; color: #064E3B; padding: 16px 0; display: flex; align-items: center; justify-content: space-between; gap: 14px; letter-spacing: -.01em; }
.ak-faq-q::-webkit-details-marker { display: none; }
.ak-faq-chevron { font-size: 26px; line-height: 1; color: #059669; transform: rotate(90deg); transition: transform .2s; flex: none; }
.ak-faq-item[open] .ak-faq-chevron { transform: rotate(-90deg); }
.ak-faq-a { padding: 0 0 18px; }
.ak-faq-a p { margin: 0; font-size: 15.5px; line-height: 1.7; color: #475569; }
.ak-faq-more { text-align: center; margin: 30px 0 0; font-size: 15px; color: #6b7280; }
.ak-faq-more a { color: #059669; font-weight: 700; text-decoration: none; }
.ak-faq-more a:hover { text-decoration: underline; }
@media (max-width: 640px) { .ak-faq-q { font-size: 15.5px; } .ak-faq-item { padding: 2px 16px; } }
</style>

<!-- ============================================================ -->
<!-- CTA FINAL -->
<!-- ============================================================ -->



<section class="pub-section ak-cta-final">
  <div class="pub-container">
    <div class="ak-cta-card">
      <span class="ak-cta-eyebrow">✨ Essai gratuit · sans carte bancaire</span>
      <h2 class="ak-cta-title">Reprenez le contrôle de votre gestion. <em>Sans complexité.</em></h2>
      <p class="ak-cta-lead">Essayez Assokit gratuitement pendant <strong>14 jours</strong> et découvrez comment votre association ou votre TPE peut gagner du temps dès la première semaine.</p>
      <div class="ak-cta-actions">
        <a href="/signup" class="pub-btn pub-btn-primary pub-btn-lg">Démarrer l'essai gratuit →</a>
        <a href="/contact?demo=1" class="pub-btn pub-btn-ghost pub-btn-lg">Réserver une démo</a>
      </div>
      <div class="ak-cta-trust">
        <span>🇫🇷 Hébergé en France</span>
        <span>🔒 Conforme RGPD</span>
        <span>⚡ Support &lt;24h</span>
        <span>💚 Sans engagement</span>
      </div>
    </div>
  </div>
  <style>
  .ak-cta-final { padding: 60px 0 80px; }
  .ak-cta-card { max-width: 880px; margin: 0 auto; padding: 56px 48px; background: linear-gradient(135deg, #064E3B 0%, #065F46 50%, #047857 100%); border-radius: 28px; text-align: center; color: #fff; position: relative; overflow: hidden; box-shadow: 0 24px 60px rgba(6, 78, 59, 0.25); }
  .ak-cta-card::before { content: ""; position: absolute; top: -40%; right: -10%; width: 60%; height: 200%; background: radial-gradient(circle, rgba(167, 243, 208, 0.18) 0%, transparent 70%); pointer-events: none; }
  .ak-cta-card::after { content: ""; position: absolute; bottom: -30%; left: -10%; width: 50%; height: 150%; background: radial-gradient(circle, rgba(110, 231, 183, 0.12) 0%, transparent 70%); pointer-events: none; }
  .ak-cta-eyebrow { display: inline-block; padding: 7px 18px; background: rgba(167, 243, 208, 0.18); color: #A7F3D0; font-size: 12.5px; font-weight: 700; border-radius: 999px; letter-spacing: 0.04em; text-transform: uppercase; margin-bottom: 20px; position: relative; z-index: 1; }
  .ak-cta-title { font-size: 38px; font-weight: 800; line-height: 1.15; margin: 0 0 18px; letter-spacing: -0.02em; color: #fff; position: relative; z-index: 1; }
  .ak-cta-title em { font-style: normal; color: #A7F3D0; }
  .ak-cta-lead { font-size: 16.5px; line-height: 1.6; color: #D1FAE5; max-width: 620px; margin: 0 auto 32px; position: relative; z-index: 1; }
  .ak-cta-lead strong { color: #fff; font-weight: 700; }
  .ak-cta-actions { display: flex; gap: 14px; justify-content: center; flex-wrap: wrap; position: relative; z-index: 1; margin-bottom: 28px; }
  .ak-cta-actions .pub-btn-primary { background: #fff !important; color: #064E3B !important; border-color: #fff !important; }
  .ak-cta-actions .pub-btn-primary:hover { background: #ECFDF5 !important; transform: translateY(-2px); box-shadow: 0 10px 24px rgba(0,0,0,0.18); }
  .ak-cta-actions .pub-btn-ghost { background: transparent !important; color: #fff !important; border: 1.5px solid rgba(255,255,255,0.45) !important; }
  .ak-cta-actions .pub-btn-ghost:hover { background: rgba(255,255,255,0.1) !important; border-color: #fff !important; }
  .ak-cta-trust { display: flex; gap: 18px; justify-content: center; flex-wrap: wrap; font-size: 13px; color: rgba(255,255,255,0.85); position: relative; z-index: 1; padding-top: 24px; border-top: 1px solid rgba(255,255,255,0.15); }
  @media (max-width: 720px) {
    .ak-cta-card { padding: 40px 24px; border-radius: 20px; }
    .ak-cta-title { font-size: 28px; }
    .ak-cta-lead { font-size: 15px; }
    .ak-cta-actions .pub-btn { width: 100%; }
    .ak-cta-trust { gap: 10px; font-size: 12px; }
  }
  
/* === SPLITTER PREMIUM MOBILE v2 === */
@media (max-width: 768px) {
  .ak-splitter { padding: 36px 0 28px !important; }
  .ak-split-intro { font-size: 15px !important; line-height: 1.55 !important; padding: 0 8px; margin-bottom: 28px !important; }
  .ak-split-grid { gap: 18px !important; padding: 0 4px; grid-template-columns: 1fr !important; }
  .ak-split-card {
    border-radius: 22px !important;
    padding: 28px 22px 24px !important;
    border: 1.5px solid #e5e7eb !important;
    background: linear-gradient(180deg, #fff 0%, color-mix(in srgb, var(--ak-split-tone) 3%, #fff) 100%) !important;
    box-shadow:
      0 1px 2px rgba(17,24,39,0.04),
      0 8px 24px rgba(17,24,39,0.05),
      0 16px 40px color-mix(in srgb, var(--ak-split-tone) 10%, transparent) !important;
  }
  .ak-split-card::before {
    height: 5px !important;
    background: linear-gradient(90deg, var(--ak-split-tone), color-mix(in srgb, var(--ak-split-tone) 55%, #fff)) !important;
    border-radius: 22px 22px 0 0 !important;
  }
  .ak-split-ico {
    width: 64px !important; height: 64px !important;
    border-radius: 18px !important;
    background: linear-gradient(135deg,
      color-mix(in srgb, var(--ak-split-tone) 22%, #fff) 0%,
      color-mix(in srgb, var(--ak-split-tone) 8%, #fff) 100%) !important;
    font-size: 30px !important;
    box-shadow:
      inset 0 1px 0 rgba(255,255,255,0.7),
      0 8px 20px color-mix(in srgb, var(--ak-split-tone) 22%, transparent),
      0 0 0 6px color-mix(in srgb, var(--ak-split-tone) 5%, transparent) !important;
    margin-bottom: 18px !important;
  }
  .ak-split-card h2 {
    font-size: 22px !important;
    line-height: 1.18 !important;
    font-weight: 800 !important;
    letter-spacing: -0.01em !important;
    margin: 0 0 10px !important;
  }
  .ak-split-punch {
    font-size: 14.5px !important;
    line-height: 1.45 !important;
    font-weight: 600 !important;
    margin: 0 0 14px !important;
  }
  .ak-split-card > p:not(.ak-split-punch) {
    font-size: 14.5px !important;
    line-height: 1.65 !important;
    color: #374151 !important;
    margin: 0 0 18px !important;
  }
  .ak-split-targets {
    margin: 16px 0 !important;
    padding: 16px 0 !important;
    border-top: 1px solid #f3f4f6 !important;
    border-bottom: 1px solid #f3f4f6 !important;
    gap: 10px !important;
    list-style: none !important;
  }
  .ak-split-targets li {
    font-size: 14px !important;
    color: #1f2937 !important;
    padding-left: 28px !important;
    line-height: 1.4 !important;
    position: relative !important;
  }
  .ak-split-targets li::before {
    width: 18px !important; height: 18px !important;
    background: color-mix(in srgb, var(--ak-split-tone) 14%, #fff) !important;
    color: var(--ak-split-tone) !important;
    font-weight: 800 !important;
    font-size: 11px !important;
    border-radius: 50% !important;
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    box-shadow: 0 0 0 3px color-mix(in srgb, var(--ak-split-tone) 5%, transparent) !important;
    position: absolute !important;
    left: 0 !important; top: 1px !important;
  }
  .ak-split-stats {
    border: none !important;
    background: color-mix(in srgb, var(--ak-split-tone) 6%, #fff) !important;
    border-radius: 14px !important;
    padding: 10px 16px !important;
    margin-top: 18px !important;
    list-style: none !important;
    display: block !important;
    grid-template-columns: none !important;
  }
  .ak-split-stats li {
    padding: 10px 0 !important;
    border-bottom: 1px solid color-mix(in srgb, var(--ak-split-tone) 12%, transparent) !important;
    display: flex !important;
    justify-content: space-between !important;
    align-items: center !important;
  }
  .ak-split-stats li:last-child { border-bottom: none !important; }
  .ak-split-stats strong {
    font-size: 20px !important;
    font-weight: 800 !important;
    color: var(--ak-split-tone) !important;
  }
  .ak-split-stats span {
    font-size: 10.5px !important;
    letter-spacing: 0.08em !important;
    font-weight: 700 !important;
    color: #6b7280 !important;
    text-transform: uppercase !important;
  }
  .ak-split-cta {
    display: inline-flex !important;
    align-items: center !important;
    gap: 8px !important;
    margin-top: 18px !important;
    padding: 12px 22px !important;
    background: var(--ak-split-tone) !important;
    color: #fff !important;
    border-radius: 12px !important;
    font-weight: 700 !important;
    font-size: 14.5px !important;
    box-shadow:
      0 4px 14px color-mix(in srgb, var(--ak-split-tone) 35%, transparent),
      inset 0 1px 0 rgba(255,255,255,0.2) !important;
    text-decoration: none !important;
    width: 100% !important;
    justify-content: center !important;
    transform: none !important;
  }
}
/* === FIN SPLITTER PREMIUM MOBILE v2 === */

</style>
</section>

<?php
render_public_footer();
render_public_foot();
