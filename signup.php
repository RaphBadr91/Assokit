<?php
/**
 * ============================================================
 * ASSOKIT — Inscription publique : essai gratuit automatique
 * ============================================================
 * URL : /signup ou /signup?plan=association
 *
 * En 1 étape, sans carte bancaire :
 *   1. Nom de l'asso + prénom/nom + e-mail + mot de passe
 *   2. Création de l'association et de son admin, essai gratuit de
 *      AK_TRIAL_DAYS jours (14) ACTIVÉ IMMÉDIATEMENT, sans validation
 *   3. Connexion automatique, redirection vers le tableau de bord
 *   4. Le fondateur est prévenu de CHAQUE demande (e-mail + cloche),
 *      y compris si la création échoue : le prospect est alors
 *      enregistré et reçoit un accusé de réception, rien n'est perdu.
 *
 * App iPhone (ak_is_ios_app) : mode DEMANDE (règle 3.1.1 d'Apple, rien
 * ne s'essaie dans l'app). Même formulaire, mêmes contrôles, mais
 * l'association est créée « en attente de validation » (rien d'actif,
 * aucun essai), sans connexion : la page affiche « Demande envoyée » (avec
 * le lien « Retirer ma demande », /retirer-demande), le
 * demandeur reçoit « Demande reçue — validation sous 48 h » et le
 * fondateur un e-mail avec les boutons Valider / Refuser. L'essai démarre
 * à la validation (signup-review-helpers.php). Le site et l'app Android
 * ne changent pas.
 *
 * Les écritures passent par ak_db_insert()/ak_db_update() (trial-helpers.php),
 * qui n'écrivent que les colonnes existant réellement en base : c'est une
 * colonne inexistante (organizations.plan_type) qui faisait échouer toutes
 * les inscriptions avec « Erreur technique lors de la création ».
 * ============================================================
 */
require_once __DIR__ . '/config.php';
@require_once __DIR__ . '/password-token-helper.php';
require_once __DIR__ . '/trial-helpers.php';
// Prix annoncé après l'essai (formule Assokit, HT + TTC)
require_once __DIR__ . '/includes-pricing.php';
// ak_billing_hidden() : page ouverte dans l'application mobile (bouton « Créer ma démo ») → aucun prix
require_once __DIR__ . '/app-context.php';

// Si deja connecte, rediriger
if (!empty($_SESSION['user_id'])) {
    header('Location: /dashboard');
    exit;
}

// App iPhone : la demande est examinée par l'équipe, l'essai démarre à la validation (voir en tête ;
// mobile/App.js, IOS_SIGNUP_REQUEST). Aucun mot d'essai, de gratuité, de prix ni d'abonnement à l'écran.
$review = ak_is_ios_app();
if ($review) require_once __DIR__ . '/signup-review-helpers.php';

const AK_SIGNUP_PLANS = ['essentiel', 'association', 'organisation'];
const AK_SIGNUP_TYPES = ['asso' => 'Association', 'tpe' => 'TPE / entreprise'];
const AK_SIGNUP_FORMS = [
    'asso' => ['Association loi 1901', 'Association reconnue d\'utilité publique', 'Association loi 1908 (Alsace-Moselle)', 'Fondation', 'Fonds de dotation', 'Coopérative / SCIC', 'Collectif non déclaré', 'Autre'],
    'tpe'  => ['Micro-entreprise / auto-entrepreneur', 'Entreprise individuelle (EI)', 'EURL', 'SARL', 'SASU', 'SAS', 'SCOP', 'Profession libérale', 'Autre'],
];
const AK_SIGNUP_SIZES = [
    'asso' => ['Moins de 20 adhérents', '20 à 50 adhérents', '51 à 150 adhérents', '151 à 500 adhérents', 'Plus de 500 adhérents'],
    'tpe'  => ['Seul(e)', '2 à 5 personnes', '6 à 10 personnes', '11 à 20 personnes', 'Plus de 20 personnes'],
];
const AK_SIGNUP_SECTORS = [
    'asso' => ['Sport', 'Culture & loisirs', 'Éducation & jeunesse', 'Social & solidarité', 'Insertion & emploi', 'Formation', 'Santé', 'Environnement', 'Quartier & citoyenneté', 'Humanitaire', 'Autre'],
    'tpe'  => ['Commerce', 'Artisanat & BTP', 'Services aux entreprises', 'Conseil & formation', 'Numérique', 'Restauration & hôtellerie', 'Santé & bien-être', 'Transport', 'Création & communication', 'Autre'],
];
const AK_SIGNUP_ROLES = [
    'asso' => ['Président(e)', 'Trésorier(e)', 'Secrétaire', 'Coordinateur·rice / salarié(e)', 'Bénévole', 'Autre'],
    'tpe'  => ['Gérant(e) / dirigeant(e)', 'Associé(e)', 'Salarié(e)', 'Comptable / gestion', 'Autre'],
];
const AK_SIGNUP_NEEDS = ['Adhérents & cotisations', 'Facturation & devis', 'Subventions & financeurs', 'Projets & bilans', 'Comptabilité', 'Communication & e-mails', 'Événements & émargement', 'Boîte mail Gmail', 'Application mobile'];
const AK_SIGNUP_SOURCES = ['Recherche Google', 'Réseaux sociaux', 'Bouche-à-oreille', 'Mairie / collectivité', 'Salon / événement', 'Partenaire', 'Autre'];

$error  = null;   // erreur de saisie : le formulaire reste affiché
$notice = null;   // demande enregistrée mais non ouverte automatiquement : on remercie
$form = [
    'org_name'   => '',
    'first_name' => '',
    'last_name'  => '',
    'email'      => '',
    'plan'       => in_array($_GET['plan'] ?? '', AK_SIGNUP_PLANS, true) ? $_GET['plan'] : 'essentiel',
    'accept_cgu' => false,
    'type'       => (($_GET['type'] ?? '') === 'tpe') ? 'tpe' : 'asso',
    'legal_form' => '', 'rna' => '', 'siret' => '', 'street' => '', 'zip' => '', 'city' => '',
    'phone' => '', 'website' => '', 'size' => '', 'sector' => '', 'role' => '', 'vat' => '',
    'needs' => [], 'source' => '', 'message' => '',
];

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

/** Longueur d'une colonne CHAR/VARCHAR (null si inconnue ou d'un autre type). */
function ak_signup_col_max(PDO $pdo, string $table, string $col): ?int
{
    return preg_match('/char\((\d+)\)/', ak_db_columns($pdo, $table)[$col]['type'] ?? '', $m) ? (int)$m[1] : null;
}

/**
 * Clé « adresse IP » des limites anti-abus : l'adresse IPv4 telle quelle, le préfixe /64
 * pour IPv6 (un seul abonné en dispose de milliards : compter adresse par adresse
 * ne limitait rien).
 */
function ak_signup_ip_key(string $ip): string
{
    $bin = $ip !== '' ? @inet_pton($ip) : false;
    if (!is_string($bin) || strlen($bin) !== 16) return $ip;
    if (strncmp($bin, str_repeat("\0", 10) . "\xff\xff", 12) === 0) return (string)inet_ntop(substr($bin, 12));   // ::ffff:1.2.3.4
    return inet_ntop(substr($bin, 0, 8) . str_repeat("\0", 8)) . '/64';
}

/** Demandes enregistrées depuis cette IP (ou ce /64) sur la dernière heure, TOUS statuts confondus. null si incomptable. */
function ak_signup_ip_count(PDO $pdo, string $ip): ?int
{
    $cols = ak_db_columns($pdo, 'public_signups');
    if (!isset($cols['ip_address'], $cols['created_at'])) return null;
    $key = ak_signup_ip_key($ip);
    if (strpos($key, '/') === false) {   // IPv4 (éventuellement notée ::ffff:a.b.c.d)
        $st = $pdo->prepare("SELECT COUNT(*) FROM public_signups WHERE ip_address IN (?, ?) AND created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)");
        $st->execute([$key, '::ffff:' . $key]);
        return (int)$st->fetchColumn();
    }
    // IPv6 : les adresses sont écrites sous forme abrégée, le préfixe se compare donc ici
    $st = $pdo->query("SELECT ip_address FROM public_signups WHERE ip_address LIKE '%:%' AND created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR) LIMIT 5000");
    $n = 0;
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $a) if (ak_signup_ip_key((string)$a) === $key) $n++;
    return $n;
}

/**
 * Accusé de réception : au plus un par adresse et par 24 h. Une autre demande de la
 * même adresse dans les 24 h suffit à ne pas réécrire (le prospect a déjà sa réponse,
 * ou le fondateur l'a déjà dans sa liste).
 */
