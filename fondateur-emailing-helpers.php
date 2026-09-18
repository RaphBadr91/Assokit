<?php
/**
 * fondateur-emailing-helpers.php — Le socle des campagnes du Fondateur.
 * ------------------------------------------------------------------
 * Ce que ce fichier tient, et que les pages ne doivent pas refaire :
 *   · l'import d'un fichier d'adresses vers une liste ;
 *   · le rangement et la relecture des pièces jointes ;
 *   · la composition d'un e-mail de campagne, pied de page légal compris ;
 *   · l'envoi d'un destinataire, avec toutes les vérifications d'usage.
 *
 * Les contacts vivent dans asso_prospects, partagée avec la séquence de
 * prospection. C'est voulu : un désabonnement doit valoir partout.
 * ------------------------------------------------------------------
 */

require_once __DIR__ . '/api/_app-prospect.php';
require_once __DIR__ . '/xlsx-helper.php';

/** Taille maximale d'une pièce jointe. */
if (!defined('AK_CAMP_PJ_MAX')) define('AK_CAMP_PJ_MAX', 5 * 1024 * 1024);   // 5 Mo

/**
 * Où sont rangées les pièces jointes.
 *
 * Hors du dossier web : un fichier commercial déposé pour une campagne
 * n'a pas à être téléchargeable par quiconque devine son nom. On remonte
 * d'un cran depuis public_html ; si ce n'est pas possible (droits,
 * hébergement différent), on retombe sur un dossier interne protégé par
 * le .htaccess, plutôt que de refuser l'envoi.
 */
function ak_camp_dossier_pj(): string
{
    $dehors = dirname(__DIR__) . '/private_campagnes';
    if (is_dir($dehors) || @mkdir($dehors, 0700, true)) {
        if (is_writable($dehors)) return $dehors;
    }
    $dedans = __DIR__ . '/private_campagnes';
    if (!is_dir($dedans)) @mkdir($dedans, 0700, true);
    return $dedans;
}

/** Chemin complet d'une pièce jointe, ou null si le fichier a disparu. */
function ak_camp_pj_chemin(?string $fichier): ?string
{
    if (!$fichier) return null;
    // basename() : le nom vient de la base, mais rien ne coûte de
    // s'assurer qu'il ne contient pas de « ../ ».
    $p = ak_camp_dossier_pj() . '/' . basename($fichier);
    return is_file($p) ? $p : null;
}

/**
 * Range la pièce jointe envoyée et renvoie de quoi la retrouver.
 *
 * @return array{ok:bool, message:string, fichier?:string, nom?:string, taille?:int, type?:string}
 */
