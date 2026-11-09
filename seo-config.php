<?php
/**
 * ============================================================
 * ASSOKIT — seo-config.php
 * ============================================================
 * Configuration SEO centralisée par page.
 *
 * Usage :
 *   require_once __DIR__ . '/seo-config.php';
 *   $seo = AK_SEO_PAGES['accueil'];
 *   ak_seo_render($seo);
 * ============================================================
 */

const AK_SEO_PAGES = [

    // ============================================================
    // PAGE ACCUEIL
    // ============================================================
    'accueil' => [
        'title' => 'Logiciel association loi 1901 & TPE — Gestion simple',
        'description' => 'Assokit, le logiciel français pour gérer votre association loi 1901 ou TPE : adhérents, projets, factures, communication par email, IA intégrée. Essai gratuit 14 jours, sans carte bancaire.',
        'keywords' => 'logiciel association loi 1901, gestion association, logiciel adhérents, gestion TPE, facturation association, communication adhérents, association française',
        'canonical' => '/',
        'og_image' => 'https://assokit.fr/assets/og-assokit-home.png',
        'page_type' => 'website',
    ],

    // ============================================================
    // PAGE PLANS / TARIFS
    // ============================================================
    'plans' => [
        'title' => 'Tarifs Assokit — Essentiel, Pro, Équipe, Sur-mesure, essai gratuit 14 jours',
        'description' => 'Essai Pro gratuit 14 jours sans carte bancaire, puis Essentiel, Pro ou Équipe dès 19,99 € HT/mois, sans engagement, ou Sur-mesure sur devis.',
        'keywords' => 'tarif logiciel association, prix gestion asso, abonnement Assokit, essai gratuit logiciel association, sans engagement',
        'canonical' => '/tarifs',
        'og_image' => 'https://assokit.fr/assets/og-assokit-home.png',
        'page_type' => 'product',
    ],

    // ============================================================
    // PAGE INSCRIPTION
    // ============================================================
    'inscription' => [
        'title' => 'Essai gratuit 14 jours — Créez votre espace Assokit',
        'description' => 'Créez votre espace Assokit et testez toutes les fonctions de la formule Pro pendant 14 jours, sans carte bancaire, sans engagement. Idéal pour les associations loi 1901 et TPE.',
        'keywords' => 'inscription Assokit, créer compte association, essai gratuit logiciel association, démarrer gratuitement',
        'canonical' => '/signup',
        'og_image' => 'https://assokit.fr/assets/og-assokit-home.png',
        'page_type' => 'website',
    ],

    // ============================================================
    // PAGE LOGIN
    // ============================================================
    'login' => [
        'title' => 'Connexion — Assokit',
        'description' => 'Connectez-vous à votre espace Assokit pour gérer votre association ou TPE.',
        'canonical' => '/connexion',
        'og_image' => 'https://assokit.fr/assets/og-assokit-home.png',
        'page_type' => 'website',
        'noindex' => true, // pages de login pas indexées
    ],

    // ============================================================
    // PAGE FONCTIONNALITÉS
    // ============================================================
    'fonctionnalites' => [
        'title' => 'Fonctionnalités — Tout ce que fait Assokit',
        'description' => 'Adhérents, projets collaboratifs, factures et devis, paiements en ligne, communication par e-mail, IA intégrée, statistiques : tout ce que fait Assokit, formule par formule.',
        'keywords' => 'fonctionnalités Assokit, gestion adhérents, facturation, communication, IA association',
        'canonical' => '/fonctionnalites',
        'og_image' => 'https://assokit.fr/assets/og-assokit-home.png',
        'page_type' => 'website',
    ],

    // ============================================================
    // POUR ASSOCIATIONS
    // ============================================================
    'pour-associations' => [
        'title' => 'Logiciel pour associations loi 1901 — Assokit',
        'description' => 'Assokit accompagne les associations loi 1901 dans leur quotidien : gestion adhérents, projets, AG, cotisations, communication, comptabilité. Conçu en France.',
        'keywords' => 'logiciel association loi 1901, gestion association, asso française, cotisations, AG association',
        'canonical' => '/pour-associations',
        'og_image' => 'https://assokit.fr/assets/og-assokit-home.png',
        'page_type' => 'website',
    ],

    // ============================================================
    // POUR TPE
    // ============================================================
    'pour-tpe' => [
        'title' => 'Logiciel TPE — Facturation et gestion clients simple',
        'description' => 'Assokit pour les TPE : devis et factures au format Factur-X, suivi clients, paiements en ligne via Stripe, statistiques. Tout-en-un, sans complexité.',
        'keywords' => 'logiciel TPE, facturation TPE, gestion clients, devis facture, paiement Stripe',
        'canonical' => '/pour-tpe',
        'og_image' => 'https://assokit.fr/assets/og-assokit-home.png',
        'page_type' => 'website',
    ],

    // ============================================================
    // CONTACT
    // ============================================================
    'contact' => [
        'title' => 'Contact — Une question ? On vous répond',
        'description' => 'Une question sur Assokit ? Notre équipe vous répond rapidement par email. Support gratuit pour tous les utilisateurs.',
        'canonical' => '/contact',
        'og_image' => 'https://assokit.fr/assets/og-assokit-home.png',
        'page_type' => 'website',
    ],

    // ============================================================
    // PAGES LÉGALES (déjà existantes)
    // ============================================================
    'mentions-legales' => [
        'title' => 'Mentions légales — Assokit',
        'description' => 'Mentions légales d\'Assokit, édité par RBPS basée à Évry, France.',
        'canonical' => '/mentions-legales',
        'page_type' => 'website',
        'noindex' => false, // Google aime les mentions légales pour le E-A-T
    ],
    'cgu' => [
        'title' => 'Conditions Générales d\'Utilisation — Assokit',
        'description' => 'CGU d\'Assokit. Conditions d\'utilisation du service de gestion d\'associations et TPE.',
        'canonical' => '/cgu',
        'page_type' => 'website',
    ],
    'cgv' => [
        'title' => 'Conditions Générales de Vente — Assokit',
        'description' => 'CGV d\'Assokit. Conditions de vente des plans payants Assokit et Sur-mesure.',
        'canonical' => '/cgu',
        'page_type' => 'website',
    ],
    'politique-confidentialite' => [
        'title' => 'Politique de confidentialité — Assokit',
        'description' => 'Politique de confidentialité d\'Assokit. Comment nous protégeons vos données personnelles, conformément au RGPD.',
        'canonical' => '/confidentialite',
        'page_type' => 'website',
    ],
    'rgpd' => [
        'title' => 'RGPD — Vos droits sur Assokit',
        'description' => 'Informations RGPD. Exercez vos droits d\'accès, rectification, suppression de vos données personnelles.',
        'canonical' => '/confidentialite',
        'page_type' => 'website',
    ],
    'cookies' => [
        'title' => 'Politique de cookies — Assokit',
        'description' => 'Politique de cookies d\'Assokit. Liste des cookies utilisés et comment les gérer.',
        'canonical' => '/cookies',
        'page_type' => 'website',
    ],
];

