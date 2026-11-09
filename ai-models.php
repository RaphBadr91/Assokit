<?php
/**
 * ai-models.php — modèles Claude d'Assokit et appel HTTP commun à l'API Messages
 * --------------------------------------------------------------
 * Claude Sonnet 4.5 est retiré le 30/11/2026 (l'API répondra 404). Tous les appels passent
 * désormais par ce fichier :
 *   - AK_AI_MODEL_WRITE (claude-sonnet-5-5) : rédaction (hub IA, copilote, assistant de projet,
 *     brouillons Gmail, blog, lecture des justificatifs) ;
 *   - AK_AI_MODEL_FAST  (claude-haiku-5-5)  : tâches de fond courtes et répétées (tri Gmail,
 *     suggestions du jour, coach hebdomadaire).
 *
 * Ce qui change avec ces modèles (et que ce fichier gère pour tous les appels) :
 *   - la réflexion est active par défaut : la réponse peut commencer par un bloc « thinking »,
 *     on lit donc les blocs par type (ak_ai_response_text), jamais content[0] ;
 *   - la réflexion compte dans max_tokens : une marge est ajoutée (AK_AI_THINKING_HEADROOM) ;
 *   - le niveau de réflexion se règle par output_config.effort (low par défaut : rédaction et
 *     tri, le niveau conseillé pour ce type de tâche ; c'est aussi lui qui fixe le coût) ;
 *   - un refus de sécurité arrive en HTTP 200 avec stop_reason « refusal » (ak_ai_is_refusal) ;
 *   - sur Sonnet 5.5, en cas de refus, l'API relance d'elle-même la demande sur un modèle de
 *     repli (fallbacks: "default") ; si l'API refuse ce paramètre, l'appel est refait sans lui.
 *
 * Un identifiant laissé dans config.php (ANTHROPIC_MODEL, AK_AI_MODEL, réglage du blog) n'est
 * suivi que s'il désigne un modèle encore servi (ak_ai_model) : un ancien identifiant ne peut
 * plus faire tomber l'IA.
 *
 * Compteur IA unifié (grille de novembre 2026) : chaque appel réussi (ni erreur, ni refus) est
 * noté dans ai_usage_ledger (ak_ai_ledger_record) avec son chemin (« hub », « copilote »,
 * « mail-tri »…) et les crédits qu'il consomme (ak_ai_path_credits). Options acceptées par
 * ak_ai_messages et ask_claude / ask_claude_stream (ai-helper.php) : path, org_id, user_id,
 * credits, ledger (false = rien noter). Sans path, il est déduit du fichier appelant
 * (ak_ai_guess_path) ; sans org_id, l'association de la session. Une table absente ou une
 * erreur n'empêchent jamais l'appel.
 * --------------------------------------------------------------
 */

if (!defined('AK_AI_MODEL_WRITE'))       define('AK_AI_MODEL_WRITE', 'claude-sonnet-5-5');
if (!defined('AK_AI_MODEL_FAST'))        define('AK_AI_MODEL_FAST', 'claude-haiku-5-5');
if (!defined('AK_AI_EFFORT_WRITE'))      define('AK_AI_EFFORT_WRITE', 'low');
if (!defined('AK_AI_EFFORT_FAST'))       define('AK_AI_EFFORT_FAST', 'low');
// Place laissée à la réflexion en plus de la longueur de réponse demandée (plafond, pas une
// consommation : seuls les jetons réellement produits sont facturés)
if (!defined('AK_AI_THINKING_HEADROOM')) define('AK_AI_THINKING_HEADROOM', 2048);
if (!defined('AK_AI_DEFAULT_API_URL'))   define('AK_AI_DEFAULT_API_URL', 'https://api.anthropic.com/v1/messages');
// Message montré quand l'IA décline une demande (refus de sécurité)
if (!defined('AK_AI_REFUSAL_MESSAGE'))   define('AK_AI_REFUSAL_MESSAGE', 'L’IA n’a pas pu traiter cette demande. Reformulez-la puis réessayez.');

if (!function_exists('ak_ai_supported_models')) {
    /** Modèles actuels acceptés en surcharge (tous prennent output_config.effort). */
    function ak_ai_supported_models(): array {
        return [
            'claude-sonnet-5-5', 'claude-haiku-5-5', 'claude-opus-5-5',
            'claude-sonnet-5', 'claude-opus-5', 'claude-opus-4-8', 'claude-opus-4-7',
            'claude-opus-4-6', 'claude-sonnet-4-6', 'claude-fable-5-1', 'claude-fable-5',
        ];
    }
}

