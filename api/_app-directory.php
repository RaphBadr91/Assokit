<?php
/**
 * api/_app-directory.php — Socle de l'ANNUAIRE NATIONAL des associations (données publiques).
 *
 * Source : recherche-entreprises.api.gouv.fr (données ouvertes de l'État — LÉGALES).
 * Contenu : nom, catégorie, commune, département, région, SIREN, NAF.
 * PAS d'email (absent des données publiques) : la colonne reste vide, à remplir
 * légalement avant tout emailing (adresses contact@ publiées + opt-out).
 *
 * Rangement : Région → Département → Catégorie, exactement comme le dashboard fondateur.
 * App-only. NE MODIFIE PAS le site.
 */

if (!function_exists('ak_directory_tables_ensure')) {
    function ak_directory_tables_ensure(PDO $pdo): void {
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS asso_directory (
                id INT AUTO_INCREMENT PRIMARY KEY,
                siren VARCHAR(12) NOT NULL,
                org_name VARCHAR(220) NOT NULL,
                category VARCHAR(40) NOT NULL DEFAULT 'autre',
                naf VARCHAR(10) DEFAULT NULL,
                city VARCHAR(140) DEFAULT NULL,
                zip VARCHAR(10) DEFAULT NULL,
                dept_code VARCHAR(3) DEFAULT NULL,
                dept_name VARCHAR(80) DEFAULT NULL,
                region VARCHAR(80) DEFAULT NULL,
                address VARCHAR(255) DEFAULT NULL,
                email VARCHAR(255) DEFAULT NULL,
                phone VARCHAR(40) DEFAULT NULL,
                website VARCHAR(255) DEFAULT NULL,
                source VARCHAR(60) DEFAULT 'rna_open_data',
                created_at DATETIME NOT NULL,
                UNIQUE KEY uq_siren (siren),
                INDEX idx_region (region),
                INDEX idx_dept (dept_code),
                INDEX idx_cat (category)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } catch (Throwable $e) {}
    }
}

/** Catégories d'associations → codes NAF (activité principale INSEE). */
if (!function_exists('ak_directory_categories')) {
    function ak_directory_categories(): array {
        return [
            'sport'         => ['label' => 'Sport',                   'naf' => ['93.12Z','93.19Z','93.11Z','93.13Z','85.51Z']],
            'culture'       => ['label' => 'Culture & arts',          'naf' => ['90.01Z','90.02Z','90.03B','90.04Z','91.03Z','90.03A']],
            'social'        => ['label' => 'Social & solidarité',     'naf' => ['88.99B','88.99A','88.10C','88.91A','88.10A']],
            'sante'         => ['label' => 'Santé',                   'naf' => ['86.90D','86.90F','87.30A','87.90B']],
            'education'     => ['label' => 'Éducation & formation',   'naf' => ['85.59A','85.59B','85.60Z','85.52Z']],
            'environnement' => ['label' => 'Environnement',           'naf' => ['94.99Z','81.30Z']],
            'loisirs'       => ['label' => 'Loisirs & clubs',         'naf' => ['94.99Z','93.29Z']],
            'citoyennete'   => ['label' => 'Citoyenneté & droits',    'naf' => ['94.99Z','94.92Z']],
            'culte'         => ['label' => 'Cultuel',                 'naf' => ['94.91Z']],
            'professionnel' => ['label' => 'Professionnel & éco.',    'naf' => ['94.11Z','94.12Z','94.20Z']],
        ];
    }
}

