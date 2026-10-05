<?php
/**
 * seed-demo-formation.php — Crée (ou recrée) la démo de formation « DEMO F ».
 * ------------------------------------------------------------------
 * Usage, en SSH, depuis ~/public_html :
 *
 *     /usr/local/bin/php seed-demo-formation.php              reconstruit puis vérifie
 *     /usr/local/bin/php seed-demo-formation.php --verifier   vérifie seulement (lecture seule)
 *     /usr/local/bin/php seed-demo-formation.php --supprimer  efface la démo et son org
 *
 * Rejouable à volonté : chaque exécution efface les données de DEMO F
 * (et seulement elles) puis les recrée, avec des dates recalées sur le
 * jour même. cron-demo-reset.php fait la même chose chaque nuit.
 *
 * La vérification n'est pas décorative : un compte qui ne s'ouvre pas ou
 * un écran vide devant un prospect coûte une vente. Le script sort en
 * erreur (code 1) au moindre problème bloquant.
 * ------------------------------------------------------------------
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI uniquement.\n");
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/demo-formation/run.php';

if (!isset($pdo) || !($pdo instanceof PDO)) {
    fwrite(STDERR, "PDO indisponible (config.php).\n");
    exit(1);
}

$mode = $argv[1] ?? '';
$dire = function (string $l): void { echo $l, "\n"; };

/** Contrôles de lecture seule. Renvoie la liste des problèmes bloquants. */
function df_verifier(PDO $pdo, callable $dire): array
{
    DF::$pdo = $pdo;
    DF::reset_caches();
    $problemes = [];

    $s = $pdo->prepare("SELECT id, name FROM organizations WHERE slug = ?");
    $s->execute([DF_SLUG]);
    $org = $s->fetch(PDO::FETCH_ASSOC);
    if (!$org) return ["L'organisation « " . DF_SLUG . " » n'existe pas : lancez le script sans option."];
    $orgId = (int)$org['id'];
    $dire("Organisation : {$org['name']} (#$orgId)");
    $dire('');

    $dire('Comptes de connexion');
    foreach (df_comptes() as $cle => [$prenom, $nom, $role, , $fonction, $local]) {
        $email = $local . '@' . DF_DOMAINE;
        $q = $pdo->prepare("SELECT * FROM users WHERE email = ? LIMIT 1");
        $q->execute([$email]);
        $u = $q->fetch(PDO::FETCH_ASSOC);
        $etat = 'OK';
        if (!$u) {
            $etat = 'ABSENT';
            $problemes[] = "$email n'existe pas.";
        } else {
            $p = [];
            if (!password_verify(DF_MOT_DE_PASSE, (string)$u['password_hash'])) $p[] = 'mot de passe différent';
            if (empty($u['is_active']))                       $p[] = 'désactivé';
            if (!empty($u['deleted_at']))                     $p[] = 'supprimé';
            if (!empty($u['totp_enabled']))                   $p[] = '2FA active';
            if (!empty($u['must_change_password']))           $p[] = 'changement de mot de passe imposé';
            if ((int)$u['org_id'] !== $orgId)                 $p[] = 'rattaché à une autre org';
            if (($u['role'] ?? '') !== $role)                 $p[] = "rôle {$u['role']} au lieu de $role";
            if (array_key_exists('email_verified_at', $u) && empty($u['email_verified_at'])) $p[] = 'e-mail non vérifié';
            if ($p) {
                $etat = implode(', ', $p);
                foreach ($p as $x) $problemes[] = "$email : $x.";
            }
        }
        $dire(sprintf('  %-32s %s', $email, $etat));
    }
    $dire('');

    // Écrans : chaque ligne doit être non nulle, sinon l'écran est vide devant le prospect.
    $c = function (string $table, string $sql) use ($pdo, $orgId): ?int {
        if (!DF::table_existe($table)) return null;
        try {
            $s = $pdo->prepare($sql);
            $s->execute(array_fill(0, substr_count($sql, '?'), $orgId));
            return (int)$s->fetchColumn();
        } catch (Throwable $e) { return -1; }
    };
    $ecrans = [
        'Adhérents'            => ['users', "SELECT COUNT(*) FROM users WHERE org_id = ? AND deleted_at IS NULL"],
        'Cotisations'          => ['cotisation_payments', "SELECT COUNT(*) FROM cotisation_payments WHERE org_id = ?"],
        'Événements'           => ['events', "SELECT COUNT(*) FROM events WHERE org_id = ?"],
        'Emploi du temps'      => ['assokit_schedules', "SELECT COUNT(*) FROM assokit_schedules WHERE org_id = ?"],
        'Assemblées'           => ['assemblies', "SELECT COUNT(*) FROM assemblies WHERE org_id = ?"],
        'Émargement'           => ['attendance_sessions', "SELECT COUNT(*) FROM attendance_sessions WHERE org_id = ?"],
        'Projets'              => ['projects', "SELECT COUNT(*) FROM projects p JOIN folders f ON f.id = p.folder_id WHERE f.org_id = ?"],
        'Étapes de projet'     => ['project_steps', "SELECT COUNT(*) FROM project_steps s JOIN projects p ON p.id = s.project_id JOIN folders f ON f.id = p.folder_id WHERE f.org_id = ?"],
        'Notes de frais'       => ['expense_reports', "SELECT COUNT(*) FROM expense_reports WHERE org_id = ?"],
        'Clients'              => ['asso_clients', "SELECT COUNT(*) FROM asso_clients WHERE org_id = ?"],
        'Devis'                => ['asso_quotes', "SELECT COUNT(*) FROM asso_quotes WHERE org_id = ?"],
        'Factures'             => ['asso_invoices', "SELECT COUNT(*) FROM asso_invoices WHERE org_id = ?"],
        'Subventions'          => ['grants', "SELECT COUNT(*) FROM grants WHERE org_id = ?"],
        'Canaux de discussion' => ['channels', "SELECT COUNT(*) FROM channels WHERE org_id = ?"],
        'Messages'             => ['channel_messages', "SELECT COUNT(*) FROM channel_messages m JOIN channels c ON c.id = m.channel_id WHERE c.org_id = ?"],
        'Diffusions'           => ['communication_broadcasts', "SELECT COUNT(*) FROM communication_broadcasts WHERE org_id = ?"],
        'Prospection'          => ['asso_prospection', "SELECT COUNT(*) FROM asso_prospection WHERE org_id = ?"],
        'Codes QR'             => ['asso_qr_codes', "SELECT COUNT(*) FROM asso_qr_codes WHERE org_id = ?"],
        'Coach Assokit'        => ['coach_reports', "SELECT COUNT(*) FROM coach_reports WHERE org_id = ?"],
    ];
    $dire('Écrans');
    foreach ($ecrans as $lib => [$table, $sql]) {
        $n = $c($table, $sql);
        $aff = $n === null ? 'table absente sur ce serveur' : ($n < 0 ? 'requête impossible' : (string)$n);
        $dire('  ' . $lib . str_repeat(' ', max(1, 24 - mb_strlen($lib))) . $aff);
        if ($n === 0) $problemes[] = "Aucune donnée dans « $lib » : l'écran sera vide.";
    }
    return $problemes;
}