function ak_signup_ack_due(PDO $pdo, string $email, ?int $signup_id): bool
{
    try {
        $cols = ak_db_columns($pdo, 'public_signups');
        if (isset($cols['admin_email'], $cols['created_at'])) {
            $st = $pdo->prepare("SELECT 1 FROM public_signups WHERE admin_email = ? AND id <> ? AND created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY) LIMIT 1");
            $st->execute([$email, (int)$signup_id]);
            return !$st->fetchColumn();
        }
    } catch (Throwable $e) {}
    // Table sans colonne exploitable : mémoire fichier, au mieux
    return ak_rate_limit('signup_ack', 1, 86400, sha1($email));
}

/**
 * Demande enregistrée sans ouverture automatique.
 *   $kind : 'incident' → panne de notre côté : alerte fondateur + accusé au prospect ;
 *           'limit'    → valide mais refusée par les limites par IP / par adresse : alerte fondateur, pas d'accusé ;
 *           'breaker'  → disjoncteur (plus de 30 essais ouverts en 1 h) : ni accusé ni alerte unitaire.
 * Alertes unitaires : budget par heure propre à chaque sorte, par IP puis au total (incidents :
 * 3 par IP, 10 en tout ; limites : 2 par IP, 10 en tout). Des demandes limitées ne vident donc
 * jamais le budget des incidents, et une seule IP ne vide aucun budget commun.
 * Rafale (disjoncteur, ou budget dépassé) : plus d'alerte par demande ni d'accusé, mais UN
 * résumé par heure au fondateur (ak_signup_digest_maybe). Sinon, n'importe qui pouvait faire
 * écrire Assokit à une adresse de son choix et noyer le fondateur.
 * La demande reste toujours enregistrée dans public_signups.
 */
