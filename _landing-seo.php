<?php
/**
 * _landing-seo.php — Blocs partagés par les pages « logiciel-… » (pages commerciales).
 * --------------------------------------------------------------
 * - ak_landing_software_jsonld() : une seule entité SoftwareApplication (même @id partout),
 *   sans 'offers' ni note. Le résultat enrichi « application logicielle » de Google exige
 *   'offers' ET une note ('aggregateRating' ou 'review') : sans avis noté vérifiable, la page
 *   n'y est pas éligible, avec ou sans 'offers'. C'est voulu ; une alerte « champ manquant »
 *   dans la Search Console est attendue et sans effet sur le classement. Le prix reste affiché
 *   en clair sur la page (ak_landing_pricing) et sur /tarifs.
 * - ak_landing_faq_jsonld() : FAQPage construite à partir des questions AFFICHÉES sur la page.
 * - ak_landing_pricing() : le tarif réel (essai gratuit 14 jours, puis prix de la formule).
 * - ak_landing_siblings() : liens entre les pages commerciales, une page = un besoin.
 * Requiert includes-public.php et includes-pricing.php (déjà chargés par les pages).
 * --------------------------------------------------------------
 */

if (!function_exists('ak_landing_software_jsonld')) {
    function ak_landing_software_jsonld(string $description, array $features = [], string $category = 'BusinessApplication'): array {
        $j = [
            '@context' => 'https://schema.org',
            '@type'    => 'SoftwareApplication',
            '@id'      => AK_PUBLIC_DOMAIN . '/#software',
            'name'     => 'Assokit',
            'url'      => AK_PUBLIC_DOMAIN . '/',
            'applicationCategory' => $category,
            'operatingSystem' => 'Web, iOS, Android',
            'description' => $description,
            'inLanguage' => 'fr-FR',
            'publisher' => ['@id' => AK_PUBLIC_DOMAIN . '/#organization'],
        ];
        if ($features) $j['featureList'] = array_values($features);
        return $j;
    }
}

if (!function_exists('ak_landing_faq_jsonld')) {
    /** $faqs : [[question, réponse en texte brut], …] — les mêmes que la FAQ affichée. */
    function ak_landing_faq_jsonld(array $faqs): array {
        return ['@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => array_map(fn($qa) => ['@type' => 'Question', 'name' => $qa[0], 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $qa[1]]], $faqs)];
    }
}

if (!function_exists('ak_landing_pricing')) {
    /** Bloc « Tarif » : le prix réel de la formule, lu comme sur /tarifs (ak_offer_price). */
    function ak_landing_pricing(string $what = 'toutes les fonctionnalités'): void {
        $o = ak_offer_price();
        $trial = defined('AK_TRIAL_DAYS') ? (int) AK_TRIAL_DAYS : 14;
        $tax = $o['has_vat'] ? ' (soit ' . $o['ttc'] . ' TTC par mois)' : ($o['vat_known'] ? ' (TVA non applicable)' : '');
        ?>
<section class="pub-section">
  <div class="pub-container">
    <div class="pub-section-head reveal"><span class="pub-section-eyebrow">Tarif</span><h2 class="pub-h2">Un seul abonnement, <em>tout compris</em></h2></div>
    <div class="reveal" style="max-width:760px;margin:0 auto;display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:18px;">
      <div class="lp-card">
        <div class="lp-ic">🎁</div>
        <h3>Essai gratuit <?= $trial ?> jours</h3>
        <p>Sans carte bancaire et sans engagement, avec <?= pub_h($what) ?>. À la fin de l'essai, vous décidez de continuer ou non.</p>
      </div>
      <div class="lp-card">
        <div class="lp-ic">💶</div>
        <h3><?= pub_h($o['per_month']) ?></h3>
        <p>Ensuite, une formule unique<?= pub_h($tax) ?> : adhérents, cotisations, facturation, comptabilité analytique, projets, communication et application mobile. Résiliable à tout moment.</p>
      </div>
    </div>
    <div class="pub-text-center reveal" style="margin-top:24px;display:flex;gap:12px;justify-content:center;flex-wrap:wrap;">
      <a href="/signup" class="lp-btn lp-btn-primary">Commencer l'essai gratuit</a>
      <a href="/tarifs" class="lp-btn lp-btn-glass">Détail des tarifs</a>
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
