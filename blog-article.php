<?php
/**
 * blog-article.php
 * --------------------------------------------------------------
 * Page d'article single
 * URL : /blog/{slug} (via .htaccess rewrite)
 * --------------------------------------------------------------
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes-public.php';
require_once __DIR__ . '/blog-helpers.php';
require_once __DIR__ . '/blog-markdown.php';

$slug = trim((string)($_GET['slug'] ?? ''));
if ($slug === '') { header('Location: /blog'); exit; }

$article = blog_get_by_slug($pdo, $slug);
if (!$article) {
    http_response_code(404);
    render_public_head([
        'title'       => '404 · Article introuvable',
        'description' => 'Cet article n\'existe pas ou plus.',
        'path'        => '/blog/' . $slug,
        'noindex'     => true,
    ]);
    render_public_nav('blog');
    ?>
    <section class="pub-section pub-text-center" style="padding: 100px 0;">
      <div class="pub-container-narrow">
        <div style="font-size:80px;margin-bottom:14px;">🌱</div>
        <h1 class="pub-h2">Article introuvable</h1>
        <p style="color:var(--c-text-2);font-size:17px;">Cet article n'existe pas ou a été retiré.</p>
        <a href="/blog" class="pub-btn pub-btn-primary pub-btn-lg" style="margin-top:20px;">← Retour au blog</a>
      </div>
    </section>
    <?php
    render_public_footer();
    render_public_foot();
    exit;
}

$cat = blog_category((string)$article['category']);
$related = blog_related($pdo, $article, 3);
$tags = $article['tags'] ? array_map('trim', explode(',', $article['tags'])) : [];

$breadcrumb = build_breadcrumb_jsonld([
    ['name' => 'Accueil', 'url' => '/'],
    ['name' => 'Blog',    'url' => '/blog'],
    ['name' => $cat['label'], 'url' => '/blog?categorie=' . $article['category']],
    ['name' => $article['title'], 'url' => '/blog/' . $article['slug']],
]);

// Image de l'article : cover dédiée si disponible, sinon visuel de marque par défaut (1200x630)
$article_image = !empty($article['og_image'])
    ? (preg_match('#^https?://#', $article['og_image']) ? $article['og_image'] : AK_PUBLIC_DOMAIN . '/' . ltrim($article['og_image'], '/'))
    : AK_PUBLIC_DOMAIN . '/assets/og-assokit-home.png';

// Comptage de mots (approx.) depuis le markdown, pour le schema
$word_count = str_word_count(strip_tags((string)$article['content_md']));

// Schema BlogPosting (Article enrichi E-E-A-T)
$article_jsonld = [
    '@context' => 'https://schema.org',
    '@type'    => 'BlogPosting',
    'headline' => mb_substr($article['title'], 0, 110),
    'description' => $article['excerpt'],
    'image' => [$article_image],
    'datePublished' => $article['published_at'] ? date('c', strtotime($article['published_at'])) : null,
    'dateModified'  => ($article['updated_at'] ?: $article['published_at']) ? date('c', strtotime($article['updated_at'] ?: $article['published_at'])) : null,
    // « L'équipe Assokit » n'est pas une personne : auteur = l'organisation. Un auteur nommé
    // (rédacteur réel) reste une Person.
    'author' => ($article['author'] && stripos((string) $article['author'], 'équipe') === false)
        ? ['@type' => 'Person', 'name' => $article['author'], 'url' => AK_PUBLIC_DOMAIN . '/a-propos']
        : ['@type' => 'Organization', '@id' => AK_PUBLIC_DOMAIN . '/#organization', 'name' => 'Assokit', 'url' => AK_PUBLIC_DOMAIN],
    'publisher' => [
        '@type' => 'Organization',
        '@id'   => AK_PUBLIC_DOMAIN . '/#organization',
        'name'  => 'Assokit',
        'logo'  => ['@type' => 'ImageObject', 'url' => AK_PUBLIC_DOMAIN . '/assets/logo-assokit.png', 'width' => 1024, 'height' => 1024],
    ],
    'mainEntityOfPage' => [
        '@type' => 'WebPage',
        '@id'   => pub_url('/blog/' . $article['slug']),
    ],
    'articleSection' => $cat['label'],
    'keywords' => implode(', ', $tags),
    'wordCount' => $word_count,
    'inLanguage' => 'fr-FR',
    'isAccessibleForFree' => true,
];

// FAQPage : extraction automatique depuis une section « ## FAQ » du markdown (si présente).
// Valide + réutilisé par les AI Overviews. Conditionnel : les articles sans FAQ sont ignorés.
$faq_jsonld = blog_extract_faq_jsonld((string)$article['content_md']);

render_public_head([
    // Un meta_title ou une meta_description coupés par « … » (ancien générateur) sont ignorés :
    // le titre complet et l'extrait (coupé à la dernière phrase entière par render_public_head) valent mieux.
    'title'       => (($article['meta_title'] ?? '') !== '' && mb_substr((string) $article['meta_title'], -1) !== '…') ? $article['meta_title'] : $article['title'],
    'description' => (($article['meta_description'] ?? '') !== '' && mb_substr((string) $article['meta_description'], -1) !== '…') ? $article['meta_description'] : ($article['excerpt'] ?: $article['meta_description']),
    'path'        => '/blog/' . $article['slug'],
    'og_type'     => 'article',
    'og_image'    => $article_image,
    'article_data' => [
        'published_time' => $article['published_at'],
        'modified_time'  => $article['updated_at'],
        'author'         => $article['author'] ?: 'L\'équipe Assokit',
        'section'        => $cat['label'],
        'tags'           => $tags,
    ],
    'schema_jsonld' => array_values(array_filter([$breadcrumb, $article_jsonld, $faq_jsonld])),
]);
render_public_nav('blog');
?>

<!-- HERO -->
<section style="background:linear-gradient(135deg, <?= pub_h($article['cover_color_from'] ?: '#059669') ?> 0%, <?= pub_h($article['cover_color_to'] ?: '#0F172A') ?> 100%); color: white; padding: 60px 0;">
  <div class="pub-container-narrow">
    <div class="pub-breadcrumb" style="color:rgba(255,255,255,0.7);">
      <a href="/" style="color:rgba(255,255,255,0.85);">Accueil</a><span class="pub-breadcrumb-sep">›</span>
      <a href="/blog" style="color:rgba(255,255,255,0.85);">Blog</a><span class="pub-breadcrumb-sep">›</span>
      <a href="/blog?categorie=<?= pub_h($article['category']) ?>" style="color:rgba(255,255,255,0.85);"><?= pub_h($cat['label']) ?></a>
    </div>
    <div style="font-size:60px;margin: 20px 0 14px;"><?= pub_h($article['cover_emoji'] ?: $cat['icon']) ?></div>
    <h1 style="font-size:42px;font-weight:700;letter-spacing:-0.02em;line-height:1.15;margin:0 0 18px;color:white;"><?= pub_h($article['title']) ?></h1>
    <p style="font-size:18px;line-height:1.55;color:rgba(255,255,255,0.85);margin:0 0 24px;max-width:680px;"><?= pub_h($article['excerpt']) ?></p>
    <div style="display:flex;gap:18px;flex-wrap:wrap;font-size:14px;color:rgba(255,255,255,0.75);">
      <span>👤 <?= pub_h($article['author'] ?: 'L\'équipe Assokit') ?></span>
      <span>📅 <?= pub_h(blog_format_date_fr($article['published_at'])) ?></span>
      <span>⏱ <?= (int)$article['reading_time_min'] ?> min de lecture</span>
    </div>
  </div>
</section>

<!-- CONTENU -->
<section style="padding: 0 0 60px;">
  <div class="pub-container-narrow">
    <article class="pub-article-content" style="margin-top:-20px;position:relative;">
      <?= render_blog_markdown_css() ?>
      <?php
      // === Nettoyage du contenu généré (retire tous les blocs promo/CTA bruts) ===
      $ak_body = (string) $article['content_md'];
      // 0) retire le H1 en tête (le titre est déjà affiché dans le hero) — évite un doublon de titre
      $ak_body = preg_replace('/\A(?:\xEF\xBB\xBF)?\s*#[ \t]+[^\n]+\n+/', '', $ak_body, 1);
      // 1) retire les citations « callout » Assokit (> ### 💡 Comment Assokit … — mal rendues)
      $ak_body = preg_replace_callback('/(?:^[ \t]*>[^\n]*\n?)+/m', function ($m) {
          return (stripos($m[0], 'Comment Assokit') !== false) ? "\n" : $m[0];
      }, $ak_body);
      // 2) retire chaque section promotionnelle : le titre + son contenu jusqu'au titre suivant
      //    (s'arrête aux titres comme « ## FAQ » -> la FAQ n'est jamais supprimée)
      foreach ([
          'Pr[êe]t\s+à\s+passer\s+à\s+l[\'’]action', 'Passez\s+à\s+l[\'’]action',
          'Articles?\s+li[ée]s', 'À\s+lire\s+aussi', 'Avec\s+Assokit',
          'gagnez\s+du\s+temps', 'Pourquoi\s+(?:choisir\s+)?Assokit', 'D[ée]couvrez\s+Assokit',
      ] as $ak_p) {
          $ak_body = preg_replace('/\n[ \t]*(?:[-*_]{3,}[ \t]*\n)?#{1,4}[ \t]*[^\n]*(?:' . $ak_p . ')[^\n]*(?:\n(?![ \t]*#{1,4}[ \t]).*)*/iu', '', $ak_body);
      }
      // 3) retire la signature « > *Article rédigé par l'équipe Assokit…* » + séparateurs orphelins
      $ak_body = preg_replace('/\n[ \t]*>[^\n]*Article r[ée]dig[ée][^\n]*(?:\n[ \t]*>[^\n]*)*/iu', '', $ak_body);
      $ak_body = preg_replace('/\n{3,}/', "\n\n", (string) $ak_body);
      $ak_body = preg_replace('/(?:\n[ \t]*[-*_]{3,}[ \t]*)+\s*$/', '', rtrim((string) $ak_body));

      // === CTA contextuels selon le thème de l'article ===
      // Ordre : du besoin le plus précis au plus général. La facturation passe avant la compta
      // (sinon les articles facture, avoir, Chorus Pro… ne menaient jamais à /logiciel-facturation).
      $ak_theme = 'asso';
      $cat_l = mb_strtolower((string) $article['category'] . ' ' . $article['title'] . ' ' . $article['slug']);
      $ak_is_tpe = (bool) preg_match('/\btpe\b|entreprise|auto-?entrepreneur|ind[ée]pendant|artisan/u', $cat_l);
      if (preg_match('/factur|devis|\bavoirs?\b|chorus|bon[- ]de[- ]commande/u', $cat_l)) $ak_theme = 'facturation';
      elseif (preg_match('/cotisation/u', $cat_l)) $ak_theme = 'cotisation';
      elseif (preg_match('/adh[ée]sion|adh[ée]rent|membres?\b|radier|radiation/u', $cat_l)) $ak_theme = 'adherents';
      elseif ($ak_is_tpe) $ak_theme = 'tpe';
      elseif (preg_match('/compta|tva|bilan|fiscal|tr[ée]sor|budget|frais|kilom[ée]tr|justificatif/u', $cat_l)) $ak_theme = 'compta';
      elseif (preg_match('/juridiqu|statut|rgpd|l[ée]gal|obligation|d[ée]claration/u', $cat_l)) $ak_theme = 'juridique';
      elseif (preg_match('/communic|newsletter|r[ée]seau|email/u', $cat_l)) $ak_theme = 'communication';

      $AK_CTA = [
        'facturation' => [
          ['ic'=>'🧾','t'=>'Des factures justes du premier coup, et des impayés relancés','p'=>'Avec Assokit, devis et factures sont numérotés automatiquement, avec les mentions et la TVA calculées. Un devis accepté devient facture en un clic, les <strong>relances d\'impayés partent seules</strong> et une erreur se corrige par un avoir.','a'=>['/logiciel-facturation','Logiciel de facturation pour association'],'b'=>['/tarifs','Voir les tarifs']],
          ['ic'=>'📄','t'=>'Factures au format Factur-X','p'=>'Vos factures sont générées au format Factur-X (PDF + XML), vos pièces centralisées et chaque facture alimente directement votre comptabilité. Le raccordement à une plateforme agréée est en préparation.','a'=>[$ak_is_tpe ? '/logiciel-gestion-tpe' : '/logiciel-comptabilite-association', $ak_is_tpe ? 'Logiciel de gestion pour TPE' : 'Logiciel de comptabilité association'],'b'=>['/contact','Réserver une démo']],
        ],
        'cotisation' => [
          ['ic'=>'💸','t'=>'Sachez enfin qui est à jour de sa cotisation','p'=>'Campagnes et tarifs, règlements par chèque, virement ou espèces enregistrés en quelques secondes, <strong>adhésions à renouveler relancées</strong> en un clic ou automatiquement. Le trésorier voit qui a payé, sans tableur.','a'=>['/logiciel-cotisation-association','Gérer les cotisations avec Assokit'],'b'=>['/tarifs','Voir les tarifs']],
          ['ic'=>'👥','t'=>'Un fichier adhérents relié aux cotisations','p'=>'Chaque règlement met à jour la fiche du membre et prolonge son adhésion. L\'historique reste complet d\'une saison et d\'un trésorier à l\'autre.','a'=>['/logiciel-adherents','Logiciel de gestion des adhérents'],'b'=>['/contact','Réserver une démo']],
        ],
        'adherents' => [
          ['ic'=>'👥','t'=>'Un fichier des membres toujours à jour','p'=>'Fiche par adhérent, rôles et espaces membres, import de votre fichier Excel, <strong>renouvellements suivis</strong> et relances des adhésions qui arrivent à échéance. Fini les versions de tableur qui circulent par e-mail.','a'=>['/logiciel-adherents','Logiciel de gestion des adhérents'],'b'=>['/tarifs','Voir les tarifs']],
          ['ic'=>'💸','t'=>'Les cotisations au même endroit','p'=>'Campagnes, tarifs et règlements enregistrés sur la fiche de chaque membre : vous savez qui est à jour, sans double saisie.','a'=>['/logiciel-cotisation-association','Gérer les cotisations'],'b'=>['/contact','Réserver une démo']],
        ],
        'compta' => [
          ['ic'=>'📊','t'=>'La compta de votre association, sans y passer vos soirées','p'=>'Assokit construit votre comptabilité analytique <strong>automatiquement</strong> au fil de votre activité : factures, cotisations, dépenses catégorisées, justificatifs centralisés. Bilan par projet inclus dans l\'abonnement.','a'=>['/logiciel-comptabilite-association','Logiciel de comptabilité association'],'b'=>['/tarifs','Voir les tarifs']],
          ['ic'=>'✅','t'=>'Un dossier propre et daté pour votre expert-comptable','p'=>'Fini la ressaisie de fin d\'année. Vos comptes se préparent tout seuls, exportables en PDF et Excel — votre comptable n\'a plus qu\'à valider.','a'=>['/pour-associations','Découvrir pour les assos'],'b'=>['/contact','Réserver une démo']],
        ],
        'tpe' => [
          ['ic'=>'🧾','t'=>'Devis, factures et relances : arrêtez de courir après vos paiements','p'=>'Assokit crée vos devis et factures pro en 2 minutes, envoie les <strong>relances automatiquement</strong> et suit votre trésorerie en temps réel. Vous récupérez plusieurs heures par semaine.','a'=>['/logiciel-gestion-tpe','Logiciel de gestion pour TPE'],'b'=>['/tarifs','Voir les tarifs']],
          ['ic'=>'🏗️','t'=>'Sachez enfin quel chantier vous rapporte vraiment','p'=>'Un projet par client/chantier : budget, factures rattachées, rentabilité réelle poste par poste. Le pilotage clair de votre activité, sur mobile comme au bureau.','a'=>['/fonctionnalites','Voir les fonctionnalités'],'b'=>['/contact','Réserver une démo']],
        ],
        'juridique' => [
          ['ic'=>'🛡️','t'=>'Des obligations suivies sans effort','p'=>'Registres tenus automatiquement, traçabilité des décisions, respect du RGPD, archivage horodaté. Avec Assokit, vos obligations sont <strong>suivies au fil de l’eau</strong>, pas rattrapées en catastrophe.','a'=>['/pour-associations','Découvrir Assokit'],'b'=>['/fonctionnalites','Les fonctionnalités']],
          ['ic'=>'📁','t'=>'Toutes vos pièces au même endroit, retrouvables en 2 clics','p'=>'Statuts, PV, contrats, justificatifs : centralisés et sécurisés, hébergés en France. En cas de contrôle ou de passation, tout est prêt.','a'=>['/pour-associations','Pour les associations'],'b'=>['/contact','Réserver une démo']],
        ],
        'communication' => [
          ['ic'=>'✍️','t'=>'Vos contenus rédigés en 30 secondes','p'=>'Newsletter, compte-rendu, lettre aux adhérents, post réseaux : les <strong>outils IA d\'Assokit</strong> pré-rédigent, vous gardez la main.','a'=>['/fonctionnalites','Voir l\'IA intégrée'],'b'=>['/tarifs','Voir les tarifs']],
          ['ic'=>'📣','t'=>'Touchez le bon public, au bon moment','p'=>'Diffusion email ciblée par rôle ou par équipe, suivi des ouvertures, listes toujours à jour. Votre communication devient simple et mesurable.','a'=>['/pour-associations','Découvrir Assokit'],'b'=>['/contact','Réserver une démo']],
        ],
        'asso' => [
          ['ic'=>'🤝','t'=>'La gestion de votre association, sans la paperasse','p'=>'Adhérents, cotisations, projets, événements et communication réunis dans <strong>un seul outil pensé pour les assos loi 1901</strong>. Le temps bénévole retourne à votre vraie mission.','a'=>['/pour-associations','Assokit pour les associations'],'b'=>['/tarifs','Voir les tarifs']],
          ['ic'=>'💸','t'=>'Ne courez plus après les cotisations','p'=>'Campagnes et tarifs, règlements enregistrés (chèque, virement, espèces), relances des adhésions, suivi en temps réel. Le trésorier sait qui est à jour, sans tableur cassé.','a'=>['/fonctionnalites','Voir les fonctionnalités'],'b'=>['/contact','Réserver une démo']],
        ],
      ];
      $ak_cards = $AK_CTA[$ak_theme] ?? $AK_CTA['asso'];

      $ak_render_card = function (array $c) {
          $arrow = '<svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M13 6l6 6-6 6"/></svg>';
          $h  = '<div class="bm-cta"><span class="bm-cta-eb"><span class="bm-cta-dot"></span>Avec Assokit</span>';
          $h .= '<h3 class="bm-cta-t">' . htmlspecialchars($c['t']) . '</h3>';
          $h .= '<p>' . $c['p'] . '</p><div class="bm-cta-btns">';
          $h .= '<a class="bm-cta-btn primary" href="' . $c['a'][0] . '">' . htmlspecialchars($c['a'][1]) . $arrow . '</a>';
          $h .= '<a class="bm-cta-btn link" href="' . $c['b'][0] . '">' . htmlspecialchars($c['b'][1]) . ' ' . $arrow . '</a>';
          return $h . '</div></div>';
      };

      // Rend le HTML puis injecte les cartes CTA à ~40% et ~75% (après une fin de paragraphe)
      $ak_html = render_blog_markdown($ak_body);
      $ak_inject = function (string $html, array $cards) use ($ak_render_card) {
          if (!preg_match_all('/<\/p>/', $html, $mm, PREG_OFFSET_CAPTURE)) return $html . $ak_render_card($cards[0]);
          $ends = array_map(fn($x) => $x[1] + 4, $mm[0]);
          $n = count($ends);
          $spots = [];
          foreach ([0.40, 0.75] as $i => $frac) {
              if (!isset($cards[$i])) break;
              $idx = min($n - 1, max(0, (int) round($frac * $n)));
              $spots[$ends[$idx]] = $ak_render_card($cards[$i]);
          }
          krsort($spots); // insère de la fin vers le début pour garder les offsets valides
          foreach ($spots as $pos => $card) $html = substr($html, 0, $pos) . $card . substr($html, $pos);
          return $html;
      };

      // === Lien contextuel dans le texte (articles qui reçoivent le plus de visites) ===
      // Une phrase par article, insérée à la fin de la première section (avant le 2e titre H2),
      // avant les cartes CTA pour ne jamais tomber juste après l'une d'elles :
      // un seul lien commercial, ancre descriptive et variée d'un article à l'autre.
      // [texte avant le lien, ancre, url, texte après le lien]
      $AK_CONTEXT_LINKS = [
        'changement-tresorier-association-passation-en-7-etapes-cles' => ['Quand les comptes sont tenus dans un ', 'logiciel de comptabilité pour association', '/logiciel-comptabilite-association', ', la passation se résume à transmettre un accès : l\'historique, les justificatifs et le budget restent en place.'],
        'relance-cotisation-association-5-modeles-d-emails-et-sms-qui-fonctionnent' => ['Pour ne plus envoyer les relances une par une, vous pouvez aussi ', 'relancer automatiquement les cotisations', '/logiciel-cotisation-association', ' arrivées à échéance depuis votre fichier d\'adhérents.'],
        'depenses-sans-justificatif-en-association-les-limites-legales-en-2026' => ['Le plus sûr reste de ', 'centraliser les justificatifs de l\'association', '/logiciel-comptabilite-association', ' au moment de chaque dépense, plutôt qu\'en fin d\'exercice.'],
        'budget-previsionnel-association-2026-modele-excel-gratuit-et-tutoriel-complet' => ['Une fois le budget voté, il reste à ', 'suivre le budget par projet', '/comptabilite-analytique', ' tout au long de l\'année, pour comparer le prévu et le réalisé.'],
        'tresorerie-association-tableau-de-suivi-excel-gratuit-2026' => ['Quand un tableau Excel devient trop lourd à tenir, un ', 'logiciel de trésorerie pour association', '/logiciel-comptabilite-association', ' reprend les encaissements et les dépenses sans ressaisie.'],
        'plan-comptable-association-modele-simplifie' => ['Un ', 'logiciel de comptabilité d\'association', '/logiciel-comptabilite-association', ' classe chaque recette et chaque dépense dans le bon poste, sans connaître les numéros de compte par cœur.'],
        'logiciel-comptable-gratuit-vs-excel-pour-association-que-choisir-en-2026' => ['Pour comparer les options sans engagement, voir aussi notre point sur le ', 'logiciel association gratuit', '/logiciel-association-gratuit', ' : ce qui est vraiment gratuit, et ce qui ne l\'est pas.'],
        'passage-d-une-comptabilite-excel-a-un-logiciel-10-etapes-sans-perdre-de-donnees' => ['Si vous hésitez encore, découvrez ce que change le fait de ', 'passer à un logiciel de comptabilité d\'association', '/logiciel-comptabilite-association', ', avec un essai gratuit de 14 jours.'],
        'erreur-sur-facture-electronique-comment-corriger-legalement-en-2026' => ['Avec un ', 'logiciel de facturation pour association', '/logiciel-facturation', ', l\'avoir se crée depuis la facture d\'origine, reprend ses lignes et prend son numéro dans la même séquence que vos factures : aucune ressaisie.'],
        'chorus-pro-association-inscription-et-premier-envoi-en-8-etapes' => ['Avant le dépôt sur Chorus Pro, la facture elle-même peut être préparée dans un ', 'logiciel de facturation', '/logiciel-facturation', ' qui calcule la TVA et numérote vos pièces.'],
        'facture-association-sans-numero-siret-les-alternatives-legales-en-2027' => ['Une fois le SIRET obtenu, vos ', 'factures d\'association', '/logiciel-facturation', ' peuvent être émises avec toutes les mentions, au format PDF ou Factur-X.'],
        'association-et-facturation-de-partenariat-sponsors-vs-mecenat-fiscal' => ['Un partenariat de sponsoring se facture comme toute prestation : un ', 'outil de facturation pour association', '/logiciel-facturation', ' évite les oublis de mentions et de TVA.'],
        'association-et-auto-entrepreneur-qui-facture-qui-et-comment-gerer-la-situation' => ['Côté association, chaque facture reçue ou émise peut être rattachée au projet concerné grâce à un ', 'logiciel de facturation et de devis', '/logiciel-facturation', '.'],
        'prestation-de-service-payante-en-association-7-activites-lucratives-autorisees' => ['Pour les prestations payantes de l\'association, ', 'établir devis et factures', '/logiciel-facturation', ' dans un outil dédié permet de suivre ce qui a été encaissé et ce qui reste dû.'],
        'archivage-factures-association-duree-legale-et-obligations-2027' => ['Les factures émises avec un ', 'logiciel de facturation', '/logiciel-facturation', ' sont conservées et retrouvables en PDF, classées par client et par date.'],
        'avoir-ou-remboursement-quelle-difference-comptable-et-fiscale-en-tpe' => ['Dans la pratique, ', 'émettre un avoir', '/logiciel-facturation', ' depuis la facture d\'origine garde la numérotation et la comptabilité cohérentes.'],
        'adhesion-association-regles-bonnes-pratiques' => ['Pour suivre les adhésions et leurs renouvellements sans tableur, voir notre ', 'logiciel de gestion des adhérents', '/logiciel-adherents', '.'],
        'radier-membre-association-procedure' => ['Chaque radiation reste tracée sur la fiche du membre dans un ', 'fichier des adhérents', '/logiciel-adherents', ' tenu à jour, utile en cas de contestation.'],
        'cotisation-adherent-association-gerer' => ['Pour aller plus vite, la ', 'gestion des cotisations d\'association', '/logiciel-cotisation-association', ' peut se faire dans un outil qui relie chaque règlement à la fiche du membre.'],
        'augmentation-cotisation-association-7-strategies-pour-preserver-vos-adherents' => ['Une fois le nouveau tarif voté, il suffit de le reporter dans votre ', 'campagne de cotisation', '/logiciel-cotisation-association', ', avec un tarif par catégorie de membre si besoin.'],
        'bureau-association-composition-role' => ['Pour répartir les accès entre président, trésorier et secrétaire, un ', 'logiciel de gestion d\'association loi 1901', '/logiciel-association', ' donne à chacun les droits adaptés à son rôle.'],
        'calendrier-comptable-association-2026-12-echeances-a-ne-jamais-manquer' => ['Les échéances comptables de l\'année peuvent aussi être suivies dans un ', 'logiciel de comptabilité pour association', '/logiciel-comptabilite-association', ', avec l\'agenda partagé du bureau.'],
        'echeancier-comptable-association-2026-le-calendrier-mensuel-pour-ne-rien-oublier' => ['Toutes les dates de l\'année sont réunies dans notre ', 'agenda comptable de l\'association', '/blog/calendrier-comptable-association-2026-12-echeances-a-ne-jamais-manquer', '.'],
        'indemnites-kilometriques-en-association-bareme-optimal-2026-et-maximisation' => ['Les indemnités kilométriques peuvent être remboursées par les ', 'notes de frais de l\'association', '/logiciel-comptabilite-association#notes-de-frais', ', avec le justificatif rattaché à chaque dépense.'],
        'note-frais-benevole-regles-modele' => ['Pour ne plus perdre de justificatifs, les ', 'notes de frais des bénévoles', '/logiciel-comptabilite-association#notes-de-frais', ' peuvent être saisies et suivies dans le logiciel de l\'association.'],
      ];
      if (isset($AK_CONTEXT_LINKS[$article['slug']])) {
          [$ak_b, $ak_a, $ak_u, $ak_e] = $AK_CONTEXT_LINKS[$article['slug']];
          $ak_ctx = '<p class="bm-p">' . htmlspecialchars($ak_b) . '<a href="' . $ak_u . '" class="bm-link">' . htmlspecialchars($ak_a) . '</a>' . htmlspecialchars($ak_e) . '</p>';
          // Fin de la 1re section : juste avant le 2e titre H2 (sinon après le 1er paragraphe de la section).
          $ak_h2  = strpos($ak_html, '</h2>');
          $ak_pos = $ak_h2 !== false ? strpos($ak_html, '<h2', $ak_h2) : false;
          if ($ak_pos === false) {
              $ak_p   = $ak_h2 !== false ? strpos($ak_html, '</p>', $ak_h2) : strpos($ak_html, '</p>');
              $ak_pos = $ak_p !== false ? $ak_p + 4 : false;
          }
          if ($ak_pos !== false) $ak_html = substr($ak_html, 0, $ak_pos) . $ak_ctx . substr($ak_html, $ak_pos);
          else $ak_html .= $ak_ctx;
      }

      $ak_html = $ak_inject($ak_html, $ak_cards);
      ?>
      <style>
      .bm-cta{position:relative;margin:40px 0;border-radius:22px;padding:28px 30px;background:linear-gradient(180deg,#FFFFFF 0%,#F5FBF8 100%);border:1px solid #E5F1EB;overflow:hidden;box-shadow:0 1px 0 rgba(255,255,255,.9) inset,0 18px 44px rgba(6,78,59,.09)}
      .bm-cta::after{content:"";position:absolute;top:-50px;right:-40px;width:200px;height:200px;border-radius:50%;background:radial-gradient(circle,rgba(16,203,143,.16),transparent 70%);pointer-events:none}
      .bm-cta-eb{display:inline-flex;align-items:center;gap:8px;font-size:11.5px;font-weight:800;letter-spacing:.1em;text-transform:uppercase;color:#059669;margin-bottom:13px}
      .bm-cta-dot{width:7px;height:7px;border-radius:50%;background:linear-gradient(135deg,#0CCB8F,#059669);box-shadow:0 0 0 4px rgba(5,150,105,.14)}
      .bm-cta-t{font-size:20px !important;font-weight:800 !important;color:#0F172A !important;line-height:1.28 !important;margin:0 0 9px !important;letter-spacing:-.01em}
      .bm-cta p{font-size:15px !important;color:#475569 !important;line-height:1.62 !important;margin:0 0 20px !important;max-width:60ch}
      .bm-cta-btns{display:flex;align-items:center;gap:18px;flex-wrap:wrap;position:relative}
      .bm-cta-btn{display:inline-flex;align-items:center;gap:8px;text-decoration:none;font-weight:700;transition:transform .18s cubic-bezier(.2,.8,.2,1),box-shadow .18s,color .18s}
      .bm-cta-btn svg{transition:transform .18s cubic-bezier(.2,.8,.2,1)}
      .bm-cta-btn.primary{padding:13px 24px;border-radius:13px;font-size:14.5px;color:#fff;background:linear-gradient(135deg,#0CCB8F,#059669);box-shadow:0 12px 24px rgba(5,150,105,.30)}
      .bm-cta-btn.primary:hover{transform:translateY(-2px);box-shadow:0 18px 34px rgba(5,150,105,.42)}
      .bm-cta-btn.primary:hover svg{transform:translateX(4px)}
      .bm-cta-btn.link{font-size:14.5px;color:#059669}
      .bm-cta-btn.link:hover{color:#047857}
      .bm-cta-btn.link:hover svg{transform:translateX(4px)}
      </style>
      <?= $ak_html ?>

      <?php if (!empty($tags)): ?>
        <hr style="border:none;border-top:1px solid var(--c-border);margin:36px 0 20px;">
        <div style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;">
          <span style="font-size:13px;color:var(--c-text-3);">🏷 Tags :</span>
          <?php foreach ($tags as $tag): ?>
            <span style="display:inline-flex;align-items:center;padding:4px 10px;background:var(--c-creme-2);border-radius:var(--radius-pill);font-size:12px;color:var(--c-text-2);"><?= pub_h($tag) ?></span>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <?php
      // Maillage interne montant : article → pages produit (money pages) selon le thème détecté ($ak_theme)
      $AK_SOLUTIONS = [
        'facturation'   => [['/logiciel-facturation', 'Logiciel de facturation pour association et TPE'],
                            $ak_is_tpe ? ['/logiciel-gestion-tpe', 'Logiciel de gestion pour TPE'] : ['/logiciel-comptabilite-association', 'Logiciel de comptabilité pour association']],
        'cotisation'    => [['/logiciel-cotisation-association', 'Gestion des cotisations d\'association'],
                            ['/logiciel-adherents', 'Logiciel de gestion des adhérents']],
        'adherents'     => [['/logiciel-adherents', 'Logiciel de gestion des adhérents'],
                            ['/logiciel-cotisation-association', 'Gestion des cotisations d\'association'],
                            ['/logiciel-association', 'Logiciel de gestion d\'association loi 1901']],
        'compta'        => [['/logiciel-comptabilite-association', 'Logiciel de comptabilité pour association'],
                            ['/comptabilite-analytique', 'La comptabilité analytique par projet']],
        'asso'          => [['/logiciel-association', 'Logiciel de gestion d\'association loi 1901'],
                            ['/logiciel-cotisation-association', 'Gérer les cotisations de l\'association'],
                            ['/logiciel-adherents', 'Gérer et fidéliser vos adhérents']],
        'juridique'     => [['/logiciel-association', 'Registres, statuts et AG sans paperasse'],
                            ['/logiciel-adherents', 'Logiciel de gestion des adhérents']],
        'communication' => [['/logiciel-adherents', 'Communiquer avec vos adhérents'],
                            ['/fonctionnalites', 'Les outils de communication intégrés']],
        'tpe'           => [['/logiciel-gestion-tpe', 'Logiciel de gestion pour TPE'],
                            ['/logiciel-facturation', 'Devis & factures pro en 2 minutes']],
      ];
      $ak_sol = $AK_SOLUTIONS[$ak_theme ?? 'asso'] ?? $AK_SOLUTIONS['asso'];
      // Modèles et guides gratuits qui répondent à la même question que l'article (en tête de liste)
      $ak_t = mb_strtolower((string) $article['title']);
      foreach ([
        '/statuts/u'                                  => ['/modele-statuts-association', 'Modèle de statuts d\'association loi 1901'],
        '/assembl[ée]e|\bag\b|convocation/u'          => ['/modele-convocation-assemblee-generale', 'Modèle de convocation à l\'assemblée générale'],
        '/proc[èe]s-verbal|\bpv\b|compte[- ]rendu/u'   => ['/modele-proces-verbal-assemblee-generale', 'Modèle de procès-verbal d\'AG'],
        '/re[çc]u fiscal|\bdons?\b|m[ée]c[ée]nat/u'     => ['/modele-recu-fiscal-association', 'Modèle de reçu fiscal pour association'],
        '/plan comptable|bilan|cl[ôo]ture|comptabilit[ée] d/u' => ['/guide-comptabilite-association', 'Guide de la comptabilité d\'association'],
        '/loi 1901|cr[ée]er une association|bureau/u' => ['/guide-association-loi-1901', 'Guide de l\'association loi 1901'],
      ] as $ak_rx => $ak_lnk) {
          if (preg_match($ak_rx, $ak_t)) array_unshift($ak_sol, $ak_lnk);
      }
      $ak_sol = array_slice(array_values(array_unique($ak_sol, SORT_REGULAR)), 0, 4);
      ?>
      <div style="border:1px solid var(--c-border);border-radius:var(--radius-lg);padding:22px 24px;margin-top:32px;background:var(--c-creme-2);">
        <h3 style="margin:0 0 12px;font-size:16px;color:var(--c-encre);">🧩 Les outils Assokit sur ce sujet</h3>
        <ul style="margin:0;padding-left:18px;line-height:1.9;">
          <?php foreach ($ak_sol as $s): ?>
            <li><a href="<?= $s[0] ?>" style="color:var(--c-emeraude-dark);font-weight:600;"><?= pub_h($s[1]) ?></a></li>
          <?php endforeach; ?>
        </ul>
      </div>

      <?php
      // CTA d'audience (persona) — complémentaire du bloc "Solutions" ci-dessus
      $blog_is_tpe = (stripos((string)$article['category'], 'tpe') !== false)
                  || (stripos((string)$article['category'], 'entreprise') !== false)
                  || (stripos((string)$article['title'], 'TPE') !== false)
                  || (stripos((string)$article['title'], 'auto-entrepreneur') !== false);
      $blog_land   = $blog_is_tpe ? '/pour-tpe' : '/pour-associations';
      $blog_aud    = $blog_is_tpe ? 'votre entreprise' : 'votre association';
      $blog_aud2   = $blog_is_tpe ? 'TPE, PME et indépendants' : 'associations loi 1901';
      ?>
      <!-- Bloc CTA in-article (maillage interne contextuel) -->
      <div style="background:linear-gradient(135deg,var(--c-encre),var(--c-emeraude-dark));border-radius:var(--radius-lg);padding:32px;margin-top:40px;color:white;text-align:center;">
        <h3 style="margin:0 0 10px;color:white;font-size:21px;">Gérez <?= pub_h($blog_aud) ?> sans paperasse</h3>
        <p style="color:rgba(255,255,255,0.85);margin:0 0 22px;font-size:15px;line-height:1.6;">Assokit réunit adhérents, cotisations, facturation, <a href="/comptabilite-analytique" style="color:#FCD34D;font-weight:600;">comptabilité analytique</a>, projets et communication — l'outil tout-en-un pensé pour les <?= pub_h($blog_aud2) ?>.</p>
        <div style="display:flex;gap:12px;flex-wrap:wrap;justify-content:center;">
          <a href="<?= $blog_land ?>" style="display:inline-block;background:var(--c-ambre);color:var(--c-encre);padding:12px 24px;border-radius:var(--radius-md);text-decoration:none;font-weight:700;">Découvrir Assokit pour <?= $blog_is_tpe ? 'les TPE' : 'les assos' ?> →</a>
          <a href="/contact" style="display:inline-block;background:rgba(255,255,255,0.14);color:#fff;border:1px solid rgba(255,255,255,0.3);padding:12px 24px;border-radius:var(--radius-md);text-decoration:none;font-weight:600;">Réserver une démo</a>
        </div>
        <div style="margin-top:18px;font-size:13px;">
          <a href="/fonctionnalites" style="color:rgba(255,255,255,0.75);margin:0 8px;">Fonctionnalités</a>·
          <a href="/tarifs" style="color:rgba(255,255,255,0.75);margin:0 8px;">Tarifs</a>·
          <a href="<?= $blog_land ?>" style="color:rgba(255,255,255,0.75);margin:0 8px;"><?= $blog_is_tpe ? 'Pour les TPE/PME' : 'Pour les associations' ?></a>
        </div>
      </div>
    </article>

    <!-- ARTICLES LIÉS -->
    <?php if (!empty($related)): ?>
      <section style="margin-top:60px;">
        <h2 style="font-size:24px;color:var(--c-encre);margin-bottom:24px;">📚 À lire aussi</h2>
        <div class="pub-article-list">
          <?php foreach ($related as $r):
            $rcat = blog_category((string)$r['category']);
          ?>
            <article class="pub-article-card">
              <a href="/blog/<?= pub_h($r['slug']) ?>">
                <div class="pub-article-cover" style="background:linear-gradient(135deg, <?= pub_h($r['cover_color_from'] ?: '#059669') ?>, <?= pub_h($r['cover_color_to'] ?: '#0F172A') ?>);">
                  <?= pub_h($r['cover_emoji'] ?: $rcat['icon']) ?>
                </div>
                <div class="pub-article-body">
                  <div class="pub-article-cat"><?= $rcat['icon'] ?> <?= pub_h($rcat['label']) ?></div>
                  <h3 class="pub-article-title"><?= pub_h($r['title']) ?></h3>
                  <p class="pub-article-excerpt"><?= pub_h($r['excerpt'] ?: '') ?></p>
                  <div class="pub-article-meta">
                    <span>⏱ <?= (int)$r['reading_time_min'] ?> min</span>
                  </div>
                </div>
              </a>
            </article>
          <?php endforeach; ?>
        </div>
      </section>
    <?php endif; ?>
  </div>
</section>

<?php
render_public_footer();
render_public_foot();