function ak_signup_fallback(PDO $pdo, array $lead, ?int $signup_id, string $reason, string $kind = 'incident'): string
{
    if ($signup_id) {
        try { ak_db_update($pdo, 'public_signups', $signup_id, ['status' => ak_db_pick($pdo, 'public_signups', 'status', ['blocked']) ?? 'blocked']); } catch (Throwable $e) {}
        try { ak_db_update($pdo, 'public_signups', $signup_id, ['error_message' => mb_substr($reason, 0, 500)]); } catch (Throwable $e) {}
    }
    try {
        @require_once __DIR__ . '/signup-email-helpers.php';
        $ip_key = ak_signup_ip_key((string)($lead['ip'] ?? ''));
        if ($kind === 'breaker') {
            $flood = true;
        } elseif ($kind === 'limit') {
            $flood = !ak_rate_limit('signup_limit_alert', 2, 3600, $ip_key) || !ak_rate_limit('signup_limit_alert_all', 10, 3600, 'all');
        } else {
            $flood = !ak_rate_limit('signup_fallback_alert_ip', 3, 3600, $ip_key) || !ak_rate_limit('signup_fallback_alert', 10, 3600, 'all');
        }
        if ($flood) {
            // Demande non enregistrée (public_signups illisible) : une trace dans les messages du cockpit
            if (!$signup_id && function_exists('ak_signup_record_contact')) ak_signup_record_contact($pdo, $lead, ' — non ouverte automatiquement (anti-abus), à vérifier.', [], $reason);
            if (function_exists('ak_signup_digest_maybe')) ak_signup_digest_maybe($pdo);
        } else {
            if (function_exists('ak_signup_alert_founder')) ak_signup_alert_founder($pdo, $lead, 'failed', null, $reason);
            if ($kind === 'incident' && ak_signup_ack_due($pdo, $lead['email'], $signup_id)) {
                // App iPhone : le même accusé que pour une demande enregistrée (« validation sous 48 h »)
                if (ak_is_ios_app() && function_exists('ak_signup_review_mail_received')) {
                    ak_signup_review_mail_received($lead['email'], $lead['first_name'], $lead['org_name']);
                } elseif (function_exists('ak_signup_ack_prospect')) {
                    ak_signup_ack_prospect($lead['email'], $lead['first_name'], $lead['org_name']);
                }
            }
        }
    } catch (Throwable $e) {
        error_log('[signup] fallback: ' . get_class($e));
    }
    // App iPhone : ni « essai » ni promesse d'ouverture automatique, la demande est examinée par l'équipe
    if (ak_is_ios_app()) {
        return 'Merci ' . $lead['first_name'] . ' ! Votre demande pour « ' . $lead['org_name'] . ' » est bien enregistrée. '
             . 'Notre équipe l’examine et vous écrit à ' . $lead['email'] . ' sous 48 h. Inutile de recommencer.';
    }
    if ($kind === 'incident') {
        return 'Merci ' . $lead['first_name'] . ' ! Votre demande d’essai pour « ' . $lead['org_name'] . ' » est bien enregistrée. '
             . 'Un incident nous empêche de l’ouvrir automatiquement : notre équipe l’active pour vous et vous écrit à '
             . $lead['email'] . ' sous 24 h ouvrées. Inutile de recommencer.';
    }
    return 'Merci ' . $lead['first_name'] . ' ! Votre demande d’essai pour « ' . $lead['org_name'] . ' » est bien enregistrée. '
         . 'Par sécurité, elle ne peut pas être ouverte automatiquement pour le moment : notre équipe la vérifie et vous écrit à '
         . $lead['email'] . ' sous 24 h ouvrées. Inutile de recommencer.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once __DIR__ . '/rate-limit-helper.php';
    $csrf_ok = function_exists('check_csrf') && check_csrf($_POST['csrf_token'] ?? '');

    // Anti-robots : champ invisible rempli, ou formulaire envoyé en moins de 2 secondes.
    // On affiche un faux succès, sans rien écrire ni alerter personne.
    $form_at  = (int)($_SESSION['signup_form_at'] ?? 0);
    $is_robot = trim((string)($_POST['website'] ?? '')) !== '' || ($form_at > 0 && time() - $form_at < 2);

    // Saisie relue AVANT les contrôles : une session expirée ne vide plus un formulaire
    // de 20 champs. Jamais le mot de passe, qui n'est pas renvoyé dans la page.
    $form['org_name']   = trim((string)($_POST['org_name'] ?? ''));
    $form['first_name'] = trim((string)($_POST['first_name'] ?? ''));
    $form['last_name']  = trim((string)($_POST['last_name'] ?? ''));
    $form['email']      = strtolower(trim((string)($_POST['email'] ?? '')));
    $form['plan']       = in_array($_POST['plan'] ?? '', AK_SIGNUP_PLANS, true) ? $_POST['plan'] : 'essentiel';
    $form['accept_cgu'] = !empty($_POST['accept_cgu']);
    $t = (($_POST['org_type'] ?? '') === 'tpe') ? 'tpe' : 'asso';
    $form['type']       = $t;
    $pick = fn($k, array $allowed) => in_array((string)($_POST[$k] ?? ''), $allowed, true) ? (string)$_POST[$k] : '';
    $form['legal_form'] = $pick('legal_form', AK_SIGNUP_FORMS[$t]);
    $form['size']       = $pick('size', AK_SIGNUP_SIZES[$t]);
    $form['sector']     = $pick('sector', AK_SIGNUP_SECTORS[$t]);
    $form['role']       = $pick('role', AK_SIGNUP_ROLES[$t]);
    $form['source']     = $pick('source', AK_SIGNUP_SOURCES);
    // Champs propres à un type : ignorés pour l'autre (cachés à l'écran, ils étaient quand
    // même envoyés, contrôlés et enregistrés : RNA sur une TPE, TVA sur une association).
    $form['vat']        = ($t === 'tpe' && in_array($_POST['vat'] ?? '', ['oui', 'non'], true)) ? (string)$_POST['vat'] : '';
    $form['needs']      = array_values(array_intersect(AK_SIGNUP_NEEDS, array_map('strval', (array)($_POST['needs'] ?? []))));
    $form['rna']        = $t === 'asso' ? strtoupper(preg_replace('/\s+/', '', (string)($_POST['rna'] ?? ''))) : '';
    $form['siret']      = preg_replace('/\D+/', '', (string)($_POST['siret'] ?? ''));
    $form['street']     = trim((string)($_POST['street'] ?? ''));
    $form['zip']        = preg_replace('/\s+/', '', (string)($_POST['zip'] ?? ''));
    $form['city']       = trim((string)($_POST['city'] ?? ''));
    $form['phone']      = trim((string)($_POST['phone'] ?? ''));
    $form['website']    = trim((string)($_POST['site_web'] ?? ''));
    $form['message']    = trim((string)($_POST['message'] ?? ''));

    if (!$csrf_ok) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        $error = 'Session expirée. Veuillez réessayer.';
    } elseif ($is_robot) {
        $notice = 'Merci ! Votre demande est bien enregistrée.';
    } else {
        $password           = (string)($_POST['password'] ?? '');
        $password_confirm   = (string)($_POST['password_confirm'] ?? '');
        $no_link            = '~https?://|www\.|[<>]~i';
        // L'e-mail est l'identifiant de connexion (et la clé des limites par adresse) : une
        // colonne plus courte le couperait en silence (compte inutilisable). On refuse plutôt.
        $email_max = min(190, ak_signup_col_max($pdo, 'users', 'email') ?? 190, ak_signup_col_max($pdo, 'organizations', 'billing_email') ?? 190,
                         ak_signup_col_max($pdo, 'public_signups', 'admin_email') ?? 190);

        // Vocabulaire du type de structure choisi (TPE : « entreprise »), comme les libellés du formulaire
        $org_noun = $t === 'tpe' ? 'de votre entreprise' : 'de l’association';
        if ($form['org_name'] === '' || mb_strlen($form['org_name']) < 2) {
            $error = 'Le nom ' . $org_noun . ' est requis (2 caractères minimum).';
        } elseif (mb_strlen($form['org_name']) > 200) {
            $error = 'Le nom ' . $org_noun . ' est trop long.';
        } elseif ($form['first_name'] === '' || $form['last_name'] === '') {
            $error = 'Veuillez renseigner votre prénom et votre nom.';
        } elseif (mb_strlen($form['first_name']) > 100 || mb_strlen($form['last_name']) > 100) {
            $error = 'Prénom ou nom trop long.';
        } elseif (preg_match($no_link, $form['org_name'] . ' ' . $form['first_name'] . ' ' . $form['last_name'])) {
            $error = 'Les liens et les caractères < > ne sont pas acceptés dans les noms.';
        } elseif (!filter_var($form['email'], FILTER_VALIDATE_EMAIL)) {
            $error = 'Adresse email invalide.';
        } elseif (mb_strlen($form['email']) > $email_max) {
            $error = 'Adresse email trop longue (' . $email_max . ' caractères maximum).';
        } elseif (in_array($form['email'], ak_founder_emails(), true)) {
            $error = 'Cette adresse e-mail est réservée.';
        } elseif (mb_strlen($password) < 8) {
            $error = 'Le mot de passe doit faire au moins 8 caractères.';
        } elseif (mb_strlen($password) > 200) {
            $error = 'Le mot de passe est trop long.';
        } elseif ($password !== $password_confirm) {
            $error = 'Les mots de passe ne correspondent pas.';
        } elseif ($form['legal_form'] === '') {
            $error = 'Veuillez choisir la forme juridique.';
        } elseif ($form['rna'] !== '' && !preg_match('/^W\d{9}$/', $form['rna'])) {
            $error = 'Le numéro RNA commence par W suivi de 9 chiffres (ex. W912345678).';
        } elseif ($form['siret'] !== '' && !in_array(strlen($form['siret']), [9, 14], true)) {
            $error = 'Le SIREN compte 9 chiffres, le SIRET 14 chiffres.';
        } elseif ($form['street'] === '' || $form['city'] === '' || !preg_match('/^\d{5}$/', $form['zip'])) {
            $error = 'Veuillez renseigner l’adresse complète (rue, code postal à 5 chiffres, ville).';
        } elseif (mb_strlen($form['street']) > 200 || mb_strlen($form['city']) > 100 || preg_match($no_link, $form['street'] . ' ' . $form['city'])) {
            $error = 'Adresse invalide.';
        } elseif (!preg_match('/^\+?[0-9 .()-]{10,20}$/', $form['phone'])) {
            $error = 'Veuillez indiquer un numéro de téléphone valide.';
        } elseif ($form['website'] !== '' && (!filter_var($form['website'], FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $form['website']) || mb_strlen($form['website']) > 200)) {
            $error = 'Adresse du site web invalide (elle doit commencer par https://).';
        } elseif ($form['size'] === '' || $form['sector'] === '' || $form['role'] === '') {
            $error = $t === 'tpe' ? 'Veuillez indiquer la taille, le secteur d’activité et votre fonction.' : 'Veuillez indiquer le nombre d’adhérents, le domaine d’activité et votre fonction.';
        } elseif (mb_strlen($form['message']) > 1500) {
            $error = 'Votre message est trop long (1 500 caractères maximum).';
        } elseif (!$form['accept_cgu']) {
            $error = 'Veuillez accepter les CGU et la politique de confidentialité.';
        }

        $ip   = (string)($_SERVER['REMOTE_ADDR'] ?? '');
        $details = array_filter([
            'Type'                => AK_SIGNUP_TYPES[$form['type']],
            'Forme juridique'     => $form['legal_form'],
            'N° RNA'              => $form['rna'],
            strlen($form['siret']) === 9 ? 'SIREN' : 'SIRET' => $form['siret'],
            'TVA'                 => $form['vat'] === 'oui' ? 'Assujetti(e)' : ($form['vat'] === 'non' ? 'Non assujetti(e)' : ''),
            'Adresse'             => trim($form['street'] . ', ' . $form['zip'] . ' ' . $form['city'], ', '),
            'Téléphone'           => $form['phone'],
            'Site web'            => $form['website'],
            $form['type'] === 'tpe' ? 'Taille' : 'Adhérents' => $form['size'],
            $form['type'] === 'tpe' ? 'Secteur' : 'Domaine'  => $form['sector'],
            'Fonction du contact' => $form['role'],
            'Besoins'             => implode(', ', $form['needs']),
            'Nous a connus par'   => $form['source'],
            'Message'             => $form['message'],
        ], fn($v) => $v !== '' && $v !== null);
        $lead = ['org_name' => $form['org_name'], 'first_name' => $form['first_name'], 'last_name' => $form['last_name'],
                 'email' => $form['email'], 'phone' => $form['phone'], 'plan' => $form['plan'], 'type' => $form['type'],
                 'details' => $details, 'ip' => $ip, 'signup_id' => null, 'trial_end' => null];
        $has_status = isset(ak_db_columns($pdo, 'public_signups')['status']);

        // Anti-abus, compté seulement pour une demande VALIDE (une faute de saisie corrigée ne
        // coûte plus rien) et avant le contrôle « compte existant », qui reste ainsi limité :
        //   - 5 envois en 15 min par IP (ou /64 IPv6) ;
        //   - 5 demandes par heure par IP, tous statuts confondus (ouvertes, refusées, en panne) ;
        //   - 3 demandes par 24 h pour une même adresse e-mail.
        // Une demande valide refusée par ces limites n'est pas perdue : elle est enregistrée et
        // transmise au fondateur (ak_signup_fallback), dans la limite de 3 par heure et par IP
        // (200 en tout). Au-delà, rien n'est écrit et le formulaire le dit : rejouer l'envoi ne
        // remplit plus public_signups.
        $limited = null;
        if (!$error) {
            if (!ak_rate_limit('signup', 5, 900, ak_signup_ip_key($ip))) {
                $limited = 'Limite anti-abus : plus de 5 envois en 15 min depuis cette adresse IP.';
            }
            try {
                if (!$limited && ($n = ak_signup_ip_count($pdo, $ip)) !== null && $n >= 5) {
                    $limited = 'Limite anti-abus : ' . $n . ' demandes depuis cette adresse IP dans l’heure.';
                }
                if (!$limited && isset(ak_db_columns($pdo, 'public_signups')['admin_email'])) {
                    $st = $pdo->prepare("SELECT COUNT(*) FROM public_signups WHERE admin_email = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)");
                    $st->execute([$form['email']]);
                    if (($n = (int)$st->fetchColumn()) >= 3) $limited = 'Limite anti-abus : ' . $n . ' demandes pour cette adresse e-mail en 24 h.';
                }
            } catch (Throwable $e) {
                error_log('[signup] limites: ' . get_class($e));
            }
            if ($limited !== null && (!ak_rate_limit('signup_limited', 3, 3600, ak_signup_ip_key($ip)) || !ak_rate_limit('signup_limited_all', 200, 3600, 'all'))) {
                error_log('[signup] demande limitée non enregistrée (plafond horaire atteint)');
                $error = ($review ? 'Trop de demandes' : 'Trop de demandes d’essai') . ' en peu de temps. Merci de réessayer dans une heure, ou écrivez-nous depuis la page Contact.';
            }
        }

        // Compte déjà existant (pas consulté pour une demande limitée : la réponse ne dit rien)
        $db_trouble = null;
        if (!$error && !$limited) {
            try {
                $st = $pdo->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
                $st->execute([$form['email']]);
                if ($st->fetchColumn()) {
                    $error = 'Un compte existe déjà avec cette adresse email. Connectez-vous, ou utilisez « Mot de passe oublié ».';
                }
            } catch (Throwable $e) {
                $db_trouble = get_class($e) . ': ' . $e->getMessage();
            }
        }

        if (!$error) {
            // Trace de la demande, AVANT toute création : rien n'est jamais perdu
            $signup_id = null;
            try {
                $signup_id = ak_db_insert($pdo, 'public_signups', [
                    'org_name' => mb_substr($form['org_name'], 0, 200), 'admin_email' => $form['email'],
                    'admin_first_name' => mb_substr($form['first_name'], 0, 100), 'admin_last_name' => mb_substr($form['last_name'], 0, 100),
                    'plan_choice' => $form['plan'], 'ip_address' => $ip,
                    'user_agent' => mb_substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500),
                    'referer' => mb_substr($_SERVER['HTTP_REFERER'] ?? '', 0, 500),
                    'org_type' => $form['type'], 'phone' => mb_substr($form['phone'], 0, 40),
                    'details_json' => json_encode($details, JSON_UNESCAPED_UNICODE),
                    'status' => 'pending',
                ], ['created_at' => 'NOW()']) ?: null;
            } catch (Throwable $e) {
                error_log('[signup] public_signups: ' . get_class($e) . ' ' . $e->getCode());
            }
            $lead['signup_id'] = $signup_id;

            // Disjoncteur anti-abus : plus de 30 essais ouverts en 1 h → les demandes sont
            // transmises au fondateur au lieu d'être ouvertes automatiquement.
            $breaker = false;
            try {
                if ($has_status) {
                    $breaker = (int)$pdo->query("SELECT COUNT(*) FROM public_signups WHERE status = 'completed' AND created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)")->fetchColumn() >= 30;
                }
            } catch (Throwable $e) {}

            if ($limited !== null) {
                $notice = ak_signup_fallback($pdo, $lead, $signup_id, $limited . ' Vérifiez puis créez l’espace.', 'limit');
            } elseif ($db_trouble !== null) {
                $notice = ak_signup_fallback($pdo, $lead, $signup_id, 'Contrôle du doublon impossible : ' . $db_trouble);
            } elseif ($breaker) {
                $notice = ak_signup_fallback($pdo, $lead, $signup_id, 'Plus de 30 essais ouverts en 1 h : ouverture automatique suspendue (anti-abus). Vérifiez puis créez l’espace.', 'breaker');
            } else {
                $warnings = [];
                $org_id = 0; $user_id = 0;
                try {
                    // App iPhone : sans moyen de marquer la demande « en attente », rien n'est créé (incident
                    // ci-dessous : demande gardée, fondateur prévenu) plutôt qu'un espace ouvert sans validation
                    if ($review && !ak_signup_review_supported($pdo)) {
                        throw new RuntimeException('Demande de l’app iPhone : organizations ne permet pas l’état « en attente de validation » (suspended_reason, validation_status, status).');
                    }
                    $trial_end  = (string)$pdo->query("SELECT DATE_ADD(NOW(), INTERVAL " . (int)AK_TRIAL_DAYS . " DAY)")->fetchColumn();
                    if (!$review) $lead['trial_end'] = $trial_end;
                    $trial_plan = ak_trial_plan($pdo);
                    if (!$trial_plan && !$review) $warnings[] = 'Aucun plan d’essai trouvé (asso_plans) : l’association a le plan par défaut.';
                    $slug = ak_signup_slug($pdo, $form['org_name']);
                    // Slug taillé sur la colonne réelle : jamais coupé en silence à l'écriture
                    // (un slug tronqué n'est plus celui dont on a vérifié l'unicité).
                    $slug_max = ak_signup_col_max($pdo, 'organizations', 'slug') ?? 255;
                    if (strlen($slug) > $slug_max) throw new RuntimeException('organizations.slug trop court (' . $slug_max . ') pour « ' . $slug . ' »');

                    $pdo->beginTransaction();

                    // 1. Association — la même forme d'INSERT que les créations fondateur, éprouvées en prod
                    try {
                        $org_id = ak_db_insert($pdo, 'organizations',
                            ['name' => mb_substr($form['org_name'], 0, 200), 'slug' => $slug, 'billing_email' => $form['email']],
                            ['created_at' => 'NOW()']);
                    } catch (PDOException $e) {
                        if ((string)$e->getCode() !== '23000') throw $e;   // slug pris entre-temps : un autre
                        $slug = substr($slug, 0, max(1, min(50, $slug_max - 5))) . '-' . bin2hex(random_bytes(2));
                        $org_id = ak_db_insert($pdo, 'organizations',
                            ['name' => mb_substr($form['org_name'], 0, 200), 'slug' => $slug, 'billing_email' => $form['email']],
                            ['created_at' => 'NOW()']);
                    }
                    if ($org_id <= 0) throw new RuntimeException('organizations: id introuvable après INSERT');

                    if ($review) {
                        // App iPhone : demande en attente de validation, rien d'actif (ni essai, ni plan)
                        ak_db_update($pdo, 'organizations', $org_id,
                            ['status' => 'suspended', 'suspended_reason' => AK_SIGNUP_REVIEW_REASON, 'validation_status' => 'pending_founder']);
                    } else {
                        // Essai actif tout de suite, sans validation manuelle
                        $upd = ['trial_ends_at' => $trial_end];
                        if ($trial_plan && ak_db_accepts($pdo, 'organizations', 'plan', $trial_plan['slug'])) $upd['plan'] = $trial_plan['slug'];
                        if ($v = ak_db_pick($pdo, 'organizations', 'status', ['trial', 'active'])) $upd['status'] = $v;
                        if ($v = ak_db_pick($pdo, 'organizations', 'validation_status', ['validated', 'approved'])) $upd['validation_status'] = $v;
                        ak_db_update($pdo, 'organizations', $org_id, $upd, ['validated_at' => 'NOW()']);
                    }

                    // 2. Admin de l'association
                    $colors = ['#3b82f6', '#8b5cf6', '#f59e0b', '#ec4899', '#14b8a6'];
                    $user_id = ak_db_insert($pdo, 'users', [
                        'org_id' => $org_id, 'role' => 'admin', 'email' => $form['email'],
                        'password_hash' => password_hash($password, PASSWORD_BCRYPT),
                        'first_name' => mb_substr($form['first_name'], 0, 100), 'last_name' => mb_substr($form['last_name'], 0, 100),
                        'avatar_color' => $colors[array_rand($colors)],
                        'must_change_password' => 0, 'is_active' => 1,
                        'can_create_projects' => 1, 'can_create_folders' => 1, 'can_manage_members' => 1, 'can_manage_finances' => 1,
                        'can_access_marketing' => 1, 'can_manage_events' => 1, 'can_moderate_messages' => 1,
                    ], ['adhesion_date' => 'CURDATE()', 'created_at' => 'NOW()']);
                    if ($user_id <= 0) throw new RuntimeException('users: id introuvable après INSERT');
                    ak_db_update($pdo, 'organizations', $org_id, ['created_by_user_id' => $user_id]);

                    // Demande relue avant d'être gardée : une valeur refusée ou coupée par la base ouvrirait
                    // l'espace sans validation (ak_org_access ne le reconnaîtrait pas comme « en attente »)
                    if ($review) {
                        $chk = $pdo->prepare('SELECT * FROM organizations WHERE id = ?');
                        $chk->execute([$org_id]);
                        if (!ak_signup_review_pending($chk->fetch(PDO::FETCH_ASSOC) ?: [])) {
                            throw new RuntimeException('organizations : la demande n’a pas pu être marquée « en attente de validation ».');
                        }
                    }

                    $pdo->commit();
                } catch (Throwable $e) {
                    try { if ($pdo->inTransaction()) $pdo->rollBack(); } catch (Throwable $e0) {}
                    error_log('[signup] création: ' . get_class($e) . ': ' . $e->getMessage());
                    $notice = ak_signup_fallback($pdo, $lead, $signup_id, get_class($e) . ': ' . $e->getMessage());
                    $org_id = 0;
                }

                if ($org_id > 0 && $user_id > 0) {
                    // 3a. Fiche de la structure (hors transaction : un format inattendu ne coûte jamais le compte)
                    try {
                        $is_pres = in_array($form['role'], ['Président(e)', 'Gérant(e) / dirigeant(e)'], true);
                        $notes = "Inscription en ligne le " . date('d/m/Y H:i') . ($review ? " (demande depuis l'app iPhone, à valider)" : '') . "\n";
                        foreach ($details as $k => $v) $notes .= $k . ' : ' . $v . "\n";
                        $fiche = [
                            'org_type' => $form['type'],
                            'legal_name' => mb_substr($form['org_name'], 0, 200),
                            'legal_form' => mb_substr($form['legal_form'], 0, 50),
                            'rna_number' => $form['rna'] ?: null,
                            'siren' => $form['siret'] !== '' ? substr($form['siret'], 0, 9) : null,
                            'siret' => strlen($form['siret']) === 14 ? $form['siret'] : null,
                            'billing_address_street' => mb_substr($form['street'], 0, 200),
                            'billing_address_zip' => $form['zip'],
                            'billing_address_city' => mb_substr($form['city'], 0, 100),
                            'billing_address_country' => 'FR',   // code ISO, comme le reste de l'appli
                            'billing_phone' => mb_substr($form['phone'], 0, 30),
                        ];
                        if ($form['vat'] !== '') $fiche['vat_subject'] = $form['vat'] === 'oui' ? 1 : 0;
                        if ($is_pres) {
                            $fiche['president_first_name'] = mb_substr($form['first_name'], 0, 100);
                            $fiche['president_last_name'] = mb_substr($form['last_name'], 0, 100);
                            $fiche['president_role'] = mb_substr($form['role'], 0, 50);
                        }
                        ak_db_update($pdo, 'organizations', $org_id, $fiche);
                    } catch (Throwable $e) {
                        $warnings[] = 'Fiche de la structure incomplète (' . get_class($e) . ' ' . mb_substr($e->getMessage(), 0, 120) . ') : les informations sont dans cet e-mail.';
                    }
                    // Résumé complet dans les notes internes (cockpit fondateur), à part : un champ trop
                    // court ne doit pas faire perdre la fiche ci-dessus.
                    try {
                        $cols_o = ak_db_columns($pdo, 'organizations');
                        $note_col = isset($cols_o['internal_notes']) ? 'internal_notes' : (isset($cols_o['notes_superadmin']) ? 'notes_superadmin' : null);
                        if ($note_col) ak_db_update($pdo, 'organizations', $org_id, [$note_col => $notes]);
                    } catch (Throwable $e) {
                        error_log('[signup] notes internes: ' . get_class($e));
                    }
                    try { ak_db_update($pdo, 'users', $user_id, ['phone' => mb_substr($form['phone'], 0, 30)]); } catch (Throwable $e) {}

                    // 3 bis. App iPhone : demande enregistrée, rien d'actif et pas de connexion. La page
                    // « Demande envoyée » s'affiche (après redirection : recharger ne renvoie rien), puis
                    // l'accusé de réception part au demandeur et l'alerte (Valider / Refuser) au fondateur.
                    if ($review) {
                        if ($signup_id) {
                            try { ak_db_update($pdo, 'public_signups', $signup_id, ['org_id' => $org_id, 'user_id' => $user_id]); }
                            catch (Throwable $e) { error_log('[signup] public_signups demande: ' . get_class($e)); }
                        }
                        $_SESSION['signup_request_sent'] = ['first_name' => $form['first_name'], 'org_name' => $form['org_name'], 'email' => $form['email']];
                        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                        unset($_SESSION['signup_form_at']);
                        session_write_close();

                        header('Location: /signup?demande=envoyee', true, 303);
                        ignore_user_abort(true);
                        if (function_exists('litespeed_finish_request')) litespeed_finish_request();
                        elseif (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
                        try {
                            @require_once __DIR__ . '/signup-email-helpers.php';
                            ak_signup_review_mail_received($form['email'], $form['first_name'], $form['org_name']);
                            if (function_exists('ak_signup_alert_founder')) {
                                $lead['requested_at'] = date('Y-m-d H:i:s');
                                ak_signup_alert_founder($pdo, $lead, 'review', $org_id, null, $warnings);
                            }
                        } catch (Throwable $e) {
                            error_log('[signup] e-mails demande: ' . get_class($e));
                        }
                        exit;
                    }

                    // 3. Éléments de l'essai, hors transaction : un échec ici ne coûte jamais le compte.
                    $warnings = array_merge($warnings, ak_signup_trial_rows($pdo, $org_id, $user_id, $trial_end, $trial_plan));
                    if ($signup_id) {
                        try {
                            ak_db_update($pdo, 'public_signups', $signup_id, ['org_id' => $org_id, 'user_id' => $user_id, 'status' => 'completed'], ['completed_at' => 'NOW()']);
                        } catch (Throwable $e) {
                            error_log('[signup] public_signups completed: ' . get_class($e));
                        }
                    }

                    // 4. Connexion automatique (avant les e-mails : si l'envoi est lent, le prospect est déjà connecté)
                    session_regenerate_id(true);
                    $_SESSION['user_id'] = $user_id;
                    $_SESSION['org_id'] = $org_id;
                    $_SESSION['user_email'] = $form['email'];
                    $_SESSION['user_name'] = $form['first_name'] . ' ' . $form['last_name'];
                    $_SESSION['user_role'] = 'admin';
                    $_SESSION['is_super_admin'] = 0;
                    $_SESSION['logged_at'] = time();
                    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                    $_SESSION['signup_welcome'] = 1;
                    unset($_SESSION['signup_form_at']);
                    session_write_close();

                    header('Location: /dashboard?welcome=1');
                    // La réponse part tout de suite ; les e-mails sont envoyés ensuite.
                    ignore_user_abort(true);
                    if (function_exists('litespeed_finish_request')) litespeed_finish_request();
                    elseif (function_exists('fastcgi_finish_request')) fastcgi_finish_request();

                    // 5. E-mails : bienvenue au prospect, alerte au fondateur (jamais bloquants)
                    try {
                        @require_once __DIR__ . '/signup-email-helpers.php';
                        $verify_url = function_exists('ak_email_verify_url') ? ak_email_verify_url($user_id) : 'https://assokit.fr/connexion';
                        if (function_exists('send_demo_welcome_email')) {
                            send_demo_welcome_email($form['email'], $form['first_name'], $form['org_name'], $verify_url, ak_trial_end_label($trial_end), $form['type']);
                        }
                        if (function_exists('ak_signup_alert_founder')) {
                            ak_signup_alert_founder($pdo, $lead, $warnings ? 'incomplete' : 'ok', $org_id, null, $warnings);
                        }
                    } catch (Throwable $e) {
                        error_log('[signup] e-mails: ' . get_class($e));
                    }
                    exit;
                }
            }
        }
    }
}

