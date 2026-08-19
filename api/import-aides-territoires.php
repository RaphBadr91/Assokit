<?php
/**
 * api/import-aides-territoires.php
 * ------------------------------------------------------------------
 * Enrichit le catalogue de subventions depuis l'API Aides-territoires
 * (service public, MTES). Couche d'enrichissement AU-DESSUS du catalogue
 * curé : n'écrase jamais les dispositifs 'curation_assokit'.
 *
 * ⚠️  IMPORT PRUDENT : chaque aide importée arrive en status='draft'
 *     (is_verified=0). Elle n'apparaît PAS dans le radar tant qu'un
 *     humain (fondateur) ne l'a pas passée en 'active'. On ne diffuse
 *     jamais de données non relues aux associations.
 *
 * Pré-requis dans config.php :
 *     define('AIDES_TERRITOIRES_API_KEY', 'votre_cle_api');
 * (Obtenir une clé : https://aides-territoires.beta.gouv.fr/data/)
 *
 * Flux API (doc officielle) :
 *   1) POST /api/connexion/  header X-AUTH-TOKEN: <clé>   -> { "token": "<JWT>" }  (valide 24 h)
 *   2) GET  /api/aids/?targeted_audiences=association  header Authorization: Bearer <JWT>
 *      Pagination DRF : { count, next, previous, results:[...] }
 *
 * Exécution : php api/import-aides-territoires.php [audience] [maxpages]
 *   audience : association (défaut) | private_sector
 * ------------------------------------------------------------------
 */
require_once __DIR__ . '/../config.php';

$cli = (php_sapi_name() === 'cli');
if (!$cli) {
    require_once __DIR__ . '/../includes-layout.php';
    require_login();
    $u = current_user();
    $is_priv = !empty($u['is_super_admin']) || !empty($u['is_founder']);
    if (!$is_priv) {
        try {
            $st = $pdo->prepare("SELECT is_super_admin, is_founder FROM users WHERE id = ?");
            $st->execute([(int)($u['id'] ?? 0)]);
            if ($row = $st->fetch()) $is_priv = ((int)($row['is_super_admin'] ?? 0) === 1) || ((int)($row['is_founder'] ?? 0) === 1);
        } catch (Throwable $e) {}
    }
    if (!$is_priv) { http_response_code(403); exit('Réservé au fondateur.'); }
    header('Content-Type: text/plain; charset=utf-8');
}

if (!defined('AIDES_TERRITOIRES_API_KEY') || AIDES_TERRITOIRES_API_KEY === '') {
    exit("❌ Clé API absente. Ajoutez dans config.php :\n   define('AIDES_TERRITOIRES_API_KEY', 'votre_cle');\nObtenir une clé : https://aides-territoires.beta.gouv.fr/data/\n");
}

$API   = 'https://aides-territoires.beta.gouv.fr';
$audience = $argv[1] ?? ($_GET['audience'] ?? 'association');
if (!in_array($audience, ['association','private_sector'], true)) $audience = 'association';
$maxPages = (int)($argv[2] ?? ($_GET['maxpages'] ?? 20));
if ($maxPages < 1) $maxPages = 1;
if ($maxPages > 200) $maxPages = 200;
$beneficiary = $audience === 'private_sector' ? 'tpe' : 'association';

/** Petit client HTTP JSON. */
function at_http(string $method, string $url, array $headers = [], ?string $body = null): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 40,
        CURLOPT_HTTPHEADER     => array_merge(['Accept: application/json'], $headers),
        CURLOPT_USERAGENT      => 'AssokitRadar/1.0 (+https://assokit.fr)',
    ]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    $raw  = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    $json = $raw !== false ? json_decode($raw, true) : null;
    return ['code' => $code, 'json' => $json, 'err' => $err, 'raw' => $raw];
}

/** Nettoie un extrait HTML -> texte court. */
function at_clean(?string $html, int $max = 480): ?string {
    if (!$html) return null;
    $t = html_entity_decode(strip_tags($html), ENT_QUOTES, 'UTF-8');
    $t = trim(preg_replace('/\s+/', ' ', $t));
    if ($t === '') return null;
    return mb_substr($t, 0, $max);
}

/** recurrence AT -> enum interne. */
function at_recurrence($r): string {
    switch ((string)$r) {
        case 'oneoff':    return 'ponctuel';
        case 'ongoing':   return 'permanent';
        case 'recurring': return 'annuel';
        default:          return 'annuel';
    }
}

/** Premier financeur lisible (les champs AT peuvent être string ou objet). */
function at_first_financer($financers): string {
    if (is_array($financers)) {
        foreach ($financers as $f) {
            if (is_string($f) && trim($f) !== '') return trim($f);
            if (is_array($f) && !empty($f['name'])) return (string)$f['name'];
        }
    } elseif (is_string($financers) && trim($financers) !== '') {
        return trim($financers);
    }
    return 'Aides-territoires';
}

// ── 1) Authentification : échange clé -> token JWT ──────────────────
echo "🔑 Connexion à Aides-territoires…\n";
$auth = at_http('POST', "$API/api/connexion/", ['X-AUTH-TOKEN: ' . AIDES_TERRITOIRES_API_KEY]);
if ($auth['code'] !== 200 || empty($auth['json']['token'])) {
    exit("❌ Auth échouée (HTTP {$auth['code']}). {$auth['err']}\nRéponse : " . mb_substr((string)$auth['raw'], 0, 300) . "\n");
}
$token = $auth['json']['token'];
$bearer = ['Authorization: Bearer ' . $token];
echo "✅ Token obtenu.\n";

