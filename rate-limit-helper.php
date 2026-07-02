<?php
/**
 * ============================================================
 * ASSOKIT — rate-limit-helper.php
 * Anti-flood / anti-DDoS applicatif (sliding window via /tmp)
 * APCu absent sur o2switch → stockage fichier avec verrou.
 * Politique FAIL-OPEN : en cas d'erreur disque, on n'bloque pas.
 * ============================================================
 */
if (!function_exists('ak_rate_limit')) {

/**
 * Retourne true si la requête est autorisée, false si le quota est dépassé.
 * @param string $bucket  identifiant logique (ex: 'api_messages', 'ia_generate')
 * @param int    $max     nombre max de requêtes dans la fenêtre
 * @param int    $window  fenêtre en secondes
 * @param string $id      identité (par défaut IP ; passer user_id si connecté)
 */
function ak_rate_limit(string $bucket, int $max, int $window, string $id = ''): bool
{
    if ($id === '') {
        $id = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    }
    $key  = preg_replace('/[^a-zA-Z0-9_]/', '_', $bucket . '_' . $id);
    $file = sys_get_temp_dir() . '/akrl_' . $key;
    $now  = time();

    $fp = @fopen($file, 'c+');
    if ($fp === false) {
        return true; // fail-open
    }

    $allowed = true;
    if (flock($fp, LOCK_EX)) {
        $content = stream_get_contents($fp);
        $timestamps = [];
        if ($content !== false && $content !== '') {
            foreach (explode(',', $content) as $t) {
                $t = (int)$t;
                if ($t > $now - $window) {
                    $timestamps[] = $t;
                }
            }
        }
        $allowed = count($timestamps) < $max;
        if ($allowed) {
            $timestamps[] = $now;
            ftruncate($fp, 0);
            rewind($fp);
            fwrite($fp, implode(',', $timestamps));
        }
        flock($fp, LOCK_UN);
    }
    fclose($fp);

    // GC probabiliste léger (1%) : purge les vieux fichiers akrl_
    if (mt_rand(1, 100) === 1) {
        foreach (glob(sys_get_temp_dir() . '/akrl_*') ?: [] as $old) {
            if (@filemtime($old) < $now - 3600) { @unlink($old); }
        }
    }

    return $allowed;
}

/**
 * Bloque la requête (HTTP 429) si le quota est dépassé.
 */
function ak_rate_limit_or_die(string $bucket, int $max, int $window, string $id = ''): void
{
    if (!ak_rate_limit($bucket, $max, $window, $id)) {
        http_response_code(429);
        header('Retry-After: ' . $window);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'error'   => 'too_many_requests',
            'message' => 'Trop de requêtes en peu de temps. Merci de patienter quelques instants.',
        ]);
        exit;
    }
}

}
