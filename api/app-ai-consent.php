<?php
/**
 * api/app-ai-consent.php — Enregistre l'accord (ou le refus) pour les fonctions IA, depuis l'app.
 *
 * Règle 5.1.2(i) d'Apple : avant le premier envoi d'un contenu à Anthropic (États-Unis),
 * l'app montre une fenêtre native (mobile/App.js, askAiConsent) avec « Accepter » et « Refuser ».
 * Le choix est gardé par compte (ai-consent-helper.php) ; il se change dans Plus › Paramètres.
 * Tant qu'il n'est pas « accepté », le serveur refuse les envois à l'IA venant de l'app
 * (ak_ai_app_gate) et l'app grise ses boutons IA.
 *
 * Entrée JSON : { consent: true|false, csrf }. Sortie : { ok, consent, decided_at }.
 * Lecture : la valeur courante est dans api/app-dashboard.php (clé ai_consent).
 */
require __DIR__ . '/_app-write-boot.php';   // session, compte actif, CSRF, $pdo, $uid, $input
require_once __DIR__ . '/../ai-consent-helper.php';

if (!array_key_exists('consent', $input)) app_fail(422, 'invalid', 'Choix manquant.');
$yes = filter_var($input['consent'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
if ($yes === null) app_fail(422, 'invalid', 'Choix invalide.');

if (!ak_ai_consent_set($pdo, $uid, $yes, 'app')) app_fail(500, 'server', 'Choix non enregistré. Réessayez dans un instant.');
$at = null;
$now = ak_ai_consent_get($pdo, $uid, $at);
echo json_encode(['ok' => true, 'consent' => $now, 'decided_at' => $at], JSON_UNESCAPED_UNICODE);