// ── 2) Upsert préparé (source=aides_territoires, draft) ─────────────
$sql = "INSERT INTO grant_catalog
    (title, funder_name, funder_type, program_code, summary, geo_scope, region_code, dept_code,
     sectors, beneficiary, amount_min, amount_max, req_qpv, req_interet_general, recurrence,
     opens_at, deadline_apply, next_expected, apply_url, source, source_ref, source_url,
     verified_at, is_verified, status, org_id)
    VALUES
    (:title, :funder_name, 'autre', :program_code, :summary, :geo_scope, NULL, NULL,
     '', :beneficiary, NULL, NULL, 0, 0, :recurrence,
     NULL, :deadline_apply, NULL, :apply_url, 'aides_territoires', :source_ref, :source_url,
     NULL, 0, 'draft', NULL)
    ON DUPLICATE KEY UPDATE
     title=VALUES(title), funder_name=VALUES(funder_name), summary=VALUES(summary),
     geo_scope=VALUES(geo_scope), beneficiary=VALUES(beneficiary), recurrence=VALUES(recurrence),
     deadline_apply=VALUES(deadline_apply), apply_url=VALUES(apply_url), source_url=VALUES(source_url),
     updated_at=NOW()";
$up = $pdo->prepare($sql);

// ── 3) Pagination ───────────────────────────────────────────────────
$url = "$API/api/aids/?targeted_audiences=" . urlencode($audience);
$page = 0; $imported = 0; $skipped = 0; $total = null;

while ($url && $page < $maxPages) {
    $page++;
    $res = at_http('GET', $url, $bearer);
    if ($res['code'] === 401) {
        echo "↻ Token expiré, reconnexion…\n";
        $auth = at_http('POST', "$API/api/connexion/", ['X-AUTH-TOKEN: ' . AIDES_TERRITOIRES_API_KEY]);
        if (!empty($auth['json']['token'])) { $token = $auth['json']['token']; $bearer = ['Authorization: Bearer ' . $token]; $res = at_http('GET', $url, $bearer); }
    }
    if ($res['code'] !== 200 || !is_array($res['json'])) {
        echo "❌ Page $page : HTTP {$res['code']} {$res['err']}\n";
        break;
    }
    if ($total === null && isset($res['json']['count'])) { $total = (int)$res['json']['count']; echo "→ $total aide(s) « $audience » côté API.\n"; }

    $results = $res['json']['results'] ?? [];
    foreach ($results as $aid) {
        $id = $aid['id'] ?? ($aid['slug'] ?? null);
        $name = $aid['name'] ?? ($aid['name_initial'] ?? null);
        if (!$id || !$name) { $skipped++; continue; }

        // Géo : on reste prudent (national) faute de mapping fiable des périmètres.
        $geo = 'national';
        if (!empty($aid['perimeter']) && is_string($aid['perimeter'])) {
            // périmètre nommé : on l'indique dans le résumé, sans deviner un code.
        }

        $summary = at_clean($aid['description'] ?? ($aid['short_title'] ?? null));
        if (!empty($aid['perimeter']) && is_string($aid['perimeter'])) {
            $summary = 'Périmètre : ' . $aid['perimeter'] . '. ' . ($summary ?? '');
            $summary = mb_substr($summary, 0, 480);
        }

        $apply = $aid['application_url'] ?? ($aid['origin_url'] ?? ($aid['url'] ?? null));
        $srcUrl = $aid['url'] ?? ($aid['origin_url'] ?? null);
        $deadline = !empty($aid['submission_deadline']) ? substr((string)$aid['submission_deadline'], 0, 10) : null;
        if ($deadline && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $deadline)) $deadline = null;

        try {
            $up->execute([
                ':title'         => mb_substr((string)$name, 0, 255),
                ':funder_name'   => mb_substr(at_first_financer($aid['financers'] ?? null), 0, 255),
                ':program_code'  => 'AT',
                ':summary'       => $summary,
                ':geo_scope'     => $geo,
                ':beneficiary'   => $beneficiary,
                ':recurrence'    => at_recurrence($aid['recurrence'] ?? null),
                ':deadline_apply'=> $deadline,
                ':apply_url'     => $apply ? mb_substr((string)$apply, 0, 512) : null,
                ':source_ref'    => 'at-' . $id,
                ':source_url'    => $srcUrl ? mb_substr((string)$srcUrl, 0, 512) : null,
            ]);
            $imported++;
        } catch (Throwable $e) {
            $skipped++;
            echo "  ⚠️ $id : " . $e->getMessage() . "\n";
        }
    }
    echo "  · page $page : " . count($results) . " traitée(s) (cumul importé : $imported)\n";

    $url = $res['json']['next'] ?? null;
    if ($url) usleep(300000); // 0,3 s entre pages, courtoisie API
}

echo "\n✅ Import terminé : $imported en brouillon, $skipped ignorée(s).\n";
echo "⚠️  Ces aides sont en status='draft' : relisez-les puis passez-les en 'active'\n";
echo "    (ex. SQL : UPDATE grant_catalog SET status='active', is_verified=1 WHERE source='aides_territoires' AND id IN (...));\n";
echo "    Elles n'apparaissent dans le radar qu'une fois actives.\n";