// ------------------------------------------------------------------
if ($mode === '--verifier') {
    $p = df_verifier($pdo, $dire);
    $dire('');
    if ($p) { $dire('À CORRIGER'); foreach ($p as $x) $dire("  - $x"); exit(1); }
    $dire('Démo opérationnelle.');
    exit(0);
}

if ($mode === '--supprimer') {
    DF::$pdo = $pdo;
    DF::$log = $dire;
    $s = $pdo->prepare("SELECT id FROM organizations WHERE slug = ?");
    $s->execute([DF_SLUG]);
    $org = (int)$s->fetchColumn();
    if (!$org) { $dire('Aucune démo à supprimer.'); exit(0); }
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
    $b = df_purger($org);
    $pdo->prepare("DELETE FROM organizations WHERE id = ? AND slug = ?")->execute([$org, DF_SLUG]);
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
    $dire('Démo supprimée : ' . array_sum($b) . ' lignes, org #' . $org . '.');
    exit(0);
}

if ($mode !== '') {
    fwrite(STDERR, "Option inconnue : $mode (--verifier, --supprimer, ou rien)\n");
    exit(1);
}

$dire('=== Démo de formation « DEMO F » ===');
$dire('');
$debut = microtime(true);
try {
    $r = df_run($pdo, $dire);
} catch (Throwable $e) {
    $dire('');
    $dire('ÉCHEC — rien n\'a été modifié (transaction annulée) : ' . $e->getMessage());
    exit(1);
}
$dire(sprintf('  terminé en %.1f s, %d lignes', microtime(true) - $debut, array_sum($r['inseres'])));
$dire('');

foreach ($r['notes'] ?? [] as $note) $dire('Note : ' . $note);

// Écarts de schéma : utiles pour comprendre un écran vide, sans être bloquants.
if ($r['tables_absentes']) {
    $dire('Tables absentes sur ce serveur (modules sautés) : ' . implode(', ', array_keys($r['tables_absentes'])));
}
if ($r['colonnes_ignorees']) {
    $dire('Colonnes ignorées (absentes de ce schéma) :');
    foreach ($r['colonnes_ignorees'] as $t => $cols) $dire("  $t : " . implode(', ', array_keys($cols)));
}
if ($r['enum']) {
    $dire('Valeurs refusées par une colonne ENUM (valeur par défaut appliquée) :');
    foreach (array_keys($r['enum']) as $m) $dire("  $m");
}
if ($r['restes']) {
    $dire('Tables hors purge contenant des lignes de la démo (à ajouter à purge.php) :');
    foreach ($r['restes'] as $t => $n) $dire("  $t : $n");
}
if ($r['erreurs']) {
    $dire('Erreurs d\'insertion (' . count($r['erreurs']) . ') :');
    foreach (array_slice(array_values(array_unique($r['erreurs'])), 0, 40) as $m) $dire("  $m");
}
$dire('');

$problemes = df_verifier($pdo, $dire);
foreach ($r['modules'] as $nom => $etat) {
    if ($etat !== 'ok') $problemes[] = "Module « $nom » : $etat";
}
$dire('');
if ($problemes) {
    $dire('À CORRIGER');
    foreach ($problemes as $p) $dire("  - $p");
    $dire('');
}

$dire('Identifiants (mot de passe commun : ' . DF_MOT_DE_PASSE . ')');
foreach (df_comptes() as [$prenom, $nom, $role, , $fonction, $local]) {
    $dire(sprintf('  %-30s %s %s — %s', $local . '@' . DF_DOMAINE, $prenom, $nom, $fonction));
}
$dire('');
$dire('Connexion : https://assokit.fr/connexion');
exit($problemes ? 1 : 0);
