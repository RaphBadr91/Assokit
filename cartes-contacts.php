<?php
/**
 * cartes-contacts.php — Les fiches derrière les QR codes.
 *
 * ── LE SEUL FICHIER À MODIFIER ──────────────────────────────────────────────
 *
 * Chaque entrée correspond à un QR code déjà imprimé. Le QR encode l'URL
 * (https://assokit.fr/carte/1), jamais les coordonnées elles-mêmes : vous
 * pouvez donc corriger un numéro de téléphone ici sans réimprimer quoi que
 * ce soit. C'est toute la raison de ce montage.
 *
 * Ne changez PAS les clés '1', '2', '3' — ce sont les adresses gravées dans
 * les QR codes. Tout le reste se modifie librement.
 *
 * Champs facultatifs : laissez la chaîne vide et la ligne disparaît de la
 * fiche comme du fichier .vcf. Aucun champ n'est obligatoire sauf le nom.
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
