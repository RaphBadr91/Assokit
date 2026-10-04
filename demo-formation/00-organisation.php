<?php
/**
 * 00 — L'association DEMO F et toute sa distribution.
 * ------------------------------------------------------------------
 * DEMO F est une association loi 1901 de formation et d'insertion,
 * installée à Massy (91). Elle forme des adultes au numérique, au
 * français (FLE) et à la recherche d'emploi ; elle vend aussi des
 * sessions de formation à des entreprises et des collectivités, ce qui
 * fait vivre la facturation. C'est le profil qui permet de montrer
 * TOUT Assokit d'un seul tenant : adhérents et cotisations, salariés
 * et bénévoles, projets subventionnés, clients et factures.
 *
 * Les cinq comptes de connexion :
 *   admin@      Sophie Laurent   présidente           admin
 *   salarie@    Karim Benali     coordinateur (CDI)   coordinator
 *   benevole@   Julie Moreau     formatrice FLE       referent
 *   membre@     Thomas Petit     adhérent apprenant   member
 *   financeur@  Claire Vasseur   fondation partenaire follower
 * ------------------------------------------------------------------
 */

/** Comptes de connexion : clé => [prénom, nom, rôle, contrat, fonction, email local]. */
function df_comptes(): array
{
    return [
        'admin'     => ['Sophie',  'Laurent', 'admin',       'volunteer', 'Présidente',                         'admin'],
        'salarie'   => ['Karim',   'Benali',  'coordinator', 'employee',  'Coordinateur pédagogique (CDI)',     'salarie'],
        'benevole'  => ['Julie',   'Moreau',  'referent',    'volunteer', 'Formatrice FLE bénévole',            'benevole'],
        'membre'    => ['Thomas',  'Petit',   'member',      'volunteer', 'Adhérent — parcours numérique',      'membre'],
        'financeur' => ['Claire',  'Vasseur', 'follower',    'external',  'Chargée de mission, Fondation Avenir Solidaire', 'financeur'],
    ];
}

/** Équipe nommée (hors comptes de connexion). */
function df_equipe(): array
{
    // clé => [prénom, nom, rôle, contrat, fonction, groupe]
    return [
        // Bureau
        'secretaire'  => ['Marc',     'Lefèvre',  'admin',       'volunteer',     'Secrétaire général',                 'bureau'],
        'tresoriere'  => ['Nadia',    'Haddad',   'admin',       'volunteer',     'Trésorière',                         'bureau'],
        'vicepres'    => ['Bernard',  'Rousseau', 'coordinator', 'volunteer',     'Vice-président',                     'bureau'],
        // Salariés
        'formatrice'  => ['Élodie',   'Garnier',  'referent',    'employee',      'Formatrice numérique (CDI)',         'salaries'],
        'insertion'   => ['Yanis',    'Mercier',  'referent',    'employee',      'Conseiller en insertion (CDD)',      'salaries'],
        'communication'=> ['Léa',     'Fontaine', 'member',      'intern',        'Chargée de communication (alternance)', 'salaries'],
        'civique'     => ['Inès',     'Roux',     'member',      'civic_service', 'Volontaire en service civique',      'salaries'],
        // Bénévoles référents
        'fle2'        => ['Hélène',   'Marchand', 'referent',    'volunteer',     'Formatrice FLE bénévole',            'benevoles'],
        'devoirs'     => ['Paul',     'Girard',   'referent',    'volunteer',     'Responsable aide aux devoirs',       'benevoles'],
        'emploi'      => ['Fatou',    'Diallo',   'member',      'volunteer',     'Marraine emploi',                    'benevoles'],
        'numerique2'  => ['Antoine',  'Dupuis',   'member',      'volunteer',     'Formateur bureautique bénévole',     'benevoles'],
        'evenements'  => ['Camille',  'Perrin',   'member',      'volunteer',     'Bénévole événements',                'benevoles'],
        'logistique'  => ['Jean-Luc', 'Martin',   'member',      'volunteer',     'Bénévole logistique',                'benevoles'],
        'accueil'     => ['Samira',   'Bensaïd',  'member',      'volunteer',     'Bénévole accueil',                   'benevoles'],
    ];
}

