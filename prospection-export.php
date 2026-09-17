<?php
/**
 * prospection-export.php — Sort la prospection en Excel.
 * ------------------------------------------------------------------
 * Trois usages, selon `?quoi=` :
 *
 *   liste   (défaut) : les fiches, en respectant le filtre et la recherche
 *                      affichés à l'écran. Ce qu'on voit est ce qu'on exporte.
 *   modele           : un classeur vide avec les bons en-têtes et deux lignes
 *                      d'exemple, à remplir puis réimporter.
 *   historique       : quand chaque action a été faite, une ligne par geste.
 *
 * Le fichier sort en .xlsx et non en CSV : un CSV ouvert par double-clic
 * sous Excel français découpe mal les colonnes une fois sur deux, et
 * transforme « 0601020304 » en « 601020304 ».
 * ------------------------------------------------------------------
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/xlsx-helper.php';

require_login();
$user = current_user();
if (empty($user['org_id'])) { http_response_code(403); die('Aucune association rattachée à ce compte.'); }
$org_id = (int) $user['org_id'];

// Même porte que la page elle-même.
$role = (string) ($user['role'] ?? '');
$autorise = in_array($role, ['admin', 'coordinator'], true);
if (!$autorise && function_exists('can')) $autorise = can('access_marketing');
if (!$autorise) { http_response_code(403); die('Accès refusé.'); }

$quoi = (string) ($_GET['quoi'] ?? 'liste');
$jour = date('Y-m-d');

/** Natures de prospect. Doit rester aligné sur PROSP_TYPES de prospection.php. */
$TYPES = ['asso' => 'Association', 'entreprise' => 'Entreprise',
          'collectiv' => 'Collectivité', 'autre' => 'Autre'];

/** Nom de fichier sans accent ni espace : certains navigateurs les massacrent. */
function exp_nom(string $base, string $jour): string
{
    $s = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $base) ?: $base;
    $s = preg_replace('/[^A-Za-z0-9]+/', '-', $s);
    return trim($s, '-') . '-' . $jour . '.xlsx';
}

/** Date lisible, ou vide. Jamais « 01/01/1970 » sur un NULL. */
function exp_date($v, bool $avecHeure = true): string
{
    if (!$v) return '';
    $t = strtotime((string) $v);
    if (!$t) return '';
    return date($avecHeure ? 'd/m/Y H:i' : 'd/m/Y', $t);
}

// ------------------------------------------------------------------
// Le modèle à remplir
// ------------------------------------------------------------------
if ($quoi === 'modele') {
    $entetes = ['Prénom', 'Nom', 'Type', 'Salariés', 'Code postal', 'Ville', 'Téléphone', 'E-mail', 'Notes'];
    $lignes = [array_map(fn($h) => ['v' => $h, 'entete' => true], $entetes)];
    $lignes[] = ['Amélie', 'Rousseau', 'Association', '3', '91000', 'Évry-Courcouronnes',
                 '06 01 02 03 04', 'amelie@exemple.fr', 'Rencontrée au forum des assos'];
    $lignes[] = ['Karim', 'Benali', 'Entreprise', 'non', '75011', 'Paris',
                 '07 88 99 00 11', 'karim@exemple.fr', ''];
    $lignes[] = [];
    $lignes[] = [['v' => 'Remplacez les deux lignes d’exemple par vos contacts, puis importez ce fichier.', 'entete' => false]];
    $lignes[] = ['L’ordre des colonnes peut changer : l’import lit les en-têtes. Seule la première ligne doit les contenir.'];
    $lignes[] = ['Une fiche sans nom ni téléphone est ignorée — elle ne serait pas rappelable.'];
    $lignes[] = ['Type accepte Association, Entreprise, Collectivité ou Autre. Le département se déduit du code postal.'];
    $lignes[] = ['Salariés accepte oui, non, ou directement un nombre. Laissée vide, la fiche reste « à qualifier ».'];

    ak_tableur_envoyer(
        ak_xlsx_octets($lignes, 'Modèle', [16, 18, 14, 10, 12, 20, 20, 30, 46]),
        exp_nom('modele-prospection', $jour)
    );
}