// ============================================================
// FAQS PAR PAGE (pour FAQPage Schema.org)
// ============================================================
const AK_SEO_FAQS = [

    'accueil' => [
        [
            'q' => 'Peut-on essayer Assokit gratuitement ?',
            'a' => 'Oui : 14 jours d’essai Pro gratuits, sans carte bancaire, avec toutes les fonctions de la formule Pro. Ensuite, vous choisissez votre formule (Essentiel, Pro ou Équipe), sans engagement, aux prix indiqués sur la page Tarifs. Si vous ne choisissez rien, rien n’est prélevé.',
        ],
        [
            'q' => 'Pour qui est conçu Assokit ?',
            'a' => 'Assokit est conçu pour les associations loi 1901, les TPE et les indépendants français qui veulent un outil simple et complet pour gérer adhérents, projets, factures et communication.',
        ],
        [
            'q' => 'Mes données sont-elles en sécurité ?',
            'a' => 'Oui. Vos données sont hébergées en France (O2Switch), protégées par chiffrement, dans le respect du RGPD. Vous restez propriétaire de toutes vos informations.',
        ],
        [
            'q' => 'Quelles formules propose Assokit ?',
            'a' => 'Essentiel pour gérer à la main une petite structure, Pro pour les relances automatiques, les factures récurrentes et le copilote IA, Équipe quand plusieurs personnes gèrent chaque jour ou pour une petite fédération réunie dans un seul espace, et Sur-mesure sur devis pour les grandes fédérations, les ligues, les têtes de réseau et les collectivités territoriales qui suivent plusieurs structures. Prix et détails sur la page Tarifs.',
        ],
        [
            'q' => 'Comment fonctionne l\'IA d\'Assokit ?',
            'a' => 'L’IA d’Assokit rédige vos courriers et vos communications dans toutes les formules, dans la limite de vos crédits IA du mois. Dès la formule Pro, le copilote IA propose vos priorités du jour et répond à vos questions sur votre structure. Elle propose, vous vérifiez.',
        ],
    ],

    'plans' => [
        [
            'q' => 'Quelle est la différence entre Essentiel, Pro, Équipe et Sur-mesure ?',
            'a' => 'Toutes les formules comprennent adhérents, cotisations, factures et devis, comptabilité, assemblées générales et application mobile. Essentiel convient à une petite structure gérée à la main ; Pro ajoute les relances automatiques, les factures récurrentes, les devis signés en ligne et le copilote IA ; Équipe ajoute les priorités du jour pour toute l’équipe, le planning et la détection d’anomalies. Équipe convient aussi aux petites fédérations qui réunissent leurs membres dans un seul espace. Sur-mesure, sur devis, va au-delà des volumes d’Équipe et fixe les volumes pour les grandes fédérations, les ligues, les têtes de réseau et les collectivités territoriales qui suivent plusieurs structures. L’essai Pro est gratuit pendant 14 jours, sans carte bancaire.',
        ],
        [
            'q' => 'Y a-t-il un engagement ?',
            'a' => 'Non, aucun engagement. Vous pouvez annuler à tout moment depuis votre tableau de bord. Vous gardez l\'accès jusqu\'à la fin de la période payée.',
        ],
        [
            'q' => 'Comment se passe le paiement ?',
            'a' => 'Abonnement mensuel, renouvelé chaque mois, résiliable à tout moment. Le mode de paiement (carte, prélèvement SEPA ou virement sur facture) est indiqué sur la page de paiement. Les prix sont affichés HT ; la note de TVA applicable figure sur la page Tarifs.',
        ],
        [
            'q' => 'Puis-je m’abonner pendant l’essai ?',
            'a' => 'Oui, depuis votre espace (Mon abonnement). Vous gardez vos jours d’essai et vos données.',
        ],
        [
            'q' => 'Y a-t-il des frais cachés ?',
            'a' => 'Non : les prix sont affichés HT et le montant à payer est indiqué avant tout paiement. Pas de frais d\'installation, pas de frais de résiliation. Toute hausse de prix est annoncée 30 jours avant.',
        ],
    ],

    'pour-associations' => [
        [
            'q' => 'Assokit est-il adapté aux associations loi 1901 ?',
            'a' => 'Oui, Assokit est conçu spécifiquement pour les associations françaises loi 1901. Toutes les fonctions essentielles sont incluses : gestion des adhérents, suivi des cotisations, organisation des AG, gestion des projets et événements.',
        ],
        [
            'q' => 'Puis-je gérer plusieurs associations avec un seul compte ?',
            'a' => 'Une organisation par compte. Si vous gérez plusieurs associations, créez un compte par structure. Pour une grande fédération, une ligue ou une tête de réseau qui suit plusieurs structures, chacune avec son propre compte, l’offre Sur-mesure est établie sur devis.',
        ],
        [
            'q' => 'Comment se passe la migration depuis un autre logiciel ?',
            'a' => 'Vous importez vos adhérents depuis un tableur (Excel, Google Sheets, CSV). Avec la formule Équipe, nous reprenons votre fichier d’adhérents pour vous. Une question ? Écrivez-nous via /contact.',
        ],
    ],

    'pour-tpe' => [
        [
            'q' => 'Et la facture électronique de 2026 ?',
            'a' => 'Assokit génère des factures avec numérotation continue, mentions obligatoires et archivage, au format Factur-X ; le raccordement à une plateforme agréée est en préparation.',
        ],
        [
            'q' => 'Comment se passent les paiements en ligne ?',
            'a' => 'Une fois votre compte Stripe relié, vos clients paient vos factures en ligne (carte, Apple Pay, Google Pay ou prélèvement SEPA), sans commission Assokit (frais bancaires Stripe en sus). Les fonds sont versés par Stripe sur votre compte bancaire.',
        ],
        [
            'q' => 'Puis-je gérer la TVA ?',
            'a' => 'Oui, Assokit gère la TVA selon votre régime (franchise en base, réel simplifié, réel normal). Calculs automatiques HT/TTC sur factures et devis.',
        ],
    ],

];
