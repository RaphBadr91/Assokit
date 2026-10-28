<?php
/**
 * api/app-founder-org-detail.php — Fiche détaillée d'une association (Fondateur, natif).
 * GET ?id=INT → infos éditables + membres actifs (50 premiers ; nb_users = total, members_more = le reste)
 *               + plan + note interne + plans disponibles.
 * Le plan et l'essai viennent d'ak_org_plan_state() (même vérité que le web) ; « plans »
 * liste les VRAIS plans (asso_plans) pour le sélecteur de formule.
 * Fiche complète, en lecture seule (« toutes les informations », comme l'e-mail d'inscription
 * envoyé au Fondateur et la fiche du cockpit web) :
 *   - fiche    : type, identifiants (RNA, SIREN / SIRET, TVA), adresse, téléphone, e-mails, site web,
 *                représentant légal ([{key, label, value, kind: tel|mail|url|null}]) ;
 *   - contact  : demandeur / administrateur (nom, e-mail, téléphone, fonction, e-mail confirmé,
 *                dernière connexion) ;
 *   - signup   : demande d'inscription (n°, origine site / Android / iPhone, date, formule vue sur le
 *                site, statut, adresse IP pour le Fondateur, réponses du formulaire : taille, secteur,
 *                besoins, « nous a connus par », message…) ;
 *   - review   : validation (à valider, demande iPhone, validée le … par …, refusée et motif, retirée),
 *                can_decide = Valider / Refuser possibles (Fondateur, demande en attente) ;
 *   - activity : dernière activité, projets, appli installée, impayés.
 * Réservé Fondateur/Super Admin. NE MODIFIE PAS le site : dédié à l'application.
 */
ob_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes-layout.php';
ob_end_clean();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (empty($_SESSION['user_id'])) { http_response_code(401); echo json_encode(['ok' => false, 'error' => 'auth']); exit; }
$user = function_exists('current_user') ? current_user() : null;
require_once __DIR__ . '/_app-founder.php';
// Compte désactivé ou supprimé depuis l'ouverture de la session : session fermée, 401
if (!app_session_account_alive($pdo)) { http_response_code(401); echo json_encode(['ok' => false, 'error' => 'auth']); exit; }
require_once __DIR__ . '/_app-founder-plan.php';
$is_founder = app_is_founder($pdo, $user);
$is_sa = $is_founder || !empty($user['is_super_admin']) || (($user['role'] ?? '') === 'super_admin');
if (!$is_sa) { http_response_code(403); echo json_encode(['ok' => false, 'error' => 'forbidden', 'message' => 'Fiche réservée au Fondateur et aux super-admins.'], JSON_UNESCAPED_UNICODE); exit; }

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) { http_response_code(400); echo json_encode(['ok' => false, 'error' => 'id']); exit; }

require_once __DIR__ . '/../signup-review-helpers.php';   // demande iPhone : en attente, retirée, refusée ; demandeur

/** « 19/10/2026 à 14 h 05 » (ou « 19/10/2026 » pour une date sans heure) ; '' si vide. */
function fd_when($sql): string {
    $sql = trim((string) $sql);
    if ($sql === '' || strpos($sql, '0000-00-00') === 0) return '';
    if (strlen($sql) <= 10) { $t = strtotime($sql); return $t ? date('d/m/Y', $t) : ''; }
    return ak_signup_review_when($sql);
}