// ------------------------------------------------------------------
// L'historique
// ------------------------------------------------------------------
if ($quoi === 'historique') {
    $LIB = [
        'create' => 'Fiche créée', 'edit' => 'Fiche modifiée',
        'call_yes' => 'Marquée appelée', 'call_no' => 'Appel annulé',
        'mail_yes' => 'E-mail envoyé', 'mail_no' => 'E-mail annulé',
        'callback_set' => 'Rappel programmé', 'callback_clear' => 'Rappel retiré',
        'note' => 'Note', 'delete' => 'Fiche supprimée', 'restore' => 'Fiche restaurée',
        'import' => 'Importée depuis un tableur',
    ];
    $entetes = ['Date', 'Heure', 'Contact', 'Téléphone', 'Action', 'Détail', 'Par'];
    $lignes = [array_map(fn($h) => ['v' => $h, 'entete' => true], $entetes)];

    $st = $pdo->prepare("SELECT e.*, p.prenom, p.nom, p.telephone,
                                TRIM(CONCAT_WS(' ', u.first_name, u.last_name)) AS auteur
                         FROM asso_prospection_events e
                         LEFT JOIN asso_prospection p ON p.id = e.prospect_id
                         LEFT JOIN users u ON u.id = e.user_id
                         WHERE e.org_id = ?
                         ORDER BY e.id DESC LIMIT 20000");
    $st->execute([$org_id]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $e) {
        $t = strtotime((string) $e['created_at']);
        $lignes[] = [
            $t ? date('d/m/Y', $t) : '',
            $t ? date('H:i', $t) : '',
            trim(($e['prenom'] ?? '') . ' ' . ($e['nom'] ?? '')),
            (string) ($e['telephone'] ?? ''),
            $LIB[$e['type']] ?? (string) $e['type'],
            (string) ($e['detail'] ?? ''),
            (string) ($e['auteur'] ?? ''),
        ];
    }

    ak_tableur_envoyer(
        ak_xlsx_octets($lignes, 'Historique', [12, 8, 26, 20, 24, 40, 22]),
        exp_nom('prospection-historique', $jour)
    );
}

// ------------------------------------------------------------------
// La liste, filtrée comme à l'écran
// ------------------------------------------------------------------
$filtre = (string) ($_GET['f'] ?? 'tous');
$q      = trim((string) ($_GET['q'] ?? ''));

$where  = ['p.org_id = ?', 'p.deleted_at IS NULL'];
$params = [$org_id];

if ($filtre === 'a_appeler')      $where[] = 'p.called = 0';
elseif ($filtre === 'appeles')    $where[] = 'p.called = 1';
elseif ($filtre === 'emails')     $where[] = 'p.emailed = 1';
elseif ($filtre === 'jamais')     $where[] = 'p.called = 0 AND p.emailed = 0';
elseif ($filtre === 'a_rappeler') $where[] = 'p.callback_at IS NOT NULL';
elseif ($filtre === 'en_retard')  $where[] = 'p.callback_at IS NOT NULL AND p.callback_at <= NOW()';
elseif ($filtre === 'avec_tel')   $where[] = "p.telephone <> ''";
elseif ($filtre === 'avec_email') $where[] = "p.email <> ''";
elseif ($filtre === 'email_seul') $where[] = "p.email <> '' AND p.telephone = ''";

// Les mêmes axes qu'à l'écran : ce qu'on voit est ce qu'on exporte.
$lotVu = (int) ($_GET['import'] ?? 0);
if ($lotVu > 0) { $where[] = 'p.import_id = ?'; $params[] = $lotVu; }

$fType = preg_replace('/[^a-z]/', '', strtolower((string) ($_GET['type'] ?? '')));
if ($fType !== '' && isset($TYPES[$fType])) { $where[] = 'p.type = ?'; $params[] = $fType; }

$fDept = mb_substr(strtoupper(preg_replace('/[^0-9A-Za-z]/', '', (string) ($_GET['dept'] ?? ''))), 0, 3);
if ($fDept !== '') { $where[] = 'p.departement = ?'; $params[] = $fDept; }

// Le même filtre que la page : l'export doit rendre ce qui est affiché,
// sinon on croit exporter sa sélection et on repart avec tout.
$fSal = (string) ($_GET['sal'] ?? '');
if ($fSal === 'oui')         $where[] = 'p.salaries = 1';
elseif ($fSal === 'non')     $where[] = 'p.salaries = 0';
elseif ($fSal === 'inconnu') $where[] = 'p.salaries IS NULL';

if ($q !== '') {
    $where[] = '(p.nom LIKE ? OR p.prenom LIKE ? OR p.telephone LIKE ? OR p.email LIKE ?)';
    $like = '%' . $q . '%';
    array_push($params, $like, $like, $like, $like);
}

$sql = "SELECT p.*, TRIM(CONCAT_WS(' ', u.first_name, u.last_name)) AS modifie_par
        FROM asso_prospection p
        LEFT JOIN users u ON u.id = p.updated_by
        WHERE " . implode(' AND ', $where) . "
        ORDER BY (p.callback_at IS NOT NULL AND p.callback_at <= NOW()) DESC,
                 p.callback_at IS NULL, p.callback_at ASC, p.called ASC, p.id DESC
        LIMIT 20000";
$st = $pdo->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

// Libellés des codes QR, en requête tolérante : un export ne doit pas
// tomber parce que la migration des QR n'est pas passée.
$qr = [];
try {
    $s2 = $pdo->prepare("SELECT id, label FROM asso_qr_codes WHERE org_id = ?");
    $s2->execute([$org_id]);
    foreach ($s2->fetchAll(PDO::FETCH_ASSOC) as $r) $qr[(int) $r['id']] = (string) $r['label'];
} catch (Throwable $e) { /* sans importance */ }

// L'historique des rappels : combien par fiche, et l'issue du dernier.
// Requête tolérante et groupée — une par fiche ferait 20 000 allers-retours.
$relances = [];
try {
    $s4 = $pdo->prepare("SELECT r.prospect_id, COUNT(*) n,
                SUBSTRING_INDEX(GROUP_CONCAT(r.issue ORDER BY r.fait_at DESC, r.id DESC), ',', 1) AS derniere
              FROM asso_prospection_rappels r
             WHERE r.org_id = ? AND r.fait_at IS NOT NULL
             GROUP BY r.prospect_id");
    $s4->execute([$org_id]);
    $LIB_ISSUE = ['repondu' => 'A répondu', 'absent' => 'Pas de réponse',
                  'reporte' => 'À rappeler plus tard', 'refus' => 'Ne veut plus être appelé'];
    foreach ($s4->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $relances[(int) $r['prospect_id']] = [
            'n'     => (int) $r['n'],
            'issue' => $LIB_ISSUE[(string) $r['derniere']] ?? '',
        ];
    }
} catch (Throwable $e) { /* migration des rappels pas encore passée */ }

// Le nom du fichier d'origine, pour que « Provenance » dise quelque chose.
$lots = [];
try {
    $s3 = $pdo->prepare("SELECT id, fichier FROM asso_prospection_imports WHERE org_id = ?");
    $s3->execute([$org_id]);
    foreach ($s3->fetchAll(PDO::FETCH_ASSOC) as $r) $lots[(int) $r['id']] = (string) $r['fichier'];
} catch (Throwable $e) { /* migration des imports pas encore passée */ }

$entetes = ['Prénom', 'Nom', 'Type', 'Salariés', 'Nb salariés',
            'Code postal', 'Ville', 'Département',
            'Téléphone', 'E-mail', 'Appelé', 'Date d’appel',
            'E-mail envoyé', 'Date d’e-mail', 'À rappeler le',
            'Relances faites', 'Dernière issue', 'Provenance',
            'Notes', 'Créée le', 'Modifiée le', 'Modifiée par'];
$lignes = [array_map(fn($h) => ['v' => $h, 'entete' => true], $entetes)];

foreach ($rows as $r) {
    $src = $r['source'] ?? 'manuel';
    if ($src === 'qr') {
        $provenance = 'Code QR'
            . (!empty($r['qr_id']) && isset($qr[(int) $r['qr_id']]) ? ' — ' . $qr[(int) $r['qr_id']] : '');
    } elseif ($src === 'import') {
        $provenance = 'Import'
            . (!empty($r['import_id']) && isset($lots[(int) $r['import_id']]) ? ' — ' . $lots[(int) $r['import_id']] : '');
    } else {
        $provenance = 'Saisie manuelle';
    }
    $lignes[] = [
        (string) $r['prenom'],
        (string) $r['nom'],
        $TYPES[$r['type'] ?? ''] ?? '',
        // Vide et non « Non » quand la question n'a pas été posée : sur un
        // export qu'on retravaille, « Non » partout ferait passer pour
        // vérifiées des fiches qui ne l'ont jamais été.
        $r['salaries'] === null ? '' : ((int) $r['salaries'] === 1 ? 'Oui' : 'Non'),
        $r['nb_salaries'] === null ? '' : (int) $r['nb_salaries'],
        // Forcés en texte : Excel avale le zéro initial d'un code postal
        // comme celui d'un numéro en 06.
        (string) ($r['code_postal'] ?? ''),
        (string) ($r['ville'] ?? ''),
        (string) ($r['departement'] ?? ''),
        (string) $r['telephone'],
        (string) $r['email'],
        !empty($r['called'])  ? 'Oui' : 'Non',
        exp_date($r['called_at']),
        !empty($r['emailed']) ? 'Oui' : 'Non',
        exp_date($r['emailed_at']),
        exp_date($r['callback_at']),
        // Combien de fois on a déjà appelé, et ce que ça a donné la
        // dernière fois : sur un fichier qu'on retravaille hors ligne,
        // c'est ce qui dit s'il faut insister ou laisser tomber.
        (int) ($relances[(int) $r['id']]['n'] ?? 0),
        (string) ($relances[(int) $r['id']]['issue'] ?? ''),
        $provenance,
        (string) ($r['notes'] ?? ''),
        exp_date($r['created_at']),
        exp_date($r['updated_at']),
        (string) ($r['modifie_par'] ?? ''),
    ];
}

if (count($lignes) === 1) {
    $lignes[] = [['v' => 'Aucune fiche pour ce filtre.', 'entete' => false]];
}

ak_tableur_envoyer(
    ak_xlsx_octets($lignes, 'Prospection', [15, 17, 14, 10, 11, 12, 18, 12, 18, 28, 9, 17, 14, 17, 17, 14, 20, 24, 40, 17, 17, 22]),
    exp_nom('prospection' . ($filtre !== 'tous' ? '-' . $filtre : ''), $jour)
);
