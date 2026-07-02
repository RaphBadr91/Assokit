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
        'description' => 'Assokit, le logiciel français pour gérer votre association loi 1901 ou TPE : adhérents, projets, factures, communication par email et SMS, IA intégrée. Gratuit pour démarrer.',
        'keywords' => 'logiciel association loi 1901, gestion association, logiciel adhérents, gestion TPE, facturation association, communication adhérents, association française',
        'canonical' => '/',
        'og_image' => 'https://assokit.fr/assets/og-home.jpg',
        'page_type' => 'website',
    ],

    // ============================================================
    // PAGE PLANS / TARIFS
    // ============================================================
    'plans' => [
        'title' => 'Tarifs Assokit — Démarrage gratuit, Assokit 49,99€, Sur-mesure',
        'description' => 'Découvrez nos plans : Démarrage 100% gratuit, plan Assokit à 49,99€/mois tout inclus, ou Sur-mesure avec domaine perso. Pas d\'engagement, sans frais cachés.',
        'keywords' => 'tarif logiciel association, prix gestion asso, abonnement Assokit, plan gratuit association, sans engagement',
        'canonical' => '/plans',
        'og_image' => 'https://assokit.fr/assets/og-plans.jpg',
        'page_type' => 'product',
    ],

    // ============================================================
    // PAGE INSCRIPTION
    // ============================================================
    'inscription' => [
        'title' => 'Inscription gratuite — Créez votre compte Assokit',
        'description' => 'Créez votre compte Assokit gratuitement en 30 secondes. Démarrez avec le plan gratuit, sans CB, sans engagement. Idéal pour les associations loi 1901 et TPE.',
        'keywords' => 'inscription Assokit, créer compte association, logiciel gratuit asso, démarrer gratuitement',
        'canonical' => '/inscription',
        'og_image' => 'https://assokit.fr/assets/og-inscription.jpg',
        'page_type' => 'website',
    ],

    // ============================================================
    // PAGE LOGIN
    // ============================================================
    'login' => [
        'title' => 'Connexion — Assokit',
        'description' => 'Connectez-vous à votre espace Assokit pour gérer votre association ou TPE.',
        'canonical' => '/login',
        'og_image' => 'https://assokit.fr/assets/og-default.jpg',
        'page_type' => 'website',
        'noindex' => true, // pages de login pas indexées
    ],

    // ============================================================
    // PAGE FONCTIONNALITÉS
    // ============================================================
    'fonctionnalites' => [
        'title' => 'Fonctionnalités — Tout ce que fait Assokit',
        'description' => 'Adhérents, projets collaboratifs, factures, devis, paiements en ligne, communication email/SMS, IA intégrée, statistiques, white-label : Assokit fait tout.',
        'keywords' => 'fonctionnalités Assokit, gestion adhérents, facturation, communication, IA association',
        'canonical' => '/fonctionnalites',
        'og_image' => 'https://assokit.fr/assets/og-fonctionnalites.jpg',
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
        'og_image' => 'https://assokit.fr/assets/og-asso.jpg',
        'page_type' => 'website',
    ],

    // ============================================================
    // POUR TPE
    // ============================================================
    'pour-tpe' => [
        'title' => 'Logiciel TPE — Facturation et gestion clients simple',
        'description' => 'Assokit pour les TPE : facturation conforme, devis, suivi clients, paiements en ligne via Stripe, statistiques. Tout-en-un, sans complexité.',
        'keywords' => 'logiciel TPE, facturation TPE, gestion clients, devis facture, paiement Stripe',
        'canonical' => '/pour-tpe',
        'og_image' => 'https://assokit.fr/assets/og-tpe.jpg',
        'page_type' => 'website',
    ],

    // ============================================================
    // CONTACT
    // ============================================================
    'contact' => [
        'title' => 'Contact — Une question ? On vous répond',
        'description' => 'Une question sur Assokit ? Notre équipe vous répond rapidement par email. Support gratuit pour tous les utilisateurs.',
        'canonical' => '/contact',
        'og_image' => 'https://assokit.fr/assets/og-default.jpg',
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
        'canonical' => '/cgv',
        'page_type' => 'website',
    ],
    'politique-confidentialite' => [
        'title' => 'Politique de confidentialité — Assokit',
        'description' => 'Politique de confidentialité d\'Assokit. Comment nous protégeons vos données personnelles, conformément au RGPD.',
        'canonical' => '/politique-confidentialite',
        'page_type' => 'website',
    ],
    'rgpd' => [
        'title' => 'RGPD — Vos droits sur Assokit',
        'description' => 'Informations RGPD. Exercez vos droits d\'accès, rectification, suppression de vos données personnelles.',
        'canonical' => '/rgpd',
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
            'q' => 'Assokit est-il vraiment gratuit ?',
            'a' => 'Oui, le plan Démarrage est gratuit à vie, sans carte bancaire requise. Vous pouvez gérer votre association ou TPE avec les fonctionnalités essentielles. Si vos besoins grandissent, vous pouvez passer au plan Assokit à 49,99€/mois.',
        ],
        [
            'q' => 'Pour qui est conçu Assokit ?',
            'a' => 'Assokit est conçu pour les associations loi 1901, les TPE et les indépendants français qui veulent un outil simple et complet pour gérer adhérents, projets, factures et communication.',
        ],
        [
            'q' => 'Mes données sont-elles en sécurité ?',
            'a' => 'Oui. Vos données sont hébergées en France (O2Switch), protégées par chiffrement et conformes au RGPD. Vous restez propriétaire de toutes vos informations.',
        ],
        [
            'q' => 'Puis-je avoir mon propre nom de domaine ?',
            'a' => 'Oui, avec le plan Assokit (sous-domaine adherents.tonasso.fr inclus, ou domaine personnalisé +10€/mois) ou le plan Sur-mesure (domaine inclus + emails à votre nom).',
        ],
        [
            'q' => 'Comment fonctionne l\'IA d\'Assokit ?',
            'a' => 'Assokit IA vous aide à rédiger vos communications aux adhérents, à analyser vos données et à automatiser vos tâches récurrentes. Disponible dans tous les plans.',
        ],
    ],

    'plans' => [
        [
            'q' => 'Quelle est la différence entre les plans Démarrage, Assokit et Sur-mesure ?',
            'a' => 'Démarrage est gratuit avec les fonctions essentielles. Assokit (49,99€/mois) inclut tout : sous-domaine, IA illimitée, factures avancées, support prioritaire. Sur-mesure ajoute votre domaine personnalisé + emails à votre nom + white-label total.',
        ],
        [
            'q' => 'Y a-t-il un engagement ?',
            'a' => 'Non, aucun engagement. Vous pouvez annuler à tout moment depuis votre tableau de bord. Vous gardez l\'accès jusqu\'à la fin de la période payée.',
        ],
        [
            'q' => 'Comment se passe le paiement ?',
            'a' => 'Paiement sécurisé par Stripe : carte bancaire, Apple Pay, Google Pay, SEPA, Klarna. Renouvellement automatique mensuel. Annulation possible à tout moment.',
        ],
        [
            'q' => 'Puis-je passer du plan gratuit à Assokit plus tard ?',
            'a' => 'Oui, vous pouvez upgrader à tout moment depuis votre tableau de bord. Vos données sont conservées et le passage est instantané.',
        ],
        [
            'q' => 'Y a-t-il des frais cachés ?',
            'a' => 'Aucun frais caché. Le prix affiché est le prix final. Pas de frais d\'installation, pas de frais de résiliation, pas de surprise.',
        ],
    ],

    'pour-associations' => [
        [
            'q' => 'Assokit est-il adapté aux associations loi 1901 ?',
            'a' => 'Oui, Assokit est conçu spécifiquement pour les associations françaises loi 1901. Toutes les fonctions essentielles sont incluses : gestion des adhérents, suivi des cotisations, organisation des AG, gestion des projets et événements.',
        ],
        [
            'q' => 'Puis-je gérer plusieurs associations avec un seul compte ?',
            'a' => 'Une organisation par compte. Si vous gérez plusieurs associations, créez un compte par structure. Le tarif Sur-mesure offre des conditions préférentielles pour les fédérations.',
        ],
        [
            'q' => 'Comment se passe la migration depuis un autre logiciel ?',
            'a' => 'Notre équipe vous accompagne gratuitement pour importer vos adhérents et données depuis Excel, Google Sheets ou un autre logiciel. Contactez-nous via /contact.',
        ],
    ],

    'pour-tpe' => [
        [
            'q' => 'Est-ce conforme à la facturation française 2026 ?',
            'a' => 'Oui, Assokit génère des factures 100% conformes aux exigences légales françaises : numérotation continue, mentions obligatoires, archivage 10 ans, format conforme à la facturation électronique.',
        ],
        [
            'q' => 'Comment se passent les paiements en ligne ?',
            'a' => 'Vos clients paient directement vos factures par CB, Apple Pay, Google Pay, SEPA ou Klarna via Stripe. Les fonds arrivent sur votre compte sous 2 jours ouvrés.',
        ],
        [
            'q' => 'Puis-je gérer la TVA ?',
            'a' => 'Oui, Assokit gère la TVA selon votre régime (franchise en base, réel simplifié, réel normal). Calculs automatiques HT/TTC sur factures et devis.',
        ],
    ],

];
