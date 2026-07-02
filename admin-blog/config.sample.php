<?php
/**
 * ================================================================
 * CONFIGURATION ADMIN BLOG ASSOKIT
 * ================================================================
 * Renomme ce fichier en `config.php` et adapte les valeurs.
 * ⚠️  NE JAMAIS COMMITTER `config.php` SUR GITHUB
 * ================================================================
 */

// --- Connexion BDD (adapte avec tes credentials O2switch) ---
define('DB_HOST', 'localhost');
define('DB_NAME', 'pura7044_assokit');
define('DB_USER', 'pura7044_xxxxx');     // ⚠️ À COMPLÉTER
define('DB_PASS', 'xxxxxxxxxxxxxxx');    // ⚠️ À COMPLÉTER
define('DB_CHARSET', 'utf8mb4');

// --- URL publique du site (pour les liens dans les articles générés) ---
define('SITE_URL', 'https://assokit.fr');

// --- Sécurité session ---
define('SESSION_NAME', 'assokit_admin');
define('SESSION_LIFETIME', 7200); // 2 heures
define('SESSION_REGENERATE_INTERVAL', 600); // toutes les 10 min

// --- Mode debug (mettre à false en production) ---
define('DEBUG_MODE', false);

// --- Limites de génération IA (sécurité facture) ---
define('MAX_ARTICLES_PER_HOUR', 10);   // anti-fugue
define('MAX_ARTICLES_PER_DAY', 50);    // plafond strict

// --- Initialisation ---
date_default_timezone_set('Europe/Paris');
mb_internal_encoding('UTF-8');

// --- Affichage erreurs en mode debug uniquement ---
if (DEBUG_MODE) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(E_ALL & ~E_DEPRECATED & ~E_STRICT & ~E_NOTICE);
    ini_set('display_errors', '0');
}
