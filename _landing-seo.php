<?php
/**
 * _landing-seo.php — Blocs partagés par les pages « logiciel-… » (pages commerciales).
 * --------------------------------------------------------------
 * - ak_landing_software_jsonld() : une seule entité SoftwareApplication (même @id partout),
 *   sans 'offers' ni note. Le résultat enrichi « application logicielle » de Google exige
 *   'offers' ET une note ('aggregateRating' ou 'review') : sans avis noté vérifiable, la page
 *   n'y est pas éligible, avec ou sans 'offers'. C'est voulu ; une alerte « champ manquant »
 *   dans la Search Console est attendue et sans effet sur le classement. Le prix reste affiché
 *   en clair sur la page (ak_landing_pricing) et sur /tarifs. L'entité est construite par
 *   ak_software_jsonld() (includes-pricing.php), la même sur /tarifs. ak_offer_jsonld() n'est
 *   donc appelée nulle part.
 * - ak_landing_faq_jsonld() : FAQPage construite à partir des questions AFFICHÉES sur la page.
 * - ak_landing_pricing() : la grille réelle (essai gratuit 14 jours sur Pro, puis « dès 19,99 € HT/mois »).
 * - ak_landing_siblings() : liens entre les pages commerciales, une page = un besoin.
 * Requiert includes-public.php et includes-pricing.php (déjà chargés par les pages).
 * --------------------------------------------------------------
 */

if (!function_exists('ak_landing_software_jsonld')) {
    /** L'entité SoftwareApplication commune (ak_software_jsonld, includes-pricing.php) : ni 'offers' ni note. */
    function ak_landing_software_jsonld(string $description, array $features = [], string $category = 'BusinessApplication'): array {
        if (!function_exists('ak_software_jsonld')) require_once __DIR__ . '/includes-pricing.php';
        return ak_software_jsonld($description, $features, $category);
    }
}

if (!function_exists('ak_landing_faq_jsonld')) {
    /** $faqs : [[question, réponse en texte brut], …] — les mêmes que la FAQ affichée. */
    function ak_landing_faq_jsonld(array $faqs): array {
        return ['@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => array_map(fn($qa) => ['@type' => 'Question', 'name' => $qa[0], 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $qa[1]]], $faqs)];
    }
}

if (!function_exists('ak_landing_pricing')) {
    /**
     * Bloc « Tarif » : la grille réelle, lue comme sur /tarifs (ak_pricing_state, dernier relevé en
     * base) : essai gratuit de 14 jours sur Pro, puis « dès 19,99 € HT/mois » (Essentiel, Pro, Équipe),
     * Sur-mesure sur devis. Jamais de prix d'ancienne formule.
     */
    function ak_landing_pricing(string $what = 'toutes les fonctions de la formule Pro'): void {
        $trial = defined('AK_TRIAL_DAYS') ? (int) AK_TRIAL_DAYS : 14;
        $from = ak_offer_from_price();
        $plans = ['ak-essentiel' => 'Essentiel', 'assokit' => 'Pro', 'ak-equipe' => 'Équipe'];
        $list = [];
        foreach ($plans as $slug => $name) $list[] = $name . ' ' . ak_plan_price($slug)['per_month'];
        ?>
<section class="pub-section">
  <div class="pub-container">
    <div class="pub-section-head reveal"><span class="pub-section-eyebrow">Tarif</span><h2 class="pub-h2">Trois formules, <em>sans engagement</em></h2></div>
    <div class="reveal" style="max-width:760px;margin:0 auto;display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:18px;">
      <div class="lp-card">
        <div class="lp-ic">🎁</div>
        <h3>Essai gratuit <?= $trial ?>&nbsp;jours</h3>
        <p>Sans carte bancaire et sans engagement, avec <?= pub_h($what) ?>. À la fin de l'essai, vous choisissez votre formule ou vous vous arrêtez : rien n'est prélevé si vous ne choisissez rien.</p>
      </div>
      <div class="lp-card">
        <div class="lp-ic">💶</div>
        <h3><?= pub_h(ucfirst($from)) ?></h3>
        <p><?= pub_h(implode(' · ', $list)) ?>. Sur-mesure sur devis pour les grandes fédérations, les têtes de réseau et les collectivités territoriales. Résiliable à tout moment. <?= pub_h(ak_vat_note()) ?></p>
      </div>
    </div>
    <div class="pub-text-center reveal" style="margin-top:24px;display:flex;gap:12px;justify-content:center;flex-wrap:wrap;">
      <a href="/signup" class="lp-btn lp-btn-primary">Commencer l'essai gratuit</a>
      <a href="/tarifs" class="lp-btn lp-btn-glass">Comparer les formules</a>
    </div>
  </div>
</section>
        <?php
    }
}

if (!function_exists('ak_landing_siblings')) {
    /** Les autres pages commerciales (hors page courante), chacune sur son besoin propre. */
    function ak_landing_siblings(string $current): void {
        $all = [
            '/logiciel-association'              => ['🤝', 'Logiciel de gestion d\'association', 'Adhérents, cotisations, assemblées générales, projets et communication dans un seul outil.'],
            '/logiciel-adherents'                => ['👥', 'Gestion des adhérents', 'Fichier des membres, rôles, espaces adhérents, import depuis Excel et renouvellements.'],
            '/logiciel-cotisation-association'   => ['💸', 'Gestion des cotisations', 'Campagnes et tarifs, règlements enregistrés, qui est à jour, relances des adhésions.'],
            '/logiciel-comptabilite-association' => ['📊', 'Comptabilité d\'association', 'Recettes, dépenses, justificatifs, bilan par projet et exports pour l\'expert-comptable.'],
            '/logiciel-facturation'              => ['🧾', 'Facturation et devis', 'Devis signés en ligne, factures, avoirs, relances d\'impayés et facture électronique.'],
            '/logiciel-gestion-tpe'              => ['🏗️', 'Gestion de TPE', 'Clients, chantiers, devis, factures, trésorerie et rentabilité par projet.'],
        ];
        unset($all[$current]);
        ?>
<section class="pub-section pub-section-creme">
  <div class="pub-container">
    <div class="pub-section-head reveal"><span class="pub-section-eyebrow">Les autres outils Assokit</span><h2 class="pub-h2">Le même logiciel, <em>pour chaque besoin</em></h2></div>
    <div class="lp-grid">
      <?php foreach ($all as $url => $c): ?>
        <a href="<?= $url ?>" class="lp-card reveal" style="text-decoration:none;color:inherit;"><div class="lp-ic"><?= $c[0] ?></div><h3><?= pub_h($c[1]) ?></h3><p><?= pub_h($c[2]) ?></p></a>
      <?php endforeach; ?>
    </div>
  </div>
</section>
        <?php
    }
}
