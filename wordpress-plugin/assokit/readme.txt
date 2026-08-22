=== Assokit ===
Contributors: assokit
Tags: association, gestion, événements, adhérents
Requires at least: 5.5
Tested up to: 6.6
Stable tag: 1.1.0
License: GPLv2 or later

Intègre Assokit à un site WordPress d'association.

== Description ==
Affichez l'espace projets de votre association (lecture seule) et vos
événements/projets publics dans WordPress, avec un bouton de connexion
individuel vers Assokit.

Shortcodes :
* [assokit_projets token="…"] — espace projets en lecture seule (pour vos collaborateurs)
* [assokit_espace texte="Espace Assokit"] — bouton connexion (chacun sur son propre compte)
* [assokit_evenement token="…"] — événement public
* [assokit_projet token="…"] — projet public
* [assokit_bouton url="/tarifs" texte="Découvrir"] — bouton vers une page

Sécurité : le widget « espace projets » est en lecture seule (aucune session,
aucune donnée privée). Aucune connexion automatique ni usurpation d'identité :
pour agir dans Assokit, chaque collaborateur se connecte à son propre compte.

== Installation ==
1. Copier le dossier `assokit/` dans wp-content/plugins/ (ou installer le .zip).
2. Activer le plugin.
3. Réglages → Assokit : URL (https://assokit.fr) + jeton « Espace projets »
   (généré dans Assokit → Admin → Intégration WordPress).

== Changelog ==
= 1.1.0 =
* Nouveau : widget [assokit_projets] (espace projets en lecture seule).
* Sécurité : suppression du bouton SSO qui connectait tout utilisateur WordPress
  en tant qu'administrateur Assokit (usurpation d'identité).
= 1.0.0 =
* Version initiale : shortcodes événements/projets publics + boutons.