/** Mappe un code département → [nom, région]. Couvre métropole + DOM. */
if (!function_exists('ak_dept_region_map')) {
    function ak_dept_region_map(): array {
        return [
            '01'=>['Ain','Auvergne-Rhône-Alpes'],'02'=>['Aisne','Hauts-de-France'],'03'=>['Allier','Auvergne-Rhône-Alpes'],
            '04'=>['Alpes-de-Haute-Provence','Provence-Alpes-Côte d\'Azur'],'05'=>['Hautes-Alpes','Provence-Alpes-Côte d\'Azur'],
            '06'=>['Alpes-Maritimes','Provence-Alpes-Côte d\'Azur'],'07'=>['Ardèche','Auvergne-Rhône-Alpes'],'08'=>['Ardennes','Grand Est'],
            '09'=>['Ariège','Occitanie'],'10'=>['Aube','Grand Est'],'11'=>['Aude','Occitanie'],'12'=>['Aveyron','Occitanie'],
            '13'=>['Bouches-du-Rhône','Provence-Alpes-Côte d\'Azur'],'14'=>['Calvados','Normandie'],'15'=>['Cantal','Auvergne-Rhône-Alpes'],
            '16'=>['Charente','Nouvelle-Aquitaine'],'17'=>['Charente-Maritime','Nouvelle-Aquitaine'],'18'=>['Cher','Centre-Val de Loire'],
            '19'=>['Corrèze','Nouvelle-Aquitaine'],'2A'=>['Corse-du-Sud','Corse'],'2B'=>['Haute-Corse','Corse'],
            '21'=>['Côte-d\'Or','Bourgogne-Franche-Comté'],'22'=>['Côtes-d\'Armor','Bretagne'],'23'=>['Creuse','Nouvelle-Aquitaine'],
            '24'=>['Dordogne','Nouvelle-Aquitaine'],'25'=>['Doubs','Bourgogne-Franche-Comté'],'26'=>['Drôme','Auvergne-Rhône-Alpes'],
            '27'=>['Eure','Normandie'],'28'=>['Eure-et-Loir','Centre-Val de Loire'],'29'=>['Finistère','Bretagne'],
            '30'=>['Gard','Occitanie'],'31'=>['Haute-Garonne','Occitanie'],'32'=>['Gers','Occitanie'],'33'=>['Gironde','Nouvelle-Aquitaine'],
            '34'=>['Hérault','Occitanie'],'35'=>['Ille-et-Vilaine','Bretagne'],'36'=>['Indre','Centre-Val de Loire'],
            '37'=>['Indre-et-Loire','Centre-Val de Loire'],'38'=>['Isère','Auvergne-Rhône-Alpes'],'39'=>['Jura','Bourgogne-Franche-Comté'],
            '40'=>['Landes','Nouvelle-Aquitaine'],'41'=>['Loir-et-Cher','Centre-Val de Loire'],'42'=>['Loire','Auvergne-Rhône-Alpes'],
            '43'=>['Haute-Loire','Auvergne-Rhône-Alpes'],'44'=>['Loire-Atlantique','Pays de la Loire'],'45'=>['Loiret','Centre-Val de Loire'],
            '46'=>['Lot','Occitanie'],'47'=>['Lot-et-Garonne','Nouvelle-Aquitaine'],'48'=>['Lozère','Occitanie'],
            '49'=>['Maine-et-Loire','Pays de la Loire'],'50'=>['Manche','Normandie'],'51'=>['Marne','Grand Est'],
            '52'=>['Haute-Marne','Grand Est'],'53'=>['Mayenne','Pays de la Loire'],'54'=>['Meurthe-et-Moselle','Grand Est'],
            '55'=>['Meuse','Grand Est'],'56'=>['Morbihan','Bretagne'],'57'=>['Moselle','Grand Est'],'58'=>['Nièvre','Bourgogne-Franche-Comté'],
            '59'=>['Nord','Hauts-de-France'],'60'=>['Oise','Hauts-de-France'],'61'=>['Orne','Normandie'],'62'=>['Pas-de-Calais','Hauts-de-France'],
            '63'=>['Puy-de-Dôme','Auvergne-Rhône-Alpes'],'64'=>['Pyrénées-Atlantiques','Nouvelle-Aquitaine'],
            '65'=>['Hautes-Pyrénées','Occitanie'],'66'=>['Pyrénées-Orientales','Occitanie'],'67'=>['Bas-Rhin','Grand Est'],
            '68'=>['Haut-Rhin','Grand Est'],'69'=>['Rhône','Auvergne-Rhône-Alpes'],'70'=>['Haute-Saône','Bourgogne-Franche-Comté'],
            '71'=>['Saône-et-Loire','Bourgogne-Franche-Comté'],'72'=>['Sarthe','Pays de la Loire'],'73'=>['Savoie','Auvergne-Rhône-Alpes'],
            '74'=>['Haute-Savoie','Auvergne-Rhône-Alpes'],'75'=>['Paris','Île-de-France'],'76'=>['Seine-Maritime','Normandie'],
            '77'=>['Seine-et-Marne','Île-de-France'],'78'=>['Yvelines','Île-de-France'],'79'=>['Deux-Sèvres','Nouvelle-Aquitaine'],
            '80'=>['Somme','Hauts-de-France'],'81'=>['Tarn','Occitanie'],'82'=>['Tarn-et-Garonne','Occitanie'],
            '83'=>['Var','Provence-Alpes-Côte d\'Azur'],'84'=>['Vaucluse','Provence-Alpes-Côte d\'Azur'],'85'=>['Vendée','Pays de la Loire'],
            '86'=>['Vienne','Nouvelle-Aquitaine'],'87'=>['Haute-Vienne','Nouvelle-Aquitaine'],'88'=>['Vosges','Grand Est'],
            '89'=>['Yonne','Bourgogne-Franche-Comté'],'90'=>['Territoire de Belfort','Bourgogne-Franche-Comté'],
            '91'=>['Essonne','Île-de-France'],'92'=>['Hauts-de-Seine','Île-de-France'],'93'=>['Seine-Saint-Denis','Île-de-France'],
            '94'=>['Val-de-Marne','Île-de-France'],'95'=>['Val-d\'Oise','Île-de-France'],
            '971'=>['Guadeloupe','Guadeloupe'],'972'=>['Martinique','Martinique'],'973'=>['Guyane','Guyane'],
            '974'=>['La Réunion','La Réunion'],'976'=>['Mayotte','Mayotte'],
        ];
    }
}

/** Renvoie [nom_dept, region] pour un code, ou ['',''] si inconnu. */
if (!function_exists('ak_dept_region')) {
    function ak_dept_region(string $code): array {
        $m = ak_dept_region_map();
        return $m[$code] ?? ['', ''];
    }
}
