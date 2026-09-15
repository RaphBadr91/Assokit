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
 *   historique       : chaque geste daté et signé, une ligne par événement.
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
    $entetes = ['Prénom', 'Nom', 'Téléphone', 'E-mail', 'Notes'];
    $lignes = [array_map(fn($h) => ['v' => $h, 'entete' => true], $entetes)];
    $lignes[] = ['Amélie', 'Rousseau', '06 01 02 03 04', 'amelie@exemple.fr', 'Rencontrée au forum des assos'];
    $lignes[] = ['Karim', 'Benali', '07 88 99 00 11', 'karim@exemple.fr', ''];
    $lignes[] = [];
    $lignes[] = [['v' => 'Remplacez les deux lignes d’exemple par vos contacts, puis importez ce fichier.', 'entete' => false]];
    $lignes[] = ['L’ordre des colonnes peut changer : l’import lit les en-têtes. Seule la première ligne doit les contenir.'];
    $lignes[] = ['Une fiche sans nom ni téléphone est ignorée — elle ne serait pas rappelable.'];

    ak_tableur_envoyer(
        ak_xlsx_octets($lignes, 'Modèle', [16, 18, 20, 30, 46]),
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

$entetes = ['Prénom', 'Nom', 'Téléphone', 'E-mail', 'Appelé', 'Date d’appel',
            'E-mail envoyé', 'Date d’e-mail', 'À rappeler le', 'Provenance',
            'Notes', 'Créée le', 'Modifiée le', 'Modifiée par'];
$lignes = [array_map(fn($h) => ['v' => $h, 'entete' => true], $entetes)];

foreach ($rows as $r) {
    $provenance = ($r['source'] ?? 'manuel') === 'qr'
        ? 'Code QR' . (!empty($r['qr_id']) && isset($qr[(int) $r['qr_id']]) ? ' — ' . $qr[(int) $r['qr_id']] : '')
        : 'Saisie manuelle';
    $lignes[] = [
        (string) $r['prenom'],
        (string) $r['nom'],
        // Forcé en texte : sinon Excel avale le zéro initial du 06.
        (string) $r['telephone'],
        (string) $r['email'],
        !empty($r['called'])  ? 'Oui' : 'Non',
        exp_date($r['called_at']),
        !empty($r['emailed']) ? 'Oui' : 'Non',
        exp_date($r['emailed_at']),
        exp_date($r['callback_at']),
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
    ak_xlsx_octets($lignes, 'Prospection', [15, 17, 18, 28, 9, 17, 14, 17, 17, 24, 40, 17, 17, 22]),
    exp_nom('prospection' . ($filtre !== 'tous' ? '-' . $filtre : ''), $jour)
);
