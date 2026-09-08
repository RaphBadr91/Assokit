<?php
/**
 * cartes-contacts.php — Filet de secours des fiches QR.
 *
 * ── CE N'EST PLUS ICI QUE ÇA SE PASSE ───────────────────────────────────────
 *
 * Les cartes s'éditent dans le tableau de bord fondateur :
 *     /fondateur-cartes
 * et vivent en base, dans la table qr_cards.
 *
 * Ce fichier ne sert que si la base est injoignable ou si la migration
 * 2026-09-08-cartes-qr.sql n'a pas encore été lancée. Un QR imprimé ne se
 * rappelle pas : il vaut mieux servir des coordonnées un peu anciennes
 * qu'une page d'erreur.
 *
 * Ne changez PAS les clés '1', '2', '3' — ce sont les adresses gravées dans
 * les QR codes.
 */

return [

    '1' => [
        'prenom'    => 'Prénom',                 // ← à remplir
        'nom'       => 'NOM',                    // ← à remplir
        'fonction'  => 'Fondateur',              // intitulé de poste
        'societe'   => 'Assokit',
        'tel'       => '+33 6 00 00 00 00',      // ← à remplir, format international
        'tel_fixe'  => '',
        'email'     => 'prenom@assokit.fr',      // ← à remplir
        'site'      => 'https://assokit.fr',
        'adresse'   => [
            'rue'    => '',                      // ← à remplir
            'cp'     => '',
            'ville'  => '',
            'pays'   => 'France',
        ],
        'linkedin'  => '',                       // URL complète, ou vide
        'note'      => '',                       // apparaît dans la fiche du contact
    ],

    '2' => [
        'prenom'    => 'Prénom',
        'nom'       => 'NOM',
        'fonction'  => '',
        'societe'   => 'Assokit',
        'tel'       => '+33 6 00 00 00 00',
        'tel_fixe'  => '',
        'email'     => 'prenom@assokit.fr',
        'site'      => 'https://assokit.fr',
        'adresse'   => [
            'rue'    => '',
            'cp'     => '',
            'ville'  => '',
            'pays'   => 'France',
        ],
        'linkedin'  => '',
        'note'      => '',
    ],

    '3' => [
        'prenom'    => 'Prénom',
        'nom'       => 'NOM',
        'fonction'  => '',
        'societe'   => 'Assokit',
        'tel'       => '+33 6 00 00 00 00',
        'tel_fixe'  => '',
        'email'     => 'prenom@assokit.fr',
        'site'      => 'https://assokit.fr',
        'adresse'   => [
            'rue'    => '',
            'cp'     => '',
            'ville'  => '',
            'pays'   => 'France',
        ],
        'linkedin'  => '',
        'note'      => '',
    ],

];
