<?php
/**
 * cli-ai-check.php — vérifie, avec la vraie clé, que les modèles Claude d'Assokit répondent.
 * Ligne de commande uniquement :  /usr/local/bin/php cli-ai-check.php
 * Deux très courts appels (moins d'un centime au total). Aucune donnée client n'est envoyée.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/ai-models.php';

$keys = [
    'ANTHROPIC_API_KEY (copilote, assistant, Gmail, factures)' => defined('ANTHROPIC_API_KEY') ? (string)ANTHROPIC_API_KEY : '',
    'CLAUDE_API_KEY (hub IA, suggestions du jour)'             => defined('CLAUDE_API_KEY') ? (string)CLAUDE_API_KEY : '',
];
foreach (['ANTHROPIC_MODEL', 'AK_AI_MODEL', 'TODAY_AI_MODEL'] as $c) {
    if (defined($c) && ak_ai_model('write', constant($c)) !== strtolower(trim((string)constant($c)))) {
        echo "ℹ  config.php : {$c} = « " . constant($c) . " » n'est plus servi ; il est ignoré (vous pouvez supprimer la ligne).\n";
    }
}
$fail = 0;
foreach ($keys as $label => $key) {
    if ($key === '') { echo "–  {$label} : clé absente, ignorée\n"; continue; }
    foreach (['write' => AK_AI_MODEL_WRITE, 'fast' => AK_AI_MODEL_FAST] as $purpose => $model) {
        $r = ak_ai_messages(['max_tokens' => 50, 'messages' => [['role' => 'user', 'content' => 'Réponds seulement : OK']]],
                            ['purpose' => $purpose, 'api_key' => $key, 'timeout' => 60]);
        $txt = trim(ak_ai_response_text($r['data']));
        if ($r['ok'] && !$r['refusal'] && $txt !== '') {
            echo "✅ {$label} — {$r['model']} répond : « " . mb_substr($txt, 0, 40) . " »\n";
        } else {
            $fail++;
            $why = $r['curl_error'] ?: ('HTTP ' . $r['code'] . ' ' . mb_substr((string)($r['data']['error']['message'] ?? $r['raw']), 0, 160));
            echo "❌ {$label} — {$model} : {$why}\n";
        }
    }
}
echo $fail ? "\n{$fail} échec(s) : envoyez ce résultat à l'équipe technique.\n" : "\nTout fonctionne.\n";
exit($fail ? 1 : 0);
