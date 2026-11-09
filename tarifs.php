<?php
/**
 * tarifs.php — grille publique (novembre 2026)
 * --------------------------------------------------------------
 * Trois cartes et un bandeau, aucune formule gratuite :
 * - Essentiel (ak-essentiel), Pro (slug assokit, au centre, mis en avant), Équipe (ak-equipe) :
 *   prix HT et volumes lus en base par ak_pricing_state (includes-pricing.php : le même calcul
 *   que la page de paiement), valeurs de référence de la grille en repli ;
 * - Sur-mesure (sur-mesure) : bandeau pleine largeur, prix masqué, sur devis.
 * Essai gratuit de 14 jours sur Pro, sans carte bancaire. Abonnement mensuel, sans engagement,
 * pas de formule annuelle. Aucun avis, chiffre ou logo inventé ; aucun aggregateRating/Review.
 * Lignes conditionnelles : une fonction n'est citée que si elle existe (N1 journal-equipe.php,
 * N2 boite-mail-attribuer.php, N3 cron-point-tresorier.php, N4 support prioritaire branché dans
 * support-tickets.php) ; « sans commission Assokit » et le paiement par carte seulement si
 * Stripe est configuré ; badge « Le plus choisi » seulement si ak_plan_badge() le constate.
 * --------------------------------------------------------------
 */
// config.php : prix et TVA lus en base (comme blog.php et contact.php, pages publiques elles aussi)
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes-public.php';
require_once __DIR__ . '/app-context.php';
require_once __DIR__ . '/includes-pricing.php';
// ak_plan_badge (plan-helpers.php) : facultatif, la page fonctionne sans
if (!function_exists('ak_plan_badge') && is_file(__DIR__ . '/plan-helpers.php')) {
    try { require_once __DIR__ . '/plan-helpers.php'; } catch (Throwable $e) { error_log('[tarifs] plan-helpers: ' . get_class($e)); }
}

// Page publique, mais c'est la vitrine tarifaire : elle n'a rien à faire dans
// l'app (Apple 3.1.1). Depuis un navigateur, rien ne change : la session y est
// désormais ouverte (en-tête « Mon tableau de bord », includes-public.php), mais
// un client ou un prospect connecté à la démo doit toujours pouvoir lire les tarifs.
if (ak_is_mobile_app()) ak_block_billing_page();

$db = (isset($pdo) && $pdo instanceof PDO) ? $pdo : null;
$grid = ak_pricing_state($db);
$P = $grid['plans'];
$is_ref = ak_grid_is_reference($db);           // phrases calculées (« environ 1 € HT par jour »…)
$stripe_on = !empty($grid['stripe']);
$trial_days = defined('AK_TRIAL_DAYS') ? (int)AK_TRIAL_DAYS : 14;
$vat_note = ak_vat_note($db);                  // note 293 B seulement si le taux vaut 0
$price = [];
foreach (['ak-essentiel', 'assokit', 'ak-equipe'] as $s) $price[$s] = ak_plan_price($s, $db);

