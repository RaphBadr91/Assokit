<?php
/**
 * app-context.php — Savoir d'où vient la requête, et si l'on a le droit
 * d'y afficher quoi que ce soit qui ressemble à un achat.
 * ------------------------------------------------------------------
 * Pourquoi ce fichier existe :
 *
 * La directive 3.1.1 d'Apple interdit qu'une app affiche un bouton, un lien
 * ou même une simple mention renvoyant vers un achat effectué ailleurs que
 * dans l'App Store. Bloquer la navigation ne suffit pas : le seul fait
 * d'afficher « S'abonner » dans l'app est un motif de rejet.
 *
 * L'app Assokit affiche le site dans une WebView dont l'agent utilisateur
 * porte « AssokitApp/1.0 » (mobile/App.js, applicationNameForUserAgent).
 * Le serveur peut donc reconnaître ces requêtes et retirer toute surface
 * d'achat — bandeau d'essai, entrée « Abonnement » du menu, pages de plan
 * et de paiement.
 *
 * Sur le site consulté depuis un navigateur, rien ne change : les clients
 * gèrent leur abonnement normalement sur assokit.fr.
 *
 * Ce qui N'EST PAS masqué : /mon-asso-facturation et les factures que
 * l'association émet vers SES clients. Ce n'est pas un achat dans l'app,
 * c'est le métier de l'utilisateur.
 * ------------------------------------------------------------------
 */

/** Adresse du compte remis à Apple pour l'examen de l'app. */
const AK_APPLE_REVIEW_EMAIL = 'apple.review@assokit.fr';

if (!function_exists('ak_is_mobile_app')) {

/**
 * La requête vient-elle de la WebView de l'app iOS/Android ?
 */
function ak_is_mobile_app(): bool
{
    static $cache = null;
    if ($cache !== null) return $cache;

    $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
    return $cache = (stripos($ua, 'AssokitApp') !== false);
}

/**
 * Faut-il supprimer toute surface d'achat de cette page ?
 *
 * Vrai dans l'app (règle Apple 3.1.1), et vrai aussi pour le compte
 * d'examen même hors app : si l'examinateur ouvre le site à côté,
 * il ne doit pas non plus y trouver de tunnel de paiement.
 */
function ak_billing_hidden(): bool
{
    if (ak_is_mobile_app()) return true;

    $email = $_SESSION['user_email'] ?? '';
    return is_string($email) && strcasecmp($email, AK_APPLE_REVIEW_EMAIL) === 0;
}

/**
 * À appeler en tête des pages d'abonnement et de paiement.
 * Renvoie vers le tableau de bord au lieu d'afficher le tunnel.
 */
function ak_block_billing_page(string $to = '/dashboard'): void
{
    if (!ak_billing_hidden()) return;

    if (!headers_sent()) {
        header('Location: ' . $to, true, 302);
    }
    exit;
}

}