try {
    $st = $pdo->prepare("SELECT * FROM organizations WHERE id = ? AND deleted_at IS NULL LIMIT 1");
    $st->execute([$id]);
    $o = $st->fetch(PDO::FETCH_ASSOC);
    if (!$o) { http_response_code(404); echo json_encode(['ok' => false, 'error' => 'not_found', 'message' => 'Association introuvable : elle a peut-être été supprimée.'], JSON_UNESCAPED_UNICODE); exit; }
    $hasNote = array_key_exists('notes_superadmin', $o);
    $hasBill = array_key_exists('billing_email', $o);
    $vstatus = (string) ($o['validation_status'] ?? '') ?: 'validated';

    // Membres : comptes actifs seulement, avec le même décompte que la liste des associations et le
    // cockpit web (is_active = 1, non supprimés). nb_users est compté à part : la liste affichée
    // s'arrête à 50 (members_more = membres actifs non affichés).
    $nb_users = 0;
    try {
        $cst = $pdo->prepare("SELECT COUNT(*) FROM users WHERE org_id = ? AND deleted_at IS NULL AND is_active = 1");
        $cst->execute([$id]);
        $nb_users = (int) $cst->fetchColumn();
    } catch (Throwable $e) {}
    $ucols = ak_db_columns($pdo, 'users');
    $members = [];
    try {
        // id + totp_enabled : « Réinitialiser la 2FA » (action reset_2fa d'app-founder-action.php)
        $totp = isset($ucols['totp_enabled']) || !$ucols ? 'totp_enabled' : '0 AS totp_enabled';
        $last = isset($ucols['last_login_at']) ? 'last_login_at' : 'NULL AS last_login_at';
        $mst = $pdo->prepare("SELECT id, first_name, last_name, email, role, $totp, $last FROM users WHERE org_id = ? AND deleted_at IS NULL AND is_active = 1 ORDER BY (role='admin') DESC, created_at ASC LIMIT 50");
        $mst->execute([$id]);
        foreach ($mst->fetchAll(PDO::FETCH_ASSOC) as $m) {
            $members[] = [
                'id'    => (int) $m['id'],
                'name'  => trim(($m['first_name'] ?? '') . ' ' . ($m['last_name'] ?? '')) ?: ($m['email'] ?? '—'),
                'email' => (string) ($m['email'] ?? ''),
                'role'  => (string) ($m['role'] ?? 'member'),
                'totp_enabled' => !empty($m['totp_enabled']),
                'last_login' => !empty($m['last_login_at']) ? date('d/m/Y', strtotime((string) $m['last_login_at'])) : '',
            ];
        }
    } catch (Throwable $e) {}

    // État du plan (vérité unique) + plans réels pour changer de formule
    $ps = fd_org_plan($pdo, $id);
    $plans = fd_plan_choices($pdo, $ps['plan_id']);
    $known = in_array($ps['plan_slug'], array_column($plans, 'slug'), true);

    /* ---------------- Demande d'inscription (public_signups) ---------------- */
    $sig = [];
    try {
        if (isset(ak_db_columns($pdo, 'public_signups')['org_id'])) {
            $sst = $pdo->prepare("SELECT * FROM public_signups WHERE org_id = ? ORDER BY id DESC LIMIT 1");
            $sst->execute([$id]);
            $sig = $sst->fetch(PDO::FETCH_ASSOC) ?: [];
        }
    } catch (Throwable $e) {}
    // Réponses du formulaire, dans l'ordre de saisie (signup.php) ; celles reprises dans la fiche
    // ou le contact en sont retirées au fur et à mesure, le reste forme « Formulaire d'inscription »
    $details = [];
    if (!empty($sig['details_json'])) {
        $dec = json_decode((string) $sig['details_json'], true);
        if (is_array($dec)) {
            foreach ($dec as $k => $v) {
                $v = is_scalar($v) ? trim((string) $v) : json_encode($v, JSON_UNESCAPED_UNICODE);
                if ($v !== '') $details[(string) $k] = $v;
            }
        }
    }
    $take = function (string ...$keys) use (&$details): string {
        $v = '';
        foreach ($keys as $k) {
            if (isset($details[$k])) { if ($v === '') $v = $details[$k]; unset($details[$k]); }
        }
        return $v;
    };
    $col = fn(string $c): string => trim((string) ($o[$c] ?? ''));
    $lead = ak_signup_review_lead($pdo, $o);   // demandeur : public_signups, à défaut l'administrateur
    $adm = ak_signup_review_admin($pdo, $o) ?: [];
    $withdrawn = ak_signup_review_withdrawn($o);
    $is_request = ak_signup_review_pending($o);

    /* ---------------- Fiche de la structure ---------------- */
    $type = $col('org_type') ?: (string) ($sig['org_type'] ?? '') ?: (string) ($lead['type'] ?? '');
    $take('Type');
    $fiche = [];
    $add = function (string $key, string $label, $value, ?string $kind = null) use (&$fiche) {
        $value = trim((string) $value);
        if ($value !== '') $fiche[] = ['key' => $key, 'label' => $label, 'value' => $value, 'kind' => $kind];
    };
    $add('type', 'Type', $type === 'tpe' ? 'TPE / entreprise' : ($type === 'asso' ? 'Association' : ''));
    if ($col('legal_name') !== '' && mb_strtolower($col('legal_name')) !== mb_strtolower((string) $o['name'])) $add('legal_name', 'Raison sociale', $col('legal_name'));
    $add('legal_form', 'Forme juridique', $col('legal_form') ?: $take('Forme juridique'));
    $take('Forme juridique');
    $add('rna', 'N° RNA', $col('rna_number') ?: $take('N° RNA'));
    $take('N° RNA');
    $siret = $col('siret') ?: $take('SIRET');
    $siren = $col('siren') ?: $take('SIREN');
    $take('SIRET', 'SIREN');
    if ($siret !== '' && $siret !== '0') $add('siret', 'SIRET', $siret);
    elseif ($siren !== '' && $siren !== '0') $add('siren', 'SIREN', $siren);
    // TVA : question posée aux seules TPE (la colonne vaut 0 par défaut pour une association)
    $vat = $take('TVA');
    if ($vat === '' && $type === 'tpe' && array_key_exists('vat_subject', $o) && $o['vat_subject'] !== null) $vat = (int) $o['vat_subject'] ? 'Assujetti(e)' : 'Non assujetti(e)';
    if ($vat !== '' && $col('vat_number') !== '') $vat .= ' · ' . $col('vat_number');
    $add('vat', 'TVA', $vat);
    $street = trim($col('billing_address_street') . ($col('billing_address_complement') !== '' ? ', ' . $col('billing_address_complement') : ''));
    $city = trim($col('billing_address_zip') . ' ' . $col('billing_address_city'));
    $country = $col('billing_address_country');
    $addr = trim($street . ($street !== '' && $city !== '' ? ', ' : '') . $city . ($country !== '' && strtoupper($country) !== 'FR' && strtolower($country) !== 'france' ? ' (' . $country . ')' : ''), ', ');
    $add('address', 'Adresse', $addr ?: $take('Adresse'));
    $take('Adresse');
    $phone = $col('billing_phone') ?: (string) ($lead['phone'] ?? '') ?: $take('Téléphone');
    $take('Téléphone');
    $add('phone', 'Téléphone', $phone, 'tel');
    if ($hasBill) $add('billing_email', 'E-mail de facturation', $col('billing_email'), 'mail');
    $site = $take('Site web') ?: $col('website');
    $add('website', 'Site web', $site, preg_match('#^https?://#i', $site) ? 'url' : null);
    $pres = trim($col('president_first_name') . ' ' . $col('president_last_name'));
    if ($pres !== '') $add('president', 'Représentant légal', $pres . ($col('president_role') !== '' ? ' (' . $col('president_role') . ')' : ''));

    /* ---------------- Contact (demandeur / administrateur) ---------------- */
    $contact = null;
    $cname = trim(($lead['first_name'] ?? '') . ' ' . ($lead['last_name'] ?? ''));
    $role_fn = $take('Fonction du contact');
    if ($cname !== '' || !empty($lead['email']) || $adm) {
        $contact = [
            'name'           => $cname,
            'email'          => (string) ($lead['email'] ?? ''),
            'phone'          => (string) ($lead['phone'] ?? ''),
            'function'       => $role_fn,
            'user_id'        => (int) ($adm['id'] ?? 0),
            'email_verified' => array_key_exists('email_verified_at', $adm) ? !empty($adm['email_verified_at']) : null,
            'last_login'     => fd_when($adm['last_login_at'] ?? ''),
            'account_created'=> fd_when($adm['created_at'] ?? ''),
        ];
    }

    // Téléphone de la structure identique à celui du contact : une seule fois (dans le contact)
    if ($contact && $contact['phone'] !== '' && preg_replace('/\D/', '', $contact['phone']) === preg_replace('/\D/', '', $phone)) {
        $fiche = array_values(array_filter($fiche, fn($r) => $r['key'] !== 'phone'));
    }

    /* ---------------- Demande d'inscription ---------------- */
    $notes = $col('internal_notes');
    $from_signup = (bool) $sig || strpos($notes, 'Inscription en ligne') === 0;
    $ua = (string) ($sig['user_agent'] ?? '');
    if (stripos($ua, 'AssokitApp') !== false) $source = stripos($ua, 'Android') !== false ? 'android' : 'ios';
    elseif ($is_request || ($withdrawn && $from_signup) || strpos($notes, "(demande depuis l'app iPhone") !== false || ak_signup_review_refused($pdo, $o)) $source = 'ios';
    elseif ($ua !== '') $source = 'web';
    elseif ($from_signup) $source = 'online';
    else $source = 'cockpit';
    $SOURCES = ['web' => 'Site web', 'android' => 'Application Android', 'ios' => 'Application iPhone',
                'online' => 'Inscription en ligne', 'cockpit' => 'Créée depuis le cockpit'];
    $sstatus = (string) ($sig['status'] ?? '');
    $refused_request = !$withdrawn && ak_signup_review_refused($pdo, $o);
    $SIG_STATUS = ['pending' => $is_request ? 'En attente de validation' : ($refused_request ? 'Refusée' : 'En cours'), 'completed' => 'Espace ouvert', 'blocked' => 'Non ouverte automatiquement', 'withdrawn' => 'Retirée'];
    $signup = null;
    if ($from_signup || $sig) {
        $err = trim((string) ($sig['error_message'] ?? ''));
        $signup = [
            'id'          => isset($sig['id']) ? (int) $sig['id'] : null,
            'source'      => $source,
            'source_label'=> $SOURCES[$source],
            'requested'   => fd_when($sig['created_at'] ?? '') ?: fd_when($o['created_at'] ?? ''),
            // Retrait : la ligne est close (« completed ») sans que l'espace ait jamais été ouvert
            'completed'   => $withdrawn ? '' : fd_when($sig['completed_at'] ?? ''),
            // Formule vue sur le site : jamais dans l'app iPhone (aucun prix ni formule, règle 3.1.1)
            'plan_choice' => $source === 'ios' || empty($sig['plan_choice']) ? '' : (function_exists('ak_plan_label') ? ak_plan_label((string) $sig['plan_choice']) : (string) $sig['plan_choice']),
            'status'      => $withdrawn ? 'withdrawn' : $sstatus,
            'status_label'=> $withdrawn ? 'Retirée par le demandeur' : ($SIG_STATUS[$sstatus] ?? ''),
            // Note de refus / de retrait, ou cause d'un échec d'ouverture (jamais affichée à l'association)
            'note'        => $err !== '' ? mb_substr($err, 0, 400) : '',
            'ip'          => $is_founder ? (string) ($sig['ip_address'] ?? '') : '',
            'details'     => array_map(fn($k, $v) => ['label' => $k, 'value' => $v], array_keys($details), array_values($details)),
            // Inscriptions sans détail conservé (avant details_json) : le résumé écrit dans les notes internes
            'notes'       => !$details && $notes !== '' && !$withdrawn ? $notes : '',
        ];
    }

    /* ---------------- Validation ---------------- */
    if ($withdrawn) { $rstate = 'withdrawn'; $rlabel = 'Demande retirée par le demandeur'; }
    elseif ($is_request) { $rstate = 'request'; $rlabel = 'Demande d’espace à valider (application iPhone)'; }
    elseif ($vstatus === 'pending_founder') { $rstate = 'pending'; $rlabel = 'À valider'; }
    elseif ($vstatus === 'rejected') { $rstate = 'rejected'; $rlabel = $refused_request ? 'Demande refusée' : 'Refusée'; }
    else { $rstate = 'validated'; $rlabel = 'Validée'; }
    $by = '';
    if (!empty($o['validated_by_user_id'])) {
        try {
            $bst = $pdo->prepare("SELECT first_name, last_name, email FROM users WHERE id = ? LIMIT 1");
            $bst->execute([(int) $o['validated_by_user_id']]);
            if ($b = $bst->fetch(PDO::FETCH_ASSOC)) $by = trim(($b['first_name'] ?? '') . ' ' . ($b['last_name'] ?? '')) ?: (string) $b['email'];
        } catch (Throwable $e) {}
    }
    $review = [
        'state'      => $rstate,
        'label'      => $rlabel,
        'is_request' => $is_request,
        'withdrawn'  => $withdrawn,
        'reason'     => $rstate === 'rejected' ? $col('rejection_reason') : '',
        'at'         => $rstate === 'validated' || $rstate === 'rejected' ? fd_when($o['validated_at'] ?? '') : '',
        'by'         => $rstate === 'validated' ? $by : '',
        // Valider / Refuser : Fondateur seulement (app-founder-action.php, 403 founder_only sinon)
        'can_decide' => $is_founder && $vstatus === 'pending_founder' && !$withdrawn,
        // Demande iPhone en attente : réactiver, suspendre, prolonger, changer de formule sont refusés (409)
        'locked'     => $is_request,
    ];

    /* ---------------- Activité ---------------- */
    $activity = ['last_activity' => '', 'nb_projects' => null, 'apps' => [], 'unpaid_nb' => 0, 'unpaid_total' => 0.0];
    if (isset($ucols['last_login_at']) || !$ucols) {
        try {
            $ast = $pdo->prepare("SELECT MAX(last_login_at) FROM users WHERE org_id = ? AND is_active = 1 AND deleted_at IS NULL");
            $ast->execute([$id]);
            $activity['last_activity'] = fd_when($ast->fetchColumn() ?: '');
        } catch (Throwable $e) {}
    }
    try {
        $pst = $pdo->prepare("SELECT COUNT(*) FROM projects p JOIN folders f ON f.id = p.folder_id WHERE f.org_id = ?");
        $pst->execute([$id]);
        $activity['nb_projects'] = (int) $pst->fetchColumn();
    } catch (Throwable $e) {}
    try {
        $tst = $pdo->prepare("SELECT DISTINCT LOWER(t.platform) FROM asso_push_tokens t JOIN users u ON u.id = t.user_id WHERE u.org_id = ? AND u.deleted_at IS NULL");
        $tst->execute([$id]);
        foreach ($tst->fetchAll(PDO::FETCH_COLUMN) as $p) {
            if ($p === 'ios') $activity['apps'][] = 'iPhone';
            elseif ($p === 'android') $activity['apps'][] = 'Android';
        }
    } catch (Throwable $e) {}
    try {
        $ust = $pdo->prepare("SELECT COUNT(*), COALESCE(SUM(amount_ttc), 0) FROM subscription_invoices WHERE org_id = ? AND status IN ('sent','overdue')");
        $ust->execute([$id]);
        [$unb, $utot] = $ust->fetch(PDO::FETCH_NUM) ?: [0, 0];
        $activity['unpaid_nb'] = (int) $unb;
        $activity['unpaid_total'] = round((float) $utot, 2);
    } catch (Throwable $e) {}

    echo json_encode([
        'ok' => true,
        'org' => [
            'id'            => (int) $o['id'],
            'name'          => (string) $o['name'],
            'billing_email' => $hasBill ? (string) ($o['billing_email'] ?? '') : '',
            // « plan » = slug du plan COURANT (abonnement), comparé aux slugs de « plans »
            'plan'          => $ps['plan_slug'],
            'plan_known'    => $known,   // false : ancien libellé (ex. « essentiel ») hors asso_plans
            'status'        => (string) $o['status'],
            'validation_status' => $vstatus,
            // Mêmes clés que la liste (app-founder-orgs.php) : pastilles « À valider » / « Refusée »
            'pending'       => ($vstatus === 'pending_founder'),
            'rejected'      => ($vstatus === 'rejected'),
            'org_type'      => $type,
            'note'          => $hasNote ? (string) ($o['notes_superadmin'] ?? '') : '',
            'created'       => !empty($o['created_at']) ? date('d/m/Y', strtotime($o['created_at'])) : '',
            'nb_users'      => max($nb_users, count($members)),
            'members_more'  => max(0, $nb_users - count($members)),
            // Prolonger l'essai : seulement un essai en cours ou terminé (jamais un abonnement payant)
            'can_extend_trial' => ($ps['is_trial'] || $ps['trial_expired']) && empty($ps['payment_pending']) && (string) $o['status'] !== 'cancelled' && !$is_request,
            'trial_days'    => (int) AK_TRIAL_DAYS,
        ] + $ps,
        'fiche'    => $fiche,
        'contact'  => $contact,
        'signup'   => $signup,
        'review'   => $review,
        'activity' => $activity,
        'members'  => $members,
        'plans'    => $plans,
        'can_note' => $hasNote,
        'can_reset_2fa' => $is_founder,   // bouton « Réinitialiser la 2FA » d'un membre (Fondateur)
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('[api/app-founder-org-detail] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'server']);
}
