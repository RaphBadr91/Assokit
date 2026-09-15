<?php
/**
 * prospection.php — Prospection téléphonique (association).
 * --------------------------------------------------------------
 * Une fiche par personne à contacter : nom, prénom, téléphone, e-mail.
 * Trois gestes, tous horodatés par le serveur — « Appel : oui/non »,
 * « E-mail : oui/non », « À rappeler le… » — plus un historique de tout ce
 * qui a été fait, par qui et quand.
 *
 * Deux canaux séparés plutôt qu'un état « contacté » : sur un même prospect
 * on appelle, on tombe sur un répondeur, on envoie un e-mail, on rappelle.
 * Savoir lequel a déjà servi change ce qu'on fait au coup suivant.
 *
 * Les données sont cloisonnées par organisation : chaque requête filtre sur
 * org_id, y compris les écritures, pour qu'un identifiant deviné dans un
 * formulaire ne donne pas accès au fichier d'une autre association.
 *
 * Sécurité :
 *   - connexion requise, organisation obligatoire
 *   - écritures en POST + jeton CSRF, puis redirection (pas de renvoi de
 *     formulaire au rechargement)
 * --------------------------------------------------------------
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes-layout.php';

require_login();
$user = current_user();
if (empty($user['org_id'])) { http_response_code(403); die('Aucune association rattachée à ce compte.'); }
$org_id = (int) $user['org_id'];
$uid    = (int) $user['id'];

$role = (string) ($user['role'] ?? '');
$allowed = in_array($role, ['admin', 'coordinator'], true);
if (!$allowed && function_exists('can')) $allowed = can('access_marketing');
if (!$allowed) { http_response_code(403); die('Accès refusé.'); }

if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$csrf = $_SESSION['csrf_token'];

$flash = $_SESSION['flash_prospection'] ?? null;
unset($_SESSION['flash_prospection']);

/** Journalise un fait. Best-effort : un historique muet ne doit jamais
 *  empêcher l'action elle-même d'aboutir. */
function prosp_log(PDO $pdo, int $org_id, int $pid, int $uid, string $type, string $detail = ''): void {
    try {
        $pdo->prepare("INSERT INTO asso_prospection_events (org_id, prospect_id, user_id, type, detail)
                       VALUES (?, ?, ?, ?, ?)")
            ->execute([$org_id, $pid, $uid, $type, mb_substr($detail, 0, 255)]);
    } catch (Throwable $e) { /* sans importance */ }
}

/** Normalise une saisie datetime-local ('' → null). */
function prosp_dt(?string $v): ?string {
    $v = trim((string) $v);
    if ($v === '') return null;
    $t = strtotime(str_replace('T', ' ', $v));
    return $t ? date('Y-m-d H:i:00', $t) : null;
}

$migration_missing = false;
$migration_manquantes = [];   // fichiers .sql à passer, dans l'ordre
$migration_erreur = '';       // message SQL brut, si la cause est ailleurs

/**
 * Quelles migrations manquent réellement ?
 *
 * On interroge le schéma au lieu de lire le message d'erreur : celui-ci
 * change selon la version de MariaDB et la langue du serveur, et l'ancienne
 * version de cette page désignait la première migration quelle que soit la
 * panne — y compris quand la table existait mais qu'il manquait seulement
 * les colonnes du canal e-mail. On envoyait alors l'utilisateur relancer
 * une migration déjà passée, indéfiniment.
 */
function pr_migrations_manquantes(PDO $pdo): array
{
    // Un seul script répare tout : il crée les tables manquantes et reprend,
    // le cas échéant, celles de l'ancien nom `asso_prospects` — lequel
    // appartenait déjà à la prospection du fondateur.
    $reparation = ['2026-09-14-prospection-tables-dediees.php'];

    try {
        $pdo->query("SELECT 1 FROM asso_prospection LIMIT 1")->closeCursor();
    } catch (Throwable $e) {
        return $reparation;
    }
    try {
        $pdo->query("SELECT emailed, emailed_at FROM asso_prospection LIMIT 1")->closeCursor();
    } catch (Throwable $e) {
        return $reparation;
    }
    try {
        $pdo->query("SELECT 1 FROM asso_prospection_events LIMIT 1")->closeCursor();
    } catch (Throwable $e) {
        return $reparation;
    }
    return [];
}

/** Réduit un intitulé de colonne à sa forme comparable : sans accent, sans ponctuation. */
function prosp_cle(string $s): string
{
    $s = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s) ?: $s;
    return preg_replace('/[^a-z0-9]/', '', strtolower($s));
}

/**
 * Remet un numéro français d'aplomb.
 *
 * Excel stocke volontiers « 0601020304 » comme le nombre 601020304 et perd le
 * zéro. Un fichier venu d'ailleurs écrit « +33 6 01 02 03 04 ». Les deux
 * doivent donner le même numéro, sinon le dédoublonnage ne sert à rien.
 */
function prosp_tel(string $brut): string
{
    $t = preg_replace('/[^0-9+]/', '', $brut);
    if ($t === '') return '';
    if (str_starts_with($t, '+33'))  $t = '0' . substr($t, 3);
    elseif (str_starts_with($t, '0033')) $t = '0' . substr($t, 4);
    // Neuf chiffres commençant par 1-9 : c'est un numéro amputé de son zéro.
    if (strlen($t) === 9 && $t[0] !== '0' && ctype_digit($t)) $t = '0' . $t;
    return $t;
}

/** Natures de prospect proposées. La clé est ce qui est stocké. */
const PROSP_TYPES = [
    'asso'       => 'Association',
    'entreprise' => 'Entreprise',
    'collectiv'  => 'Collectivité',
    'autre'      => 'Autre',
];

/** Normalise une nature saisie ou importée ('' si non reconnue). */
function prosp_type(string $brut): string
{
    $k = prosp_cle($brut);
    if ($k === '') return '';
    if (isset(PROSP_TYPES[$k])) return $k;
    $synonymes = [
        'asso' => ['association', 'associations', 'assoc', 'asso', 'loi1901', '1901', 'club'],
        'entreprise' => ['entreprise', 'entreprises', 'societe', 'societes', 'tpe', 'pme',
                         'sarl', 'sas', 'eurl', 'company', 'business', 'pro'],
        'collectiv' => ['collectivite', 'collectivites', 'mairie', 'mairies', 'commune',
                        'communes', 'ville', 'villes', 'municipal', 'servicemunicipal',
                        'epci', 'departement', 'region', 'ccas'],
        'autre' => ['autre', 'autres', 'divers'],
    ];
    foreach ($synonymes as $type => $mots) {
        if (in_array($k, $mots, true)) return $type;
    }
    return '';
}

/**
 * Département d'un code postal français.
 *
 * Deux exceptions que la simple troncature à deux caractères rate : la
 * Corse, dont les codes en 20 se répartissent entre 2A et 2B, et
 * l'outre-mer, dont le département tient sur trois chiffres.
 */
function prosp_departement(string $cp): string
{
    $cp = preg_replace('/[^0-9A-Za-z]/', '', $cp);
    if (strlen($cp) < 4) return '';
    $cp = str_pad(substr($cp, 0, 5), 5, '0', STR_PAD_LEFT);
    if (!ctype_digit($cp)) return '';

    $deux = substr($cp, 0, 2);
    if ($deux === '97' || $deux === '98') return substr($cp, 0, 3);
    if ($deux === '20') {
        // Corse-du-Sud jusqu'à 20190, Haute-Corse au-delà.
        return ((int) $cp) <= 20190 ? '2A' : '2B';
    }
    return $deux;
}

/** Forme canonique servant à repérer les doublons. */
function prosp_empreinte_tel(string $tel): string
{
    return preg_replace('/[^0-9]/', '', prosp_tel($tel));
}

/**
 * Empreinte de repli, quand la fiche n'a ni téléphone ni e-mail.
 *
 * Sans elle, une ligne réduite à un nom n'a aucune clé de comparaison et
 * le même fichier réimporté la recrée à chaque fois. Le nom est un
 * repère imparfait — deux Jean Martin existent — mais dans un même
 * fichier de prospection le doublon est bien plus probable que l'homonyme.
 */
function prosp_empreinte_nom(string $prenom, string $nom): string
{
    return prosp_cle($prenom . '|' . $nom);
}