function ak_camp_pj_ranger(array $f): array
{
    $err = (int) ($f['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($err === UPLOAD_ERR_NO_FILE) return ['ok' => true, 'message' => ''];   // pas de pièce jointe, c'est permis
    if ($err !== UPLOAD_ERR_OK) {
        $codes = [
            UPLOAD_ERR_INI_SIZE  => 'Fichier trop volumineux pour le serveur.',
            UPLOAD_ERR_FORM_SIZE => 'Fichier trop volumineux.',
            UPLOAD_ERR_PARTIAL   => 'Envoi interrompu, réessayez.',
        ];
        return ['ok' => false, 'message' => $codes[$err] ?? 'Échec du dépôt (code ' . $err . ').'];
    }
    // Taille et format d'abord : ce sont les refus les plus fréquents,
    // et les annoncer avant toute manipulation du fichier rend aussi ces
    // règles vérifiables sans passer par un vrai téléversement.
    $taille = (int) ($f['size'] ?? 0);
    if ($taille > AK_CAMP_PJ_MAX) {
        return ['ok' => false, 'message' => 'Pièce jointe limitée à '
            . round(AK_CAMP_PJ_MAX / 1048576) . ' Mo (la vôtre : '
            . round($taille / 1048576, 1) . ' Mo). Au-delà, beaucoup de messageries la rejettent.'];
    }

    $nom = mb_substr(preg_replace('/[\r\n"]/', '', (string) ($f['name'] ?? 'piece-jointe')), 0, 255);
    $ext = strtolower(pathinfo($nom, PATHINFO_EXTENSION));
    // Liste blanche : une pièce jointe de campagne est un document, pas
    // un exécutable. Bloquer ici évite surtout de faire classer nos
    // envois en indésirables par les messageries.
    $permis = ['pdf', 'png', 'jpg', 'jpeg', 'gif', 'webp', 'doc', 'docx',
               'xls', 'xlsx', 'csv', 'ppt', 'pptx', 'odt', 'ods', 'txt'];
    if (!in_array($ext, $permis, true)) {
        return ['ok' => false, 'message' => 'Format « ' . $ext . ' » non accepté en pièce jointe. '
            . 'Formats permis : ' . implode(', ', $permis) . '.'];
    }

    // Le garde-fou, juste avant de toucher au fichier : sans lui, un
    // chemin fourni par la requête permettrait de déplacer n'importe
    // quel fichier lisible du serveur.
    if (!is_uploaded_file($f['tmp_name'])) {
        return ['ok' => false, 'message' => 'Fichier inattendu.'];
    }

    $dossier = ak_camp_dossier_pj();
    $interne = bin2hex(random_bytes(12)) . '.' . $ext;
    if (!@move_uploaded_file($f['tmp_name'], $dossier . '/' . $interne)) {
        return ['ok' => false, 'message' => 'Impossible d’enregistrer la pièce jointe (dossier non accessible).'];
    }
    @chmod($dossier . '/' . $interne, 0600);

    return ['ok' => true, 'message' => '', 'fichier' => $interne, 'nom' => $nom,
            'taille' => $taille, 'type' => (string) ($f['type'] ?? '')];
}

/**
 * Importe un fichier d'adresses dans une liste.
 *
 * Reprend le lecteur de la prospection (xlsx, xlsm, csv) et sa
 * reconnaissance d'en-têtes : l'ordre des colonnes n'a pas d'importance,
 * seuls leurs intitulés comptent.
 *
 * Les contacts déjà connus ne sont pas recréés — on les rattache à la
 * liste. C'est la raison d'être de l'e-mail unique : la même adresse
 * dans trois fichiers reste un seul contact, avec un seul désabonnement
 * possible.
 *
 * @return array{ok:bool, message:string, ajoutes?:int, rattaches?:int, doublons?:int, invalides?:int}
 */
function ak_camp_importer(PDO $pdo, int $listeId, string $chemin, string $nomOriginal): array
{
    // La table des contacts est partagée avec la prospection et créée à
    // la demande. On l'assure ici plutôt que de compter sur l'appelant :
    // le cron et la page n'ont pas le même chemin d'entrée, et l'oubli
    // ne se voit qu'au premier import sur une base neuve.
    ak_prospect_tables_ensure($pdo);

    try {
        $lignes = ak_tableur_lire($chemin, $nomOriginal, 50000);
    } catch (Throwable $e) {
        return ['ok' => false, 'message' => $e->getMessage()];
    }
    if (!$lignes) return ['ok' => false, 'message' => 'Fichier vide.'];

    // --- Reconnaissance des colonnes -------------------------------
    $synonymes = [
        'email'  => ['email', 'mail', 'courriel', 'adresseemail', 'adressemail', 'emailaddress', 'e'],
        'nom'    => ['nom', 'nomcomplet', 'contact', 'name', 'fullname', 'responsable', 'prenomnom'],
        'orga'   => ['association', 'organisation', 'structure', 'societe', 'entreprise',
                     'raisonsociale', 'orgname', 'company', 'nomassociation'],
        'ville'  => ['ville', 'commune', 'city', 'localite'],
        'dept'   => ['departement', 'dept', 'dpt', 'codedepartement'],
    ];
    $map = [];
    foreach (($lignes[0] ?? []) as $i => $cel) {
        $k = prosp_cle_camp((string) $cel);
        if ($k === '') continue;
        foreach ($synonymes as $champ => $mots) {
            if (in_array($k, $mots, true) && !isset($map[$champ])) { $map[$champ] = $i; break; }
        }
    }
    $debut = 0;
    if (isset($map['email'])) {
        $debut = 1;   // la première ligne était l'en-tête
    } else {
        // Pas d'en-tête reconnu : on cherche la colonne qui ressemble le
        // plus à des adresses, plutôt que de supposer un ordre. Un
        // fichier d'adresses sans titre de colonne reste exploitable.
        $meilleure = -1; $score = 0;
        foreach (($lignes[0] ?? []) as $i => $_) {
            $n = 0;
            for ($l = 0; $l < min(20, count($lignes)); $l++) {
                if (filter_var(trim((string) ($lignes[$l][$i] ?? '')), FILTER_VALIDATE_EMAIL)) $n++;
            }
            if ($n > $score) { $score = $n; $meilleure = $i; }
        }
        if ($meilleure < 0) {
            return ['ok' => false, 'message' => 'Aucune colonne d’adresses e-mail trouvée. '
                . 'Ajoutez un en-tête « Email » en première ligne.'];
        }
        $map['email'] = $meilleure;
    }

    $lire = fn(array $l, string $c) => isset($map[$c], $l[$map[$c]]) ? trim((string) $l[$map[$c]]) : '';

    // --- Ce qu'on connaît déjà -------------------------------------
    $connus = [];
    $st = $pdo->query("SELECT id, LOWER(email) e FROM asso_prospects");
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $connus[$r['e']] = (int) $r['id'];

    $dejaListe = [];
    $st = $pdo->prepare("SELECT prospect_id FROM fond_liste_membres WHERE liste_id = ?");
    $st->execute([$listeId]);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $pid) $dejaListe[(int) $pid] = true;

    $insContact = $pdo->prepare("INSERT INTO asso_prospects
            (name, org_name, type, email, city, dept_code, source, status, step,
             consent_basis, created_at)
            VALUES (?, ?, 'asso', ?, ?, ?, 'import_campagne', 'new', 0,
                    'b2b_legitimate_interest', NOW())");
    $insMembre = $pdo->prepare("INSERT IGNORE INTO fond_liste_membres (liste_id, prospect_id) VALUES (?, ?)");

    $ajoutes = $rattaches = $doublons = $invalides = 0;
    $vus = [];

    $pdo->beginTransaction();
    try {
        for ($i = $debut; $i < count($lignes); $i++) {
            $l = $lignes[$i];
            if (!$l) continue;

            $email = mb_strtolower(trim($lire($l, 'email')));
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) { $invalides++; continue; }
            // Doublon à l'intérieur même du fichier.
            if (isset($vus[$email])) { $doublons++; continue; }
            $vus[$email] = true;

            $pid = $connus[$email] ?? null;
            if ($pid === null) {
                $insContact->execute([
                    mb_substr($lire($l, 'nom'), 0, 160) ?: null,
                    mb_substr($lire($l, 'orga'), 0, 200) ?: null,
                    mb_substr($email, 0, 255),
                    mb_substr($lire($l, 'ville'), 0, 120) ?: null,
                    mb_substr(preg_replace('/[^0-9A-Za-z]/', '', $lire($l, 'dept')), 0, 3) ?: null,
                ]);
                $pid = (int) $pdo->lastInsertId();
                $connus[$email] = $pid;
                $ajoutes++;
            } elseif (isset($dejaListe[$pid])) {
                $doublons++; continue;
            } else {
                $rattaches++;
            }
            $insMembre->execute([$listeId, $pid]);
            $dejaListe[$pid] = true;
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        return ['ok' => false, 'message' => 'Import interrompu : ' . $e->getMessage()];
    }

    // Un compte rendu chiffré : « import terminé » laisserait croire que
    // les lignes écartées ont été prises.
    $bouts = [];
    if ($ajoutes)   $bouts[] = $ajoutes . ' nouveau' . ($ajoutes > 1 ? 'x contacts' : ' contact');
    if ($rattaches) $bouts[] = $rattaches . ' déjà connu' . ($rattaches > 1 ? 's' : '') . ' rattaché' . ($rattaches > 1 ? 's' : '');
    if ($doublons)  $bouts[] = $doublons . ' doublon' . ($doublons > 1 ? 's' : '') . ' ignoré' . ($doublons > 1 ? 's' : '');
    if ($invalides) $bouts[] = $invalides . ' adresse' . ($invalides > 1 ? 's' : '') . ' invalide' . ($invalides > 1 ? 's' : '');
    if (!$bouts) $bouts[] = 'aucune ligne exploitable';

    return ['ok' => true, 'message' => implode(' · ', $bouts) . '.',
            'ajoutes' => $ajoutes, 'rattaches' => $rattaches,
            'doublons' => $doublons, 'invalides' => $invalides];
}

/** Réduit un intitulé de colonne à sa forme comparable. */
function prosp_cle_camp(string $s): string
{
    $s = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s) ?: $s;
    return preg_replace('/[^a-z0-9]/', '', strtolower($s));
}

/**
 * Le corps final d'un e-mail de campagne, pour un destinataire donné.
 *
 * Deux choses s'ajoutent à ce qu'a écrit le Fondateur :
 *   · les variables de personnalisation ;
 *   · le pied de page légal — identité de l'expéditeur et lien de
 *     désinscription. Il n'est pas optionnel : sans lui l'envoi est en
 *     infraction, et les messageries classent en indésirable.
 */
/**
 * Les variables de personnalisation d'un contact.
 *
 * À part, parce qu'elles servent AUSSI à l'objet du message. Quand elles
 * n'étaient appliquées qu'au corps, un objet contenant {{association}}
 * partait tel quel — la faute la plus visible qui soit, en tête de boîte
 * de réception.
 */
function ak_camp_variables(array $contact): array
{
    $nom  = trim((string) ($contact['name'] ?? ''));
    $orga = trim((string) ($contact['org_name'] ?? ''));
    return [
        '{{nom}}'         => $nom !== '' ? $nom : 'Bonjour',
        '{{prenom}}'      => $nom !== '' ? explode(' ', $nom)[0] : 'Bonjour',
        '{{association}}' => $orga !== '' ? $orga : 'votre association',
        '{{ville}}'       => trim((string) ($contact['city'] ?? '')),
        '{{email}}'       => (string) ($contact['email'] ?? ''),
    ];
}

/** L'objet, personnalisé. */
function ak_camp_sujet(string $sujet, array $contact): string
{
    return strtr($sujet, ak_camp_variables($contact));
}

function ak_camp_corps(string $html, array $contact): string
{
    $corps = strtr($html, ak_camp_variables($contact));

    $unsub = ak_prospect_unsub_link((int) $contact['id'], (string) $contact['email']);
    $pied = '<hr style="border:none;border-top:1px solid #e5e7eb;margin:28px 0 14px">'
          . '<p style="font-size:11.5px;color:#6b7280;line-height:1.6;margin:0">'
          . 'Ce message vous est adressé par <strong>Assokit</strong> — '
          . 'logiciel de gestion pour associations, Évry (France).<br>'
          . 'Vous le recevez à votre adresse professionnelle dans le cadre d’une '
          . 'information entre organisations.<br>'
          . '<a href="' . htmlspecialchars($unsub, ENT_QUOTES, 'UTF-8') . '" style="color:#6b7280">'
          . 'Je ne souhaite plus recevoir ces messages</a>'
          . '</p>';

    return $corps . $pied;
}

/**
 * Envoie un destinataire d'une campagne.
 *
 * Toutes les vérifications sont ici et pas dans la page : la page envoie
 * un test, le cron envoie en masse, et les deux doivent appliquer
 * exactement les mêmes règles.
 *
 * @return array{statut:string, erreur:?string}
 */
function ak_camp_envoyer(PDO $pdo, array $campagne, array $contact, bool $test = false): array
{
    $email = trim((string) $contact['email']);

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['statut' => 'ignore', 'erreur' => 'adresse invalide'];
    }
    // Le refus prime sur tout le reste, y compris sur un envoi de test.
    if (in_array((string) ($contact['status'] ?? ''), ['unsubscribed', 'bounced'], true)) {
        return ['statut' => 'ignore', 'erreur' => 'désinscrit ou adresse en erreur'];
    }
    if (!function_exists('send_transactional_email')) {
        return ['statut' => 'echec', 'erreur' => 'resend-helper indisponible'];
    }

    $options = [
        'from_email' => AK_PROSPECT_FROM,
        'from_name'  => AK_PROSPECT_FROM_NAME,
        'reply_to'   => AK_PROSPECT_REPLY_TO,
        'tag'        => 'campagne_' . (int) $campagne['id'],
    ];

    $pj = ak_camp_pj_chemin($campagne['pj_fichier'] ?? null);
    if ($pj !== null) {
        $options['attachments'] = [[
            'filename' => (string) ($campagne['pj_nom'] ?? basename($pj)),
            'content'  => base64_encode((string) file_get_contents($pj)),
        ]];
    }

    $sujet = ak_camp_sujet((string) $campagne['sujet'], $contact);
    if ($test) $sujet = '[TEST] ' . $sujet;

    try {
        $r = send_transactional_email($email, $sujet, ak_camp_corps((string) $campagne['corps_html'], $contact), $options);
    } catch (Throwable $e) {
        return ['statut' => 'echec', 'erreur' => mb_substr($e->getMessage(), 0, 255)];
    }

    if (!empty($r['success'])) {
        if (!$test) ak_prospect_event($pdo, (int) $contact['id'], 'sent', null, 'campagne ' . (int) $campagne['id']);
        return ['statut' => 'envoye', 'erreur' => null];
    }
    return ['statut' => 'echec', 'erreur' => mb_substr((string) ($r['error'] ?? 'erreur inconnue'), 0, 255)];
}

/** Combien d'e-mails sont déjà partis aujourd'hui, toutes sources confondues. */
function ak_camp_envoyes_aujourdhui(PDO $pdo): int
{
    $n = 0;
    // La séquence de prospection et les campagnes puisent au même
    // plafond : c'est la réputation du domaine d'envoi qui est en jeu,
    // et elle ne distingue pas les deux.
    try {
        $n += (int) $pdo->query("SELECT COUNT(*) FROM asso_prospect_events
                                 WHERE type = 'sent' AND created_at >= CURDATE()")->fetchColumn();
    } catch (Throwable $e) {}
    return $n;
}
