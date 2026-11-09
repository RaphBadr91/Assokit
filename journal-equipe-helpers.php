<?php
/**
 * journal-equipe-helpers.php — Journal d'activité de l'équipe (formule Équipe, N1)
 * ------------------------------------------------------------------
 * Lecture seule, sans IA. Rassemble ce que l'équipe a FAIT dans Assokit :
 *   - actions      : assokit_activity_log, event_type = 'action' (jamais les pages vues),
 *                    sans les actions refusées ni les étapes de connexion ;
 *   - comptes      : admin_activity_log (création, modification, désactivation d'un compte…) ;
 *   - factures     : asso_invoice_audit (création, validation, avoir, paiement…) ;
 *   - copilote     : copilote_audit_log (questions posées, sans leur texte).
 * Ni l'adresse IP ni le navigateur ne sont lus ni affichés. 12 mois au plus.
 * Chaque source est lue à part : une table absente n'empêche pas les autres.
 * Utilisé par journal-equipe.php (page) et journal-equipe-export.php (CSV).
 * ------------------------------------------------------------------
 */

if (!function_exists('je_types')) {

/** Types proposés dans le filtre. */
function je_types(): array
{
    return ['actions' => 'Actions', 'comptes' => 'Comptes et accès', 'factures' => 'Factures', 'copilote' => 'Copilote'];
}

/** Périodes proposées (jours) : 30 par défaut, 12 mois au plus. */
function je_periods(): array
{
    return [7 => '7 derniers jours', 30 => '30 derniers jours', 90 => '3 derniers mois', 180 => '6 derniers mois', 365 => '12 derniers mois'];
}

/** Filtres lus dans la requête, bornés. */
function je_filters(array $q): array
{
    $days = (int)($q['periode'] ?? 30);
    if (!isset(je_periods()[$days])) $days = 30;
    $type = (string)($q['type'] ?? '');
    if (!isset(je_types()[$type])) $type = '';
    return ['days' => $days, 'type' => $type, 'membre' => max(0, (int)($q['membre'] ?? 0))];
}

/** Membres de l'association (filtre et noms), id => « Prénom Nom ». Comptes supprimés compris. */
function je_members(PDO $pdo, int $org_id): array
{
    $out = [];
    try {
        $s = $pdo->prepare("SELECT id, TRIM(CONCAT(COALESCE(first_name, ''), ' ', COALESCE(last_name, ''))) AS nom, email
                              FROM users WHERE org_id = ? ORDER BY first_name, last_name, id");
        $s->execute([$org_id]);
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) $out[(int)$r['id']] = $r['nom'] !== '' ? $r['nom'] : (string)$r['email'];
    } catch (Throwable $e) {}
    return $out;
}

/** Libellé lisible d'une action du journal général (« action-cotisation-payment:create »). */
function je_action_label(string $event_action): string
{
    $parts = explode(':', $event_action, 2);
    $script = preg_replace('/\.php$/', '', (string)$parts[0]);
    $sub = strtolower((string)($parts[1] ?? ''));
    $scripts = [
        'action-cotisation' => 'Campagne de cotisation', 'action-cotisation-payment' => 'Paiement de cotisation',
        'action-assemblee' => 'Assemblée générale', 'action-admin-utilisateur' => 'Compte utilisateur',
        'action-facture' => 'Facture de projet', 'action-projet' => 'Projet', 'action-message' => 'Message',
        'action-ia' => 'Assistant de projet', 'action-scan-facture' => 'Lecture de justificatif',
        'action-coach-ia' => 'Coach', 'action-parametres' => 'Paramètres', 'action-evenement' => 'Événement',
        'action-adherent' => 'Adhérent', 'action-dossier' => 'Dossier', 'action-etape' => 'Étape',
        'action-subvention' => 'Subvention', 'action-note-frais' => 'Note de frais',
        'boite-mail-attribuer' => 'Conversation de la boîte mail',
        'mon-asso-recurrence-save' => 'Facture récurrente', 'mon-asso-recurrence-delete' => 'Facture récurrente',
        'mon-asso-recurrence-toggle' => 'Facture récurrente', 'mon-asso-recurrence-run-now' => 'Facture récurrente',
        'mon-asso-devis-send' => 'Devis', 'mon-asso-devis-save' => 'Devis', 'mon-asso-facture-save' => 'Facture',
        'communication-diffuser-nouveau' => 'Diffusion', 'communication-evenement' => 'Invitation à un événement',
        'mon-asso-ia-diffusion-send' => 'Diffusion', 'prospection' => 'Prospection',
    ];
    $subs = [
        'create' => 'création', 'add' => 'ajout', 'new' => 'création', 'update' => 'modification', 'edit' => 'modification',
        'save' => 'enregistrement', 'delete' => 'suppression', 'remove' => 'suppression', 'send' => 'envoi',
        'send_invitations' => 'envoi des convocations', 'archive' => 'archivage', 'restore' => 'restauration',
        'attribuer' => 'attribution', 'retirer' => 'retrait de l’attribution', 'pause' => 'mise en pause',
        'resume' => 'reprise', 'cancel' => 'arrêt', 'validate' => 'validation', 'close' => 'clôture',
        'generate_now' => 'génération', 'chat' => 'question', 'generate' => 'génération',
    ];
    if (isset($scripts[$script])) {
        $label = $scripts[$script];
    } else {
        $label = preg_replace('/^(action-|mon-asso-|api\/)/', '', $script);
        $label = trim(str_replace(['-', '_'], ' ', $label));
        $label = $label !== '' ? mb_strtoupper(mb_substr($label, 0, 1)) . mb_substr($label, 1) : 'Action';
    }
    if ($sub !== '') $label .= ' : ' . ($subs[$sub] ?? str_replace(['-', '_'], ' ', $sub));
    return $label;
}

/** Objet concerné, d'après la cible enregistrée (« adherent_id=4 » → « Membre n° 4 »). */
function je_target_label(?string $target): string
{
    $target = trim((string)$target);
    if ($target === '' || !preg_match('/^([a-z_]+)=(\d+)$/', $target, $m)) return '';
    $map = [
        'id' => 'n°', 'project_id' => 'Projet n°', 'projet_id' => 'Projet n°', 'facture_id' => 'Facture n°',
        'invoice_id' => 'Facture n°', 'adherent_id' => 'Membre n°', 'user_id' => 'Membre n°', 'event_id' => 'Événement n°',
        'evenement_id' => 'Événement n°', 'campaign_id' => 'Campagne n°', 'grant_id' => 'Subvention n°',
        'prospect_id' => 'Prospect n°', 'ticket_id' => 'Demande n°', 'canal_id' => 'Canal n°', 'folder_id' => 'Dossier n°',
        'thread_id' => 'Conversation n°',
    ];
    return ($map[$m[1]] ?? 'n°') . "\u{00A0}" . $m[2];
}

/**
 * Lignes du journal, les plus récentes d'abord.
 * @return array<int, array{at:string, user_id:int, who:string, type:string, action:string, target:string}>
 */
function je_rows(PDO $pdo, int $org_id, array $f, int $limit = 500): array
{
    $limit = max(1, min(20000, $limit));
    $since = date('Y-m-d H:i:s', strtotime('-' . (int)$f['days'] . ' days'));
    $members = je_members($pdo, $org_id);
    $who = function ($uid, $fallback = '') use ($members) {
        $uid = (int)$uid;
        if ($uid > 0 && isset($members[$uid])) return $members[$uid];
        return $fallback !== '' ? $fallback : ($uid > 0 ? 'Compte n° ' . $uid : 'Assokit (automatique)');
    };
    $type = $f['type'];
    $m = (int)$f['membre'];
    $rows = [];

    // 1. Actions de l'équipe (jamais les pages vues ; ni IP ni navigateur)
    if ($type === '' || $type === 'actions') {
        try {
            $sql = "SELECT created_at, user_id, user_role, event_action, event_target FROM assokit_activity_log
                     WHERE organization_id = ? AND event_type = 'action' AND created_at >= ?
                       AND event_action NOT LIKE 'login%' AND (event_meta IS NULL OR event_meta NOT LIKE '%\"echec\":true%')"
                 . ($m ? " AND user_id = ?" : '') . " ORDER BY created_at DESC, id DESC LIMIT $limit";
            $s = $pdo->prepare($sql);
            $s->execute($m ? [$org_id, $since, $m] : [$org_id, $since]);
            foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $platform = in_array((string)$r['user_role'], ['founder', 'super_admin'], true) && !isset($members[(int)$r['user_id']]);
                $rows[] = ['at' => (string)$r['created_at'], 'user_id' => (int)$r['user_id'],
                           'who' => $platform ? 'Support Assokit' : $who($r['user_id']), 'type' => 'Actions',
                           'action' => je_action_label((string)$r['event_action']), 'target' => je_target_label($r['event_target'])];
            }
        } catch (Throwable $e) {}
    }

    // 2. Comptes et accès (admin_activity_log)
    if ($type === '' || $type === 'comptes') {
        $labels = ['create_user' => 'Compte créé', 'update_user' => 'Compte modifié', 'deactivate_user' => 'Compte désactivé',
                   'reactivate_user' => 'Compte réactivé', 'reset_password' => 'Mot de passe réinitialisé',
                   'reset_2fa' => 'Double authentification réinitialisée'];
        try {
            $sql = "SELECT created_at, admin_user_id, target_user_id, action FROM admin_activity_log
                     WHERE org_id = ? AND created_at >= ?" . ($m ? " AND admin_user_id = ?" : '') . " ORDER BY created_at DESC LIMIT $limit";
            $s = $pdo->prepare($sql);
            $s->execute($m ? [$org_id, $since, $m] : [$org_id, $since]);
            foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $tid = (int)$r['target_user_id'];
                $rows[] = ['at' => (string)$r['created_at'], 'user_id' => (int)$r['admin_user_id'], 'who' => $who($r['admin_user_id']),
                           'type' => 'Comptes et accès', 'action' => $labels[(string)$r['action']] ?? 'Compte : ' . str_replace('_', ' ', (string)$r['action']),
                           'target' => $tid > 0 ? ($members[$tid] ?? 'Compte n° ' . $tid) : ''];
            }
        } catch (Throwable $e) {}
    }

    // 3. Factures (asso_invoice_audit)
    if ($type === '' || $type === 'factures') {
        $labels = ['created' => 'Facture créée', 'finalized' => 'Facture validée', 'credit_note' => 'Avoir émis',
                   'status_change' => 'Statut de facture modifié', 'paid_online' => 'Facture payée en ligne', 'sent' => 'Facture envoyée',
                   'paid' => 'Facture marquée payée', 'cancelled' => 'Facture annulée'];
        try {
            $sql = "SELECT a.created_at, a.actor_user_id, a.event_type, a.invoice_id, i.invoice_number
                      FROM asso_invoice_audit a LEFT JOIN asso_invoices i ON i.id = a.invoice_id AND i.org_id = a.org_id
                     WHERE a.org_id = ? AND a.created_at >= ?" . ($m ? " AND a.actor_user_id = ?" : '') . " ORDER BY a.created_at DESC, a.id DESC LIMIT $limit";
            $s = $pdo->prepare($sql);
            $s->execute($m ? [$org_id, $since, $m] : [$org_id, $since]);
            foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $rows[] = ['at' => (string)$r['created_at'], 'user_id' => (int)$r['actor_user_id'], 'who' => $who($r['actor_user_id']),
                           'type' => 'Factures', 'action' => $labels[(string)$r['event_type']] ?? 'Facture : ' . str_replace('_', ' ', (string)$r['event_type']),
                           'target' => !empty($r['invoice_number']) ? 'Facture ' . $r['invoice_number'] : 'Facture n° ' . (int)$r['invoice_id']];
            }
        } catch (Throwable $e) {}
    }

    // 4. Copilote (copilote_audit_log) : la question posée, sans son texte
    if ($type === '' || $type === 'copilote') {
        try {
            $sql = "SELECT created_at, user_id FROM copilote_audit_log
                     WHERE org_id = ? AND created_at >= ?" . ($m ? " AND user_id = ?" : '') . " ORDER BY created_at DESC, id DESC LIMIT $limit";
            $s = $pdo->prepare($sql);
            $s->execute($m ? [$org_id, $since, $m] : [$org_id, $since]);
            foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $rows[] = ['at' => (string)$r['created_at'], 'user_id' => (int)$r['user_id'], 'who' => $who($r['user_id']),
                           'type' => 'Copilote', 'action' => 'Question posée au copilote', 'target' => ''];
            }
        } catch (Throwable $e) {}
    }

    usort($rows, fn($a, $b) => strcmp($b['at'], $a['at']));
    return array_slice($rows, 0, $limit);
}

/**
 * Accès au journal : administrateur de l'association (ou compte plateforme), et formule avec
 * feature_activity_log. @return string '' si permis, 'role' ou 'plan' sinon.
 */
function je_access(PDO $pdo, array $user, int $org_id): string
{
    if (!is_file(__DIR__ . '/plan-controls.php')) return (($user['role'] ?? '') === 'admin') ? '' : 'role';
    require_once __DIR__ . '/plan-controls.php';
    if (($user['role'] ?? '') !== 'admin' && !akc_is_platform()) return 'role';
    return akc_has($pdo, $org_id, 'activity_log') ? '' : 'plan';
}

}