function df_prenoms(): array
{
    return ['Amina','Lucas','Chloé','Mamadou','Léa','Hugo','Inès','Nathan','Manon','Youssef',
            'Emma','Théo','Sarah','Enzo','Lina','Louis','Jade','Gabriel','Louise','Adam',
            'Alice','Raphaël','Yasmine','Arthur','Zoé','Jules','Anna','Mehdi','Rose','Noah',
            'Julia','Tom','Nina','Ibrahim','Éva','Sacha','Mila','Ethan','Léna','Moussa',
            'Ambre','Aaron','Romane','Malik','Juliette','Isaac','Aïcha','Nolan','Clara','Ali',
            'Agathe','Kevin','Sophia','Bilal','Margaux','Victor','Leïla','Maxime','Océane','Rayan',
            'Pauline','Florian','Assia','Quentin','Mathilde','Samuel','Kenza','Benjamin','Elsa','Omar',
            'Monique','Gérard','Françoise','Michel','Josiane','Daniel','Brigitte','Patrick','Sylvie','Alain'];
}

function df_noms(): array
{
    return ['Martin','Bernard','Dubois','Thomas','Robert','Richard','Durand','Leroy','Moreau','Simon',
            'Lefebvre','Michel','Garcia','David','Bertrand','Roux','Vincent','Fournier','Morel','Girard',
            'André','Mercier','Dupont','Lambert','Bonnet','François','Martinez','Legrand','Garnier','Faure',
            'Rousseau','Blanc','Guérin','Muller','Henry','Roussel','Nguyen','Gauthier','Perrin','Robin',
            'Clément','Morin','Nicolas','Mathieu','Traoré','Diallo','Kone','Benali','Haddad','Cissé',
            'Lopez','Fontaine','Chevalier','Masson','Sanchez','Boyer','Denis','Lemaire','Duval','Joly',
            'Gaillard','Barbier','Arnaud','Martins','Rivière','Lucas','Brun','Colin','Vidal','Caron'];
}

function df_villes(): array
{
    // [ville, code postal] — communes réelles du secteur, adresses fictives.
    return [['Massy','91300'],['Massy','91300'],['Massy','91300'],['Palaiseau','91120'],['Antony','92160'],
            ['Chilly-Mazarin','91380'],['Longjumeau','91160'],['Igny','91430'],['Wissous','91320'],
            ['Verrières-le-Buisson','91370'],['Orsay','91400'],['Champlan','91160']];
}

const DF_AVATARS = ['blue', 'purple', 'amber', 'pink', 'teal', 'green', 'red', 'gray'];

/** Nombre d'adhérents « simples » générés en plus de l'équipe nommée. */
const DF_NB_ADHERENTS = 132;

/**
 * Crée (ou remet à zéro) la ligne organizations et renvoie son id.
 * La ligne n'est jamais supprimée : son id reste stable d'une nuit à l'autre.
 */
function df_organisation(): int
{
    $pdo = DF::$pdo;
    $s = $pdo->prepare("SELECT id FROM organizations WHERE slug = ? ORDER BY id LIMIT 2");
    $s->execute([DF_SLUG]);
    $ids = $s->fetchAll(PDO::FETCH_COLUMN);
    if (count($ids) > 1) {
        throw new RuntimeException("Plusieurs organisations portent le slug « " . DF_SLUG . " » : arrêt par prudence.");
    }

    $champs = [
        'name'                    => 'DEMO F',
        'legal_name'              => 'Association DEMO F — Formation & Insertion',
        'slug'                    => DF_SLUG,
        'subdomain_slug'          => DF_SLUG,
        'status'                  => 'active',
        'validation_status'       => 'validated',
        'validated_at'            => df_jh(-1100, '09:00'),
        'rejection_reason'        => null,
        'suspended_reason'        => null,
        'trial_ends_at'           => null,
        'notified_trial_j7'       => 1,
        'notified_trial_j3'       => 1,
        'notified_trial_j0'       => 1,
        'current_period_start'    => df_mois(0, 1),
        'current_period_end'      => '2099-12-31',
        'legal_form'              => 'Association loi 1901',
        'siren'                   => '913452786',
        'siret'                   => '91345278600019',
        'rna_number'              => 'W913004521',
        'vat_subject'             => 0,
        'vat_number'              => null,
        'billing_address_street'  => '12 rue des Ateliers',
        'billing_address_complement' => 'Bâtiment B — 1er étage',
        'billing_address_zip'     => '91300',
        'billing_address_city'    => 'Massy',
        'billing_address_country' => 'FR',
        'billing_email'           => 'contact@' . DF_DOMAINE,
        'billing_phone'           => df_tel(1234, false),
        'president_first_name'    => 'Sophie',
        'president_last_name'     => 'Laurent',
        'president_role'          => 'Présidente',
        'branding_primary_color'  => '#4F46E5',
        'branding_secondary_color'=> '#0F172A',
        'hide_assokit_branding'   => 0,
        'deleted_at'              => null,
        'deletion_reason'         => null,
        'deleted_by_user_id'      => null,
        'parent_org_id'           => null,
        'internal_notes'          => '⚠️ ORGANISATION DE DÉMONSTRATION « DEMO F » — formation des commerciaux. '
                                   . 'Données fictives, reconstruites chaque nuit (seed-demo-formation.php). Ne pas facturer.',
        'notes_superadmin'        => 'Démo commerciale — reset nocturne automatique.',
        'billing_updated_at'      => df_jh(-30, '10:00'),
    ];

    if ($ids) {
        $org = (int)$ids[0];
        df_maj('organizations', $champs, 'id = ?', [$org]);
    } else {
        $champs['created_at'] = df_jh(-1100, '09:00');
        $org = (int)df_ins('organizations', $champs);
        if ($org <= 0) throw new RuntimeException("Impossible de créer l'organisation de démo.");
    }
    return $org;
}