// Heure d'affichage du formulaire (anti-robots : un humain met plus de 2 secondes). Après une
// demande traitée, jeton neuf : un chargement de page ne sert qu'à un seul envoi.
if ($notice !== null) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$_SESSION['signup_form_at'] = time();
// App iPhone, après l'envoi (redirection) : « Demande envoyée » à la place du formulaire
$sent = ($review && ($_GET['demande'] ?? '') === 'envoyee' && is_array($_SESSION['signup_request_sent'] ?? null))
    ? $_SESSION['signup_request_sent'] : null;
?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex">
<?php // Dans l'application (ak_billing_hidden) : ni « gratuit », ni « PRO », ni carte bancaire — rien qui évoque un achat (Apple 3.1.1 / 3.1.3 f) ?>
<title><?= $review ? ($sent !== null ? 'Demande envoyée' : 'Demander la création de votre espace') : (ak_billing_hidden() ? 'Créer votre espace' : 'Essai PRO gratuit ' . (int)AK_TRIAL_DAYS . ' jours') ?> — Assokit</title>
<link rel="icon" type="image/svg+xml" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'%3E%3Crect x='2' y='2' width='28' height='28' rx='7' fill='%23059669'/%3E%3Ccircle cx='22' cy='22' r='4.5' fill='%23FFFFFF'/%3E%3C/svg%3E">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Geist:wght@400;500;600&display=swap" rel="stylesheet">
<style>
* { box-sizing: border-box; margin: 0; padding: 0; }
:root {
  --acc: #059669; --acc-dark: #047857; --acc-light: #D1FAE5;
  --ink: #0A0A0B; --ink-2: #3F3F46; --ink-3: #71717A;
  --bg: #FFFFFF; --bg-2: #FAFAF9;
  --border: rgba(0,0,0,0.08); --border-strong: rgba(0,0,0,0.14);
}
body {
  font-family: 'Geist', -apple-system, BlinkMacSystemFont, sans-serif;
  background: var(--bg-2); color: var(--ink); min-height: 100vh;
  display: flex; align-items: center; justify-content: center;
  padding: 24px; font-size: 15px; line-height: 1.5;
  -webkit-font-smoothing: antialiased;
}
.wrap { max-width: 460px; width: 100%; }
.logo-wrap { text-align: center; margin-bottom: 24px; }
.logo-mark {
  display: inline-block; width: 48px; height: 48px;
  background: var(--acc); border-radius: 12px; position: relative;
  box-shadow: 0 4px 14px rgba(5, 150, 105, 0.2);
}
.logo-mark::after {
  content: ''; position: absolute;
  right: 10px; bottom: 10px;
  width: 12px; height: 12px;
  background: white; border-radius: 50%;
}
.logo-name { display: block; margin-top: 10px; font-size: 14px; color: var(--ink-3); letter-spacing: 0.05em; }

