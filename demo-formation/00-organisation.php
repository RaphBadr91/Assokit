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
        // Bureau. Une seule présidente « admin » : le sélecteur de démo et les
        // crons prennent « un admin » au hasard ; le bureau passe par coordinator.
        'secretaire'  => ['Marc',     'Lefèvre',  'coordinator', 'volunteer',     'Secrétaire général',                 'bureau'],
        'tresoriere'  => ['Nadia',    'Haddad',   'coordinator', 'volunteer',     'Trésorière',                         'bureau'],
        'vicepres'    => ['Bernard',  'Rousseau', 'referent',    'volunteer',     'Vice-président',                     'bureau'],
        // Salariés
        'formatrice'  => ['Élodie',   'Garnier',  'referent',    'employee',      'Formatrice numérique (CDI)',         'salaries'],
        'insertion'   => ['Yanis',    'Mercier',  'referent',    'employee',      'Conseiller en insertion (CDD)',      'salaries'],
        'communication'=> ['Léa',     'Fontaine', 'member',      'intern',        'Chargée de communication (alternance)', 'salaries'],
        'civique'     => ['Inès',     'Roux',     'member',      'civic_service', 'Volontaire en service civique',      'salaries'],
        'prestataire' => ['Olivier',  'Chevalier','member',      'contractor',    'Formateur certifié Pix (prestataire)', 'salaries'],
        // Bénévoles référents
        'fle2'        => ['Hélène',   'Marchand', 'referent',    'volunteer',     'Bénévole — formatrice FLE',          'benevoles'],
        'devoirs'     => ['Paul',     'Girard',   'referent',    'volunteer',     'Bénévole — responsable aide aux devoirs', 'benevoles'],
        'emploi'      => ['Fatou',    'Diallo',   'member',      'volunteer',     'Bénévole — marraine emploi',         'benevoles'],
        'numerique2'  => ['Antoine',  'Dupuis',   'member',      'volunteer',     'Bénévole — formateur bureautique',   'benevoles'],
        'evenements'  => ['Camille',  'Perrin',   'member',      'volunteer',     'Bénévole — événements',              'benevoles'],
        'logistique'  => ['Jean-Luc', 'Martin',   'member',      'volunteer',     'Bénévole — logistique',              'benevoles'],
        'accueil'     => ['Samira',   'Bensaïd',  'member',      'volunteer',     'Bénévole — accueil',                 'benevoles'],
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

/** Seules couleurs qui ont une classe CSS partout (green/red/gray retombent en bleu). */
const DF_AVATARS = ['blue', 'purple', 'amber', 'pink', 'teal'];

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
        // Formule « Sur-mesure » : la carte du sélecteur affiche FULL MAX. Prix
        // legacy à 0 pour ne pas gonfler les indicateurs du fondateur.
        'plan'                    => 'organisation',
        'monthly_price_cents'     => 0,
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
        // Pas de SIRET : le radar de subventions classerait l'org en « TPE » (financements-engine.php).
        'siret'                   => null,
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
        'parent_org_linked_at'    => null,
        // Un commercial qui ouvre le tunnel de paiement crée un client Stripe :
        // on l'oublie chaque nuit.
        'stripe_customer_id'      => null,
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
        'ics_token'              => substr(hash('sha256', 'demo-f|' . $email), 0, 40),
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
 * Échéance « date à date » d'une adhésion réglée $j jours avant aujourd'hui.
 * Le module cotisations retrouve la date du paiement par le calcul inverse
 * (fin − 364 jours) : les deux restent cohérents sans se parler.
 */
function df_fin_depuis_paiement(int $j): string
{
    return df_j($j + 364);
}

/**
 * Crée les comptes de connexion, l'équipe nommée et les adhérents.
 * Remplit DF::$u (personnages) et DF::$g (groupes).
 *
 * Groupes : comptes, bureau, salaries, benevoles, adherents, apprenants,
 * nouveaux (arrivés depuis 30 j), expires, anciens (désactivés), corbeille.
 */