/**
 * Échéance d'adhésion « à jour » : 31 décembre de l'année, ou de l'année
 * suivante quand on est trop près de la fin d'année (sinon, en décembre,
 * toute l'équipe apparaîtrait « à renouveler »).
 */
function df_fin_adhesion(): string
{
    $fin = date('Y-12-31', DF::$t0);
    if (strtotime($fin) - DF::$t0 < 45 * 86400) $fin = (df_annee() + 1) . '-12-31';
    return $fin;
}

/** Ligne users commune à tout le monde. */
function df_user_ligne(string $prenom, string $nom, string $email, string $role, string $contrat, array $plus = []): array
{
    static $n = 0;
    $n++;
    $ville = df_choix(df_villes());
    $ligne = [
        'org_id'                 => DF::$org,
        'email'                  => $email,
        'first_name'             => $prenom,
        'last_name'              => $nom,
        'phone'                  => df_tel(2000 + $n * 37),
        'city'                   => $ville[0],
        'avatar_color'           => DF_AVATARS[$n % count(DF_AVATARS)],
        'role'                   => $role,
        'contract_type'          => $contrat,
        'is_active'              => 1,
        'deleted_at'             => null,
        'must_change_password'   => 0,
        'is_super_admin'         => 0,
        'is_founder'             => 0,
        'is_platform_admin'      => 0,
        'parent_org_id'          => null,
        'parent_org_role'        => null,
        'totp_secret'            => null,
        'totp_enabled'           => 0,
        'totp_backup_codes'      => null,
        'email_verified_at'      => df_jh(-200, '09:00'),
        'onboarding_completed_at'=> df_jh(-200, '09:05'),
        'can_create_projects'    => 0,
        'can_create_folders'     => 0,
        'can_manage_members'     => 0,
        'can_manage_finances'    => 0,
        'can_access_marketing'   => 0,
        'can_manage_events'      => 0,
        'can_moderate_messages'  => 0,
        'ics_token'              => substr(hash('sha256', 'demo-f|' . $email), 0, 32),
    ];
    $droits = [
        'admin'       => ['can_create_projects','can_create_folders','can_manage_members','can_manage_finances',
                          'can_access_marketing','can_manage_events','can_moderate_messages'],
        'coordinator' => ['can_create_projects','can_create_folders','can_access_marketing','can_manage_events','can_moderate_messages'],
        'referent'    => ['can_create_projects'],
    ][$role] ?? [];
    foreach ($droits as $d) $ligne[$d] = 1;
    return array_merge($ligne, $plus);
}

/**
 * Crée les comptes de connexion, l'équipe nommée et les adhérents.
 * Remplit DF::$u (personnages) et DF::$g (groupes).
 */
