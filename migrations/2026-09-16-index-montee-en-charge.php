<?php
/**
 * migrations/2026-09-16-index-montee-en-charge.php
 * ------------------------------------------------------------------
 * Pose les index qui manquent pour tenir 10 000 associations.
 *
 * Pourquoi une migration PHP et pas un .sql :
 * le schéma de production n'est pas dans le dépôt (les tables du noyau
 * ont été créées à la main, seules les tables récentes ont une
 * migration). On ne sait donc pas à l'avance quelles colonnes et quels
 * index existent réellement. Un fichier .sql écrit à l'aveugle
 * échouerait sur la première table absente ; ce script inspecte
 * information_schema, saute ce qui n'existe pas, saute ce qui est déjà
 * indexé, et dit pourquoi à chaque ligne.
 *
 * Ce qu'on corrige :
 * sans index sur users.org_id, compter les adhérents d'UNE association
 * oblige MySQL à lire la table entière. Mesuré sur une base de test de
 * 10 000 associations / 765 000 adhérents : 765 001 lignes lues pour en
 * retourner 24. Même chose pour le fil d'activité du tableau de bord,
 * qui triait 3,1 millions de lignes pour en afficher 8.
 *
 * Les index sont ajoutés en ALGORITHM=INPLACE, LOCK=NONE : la table
 * reste lisible et modifiable pendant la construction. Indispensable
 * ici, la migration tourne sur un site en service.
 *
 * Usage :
 *   php migrations/2026-09-16-index-montee-en-charge.php            (simulation)
 *   php migrations/2026-09-16-index-montee-en-charge.php --appliquer
 *
 * La simulation est le défaut : sur une grosse table un index se
 * construit en minutes, autant savoir d'abord lesquels vont être créés.
 * Relançable autant de fois qu'on veut.
 * ------------------------------------------------------------------
 */

if (PHP_SAPI !== 'cli' && isset($_SERVER['REQUEST_METHOD'])) { http_response_code(403); die('Forbidden'); }

require_once __DIR__ . '/../config.php';
if (!isset($pdo) || !($pdo instanceof PDO)) { fwrite(STDERR, "PDO indisponible (config.php).\n"); exit(1); }
try { $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true); } catch (Throwable $e) {}

$appliquer = in_array('--appliquer', $argv, true);

/**
 * Les index à poser, table par table.
 *
 * L'ordre des colonnes n'est pas décoratif : on met d'abord ce sur quoi
 * on filtre par égalité (org_id, project_id), ensuite ce sur quoi on
 * trie ou on filtre par plage (created_at). C'est ce qui permet à MySQL
 * de lire directement les N lignes voulues au lieu de tout trier.
 */