if (!function_exists('ak_ai_model')) {
    /**
     * Modèle à utiliser. $purpose : 'write' (rédaction) ou 'fast' (tâches de fond).
     * $override : identifiant lu dans une configuration ; suivi seulement s'il est encore servi.
     */
    function ak_ai_model(string $purpose = 'write', ?string $override = null): string {
        $o = strtolower(trim((string)$override));
        if ($o !== '' && in_array($o, ak_ai_supported_models(), true)) return $o;
        if ($o !== '' && $o !== strtolower(AK_AI_MODEL_WRITE) && $o !== strtolower(AK_AI_MODEL_FAST)) {
            // Une seule ligne par requête PHP suffit à le repérer dans le journal
            static $warned = [];
            if (empty($warned[$o])) { $warned[$o] = true; error_log('[ai-models] modèle « ' . $o . ' » ignoré (plus servi) : ' . ($purpose === 'fast' ? AK_AI_MODEL_FAST : AK_AI_MODEL_WRITE) . ' utilisé'); }
        }
        return $purpose === 'fast' ? AK_AI_MODEL_FAST : AK_AI_MODEL_WRITE;
    }
}

if (!function_exists('ak_ai_api_url')) {
    function ak_ai_api_url(): string {
        return defined('ANTHROPIC_API_URL') && ANTHROPIC_API_URL ? (string)ANTHROPIC_API_URL : AK_AI_DEFAULT_API_URL;
    }
}

if (!function_exists('ak_ai_effort')) {
    function ak_ai_effort(string $purpose, ?string $effort = null): string {
        $e = $effort ?: ($purpose === 'fast' ? AK_AI_EFFORT_FAST : AK_AI_EFFORT_WRITE);
        return in_array($e, ['low', 'medium', 'high', 'xhigh', 'max'], true) ? $e : 'low';
    }
}

if (!function_exists('ak_ai_uses_fallbacks')) {
    /** Repli côté serveur (forme "default") : modèles qui l'acceptent, et seulement sur l'API Anthropic directe. */
    function ak_ai_uses_fallbacks(string $model, string $url): bool {
        if (!in_array($model, ['claude-sonnet-5-5', 'claude-opus-5-5', 'claude-opus-5'], true)) return false;
        // Banc de test uniquement (fausse API locale) : jamais défini en production
        if (defined('AK_AI_FALLBACKS_ANY_HOST') && AK_AI_FALLBACKS_ANY_HOST) return true;
        return strtolower((string)parse_url($url, PHP_URL_HOST)) === 'api.anthropic.com';
    }
}

if (!function_exists('ak_ai_payload')) {
    /**
     * Complète une requête Messages pour les modèles actuels.
     * $req : system, messages, max_tokens (+ stream éventuel). Ne JAMAIS y mettre temperature,
     * top_p, top_k, ni un dernier message « assistant » (pré-remplissage) : refusés (400).
     * Retourne [payload, en-têtes supplémentaires].
     */
    function ak_ai_payload(string $model, string $effort, array $req, string $url, bool $with_fallbacks = true): array {
        unset($req['temperature'], $req['top_p'], $req['top_k'], $req['thinking']);
        $req['model'] = $model;
        $req['max_tokens'] = max(1, (int)($req['max_tokens'] ?? 1024)) + AK_AI_THINKING_HEADROOM;
        $req['output_config'] = array_merge((array)($req['output_config'] ?? []), ['effort' => $effort]);
        $headers = [];
        if ($with_fallbacks && ak_ai_uses_fallbacks($model, $url)) {
            $req['fallbacks'] = 'default';
            $headers[] = 'anthropic-beta: server-side-fallback-2026-07-01';
        }
        return [$req, $headers];
    }
}

if (!function_exists('ak_ai_fallback_rejected')) {
    /** L'API a refusé le paramètre de repli (400 qui le nomme) : on refait l'appel sans lui. */
    function ak_ai_fallback_rejected(int $code, string $raw): bool {
        return $code === 400 && (stripos($raw, 'fallback') !== false || stripos($raw, 'anthropic-beta') !== false);
    }
}