function df_seed_personnes(): void
{
    $hashComptes = password_hash(DF_MOT_DE_PASSE, PASSWORD_BCRYPT);
    // Les figurants ne se connectent jamais : une empreinte aléatoire unique suffit
    // (et évite 150 calculs bcrypt).
    $hashFigurants = password_hash(bin2hex(random_bytes(24)), PASSWORD_BCRYPT);

    DF::$g = ['comptes' => [], 'bureau' => [], 'salaries' => [], 'benevoles' => [], 'adherents' => [],
              'apprenants' => [], 'nouveaux' => [], 'expires' => [], 'anciens' => [], 'corbeille' => []];
    $vus = [];

    // --- Comptes de connexion (la présidente d'abord : id le plus bas) ---
    // [ancienneté, jours depuis le dernier paiement d'adhésion]
    $profil = ['admin' => [-1100, -40], 'salarie' => [-980, -200], 'benevole' => [-720, -150],
               'membre' => [-260, -260], 'financeur' => [-150, null]];
    foreach (df_comptes() as $cle => [$prenom, $nom, $role, $contrat, $fonction, $local]) {
        [$anc, $paye] = $profil[$cle];
        $plus = [
            'password_hash'  => $hashComptes,
            'adhesion_date'  => df_j($anc),
            'adhesion_valid_until' => $paye === null ? null : df_fin_depuis_paiement($paye),
            'notes_admin'    => $fonction,
            'created_at'     => df_jh($anc, '09:30'),
            'last_login_at'  => df_jh(-1, '18:20'),
        ];
        if ($cle === 'salarie') {
            $plus['contract_start_date'] = df_j(-980);
            $plus['contract_end_date']   = null;
            $plus['can_manage_members']  = 1;
        }
        if ($cle === 'financeur') {
            $plus['organization_name'] = 'Fondation Avenir Solidaire';
            $plus['adhesion_date']     = null;
        }
        // Le compte survit à la purge (voir df_purger) : on le remet à neuf sur
        // place pour que son id ne change pas. Sessions web et app restent valides.
        $email = $local . '@' . DF_DOMAINE;
        $ligne = df_user_ligne($prenom, $nom, $email, $role, $contrat, $plus);
        $q = DF::$pdo->prepare("SELECT id FROM users WHERE email = ? AND org_id = ? LIMIT 1");
        $q->execute([$email, DF::$org]);
        $id = (int)$q->fetchColumn();
        if ($id) {
            df_maj('users', $ligne + ['organization_name' => null, 'deleted_by_user_id' => null,
                                      'contract_start_date' => null, 'contract_end_date' => null, 'notes_admin' => null]
                            , 'id = ?', [$id]);
            if (isset($plus['organization_name']) || isset($plus['contract_start_date'])) df_maj('users', $plus, 'id = ?', [$id]);
            DF::$rapport['inseres']['users'] = (DF::$rapport['inseres']['users'] ?? 0) + 1;
        } else {
            $id = df_ins('users', $ligne);
        }
        if (!$id) throw new RuntimeException("Création du compte $email impossible.");
        DF::$u[$cle] = $id;
        DF::$g['comptes'][] = $id;
        $vus[$prenom . $nom] = true;
    }
    DF::$g['bureau'][]     = DF::$u['admin'];
    DF::$g['salaries'][]   = DF::$u['salarie'];
    DF::$g['benevoles'][]  = DF::$u['benevole'];
    DF::$g['adherents'][]  = DF::$u['membre'];
    DF::$g['apprenants'][] = DF::$u['membre'];

    // --- Équipe nommée ---
    $finContrat = ['insertion' => 150, 'communication' => 240, 'civique' => 25, 'prestataire' => 70];
    foreach (df_equipe() as $cle => [$prenom, $nom, $role, $contrat, $fonction, $groupe]) {
        $debut = -df_entre(250, 1000);
        $plus = [
            'password_hash' => $hashFigurants,
            'adhesion_date' => df_j($debut),
            'adhesion_valid_until' => df_fin_depuis_paiement(-df_entre(20, 300)),
            'notes_admin'   => $fonction,
            'created_at'    => df_jh($debut, '10:00'),
            'last_login_at' => df_jh(-df_entre(0, 6), sprintf('%02d:%02d', df_entre(8, 20), df_entre(0, 59))),
        ];
        if ($contrat !== 'volunteer') {
            $plus['contract_start_date'] = df_j($debut);
            $plus['contract_end_date'] = isset($finContrat[$cle]) ? df_j($finContrat[$cle]) : null;
        }
        if ($cle === 'prestataire') $plus['organization_name'] = 'Numéris Formation SARL';
        if ($cle === 'secretaire')  $plus['can_manage_members'] = 1;
        if ($cle === 'tresoriere')  { $plus['can_manage_finances'] = 1; $plus['can_manage_members'] = 1; }
        $id = df_ins('users', df_user_ligne($prenom, $nom, df_email($prenom, $nom), $role, $contrat, $plus));
        $vus[$prenom . $nom] = true;
        if ($id) {
            DF::$u[$cle] = $id;
            DF::$g[$groupe][] = $id;
        }
    }

    // --- Adhérents ---
    // Profils tirés par index, puis mélangés : les effectifs sont exacts
    // chaque nuit, seuls les noms changent de case.
    //   0-5    échéance dans 2 à 14 j        (« expire bientôt » dans /relances)
    //   6-11   échéance dans 16 à 30 j       (Copilote : « à renouveler »)
    //   12-15  expirés depuis 2 à 12 j       (relance stade 1)
    //   16-19  expirés depuis 15 à 28 j      (stade 2)
    //   20-23  expirés depuis 35 à 110 j     (stade 3)
    //   24-33  arrivés dans les 30 derniers jours, dont 3 cette semaine
    //   34-35  invités cette semaine, mot de passe provisoire
    //   reste  à jour, réglé il y a 35 à 330 j
    $ordre = df_echantillon(range(0, DF_NB_ADHERENTS - 1), DF_NB_ADHERENTS);
    $prenoms = df_prenoms();
    $noms = df_noms();
    foreach ($ordre as $rang => $k) {
        do {
            $p = df_choix($prenoms);
            $n = df_choix($noms);
        } while (isset($vus[$p . $n]));
        $vus[$p . $n] = true;

        $debut = [-df_entre(400, 700), -df_entre(400, 1080), -df_entre(60, 400)][df_entre(0, 2)];
        $mdpProvisoire = false;
        $groupe = 'adherents';
        if ($k <= 5)       { $fin = df_j(df_entre(2, 14)); }
        elseif ($k <= 11)  { $fin = df_j(df_entre(16, 30)); }
        elseif ($k <= 15)  { $fin = df_j(-df_entre(2, 12));  $groupe = 'expires'; }
        elseif ($k <= 19)  { $fin = df_j(-df_entre(15, 28)); $groupe = 'expires'; }
        elseif ($k <= 23)  { $fin = df_j(-df_entre(35, 110)); $groupe = 'expires'; }
        elseif ($k <= 33)  {
            $debut = $k <= 26 ? -df_entre(1, 6) : -df_entre(8, 29);
            $fin = df_fin_depuis_paiement($debut);
        } elseif ($k <= 35) {
            $debut = -df_entre(1, 5);
            $fin = df_fin_depuis_paiement($debut);
            $mdpProvisoire = true;
        } else {
            $fin = df_fin_depuis_paiement(-df_entre(35, 330));
        }
        // Une adhésion ne peut pas avoir été réglée avant d'exister.
        $debut = min($debut, (int)round((strtotime($fin) - DF::$t0) / 86400) - 364);

        // Un sur neuf est bénévole en plus de l'équipe nommée.
        $benevole = ($rang % 9 === 4 && $k > 35);
        $plus = [
            'password_hash' => $hashFigurants,
            'adhesion_date' => df_j($debut),
            'adhesion_valid_until' => $fin,
            'created_at'    => df_jh($debut, sprintf('%02d:%02d', df_entre(9, 19), df_entre(0, 59))),
            'last_login_at' => $mdpProvisoire ? null : (df_proba(0.55) ? df_jh(-df_entre(0, 40), sprintf('%02d:%02d', df_entre(8, 21), df_entre(0, 59))) : null),
            'must_change_password' => $mdpProvisoire ? 1 : 0,
            'onboarding_completed_at' => $mdpProvisoire ? null : df_jh($debut + 1, '10:00'),
            'notes_admin'   => $benevole ? df_choix(['Bénévole — accompagnement numérique', 'Bénévole — conversation FLE',
                                                     'Bénévole — aide aux devoirs', 'Bénévole — atelier CV', 'Bénévole — accueil']) : null,
        ];
        $id = df_ins('users', df_user_ligne($p, $n, df_email($p, $n), 'member', 'volunteer', $plus));
        if (!$id) continue;
        if ($benevole) {
            DF::$g['benevoles'][] = $id;
        } else {
            DF::$g[$groupe][] = $id;
            if ($groupe === 'adherents' && $rang % 3 !== 0) DF::$g['apprenants'][] = $id;
        }
        if ($k >= 24 && $k <= 35) DF::$g['nouveaux'][] = $id;
    }

    // --- Anciens adhérents, désactivés (expirés depuis plus de 4 mois) ---
    foreach ([['Gisèle', 'Bouvier', 160], ['Hervé', 'Collet', 230], ['Nathalie', 'Weber', 410]] as [$p, $n, $j]) {
        $id = df_ins('users', df_user_ligne($p, $n, df_email($p, $n), 'member', 'volunteer', [
            'password_hash' => $hashFigurants,
            'adhesion_date' => df_j(-$j - 700),
            'adhesion_valid_until' => df_j(-$j),
            'created_at' => df_jh(-$j - 700, '11:00'),
            'last_login_at' => df_jh(-$j - 20, '17:00'),
            'is_active' => 0,
            'notes_admin' => 'A déménagé — adhésion non renouvelée',
        ]));
        if ($id) DF::$g['anciens'][] = $id;
    }

    // --- Corbeille : supprimés il y a 3, 11 et 24 jours (27, 19 et 6 jours pour restaurer) ---
    foreach ([['Rémi', 'Fabre', 3], ['Sonia', 'Meunier', 11], ['Laurent', 'Picard', 24]] as [$p, $n, $j]) {
        $id = df_ins('users', df_user_ligne($p, $n, df_email($p, $n), 'member', 'volunteer', [
            'password_hash' => $hashFigurants,
            'adhesion_date' => df_j(-300 - $j),
            'adhesion_valid_until' => df_j(-$j + 40),
            'created_at' => df_jh(-300 - $j, '14:00'),
            'is_active' => 0,
            'deleted_at' => df_jh(-$j, '16:45'),
            'deleted_by_user_id' => DF::$u['admin'],
        ]));
        if ($id) DF::$g['corbeille'][] = $id;
    }

    // L'org est créée par la présidente.
    df_maj('organizations', ['created_by_user_id' => DF::$u['admin'], 'billing_updated_by_user_id' => DF::$u['admin']],
           'id = ?', [DF::$org]);
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