// ── Écritures ───────────────────────────────────────────────────────────────
// Un envoi de fichier trop lourd arrive avec $_POST vide : PHP a jeté le
// corps de la requête. Sans ce test, l'utilisateur verrait « Jeton CSRF
// invalide », message qui ne dit rien de la vraie cause.
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'
    && !$_POST && !$_FILES && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    $_SESSION['flash_prospection'] = 'Fichier trop volumineux (limite du serveur : '
        . ini_get('post_max_size') . '). Découpez-le ou enregistrez-le en CSV.';
    header('Location: /prospection'); exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!hash_equals($csrf, (string) ($_POST['csrf_token'] ?? ''))) {
        http_response_code(419); exit('Jeton CSRF invalide.');
    }
    $action = (string) ($_POST['action'] ?? '');
    $pid    = (int) ($_POST['id'] ?? 0);
    $msg    = null;

    try {
        if ($action === 'create') {
            $prenom = trim((string) ($_POST['prenom'] ?? ''));
            $nom    = trim((string) ($_POST['nom'] ?? ''));
            $tel    = trim((string) ($_POST['telephone'] ?? ''));
            $email  = trim((string) ($_POST['email'] ?? ''));

            // Un prospect sans nom ni téléphone n'est pas rappelable : autant
            // le refuser tout de suite plutôt que de polluer la liste.
            if ($prenom === '' && $nom === '' && $tel === '') {
                $msg = "Renseignez au moins un nom ou un numéro.";
            } else {
                $st = $pdo->prepare("INSERT INTO asso_prospection
                        (org_id, prenom, nom, telephone, email, created_by, updated_by, updated_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, NOW())");
                $st->execute([$org_id, $prenom, $nom, $tel, $email, $uid, $uid]);
                prosp_log($pdo, $org_id, (int) $pdo->lastInsertId(), $uid, 'create', trim("$prenom $nom"));
                $msg = "Prospect ajouté.";
            }

        } elseif ($action === 'import') {
            require_once __DIR__ . '/xlsx-helper.php';
            $f = $_FILES['fichier'] ?? null;

            if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                $codes = [
                    UPLOAD_ERR_INI_SIZE   => 'Fichier trop volumineux pour le serveur.',
                    UPLOAD_ERR_FORM_SIZE  => 'Fichier trop volumineux.',
                    UPLOAD_ERR_PARTIAL    => 'Envoi interrompu, réessayez.',
                    UPLOAD_ERR_NO_FILE    => 'Choisissez un fichier avant d’importer.',
                    UPLOAD_ERR_NO_TMP_DIR => 'Dossier temporaire absent sur le serveur.',
                    UPLOAD_ERR_CANT_WRITE => 'Écriture impossible sur le serveur.',
                ];
                $msg = $codes[$f['error'] ?? UPLOAD_ERR_NO_FILE] ?? 'Envoi impossible.';

            } elseif (!is_uploaded_file($f['tmp_name'])) {
                $msg = 'Envoi invalide.';

            } else {
                $lignes = ak_tableur_lire($f['tmp_name'], (string) $f['name'], 5000);

                // Les colonnes sont reconnues par leur intitulé, pas par leur
                // position : un fichier venu d'ailleurs range rarement dans
                // le même ordre, et exiger un ordre ferait échouer l'import
                // sans que l'utilisateur comprenne pourquoi.
                $synonymes = [
                    'prenom'    => ['prenom', 'firstname', 'first', 'prenoms', 'givenname'],
                    'nom'       => ['nom', 'lastname', 'last', 'name', 'nomdefamille', 'surname'],
                    'telephone' => ['telephone', 'tel', 'phone', 'portable', 'mobile', 'numero', 'telmobile'],
                    'email'     => ['email', 'mail', 'courriel', 'adresseemail', 'emailaddress'],
                    'notes'     => ['notes', 'note', 'commentaire', 'commentaires', 'remarque', 'observations'],
                    'type'      => ['type', 'nature', 'categorie', 'category', 'structure', 'formejuridique'],
                    'cp'        => ['codepostal', 'cp', 'zip', 'zipcode', 'postal', 'postcode'],
                    'ville'     => ['ville', 'commune', 'city', 'localite'],
                    'dept'      => ['departement', 'dept', 'dpt', 'department'],
                ];

                $map = [];
                $debut = 0;
                foreach (($lignes[0] ?? []) as $i => $cel) {
                    $k = prosp_cle((string) $cel);
                    if ($k === '') continue;
                    foreach ($synonymes as $champ => $mots) {
                        if (in_array($k, $mots, true) && !isset($map[$champ])) { $map[$champ] = $i; break; }
                    }
                }
                if ($map) {
                    $debut = 1;  // la première ligne était l'en-tête
                } else {
                    // Aucun intitulé reconnu : on suppose l'ordre du modèle et
                    // on lit dès la première ligne, qui contient des données.
                    $map = ['prenom' => 0, 'nom' => 1, 'telephone' => 2, 'email' => 3, 'notes' => 4];
                }

                // Ce qui est déjà en base, pour ne pas créer de doublon.
                $vus = ['tel' => [], 'mail' => [], 'nom' => []];
                $st = $pdo->prepare("SELECT prenom, nom, telephone, email FROM asso_prospection
                                     WHERE org_id = ? AND deleted_at IS NULL");
                $st->execute([$org_id]);
                foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $e = prosp_empreinte_tel((string) $r['telephone']);
                    if ($e !== '') $vus['tel'][$e] = true;
                    $m = mb_strtolower(trim((string) $r['email']));
                    if ($m !== '') $vus['mail'][$m] = true;
                    if ($e === '' && $m === '') {
                        $n = prosp_empreinte_nom((string) $r['prenom'], (string) $r['nom']);
                        if ($n !== '') $vus['nom'][$n] = true;
                    }
                }

                $ajoutes = 0; $doublons = 0; $vides = 0;
                $lire = fn(array $l, string $champ) => isset($map[$champ], $l[$map[$champ]])
                    ? trim((string) $l[$map[$champ]]) : '';

                // Nature appliquée aux lignes qui n'en portent pas : « ce
                // fichier, ce sont des mairies ». C'est le cas courant, un
                // annuaire ne mélange presque jamais les genres.
                $typeDefaut = prosp_type((string) ($_POST['type_defaut'] ?? ''));

                $ins = $pdo->prepare("INSERT INTO asso_prospection
                        (org_id, prenom, nom, telephone, email, notes, type,
                         code_postal, ville, departement, source, import_id,
                         created_by, updated_by, updated_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'import', ?, ?, ?, NOW())");

                $pdo->beginTransaction();
                try {
                    // Le lot est créé avant les fiches : c'est lui qui porte le
                    // nom du fichier, et c'est par lui qu'on pourra tout retirer
                    // si l'import s'avère hors sujet.
                    $pdo->prepare("INSERT INTO asso_prospection_imports
                                   (org_id, fichier, lignes, type_defaut, created_by)
                                   VALUES (?, ?, ?, ?, ?)")
                        ->execute([$org_id, mb_substr((string) $f['name'], 0, 255),
                                   max(0, count($lignes) - $debut), $typeDefaut, $uid]);
                    $lot = (int) $pdo->lastInsertId();

                    for ($i = $debut; $i < count($lignes); $i++) {
                        $l = $lignes[$i];
                        if (!$l) { $vides++; continue; }

                        $prenom = mb_substr($lire($l, 'prenom'), 0, 120);
                        $nom    = mb_substr($lire($l, 'nom'), 0, 120);
                        $tel    = prosp_tel($lire($l, 'telephone'));
                        $email  = mb_substr($lire($l, 'email'), 0, 190);
                        $notes  = $lire($l, 'notes');

                        // Même règle que la saisie manuelle : sans nom ni
                        // numéro, la fiche ne sert à personne.
                        if ($prenom === '' && $nom === '' && $tel === '') { $vides++; continue; }

                        $eTel  = prosp_empreinte_tel($tel);
                        $eMail = mb_strtolower($email);
                        // Le nom ne sert de clé que faute de mieux : deux
                        // homonymes joignables restent deux fiches.
                        $eNom  = ($eTel === '' && $eMail === '')
                               ? prosp_empreinte_nom($prenom, $nom) : '';
                        if (($eTel !== '' && isset($vus['tel'][$eTel]))
                            || ($eMail !== '' && isset($vus['mail'][$eMail]))
                            || ($eNom !== '' && isset($vus['nom'][$eNom]))) {
                            $doublons++; continue;
                        }

                        $type  = prosp_type($lire($l, 'type')) ?: $typeDefaut;
                        $cp    = mb_substr(preg_replace('/\s+/', '', $lire($l, 'cp')), 0, 10);
                        $ville = mb_substr($lire($l, 'ville'), 0, 120);
                        // Le département vient du code postal ; une colonne
                        // « département » explicite reste prioritaire.
                        $dept  = mb_substr(strtoupper($lire($l, 'dept')), 0, 3) ?: prosp_departement($cp);

                        $ins->execute([$org_id, $prenom, $nom, mb_substr($tel, 0, 40),
                                       $email, $notes, $type, $cp, $ville, $dept,
                                       $lot, $uid, $uid]);
                        prosp_log($pdo, $org_id, (int) $pdo->lastInsertId(), $uid, 'import',
                                  trim("$prenom $nom") ?: $tel);
                        $ajoutes++;
                        if ($eTel !== '')  $vus['tel'][$eTel] = true;
                        if ($eMail !== '') $vus['mail'][$eMail] = true;
                        if ($eNom !== '')  $vus['nom'][$eNom] = true;
                    }
                    $pdo->prepare("UPDATE asso_prospection_imports
                                   SET ajoutes = ?, doublons = ?, ignorees = ?
                                   WHERE id = ? AND org_id = ?")
                        ->execute([$ajoutes, $doublons, $vides, $lot, $org_id]);
                    $pdo->commit();
                } catch (Throwable $e) {
                    $pdo->rollBack();
                    throw $e;
                }

                // Un compte rendu chiffré : « import terminé » laisserait
                // croire que les lignes ignorées ont été prises.
                $bouts = [$ajoutes . ' ' . ($ajoutes > 1 ? 'fiches ajoutées' : 'fiche ajoutée')];
                if ($doublons) $bouts[] = $doublons . ' ' . ($doublons > 1 ? 'doublons ignorés' : 'doublon ignoré');
                if ($vides)    $bouts[] = $vides . ' ' . ($vides > 1 ? 'lignes vides ou sans contact' : 'ligne vide ou sans contact');
                $msg = implode(' · ', $bouts) . '.';
            }

        } elseif (in_array($action, ['import_delete', 'import_restore', 'import_purge'], true)) {
            $lot = (int) ($_POST['import_id'] ?? 0);

            $st = $pdo->prepare("SELECT * FROM asso_prospection_imports WHERE id = ? AND org_id = ?");
            $st->execute([$lot, $org_id]);
            $info = $st->fetch(PDO::FETCH_ASSOC);

            if (!$info) {
                $msg = "Import introuvable.";
            } elseif ($role !== 'admin') {
                // Réservé à l'administrateur, et à lui seul. Retirer un import
                // efface le travail d'appel de toute l'équipe sur des centaines
                // de fiches : ce n'est pas une opération que l'on confie à
                // quiconque décroche le téléphone, fût-il l'auteur de l'import.
                $msg = "Seul un administrateur peut retirer un import.";
            } elseif ($action === 'import_delete') {
                // Un seul horodatage pour le lot et pour ses fiches. Deux
                // NOW() posés par deux requêtes peuvent tomber sur deux
                // secondes différentes, et la restauration — qui compare les
                // deux — ne retrouverait alors plus rien.
                $quand = date('Y-m-d H:i:s');
                $st = $pdo->prepare("UPDATE asso_prospection
                                     SET deleted_at = ?, updated_by = ?, updated_at = ?
                                     WHERE org_id = ? AND import_id = ? AND deleted_at IS NULL");
                $st->execute([$quand, $uid, $quand, $org_id, $lot]);
                $n = $st->rowCount();
                $pdo->prepare("UPDATE asso_prospection_imports SET deleted_at = ? WHERE id = ? AND org_id = ?")
                    ->execute([$quand, $lot, $org_id]);
                $_SESSION['flash_prospection_annuler'] = $lot;
                $msg = "Import « " . $info['fichier'] . " » retiré : "
                     . $n . ' ' . ($n > 1 ? 'fiches masquées' : 'fiche masquée') . '.';

            } elseif ($action === 'import_restore') {
                // Seules les fiches retirées avec le lot reviennent : une fiche
                // supprimée à la main avant cela doit le rester.
                $st = $pdo->prepare("UPDATE asso_prospection
                                     SET deleted_at = NULL, updated_by = ?, updated_at = NOW()
                                     WHERE org_id = ? AND import_id = ? AND deleted_at IS NOT NULL
                                       AND deleted_at = ?");
                $st->execute([$uid, $org_id, $lot, (string) $info['deleted_at']]);
                $n = $st->rowCount();
                $pdo->prepare("UPDATE asso_prospection_imports SET deleted_at = NULL WHERE id = ? AND org_id = ?")
                    ->execute([$lot, $org_id]);
                $msg = "Import « " . $info['fichier'] . " » restauré : "
                     . $n . ' ' . ($n > 1 ? 'fiches revenues' : 'fiche revenue') . '.';

            } else { // import_purge
                if (empty($info['deleted_at'])) {
                    $msg = "Retirez d'abord cet import : la suppression définitive ne s'applique qu'à un import déjà retiré.";
                } else {
                    $pdo->beginTransaction();
                    try {
                        // L'historique part avec les fiches : le conserver
                        // laisserait des événements pointant dans le vide.
                        $pdo->prepare("DELETE e FROM asso_prospection_events e
                                       JOIN asso_prospection p ON p.id = e.prospect_id
                                       WHERE p.org_id = ? AND p.import_id = ?")
                            ->execute([$org_id, $lot]);
                        $st = $pdo->prepare("DELETE FROM asso_prospection WHERE org_id = ? AND import_id = ?");
                        $st->execute([$org_id, $lot]);
                        $n = $st->rowCount();
                        $pdo->prepare("DELETE FROM asso_prospection_imports WHERE id = ? AND org_id = ?")
                            ->execute([$lot, $org_id]);
                        $pdo->commit();
                    } catch (Throwable $e) { $pdo->rollBack(); throw $e; }
                    $msg = "Import « " . $info['fichier'] . " » supprimé définitivement : "
                         . $n . ' ' . ($n > 1 ? 'fiches effacées' : 'fiche effacée') . '.';
                }
            }

        } elseif ($action === 'call' && $pid > 0) {
            // « Appelé : oui / non ». La date est posée par le serveur, jamais
            // saisie : c'est le seul moyen qu'elle reflète l'appel réel.
            $yes = ($_POST['value'] ?? '') === '1';
            $st = $pdo->prepare("UPDATE asso_prospection
                                 SET called = ?, called_at = " . ($yes ? 'NOW()' : 'NULL') . ",
                                     updated_by = ?, updated_at = NOW()
                                 WHERE id = ? AND org_id = ? AND deleted_at IS NULL");
            $st->execute([$yes ? 1 : 0, $uid, $pid, $org_id]);
            if ($st->rowCount() > 0) {
                prosp_log($pdo, $org_id, $pid, $uid, $yes ? 'call_yes' : 'call_no');
                $msg = $yes ? "Appel enregistré." : "Appel annulé.";
            }

        } elseif ($action === 'mail' && $pid > 0) {
            // Même principe que l'appel : la date vient du serveur. Un
            // canal distinct, parce que « appelé » et « relancé par e-mail »
            // n'appellent pas la même action suivante.
            $yes = ($_POST['value'] ?? '') === '1';
            $st = $pdo->prepare("UPDATE asso_prospection
                                 SET emailed = ?, emailed_at = " . ($yes ? 'NOW()' : 'NULL') . ",
                                     updated_by = ?, updated_at = NOW()
                                 WHERE id = ? AND org_id = ? AND deleted_at IS NULL");
            $st->execute([$yes ? 1 : 0, $uid, $pid, $org_id]);
            if ($st->rowCount() > 0) {
                prosp_log($pdo, $org_id, $pid, $uid, $yes ? 'mail_yes' : 'mail_no');
                $msg = $yes ? "E-mail enregistré." : "E-mail annulé.";
            }

        } elseif ($action === 'callback' && $pid > 0) {
            $when = prosp_dt($_POST['callback_at'] ?? '');
            $st = $pdo->prepare("UPDATE asso_prospection SET callback_at = ?, updated_by = ?, updated_at = NOW()
                                 WHERE id = ? AND org_id = ? AND deleted_at IS NULL");
            $st->execute([$when, $uid, $pid, $org_id]);
            if ($st->rowCount() > 0) {
                prosp_log($pdo, $org_id, $pid, $uid, $when ? 'callback_set' : 'callback_clear',
                          $when ? date('d/m/Y à H:i', strtotime($when)) : '');
                $msg = $when ? "Rappel programmé le " . date('d/m/Y à H:i', strtotime($when)) . "." : "Rappel retiré.";
            }

        } elseif ($action === 'edit' && $pid > 0) {
            $st = $pdo->prepare("SELECT prenom, nom, telephone, email, notes FROM asso_prospection
                                 WHERE id = ? AND org_id = ? AND deleted_at IS NULL LIMIT 1");
            $st->execute([$pid, $org_id]);
            $old = $st->fetch(PDO::FETCH_ASSOC);
            if ($old) {
                $new = [
                    'prenom'    => trim((string) ($_POST['prenom'] ?? '')),
                    'nom'       => trim((string) ($_POST['nom'] ?? '')),
                    'telephone' => trim((string) ($_POST['telephone'] ?? '')),
                    'email'     => trim((string) ($_POST['email'] ?? '')),
                    'notes'     => trim((string) ($_POST['notes'] ?? '')),
                ];
                // On nomme les champs réellement modifiés : « fiche modifiée »
                // n'apprend rien à celui qui relira l'historique dans un mois.
                $labels = ['prenom' => 'prénom', 'nom' => 'nom', 'telephone' => 'téléphone',
                           'email' => 'e-mail', 'notes' => 'notes'];
                $changed = [];
                foreach ($new as $k => $v) if ((string) $old[$k] !== $v) $changed[] = $labels[$k];

                $pdo->prepare("UPDATE asso_prospection
                               SET prenom = ?, nom = ?, telephone = ?, email = ?, notes = ?,
                                   updated_by = ?, updated_at = NOW()
                               WHERE id = ? AND org_id = ?")
                    ->execute([$new['prenom'], $new['nom'], $new['telephone'], $new['email'],
                               $new['notes'], $uid, $pid, $org_id]);
                if ($changed) prosp_log($pdo, $org_id, $pid, $uid, 'edit', implode(', ', $changed));
                $msg = $changed ? "Fiche mise à jour (" . implode(', ', $changed) . ")." : "Aucun changement.";
            }

        } elseif ($action === 'delete' && $pid > 0) {
            $st = $pdo->prepare("UPDATE asso_prospection SET deleted_at = NOW(), updated_by = ?, updated_at = NOW()
                                 WHERE id = ? AND org_id = ? AND deleted_at IS NULL");
            $st->execute([$uid, $pid, $org_id]);
            if ($st->rowCount() > 0) { prosp_log($pdo, $org_id, $pid, $uid, 'delete'); $msg = "Prospect supprimé."; }

        } elseif ($action === 'restore' && $pid > 0) {
            $st = $pdo->prepare("UPDATE asso_prospection SET deleted_at = NULL, updated_by = ?, updated_at = NOW()
                                 WHERE id = ? AND org_id = ? AND deleted_at IS NOT NULL");
            $st->execute([$uid, $pid, $org_id]);
            if ($st->rowCount() > 0) { prosp_log($pdo, $org_id, $pid, $uid, 'restore'); $msg = "Prospect restauré."; }
        }
    } catch (Throwable $e) {
        $msg = "Échec : " . $e->getMessage();
    }

    // Redirection après écriture : sinon un F5 rejoue l'action.
    $_SESSION['flash_prospection'] = $msg;
    $qs = $_SERVER['QUERY_STRING'] ?? '';
    header('Location: /prospection' . ($qs !== '' ? '?' . $qs : ''));
    exit;
}

// ── Lecture ─────────────────────────────────────────────────────────────────
$filtre = (string) ($_GET['f'] ?? 'tous');
$q      = trim((string) ($_GET['q'] ?? ''));
$lotVu  = (int) ($_GET['import'] ?? 0);   // n'afficher qu'un import
$fType  = prosp_type((string) ($_GET['type'] ?? ''));
$fDept  = mb_substr(strtoupper(preg_replace('/[^0-9A-Za-z]/', '', (string) ($_GET['dept'] ?? ''))), 0, 3);

$where  = ['p.org_id = ?'];
$params = [$org_id];

if ($lotVu > 0) { $where[] = 'p.import_id = ?'; $params[] = $lotVu; }
if ($fType !== '') { $where[] = 'p.type = ?'; $params[] = $fType; }
if ($fDept !== '') { $where[] = 'p.departement = ?'; $params[] = $fDept; }

if ($filtre === 'corbeille') {
    $where[] = 'p.deleted_at IS NOT NULL';
} else {
    $where[] = 'p.deleted_at IS NULL';
    if ($filtre === 'a_appeler')      $where[] = 'p.called = 0';
    elseif ($filtre === 'appeles')    $where[] = 'p.called = 1';
    elseif ($filtre === 'emails')     $where[] = 'p.emailed = 1';
    elseif ($filtre === 'jamais')     $where[] = 'p.called = 0 AND p.emailed = 0';
    elseif ($filtre === 'a_rappeler') $where[] = 'p.callback_at IS NOT NULL';
    elseif ($filtre === 'en_retard')  $where[] = 'p.callback_at IS NOT NULL AND p.callback_at <= NOW()';
    // Préparer une tournée d'appels et préparer un envoi d'e-mails ne
    // demandent pas la même liste : on sépare les deux.
    elseif ($filtre === 'avec_tel')   $where[] = "p.telephone <> ''";
    elseif ($filtre === 'avec_email') $where[] = "p.email <> ''";
    elseif ($filtre === 'email_seul') $where[] = "p.email <> '' AND p.telephone = ''";
}
if ($q !== '') {
    $where[] = '(p.nom LIKE ? OR p.prenom LIKE ? OR p.telephone LIKE ? OR p.email LIKE ?)';
    $like = '%' . $q . '%';
    array_push($params, $like, $like, $like, $like);
}

$rows = []; $stats = ['total' => 0, 'a_appeler' => 0, 'appeles' => 0, 'emails' => 0, 'a_rappeler' => 0, 'en_retard' => 0];
$events = [];
$qr_labels = [];
$imports = [];
$imports_indispo = false;
$facettes = ['type' => [], 'dept' => []];

try {
    // Les rappels dus remontent en tête : c'est l'ordre dans lequel on
    // décroche le téléphone.
    // users porte first_name/last_name, pas name : CONCAT_WS ignore les NULL
    // et ne laisse pas d'espace orphelin si le nom de famille manque.
    $sql = "SELECT p.*, TRIM(CONCAT_WS(' ', uu.first_name, uu.last_name)) AS updated_name
            FROM asso_prospection p
            LEFT JOIN users uu ON uu.id = p.updated_by
            WHERE " . implode(' AND ', $where) . "
            ORDER BY (p.callback_at IS NOT NULL AND p.callback_at <= NOW()) DESC,
                     p.callback_at IS NULL, p.callback_at ASC,
                     p.called ASC, p.id DESC
            LIMIT 300";
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    $st = $pdo->prepare("SELECT
            COUNT(*) AS total,
            SUM(called = 0) AS a_appeler,
            SUM(called = 1) AS appeles,
            SUM(emailed = 1) AS emails,
            SUM(callback_at IS NOT NULL) AS a_rappeler,
            SUM(callback_at IS NOT NULL AND callback_at <= NOW()) AS en_retard
          FROM asso_prospection WHERE org_id = ? AND deleted_at IS NULL");
    $st->execute([$org_id]);
    $stats = array_map('intval', $st->fetch(PDO::FETCH_ASSOC) ?: $stats);

    // Les imports du fichier, et ce qu'il en reste. Requête tolérante : la
    // prospection doit rester utilisable si cette migration-ci n'est pas
    // passée, comme elle l'est déjà pour celle des codes QR.
    try {
        $st = $pdo->prepare("SELECT i.*,
                    TRIM(CONCAT_WS(' ', u.first_name, u.last_name)) AS auteur,
                    (SELECT COUNT(*) FROM asso_prospection p
                      WHERE p.import_id = i.id AND p.deleted_at IS NULL) AS restantes
                 FROM asso_prospection_imports i
                 LEFT JOIN users u ON u.id = i.created_by
                 WHERE i.org_id = ?
                 ORDER BY i.id DESC LIMIT 60");
        $st->execute([$org_id]);
        $imports = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { $imports_indispo = true; }

    // Les natures et départements réellement présents : proposer un filtre
    // sur un département vide n'aiderait personne.
    try {
        $st = $pdo->prepare("SELECT type, COUNT(*) n FROM asso_prospection
                             WHERE org_id = ? AND deleted_at IS NULL AND type <> ''
                             GROUP BY type ORDER BY n DESC");
        $st->execute([$org_id]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $facettes['type'][$r['type']] = (int) $r['n'];

        $st = $pdo->prepare("SELECT departement, COUNT(*) n FROM asso_prospection
                             WHERE org_id = ? AND deleted_at IS NULL AND departement <> ''
                             GROUP BY departement ORDER BY departement ASC");
        $st->execute([$org_id]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $facettes['dept'][$r['departement']] = (int) $r['n'];
    } catch (Throwable $e) { /* colonnes pas encore là */ }

    // Libellés des codes QR, en requête séparée et tolérante : si la migration
    // des QR n'a pas été passée, la prospection doit continuer de fonctionner.
    // Une jointure aurait fait tomber toute la page.
    if ($rows) {
        try {
            foreach ($pdo->query("SELECT id, label FROM asso_qr_codes WHERE org_id = " . (int) $org_id)
                         ->fetchAll(PDO::FETCH_ASSOC) as $q) {
                $qr_labels[(int) $q['id']] = (string) $q['label'];
            }
        } catch (Throwable $e) { /* migration QR pas encore passée */ }
    }

    if ($rows) {
        $ids = array_column($rows, 'id');
        $in  = implode(',', array_fill(0, count($ids), '?'));
        $st  = $pdo->prepare("SELECT e.*, TRIM(CONCAT_WS(' ', u.first_name, u.last_name)) AS user_name
                              FROM asso_prospection_events e
                              LEFT JOIN users u ON u.id = e.user_id
                              WHERE e.org_id = ? AND e.prospect_id IN ($in)
                              ORDER BY e.id DESC");
        $st->execute(array_merge([$org_id], $ids));
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $e) $events[(int) $e['prospect_id']][] = $e;
    }
} catch (Throwable $e) {
    $migration_missing = true;
    $migration_manquantes = pr_migrations_manquantes($pdo);
    // Si le schéma est complet, la panne vient d'ailleurs : autant le dire
    // plutôt que de faire relancer une migration qui n'y changera rien.
    if (!$migration_manquantes) $migration_erreur = $e->getMessage();
}

$EVENT_LABEL = [
    'create'         => 'Fiche créée',
    'edit'           => 'Fiche modifiée',
    'call_yes'       => 'Marquée appelée',
    'call_no'        => 'Appel annulé',
    'mail_yes'       => 'E-mail envoyé',
    'mail_no'        => 'E-mail annulé',
    'callback_set'   => 'Rappel programmé',
    'callback_clear' => 'Rappel retiré',
    'delete'         => 'Supprimée',
    'restore'        => 'Restaurée',
    'import'         => 'Importée depuis un fichier',
];

$FILTRES = [
    'tous'       => 'Tous',
    'a_appeler'  => 'À appeler',
    'appeles'    => 'Appelés',
    'emails'     => 'E-mail envoyé',
    'jamais'     => 'Jamais contactés',
    'a_rappeler' => 'À rappeler',
    'en_retard'  => 'Rappels dus',
    'avec_tel'   => 'Avec téléphone',
    'avec_email' => 'Avec e-mail',
    'email_seul' => 'E-mail seulement',
    'corbeille'  => 'Corbeille',
];

/** Conserve le filtre courant à travers les redirections. */
// Le contexte est rendu après chaque écriture : on ne veut pas perdre son
// filtre par département dès qu'on a coché un appel.
$qs_keep = http_build_query(array_filter([
    'f'      => $filtre !== 'tous' ? $filtre : '',
    'q'      => $q,
    'import' => $lotVu > 0 ? $lotVu : '',
    'type'   => $fType,
    'dept'   => $fDept,
]));

render_head('Prospection');
render_sidebar('prospection');
?>

<main class="main">
<style>
  .pr-top{display:flex;align-items:flex-start;justify-content:space-between;gap:18px;flex-wrap:wrap;margin-bottom:20px}
  .pr-kpis{display:flex;gap:9px;flex-wrap:wrap}
  .pr-kpi{background:#fff;border:1px solid var(--line,#E7EEEA);border-radius:12px;padding:9px 15px;min-width:92px}
  .pr-kpi b{display:block;font-size:20px;line-height:1.2}
  .pr-kpi span{font-size:11px;color:var(--ink-3,#5F6D66);text-transform:uppercase;letter-spacing:.04em}
  .pr-kpi.hot b{color:#B91C1C}
  .pr-flash{border:1px solid #A7F3D0;background:#ECFDF5;color:#065F46;border-radius:11px;padding:12px 15px;margin-bottom:16px;font-size:14px}
  .pr-panel{background:#fff;border:1px solid var(--line,#E7EEEA);border-radius:16px;padding:16px;margin-bottom:16px}
  .pr-new{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,165px),1fr));gap:10px;align-items:end}
  .pr-new label{display:block;font-size:12px;font-weight:600;color:var(--ink-2,#45544D);margin-bottom:5px}
  /* Import / export */
  .pr-io{display:flex;gap:12px;align-items:center;flex-wrap:wrap;margin-top:14px;padding-top:14px;border-top:1px solid var(--line,#E7EEEA)}
  .pr-io-form{display:flex;gap:8px;align-items:center;flex-wrap:wrap;min-width:0}
  /* L'input natif est illisible et impossible à styler : on l'enveloppe. */
  .pr-io-file{position:relative;overflow:hidden;display:inline-flex;align-items:center;
    border:1px dashed #A9BDB3;border-radius:10px;padding:8px 13px;cursor:pointer;
    font-size:13px;color:var(--ink-2,#45544D);background:#F7FAF9;max-width:100%}
  .pr-io-file:hover{border-color:#059669;color:#059669}
  .pr-io-file input{position:absolute;inset:0;opacity:0;cursor:pointer;width:100%;height:100%}
  .pr-io-file span{overflow:hidden;text-overflow:ellipsis;white-space:nowrap;min-width:0}
  .pr-io-liens{display:flex;gap:14px;flex-wrap:wrap;margin-left:auto;font-size:13px}
  .pr-io-liens a{color:#059669;font-weight:600;text-decoration:none}
  .pr-io-liens a:hover{text-decoration:underline}
  .pr-io-aide{margin:9px 0 0;font-size:12.5px;color:var(--ink-3,#5F6D66);line-height:1.5}
  /* Les fichiers déjà versés */
  .pr-lots{margin-top:14px;border-top:1px solid var(--line,#E7EEEA);padding-top:12px}
  .pr-lots-titre{font-size:11px;font-weight:700;letter-spacing:.07em;text-transform:uppercase;
    color:var(--ink-4,#9AA8A2);margin-bottom:8px}
  .pr-lot{display:flex;gap:12px;align-items:center;flex-wrap:wrap;padding:8px 10px;border-radius:10px;min-width:0}
  .pr-lot + .pr-lot{margin-top:2px}
  .pr-lot:hover{background:#F7FAF9}
  .pr-lot.est-vu{background:#ECFDF5;box-shadow:inset 0 0 0 1px #A7F3D0}
  .pr-lot.est-sup{opacity:.62}
  .pr-lot-nom{min-width:0;flex:1}
  .pr-lot-nom a{color:#059669;font-weight:600;text-decoration:none;font-size:13.5px}
  .pr-lot-nom a:hover{text-decoration:underline}
  .pr-lot-nom span{font-weight:600;font-size:13.5px;color:var(--ink-2,#45544D)}
  .pr-lot-nom small{display:block;color:var(--ink-3,#5F6D66);font-size:12px;margin-top:2px}
  .pr-lot-acts{display:flex;gap:6px;flex-shrink:0;flex-wrap:wrap}
  .pr-btn.danger{background:#FEF2F2;color:#991B1B;border-color:#FECACA}
  .pr-btn.danger:hover{background:#FEE2E2}
  @media (max-width:760px){ .pr-io-liens{margin-left:0} .pr-lot-acts{width:100%} }
  input.pr-in,textarea.pr-in{width:100%;padding:9px 12px;border:1px solid var(--line,#E7EEEA);border-radius:9px;font:inherit;font-size:13.5px;background:#fff}
  input.pr-in:focus,textarea.pr-in:focus{outline:2px solid #05966933;border-color:#059669}
  .pr-btn{padding:10px 17px;border-radius:10px;border:none;background:#059669;color:#fff;font:inherit;font-weight:600;font-size:13.5px;cursor:pointer;white-space:nowrap}
  .pr-btn.sec{background:#fff;border:1px solid var(--line,#E7EEEA);color:var(--ink-2,#45544D)}
  .pr-btn.dgr{background:#fff;border:1px solid #FECACA;color:#B91C1C}
  .pr-tabs{display:flex;gap:7px;flex-wrap:wrap;margin-bottom:14px}
  .pr-tab{padding:8px 14px;border-radius:999px;border:1px solid var(--line,#E7EEEA);background:#fff;font-size:13px;font-weight:600;color:var(--ink-2,#45544D);text-decoration:none}
  .pr-tab.on{background:#059669;border-color:#059669;color:#fff}
  .pr-search{display:flex;gap:8px;margin-left:auto}
  .pr-row{background:#fff;border:1px solid var(--line,#E7EEEA);border-radius:14px;margin-bottom:10px;overflow:hidden}
  .pr-row.due{border-color:#FCA5A5;box-shadow:0 0 0 3px #FEE2E233}
  .pr-main{display:flex;align-items:center;gap:14px;padding:13px 16px;flex-wrap:wrap}
  .pr-id{width:36px;height:36px;border-radius:11px;background:#ECF7F2;color:#059669;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:13px;flex-shrink:0}
  .pr-who b{font-size:14.5px;display:block}
  .pr-coord{font-size:12.5px;color:var(--ink-3,#5F6D66);display:flex;gap:12px;flex-wrap:wrap;margin-top:2px}
  .pr-coord a{color:#059669;text-decoration:none;font-weight:500}
  .pr-badges{display:flex;gap:6px;align-items:center;flex-wrap:wrap}
  .pr-bg{font-size:11px;font-weight:700;padding:3px 9px;border-radius:999px;letter-spacing:.02em}
  .pr-bg.ok{background:#D1FAE5;color:#065F46}
  .pr-bg.no{background:#F1F5F9;color:#64748B}
  .pr-bg.cb{background:#FEF3C7;color:#92400E}
  .pr-bg.due{background:#FEE2E2;color:#991B1B}
  .pr-bg.qr{background:#EDE9FE;color:#5B21B6}
  .pr-bg.ml{background:#DBEAFE;color:#1E40AF}
  .pr-acts{margin-left:auto;display:flex;gap:8px;align-items:center;flex-wrap:wrap}
  .pr-detail{border-top:1px solid var(--sep,#F1F5F4);padding:14px 16px;background:#FBFDFC;display:grid;grid-template-columns:1fr 300px;gap:20px}
  .pr-detail h4{font-size:12px;text-transform:uppercase;letter-spacing:.05em;color:var(--ink-3,#5F6D66);margin:0 0 9px}
  .pr-hist{list-style:none;margin:0;padding:0;max-height:200px;overflow:auto}
  .pr-hist li{font-size:12.5px;padding:6px 0;border-bottom:1px solid var(--sep,#F1F5F4);line-height:1.45}
  .pr-hist li:last-child{border-bottom:none}
  .pr-hist time{color:var(--ink-3,#5F6D66)}
  .pr-empty{text-align:center;padding:44px 20px;color:var(--ink-3,#5F6D66)}
  /* Le champ de date doit pouvoir rétrécir : sans min-width:0 il garde sa
     largeur intrinsèque et pousse le bouton hors de la carte, qui le rogne. */
  .pr-cb{display:flex;gap:6px;align-items:center;flex-wrap:wrap;min-width:0}
  .pr-cb input{flex:1 1 170px;min-width:0;max-width:210px}
  .pr-seg{display:inline-flex;align-items:center;gap:0;border:1px solid var(--line,#E7EEEA);border-radius:10px;overflow:hidden;background:#fff}
  .pr-seg-lab{font-size:11.5px;font-weight:700;color:var(--ink-3,#5F6D66);padding:0 10px;letter-spacing:.04em;text-transform:uppercase}
  .pr-seg button{border:none;background:#fff;font:inherit;font-weight:700;font-size:13px;padding:9px 15px;cursor:pointer;color:var(--ink-3,#5F6D66);border-left:1px solid var(--line,#E7EEEA)}
  .pr-seg button:hover{background:var(--bg-2,#EDF2EF)}
  .pr-seg button.on{background:#059669;color:#fff}
  .pr-seg button.on.off{background:var(--ink-3,#5F6D66)}
  summary{cursor:pointer;list-style:none}
  summary::-webkit-details-marker{display:none}
  summary::before{content:"▸";display:inline-block;margin-right:7px;transition:transform .12s ease;color:var(--ink-3,#5F6D66)}
  details[open] summary::before{transform:rotate(90deg)}
  @media (max-width:820px){
    .pr-detail{grid-template-columns:1fr}
    .pr-acts{margin-left:0;width:100%}
    .pr-search{margin-left:0;width:100%}
  }
</style>

<div class="pr-top">
  <div>
    <h1 style="font-size:23px;margin:0 0 4px">Prospection</h1>
    <p style="color:var(--ink-3,#5F6D66);font-size:14px;margin:0;max-width:680px">
      <?php // Formulation tournée vers ce que l'outil apporte à celui qui
            // l'utilise. La version précédente — « chaque geste est daté et
            // signé » — décrivait la même chose mais sonnait comme une
            // surveillance de l'équipe, ce qui n'est pas le propos. ?>
      Marquez l'appel ou l'e-mail d'un clic — la date est posée automatiquement — et
      programmez le rappel. Vous savez ainsi quand chaque action a été faite et où en
      sont vos rappels.
    </p>
  </div>
  <div class="pr-kpis">
    <a class="pr-kpi" href="/mon-asso-qr" style="text-decoration:none;color:inherit;display:flex;align-items:center;gap:9px;min-width:0">
      <svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="#059669" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><path d="M14 14h3v3h-3zM18 18h3v3h-3z"/></svg>
      <span style="font-size:13px;font-weight:600;color:#059669">Codes QR</span>
    </a>
    <div class="pr-kpi"><b><?= $stats['total'] ?></b><span>fiches</span></div>
    <div class="pr-kpi"><b><?= $stats['a_appeler'] ?></b><span>à appeler</span></div>
    <div class="pr-kpi"><b><?= $stats['appeles'] ?></b><span>appelés</span></div>
    <div class="pr-kpi"><b><?= $stats['emails'] ?></b><span>e-mails</span></div>
    <div class="pr-kpi <?= $stats['en_retard'] > 0 ? 'hot' : '' ?>"><b><?= $stats['en_retard'] ?></b><span>rappels dus</span></div>
  </div>
</div>

<?php if ($flash): ?><div class="pr-flash"><?= h($flash) ?></div><?php endif; ?>

<?php if ($migration_missing): ?>
  <div class="pr-flash" style="background:#FEF2F2;border-color:#FECACA;color:#991B1B">
    <?php if ($migration_manquantes): ?>
      <?= count($migration_manquantes) > 1
            ? 'Les tables de prospection ne sont pas en place. Sur le serveur, dans cet ordre :'
            : 'Les tables de prospection ne sont pas en place. Sur le serveur :' ?>
      <?php foreach ($migration_manquantes as $f): ?>
        <?php // Un script .php se lance directement ; un .sql passe par run.php. ?>
        <code style="display:block;margin-top:6px">php migrations/<?= str_ends_with($f, '.php') ? '' : 'run.php ' ?><?= h($f) ?></code>
      <?php endforeach; ?>
    <?php else: ?>
      La prospection n’a pas pu être chargée, et ce n’est pas une migration
      manquante : le schéma est complet. Erreur renvoyée par la base :
      <code style="display:block;margin-top:6px"><?= h($migration_erreur) ?></code>
    <?php endif; ?>
  </div>
<?php else: ?>

<div class="pr-panel">
  <form method="post" action="/prospection<?= $qs_keep ? '?' . h($qs_keep) : '' ?>" class="pr-new">
    <input type="hidden" name="action" value="create">
    <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
    <div><label>Prénom</label><input class="pr-in" name="prenom" autocomplete="off"></div>
    <div><label>Nom</label><input class="pr-in" name="nom" autocomplete="off"></div>
    <div><label>Téléphone</label><input class="pr-in" name="telephone" type="tel" inputmode="tel" placeholder="06 12 34 56 78"></div>
    <div><label>E-mail</label><input class="pr-in" name="email" type="email" autocomplete="off"></div>
    <div><button class="pr-btn" type="submit">Ajouter</button></div>
  </form>

  <?php // Import / export. Ajouter un à un convient pour trois contacts,
        // pas pour la liste d'un forum des associations. ?>
  <div class="pr-io">
    <form method="post" action="/prospection<?= $qs_keep ? '?' . h($qs_keep) : '' ?>"
          enctype="multipart/form-data" class="pr-io-form">
      <input type="hidden" name="action" value="import">
      <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
      <label class="pr-io-file">
        <input type="file" name="fichier" accept=".xlsx,.xlsm,.csv,.txt,.tsv" required
               onchange="this.nextElementSibling.textContent = this.files[0] ? this.files[0].name : 'Choisir un fichier…'">
        <span>Choisir un fichier…</span>
      </label>
      <?php // Un annuaire ne mélange presque jamais les genres : le dire une
            // fois évite de qualifier trois cents fiches à la main. ?>
      <select class="pr-in" name="type_defaut" title="Nature des contacts de ce fichier" style="max-width:170px">
        <option value="">Nature — au choix</option>
        <?php foreach (PROSP_TYPES as $k => $lab): ?>
          <option value="<?= h($k) ?>"><?= h($lab) ?></option>
        <?php endforeach; ?>
      </select>
      <button class="pr-btn" type="submit">Importer</button>
    </form>

    <div class="pr-io-liens">
      <a href="/prospection-export.php?quoi=modele">Modèle Excel</a>
      <a href="/prospection-export.php<?= $qs_keep ? '?' . h($qs_keep) : '' ?>">Exporter la liste</a>
      <a href="/prospection-export.php?quoi=historique">Exporter l’historique</a>
    </div>
  </div>

  <p class="pr-io-aide">
    Excel ou CSV. Les colonnes sont reconnues à leur intitulé — <em>Prénom, Nom,
    Téléphone, E-mail, Notes, Type, Code postal, Ville</em> — dans n’importe quel
    ordre. Le département se déduit du code postal. Les numéros déjà présents ne
    sont pas réimportés.
  </p>

  <?php // Les fichiers déjà versés, et de quoi en retirer un en entier. ?>
  <?php if ($imports_indispo): ?>
    <p class="pr-io-aide" style="color:#991B1B">
      Le suivi des imports demande une migration :
      <code>php migrations/run.php 2026-09-15-prospection-imports.sql</code>
    </p>
  <?php elseif ($imports): ?>
    <div class="pr-lots">
      <div class="pr-lots-titre">Fichiers importés<?php if ($role !== 'admin'): ?>
        <span style="font-weight:500;text-transform:none;letter-spacing:0">
          · seul un administrateur peut en retirer un</span>
      <?php endif; ?></div>
      <?php foreach ($imports as $it):
            $sup = !empty($it['deleted_at']);
            // Le retrait est réservé à l'administrateur : les commandes ne
            // s'affichent même pas pour les autres, plutôt que de proposer
            // un bouton qui refusera.
            $peut = ($role === 'admin');
            $t = strtotime((string) $it['created_at']); ?>
        <div class="pr-lot<?= $sup ? ' est-sup' : '' ?><?= $lotVu === (int) $it['id'] ? ' est-vu' : '' ?>">
          <div class="pr-lot-nom">
            <?php if ($sup): ?>
              <span><?= h($it['fichier']) ?></span>
            <?php else: ?>
              <a href="/prospection?import=<?= (int) $it['id'] ?>"><?= h($it['fichier']) ?></a>
            <?php endif; ?>
            <small>
              <?= $t ? date('d/m/Y à H\hi', $t) : '' ?>
              <?php if (!empty($it['auteur'])): ?> · <?= h($it['auteur']) ?><?php endif; ?>
              <?php if (!empty($it['type_defaut']) && isset(PROSP_TYPES[$it['type_defaut']])): ?>
                · <?= h(PROSP_TYPES[$it['type_defaut']]) ?>
              <?php endif; ?>
              · <?= (int) $it['restantes'] ?> fiche<?= (int) $it['restantes'] > 1 ? 's' : '' ?>
              <?php if ((int) $it['doublons'] > 0): ?> · <?= (int) $it['doublons'] ?> doublon<?= (int) $it['doublons'] > 1 ? 's' : '' ?> écarté<?= (int) $it['doublons'] > 1 ? 's' : '' ?><?php endif; ?>
              <?php if ($sup): ?> · <strong>retiré</strong><?php endif; ?>
            </small>
          </div>
          <?php if ($peut): ?>
          <div class="pr-lot-acts">
            <?php if ($sup): ?>
              <form method="post" action="/prospection" style="display:inline">
                <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                <input type="hidden" name="action" value="import_restore">
                <input type="hidden" name="import_id" value="<?= (int) $it['id'] ?>">
                <button class="pr-btn sec" type="submit">Restaurer</button>
              </form>
              <form method="post" action="/prospection" style="display:inline"
                    onsubmit="return confirm('Supprimer définitivement « <?= h(addslashes($it['fichier'])) ?> » et toutes ses fiches ? Cette action est irréversible.')">
                <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                <input type="hidden" name="action" value="import_purge">
                <input type="hidden" name="import_id" value="<?= (int) $it['id'] ?>">
                <button class="pr-btn danger" type="submit">Supprimer définitivement</button>
              </form>
            <?php else: ?>
              <form method="post" action="/prospection" style="display:inline"
                    onsubmit="return confirm('Retirer les <?= (int) $it['restantes'] ?> fiches de « <?= h(addslashes($it['fichier'])) ?> » ?')">
                <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                <input type="hidden" name="action" value="import_delete">
                <input type="hidden" name="import_id" value="<?= (int) $it['id'] ?>">
                <button class="pr-btn sec" type="submit">Retirer cet import</button>
              </form>
            <?php endif; ?>
          </div>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<div class="pr-tabs">
  <?php foreach ($FILTRES as $k => $lab):
        $u = '/prospection?' . http_build_query(array_filter(['f' => $k !== 'tous' ? $k : '', 'q' => $q])); ?>
    <a class="pr-tab <?= $filtre === $k ? 'on' : '' ?>" href="<?= h($u) ?>"><?= h($lab) ?><?php
      if ($k === 'en_retard' && $stats['en_retard'] > 0) echo ' · ' . $stats['en_retard']; ?></a>
  <?php endforeach; ?>
  <form method="get" action="/prospection" class="pr-search">
    <input type="hidden" name="f" value="<?= h($filtre) ?>">
    <?php if ($lotVu > 0): ?><input type="hidden" name="import" value="<?= $lotVu ?>"><?php endif; ?>
    <?php // Deux axes de tri demandés sur le terrain : on n'appelle pas une
          // entreprise comme une association, et une tournée se prépare
          // département par département. ?>
    <?php if ($facettes['type']): ?>
      <select class="pr-in" name="type" onchange="this.form.submit()">
        <option value="">Toutes natures</option>
        <?php foreach ($facettes['type'] as $k => $n): ?>
          <option value="<?= h($k) ?>" <?= $fType === $k ? 'selected' : '' ?>>
            <?= h(PROSP_TYPES[$k] ?? $k) ?> (<?= $n ?>)
          </option>
        <?php endforeach; ?>
      </select>
    <?php endif; ?>
    <?php if ($facettes['dept']): ?>
      <select class="pr-in" name="dept" onchange="this.form.submit()">
        <option value="">Tous départements</option>
        <?php foreach ($facettes['dept'] as $k => $n): ?>
          <option value="<?= h($k) ?>" <?= $fDept === $k ? 'selected' : '' ?>><?= h($k) ?> (<?= $n ?>)</option>
        <?php endforeach; ?>
      </select>
    <?php endif; ?>
    <input class="pr-in" name="q" value="<?= h($q) ?>" placeholder="Nom, téléphone, e-mail…" style="min-width:180px">
    <button class="pr-btn sec" type="submit">Rechercher</button>
  </form>
</div>

<?php if (!$rows): ?>
  <div class="pr-panel pr-empty">
    <?= $q !== '' ? 'Aucun résultat pour « ' . h($q) . ' ».' : 'Aucune fiche ici pour le moment.' ?>
  </div>
<?php endif; ?>

<?php foreach ($rows as $p):
  $pid = (int) $p['id'];
  $due = !empty($p['callback_at']) && strtotime((string) $p['callback_at']) <= time();
  $who = trim((string) $p['prenom'] . ' ' . (string) $p['nom']);
  $deleted = !empty($p['deleted_at']);
  $telClean = preg_replace('/[^0-9+]/', '', (string) $p['telephone']);
?>
<div class="pr-row <?= $due && !$deleted ? 'due' : '' ?>">
  <div class="pr-main">
    <span class="pr-id"><?= h(mb_strtoupper(mb_substr((string) $p['prenom'], 0, 1) . mb_substr((string) $p['nom'], 0, 1))) ?: '·' ?></span>
    <div class="pr-who">
      <b><?= $who !== '' ? h($who) : '<span style="color:#8A968F;font-weight:400">Sans nom</span>' ?></b>
      <div class="pr-coord">
        <?php if ($telClean !== ''): ?><a href="tel:<?= h($telClean) ?>">📞 <?= h($p['telephone']) ?></a><?php endif; ?>
        <?php if (!empty($p['email'])): ?><a href="mailto:<?= h($p['email']) ?>">✉️ <?= h($p['email']) ?></a><?php endif; ?>
      </div>
    </div>

    <div class="pr-badges">
      <?php if ($deleted): ?>
        <span class="pr-bg no">SUPPRIMÉE</span>
      <?php else: ?>
        <?php if (($p['source'] ?? 'manuel') === 'qr'): ?>
          <span class="pr-bg qr" title="Coordonnées laissées par la personne elle-même">VIA QR<?php
            $ql = $qr_labels[(int) ($p['qr_id'] ?? 0)] ?? '';
            if ($ql !== '') echo ' · ' . h($ql); ?></span>
        <?php endif; ?>
        <?php if (!empty($p['called'])): ?>
          <span class="pr-bg ok">APPELÉ<?= !empty($p['called_at']) ? ' · ' . h(date('d/m/Y H:i', strtotime((string) $p['called_at']))) : '' ?></span>
        <?php else: ?>
          <span class="pr-bg no">NON APPELÉ</span>
        <?php endif; ?>
        <?php if (!empty($p['emailed'])): ?>
          <span class="pr-bg ml">E-MAIL<?= !empty($p['emailed_at']) ? ' · ' . h(date('d/m/Y H:i', strtotime((string) $p['emailed_at']))) : '' ?></span>
        <?php endif; ?>
        <?php if (!empty($p['callback_at'])): ?>
          <span class="pr-bg <?= $due ? 'due' : 'cb' ?>">RAPPEL <?= h(date('d/m/Y H:i', strtotime((string) $p['callback_at']))) ?></span>
        <?php endif; ?>
      <?php endif; ?>
    </div>

    <div class="pr-acts">
      <?php if ($deleted): ?>
        <form method="post" action="/prospection<?= $qs_keep ? '?' . h($qs_keep) : '' ?>">
          <input type="hidden" name="action" value="restore">
          <input type="hidden" name="id" value="<?= $pid ?>">
          <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
          <button class="pr-btn sec" type="submit">Restaurer</button>
        </form>
      <?php else: ?>
        <!-- Interrupteur à deux positions plutôt qu'un bouton qui bascule :
             un bouton unique étiqueté « Appelé : NON » à côté d'un badge
             « APPELÉ » se lit comme un état, pas comme une action. Ici l'état
             courant est celui qui est allumé. -->
        <form method="post" action="/prospection<?= $qs_keep ? '?' . h($qs_keep) : '' ?>" class="pr-seg">
          <input type="hidden" name="action" value="call">
          <input type="hidden" name="id" value="<?= $pid ?>">
          <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
          <span class="pr-seg-lab">Appel</span>
          <button type="submit" name="value" value="1" class="<?= !empty($p['called']) ? 'on' : '' ?>"
                  <?= !empty($p['called']) ? 'aria-pressed="true"' : '' ?>>OUI</button>
          <button type="submit" name="value" value="0" class="<?= empty($p['called']) ? 'on off' : '' ?>"
                  <?= empty($p['called']) ? 'aria-pressed="true"' : '' ?>>NON</button>
        </form>

        <form method="post" action="/prospection<?= $qs_keep ? '?' . h($qs_keep) : '' ?>" class="pr-seg">
          <input type="hidden" name="action" value="mail">
          <input type="hidden" name="id" value="<?= $pid ?>">
          <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
          <span class="pr-seg-lab">E-mail</span>
          <button type="submit" name="value" value="1" class="<?= !empty($p['emailed']) ? 'on' : '' ?>"
                  <?= !empty($p['emailed']) ? 'aria-pressed="true"' : '' ?>>OUI</button>
          <button type="submit" name="value" value="0" class="<?= empty($p['emailed']) ? 'on off' : '' ?>"
                  <?= empty($p['emailed']) ? 'aria-pressed="true"' : '' ?>>NON</button>
        </form>


        <form method="post" action="/prospection<?= $qs_keep ? '?' . h($qs_keep) : '' ?>" class="pr-cb">
          <input type="hidden" name="action" value="callback">
          <input type="hidden" name="id" value="<?= $pid ?>">
          <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
          <input class="pr-in" type="datetime-local" name="callback_at"
                 value="<?= !empty($p['callback_at']) ? h(date('Y-m-d\TH:i', strtotime((string) $p['callback_at']))) : '' ?>"
                 aria-label="Date de rappel">
          <button class="pr-btn sec" type="submit">À rappeler</button>
        </form>
      <?php endif; ?>
    </div>
  </div>

  <details>
    <summary style="padding:9px 16px;font-size:12.5px;color:var(--ink-3,#5F6D66);border-top:1px solid var(--sep,#F1F5F4)">
      Détails et historique
      <?php if (!empty($p['updated_at'])): ?>
        — dernière modification le <?= h(date('d/m/Y à H:i', strtotime((string) $p['updated_at']))) ?><?php
        if (!empty($p['updated_name'])) echo ' par ' . h($p['updated_name']); ?>
      <?php endif; ?>
    </summary>

    <div class="pr-detail">
      <form method="post" action="/prospection<?= $qs_keep ? '?' . h($qs_keep) : '' ?>">
        <!-- Pas de champ caché « action » : les deux boutons la portent. Un
             champ caché serait écrasé par le bouton cliqué, ce qui marche mais
             ne se lit pas. -->
        <input type="hidden" name="id" value="<?= $pid ?>">
        <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
        <h4>Coordonnées</h4>
        <div class="pr-new" style="margin-bottom:10px">
          <div><label>Prénom</label><input class="pr-in" name="prenom" value="<?= h($p['prenom']) ?>"></div>
          <div><label>Nom</label><input class="pr-in" name="nom" value="<?= h($p['nom']) ?>"></div>
          <div><label>Téléphone</label><input class="pr-in" name="telephone" type="tel" value="<?= h($p['telephone']) ?>"></div>
          <div><label>E-mail</label><input class="pr-in" name="email" type="email" value="<?= h($p['email']) ?>"></div>
        </div>
        <label style="display:block;font-size:12px;font-weight:600;color:var(--ink-2,#45544D);margin-bottom:5px">Notes d'appel</label>
        <textarea class="pr-in" name="notes" rows="3" placeholder="Ce qui s'est dit, l'objection, le bon moment pour rappeler…"><?= h($p['notes']) ?></textarea>
        <div style="display:flex;gap:8px;margin-top:11px;flex-wrap:wrap">
          <button class="pr-btn" type="submit" name="action" value="edit">Enregistrer</button>
          <?php if (!$deleted): ?>
            <button class="pr-btn dgr" type="submit" name="action" value="delete"
                    onclick="return confirm('Supprimer <?= h($who !== '' ? $who : 'cette fiche') ?> ? Elle restera récupérable dans la corbeille.')">
              Supprimer
            </button>
          <?php endif; ?>
        </div>
      </form>

      <div>
        <h4>Historique</h4>
        <ul class="pr-hist">
          <?php foreach (($events[$pid] ?? []) as $e): ?>
            <li>
              <strong><?= h($EVENT_LABEL[$e['type']] ?? $e['type']) ?></strong><?php
                if (!empty($e['detail'])) echo ' — ' . h($e['detail']); ?><br>
              <time><?= h(date('d/m/Y à H:i', strtotime((string) $e['created_at']))) ?></time><?php
                if (!empty($e['user_name'])) echo ' · ' . h($e['user_name']); ?>
            </li>
          <?php endforeach; ?>
          <?php if (empty($events[$pid])): ?>
            <li style="color:var(--ink-3,#5F6D66)">Rien d'enregistré pour l'instant.</li>
          <?php endif; ?>
        </ul>
      </div>
    </div>
  </details>
</div>
<?php endforeach; ?>

<?php if (count($rows) >= 300): ?>
  <p style="color:var(--ink-3,#5F6D66);font-size:13px">
    Affichage limité aux 300 premières fiches. Affinez avec la recherche ou les filtres.
  </p>
<?php endif; ?>

<?php endif; // migration ?>
</main>
<?php render_foot(); ?>