if (!function_exists('ak_ai_messages')) {
    /**
     * Appel non diffusé (non-streaming) à l'API Messages.
     * $opts : purpose ('write'|'fast'), effort, model_override, api_key (sinon ANTHROPIC_API_KEY
     *         puis CLAUDE_API_KEY), url, timeout, connect_timeout.
     * Retourne ['ok' => bool, 'code' => int, 'data' => ?array, 'raw' => string, 'curl_error' => string,
     *           'model' => string, 'refusal' => bool].
     * ok = HTTP 2xx et réponse JSON lisible ; un refus (stop_reason « refusal ») a ok = true et
     * refusal = true : à traiter par l'appelant avant de lire le texte.
     */
    function ak_ai_messages(array $req, array $opts = []): array {
        $purpose = ($opts['purpose'] ?? 'write') === 'fast' ? 'fast' : 'write';
        $model   = ak_ai_model($purpose, $opts['model_override'] ?? null);
        $effort  = ak_ai_effort($purpose, $opts['effort'] ?? null);
        $url     = (string)($opts['url'] ?? ak_ai_api_url());
        $api_key = (string)($opts['api_key'] ?? (defined('ANTHROPIC_API_KEY') && ANTHROPIC_API_KEY ? ANTHROPIC_API_KEY : (defined('CLAUDE_API_KEY') ? CLAUDE_API_KEY : '')));
        $out = ['ok' => false, 'code' => 0, 'data' => null, 'raw' => '', 'curl_error' => '', 'model' => $model, 'refusal' => false];

        foreach ([true, false] as $with_fallbacks) {
            [$payload, $extra] = ak_ai_payload($model, $effort, $req, $url, $with_fallbacks);
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
                CURLOPT_HTTPHEADER     => array_merge([
                    'content-type: application/json',
                    'x-api-key: ' . $api_key,
                    'anthropic-version: 2023-06-01',
                ], $extra),
                CURLOPT_TIMEOUT        => (int)($opts['timeout'] ?? 180),
                CURLOPT_CONNECTTIMEOUT => (int)($opts['connect_timeout'] ?? 15),
                CURLOPT_NOSIGNAL       => true,
            ]);
            $raw  = curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err  = curl_error($ch);
            curl_close($ch);
            $out['raw'] = is_string($raw) ? $raw : '';
            $out['code'] = $code;
            $out['curl_error'] = $raw === false ? ($err ?: 'inconnue') : '';
            if ($raw !== false && $with_fallbacks && $extra && ak_ai_fallback_rejected($code, $out['raw'])) {
                error_log('[ai-models] repli serveur refusé par l\'API, nouvel essai sans : ' . mb_substr($out['raw'], 0, 200));
                continue;
            }
            break;
        }
        if ($out['curl_error'] !== '') return $out;
        $data = json_decode($out['raw'], true);
        $out['data'] = is_array($data) ? $data : null;
        if ($out['code'] >= 200 && $out['code'] < 300 && is_array($data)) {
            $out['ok'] = true;
            $out['refusal'] = ak_ai_is_refusal($data);
            if (!empty($data['model'])) $out['model'] = (string)$data['model'];
            // Compteur IA unifié : seulement un appel réussi et sans refus (jamais bloquant)
            if (!$out['refusal'] && ($opts['ledger'] ?? true) !== false) {
                ak_ai_ledger_record(ak_ai_ledger_context($opts, $purpose), $out['model'], $purpose, ak_ai_usage($data));
            }
        }
        return $out;
    }
}

if (!function_exists('ak_ai_stream_request')) {
    /**
     * Prépare un appel diffusé (streaming) : [url, payload JSON, en-têtes].
     * $with_fallbacks = false pour le second essai après un refus du paramètre de repli.
     */
    function ak_ai_stream_request(array $req, array $opts = [], bool $with_fallbacks = true): array {
        $purpose = ($opts['purpose'] ?? 'write') === 'fast' ? 'fast' : 'write';
        $model   = ak_ai_model($purpose, $opts['model_override'] ?? null);
        $url     = (string)($opts['url'] ?? ak_ai_api_url());
        $api_key = (string)($opts['api_key'] ?? (defined('ANTHROPIC_API_KEY') && ANTHROPIC_API_KEY ? ANTHROPIC_API_KEY : (defined('CLAUDE_API_KEY') ? CLAUDE_API_KEY : '')));
        $req['stream'] = true;
        [$payload, $extra] = ak_ai_payload($model, ak_ai_effort($purpose, $opts['effort'] ?? null), $req, $url, $with_fallbacks);
        return [$url, json_encode($payload, JSON_UNESCAPED_UNICODE), array_merge([
            'content-type: application/json',
            'accept: text/event-stream',
            'x-api-key: ' . $api_key,
            'anthropic-version: 2023-06-01',
        ], $extra), (bool)$extra];
    }
}