$plan = [
    // ---- Le noyau multi-associations ----------------------------
    // Chaque page de l'app commence par « les X de mon association ».
    'users' => [
        ['ak_org_actif',   ['org_id', 'is_active', 'deleted_at']],
        ['ak_org_role',    ['org_id', 'role']],
        ['ak_derniere_cx', ['last_login_at']],
    ],
    'organizations' => [
        ['ak_supprime', ['deleted_at']],
        ['ak_plan',     ['plan', 'status']],
    ],
    'folders' => [
        ['ak_org_archive', ['org_id', 'archived_at']],
    ],
    'projects' => [
        ['ak_dossier',  ['folder_id', 'status', 'archived_at']],
        ['ak_referent', ['referent_id', 'status', 'archived_at']],
        ['ak_maj',      ['updated_at']],
    ],

    // ---- Les tables filles des projets --------------------------
    // Elles n'ont pas d'org_id : on y arrive toujours par project_id.
    'project_activity_log' => [
        ['ak_projet_date', ['project_id', 'created_at']],
        ['ak_membre_date', ['user_id', 'created_at']],
    ],
    'project_messages' => [
        ['ak_projet_date', ['project_id', 'created_at']],
        ['ak_auteur',      ['author_id']],
    ],
    'project_members' => [
        ['ak_projet_membre', ['project_id', 'user_id']],
        ['ak_membre',        ['user_id']],
    ],
    'project_steps'    => [['ak_projet', ['project_id']]],
    'project_files'    => [['ak_projet', ['project_id']]],
    'project_updates'  => [['ak_projet', ['project_id']]],
    'project_invoices' => [['ak_projet', ['project_id']]],

    // ---- Facturation --------------------------------------------
    'asso_invoices' => [
        ['ak_org_statut', ['org_id', 'status', 'issued_at']],
        ['ak_org_echue',  ['org_id', 'due_at']],
        ['ak_client',     ['client_id']],
    ],
    'asso_invoice_lines' => [['ak_facture', ['invoice_id']]],
    'asso_clients'       => [['ak_org', ['org_id']]],
    'asso_quotes'        => [['ak_org_statut', ['org_id', 'status']]],
    'asso_quote_lines'   => [['ak_devis', ['quote_id']]],

    // ---- Vie associative ----------------------------------------
    'events'              => [['ak_org_debut', ['org_id', 'starts_at']]],
    'event_participants'  => [['ak_evenement', ['event_id']]],
    'grants'              => [['ak_org_echeance', ['org_id', 'deadline_apply']]],
    'cotisation_payments' => [
        ['ak_org_statut', ['org_id', 'status']],
        ['ak_campagne',   ['campaign_id']],
    ],
    'channels'         => [['ak_org', ['org_id']]],
    'channel_messages' => [['ak_canal_date', ['channel_id', 'created_at']]],
    'channel_members'  => [
        ['ak_canal_membre', ['channel_id', 'user_id']],
        ['ak_membre',       ['user_id']],
    ],
    'communication_broadcast_recipients' => [['ak_envoi', ['broadcast_id']]],

    // ---- Journal d'activité -------------------------------------
    // La table qui grossit le plus vite : une ligne par action de
    // chaque membre. Les index simples posés à la création ne servent
    // pas quand on filtre ET qu'on trie ; il faut les paires.
    'assokit_activity_log' => [
        ['ak_org_date',    ['organization_id', 'created_at']],
        ['ak_membre_date', ['user_id', 'created_at']],
        ['ak_type_date',   ['event_type', 'created_at']],
        ['ak_email_date',  ['user_email', 'created_at']],
        // Index couvrant : les chiffres d'en-tête de /fondateur-connexions
        // (connexions, actions, membres distincts, associations distinctes)
        // se lisent entièrement dedans, sans jamais ouvrir la table.
        // Mesuré à 5,36 millions de lignes : 25,5 s sans, 0,3 s avec.
        ['ak_entete',      ['created_at', 'event_type', 'user_id', 'organization_id']],
    ],
    'assokit_active_sessions' => [
        ['ak_org_activite', ['organization_id', 'last_activity_at']],
    ],

    // ---- Prospection --------------------------------------------
    'asso_prospection' => [
        ['ak_org_rappel', ['org_id', 'deleted_at', 'callback_at']],
        ['ak_org_import', ['org_id', 'import_id']],
    ],
];

// ------------------------------------------------------------------
// Inspection du schéma réel
// ------------------------------------------------------------------
$base = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
echo "Base : $base\n";
echo $appliquer ? "Mode : APPLICATION\n" : "Mode : simulation (ajouter --appliquer pour écrire)\n";
echo str_repeat('-', 78), "\n";

/** Colonnes existantes, table par table. */
$st = $pdo->prepare('SELECT table_name, column_name FROM information_schema.columns WHERE table_schema = ?');
$st->execute([$base]);
$colonnes = [];
foreach ($st->fetchAll(PDO::FETCH_NUM) as [$t, $c]) $colonnes[strtolower($t)][strtolower($c)] = true;

/**
 * Index existants, ramenés à leur signature « col1,col2,… ».
 * On compare les colonnes, pas les noms : un index équivalent posé
 * sous un autre nom doit compter comme déjà là, sinon on en empile un
 * doublon qui coûte de l'écriture pour rien.
 */