// Fonctions d'Équipe : citées seulement une fois livrées
$live_n1 = is_file(__DIR__ . '/journal-equipe.php');
$live_n2 = is_file(__DIR__ . '/boite-mail-attribuer.php');
// Point du lundi : annoncé seulement s'il part VRAIMENT (tâche cron installée à la main dans le cPanel,
// « 40 7 * * 1 », docs/stripe-mise-en-service.md) : un passage réussi un lundi depuis moins de 8 jours dans
// cron_runs. Le fichier seul ne suffit pas (un envoi --force un autre jour ne compte pas).
$live_n3 = false;
if (is_file(__DIR__ . '/cron-point-tresorier.php') && $db) {
    try {
        $live_n3 = (bool)$db->query("SELECT 1 FROM cron_runs WHERE job_name = 'point-tresorier' AND status = 'success'
                                     AND DAYOFWEEK(started_at) = 2 AND started_at >= DATE_SUB(NOW(), INTERVAL 8 DAY) LIMIT 1")->fetchColumn();
    } catch (Throwable $e) { $live_n3 = false; }
}
$live_n4 = is_file(__DIR__ . '/support-tickets.php') && strpos((string)@file_get_contents(__DIR__ . '/support-tickets.php'), 'priority_support') !== false;
$change_plan_live = $stripe_on && is_file(__DIR__ . '/stripe-change-plan.php');
// Accès limité de Démarrage (consultation et export) : annoncé seulement une fois branché ET le
// préavis de 30 jours envoyé (cgu.php § 12) ; le fondateur le déclare dans config.php.
$readonly_live = defined('AK_DEMARRAGE_READONLY_LIVE') && AK_DEMARRAGE_READONLY_LIVE && function_exists('ak_plan_is_readonly');

// Badge de Pro : allégation factuelle (L121-2), jamais « Le plus choisi » en dur
$badge = null;
try { if ($db && function_exists('ak_plan_badge')) $badge = ak_plan_badge($db, 'assokit'); } catch (Throwable $e) { $badge = null; }
if ($badge !== 'Le plus choisi') $badge = 'Recommandé';

$nb = "\u{00A0}";
$fi = fn($n) => number_format((int)$n, 0, ',', "\u{00A0}");
// « jusqu'à 100 membres et 3 gestionnaires » (une limite vide = sans plafond)
$who = function (string $slug, bool $bureau = false) use ($P, $fi, $nb): string {
    $a = $P[$slug]['adherents']; $m = $P[$slug]['managers'];
    $ta = $a === null ? 'membres sans plafond' : 'jusqu’à ' . $fi($a) . ' membres' . ($bureau ? ' (bureau compris)' : '');
    $tm = $m === null ? 'gestionnaires sans plafond' : $fi($m) . ' gestionnaire' . ($m > 1 ? 's' : '');
    return $ta . ' et ' . $tm;
};
// « jusqu'à 2 000 membres (bureau compris), 25 gestionnaires, 500 crédits IA et 8 000 e-mails par mois »
$volumes = function (string $slug, bool $with_ai) use ($P, $fi): string {
    $a = $P[$slug]['adherents']; $m = $P[$slug]['managers'];
    $parts = [$a === null ? 'membres sans plafond' : 'jusqu’à ' . $fi($a) . ' membres (bureau compris)',
              $m === null ? 'gestionnaires sans plafond' : $fi($m) . ' gestionnaire' . ($m > 1 ? 's' : '')];
    if ($with_ai && $P[$slug]['ai'] !== null) $parts[] = $fi($P[$slug]['ai']) . ' crédits IA';
    $parts[] = $P[$slug]['emails'] === null ? 'des e-mails au devis' : $fi($P[$slug]['emails']) . ' e-mails par mois';
    $last = array_pop($parts);
    return implode(', ', $parts) . ' et ' . $last;
};
$ai = fn(string $slug) => $P[$slug]['ai'] === null ? 'Crédits IA au devis' : $fi($P[$slug]['ai']) . ' crédits IA par mois';
$em = fn(string $slug) => $P[$slug]['emails'] === null ? 'e-mails au devis' : $fi($P[$slug]['emails']) . ' e-mails par mois';
$conv_equipe = $P['ak-equipe']['mail_ai'] === null ? null : (int)$P['ak-equipe']['mail_ai'] * 15;   // lots de 15 conversations
$amt = fn(array $p) => str_replace("{$nb}€", '', $p['ht']);
$per = fn(array $p) => '€' . $nb . 'HT/mois';
$ttc_line = fn(array $p) => $p['has_vat'] ? 'soit ' . $p['ttc'] . ' TTC par mois' : '';

$breadcrumb = build_breadcrumb_jsonld([
    ['name' => 'Accueil', 'url' => '/'],
    ['name' => 'Tarifs',  'url' => '/tarifs'],
]);

// Une seule entité SoftwareApplication (@id …/#software), sans 'offers' ni note : décision
// documentée dans _landing-seo.php (sans avis notés réels, rien à fabriquer).
$product_schema = ak_software_jsonld("Logiciel de gestion pour associations loi 1901 et TPE : adhérents, cotisations, factures et devis, comptabilité, assemblées générales, subventions, communication et IA. Formules Essentiel, Pro, Équipe et Sur-mesure, sans engagement.");

$credit_def = '1 crédit IA = 1 texte rédigé, 1 réponse du copilote ou 1 justificatif lu. Les tâches de fond (tri de la boîte mail, priorités du jour préparées automatiquement, coach hebdomadaire) ne consomment pas de crédit.';
$email_def  = 'Sont comptés dans les e-mails : diffusions, invitations, convocations d’AG et relances. Les factures et devis envoyés un par un ne sont jamais comptés.';

// FAQ : source unique de la section visible ET du schema FAQPage (rich results Google)
$tarifs_faqs = [];
$tarifs_faqs[] = ['L’essai est-il vraiment gratuit ?',
    'Oui. Pendant ' . $trial_days . ' jours, vous utilisez toutes les fonctions de la formule Pro, sans carte bancaire et sans engagement. Pendant l’essai : 100 crédits IA, envois et imports plafonnés. À la fin, vous choisissez votre formule ou vous vous arrêtez : si vous n’avez pas choisi de formule, rien n’est prélevé.'
    . ($stripe_on ? ' Si vous choisissez une formule pendant l’essai, vous gardez tous vos jours d’essai : le premier mois est prélevé le jour de la fin de l’essai, et vous pouvez annuler sans frais jusque-là.' : '')];
$tarifs_faqs[] = ['Quelle formule choisir ?',
    'Essentiel (' . $price['ak-essentiel']['per_month'] . ') : pour gérer à la main une petite structure, ' . $who('ak-essentiel') . '. '
    . 'Pro (' . $price['assokit']['per_month'] . ') : pour que les relances, les factures récurrentes et le copilote IA travaillent à votre place, ' . $who('assokit') . '. '
    . ($badge === 'Le plus choisi' ? 'C’est la formule la plus choisie. ' : 'C’est la formule que nous recommandons aux associations actives et aux TPE. ')
    . 'Équipe (' . $price['ak-equipe']['per_month'] . ') : quand plusieurs personnes gèrent chaque jour, ' . $who('ak-equipe') . '. '
    . 'Au-delà, ou pour un réseau : Sur-mesure, sur devis.'];
$tarifs_faqs[] = ['Puis-je changer de formule ?', $change_plan_live
    ? 'Oui, depuis Mon abonnement. Une montée prend effet tout de suite, au prorata ; une baisse prend effet à la fin du mois payé.'
    : 'Oui, sur simple demande au support : nous faisons le changement pour vous.'];
if ($stripe_on) {
    $tarifs_faqs[] = ['Puis-je m’abonner pendant l’essai ?', 'Oui, et vous gardez tous vos jours d’essai : votre carte (ou votre mandat de prélèvement SEPA) est enregistrée, mais rien n’est prélevé avant la fin de l’essai. Le premier mois est prélevé automatiquement ce jour-là, et vous pouvez annuler sans frais d’ici là.'];
}
$tarifs_faqs[] = ['Y a-t-il un engagement ?', 'Non. L’abonnement est mensuel, se renouvelle chaque mois et reste résiliable à tout moment depuis votre espace, en quelques clics. La résiliation prend effet à la fin de la période en cours, déjà payée : pas de frais de résiliation, et pas de remboursement au prorata du mois entamé.'];
$tarifs_faqs[] = ['Existe-t-il une formule gratuite ? Que se passe-t-il si je ne choisis rien ?', $readonly_live
    ? 'Non. Après l’essai, si vous ne choisissez pas de formule, vos données sont conservées et votre espace reste consultable et exportable, mais vous ne pouvez plus rien créer ni envoyer. Vous reprenez exactement où vous en étiez dès que vous choisissez une formule.'
    : 'Non. Après l’essai, votre espace passe en accès limité, vos données sont conservées et vous choisissez votre formule quand vous le souhaitez.'];
$tarifs_faqs[] = ['Que compte un crédit IA, et quels e-mails sont comptés ?', $credit_def . ' ' . $email_def . ' Les compteurs repartent à zéro le 1er de chaque mois.'];
$tarifs_faqs[] = ['Comment se passe le paiement ?',
    ($stripe_on ? 'Par carte ou prélèvement SEPA, en paiement sécurisé par Stripe, chaque mois.' : 'Sur facture mensuelle, par virement.')
    . ' Vos factures sont dans votre espace, rubrique « Mon abonnement › Mes factures ». ' . $vat_note];
$tarifs_faqs[] = ['Le support est-il humain ? Et la facture électronique ?',
    'Oui. Une équipe répond en français, par e-mail, en moins de 24 h ouvrées, dans toutes les formules. Avec Équipe, une séance de prise en main d’une heure en visio est incluse (à demander dans les 3 mois suivant la souscription)' . ($live_n4 ? ', et vos demandes passent en tête de file' : '') . '. '
    . 'Facture électronique : vos factures sont au format Factur-X ; le raccordement à une plateforme agréée est en préparation.'];
$tarifs_faqs[] = ['Mes données sont-elles hébergées en France ?', 'Oui. Assokit est hébergé en France chez O2Switch, à Clermont-Ferrand, avec des sauvegardes quotidiennes, dans le respect du RGPD. Vos données vous appartiennent : vous pouvez exporter vos adhérents, vos factures et votre fichier des écritures comptables (FEC) à tout moment.'];
$tarifs_faqs[] = ['Pour qui est l’offre Sur-mesure ?', 'Au-delà de ' . $fi($P['ak-equipe']['adherents'] ?? 2000) . ' membres ou de ' . $fi($P['ak-equipe']['managers'] ?? 25) . ' gestionnaires, ou pour plusieurs structures (réseau, fédération, collectivité) : volumes fixés au devis, reprise de vos données, formation de vos équipes, paiement sur facture.'];
$tarifs_faqs[] = ['Peut-on voir Assokit avant de s’inscrire ?', 'Oui. Demandez une démonstration : nous vous présentons Assokit en visio, en partant de vos besoins concrets. Sans engagement.'];
// Typographie : espace insécable avant « : ; ? ! » et dans « 24 h »
$tarifs_faqs = array_map(fn($qa) => array_map(fn($t) => preg_replace(['/ ([:;?!»])/u', '/« /u', '/(\d) h /u'], ["{$nb}$1", "«{$nb}", "$1{$nb}h "], $t), $qa), $tarifs_faqs);

$faq_schema = [
    '@context'   => 'https://schema.org',
    '@type'      => 'FAQPage',
    'mainEntity' => array_map(fn($qa) => [
        '@type'          => 'Question',
        'name'           => $qa[0],
        'acceptedAnswer' => ['@type' => 'Answer', 'text' => $qa[1]],
    ], $tarifs_faqs),
];

render_public_head([
    'title'       => 'Tarifs Assokit : essai gratuit 14 jours, puis ' . ak_offer_from_price($db),
    'description' => 'Essentiel ' . $price['ak-essentiel']['per_month'] . ', Pro ' . $price['assokit']['per_month'] . ', Équipe ' . $price['ak-equipe']['per_month'] . ', sans engagement. Essai Pro gratuit ' . $trial_days . ' jours, sans carte bancaire. Sur-mesure sur devis.',
    'path'        => '/tarifs',
    'schema_jsonld' => [$breadcrumb, $product_schema, $faq_schema],
]);

render_public_nav('tarifs');

// Puces d'une carte : [texte HTML, variante] — variante 'a' (association), 'e' (entreprise) ou '' (les deux)
$li = function (array $items): void {
    foreach ($items as $it) {
        [$html, $v] = is_array($it) ? $it : [$it, ''];
        echo '<li' . ($v === 'a' ? ' data-v="a"' : ($v === 'e' ? ' data-v="e" hidden' : '')) . '>' . $html . "</li>\n";
    }
};
// Texte qui change avec le sélecteur Association / Entreprise
$ae = fn(string $a, string $e) => '<span data-v="a">' . $a . '</span><span data-v="e" hidden>' . $e . '</span>';
?>

<section class="pub-hero" style="padding: 60px 0 24px;">
  <div class="pub-container pub-text-center">
    <div class="pub-breadcrumb" style="margin-bottom:30px;justify-content:center;display:flex;">
      <a href="/">Accueil</a><span class="pub-breadcrumb-sep">›</span>
      <strong style="color:var(--c-encre);">Tarifs</strong>
    </div>
    <span class="pub-eyebrow">Sans engagement · Essai de <?= $trial_days ?>&nbsp;jours sans carte bancaire</span>

    <div class="ak-seg" role="group" aria-label="Exemples adaptés à votre structure">
      <button type="button" class="ak-seg-btn" data-seg="a" aria-pressed="true">Pour une association</button>
      <button type="button" class="ak-seg-btn" data-seg="e" aria-pressed="false">Pour une entreprise</button>
    </div>
    <p class="ak-seg-help">Les prix sont les mêmes : seuls les exemples changent.</p>

    <h1 class="pub-h1" style="max-width:860px;margin:0 auto 18px;">
      <?= $ae('Rendez leurs soirées à ceux qui font vivre <em>votre association</em>.', 'Vos devis signés en ligne, vos factures relancées, <em>vos soirées libres</em>.') ?>
    </h1>
    <p class="pub-tagline" style="max-width:700px;margin:0 auto;">
      <?= $ae('Adhérents, cotisations, factures, comptabilité, assemblées générales : tout au même endroit, bien rangé. Vous choisissez simplement jusqu’où Assokit travaille à votre place.',
              'Devis, factures au format Factur-X (raccordement à une plateforme agréée en préparation), comptabilité et export FEC pour votre expert-comptable, relances d’impayés : l’administratif d’une TPE ou d’un indépendant, enfin rangé.') ?>
    </p>
    <ul class="ak-trust-row">
      <li>✓ Sans carte bancaire</li>
      <li>✓ Sans engagement</li>
      <li>✓ Données exportables</li>
      <li>🇫🇷 Hébergé en France</li>
      <li>💬 Support en français</li>
    </ul>
  </div>
</section>

<!-- PRICING -->
<section class="pub-section ak-pricing-sec">
  <div class="pub-container">

    <p class="ak-anchor-line">
      <?= $ae('Avec Pro, les cotisations en retard sont relancées sans vous, les factures récurrentes se préparent toutes seules et le copilote vous propose chaque jour par où commencer' . ($is_ref ? ', pour environ 1&nbsp;€&nbsp;HT par jour' : '') . '.',
              'Avec Pro, vos devis se signent en ligne, vos impayés sont relancés dès le lendemain de l’échéance et vos factures d’abonnement se créent chaque mois toutes seules.') ?>
    </p>
    <?php if ($is_ref): ?>
    <p class="ak-example">
      <?= $ae('Exemple : 150 adhérents, 1 sur 3 en retard, 3 minutes par relance faite à la main. Cela fait environ 2&nbsp;h&nbsp;30 par vague de relances (estimation, à refaire avec vos chiffres). Avec Pro, une fois les relances automatiques activées, ces relances partent sans vous.',
              'Exemple : 8 factures en retard et 15 factures récurrentes à refaire chaque mois, à 4 ou 5 minutes chacune : environ 1&nbsp;h&nbsp;45 de travail par mois (estimation). Si votre heure vaut 35&nbsp;€ (mettez votre propre chiffre), c’est environ 60&nbsp;€ de temps, contre ' . pub_h($price['assokit']['per_month']) . ' pour Pro.') ?>
    </p>
    <?php endif; ?>

    <div class="ak-pricing-grid">

      <!-- ESSENTIEL -->
      <article class="ak-pcard ak-pcard-ess" id="offre-essentiel">
        <header class="ak-pcard-head">
          <div class="ak-pcard-nick">Le carnet de bord</div>
          <h2 class="ak-pcard-name">Essentiel</h2>
          <div class="ak-pcard-tag">Petites associations et indépendants</div>
        </header>
        <p class="ak-pcard-sign">Tout est rangé, enfin.</p>
        <div class="ak-pcard-price">
          <span class="ak-pcard-amt"><?= pub_h($amt($price['ak-essentiel'])) ?></span><span class="ak-pcard-per"><?= pub_h($per($price['ak-essentiel'])) ?></span>
        </div>
        <?php if ($price['ak-essentiel']['has_vat']): ?><p class="ak-pcard-ttc"><?= pub_h($ttc_line($price['ak-essentiel'])) ?></p><?php endif; ?>
        <p class="ak-pcard-sub">Sans engagement · <?= pub_h($who('ak-essentiel')) ?><?= $is_ref ? ' · environ 0,67&nbsp;€&nbsp;HT par jour' : '' ?></p>
        <p class="ak-pcard-desc">Fini les classeurs, les tableurs éparpillés et les reçus dans une boîte à chaussures : toute votre gestion tient au même endroit, prête le jour où le comptable ou le bureau vous la demande.</p>
        <a href="/signup" class="ak-pcard-cta ak-pcard-cta-ghost">Commencer gratuitement</a>
        <p class="ak-pcard-reassure">L’essai gratuit de <?= $trial_days ?>&nbsp;jours se fait sur Pro, sans carte bancaire. À la fin, choisissez Essentiel si cela vous suffit.</p>
        <div class="ak-pcard-divider"></div>
        <ul class="ak-pcard-features">
          <?php $li([
              ['<strong>Un seul endroit pour tout.</strong> Adhérents, cotisations et dons : vous voyez d’un coup d’œil qui est à jour' . ($stripe_on ? ', avec paiement en ligne sans commission Assokit (frais bancaires Stripe en sus).' : ', avec campagnes et suivi des règlements.'), 'a'],
              ['<strong>Un seul endroit pour tout.</strong> Vos clients, vos devis et vos encaissements, suivis au même endroit.', 'e'],
              '<strong>Factures et devis illimités</strong>, au format Factur-X (raccordement à une plateforme agréée en préparation).',
              '<strong>Un bilan prêt sans nuit blanche</strong> : comptabilité, bilans et export FEC pour votre expert-comptable ou votre AG.',
              ['<strong>Une AG plus sereine</strong> : convocation, vote, procès-verbal et émargement par QR code. Projets, agenda et subventions : vos échéances de dossier sous les yeux.', 'a'],
              ['<strong>L’organisation au même endroit</strong> : projets, agenda et suivi de vos devis, chaque échéance sous les yeux.', 'e'],
              '<strong>Une relance en un clic</strong> quand une cotisation ou une facture tarde : la liste des retards est prête, vous envoyez.',
              '<strong>Des courriers rédigés en quelques secondes</strong> : ' . pub_h($ai('ak-essentiel')) . ', et ' . pub_h($em('ak-essentiel')) . ' pour vos diffusions. Relisez toujours ce que l’IA propose.',
              '<strong>Tout dans la poche</strong> : ' . pub_h($who('ak-essentiel', true)) . ', l’appli mobile, la double authentification et un support humain par e-mail, avec une réponse sous 24&nbsp;h ouvrées.',
          ]); ?>
        </ul>
        <p class="ak-pcard-fine"><strong>Idéal pour</strong> <?= $ae('le club, l’amicale ou la petite association dont le bureau gère tout à la main et veut enfin y voir clair.', 'l’indépendant ou la micro-entreprise qui veut des factures propres et une comptabilité à jour.') ?></p>
      </article>

      <!-- PRO (mis en avant) -->
      <article class="ak-pcard ak-pcard-featured ak-pcard-pro" id="offre-pro">
        <span id="offre-assokit" class="ak-anchor"></span><span id="offre-essai" class="ak-anchor"></span>
        <div class="ak-pcard-badge"><?= pub_h($badge) ?></div>
        <header class="ak-pcard-head">
          <div class="ak-pcard-nick">Le copilote</div>
          <h2 class="ak-pcard-name">Pro</h2>
          <div class="ak-pcard-tag">Associations actives et TPE</div>
        </header>
        <p class="ak-pcard-sign">Assokit relance à votre place.</p>
        <div class="ak-pcard-price">
          <span class="ak-pcard-amt"><?= pub_h($amt($price['assokit'])) ?></span><span class="ak-pcard-per"><?= pub_h($per($price['assokit'])) ?></span>
        </div>
        <?php if ($price['assokit']['has_vat']): ?><p class="ak-pcard-ttc"><?= pub_h($ttc_line($price['assokit'])) ?></p><?php endif; ?>
        <p class="ak-pcard-sub"><?= $is_ref ? 'Environ 1&nbsp;€&nbsp;HT par jour · 10&nbsp;€&nbsp;HT de plus qu’Essentiel' : 'Sans engagement · ' . pub_h($who('assokit')) ?></p>
        <p class="ak-pcard-desc">Les tâches qui vous suivaient le soir (relancer, refacturer, faire signer, chercher par où commencer) se font sans vous, ou en deux clics.</p>
        <a href="/signup" class="ak-pcard-cta ak-pcard-cta-primary">Essayer gratuitement <?= $trial_days ?>&nbsp;jours</a>
        <p class="ak-pcard-reassure">Sans carte bancaire · si vous ne choisissez pas de formule, rien n’est prélevé · vos données restent à vous</p>
        <div class="ak-pcard-divider"></div>
        <div class="ak-pcard-feat-title">Tout Essentiel, plus&nbsp;:</div>
        <ul class="ak-pcard-features">
          <?php $li([
              '<strong>Les retardataires relancés sans vous.</strong> ' . $ae('Les cotisations en retard sont relancées automatiquement', 'Les impayés sont relancés automatiquement') . ', dès que vous activez l’option. Assokit envoie jusqu’à trois relances graduées, du rappel courtois (dès le lendemain du retard) à la mise en demeure, jamais deux à moins de 7&nbsp;jours d’écart. Vous choisissez jusqu’où elles vont. Elles partent au nom de votre structure, avec des modèles de relance prêts à l’emploi (personnalisables pour les factures), sans IA.',
              '<strong>Les factures récurrentes se font toutes seules</strong> : abonnements, loyers, adhésions mensuelles, créées à date et, si vous le choisissez, envoyées automatiquement.',
              '<strong>Vos devis signés en ligne, même le soir</strong> : acceptation et signature en ligne (signature simple, horodatée) depuis le téléphone du client, puis transformation en facture en un clic.',
              '<strong>Vous savez par où commencer</strong> : chaque jour, à l’ouverture du tableau de bord, le copilote IA propose les priorités du jour des gestionnaires, répond à vos questions sur votre structure, aide à monter vos projets et pré-remplit vos justificatifs à partir d’une photo, que vous vérifiez. ' . pub_h($ai('assokit')) . '.',
              '<strong>Une boîte mail mieux rangée</strong> : boîte Gmail intégrée, triée par règles, avec des brouillons de réponse rédigés par l’IA, que vous relisez avant l’envoi.',
              ['<strong>Le trésorier voit venir</strong> : prévisions de trésorerie et comptabilité analytique sans limite de bilans, par projet ou par subvention, avec export Excel.', 'a'],
              ['<strong>Vous voyez venir les mois creux</strong> : prévisions de trésorerie et comptabilité analytique sans limite de bilans, par projet ou par client, avec export Excel.', 'e'],
              '<strong>De la place pour grandir</strong> : ' . pub_h($volumes('assokit', false)) . '.',
          ]); ?>
        </ul>
        <?php if ($is_ref): ?>
        <div class="ak-pcard-calc"><strong>Le calcul est vite fait (estimation).</strong> Si les relances automatiques et les factures récurrentes vous évitent une heure de travail par mois, Pro vous coûte <?= pub_h($price['assokit']['per_month']) ?> pour cette heure. Et l’argent peut rentrer plus tôt : un retard est relancé dès le lendemain, pas le jour où quelqu’un y pense.</div>
        <?php endif; ?>
        <p class="ak-pcard-fine"><strong>Idéal pour</strong> <?= $ae('l’association qui vit toute l’année : cotisations chaque saison, événements, subventions.', 'la TPE qui envoie des devis chaque semaine et suit des clients réguliers.') ?></p>
      </article>

      <!-- ÉQUIPE -->
      <article class="ak-pcard ak-pcard-equipe" id="offre-equipe">
        <header class="ak-pcard-head">
          <div class="ak-pcard-nick">L’équipage · <span class="ak-pcard-label">Le plus complet à prix affiché</span></div>
          <h2 class="ak-pcard-name">Équipe</h2>
          <div class="ak-pcard-tag">Structures avec une équipe</div>
        </header>
        <p class="ak-pcard-sign">Chacun sait quoi faire.</p>
        <div class="ak-pcard-price">
          <span class="ak-pcard-amt"><?= pub_h($amt($price['ak-equipe'])) ?></span><span class="ak-pcard-per"><?= pub_h($per($price['ak-equipe'])) ?></span>
        </div>
        <?php if ($price['ak-equipe']['has_vat']): ?><p class="ak-pcard-ttc"><?= pub_h($ttc_line($price['ak-equipe'])) ?></p><?php endif; ?>
        <p class="ak-pcard-sub"><?= $is_ref
            ? 'Pour 2 fois le prix de Pro : 4 fois plus de membres, 2,5 fois plus de gestionnaires, 2,7 fois plus d’e-mails. Soit 2,40&nbsp;€&nbsp;HT par gestionnaire avec une équipe de 25, et 0,03&nbsp;€&nbsp;HT par membre avec 2&nbsp;000 membres.'
            : 'Sans engagement · ' . pub_h($who('ak-equipe')) ?></p>
        <p class="ak-pcard-desc">Quand plusieurs personnes gèrent chaque jour, moins de choses tombent entre deux chaises : chacun reçoit ses priorités, chacun voit qui s’occupe de quoi, et le trésorier a moins de mauvaises surprises.</p>
        <a href="/signup" class="ak-pcard-cta ak-pcard-cta-dark">Essayer gratuitement <?= $trial_days ?>&nbsp;jours</a>
        <p class="ak-pcard-reassure">L’essai porte sur les fonctions Pro ; les fonctions et les volumes Équipe s’activent à l’abonnement. Sans carte bancaire.</p>
        <a href="/contact?plan=ak-equipe" class="ak-pcard-link">Parler à un conseiller</a>
        <div class="ak-pcard-divider"></div>
        <div class="ak-pcard-feat-title">Tout Pro, plus&nbsp;:</div>
        <ul class="ak-pcard-features">
          <?php $li(array_values(array_filter([
              '<strong>Chacun commence sa journée avec ses priorités</strong> : l’IA propose les priorités du jour à chaque personne de l’équipe qui se connecte (hors simples suiveurs), et la boîte mail est triée par l’IA, par priorité et par catégorie'
                  . ($conv_equipe ? ', jusqu’à ' . $fi($conv_equipe) . ' conversations par mois (au-delà, le tri par règles prend le relais)' : '') . '.',
              $live_n2 ? '<strong>Chaque conversation a son responsable</strong> : chaque conversation de la boîte partagée peut être attribuée à un membre de l’équipe.' : null,
              ['<strong>Le planning ne se gère plus par SMS</strong> : planning d’équipe, absences et congés au même endroit, et une prospection partagée (mécènes, partenaires), avec l’historique des appels et des e-mails.', 'a'],
              ['<strong>Le planning ne se gère plus par SMS</strong> : planning d’équipe, absences et congés au même endroit, et une prospection téléphonique partagée, avec l’historique des appels et des e-mails.', 'e'],
              '<strong>Des erreurs courantes repérées avant le comptable</strong> : détection automatique d’anomalies dans vos comptes (numérotation des factures, doublons, TVA incohérente, montants inhabituels, cotisations en double), réunies sur une page dédiée'
                  . ($live_n3 ? ', et un point envoyé au trésorier chaque lundi matin' : '') . '.',
              $live_n1 ? '<strong>Qui a fait quoi, et quand</strong> : le journal d’activité de l’équipe.' : null,
              '<strong>Taillé pour les grandes structures</strong> : ' . pub_h($volumes('ak-equipe', true)) . '.',
              '<strong>Bien accompagné dès le départ</strong> : ' . ($live_n4 ? 'support prioritaire, ' : '') . 'une séance de prise en main d’une heure en visio, et la reprise de votre ' . $ae('fichier d’adhérents', 'fichier clients') . ' par nos soins (tableur CSV ou Excel, une fois, à demander dans les 3 mois suivant la souscription).',
          ]))); ?>
        </ul>
        <p class="ak-pcard-fine"><strong>Idéal pour</strong> <?= $ae('l’association employeuse, le club à plusieurs sections, la structure où salariés et bénévoles gèrent ensemble.', 'la TPE avec une équipe (accueil, commercial, comptabilité) qui partage les mêmes clients et la même boîte mail.') ?></p>
      </article>
    </div>

    <!-- SUR-MESURE : bandeau pleine largeur, prix masqué -->
    <aside class="ak-sm" id="offre-sur-mesure" aria-labelledby="ak-sm-title">
      <div class="ak-sm-main">
        <div class="ak-sm-eyebrow">La flotte · Réseaux, fédérations, collectivités</div>
        <h2 class="ak-sm-title" id="ak-sm-title">Sur-mesure</h2>
        <p class="ak-sm-sign">À l’échelle de votre réseau.</p>
        <div class="ak-sm-price"><span aria-hidden="true"><?= pub_h(AK_PRICE_MASK) ?></span><span class="sr-only">Prix sur devis</span></div>
        <p class="ak-sm-quote">Sur devis — nous contacter</p>
        <p class="ak-sm-text">Plus de <?= $fi($P['ak-equipe']['adherents'] ?? 2000) ?> membres, plus de <?= $fi($P['ak-equipe']['managers'] ?? 25) ?> gestionnaires, ou plusieurs structures (réseau, fédération, collectivité)&nbsp;? Parlons de votre projet.</p>
      </div>
      <div class="ak-sm-side">
        <ul class="ak-sm-list">
          <li>Tout Équipe, sans plafond de membres ni de gestionnaires.</li>
          <li>Portail collectivité : la mairie voit les associations de son territoire et leur écrit.</li>
          <li>Reprise de vos données et formation de vos équipes.</li>
          <li>Volumes IA et e-mails fixés au devis.</li>
          <li>Paiement par virement ou sur facture, et un interlocuteur unique.</li>
        </ul>
        <p class="ak-sm-fine">Besoin particulier&nbsp;? Nous l’étudions ensemble. Réponse sous 24&nbsp;h ouvrées · devis gratuit et sans engagement.</p>
        <div class="ak-sm-ctas">
          <a href="/contact?plan=sur-mesure" class="ak-sm-btn ak-sm-btn-light">Nous contacter</a>
          <a href="/contact?plan=collectivite" class="ak-sm-btn ak-sm-btn-line">Collectivités : nous contacter</a>
        </div>
      </div>
    </aside>

    <div class="ak-pricing-foot">
      <p><strong>Sans engagement.</strong> Essai gratuit de <?= $trial_days ?>&nbsp;jours sur Pro, sans carte bancaire.</p>
      <p><?= pub_h($vat_note) ?></p>
      <p><?= pub_h($credit_def) ?></p>
      <p><?= pub_h($email_def) ?></p>
      <p><a href="#comparer" class="ak-compare-link">Comparer les formules ↓</a></p>
    </div>
  </div>
</section>

<?php if ($is_ref): ?>
<!-- ÉQUIPE : CE QUI CHANGE VRAIMENT -->
<section class="pub-section pub-section-creme">
  <div class="pub-container">
    <div class="pub-section-head">
      <span class="pub-section-eyebrow">Pro ou Équipe&nbsp;?</span>
      <h2 class="pub-h2">Pour 30&nbsp;€&nbsp;HT de plus que Pro, <em>ce qui change vraiment</em></h2>
    </div>
    <div class="ak-table-wrap">
      <table class="ak-cmp ak-cmp-2">
        <thead><tr><th scope="col"></th><th scope="col">Pro (<?= pub_h($price['assokit']['per_month']) ?>)</th><th scope="col">Équipe (<?= pub_h($price['ak-equipe']['per_month']) ?>)</th><th scope="col">Ce que cela change pour vous</th></tr></thead>
        <tbody>
          <tr><th scope="row">Membres (bureau compris)</th><td>500</td><td><strong>2&nbsp;000</strong> (4 fois plus)</td><td>Jusqu’à 2&nbsp;000 adhérents, toutes sections comprises, dans un seul espace</td></tr>
          <tr><th scope="row">Gestionnaires</th><td>10</td><td><strong>25</strong> (2,5 fois plus)</td><td>Chaque responsable a son propre accès</td></tr>
          <tr><th scope="row">E-mails par mois</th><td>3&nbsp;000</td><td><strong>8&nbsp;000</strong> (2,7 fois plus)</td><td>Vos diffusions de saison avec plus de marge</td></tr>
          <tr><th scope="row">Crédits IA par mois</th><td>300</td><td><strong>500</strong></td><td>Plus de courriers rédigés et plus de questions au copilote</td></tr>
          <tr><th scope="row">Priorités IA du jour</th><td>Gestionnaires</td><td><strong>Toute l’équipe</strong></td><td>Chacun sait par où commencer le matin</td></tr>
          <tr><th scope="row">Tri de la boîte mail</th><td>Par règles</td><td><strong>Par l’IA (jusqu’à 7&nbsp;500 conversations par mois), puis par règles</strong></td><td>Les messages importants remontent en tête</td></tr>
          <tr><th scope="row">Planning, absences, prospection partagée</th><td>—</td><td><strong>Inclus</strong></td><td>Moins de «&nbsp;qui s’en occupe&nbsp;?&nbsp;»</td></tr>
          <tr><th scope="row">Détection d’anomalies dans les comptes</th><td>—</td><td><strong>Incluse</strong></td><td>Des erreurs courantes repérées avant le bilan</td></tr>
          <?php
          $extra = array_filter([$live_n2 ? 'Boîte partagée' : null, $live_n1 ? 'journal d’activité' : null, $live_n3 ? 'point du lundi' : null]);
          if ($extra): ?>
          <tr><th scope="row"><?= pub_h(ucfirst(implode(', ', $extra))) ?></th><td>—</td><td><strong>Inclus</strong></td><td><?= pub_h(implode(', ', array_filter([$live_n2 ? 'Chaque conversation a un responsable' : null, $live_n1 ? 'chaque action a un auteur' : null, $live_n3 ? 'le trésorier reçoit un point chaque lundi' : null]))) ?></td></tr>
          <?php endif; ?>
          <tr><th scope="row">Prise en main</th><td>Support par e-mail</td><td><strong>1&nbsp;h en visio et reprise de votre fichier</strong> (une fois, dans les 3&nbsp;mois)</td><td>Vous démarrez avec vos vraies données</td></tr>
        </tbody>
      </table>
    </div>
    <div class="ak-table-wrap" style="margin-top:22px;">
      <table class="ak-cmp ak-cmp-unit">
        <caption>Ce que coûte chaque unité, à pleine utilisation (prix ÷ volume maximal de la formule)</caption>
        <thead><tr><th scope="col"></th><th scope="col">Pro</th><th scope="col">Équipe</th></tr></thead>
        <tbody>
          <tr><th scope="row">Par gestionnaire et par mois</th><td>3,00&nbsp;€&nbsp;HT</td><td><strong>2,40&nbsp;€&nbsp;HT</strong></td></tr>
          <tr><th scope="row">Par membre et par mois</th><td>0,06&nbsp;€&nbsp;HT</td><td><strong>0,03&nbsp;€&nbsp;HT</strong></td></tr>
          <tr><th scope="row">Pour 100 e-mails</th><td>1,00&nbsp;€&nbsp;HT</td><td><strong>0,75&nbsp;€&nbsp;HT</strong></td></tr>
        </tbody>
      </table>
    </div>
    <p class="ak-note-center">Ces fonctions ne se vendent pas à l’unité : elles sont réservées à Équipe, et nous ne leur inventons pas de prix. Le prix est fixe, donc plus vous utilisez la formule, moins chaque gestionnaire, chaque membre et chaque e-mail vous coûte. Avec Équipe, nous reprenons votre fichier d’adhérents : utile, car l’essai plafonne les imports à 200&nbsp;lignes.</p>
  </div>
</section>
<?php endif; ?>

<!-- TABLEAU COMPARATIF -->
<section class="pub-section" id="comparer">
  <div class="pub-container">
    <div class="pub-section-head">
      <span class="pub-section-eyebrow">Comparer les formules</span>
      <h2 class="pub-h2">Tout voir <em>d’un coup d’œil</em>.</h2>
    </div>
    <div class="ak-tabs" role="group" aria-label="Formule affichée">
      <button type="button" class="ak-tab" data-col="1" aria-pressed="false">Essentiel</button>
      <button type="button" class="ak-tab" data-col="2" aria-pressed="true">Pro</button>
      <button type="button" class="ak-tab" data-col="3" aria-pressed="false">Équipe</button>
    </div>
    <div class="ak-table-wrap">
      <table class="ak-cmp ak-cmp-main" data-show="2">
        <thead>
          <tr>
            <th scope="col"><span class="sr-only">Ce que vous obtenez</span></th>
            <th scope="col" data-c="1">Essentiel<span>Le carnet de bord</span></th>
            <th scope="col" data-c="2" class="ak-col-pro">Pro <em class="ak-cmp-badge"><?= pub_h($badge) ?></em><span>Le copilote</span></th>
            <th scope="col" data-c="3">Équipe<span>L’équipage</span></th>
            <th scope="col" data-c="4">Sur-mesure<span>La flotte</span></th>
          </tr>
        </thead>
        <tbody>
          <?php
          $yes = '<span aria-label="Inclus">✓</span>';
          $no  = '<span aria-label="Non inclus">—</span>';
          $n = fn(string $slug, string $k) => $P[$slug][$k] === null ? 'Sans plafond' : $fi($P[$slug][$k]);
          $rows = [
              ['<em>Prix</em>', pub_h($price['ak-essentiel']['per_month']), '<strong>' . pub_h($price['assokit']['per_month']) . '</strong>', pub_h($price['ak-equipe']['per_month']),
                  '<span aria-hidden="true">' . pub_h(AK_PRICE_MASK) . '</span><span class="sr-only">Prix sur devis</span> — Sur devis — nous contacter'],
              ['<strong>Toute votre gestion au même endroit</strong> (adhérents, cotisations, factures et devis illimités au format Factur-X, compta, FEC, ' . $ae('AG, projets, subventions', 'projets, agenda, suivi des devis') . ', appli mobile)', $yes, $yes, $yes, $yes],
              ['<strong>De la place pour votre structure</strong> : membres (bureau compris) / gestionnaires',
                  $n('ak-essentiel', 'adherents') . ' / ' . $n('ak-essentiel', 'managers'), '<strong>' . $n('assokit', 'adherents') . ' / ' . $n('assokit', 'managers') . '</strong>',
                  $n('ak-equipe', 'adherents') . ' / ' . $n('ak-equipe', 'managers'), 'Sans plafond'],
              ['<strong>Les ' . $ae('retardataires', 'impayés') . ' relancés sans vous</strong>', 'Relance manuelle en un clic', '<strong>Relances automatiques + factures récurrentes</strong>', $yes, $yes],
              ['<strong>Des devis signés sans imprimante</strong> (signature simple, horodatée)', $no, $yes, $yes, $yes],
              ['<strong>Des courriers rédigés pour vous</strong> : crédits IA par mois', $n('ak-essentiel', 'ai'), '<strong>' . $n('assokit', 'ai') . '</strong>', $n('ak-equipe', 'ai'), 'Au devis'],
              ['<strong>Savoir chaque jour par où commencer</strong> (priorités du jour, copilote)', $no, '<strong>Pour les gestionnaires</strong>', 'Pour toute l’équipe', 'Pour toute l’équipe'],
              ['<strong>Une boîte mail mieux rangée</strong>', $no, '<strong>Gmail triée par règles, brouillons IA</strong>',
                  'Tri IA' . ($conv_equipe ? ' jusqu’à ' . $fi($conv_equipe) . ' conversations par mois' : '') . ($live_n2 ? ', boîte partagée' : ''), 'Tri IA, volume au devis'],
              ['<strong>Un trésorier qui voit venir</strong> (prévisions, analytique)', '1 export de bilan analytique au total', '<strong>Prévisions + bilans analytiques sans limite</strong>', $yes, $yes],
              ['<strong>Des erreurs courantes repérées avant le comptable</strong> (anomalies' . ($live_n3 ? ', point du lundi' : '') . ')', $no, $no, $yes, $yes],
              ['<strong>Une équipe coordonnée</strong> (planning, absences, prospection partagée' . ($live_n1 ? ', journal d’activité' : '') . ')', $no, $no, $yes, $yes],
              ['<strong>Toucher tout le monde</strong> : e-mails par mois (diffusions, invitations, convocations, relances)', $n('ak-essentiel', 'emails'), '<strong>' . $n('assokit', 'emails') . '</strong>', $n('ak-equipe', 'emails'), 'Au devis'],
              ['<strong>Quelqu’un à vos côtés</strong>', 'E-mail, réponse sous 24&nbsp;h ouvrées', '<strong>E-mail, réponse sous 24&nbsp;h ouvrées</strong>',
                  ($live_n4 ? 'Support prioritaire, ' : '') . '1&nbsp;h de visio + reprise du fichier', 'Interlocuteur unique, formation, portail collectivité'],
          ];
          foreach ($rows as $r): ?>
          <tr>
            <th scope="row"><?= $r[0] ?></th>
            <td data-c="1"><?= $r[1] ?></td>
            <td data-c="2" class="ak-col-pro"><?= $r[2] ?></td>
            <td data-c="3"><?= $r[3] ?></td>
            <td data-c="4"><?= $r[4] ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div class="ak-cmp-foot">
      <p><?= pub_h($vat_note === AK_VAT_293B_NOTE ? 'Prix HT. ' . $vat_note : $vat_note) ?></p>
      <p>Sans engagement. Essai gratuit de <?= $trial_days ?>&nbsp;jours sur Pro, sans carte bancaire.</p>
      <p>Les factures et devis envoyés un par un ne sont pas comptés dans les e-mails.</p>
    </div>
  </div>
</section>

<!-- GARANTIES -->
<section class="pub-section pub-section-creme">
  <div class="pub-container">
    <div class="pub-section-head">
      <span class="pub-section-eyebrow">Sans engagement</span>
      <h2 class="pub-h2">Essayez d’abord. <em>Décidez ensuite.</em></h2>
    </div>
    <ul class="ak-promise">
      <li><strong><?= $trial_days ?>&nbsp;jours pour essayer, sans carte bancaire.</strong> Toutes les fonctions Pro. À la fin, vous choisissez votre formule ou vous vous arrêtez : si vous ne choisissez rien, rien n’est prélevé.</li>
      <li><strong>Sans engagement.</strong> L’abonnement est mensuel : il se renouvelle chaque mois jusqu’à ce que vous le résiliiez, à tout moment, depuis votre espace, en quelques clics. La résiliation prend effet à la fin du mois déjà payé, sans frais (sans remboursement du mois entamé).</li>
      <li><strong>Pas de hausse surprise.</strong> Toute hausse de prix est annoncée par e-mail au moins 30&nbsp;jours avant, et vous pouvez résilier avant qu’elle s’applique.</li>
      <li><strong>Vos données vous appartiennent.</strong> Vous exportez vos adhérents, vos factures et votre fichier des écritures comptables (FEC) quand vous voulez. Elles restent conservées même sans formule, tant que vous ne demandez pas la fermeture de votre espace.</li>
      <li><strong>Hébergé en France.</strong> Chez O2Switch, à Clermont-Ferrand, avec des sauvegardes quotidiennes, dans le respect du RGPD. Double authentification dans toutes les formules.</li>
      <li><strong>Des humains vous répondent.</strong> Une équipe répond en français, par e-mail, en moins de 24&nbsp;h ouvrées, dans toutes les formules.</li>
      <li><strong>Des prix clairs.</strong> <?= pub_h($vat_note) ?></li>
      <?php if ($stripe_on): ?><li><strong>Paiement sécurisé par Stripe</strong>, par carte ou prélèvement SEPA.</li><?php endif; ?>
    </ul>
    <div class="ak-sec-cta">
      <a href="/signup" class="pub-btn pub-btn-primary pub-btn-lg">Essayer gratuitement <?= $trial_days ?>&nbsp;jours</a>
      <a href="/contact?subject=demo" class="pub-btn pub-btn-ghost pub-btn-lg">Demander une démo</a>
    </div>
  </div>
</section>

<!-- FAQ -->
<section class="pub-section" id="faq">
  <div class="pub-container">
    <div class="pub-section-head">
      <span class="pub-section-eyebrow">Questions fréquentes</span>
      <h2 class="pub-h2">Tout ce que vous voulez <em>savoir</em>.</h2>
    </div>

    <div class="pub-faq">
      <?php foreach ($tarifs_faqs as [$q, $a]): ?>
      <details class="pub-faq-item">
        <summary><?= pub_h($q) ?></summary>
        <div class="pub-faq-item-body"><?= pub_h($a) ?></div>
      </details>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<!-- CTA -->
<section class="pub-section">
  <div class="pub-container">
    <div class="pub-cta-section">
      <h2>Prêt·e à gagner du temps dès cette semaine&nbsp;?</h2>
      <p><?= $trial_days ?>&nbsp;jours d’essai Pro gratuits, sans carte bancaire. Une question avant de vous lancer&nbsp;? Notre équipe vous répond.</p>
      <a href="/signup" class="pub-btn pub-btn-primary pub-btn-lg">Démarrer mon essai gratuit</a>
      <a href="/contact" class="pub-btn pub-btn-ghost pub-btn-lg">Nous contacter</a>
    </div>
  </div>
</section>

<style>
/* Téléphone : marge latérale de 16 px (le conteneur public n'en laisse que 8) */
@media (max-width: 480px) { .ak-pricing-sec .pub-container, #comparer .pub-container { padding-left: 16px; padding-right: 16px; } }
.sr-only { position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px; overflow: hidden; clip: rect(0,0,0,0); white-space: nowrap; border: 0; }
.ak-pricing-sec { padding: 20px 0 60px; }
.ak-trust-row {
  list-style: none; padding: 0; margin: 22px auto 0;
  display: flex; gap: 10px 22px; justify-content: center; flex-wrap: wrap;
  font-size: 14px; font-weight: 600; color: #065F46;
}
/* Sélecteur association / entreprise */
.ak-seg { display: inline-flex; background: #ECFDF5; border: 1px solid #A7F3D0; border-radius: 999px; padding: 4px; margin: 18px auto 6px; gap: 4px; max-width: 100%; }
.ak-seg-btn { border: 0; background: transparent; padding: 9px 18px; border-radius: 999px; font: inherit; font-size: 14px; font-weight: 700; color: #065F46; cursor: pointer; white-space: nowrap; }
@media (max-width: 420px) { .ak-seg-btn { padding: 8px 11px; font-size: 13px; } }
.ak-seg-btn[aria-pressed="true"] { background: #fff; color: #064E3B; box-shadow: 0 2px 8px rgba(6,95,70,0.15); }
.ak-seg-btn:focus-visible, .ak-tab:focus-visible { outline: 3px solid #10B981; outline-offset: 2px; }
.ak-seg-help { font-size: 13px; color: #6b7280; margin: 0 0 18px; }
.ak-anchor-line { max-width: 820px; margin: 0 auto 8px; text-align: center; font-size: 16.5px; font-weight: 600; color: #064E3B; line-height: 1.55; }
.ak-example { max-width: 760px; margin: 0 auto 34px; text-align: center; font-size: 13px; color: #6b7280; line-height: 1.55; }

/* Grille des 3 cartes : Essentiel, Pro (centre, surélevé), Équipe */
.ak-pricing-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 18px; max-width: 1200px; margin: 0 auto; align-items: start; }
.ak-pcard { background: #fff; border: 1px solid #e5e7eb; border-radius: 20px; padding: 30px 26px; position: relative; display: flex; flex-direction: column; min-width: 0; }
.ak-anchor { position: absolute; top: -90px; }
.ak-pcard-featured {
  background: linear-gradient(165deg, #fff 0%, #F0FDF4 100%);
  border: 2px solid #10B981;
  box-shadow: 0 20px 50px rgba(16,185,129,0.18), 0 4px 12px rgba(0,0,0,0.04);
  transform: translateY(-14px); z-index: 2;
}
.ak-pcard-badge {
  position: absolute; top: -14px; left: 50%; transform: translateX(-50%);
  background: linear-gradient(135deg, #10B981, #059669); color: #fff;
  padding: 7px 18px; border-radius: 999px; font-size: 12px; font-weight: 700; letter-spacing: 0.02em;
  box-shadow: 0 6px 16px rgba(16,185,129,0.35); white-space: nowrap;
}
.ak-pcard-head { margin-bottom: 8px; }
.ak-pcard-nick { font-size: 12px; color: #6b7280; font-weight: 700; text-transform: uppercase; letter-spacing: 0.06em; margin-bottom: 4px; }
.ak-pcard-label { color: #1E3A8A; }
.ak-pcard-name { font-size: 24px; font-weight: 800; color: #111827; letter-spacing: -0.01em; line-height: 1.1; margin: 0; }
.ak-pcard-featured .ak-pcard-name { color: #065F46; }
.ak-pcard-tag { font-size: 13px; color: #4b5563; margin-top: 4px; font-weight: 600; }
.ak-pcard-sign { font-size: 15px; font-weight: 700; color: #047857; margin: 6px 0 12px; }
.ak-pcard-price { display: flex; align-items: baseline; flex-wrap: wrap; gap: 6px; margin-bottom: 4px; }
.ak-pcard-amt { font-size: 46px; font-weight: 800; color: #111827; line-height: 1; letter-spacing: -0.02em; font-variant-numeric: tabular-nums; }
.ak-pcard-per { font-size: 15px; color: #374151; font-weight: 700; }
.ak-pcard-ttc { font-size: 13px; color: #4b5563; margin: 0 0 4px; }
.ak-pcard-sub { font-size: 13px; color: #059669; font-weight: 700; margin: 4px 0 12px; line-height: 1.45; }
.ak-pcard-desc { font-size: 14px; color: #4b5563; line-height: 1.55; margin: 0 0 18px; }
.ak-pcard-cta { display: block; padding: 13px 18px; border-radius: 11px; text-align: center; font-size: 14.5px; font-weight: 700; text-decoration: none; transition: background 0.2s, box-shadow 0.2s, border-color 0.2s; letter-spacing: 0.01em; }
.ak-pcard-cta-ghost { background: #fff; color: #111827; border: 1.5px solid #d1d5db; }
.ak-pcard-cta-ghost:hover { border-color: #10B981; color: #047857; }
.ak-pcard-cta-primary { background: linear-gradient(135deg, #10B981, #059669); color: #fff; border: 0; box-shadow: 0 6px 18px rgba(16,185,129,0.35); }
.ak-pcard-cta-primary:hover { box-shadow: 0 10px 24px rgba(16,185,129,0.45); }
.ak-pcard-cta-dark { background: #111827; color: #fff; border: 0; }
.ak-pcard-cta-dark:hover { background: #000; }
.ak-pcard-reassure { font-size: 12.5px; color: #4b5563; margin: 10px 0 0; line-height: 1.5; text-align: center; }
.ak-pcard-link { display: block; text-align: center; font-size: 13.5px; font-weight: 700; color: #047857; margin-top: 8px; }
.ak-pcard-divider { height: 1px; background: #f3f4f6; margin: 18px 0; }
.ak-pcard-feat-title { font-size: 12px; color: #6b7280; font-weight: 700; text-transform: uppercase; letter-spacing: 0.06em; margin-bottom: 12px; }
.ak-pcard-features { list-style: none; padding: 0; margin: 0; display: flex; flex-direction: column; gap: 11px; }
.ak-pcard-features li { font-size: 13.5px; color: #374151; line-height: 1.5; padding-left: 26px; position: relative; overflow-wrap: anywhere; }
.ak-pcard-features li[hidden] { display: none; }
.ak-pcard-features li::before {
  content: ""; position: absolute; left: 0; top: 2px; width: 18px; height: 18px; border-radius: 50%;
  background: #ECFDF5 url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 20 20' fill='%2310B981'><path d='M16.7 5.3a1 1 0 010 1.4l-7 7a1 1 0 01-1.4 0l-4-4a1 1 0 111.4-1.4L9 11.6l6.3-6.3a1 1 0 011.4 0z'/></svg>") center/12px no-repeat;
}
.ak-pcard-features li strong { color: #111827; font-weight: 700; }
.ak-pcard-calc { margin-top: 16px; padding: 12px 14px; background: #ECFDF5; border-radius: 12px; font-size: 13px; color: #065F46; line-height: 1.5; }
.ak-pcard-fine { font-size: 12.5px; color: #4b5563; margin: 16px 0 0; line-height: 1.5; }

/* Bandeau Sur-mesure */
.ak-sm { max-width: 1200px; margin: 30px auto 0; background: #0F172A; color: #E2E8F0; border-radius: 20px; padding: 30px 32px; display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1.2fr); gap: 26px; }
.ak-sm-eyebrow { font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.06em; color: #94A3B8; }
.ak-sm-title { font-size: 26px; font-weight: 800; color: #fff; margin: 6px 0 2px; }
.ak-sm-sign { font-weight: 700; color: #6EE7B7; margin: 0 0 12px; }
.ak-sm-price { font-size: 26px; font-weight: 800; color: #fff; letter-spacing: 0.02em; font-variant-numeric: tabular-nums; }
.ak-sm-quote { font-weight: 700; color: #fff; margin: 4px 0 12px; }
.ak-sm-text { font-size: 14px; line-height: 1.6; margin: 0; }
.ak-sm-list { list-style: none; padding: 0; margin: 0 0 12px; display: grid; gap: 8px; }
.ak-sm-list li { font-size: 14px; line-height: 1.5; padding-left: 22px; position: relative; }
.ak-sm-list li::before { content: "✓"; position: absolute; left: 0; color: #6EE7B7; font-weight: 800; }
.ak-sm-fine { font-size: 13px; color: #CBD5E1; margin: 0 0 16px; }
.ak-sm-ctas { display: flex; gap: 10px; flex-wrap: wrap; }
.ak-sm-btn { display: inline-block; padding: 12px 18px; border-radius: 11px; font-weight: 700; font-size: 14px; text-decoration: none; }
.ak-sm-btn-light { background: #fff; color: #0F172A; }
.ak-sm-btn-light:hover { background: #ECFDF5; }
.ak-sm-btn-line { border: 1.5px solid #CBD5E1; color: #fff; }
.ak-sm-btn-line:hover { border-color: #fff; }

.ak-pricing-foot { text-align: center; margin: 30px auto 0; max-width: 820px; color: #4b5563; font-size: 13.5px; line-height: 1.55; }
.ak-pricing-foot p { margin: 0 0 6px; }
.ak-compare-link { font-weight: 700; color: #047857; }

/* Tableaux */
.ak-table-wrap { max-width: 1200px; margin: 0 auto; }
.ak-cmp { width: 100%; border-collapse: collapse; background: #fff; border: 1px solid #E5E7EB; border-radius: 14px; overflow: hidden; font-size: 14px; }
.ak-cmp caption { caption-side: top; text-align: left; font-weight: 700; color: #064E3B; padding: 0 0 10px; }
.ak-cmp th, .ak-cmp td { padding: 12px 14px; border-bottom: 1px solid #F1F5F9; text-align: left; vertical-align: top; line-height: 1.45; }
.ak-cmp thead th { background: #F8FAFC; font-size: 15px; color: #111827; }
.ak-cmp thead th span { display: block; font-size: 12px; font-weight: 600; color: #6b7280; }
.ak-cmp tbody th { font-weight: 500; color: #374151; }
.ak-cmp .ak-col-pro { background: #F0FDF4; }
.ak-cmp-badge { font-style: normal; font-size: 11px; font-weight: 700; background: #10B981; color: #fff; padding: 2px 8px; border-radius: 999px; margin-left: 4px; white-space: nowrap; }
.ak-cmp-foot, .ak-note-center { max-width: 820px; margin: 16px auto 0; text-align: center; font-size: 13px; color: #4b5563; line-height: 1.55; }
.ak-cmp-foot p { margin: 0 0 4px; }
.ak-tabs { display: none; }

.ak-sec-cta { text-align: center; margin-top: 30px; display: flex; gap: 12px; justify-content: center; flex-wrap: wrap; }
.ak-promise { max-width: 1000px; margin: 0 auto; display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 14px 26px; list-style: none; padding: 0; }
.ak-promise li { font-size: 14.5px; color: #374151; line-height: 1.55; padding-left: 26px; position: relative; }
.ak-promise li::before { content: "✓"; position: absolute; left: 0; color: #059669; font-weight: 800; }

/* Téléphone et tablette : Pro, Équipe, Essentiel, puis Sur-mesure */
@media (max-width: 1000px) {
  .ak-pricing-grid { grid-template-columns: minmax(0, 1fr); max-width: 520px; gap: 26px; }
  .ak-pcard-featured { transform: none; }
  .ak-pcard-pro { order: 1; }
  .ak-pcard-equipe { order: 2; }
  .ak-pcard-ess { order: 3; }
  .ak-sm { grid-template-columns: minmax(0, 1fr); max-width: 520px; }
  .ak-tabs { display: flex; gap: 6px; justify-content: center; margin: 0 0 14px; }
  .ak-tab { border: 1px solid #A7F3D0; background: #fff; color: #065F46; font: inherit; font-weight: 700; font-size: 14px; padding: 8px 14px; border-radius: 999px; cursor: pointer; }
  .ak-tab[aria-pressed="true"] { background: #065F46; color: #fff; border-color: #065F46; }
  /* Une seule colonne de formule visible, la colonne des libellés reste à gauche */
  .ak-cmp-main [data-c] { display: none; }
  .ak-cmp-main[data-show="1"] [data-c="1"], .ak-cmp-main[data-show="2"] [data-c="2"], .ak-cmp-main[data-show="3"] [data-c="3"] { display: table-cell; }
  .ak-cmp-main th, .ak-cmp-main td { width: 50%; }
  .ak-cmp-2 thead { display: none; }
  .ak-cmp-2 tr { display: block; border-bottom: 1px solid #F1F5F9; padding: 8px 0; }
  .ak-cmp-2 th, .ak-cmp-2 td { display: block; border: 0; padding: 4px 14px; }
  .ak-cmp-2 td:nth-of-type(1)::before { content: "Pro\00a0: "; font-weight: 700; }
  .ak-cmp-2 td:nth-of-type(2)::before { content: "Équipe\00a0: "; font-weight: 700; }
  .ak-cmp-2 td:nth-of-type(3) { color: #6b7280; }
  .ak-promise { grid-template-columns: minmax(0, 1fr); }
}
@media (max-width: 480px) {
  .ak-pcard { padding: 26px 20px; }
  .ak-pcard-amt { font-size: 40px; }
  .ak-sm { padding: 24px 20px; }
  .ak-cmp th, .ak-cmp td { padding: 10px 10px; font-size: 13.5px; }
}
@media (prefers-reduced-motion: reduce) { .ak-pcard-cta { transition: none; } }
</style>
<script>
(function () {
  // Sélecteur Association / Entreprise : change les exemples, jamais un prix ni une limite
  var KEY = 'ak_tarifs_variant';
  function setVariant(v) {
    document.querySelectorAll('[data-v]').forEach(function (el) { el.hidden = el.getAttribute('data-v') !== v; });
    document.querySelectorAll('.ak-seg-btn').forEach(function (b) { b.setAttribute('aria-pressed', b.getAttribute('data-seg') === v ? 'true' : 'false'); });
    try { localStorage.setItem(KEY, v); } catch (e) {}
  }
  document.querySelectorAll('.ak-seg-btn').forEach(function (b) {
    b.addEventListener('click', function () { setVariant(b.getAttribute('data-seg')); });
  });
  try { var saved = localStorage.getItem(KEY); if (saved === 'e') setVariant('e'); } catch (e) {}
  // Onglets du tableau comparatif (téléphone)
  var table = document.querySelector('.ak-cmp-main');
  document.querySelectorAll('.ak-tab').forEach(function (t) {
    t.addEventListener('click', function () {
      if (table) table.setAttribute('data-show', t.getAttribute('data-col'));
      document.querySelectorAll('.ak-tab').forEach(function (o) { o.setAttribute('aria-pressed', o === t ? 'true' : 'false'); });
    });
  });
})();
</script>

<?php
render_public_footer();
render_public_foot();
