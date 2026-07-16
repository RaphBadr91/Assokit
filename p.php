<?php
/**
 * p.php — Lien personnalisé de tracking d'un prospect. Route : /p/{id.token}
 * Logge le clic puis redirige vers la landing adaptée (asso / tpe). Public (sécurisé par jeton).
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/api/_app-prospect.php';

$seg = (string) ($_GET['t'] ?? '');
$p = ak_prospect_parse($pdo, $seg);
$dest = '/';
if ($p) {
    $dest = ak_prospect_landing_path((string) ($p['type'] ?? 'asso'));
    try {
        ak_prospect_event($pdo, (int) $p['id'], 'click', null, mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 120));
        // Un clic = engagement : on marque "contacté/engagé" si pas déjà répondu/désinscrit
        $pdo->prepare("UPDATE asso_prospects SET status = 'engaged' WHERE id = ? AND status IN ('new','queued','contacted')")->execute([(int) $p['id']]);
    } catch (Throwable $e) {}
    // Marque la landing pour signaler l'origine prospection (cookie léger, non traçant tiers)
    @setcookie('ak_src', 'prospect', ['expires' => time() + 86400 * 30, 'path' => '/', 'samesite' => 'Lax']);
}
header('Location: ' . $dest, true, 302);
exit;
