<?php
/**
 * api/app-test-doublesens.php — Diagnostic CLI du contact double-sens.
 * Vérifie toute la chaîne CODE (hors filtre cPanel) sans envoyer de vrai email :
 *   1. tables présentes
 *   2. simulation d'un message de contact (comme le formulaire)
 *   3. génération de l'adresse Reply-To à jeton
 *   4. simulation de la réponse Fondateur (fil sortant)
 *   5. simulation d'une réponse prospect ENTRANTE via le vrai script pipe (STDIN)
 *   6. vérification que la réponse est bien stockée dans le fil
 *   7. nettoyage des données de test
 *
 * Usage (Terminal O2switch) :  php api/app-test-doublesens.php
 * NE MODIFIE PAS le site. CLI uniquement.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/_app-contact-token.php';

function ok($m){ echo "  \033[32m✅ $m\033[0m\n"; }
function ko($m){ echo "  \033[31m❌ $m\033[0m\n"; }
function info($m){ echo "\n\033[1m$m\033[0m\n"; }

$fail = 0;
$test_email = 'prospect-test-doublesens@example.com';
$contact_id = 0;

try {
    // --- 1. Tables ---
    info('1) Tables en base');
    $hasMsg = false; $hasThread = false;
    try { $pdo->query("SELECT 1 FROM asso_contact_messages LIMIT 1"); $hasMsg = true; ok("asso_contact_messages présente"); }
    catch (Throwable $e) { ko("asso_contact_messages ABSENTE — migration v45 non appliquée ?"); $fail++; }
    ak_contact_thread_ensure($pdo);
    try { $pdo->query("SELECT 1 FROM asso_contact_thread LIMIT 1"); $hasThread = true; ok("asso_contact_thread présente (créée si besoin)"); }
    catch (Throwable $e) { ko("asso_contact_thread introuvable"); $fail++; }
    if (!$hasMsg) { info("STOP : sans asso_contact_messages, impossible de continuer."); exit(1); }

    // --- 2. Simulation d'un message de contact (comme le formulaire corrigé) ---
    info('2) Création d\'un message de contact de test');
    $st = $pdo->prepare("INSERT INTO asso_contact_messages
        (firstname, lastname, email, organization, type, subject, message, ip, user_agent, created_at)
        VALUES ('TestProspect','DoubleSens', :e, '', '', 'Test double-sens', 'Message initial de test', '127.0.0.1', 'cli-test', NOW())");
    $st->execute([':e' => $test_email]);
    $contact_id = (int) $pdo->lastInsertId();
    if ($contact_id > 0) ok("Contact de test créé (id=$contact_id)"); else { ko("Insertion contact échouée"); $fail++; }

    // --- 3. Adresse Reply-To à jeton ---
    info('3) Génération de l\'adresse de réponse à jeton');
    $reply_addr = ak_contact_reply_address($contact_id, $test_email);
    echo "  → $reply_addr\n";
    $parsed = ak_contact_parse_recipient($reply_addr);
    if ($parsed && $parsed['id'] === $contact_id && hash_equals(ak_contact_token($contact_id, $test_email), $parsed['token']))
        ok("Adresse valide et jeton vérifié (round-trip OK)");
    else { ko("Round-trip du jeton cassé"); $fail++; }

    // --- 4. Réponse Fondateur (fil sortant) ---
    info('4) Simulation d\'une réponse Fondateur (direction=out)');
    $pdo->prepare("INSERT INTO asso_contact_thread (contact_id, direction, body, from_email, read_by_founder, created_at)
                   VALUES (?, 'out', 'Bonjour, merci pour votre message !', 'support', 1, NOW())")->execute([$contact_id]);
    ok("Réponse fondateur historisée");

    // --- 5. Réponse ENTRANTE du prospect via le VRAI script pipe (STDIN) ---
    info('5) Simulation d\'une réponse prospect entrante (via app-pipe-inbound-contact.php)');
    $raw = "From: $test_email\n"
         . "To: $reply_addr\n"
         . "Subject: Re: Test double-sens\n"
         . "Content-Type: text/plain; charset=utf-8\n"
         . "\n"
         . "Oui, je suis toujours intéressé, merci beaucoup !\n"
         . "\n"
         . "> Le message précédent est cité ici et doit être retiré.\n";
    $pipe_script = __DIR__ . '/app-pipe-inbound-contact.php';
    $descriptors = [0 => ['pipe','r'], 1 => ['pipe','w'], 2 => ['pipe','w']];
    $proc = proc_open([PHP_BINARY, $pipe_script], $descriptors, $pipes);
    if (is_resource($proc)) {
        fwrite($pipes[0], $raw); fclose($pipes[0]);
        $out = stream_get_contents($pipes[1]); fclose($pipes[1]);
        $err = stream_get_contents($pipes[2]); fclose($pipes[2]);
        $code = proc_close($proc);
        ok("Script pipe exécuté (code sortie=$code)");
        if (trim($err) !== '') echo "  \033[33m⚠ stderr: " . trim($err) . "\033[0m\n";
    } else { ko("Impossible de lancer le script pipe"); $fail++; }

    // --- 6. Vérification du stockage entrant ---
    info('6) Vérification : la réponse entrante est-elle dans le fil ?');
    $st = $pdo->prepare("SELECT direction, body, from_email FROM asso_contact_thread WHERE contact_id = ? AND direction = 'in' ORDER BY id DESC LIMIT 1");
    $st->execute([$contact_id]);
    $in = $st->fetch(PDO::FETCH_ASSOC);
    if ($in) {
        ok("Réponse entrante stockée ✅");
        echo "  → de : {$in['from_email']}\n";
        echo "  → corps nettoyé : \"" . trim($in['body']) . "\"\n";
        if (strpos($in['body'], 'cité ici') !== false) { echo "  \033[33m⚠ l'historique cité n'a pas été retiré\033[0m\n"; }
        else ok("Historique cité correctement retiré");
    } else { ko("AUCUNE réponse entrante stockée — la chaîne pipe/jeton a échoué"); $fail++; }

    // --- 7. Nettoyage ---
    info('7) Nettoyage des données de test');
    $pdo->prepare("DELETE FROM asso_contact_thread WHERE contact_id = ?")->execute([$contact_id]);
    $pdo->prepare("DELETE FROM asso_contact_messages WHERE id = ?")->execute([$contact_id]);
    ok("Données de test supprimées");

} catch (Throwable $e) {
    ko("Exception : " . $e->getMessage());
    $fail++;
    if ($contact_id > 0) {
        try { $pdo->prepare("DELETE FROM asso_contact_thread WHERE contact_id = ?")->execute([$contact_id]); } catch (Throwable $e2) {}
        try { $pdo->prepare("DELETE FROM asso_contact_messages WHERE id = ?")->execute([$contact_id]); } catch (Throwable $e2) {}
    }
}

echo "\n" . str_repeat('─', 48) . "\n";
if ($fail === 0) echo "\033[1;32m🎉 TOUT EST OK — la chaîne CODE du double-sens fonctionne.\033[0m\n";
else            echo "\033[1;31m⚠ $fail étape(s) en échec — voir ci-dessus.\033[0m\n";
echo "Reste à valider : le filtre cPanel qui route reponse@assokit.fr → ce script pipe (test avec un vrai email).\n";
exit($fail === 0 ? 0 : 1);
