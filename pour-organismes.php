<?php
/**
 * pour-organismes.php — Page vitrine B2G
 * Cible : Mairie / Département / Préfecture / CAF
 * Présente le pilotage des associations du territoire + mockups dashboard.
 */
require_once __DIR__ . '/includes-public.php';
$breadcrumb = build_breadcrumb_jsonld([
    ['name' => 'Accueil',    'url' => '/'],
    ['name' => 'Pour les organismes', 'url' => '/pour-organismes'],
]);
render_public_head([
    'title'       => 'Assokit pour les collectivités · Piloter les associations de votre territoire',
    'description' => 'Mairies, départements, préfectures, CAF : centralisez le suivi des associations de votre territoire. Annuaire, subventions, messagerie, emailing et tableau de bord en temps réel.',
    'path'        => '/pour-organismes',
    'og_image'    => AK_PUBLIC_DOMAIN . '/assets/og-organismes.jpg',
    'schema_jsonld' => [$breadcrumb],
]);
render_public_nav('');
?>
<style>
/* ===== Mockups & sections page organismes ===== */
.org-accent { color:#1D4ED8; }
.org-hero { background:linear-gradient(180deg,#EFF6FF 0%,#fff 100%); padding:64px 0 48px; }
.org-badge-row { display:flex; flex-wrap:wrap; gap:10px; margin-top:22px; }
.org-badge { display:inline-flex; align-items:center; gap:7px; background:#fff; border:1px solid #DBEAFE; color:#1E3A8A; border-radius:999px; padding:8px 16px; font-size:14px; font-weight:600; }
.org-who-grid { display:grid; grid-template-columns:repeat(4,1fr); gap:18px; margin-top:36px; }
.org-who-card { background:#fff; border:1px solid #E2E8F0; border-radius:16px; padding:24px 20px; transition:transform .15s,box-shadow .15s; }
.org-who-card:hover { transform:translateY(-3px); box-shadow:0 12px 30px rgba(30,64,175,.10); }
.org-who-ico { font-size:30px; margin-bottom:10px; }
.org-who-card h3 { font-size:17px; margin:0 0 6px; color:#0F172A; }
.org-who-card p { font-size:13.5px; color:#64748B; line-height:1.55; margin:0; }
/* Cadre façon application */
.org-frame { background:#fff; border:1px solid #E2E8F0; border-radius:16px; overflow:hidden; box-shadow:0 20px 50px rgba(15,23,42,.08); }
.org-frame-bar { background:#F8FAFC; border-bottom:1px solid #E2E8F0; padding:11px 16px; display:flex; align-items:center; gap:8px; }
.org-frame-dot { width:11px; height:11px; border-radius:50%; }
.org-frame-title { margin-left:10px; font-size:12.5px; color:#94A3B8; font-weight:600; }
.org-frame-body { padding:22px; }
/* KPI */
.org-kpis { display:grid; grid-template-columns:repeat(4,1fr); gap:12px; }
.org-kpi { background:#F8FAFC; border:1px solid #EEF2F7; border-radius:12px; padding:14px 16px; }
.org-kpi-val { font-size:24px; font-weight:800; color:#0F172A; line-height:1.1; }
.org-kpi-lbl { font-size:12px; color:#64748B; margin-top:3px; }
.org-kpi-trend { font-size:11px; font-weight:700; color:#059669; margin-top:6px; }
/* Liste assos */
.org-row { display:flex; align-items:center; justify-content:space-between; padding:11px 0; border-top:1px solid #F1F5F9; }
.org-row:first-child { border-top:none; }
.org-row-left { display:flex; align-items:center; gap:11px; }
.org-avatar { width:34px; height:34px; border-radius:9px; display:flex; align-items:center; justify-content:center; font-weight:700; color:#fff; font-size:13px; }
.org-row-name { font-size:14px; font-weight:600; color:#0F172A; }
.org-row-meta { font-size:12px; color:#94A3B8; }
.org-pill { font-size:11px; font-weight:700; padding:4px 10px; border-radius:999px; }
.org-pill-ok { background:#DCFCE7; color:#15803D; }
.org-pill-wait { background:#FEF3C7; color:#B45309; }
/* Barres */
.org-bar-row { display:flex; align-items:center; gap:12px; margin-bottom:12px; }
.org-bar-lbl { font-size:13px; color:#475569; width:130px; flex-shrink:0; }
.org-bar-track { flex:1; background:#F1F5F9; border-radius:999px; height:10px; overflow:hidden; }
.org-bar-fill { height:100%; border-radius:999px; }
.org-bar-val { font-size:13px; font-weight:700; color:#0F172A; width:54px; text-align:right; }
/* Chat */
.org-chat-msg { max-width:78%; padding:10px 14px; border-radius:14px; font-size:13.5px; line-height:1.45; margin-bottom:10px; }
.org-chat-in { background:#F1F5F9; color:#0F172A; border-bottom-left-radius:4px; }
.org-chat-out { background:#1D4ED8; color:#fff; margin-left:auto; border-bottom-right-radius:4px; }
/* ===== RESPONSIVE MOBILE ===== */
@media (max-width:860px){
  .pub-features-grid-2{ grid-template-columns:1fr !important; gap:30px !important; }
  .pub-features-grid-2 > .org-frame{ order:2 !important; }
  .org-hero{ padding:44px 0 32px; }
}
@media (max-width:780px){
  .org-who-grid{ grid-template-columns:repeat(2,1fr); }
  .org-kpis{ grid-template-columns:repeat(2,1fr); }
  .org-frame-body{ padding:16px; }
  .org-chat-msg{ max-width:90%; }
}
@media (max-width:440px){
  .org-who-grid{ grid-template-columns:1fr; }
  .org-kpi-val{ font-size:20px; }
  .org-bar-lbl{ width:90px; font-size:12px; }
  .org-badge{ font-size:13px; padding:7px 13px; }
}
</style>

<!-- ===== HERO ===== -->
<section class="org-hero">
  <div class="pub-container">
    <div class="pub-breadcrumb">
      <a href="/">Accueil</a><span class="pub-breadcrumb-sep">›</span>
      <strong style="color:var(--c-encre);">Pour les organismes</strong>
    </div>
    <span class="pub-eyebrow" style="color:#1D4ED8;">🏛️ Collectivités &amp; organismes publics</span>
    <h1 class="pub-h1" style="max-width:820px;">Pilotez les <em>associations de votre territoire</em>, depuis un seul tableau de bord.</h1>
    <p class="pub-tagline" style="max-width:700px;">
      Mairies, départements, préfectures, CAF : centralisez l'annuaire associatif, le suivi des subventions, la communication et les échanges. <strong>Une vision claire, en temps réel.</strong>
    </p>
    <div class="org-badge-row">
      <span class="org-badge">🏛️ Mairie</span>
      <span class="org-badge">🗺️ Département</span>
      <span class="org-badge">🏢 Préfecture</span>
      <span class="org-badge">👨‍👩‍👧 CAF</span>
    </div>
  </div>
</section>

<!-- ===== POUR QUI ===== -->
<section class="pub-section">
  <div class="pub-container">
    <div class="pub-section-head" style="text-align:center;">
      <span class="pub-section-eyebrow" style="color:#1D4ED8;">À chaque organisme, ses usages</span>
      <h2 class="pub-h2">Un outil, plusieurs missions de service public</h2>
    </div>
    <div class="org-who-grid">
      <div class="org-who-card">
        <div class="org-who-ico">🏛️</div>
        <h3>Mairie</h3>
        <p>Recensez les associations communales, suivez vos subventions et communiquez en un clic avec tout le tissu associatif.</p>
      </div>
      <div class="org-who-card">
        <div class="org-who-ico">🗺️</div>
        <h3>Département</h3>
        <p>Vue consolidée des structures à l'échelle départementale, pilotage des aides et reporting pour les élus.</p>
      </div>
      <div class="org-who-card">
        <div class="org-who-ico">🏢</div>
        <h3>Préfecture</h3>
        <p>Suivi de la conformité, des déclarations et des subventions publiques sur l'ensemble du territoire.</p>
      </div>
      <div class="org-who-card">
        <div class="org-who-ico">👨‍👩‍👧</div>
        <h3>CAF</h3>
        <p>Accompagnez les structures financées, suivez les actions menées et fluidifiez les échanges de documents.</p>
      </div>
    </div>
  </div>
</section>

<!-- ===== FONCTION 1 : DASHBOARD TERRITOIRE ===== -->
<section class="pub-section pub-section-creme">
  <div class="pub-container">
    <div class="pub-features-grid-2" style="display:grid;grid-template-columns:1fr 1.1fr;gap:56px;align-items:center;">
      <div>
        <span class="pub-section-eyebrow" style="color:#1D4ED8;">Fonction 1 · Tableau de bord</span>
        <h2 class="pub-h2" style="text-align:left;">Toute la vie associative du territoire, <em>en un coup d'œil</em>.</h2>
        <p class="pub-section-lead" style="text-align:left;">Nombre d'associations, subventions versées, événements organisés, adhérents recensés : vos indicateurs clés se mettent à jour en temps réel, sans tableur à maintenir.</p>
        <ul class="pub-features-checklist">
          <li>✓ Indicateurs consolidés en temps réel</li>
          <li>✓ Répartition par domaine d'activité</li>
          <li>✓ Suivi des subventions versées</li>
          <li>✓ Export pour vos rapports aux élus</li>
        </ul>
      </div>
      <div class="org-frame">
        <div class="org-frame-bar">
          <span class="org-frame-dot" style="background:#F87171;"></span>
          <span class="org-frame-dot" style="background:#FBBF24;"></span>
          <span class="org-frame-dot" style="background:#34D399;"></span>
          <span class="org-frame-title">Tableau de bord — Mairie</span>
        </div>
        <div class="org-frame-body">
          <div class="org-kpis">
            <div class="org-kpi"><div class="org-kpi-val">142</div><div class="org-kpi-lbl">Associations</div><div class="org-kpi-trend">+8 cette année</div></div>
            <div class="org-kpi"><div class="org-kpi-val">1,2 M€</div><div class="org-kpi-lbl">Subventions versées</div><div class="org-kpi-trend">+4,2 %</div></div>
            <div class="org-kpi"><div class="org-kpi-val">318</div><div class="org-kpi-lbl">Événements / an</div><div class="org-kpi-trend">+12 %</div></div>
            <div class="org-kpi"><div class="org-kpi-val">24 580</div><div class="org-kpi-lbl">Adhérents</div><div class="org-kpi-trend">+1 940</div></div>
          </div>
          <div style="margin-top:22px;">
            <div style="font-size:13px;font-weight:700;color:#475569;margin-bottom:14px;">Associations par domaine</div>
            <div class="org-bar-row"><span class="org-bar-lbl">🏅 Sport</span><span class="org-bar-track"><span class="org-bar-fill" style="width:90%;background:#1D4ED8;"></span></span><span class="org-bar-val">42</span></div>
            <div class="org-bar-row"><span class="org-bar-lbl">🎭 Culture</span><span class="org-bar-track"><span class="org-bar-fill" style="width:75%;background:#7C3AED;"></span></span><span class="org-bar-val">35</span></div>
            <div class="org-bar-row"><span class="org-bar-lbl">🤝 Social</span><span class="org-bar-track"><span class="org-bar-fill" style="width:60%;background:#059669;"></span></span><span class="org-bar-val">28</span></div>
            <div class="org-bar-row"><span class="org-bar-lbl">🎲 Loisirs</span><span class="org-bar-track"><span class="org-bar-fill" style="width:45%;background:#EA580C;"></span></span><span class="org-bar-val">21</span></div>
            <div class="org-bar-row" style="margin-bottom:0;"><span class="org-bar-lbl">🌱 Environnement</span><span class="org-bar-track"><span class="org-bar-fill" style="width:34%;background:#0D9488;"></span></span><span class="org-bar-val">16</span></div>
          </div>
        </div>
      </div>
    </div>
  </div>
</section>

      <!-- ===== FONCTION 2 : SUIVI DE PROJET ===== -->
<section class="pub-section">
  <div class="pub-container">
    <div class="pub-features-grid-2" style="display:grid;grid-template-columns:1.1fr 1fr;gap:56px;align-items:center;">
      <div class="org-frame">
        <div class="org-frame-bar">
          <span class="org-frame-dot" style="background:#F87171;"></span>
          <span class="org-frame-dot" style="background:#FBBF24;"></span>
          <span class="org-frame-dot" style="background:#34D399;"></span>
          <span class="org-frame-title">Suivi des subventions · Mairie d'Évry</span>
        </div>
        <div class="org-frame-body">
          <div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:16px;">
            <span style="background:#1D4ED8;color:#fff;font-size:12px;font-weight:600;padding:6px 12px;border-radius:8px;">Latitude91</span>
            <span style="background:#F1F5F9;color:#64748B;font-size:12px;font-weight:600;padding:6px 12px;border-radius:8px;">ASCE Évry</span>
            <span style="background:#F1F5F9;color:#64748B;font-size:12px;font-weight:600;padding:6px 12px;border-radius:8px;">Croix-Rouge</span>
            <span style="background:#F1F5F9;color:#94A3B8;font-size:12px;font-weight:600;padding:6px 12px;border-radius:8px;">+139</span>
          </div>
          <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:14px;">
            <div><div style="font-size:16px;font-weight:700;color:#0F172A;">🎬 Tournage Ciné été</div>
            <div style="font-size:12px;color:#94A3B8;margin-top:2px;">Latitude91 · échéance août 2026</div></div>
            <span style="background:#DBEAFE;color:#1D4ED8;font-size:11px;font-weight:700;padding:4px 10px;border-radius:999px;white-space:nowrap;">En cours</span>
          </div>
          <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:14px;">
            <div style="flex:1;min-width:88px;background:#F8FAFC;border:1px solid #EEF2F7;border-radius:10px;padding:10px 12px;"><div style="font-size:11px;color:#64748B;">Alloué</div><div style="font-size:17px;font-weight:800;color:#0F172A;">2 000 €</div></div>
            <div style="flex:1;min-width:88px;background:#F8FAFC;border:1px solid #EEF2F7;border-radius:10px;padding:10px 12px;"><div style="font-size:11px;color:#64748B;">Engagé</div><div style="font-size:17px;font-weight:800;color:#1D4ED8;">1 000 €</div></div>
            <div style="flex:1;min-width:88px;background:#F8FAFC;border:1px solid #EEF2F7;border-radius:10px;padding:10px 12px;"><div style="font-size:11px;color:#64748B;">Disponible</div><div style="font-size:17px;font-weight:800;color:#059669;">1 000 €</div></div>
          </div>
          <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;">
            <span style="font-size:12px;font-weight:700;color:#475569;">Étapes du projet</span>
            <span style="font-size:12px;font-weight:700;color:#1D4ED8;">50 % · 3/6</span>
          </div>
          <div style="background:#F1F5F9;border-radius:999px;height:8px;overflow:hidden;margin-bottom:12px;"><div style="height:100%;width:50%;background:linear-gradient(90deg,#1D4ED8,#3B82F6);border-radius:999px;"></div></div>
          <div style="display:flex;align-items:center;gap:10px;padding:5px 0;"><span style="display:inline-flex;align-items:center;justify-content:center;width:18px;height:18px;border-radius:999px;background:#DCFCE7;color:#15803D;font-size:11px;font-weight:800;flex:none;">✓</span><span style="font-size:13px;color:#334155;">Écriture du script</span></div>
          <div style="display:flex;align-items:center;gap:10px;padding:5px 0;"><span style="display:inline-flex;align-items:center;justify-content:center;width:18px;height:18px;border-radius:999px;background:#DCFCE7;color:#15803D;font-size:11px;font-weight:800;flex:none;">✓</span><span style="font-size:13px;color:#334155;">Appel des organisateurs Ciné été</span></div>
          <div style="display:flex;align-items:center;gap:10px;padding:5px 0;"><span style="display:inline-flex;align-items:center;justify-content:center;width:18px;height:18px;border-radius:999px;background:#DCFCE7;color:#15803D;font-size:11px;font-weight:800;flex:none;">✓</span><span style="font-size:13px;color:#334155;">Prise de rendez-vous pour tournage</span></div>
          <div style="display:flex;align-items:center;gap:10px;padding:5px 0;"><span style="display:inline-flex;width:18px;height:18px;border-radius:999px;background:#1D4ED8;flex:none;box-shadow:0 0 0 3px #DBEAFE;"></span><span style="font-size:13px;color:#1D4ED8;font-weight:700;">Tournage</span></div>
          <div style="display:flex;align-items:center;gap:10px;padding:5px 0;"><span style="display:inline-flex;width:18px;height:18px;border-radius:999px;background:#fff;border:2px solid #E2E8F0;flex:none;"></span><span style="font-size:13px;color:#94A3B8;">Montage</span></div>
          <div style="display:flex;align-items:center;gap:10px;padding:5px 0;"><span style="display:inline-flex;width:18px;height:18px;border-radius:999px;background:#fff;border:2px solid #E2E8F0;flex:none;"></span><span style="font-size:13px;color:#94A3B8;">Diffusion</span></div>
          <div style="font-size:12px;font-weight:700;color:#475569;margin:14px 0 4px;">Factures rattachées</div>
          <div style="display:flex;justify-content:space-between;align-items:center;padding:9px 0;border-top:1px solid #F1F5F9;">
            <span style="font-size:13px;color:#0F172A;">📄 Location caméra Sony X58be</span>
            <span style="display:flex;align-items:center;gap:8px;"><span style="font-size:13px;font-weight:700;color:#0F172A;white-space:nowrap;">520 €</span><span style="background:#DCFCE7;color:#15803D;font-size:10px;font-weight:700;padding:3px 9px;border-radius:999px;white-space:nowrap;">Payée</span></span>
          </div>
          <div style="display:flex;justify-content:space-between;align-items:center;padding:9px 0;border-top:1px solid #F1F5F9;">
            <span style="font-size:13px;color:#0F172A;">📄 Essence · tournage jour 1</span>
            <span style="display:flex;align-items:center;gap:8px;"><span style="font-size:13px;font-weight:700;color:#0F172A;white-space:nowrap;">90 €</span><span style="background:#DCFCE7;color:#15803D;font-size:10px;font-weight:700;padding:3px 9px;border-radius:999px;white-space:nowrap;">Payée</span></span>
          </div>
          <div style="display:flex;justify-content:space-between;align-items:center;padding:9px 0;border-top:1px solid #F1F5F9;">
            <span style="font-size:13px;color:#0F172A;">📄 Essence · tournage jour 2</span>
            <span style="display:flex;align-items:center;gap:8px;"><span style="font-size:13px;font-weight:700;color:#0F172A;white-space:nowrap;">90 €</span><span style="background:#FEF3C7;color:#B45309;font-size:10px;font-weight:700;padding:3px 9px;border-radius:999px;white-space:nowrap;">⏳ En attente</span></span>
          </div>
          <div style="display:flex;justify-content:space-between;align-items:center;padding:9px 0;border-top:1px solid #F1F5F9;">
            <span style="font-size:13px;color:#0F172A;">📄 Perche de son</span>
            <span style="display:flex;align-items:center;gap:8px;"><span style="font-size:13px;font-weight:700;color:#0F172A;white-space:nowrap;">300 €</span><span style="background:#FEF3C7;color:#B45309;font-size:10px;font-weight:700;padding:3px 9px;border-radius:999px;white-space:nowrap;">⏳ En attente</span></span>
          </div>
        </div>
      </div>
      <div>
        <span class="pub-section-eyebrow" style="color:#1D4ED8;">Fonction 2 · Suivi de projet <span style="background:#FEF3C7;color:#B45309;padding:2px 9px;border-radius:999px;font-size:11px;margin-left:6px;">⚡ Game changer</span></span>
        <h2 class="pub-h2" style="text-align:left;">Suivez chaque euro, <em>du projet à la facture</em>.</h2>
        <p class="pub-section-lead" style="text-align:left;">Réunissez toutes vos associations au même endroit. Pour chacune, suivez ses projets étape par étape, le budget que vous avez alloué et chaque facture rattachée — et sachez <strong>en un coup d'œil</strong> où en est précisément le financement. La transparence sur l'usage des fonds publics devient automatique, et vos bilans semestriels se préparent tout seuls.</p>
        <ul class="pub-features-checklist">
          <li>✓ Toutes les associations réunies au même endroit</li>
          <li>✓ L’avancement du projet, étape par étape</li>
          <li>✓ Budget alloué, engagé et factures rattachées</li>
          <li>✓ Transparence totale sur les fonds publics</li>
        </ul>
      </div>
    </div>
  </div>
</section>

<!-- ===== FONCTION 3 : MESSAGERIE ===== -->
<section class="pub-section pub-section-creme">
  <div class="pub-container">
    <div class="pub-features-grid-2" style="display:grid;grid-template-columns:1fr 1.1fr;gap:56px;align-items:center;">
      <div>
        <span class="pub-section-eyebrow" style="color:#1D4ED8;">Fonction 3 · Messagerie</span>
        <h2 class="pub-h2" style="text-align:left;">Échangez en direct avec <em>chaque association</em>.</h2>
        <p class="pub-section-lead" style="text-align:left;">Fini les fils d'e-mails perdus. Une messagerie intégrée relie votre organisme à chaque association : demandes de documents, suivi de dossier, réponses en temps réel.</p>
        <ul class="pub-features-checklist">
          <li>✓ Conversations centralisées par association</li>
          <li>✓ Partage de documents sécurisé</li>
          <li>✓ Notifications en temps réel</li>
          <li>✓ Historique complet et traçable</li>
        </ul>
      </div>
      <div class="org-frame">
        <div class="org-frame-bar">
          <span class="org-frame-dot" style="background:#F87171;"></span>
          <span class="org-frame-dot" style="background:#FBBF24;"></span>
          <span class="org-frame-dot" style="background:#34D399;"></span>
          <span class="org-frame-title">Messagerie — Harmonie Musicale</span>
        </div>
        <div class="org-frame-body" style="background:#FBFCFE;">
          <div class="org-chat-msg org-chat-out">Bonjour, pourriez-vous nous transmettre votre bilan financier 2025 pour finaliser la subvention ?</div>
          <div class="org-chat-msg org-chat-in">Bonjour, bien sûr ! Le voici en pièce jointe 📎 bilan-2025.pdf</div>
          <div class="org-chat-msg org-chat-out">Parfait, tout est conforme. Subvention validée ✓</div>
          <div style="text-align:center;font-size:11px;color:#94A3B8;margin-top:4px;">Aujourd'hui · 14:32</div>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- ===== FONCTION 4 : EMAILING ===== -->
<section class="pub-section">
  <div class="pub-container">
    <div class="pub-features-grid-2" style="display:grid;grid-template-columns:1.1fr 1fr;gap:56px;align-items:center;">
      <div class="org-frame">
        <div class="org-frame-bar">
          <span class="org-frame-dot" style="background:#F87171;"></span>
          <span class="org-frame-dot" style="background:#FBBF24;"></span>
          <span class="org-frame-dot" style="background:#34D399;"></span>
          <span class="org-frame-title">Communication groupée</span>
        </div>
        <div class="org-frame-body">
          <div style="font-size:13px;color:#64748B;margin-bottom:6px;">Destinataires</div>
          <div style="display:flex;flex-wrap:wrap;gap:6px;margin-bottom:16px;">
            <span class="org-pill" style="background:#DBEAFE;color:#1E40AF;">🏅 Sport (42)</span>
            <span class="org-pill" style="background:#EDE9FE;color:#6D28D9;">🎭 Culture (35)</span>
            <span class="org-pill" style="background:#DCFCE7;color:#15803D;">🤝 Social (28)</span>
            <span class="org-pill" style="background:#F1F5F9;color:#475569;">+ 37 autres</span>
          </div>
          <div style="border:1px solid #E2E8F0;border-radius:10px;padding:14px;">
            <div style="font-weight:700;font-size:14px;color:#0F172A;margin-bottom:6px;">📅 Forum des associations 2026</div>
            <div style="font-size:13px;color:#64748B;line-height:1.5;">Chères associations, nous avons le plaisir de vous convier au Forum annuel le 7 septembre...</div>
          </div>
          <div style="display:flex;justify-content:space-between;align-items:center;margin-top:14px;">
            <span style="font-size:12px;color:#94A3B8;">142 associations ciblées</span>
            <span style="background:#1D4ED8;color:#fff;font-size:13px;font-weight:600;padding:8px 18px;border-radius:8px;">Envoyer</span>
          </div>
        </div>
      </div>
      <div>
        <span class="pub-section-eyebrow" style="color:#1D4ED8;">Fonction 4 · Emailing</span>
        <h2 class="pub-h2" style="text-align:left;">Informez tout le territoire <em>en un envoi</em>.</h2>
        <p class="pub-section-lead" style="text-align:left;">Forums, appels à projets, changements réglementaires : diffusez vos communications à toutes les associations ou à un domaine ciblé, en quelques clics.</p>
        <ul class="pub-features-checklist">
          <li>✓ Ciblage par domaine ou par statut</li>
          <li>✓ Modèles d'e-mails réutilisables</li>
          <li>✓ Suivi des envois</li>
          <li>✓ Conforme RGPD</li>
        </ul>
      </div>
    </div>
  </div>
</section>

<!-- ===== CTA FINAL ===== -->
<section class="pub-section" style="background:linear-gradient(135deg,#1D4ED8,#1E3A8A);">
  <div class="pub-container" style="text-align:center;">
    <h2 class="pub-h2" style="color:#fff;">Vous représentez une collectivité ou un organisme ?</h2>
    <p class="pub-section-lead" style="color:#DBEAFE;max-width:620px;margin:0 auto 28px;">Découvrez comment Assokit peut simplifier le pilotage de la vie associative de votre territoire. Démonstration personnalisée et sans engagement.</p>
    <a href="/contact" style="display:inline-block;background:#fff;color:#1D4ED8;font-weight:700;font-size:16px;padding:15px 34px;border-radius:10px;text-decoration:none;">Demander une démo</a>
  </div>
</section>

<?php
render_public_footer();
render_public_foot();