if (!function_exists('ak_ai_response_text')) {
    /** Texte de la réponse : uniquement les blocs « text », dans l'ordre (les blocs thinking / fallback sont ignorés). */
    function ak_ai_response_text(?array $data): string {
        $t = '';
        foreach ((array)($data['content'] ?? []) as $b) {
            if (is_array($b) && ($b['type'] ?? '') === 'text' && isset($b['text'])) $t .= (string)$b['text'];
        }
        return $t;
    }
}

if (!function_exists('ak_ai_is_refusal')) {
    function ak_ai_is_refusal(?array $data): bool {
        return is_array($data) && ($data['stop_reason'] ?? '') === 'refusal';
    }
}

if (!function_exists('ak_ai_usage')) {
    /** [jetons d'entrée, jetons de sortie] (la sortie inclut la réflexion). */
    function ak_ai_usage(?array $data): array {
        return [(int)($data['usage']['input_tokens'] ?? 0), (int)($data['usage']['output_tokens'] ?? 0)];
    }
}

if (!function_exists('ak_ai_path_credits')) {
    /**
     * Crédits IA consommés par un appel, selon son chemin. 1 crédit = 1 texte rédigé, 1 réponse
     * du copilote ou 1 justificatif lu. Les tâches de fond (tri de la boîte mail, priorités du jour
     * préparées automatiquement, coach hebdomadaire, blog, vérifications) ne coûtent rien.
     * Chemin inconnu : 1 si une session utilisateur existe (hors CLI), 0 sinon.
     */
    function ak_ai_path_credits(string $path): int {
        $map = [
            'hub' => 1, 'generateur' => 1, 'copilote' => 1, 'projet' => 1, 'justificatif' => 1,
            'mail-brouillon' => 1, 'priorites-refresh' => 1, 'relance-ia' => 1,
            'appli-factures' => 1, 'appli-stats' => 1,
            // Rapport du coach demandé à la main (« Générer maintenant », site et appli) : un texte
            // rédigé à la demande, donc 1 crédit. Seul le coach HEBDOMADAIRE (cron) est gratuit.
            'coach-manuel' => 1,
            'mail-tri' => 0, 'priorites' => 0, 'coach' => 0, 'blog' => 0, 'systeme' => 0,
        ];
        $p = strtolower(trim($path));
        if (isset($map[$p])) return $map[$p];
        return (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION['user_id'])) ? 1 : 0;
    }
}

if (!function_exists('ak_ai_guess_path')) {
    /**
     * Chemin d'un appel IA qui n'en précise pas : déduit du premier fichier appelant qui n'est pas
     * une bibliothèque IA commune, sinon du script lancé. 'autre' si rien ne correspond.
     */
    function ak_ai_guess_path(string $purpose = 'write'): string {
        $by_file = [
            'copilote-engine.php' => 'copilote', 'mon-asso-copilote-ask.php' => 'copilote', 'mon-asso-copilote.php' => 'copilote',
            'mon-asso-ia-generate.php' => 'hub', 'communication-generer.php' => 'generateur',
            'action-ia.php' => 'projet', 'action-scan-facture.php' => 'justificatif',
            'relance-action.php' => 'relance-ia', 'app-invoices-ai.php' => 'appli-factures', 'app-stats-ai.php' => 'appli-stats',
            'action-coach-ia.php' => 'coach-manuel', 'cron-coach-ia.php' => 'coach', 'app-coach-generate.php' => 'coach-manuel', 'app-coach.php' => 'coach',
            'today-ai-helper.php' => 'priorites', 'cli-ai-check.php' => 'systeme',
        ];
        $skip = ['ai-models.php', 'ai-helper.php', 'asso-ai-helpers.php', 'ai-consent-helper.php'];
        $match = function (string $file) use ($by_file, $purpose): ?string {
            $base = basename($file);
            if ($base === 'mail-helpers.php') return $purpose === 'fast' ? 'mail-tri' : 'mail-brouillon';
            if (strpos(str_replace('\\', '/', $file), '/admin-blog/') !== false) return 'blog';
            return $by_file[$base] ?? null;
        };
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 16) as $f) {
            $file = (string)($f['file'] ?? '');
            if ($file === '' || in_array(basename($file), $skip, true)) continue;
            $p = $match($file);
            if ($p !== null) return $p;
        }
        $script = (string)($_SERVER['SCRIPT_FILENAME'] ?? '');
        return ($script !== '' ? $match($script) : null) ?? 'autre';
    }
}