function df_seed_personnes(): void
{
    $hashComptes = password_hash(DF_MOT_DE_PASSE, PASSWORD_BCRYPT);
    // Les figurants ne se connectent jamais : une empreinte aléatoire unique suffit
    // (et évite 150 calculs bcrypt).
    $hashFigurants = password_hash(bin2hex(random_bytes(24)), PASSWORD_BCRYPT);

    DF::$g = ['bureau' => [], 'salaries' => [], 'benevoles' => [], 'adherents' => [], 'apprenants' => [], 'comptes' => []];

    // --- Comptes de connexion ---
    $anciennete = ['admin' => -1100, 'salarie' => -980, 'benevole' => -720, 'membre' => -260, 'financeur' => -150];
    foreach (df_comptes() as $cle => [$prenom, $nom, $role, $contrat, $fonction, $local]) {
        $plus = [
            'password_hash'  => $hashComptes,
            'adhesion_date'  => df_j($anciennete[$cle]),
            'adhesion_valid_until' => df_fin_adhesion(),
            'notes_admin'    => $fonction,
            'created_at'     => df_jh($anciennete[$cle], '09:30'),
            'last_login_at'  => df_jh(-1, '18:20'),
        ];
        if ($cle === 'salarie') {
            $plus['contract_start_date'] = df_j(-980);
            $plus['contract_end_date']   = null;
            $plus['can_manage_members']  = 1;
        }
        if ($cle === 'financeur') {
            $plus['organization_name']    = 'Fondation Avenir Solidaire';
            $plus['adhesion_date']        = null;
            $plus['adhesion_valid_until'] = null;
        }
        $id = df_ins('users', df_user_ligne($prenom, $nom, $local . '@' . DF_DOMAINE, $role, $contrat, $plus));
        if (!$id) throw new RuntimeException("Création du compte $local@" . DF_DOMAINE . ' impossible.');
        DF::$u[$cle] = $id;
        DF::$g['comptes'][] = $id;
    }
    DF::$g['bureau'][]    = DF::$u['admin'];
    DF::$g['salaries'][]  = DF::$u['salarie'];
    DF::$g['benevoles'][] = DF::$u['benevole'];
    DF::$g['adherents'][] = DF::$u['membre'];
    DF::$g['apprenants'][] = DF::$u['membre'];

    // --- Équipe nommée ---
    foreach (df_equipe() as $cle => [$prenom, $nom, $role, $contrat, $fonction, $groupe]) {
        $debut = -df_entre(200, 1000);
        $plus = [
            'password_hash' => $hashFigurants,
            'adhesion_date' => df_j($debut),
            'adhesion_valid_until' => df_fin_adhesion(),
            'notes_admin'   => $fonction,
            'created_at'    => df_jh($debut, '10:00'),
            'last_login_at' => df_jh(-df_entre(0, 6), sprintf('%02d:%02d', df_entre(8, 20), df_entre(0, 59))),
        ];
        if (in_array($contrat, ['employee', 'intern', 'civic_service'], true)) {
            $plus['contract_start_date'] = df_j($debut);
            $plus['contract_end_date'] = [
                'insertion' => df_j(150), 'communication' => df_j(240), 'civique' => df_j(95),
            ][$cle] ?? null;
        }
        $id = df_ins('users', df_user_ligne($prenom, $nom, df_email($prenom, $nom), $role, $contrat, $plus));
        if ($id) {
            DF::$u[$cle] = $id;
            DF::$g[$groupe][] = $id;
        }
    }

    // --- Adhérents ---
    $prenoms = df_prenoms();
    $noms = df_noms();
    $vus = [];
    for ($i = 0; $i < DF_NB_ADHERENTS; $i++) {
        do {
            $p = df_choix($prenoms);
            $n = df_choix($noms);
        } while (isset($vus[$p . $n]));
        $vus[$p . $n] = true;

        // Ancienneté étalée sur 3 ans, avec une vraie croissance : plus d'arrivées récentes.
        $r = df_entre(0, 99);
        $debut = $r < 35 ? -df_entre(5, 180) : ($r < 70 ? -df_entre(181, 540) : -df_entre(541, 1080));

        // Statut d'adhésion : 82 % à jour, 10 % à renouveler sous 30 jours, 8 % expirés.
        $s = df_entre(0, 99);
        if ($s < 82)      $fin = df_j(df_entre(45, 330));
        elseif ($s < 92)  $fin = df_j(df_entre(3, 30));
        else              $fin = df_j(-df_entre(10, 120));

        $benevole = ($i % 9 === 4);           // une quinzaine de bénévoles en plus de l'équipe
        $plus = [
            'password_hash' => $hashFigurants,
            'adhesion_date' => df_j($debut),
            'adhesion_valid_until' => $fin,
            'created_at'    => df_jh($debut, sprintf('%02d:%02d', df_entre(9, 19), df_entre(0, 59))),
            'last_login_at' => df_proba(0.55) ? df_jh(-df_entre(0, 40), '12:00') : null,
            'is_active'     => ($i % 31 === 30) ? 0 : 1,
        ];
        $id = df_ins('users', df_user_ligne($p, $n, df_email($p, $n), 'member', 'volunteer', $plus));
        if (!$id) continue;
        if ($benevole) {
            DF::$g['benevoles'][] = $id;
        } else {
            DF::$g['adherents'][] = $id;
            if ($i % 3 !== 0) DF::$g['apprenants'][] = $id;
        }
    }
}

/** Tous les ids d'utilisateurs actifs de l'org (hors financeur externe). */
function df_tous_membres(): array
{
    return array_values(array_unique(array_merge(
        DF::$g['bureau'], DF::$g['salaries'], DF::$g['benevoles'], DF::$g['adherents']
    )));
}

/** L'équipe qui fait vivre l'asso : bureau + salariés + bénévoles. */
function df_equipe_active(): array
{
    return array_values(array_unique(array_merge(DF::$g['bureau'], DF::$g['salaries'], DF::$g['benevoles'])));
}
