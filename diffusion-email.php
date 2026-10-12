<?php
/**
 * diffusion-email.php — ancienne page « Diffusion email » (v2)
 * --------------------------------------------------------------
 * Remplacée par l'onglet « Diffuser » du module Communication.
 * Cette page lisait une table que rien n'alimente (asso_email_diffusions),
 * son bouton « + Nouvelle diffusion » menait à une page inexistante
 * (/nouvelle-diffusion-email) et elle affichait des chiffres d'exemple.
 * L'adresse reste active (anciens liens, favoris) : elle mène à la
 * diffusion réelle, qui applique ses propres droits d'accès.
 * --------------------------------------------------------------
 */
header('Location: /communication?tab=diffuser', true, 302);
exit;