.card {
  background: var(--bg); border: 1px solid var(--border);
  border-radius: 16px; padding: 32px 28px;
  box-shadow: 0 10px 30px rgba(0,0,0,0.04);
}
h1 { font-size: 22px; font-weight: 500; margin-bottom: 6px; letter-spacing: -0.02em; }
.sub { font-size: 13.5px; color: var(--ink-2); margin-bottom: 22px; line-height: 1.55; }

.plan-pill {
  display: inline-flex; align-items: center; gap: 8px;
  padding: 4px 12px; background: var(--acc-light); color: var(--acc-dark);
  border-radius: 999px; font-size: 12px; font-weight: 500; margin-bottom: 18px;
}
.plan-pill-dot { width: 6px; height: 6px; background: var(--acc); border-radius: 50%; }

.alert-error {
  background: #FEF2F2; border: 1px solid #FCA5A5;
  color: #991B1B; padding: 12px 14px;
  border-radius: 10px; font-size: 13px;
  margin-bottom: 16px; line-height: 1.5;
}

.form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
@media (max-width: 430px) { .form-row { grid-template-columns: 1fr; } }
.form-group { margin-bottom: 12px; }
label { display: block; font-size: 12.5px; font-weight: 500; color: var(--ink-2); margin-bottom: 6px; }
input[type="text"], input[type="email"], input[type="password"], input[type="tel"], input[type="url"] {
  width: 100%; padding: 11px 13px;
  background: var(--bg-2); border: 1px solid var(--border-strong);
  border-radius: 9px; font-family: inherit; font-size: 14px; color: var(--ink);
  transition: border-color 0.15s, background 0.15s;
}
input:focus { outline: none; border-color: var(--acc); background: var(--bg); box-shadow: 0 0 0 3px rgba(5, 150, 105, 0.1); }