$st = $pdo->prepare('SELECT table_name, index_name, seq_in_index, column_name
                     FROM information_schema.statistics WHERE table_schema = ?
                     ORDER BY table_name, index_name, seq_in_index');
$st->execute([$base]);
$parIndex = [];
foreach ($st->fetchAll(PDO::FETCH_NUM) as [$t, $i, $s, $c]) $parIndex[strtolower($t)][$i][] = strtolower($c);

$signatures = [];
foreach ($parIndex as $t => $idx) {
    foreach ($idx as $cols) {
        // Un index sur (a,b,c) sert aussi pour (a) et (a,b) : on note
        // tous ses préfixes, sinon on recrée un index déjà couvert.
        for ($n = 1; $n <= count($cols); $n++) {
            $signatures[$t][implode(',', array_slice($cols, 0, $n))] = implode(',', $cols);
        }
    }
}

// ------------------------------------------------------------------
// Passage en revue
// ------------------------------------------------------------------
$aFaire = $faits = $sautes = $echecs = 0;

foreach ($plan as $table => $index) {
    $t = strtolower($table);
    if (!isset($colonnes[$t])) {
        echo sprintf("  · %-34s table absente, ignorée\n", $table);
        $sautes += count($index);
        continue;
    }
    foreach ($index as [$nom, $cols]) {
        $manquantes = array_values(array_filter($cols, fn($c) => !isset($colonnes[$t][strtolower($c)])));
        if ($manquantes) {
            echo sprintf("  · %-22s %-26s colonne absente : %s\n", $table, $nom, implode(', ', $manquantes));
            $sautes++;
            continue;
        }
        $sig = implode(',', array_map('strtolower', $cols));
        if (isset($signatures[$t][$sig])) {
            echo sprintf("  = %-22s %-26s déjà couvert par (%s)\n", $table, $nom, $signatures[$t][$sig]);
            $sautes++;
            continue;
        }

        $sql = sprintf('ALTER TABLE `%s` ADD KEY `%s` (%s), ALGORITHM=INPLACE, LOCK=NONE',
            $table, $nom, implode(', ', array_map(fn($c) => "`$c`", $cols)));

        if (!$appliquer) {
            echo sprintf("  + %-22s %-26s à créer (%s)\n", $table, $nom, implode(', ', $cols));
            $aFaire++;
            continue;
        }

        $debut = microtime(true);
        try {
            $pdo->exec($sql);
            $ms = (microtime(true) - $debut) * 1000;
            echo sprintf("  + %-22s %-26s créé en %s\n", $table, $nom,
                $ms < 1000 ? round($ms) . ' ms' : round($ms / 1000, 1) . ' s');
            $faits++;
            // Signature notée : deux entrées du plan peuvent se recouvrir.
            for ($n = 1; $n <= count($cols); $n++) {
                $signatures[$t][implode(',', array_slice(array_map('strtolower', $cols), 0, $n))] = $sig;
            }
        } catch (Throwable $e) {
            // LOCK=NONE n'est pas possible sur toutes les tables (vieux
            // format de ligne, index fulltext). On retente sans, plutôt
            // que d'abandonner l'index.
            try {
                $pdo->exec(sprintf('ALTER TABLE `%s` ADD KEY `%s` (%s)',
                    $table, $nom, implode(', ', array_map(fn($c) => "`$c`", $cols))));
                echo sprintf("  + %-22s %-26s créé (sans LOCK=NONE)\n", $table, $nom);
                $faits++;
            } catch (Throwable $e2) {
                echo sprintf("  ! %-22s %-26s ÉCHEC : %s\n", $table, $nom, $e2->getMessage());
                $echecs++;
            }
        }
    }
}

echo str_repeat('-', 78), "\n";

// ------------------------------------------------------------------
// Les index devenus inutiles
// ------------------------------------------------------------------
// Un index sur (a) ne sert plus à rien dès qu'il en existe un sur
// (a, b) : MySQL utilise le second partout où il aurait pris le
// premier. Le garder coûte de l'écriture à chaque INSERT, sur les
// tables qui en font le plus. On les signale sans les supprimer :
// effacer un index est une décision, pas un effet de bord.
$signatures = [];
$parIndex = [];
$st = $pdo->prepare('SELECT table_name, index_name, non_unique, seq_in_index, column_name
                     FROM information_schema.statistics WHERE table_schema = ?
                     ORDER BY table_name, index_name, seq_in_index');
$st->execute([$base]);
foreach ($st->fetchAll(PDO::FETCH_NUM) as [$t, $i, $nu, $s, $c]) {
    $parIndex[strtolower($t)][$i]['cols'][] = strtolower($c);
    $parIndex[strtolower($t)][$i]['unique'] = ((int) $nu === 0);
}

$redondants = [];
foreach ($parIndex as $t => $idx) {
    foreach ($idx as $nom => $d) {
        // Une clé unique ou primaire porte une contrainte, jamais redondante.
        if ($d['unique'] || strtoupper($nom) === 'PRIMARY') continue;
        $sig = implode(',', $d['cols']);
        foreach ($idx as $autre => $d2) {
            if ($autre === $nom || count($d2['cols']) <= count($d['cols'])) continue;
            if (implode(',', array_slice($d2['cols'], 0, count($d['cols']))) === $sig) {
                $redondants[] = [$t, $nom, $sig, $autre, implode(',', $d2['cols'])];
                break;
            }
        }
    }
}

if ($redondants) {
    echo "\nIndex devenus redondants — ils coûtent à l'écriture sans rien apporter\n";
    echo "en lecture. À supprimer une fois la migration validée :\n\n";
    foreach ($redondants as [$t, $nom, $sig, $autre, $sig2]) {
        echo sprintf("  ALTER TABLE `%s` DROP KEY `%s`;   -- (%s) déjà couvert par %s (%s)\n",
            $t, $nom, $sig, $autre, $sig2);
    }
    echo "\n";
}

if ($appliquer) {
    echo "Créés : $faits · déjà en place ou sans objet : $sautes · échecs : $echecs\n";
    echo $echecs ? "Relancer après correction : les index déjà créés seront sautés.\n"
                 : "Terminé.\n";
    exit($echecs ? 1 : 0);
}
echo "À créer : $aFaire · déjà en place ou sans objet : $sautes\n";
echo $aFaire
    ? "Relancer avec --appliquer pour les créer.\n"
    : "Rien à faire : tous les index sont là.\n";
exit(0);
