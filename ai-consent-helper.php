<?php
/**
 * ai-consent-helper.php — Accord de l'utilisateur avant tout envoi de contenus à l'IA, dans l'app.
 * ------------------------------------------------------------------
 * Règle 5.1.2(i) d'Apple (version en vigueur depuis novembre 2025) : une app doit dire clairement
 * quand des données personnelles partent chez une IA tierce, et obtenir une permission explicite
 * AVANT le premier envoi. Les fonctions IA d'Assokit (lecture d'une facture photographiée, analyse
 * des factures, cockpit des statistiques, coach, brouillons de la boîte mail, rédaction, bilans…)
 * envoient le contenu concerné à Anthropic (États-Unis), sous-traitant d'Assokit.
 *
 *   - L'app demande l'accord une fois, dans une fenêtre native (mobile/App.js, askAiConsent), et
 *     l'enregistre ici par api/app-ai-consent.php. Il se retire dans Plus › Paramètres.
 *   - Côté serveur, ak_ai_app_gate() refuse l'envoi tant que l'accord manque, pour toute requête
 *     de l'app (agent « AssokitApp ») : écrans natifs ET pages du site affichées dans l'app.
 *   - Hors de l'app (site dans un navigateur, tâches planifiées), rien ne change : ces envois sont
 *     décrits dans la politique de confidentialité (§ 5, § 11 et § 12.3).
 *   - Les traitements réglés pour toute l'association par un administrateur, sur le site (tri de la
 *     Boîte mail par l'IA, case « Tri par l'IA » qui nomme Anthropic), ne dépendent pas de l'accord
 *     de la personne qui ouvre l'app : ils passent par ak_ai_org_process().
 *
 * Stockage : table user_ai_consent (une ligne par compte), créée à la première utilisation ;
 * migration équivalente : migrations/2026-10-18-accord-ia.sql.
 * ------------------------------------------------------------------
 */
require_once __DIR__ . '/app-context.php';   // ak_is_mobile_app()

if (!function_exists('ak_ai_consent_get')) {

/** Message affiché quand une fonction IA est demandée dans l'app sans accord. */
define('AK_AI_CONSENT_BLOCKED', 'Fonctions IA désactivées dans l’application : elles demandent votre accord pour envoyer le contenu '
    . 'concerné à Anthropic, notre sous-traitant d’IA. Activez-les dans Plus › Paramètres › Fonctions IA.');

/** Crée la table au premier enregistrement d'un choix (jamais sur le chemin de lecture). */
function ak_ai_consent_ready(PDO $pdo): bool
{
    static $ok = null;
    if ($ok !== null) return $ok;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS user_ai_consent (
            user_id INT UNSIGNED NOT NULL PRIMARY KEY,
            consent TINYINT(1) NOT NULL,
            decided_at DATETIME NOT NULL,
            source VARCHAR(20) NOT NULL DEFAULT 'app'
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        return $ok = true;
    } catch (Throwable $e) {
        error_log('[ai-consent] table : ' . $e->getMessage());
        return $ok = false;
    }
}

/**
 * Accord du compte : true (accepté), false (refusé), null (jamais demandé).
 * $at reçoit la date de la décision (AAAA-MM-JJ HH:MM:SS) ou null.
 */
function ak_ai_consent_get(PDO $pdo, int $uid, ?string &$at = null): ?bool
{
    $at = null;
    if ($uid <= 0) return null;
    try {
        $s = $pdo->prepare("SELECT consent, decided_at FROM user_ai_consent WHERE user_id = ? LIMIT 1");
        $s->execute([$uid]);
        $r = $s->fetch(PDO::FETCH_ASSOC);
        if (!$r) return null;
        $at = (string) $r['decided_at'];
        return (int) $r['consent'] === 1;
    } catch (Throwable $e) {
        // Table pas encore créée (aucun choix enregistré sur ce serveur) : « jamais demandé »
        if (strpos($e->getMessage(), '42S02') === false) error_log('[ai-consent] lecture : ' . $e->getMessage());
        return null;
    }
}

function ak_ai_consent_set(PDO $pdo, int $uid, bool $yes, string $source = 'app'): bool
{
    if ($uid <= 0 || !ak_ai_consent_ready($pdo)) return false;
    try {
        $pdo->prepare("INSERT INTO user_ai_consent (user_id, consent, decided_at, source) VALUES (?, ?, NOW(), ?)
                       ON DUPLICATE KEY UPDATE consent = VALUES(consent), decided_at = VALUES(decided_at), source = VALUES(source)")
            ->execute([$uid, $yes ? 1 : 0, mb_substr($source, 0, 20)]);
        return true;
    } catch (Throwable $e) {
        error_log('[ai-consent] écriture : ' . $e->getMessage());
        return false;
    }
}

/**
 * Exécute un traitement IA réglé pour toute l'association (tri de la Boîte mail) : il ne dépend
 * pas de l'accord de la personne dont la requête l'a déclenché (bouton « Synchroniser »).
 */
function ak_ai_org_process(callable $fn)
{
    $GLOBALS['AK_AI_ORG_PROCESS'] = (int) ($GLOBALS['AK_AI_ORG_PROCESS'] ?? 0) + 1;
    try {
        return $fn();
    } finally {
        $GLOBALS['AK_AI_ORG_PROCESS']--;
    }
}

/**
 * À appeler juste avant un envoi à l'IA. null : envoi permis. Sinon : le message à afficher.
 * Ne bloque que les requêtes de l'app d'un compte connecté qui n'a pas accepté.
 */
function ak_ai_app_gate(): ?string
{
    if (!ak_is_mobile_app()) return null;
    if (!empty($GLOBALS['AK_AI_ORG_PROCESS'])) return null;
    $uid = (int) ($_SESSION['user_id'] ?? 0);
    if ($uid <= 0) return null;   // tâche sans session : pas une action de l'utilisateur dans l'app
    global $pdo;
    if (!($pdo instanceof PDO)) return AK_AI_CONSENT_BLOCKED;
    return ak_ai_consent_get($pdo, $uid) === true ? null : AK_AI_CONSENT_BLOCKED;
}

}