.pw-hint { font-size: 11.5px; color: var(--ink-3); margin-top: 5px; }

.cgu-row {
  display: flex; align-items: flex-start; gap: 8px;
  font-size: 12.5px; color: var(--ink-2); margin: 14px 0 16px;
  line-height: 1.5;
}
.cgu-row input[type="checkbox"] { margin-top: 2px; accent-color: var(--acc); flex-shrink: 0; }
.cgu-row a { color: var(--acc); text-decoration: none; }
.cgu-row a:hover { text-decoration: underline; }

.btn-submit {
  width: 100%; background: var(--ink); color: var(--bg);
  border: none; padding: 13px 20px; border-radius: 10px;
  font-family: inherit; font-size: 14.5px; font-weight: 500;
  cursor: pointer; transition: opacity 0.15s, transform 0.15s;
}
.btn-submit:hover { opacity: 0.88; transform: translateY(-1px); }

.login-link { display: block; text-align: center; margin-top: 18px; font-size: 13px; color: var(--ink-3); }
.login-link a { color: var(--acc); text-decoration: none; font-weight: 500; }
.login-link a:hover { text-decoration: underline; }

.trust-line {
  text-align: center; font-size: 11.5px; color: var(--ink-3);
  margin-top: 18px; padding-top: 14px; border-top: 1px solid var(--border);
  display: flex; justify-content: center; gap: 14px; flex-wrap: wrap;
}
.wrap { max-width: 560px; }
.sec-title { font-size: 12px; font-weight: 600; text-transform: uppercase; letter-spacing: .06em; color: var(--acc-dark); margin: 20px 0 10px; padding-top: 14px; border-top: 1px solid var(--border); }
.sec-title .opt, label .opt { text-transform: none; letter-spacing: 0; font-weight: 400; color: var(--ink-3); }
.type-switch { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; margin-bottom: 4px; }
.type-opt { position: relative; cursor: pointer; }
.type-opt input { position: absolute; opacity: 0; pointer-events: none; }
.type-opt span { display: block; text-align: center; padding: 12px 8px; border: 1.5px solid var(--border-strong); border-radius: 12px; font-size: 14px; font-weight: 500; background: #fff; transition: all .15s; }
.type-opt input:checked + span { border-color: var(--acc); background: var(--acc-light); color: var(--acc-dark); font-weight: 600; }
.type-opt input:focus-visible + span { outline: 2px solid var(--acc); outline-offset: 2px; }
.form-group select, .form-group textarea { width: 100%; padding: 11px 13px; border: 1px solid var(--border-strong); border-radius: 9px; font-size: 14px; font-family: inherit; background: var(--bg-2); color: var(--ink); }
.form-group textarea { resize: vertical; min-height: 74px; }
.form-group select:focus, .form-group textarea:focus { outline: none; border-color: var(--acc); box-shadow: 0 0 0 3px rgba(5,150,105,.12); }
.form-row-zip { grid-template-columns: 120px 1fr; }
.needs { display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 12px; }
.need { cursor: pointer; }
.need input { position: absolute; opacity: 0; pointer-events: none; }
.need span { display: inline-block; padding: 7px 11px; border: 1px solid var(--border-strong); border-radius: 999px; font-size: 13px; background: #fff; color: var(--ink-2); }
.need input:checked + span { border-color: var(--acc); background: var(--acc-light); color: var(--acc-dark); font-weight: 500; }
.need input:focus-visible + span { outline: 2px solid var(--acc); }
form[data-type="asso"] .only-tpe, form[data-type="tpe"] .only-asso { display: none; }
@media (max-width: 480px) { .form-row { grid-template-columns: 1fr; } .form-row-zip { grid-template-columns: 110px 1fr; } }
/* iPhone (Safari et appli) : un champ sous 16 px fait zoomer la page au toucher, sans dézoomer ensuite */
@media (max-width: 768px) {
  input[type="text"], input[type="email"], input[type="password"], input[type="tel"], input[type="url"],
  .form-group select, .form-group textarea { font-size: 16px; }
}
</style>
</head>
<body>

<div class="wrap">

  <div class="logo-wrap">
    <a href="/" style="display:inline-block;">
      <span class="logo-mark"></span>
    </a>
    <span class="logo-name">ASSOKIT</span>
  </div>

  <div class="card">

    <?php // Anciens liens ?plan= : « association » = formule Assokit, « organisation » = Sur-mesure ?>
    <?php if ($review): // App iPhone : une demande, examinée par l'équipe (ni prix, ni essai, ni gratuité, ni abonnement) ?>
      <span class="plan-pill"><span class="plan-pill-dot"></span>Validation par notre équipe sous <?= (int)AK_SIGNUP_REVIEW_HOURS ?> h</span>
    <?php elseif (ak_billing_hidden()): ?>
      <span class="plan-pill"><span class="plan-pill-dot"></span>Aucun achat dans l’application</span>
    <?php elseif ($form['plan'] === 'association'): ?>
      <span class="plan-pill"><span class="plan-pill-dot"></span>Formule Assokit · essai PRO gratuit <?= (int)AK_TRIAL_DAYS ?> jours</span>
    <?php elseif ($form['plan'] === 'organisation'): ?>
      <span class="plan-pill"><span class="plan-pill-dot"></span>Formule Sur-mesure · essai PRO gratuit <?= (int)AK_TRIAL_DAYS ?> jours</span>
    <?php else: ?>
      <span class="plan-pill"><span class="plan-pill-dot"></span>Essai PRO gratuit <?= (int)AK_TRIAL_DAYS ?> jours · sans carte bancaire</span>
    <?php endif; ?>

    <?php // Dans l'application : aucun prix ni mot d'achat (règles de paiement Google Play, Apple 3.1.1 / 3.1.3 f).
          // La durée reste dite : l'utilisateur sait que l'accès complet dure AK_TRIAL_DAYS jours (CGU § 4). ?>
    <?php if ($sent !== null): ?>
    <h1>Demande envoyée</h1>
    <p class="sub">Notre équipe l’examine sous <?= (int)AK_SIGNUP_REVIEW_HOURS ?> h. Vous recevrez un e-mail dès l’activation de votre espace.</p>
    <?php elseif ($review): ?>
    <h1>Demander la création de votre espace</h1>
    <p class="sub">Votre demande est examinée par notre équipe sous <?= (int)AK_SIGNUP_REVIEW_HOURS ?> h. Un e-mail vous confirme l’activation de votre espace : vous pourrez alors vous connecter avec l’adresse e-mail et le mot de passe choisis ici.</p>
    <?php elseif (ak_billing_hidden()): ?>
    <h1>Créez l’espace de votre structure</h1>
    <p class="sub">Toutes les fonctionnalités sont ouvertes dès l’inscription, pendant <?= (int)AK_TRIAL_DAYS ?> jours.</p>
    <?php else: ?>
    <h1>Démarrez votre essai PRO gratuit</h1>
    <p class="sub">Toutes les fonctionnalités PRO pendant <?= (int)AK_TRIAL_DAYS ?> jours, activées dès l’inscription. Sans carte bancaire, sans engagement.
      Ensuite, si Assokit vous convient : <?= htmlspecialchars(ak_offer_price($pdo)['label']) ?>, résiliable à tout moment. Sinon, rien n’est prélevé.</p>
    <?php endif; ?>

    <?php if ($sent !== null): ?>
      <div class="alert-ok" id="ak-demande-envoyee" style="background:#ECFDF5;border:1px solid #A7F3D0;color:#065F46;padding:14px 16px;border-radius:12px;font-size:14px;line-height:1.55;margin-bottom:14px;">✅ Merci <?= htmlspecialchars((string)($sent['first_name'] ?? '')) ?>&nbsp;! La demande de création de l’espace « <?= htmlspecialchars((string)($sent['org_name'] ?? '')) ?> » est bien envoyée.</div>
      <p class="sub">Un e-mail de confirmation vient de partir à <strong><?= htmlspecialchars((string)($sent['email'] ?? '')) ?></strong>. Dès que notre équipe a activé votre espace, vous recevez un second e-mail : connectez-vous alors avec cette adresse et le mot de passe choisi.</p>
      <a href="/connexion" class="btn-submit" style="display:block;text-align:center;text-decoration:none;">Retour à la connexion</a>
      <?php // Retrait de la demande et effacement des informations, dans l'app (règle 5.1.1 v d'Apple) ?>
      <p class="sub" style="font-size:12.5px;margin:12px 0 0;text-align:center;">Vous ne souhaitez plus de cet espace&nbsp;? <a href="/retirer-demande" style="color:var(--acc);font-weight:500;text-decoration:none;">Retirer ma demande</a></p>
    <?php elseif ($notice): ?>
      <div class="alert-ok" style="background:#ECFDF5;border:1px solid #A7F3D0;color:#065F46;padding:14px 16px;border-radius:12px;font-size:14px;line-height:1.55;margin-bottom:6px;">✅ <?= htmlspecialchars($notice) ?></div>
    <?php else: ?>
    <?php if ($error): ?>
      <div class="alert-error">⚠️ <?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <?php
      $opt = function (array $list, string $sel) { $o = '<option value="">Choisir…</option>';
          foreach ($list as $v) $o .= '<option value="' . htmlspecialchars($v) . '"' . ($v === $sel ? ' selected' : '') . '>' . htmlspecialchars($v) . '</option>';
          return $o; };
      $ty = $form['type'];
    ?>
    <form method="POST" autocomplete="off" id="signupForm" data-type="<?= $ty ?>">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
      <input type="hidden" name="plan" value="<?= htmlspecialchars($form['plan']) ?>">
      <!-- Champ piège anti-robots : invisible pour les humains -->
      <div aria-hidden="true" style="position:absolute;left:-10000px;top:auto;width:1px;height:1px;overflow:hidden;">
        <label for="website">Site web</label>
        <input type="text" id="website" name="website" tabindex="-1" autocomplete="off" value="">
      </div>

      <div class="type-switch" role="radiogroup" aria-label="Vous êtes">
        <label class="type-opt"><input type="radio" name="org_type" value="asso"<?= $ty === 'asso' ? ' checked' : '' ?>><span>🤝 Une association</span></label>
        <label class="type-opt"><input type="radio" name="org_type" value="tpe"<?= $ty === 'tpe' ? ' checked' : '' ?>><span>💼 Une TPE / entreprise</span></label>
      </div>

      <div class="sec-title">Votre structure</div>
      <div class="form-group">
        <label for="org_name" data-asso="Nom de votre association *" data-tpe="Nom de votre entreprise *"><?= $ty === 'tpe' ? 'Nom de votre entreprise *' : 'Nom de votre association *' ?></label>
        <input type="text" id="org_name" name="org_name" required maxlength="200" autofocus
               placeholder="<?= $ty === 'tpe' ? 'Ex : Atelier Dupont' : 'Ex : Latitude 91' ?>" value="<?= htmlspecialchars($form['org_name']) ?>">
      </div>
      <div class="form-row">
        <div class="form-group">
          <label for="legal_form">Forme juridique *</label>
          <select id="legal_form" name="legal_form" required data-list="forms"><?= $opt(AK_SIGNUP_FORMS[$ty], $form['legal_form']) ?></select>
        </div>
        <div class="form-group">
          <label for="sector" data-asso="Domaine d’activité *" data-tpe="Secteur d’activité *"><?= $ty === 'tpe' ? 'Secteur d’activité *' : 'Domaine d’activité *' ?></label>
          <select id="sector" name="sector" required data-list="sectors"><?= $opt(AK_SIGNUP_SECTORS[$ty], $form['sector']) ?></select>
        </div>
      </div>
      <div class="form-row">
        <div class="form-group only-asso">
          <label for="rna">N° RNA <span class="opt">(facultatif)</span></label>
          <input type="text" id="rna" name="rna" maxlength="12" placeholder="W912345678" value="<?= htmlspecialchars($form['rna']) ?>">
        </div>
        <div class="form-group">
          <label for="siret">SIRET ou SIREN <span class="opt">(facultatif)</span></label>
          <input type="text" id="siret" name="siret" maxlength="20" inputmode="numeric" placeholder="14 chiffres" value="<?= htmlspecialchars($form['siret']) ?>">
        </div>
        <div class="form-group only-tpe">
          <label for="vat">TVA</label>
          <select id="vat" name="vat">
            <option value="">Je ne sais pas</option>
            <option value="oui"<?= $form['vat'] === 'oui' ? ' selected' : '' ?>>Assujetti(e)</option>
            <option value="non"<?= $form['vat'] === 'non' ? ' selected' : '' ?>>Non assujetti(e)</option>
          </select>
        </div>
      </div>
      <div class="form-group">
        <label for="street">Adresse *</label>
        <input type="text" id="street" name="street" required maxlength="200" autocomplete="street-address" placeholder="12 rue des Lilas" value="<?= htmlspecialchars($form['street']) ?>">
      </div>
      <div class="form-row form-row-zip">
        <div class="form-group">
          <label for="zip">Code postal *</label>
          <input type="text" id="zip" name="zip" required maxlength="5" inputmode="numeric" pattern="[0-9]{5}" autocomplete="postal-code" placeholder="91000" value="<?= htmlspecialchars($form['zip']) ?>">
        </div>
        <div class="form-group">
          <label for="city">Ville *</label>
          <input type="text" id="city" name="city" required maxlength="100" autocomplete="address-level2" placeholder="Évry-Courcouronnes" value="<?= htmlspecialchars($form['city']) ?>">
        </div>
      </div>
      <div class="form-row">
        <div class="form-group">
          <label for="size" data-asso="Nombre d’adhérents *" data-tpe="Taille de l’équipe *"><?= $ty === 'tpe' ? 'Taille de l’équipe *' : 'Nombre d’adhérents *' ?></label>
          <select id="size" name="size" required data-list="sizes"><?= $opt(AK_SIGNUP_SIZES[$ty], $form['size']) ?></select>
        </div>
        <div class="form-group">
          <label for="site_web">Site web <span class="opt">(facultatif)</span></label>
          <input type="url" id="site_web" name="site_web" maxlength="200" placeholder="https://…" value="<?= htmlspecialchars($form['website']) ?>">
        </div>
      </div>

      <div class="sec-title">Vous</div>
      <div class="form-row">
        <div class="form-group">
          <label for="first_name">Prénom *</label>
          <input type="text" id="first_name" name="first_name" required maxlength="100" autocomplete="given-name" value="<?= htmlspecialchars($form['first_name']) ?>">
        </div>
        <div class="form-group">
          <label for="last_name">Nom *</label>
          <input type="text" id="last_name" name="last_name" required maxlength="100" autocomplete="family-name" value="<?= htmlspecialchars($form['last_name']) ?>">
        </div>
      </div>
      <div class="form-row">
        <div class="form-group">
          <label for="role">Votre fonction *</label>
          <select id="role" name="role" required data-list="roles"><?= $opt(AK_SIGNUP_ROLES[$ty], $form['role']) ?></select>
        </div>
        <div class="form-group">
          <label for="phone">Téléphone *</label>
          <input type="tel" id="phone" name="phone" required maxlength="20" autocomplete="tel" placeholder="06 12 34 56 78" value="<?= htmlspecialchars($form['phone']) ?>">
        </div>
      </div>
      <div class="form-group">
        <label for="email">Votre e-mail *</label>
        <input type="email" id="email" name="email" required maxlength="190" autocomplete="email"
               placeholder="<?= $ty === 'tpe' ? 'vous@entreprise.fr' : 'vous@association.fr' ?>" value="<?= htmlspecialchars($form['email']) ?>">
      </div>
      <div class="form-group">
        <label for="password">Mot de passe *</label>
        <div style="position:relative;">
          <input type="password" id="password" name="password" required minlength="8" autocomplete="new-password" style="padding-right:44px;">
          <button type="button" id="togglePwd" aria-label="Afficher le mot de passe" aria-pressed="false"
            style="position:absolute;top:50%;right:8px;transform:translateY(-50%);background:none;border:0;cursor:pointer;padding:6px;color:#64748B;font-size:14px;">👁</button>
        </div>
        <div class="pw-hint">8 caractères minimum — choisissez un mot de passe robuste.</div>
      </div>

      <div class="form-group">
        <label for="password_confirm">Confirmez le mot de passe *</label>
        <input type="password" id="password_confirm" name="password_confirm" required minlength="8" autocomplete="new-password">
        <div id="pwMatchMsg" class="pw-hint" style="display:none;color:#DC2626;">Les mots de passe ne correspondent pas.</div>
      </div>
      <script>
        (function(){
          var b=document.getElementById('togglePwd'), p=document.getElementById('password'),
              c=document.getElementById('password_confirm'), m=document.getElementById('pwMatchMsg');
          if(b&&p){ b.addEventListener('click', function(){
            var show=p.type==='password'; p.type=show?'text':'password';
            b.setAttribute('aria-pressed', show?'true':'false');
            b.setAttribute('aria-label', show?'Masquer le mot de passe':'Afficher le mot de passe');
          }); }
          function check(){ if(!c||!m) return;
            var mismatch = c.value.length>0 && p.value!==c.value;
            m.style.display = mismatch ? 'block' : 'none';
            c.setCustomValidity(mismatch ? 'Les mots de passe ne correspondent pas.' : '');
          }
          if(p&&c){ p.addEventListener('input',check); c.addEventListener('input',check); }
        })();
      </script>


      <div class="sec-title">Vos besoins <span class="opt">(facultatif)</span></div>
      <div class="needs">
        <?php foreach (AK_SIGNUP_NEEDS as $nd): ?>
          <label class="need"><input type="checkbox" name="needs[]" value="<?= htmlspecialchars($nd) ?>"<?= in_array($nd, $form['needs'], true) ? ' checked' : '' ?>><span><?= htmlspecialchars($nd) ?></span></label>
        <?php endforeach; ?>
      </div>
      <div class="form-row">
        <div class="form-group">
          <label for="source">Comment nous avez-vous connus ?</label>
          <select id="source" name="source"><?= $opt(AK_SIGNUP_SOURCES, $form['source']) ?></select>
        </div>
      </div>
      <div class="form-group">
        <label for="message">Un mot pour nous ? <span class="opt">(facultatif)</span></label>
        <textarea id="message" name="message" maxlength="1500" rows="3" placeholder="Vos attentes, votre calendrier, une question…"><?= htmlspecialchars($form['message']) ?></textarea>
      </div>

      <label class="cgu-row">
        <input type="checkbox" name="accept_cgu" value="1" required<?= !empty($form['accept_cgu']) ? ' checked' : '' ?>>
        <span>J'accepte les <a href="/cgu" target="_blank">conditions générales d'utilisation</a> et la <a href="/confidentialite" target="_blank">politique de confidentialité</a>.</span>
      </label>

      <button type="submit" class="btn-submit" id="signupBtn"><?= $review ? 'Envoyer ma demande' : (ak_billing_hidden() ? 'Créer mon espace' : 'Activer mon essai PRO de ' . (int)AK_TRIAL_DAYS . ' jours') ?> →</button>
    </form>
    <script>
      // Association / TPE : libellés, listes et champs propres à chaque type
      (function(){
        var f=document.getElementById('signupForm'); if(!f) return;
        var L=<?= json_encode(['forms' => AK_SIGNUP_FORMS, 'sectors' => AK_SIGNUP_SECTORS, 'sizes' => AK_SIGNUP_SIZES, 'roles' => AK_SIGNUP_ROLES], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        function apply(t){
          f.setAttribute('data-type', t);
          f.querySelectorAll('label[data-asso]').forEach(function(l){ l.textContent = l.getAttribute('data-' + t); });
          f.querySelectorAll('select[data-list]').forEach(function(sel){
            var cur = sel.value, list = L[sel.getAttribute('data-list')][t];
            sel.innerHTML = '';
            var o0 = document.createElement('option'); o0.value = ''; o0.textContent = 'Choisir…'; sel.appendChild(o0);
            list.forEach(function(v){ var o=document.createElement('option'); o.value=v; o.textContent=v; if(v===cur) o.selected=true; sel.appendChild(o); });
          });
          var on = document.getElementById('org_name'); if (on) on.placeholder = t === 'tpe' ? 'Ex : Atelier Dupont' : 'Ex : Latitude 91';
          var em = document.getElementById('email'); if (em) em.placeholder = t === 'tpe' ? 'vous@entreprise.fr' : 'vous@association.fr';
          fields(t);
        }
        // Champs de l'autre type (RNA / TVA) : désactivés, donc ni envoyés ni contrôlés ; la saisie reste si on revient
        function fields(t){
          f.querySelectorAll('.only-asso input, .only-asso select').forEach(function(e){ e.disabled = t !== 'asso'; });
          f.querySelectorAll('.only-tpe input, .only-tpe select').forEach(function(e){ e.disabled = t !== 'tpe'; });
        }
        fields(f.getAttribute('data-type'));
        f.querySelectorAll('input[name=org_type]').forEach(function(r){ r.addEventListener('change', function(){ if(r.checked) apply(r.value); }); });
      })();
    </script>
    <script>
      // Un seul envoi : un double clic créait deux demandes
      (function(){ var f=document.getElementById('signupForm'), b=document.getElementById('signupBtn');
        if(f&&b) f.addEventListener('submit', function(){ setTimeout(function(){ b.disabled=true; b.textContent=<?= $review ? "'Envoi de votre demande…'" : "'Création de votre espace…'" ?>; }, 0); }); })();
    </script>
    <?php endif; ?>

    <div class="trust-line">
      <span>🇫🇷 Hébergé en France</span>
      <span>🔒 Conforme RGPD</span>
      <span>✨ IA incluse</span>
    </div>

  </div>

  <div class="login-link">
    Vous avez déjà un compte ? <a href="/connexion">Se connecter</a><br>
    Vous préférez être accompagné ? <a href="/contact">Parler à un conseiller</a>
  </div>

</div>

</body>
</html>
<?php
// Fin d'une rafale de demandes non ouvertes : le résumé en attente part à la première visite
// après l'heure (la page est déjà envoyée au visiteur).
if (is_file(rtrim(sys_get_temp_dir(), '/') . '/ak_signup_digest_pending')) {
    @require_once __DIR__ . '/signup-email-helpers.php';
    if (function_exists('ak_signup_digest_maybe')) {
        session_write_close();
        ignore_user_abort(true);
        if (function_exists('litespeed_finish_request')) litespeed_finish_request();
        elseif (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
        try { ak_signup_digest_maybe($pdo); } catch (Throwable $e) { error_log('[signup] résumé: ' . get_class($e)); }
    }
}
