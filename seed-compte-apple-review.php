<?php
/**
 * seed-compte-apple-review.php — Crée (ou recrée) le compte remis à Apple.
 * ------------------------------------------------------------------
 * Usage, depuis le serveur :
 *
 *     php seed-compte-apple-review.php
 *
 * Applique demo-sql/08-compte-apple-review.sql puis VÉRIFIE le résultat.
 * La vérification n'est pas décorative : le fichier SQL clone une ligne
 * existante de l'org 23 pour créer le compte, et si cette org était vide
 * il ne créerait rien — sans erreur. Un échec silencieux découvert par
 * l'examinateur d'Apple coûte une semaine ; découvert ici, trente secondes.
 *
 * Rejouable autant de fois qu'on veut. Le cron de reset (cron-demo-reset.php)
 * applique le même fichier chaque nuit, après le snapshot qui l'aurait effacé.
 * ------------------------------------------------------------------
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); die('CLI uniquement.'); }

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/app-context.php';

if (!isset($pdo) || !($pdo instanceof PDO)) {
    fwrite(STDERR, "PDO indisponible (config.php).\n");
    exit(1);
}
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
try { $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true); } catch (Throwable $e) {}

/** Mot de passe en clair — celui qu'on recopie dans les notes d'examen. */
const AK_REVIEW_PASSWORD = 'AppleReview2026!';

$sqlFile = __DIR__ . '/demo-sql/08-compte-apple-review.sql';
if (!is_file($sqlFile)) {
    fwrite(STDERR, "Fichier introuvable : $sqlFile\n");
    exit(1);
}

/** Même découpage que migrations/run.php : on retire les lignes de commentaire. */
function ak_split_sql(string $sql): array
{
    $clean = [];
    foreach (preg_split('/\r?\n/', $sql) as $l) {
        if (str_starts_with(ltrim($l), '--')) continue;
        $clean[] = $l;
    }
    $parts = array_map('trim', explode(';', implode("\n", $clean)));
    return array_values(array_filter($parts, fn($s) => $s !== ''));
}

echo "Application de " . basename($sqlFile) . "\n";

$pdo->exec("SET SESSION sql_mode=''");
$stmts = ak_split_sql(file_get_contents($sqlFile));
$erreurs = 0;

foreach ($stmts as $i => $s) {
    try {
        $pdo->exec($s);
    } catch (Throwable $e) {
        $erreurs++;
        echo "  échec #" . ($i + 1) . " : " . substr($e->getMessage(), 0, 180) . "\n";
    }
}
echo '  ' . count($stmts) . " instructions, $erreurs échec(s)\n\n";

// ------------------------------------------------------------
// Vérification : le compte est-il réellement utilisable ?
// ------------------------------------------------------------
echo "Vérification\n";
$probleme = [];

$st = $pdo->prepare("SELECT * FROM users WHERE email = ? LIMIT 1");
$st->execute([AK_APPLE_REVIEW_EMAIL]);
$u = $st->fetch(PDO::FETCH_ASSOC);

if (!$u) {
    $probleme[] = "Le compte n'existe pas. L'org 23 contient-elle des adhérents ? "
                . "Lancez d'abord le reset démo : php cron-demo-reset.php";
} else {
    if (!password_verify(AK_REVIEW_PASSWORD, (string)$u['password_hash'])) {
        $probleme[] = "Le mot de passe ne correspond pas à l'empreinte enregistrée.";
    }
    if (empty($u['is_active']))            $probleme[] = "Le compte est désactivé.";
    if (!empty($u['totp_enabled']))        $probleme[] = "La 2FA est active : l'examinateur ne pourra pas entrer.";
    if (!empty($u['must_change_password'])) $probleme[] = "Changement de mot de passe imposé à la connexion.";
    if (!empty($u['deleted_at']))          $probleme[] = "Le compte est marqué supprimé.";

    $orgId = (int)$u['org_id'];
    $compte = function (string $sql) use ($pdo, $orgId): int {
        try {
            $s = $pdo->prepare($sql);
            $s->execute([$orgId]);
            return (int)$s->fetchColumn();
        } catch (Throwable $e) { return -1; }
    };

    $stats = [
        'adhérents'   => $compte("SELECT COUNT(*) FROM users WHERE org_id = ? AND deleted_at IS NULL"),
        'cotisations' => $compte("SELECT COUNT(*) FROM cotisation_payments WHERE org_id = ?"),
        'subventions' => $compte("SELECT COUNT(*) FROM grants WHERE org_id = ?"),
        'factures'    => $compte("SELECT COUNT(*) FROM asso_invoices WHERE org_id = ?"),
        'devis'       => $compte("SELECT COUNT(*) FROM asso_quotes WHERE org_id = ?"),
        'événements'  => $compte("SELECT COUNT(*) FROM events WHERE org_id = ?"),
        'clients'     => $compte("SELECT COUNT(*) FROM asso_clients WHERE org_id = ?"),
    ];

    $orgName = '';
    try {
        $s = $pdo->prepare("SELECT name FROM organizations WHERE id = ?");
        $s->execute([$orgId]);
        $orgName = (string)$s->fetchColumn();
    } catch (Throwable $e) {}

    echo "  association : $orgName (org $orgId)\n";
    foreach ($stats as $lib => $n) {
        // printf compte les octets : « événements » ferait 12 octets pour
        // 10 caractères et casserait la colonne. On complète à la main.
        echo '  ' . $lib . str_repeat(' ', max(1, 13 - mb_strlen($lib)))
           . ($n < 0 ? 'table absente' : $n) . "\n";
        // Apple refuse les comptes vides : mieux vaut le voir maintenant.
        if ($n === 0) $probleme[] = "Aucune donnée dans « $lib » — l'écran sera vide à l'examen.";
    }
}

echo "\n";
if ($probleme) {
    echo "À CORRIGER\n";
    foreach ($probleme as $p) echo "  - $p\n";
    exit(1);
}

echo "Compte prêt. À recopier dans App Store Connect > Notes pour l'examen :\n\n";
echo "    Email    : " . AK_APPLE_REVIEW_EMAIL . "\n";
echo "    Password : " . AK_REVIEW_PASSWORD . "\n\n";
echo "Aucune page d'abonnement ni de paiement ne lui est accessible (Apple 3.1.1).\n";
exit(0);
