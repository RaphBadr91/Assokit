<?php
/**
 * admin-create-organization.php — ancienne page de création de compte client.
 * Remplacée par fondateur-create-organization.php (formules de la grille, offre fondateur avec sa
 * garantie de 12 mois, devis Sur-mesure) : redirection permanente, comme super-admin-nouvelle-asso.php.
 * La page de destination fait elle-même les contrôles d'accès (Fondateur ou Super Admin).
 */
header('Location: /fondateur-create-organization', true, 301);
exit;