if (!function_exists('ak_ai_ledger_context')) {
    /**
     * Contexte d'un appel pour le registre, d'après ses options : path (deviné sinon), org_id et
     * user_id (session sinon), credits (ak_ai_path_credits sinon).
     * @return array{path:string, org_id:?int, user_id:?int, credits:int}
     */
    function ak_ai_ledger_context(array $opts, string $purpose = 'write'): array {
        $path = isset($opts['path']) && is_string($opts['path']) && trim($opts['path']) !== ''
            ? substr(preg_replace('/[^a-z0-9_-]/', '', strtolower(trim($opts['path']))), 0, 40) : '';
        if ($path === '') $path = ak_ai_guess_path($purpose);
        $sess = (isset($_SESSION) && is_array($_SESSION)) ? $_SESSION : [];
        $user_id = isset($opts['user_id']) ? (int)$opts['user_id'] : (int)($sess['user_id'] ?? 0);
        $org_id = isset($opts['org_id']) ? (int)$opts['org_id'] : (int)($sess['org_id'] ?? 0);
        // Session sans association en mémoire : celle du compte
        if (!isset($opts['org_id']) && $org_id <= 0 && $user_id > 0 && isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) {
            try {
                $st = $GLOBALS['pdo']->prepare("SELECT org_id FROM users WHERE id = ? LIMIT 1");
                $st->execute([$user_id]);
                $org_id = (int)$st->fetchColumn();
            } catch (Throwable $e) {
                $org_id = 0;
            }
        }
        $credits = isset($opts['credits']) ? max(0, min(255, (int)$opts['credits'])) : ak_ai_path_credits($path);
        return ['path' => $path, 'org_id' => $org_id > 0 ? $org_id : null, 'user_id' => $user_id > 0 ? $user_id : null, 'credits' => $credits];
    }
}

if (!function_exists('ak_ai_ledger_record')) {
    /**
     * Note un appel IA réussi dans ai_usage_ledger. $ctx : ak_ai_ledger_context() ; $usage :
     * [jetons d'entrée, jetons de sortie] (ak_ai_usage). Utilise $GLOBALS['pdo'] ; table absente
     * ou toute erreur : rien n'est noté, l'appel n'échoue jamais (une ligne au journal par requête).
     */
    function ak_ai_ledger_record(array $ctx, string $model, string $purpose, array $usage): void {
        static $warned = false;
        $pdo = $GLOBALS['pdo'] ?? null;
        if (!($pdo instanceof PDO)) return;
        try {
            $pdo->prepare("INSERT INTO ai_usage_ledger (org_id, user_id, path, purpose, model, tokens_in, tokens_out, credits, created_at)
                           VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())")
                ->execute([
                    isset($ctx['org_id']) && (int)$ctx['org_id'] > 0 ? (int)$ctx['org_id'] : null,
                    isset($ctx['user_id']) && (int)$ctx['user_id'] > 0 ? (int)$ctx['user_id'] : null,
                    substr((string)($ctx['path'] ?? 'autre'), 0, 40) ?: 'autre',
                    $purpose === 'fast' ? 'fast' : 'write',
                    substr($model, 0, 64),
                    max(0, (int)($usage[0] ?? 0)),
                    max(0, (int)($usage[1] ?? 0)),
                    max(0, min(255, (int)($ctx['credits'] ?? 0))),
                ]);
        } catch (Throwable $e) {
            if (!$warned) { $warned = true; error_log('[ai-models] registre IA non écrit : ' . get_class($e)); }
        }
    }
}
