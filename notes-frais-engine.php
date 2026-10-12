<?php
/**
 * notes-frais-engine.php — Notes de frais & barème kilométrique
 * ------------------------------------------------------------------
 * Logique déterministe des notes de frais : indemnités kilométriques
 * (barème administratif) + justificatifs, totaux, listing.
 *
 * Justificatifs (photo du ticket, PDF) : rangés hors du dossier web,
 * servis par /notes-de-frais?receipt=<ligne> après contrôle d'accès.
 * Colonnes receipt_file / receipt_name : migration 2026-10-11-L06-finances
 * (sans elle, la page fonctionne, sans pièce jointe).
 *
 * ⚠️ BARÈME KILOMÉTRIQUE : indicatif. Le barème officiel est publié
 *    chaque année par arrêté (voitures, selon la puissance fiscale et
 *    la distance annuelle). Vérifiez l'arrêté en vigueur ; le montant
 *    calculé est une ESTIMATION basée sur la distance saisie.
 * ------------------------------------------------------------------
 */

if (!function_exists('nf_bareme_meta')) {
/** Métadonnées du barème utilisé (à mettre à jour chaque année). */
function nf_bareme_meta(): array {
    return ['ref' => '2025 (revenus 2024)', 'note' => 'Barème kilométrique administratif — voitures. Indicatif : vérifiez l\'arrêté en vigueur.'];
}
}

if (!function_exists('nf_bareme_voiture')) {
/**
 * Barème kilométrique « voitures » : coefficients par puissance fiscale (CV)
 * et par tranche de distance (≤5000 ; 5001–20000 ; >20000).
 * Formules : t1 = d*a ; t2 = d*b + c ; t3 = d*e.
 */
function nf_bareme_voiture(): array {
    return [
        3 => ['a'=>0.529, 'b'=>0.316, 'c'=>1065, 'e'=>0.370],  // 3 CV et moins
        4 => ['a'=>0.606, 'b'=>0.340, 'c'=>1330, 'e'=>0.407],
        5 => ['a'=>0.636, 'b'=>0.357, 'c'=>1395, 'e'=>0.427],
        6 => ['a'=>0.665, 'b'=>0.374, 'c'=>1457, 'e'=>0.447],
        7 => ['a'=>0.697, 'b'=>0.394, 'c'=>1515, 'e'=>0.470],  // 7 CV et plus
    ];
}
}

if (!function_exists('nf_cv_key')) {
/** Ramène une puissance fiscale à la clé de barème (3..7). */
function nf_cv_key(int $cv): int {
    if ($cv <= 3) return 3;
    if ($cv >= 7) return 7;
    return $cv;
}
}

if (!function_exists('nf_ik_amount_cents')) {
/**
 * Indemnité kilométrique (en centimes) pour une distance $km et une
 * puissance $cv. La tranche est choisie d'après la distance saisie.
 */
function nf_ik_amount_cents(int $cv, int $km): int {
    if ($km <= 0) return 0;
    $b = nf_bareme_voiture()[nf_cv_key($cv)];
    if ($km <= 5000)       $eur = $km * $b['a'];
    elseif ($km <= 20000)  $eur = $km * $b['b'] + $b['c'];
    else                   $eur = $km * $b['e'];
    return (int) round($eur * 100);
}
}

if (!function_exists('nf_categories')) {
function nf_categories(): array {
    return ['transport'=>'Transport', 'repas'=>'Repas', 'hebergement'=>'Hébergement',
            'fourniture'=>'Fournitures', 'mileage'=>'Indemnité kilométrique', 'autre'=>'Autre'];
}
}

if (!function_exists('nf_status_meta')) {
function nf_status_meta(string $s): array {
    return [
        'draft'      => ['Brouillon',   '#475569', '#F1F5F9'],
        'submitted'  => ['Soumise',     '#3730A3', '#E0E7FF'],
        'approved'   => ['Approuvée',   '#065F46', '#D1FAE5'],
        'rejected'   => ['Refusée',     '#991B1B', '#FEE2E2'],
        'reimbursed' => ['Remboursée',  '#0F766E', '#CCFBF1'],
    ][$s] ?? ['—', '#475569', '#F1F5F9'];
}
}

if (!function_exists('nf_eur')) {
function nf_eur(int $cents): string { return number_format($cents/100, 2, ',', ' ').' €'; }
}

if (!function_exists('nf_recompute_total')) {
/** Recalcule et persiste le total d'une note (somme des lignes). */
function nf_recompute_total(PDO $pdo, int $org_id, int $report_id): int {
    $st = $pdo->prepare("SELECT COALESCE(SUM(amount_ttc_cents),0) FROM expense_report_lines WHERE report_id=? AND org_id=?");
    $st->execute([$report_id, $org_id]);
    $tot = (int)$st->fetchColumn();
    $pdo->prepare("UPDATE expense_reports SET total_cents=? WHERE id=? AND org_id=?")->execute([$tot, $report_id, $org_id]);
    return $tot;
}
}

if (!function_exists('nf_load_report')) {
/** Charge une note + ses lignes, scopée org (et user si non-admin). */
function nf_load_report(PDO $pdo, int $org_id, int $report_id, ?int $restrict_user = null): ?array {
    $sql = "SELECT * FROM expense_reports WHERE id=? AND org_id=?";
    $args = [$report_id, $org_id];
    if ($restrict_user !== null) { $sql .= " AND user_id=?"; $args[] = $restrict_user; }
    $st = $pdo->prepare($sql); $st->execute($args);
    $rep = $st->fetch(PDO::FETCH_ASSOC);
    if (!$rep) return null;
    $ls = $pdo->prepare("SELECT * FROM expense_report_lines WHERE report_id=? AND org_id=? ORDER BY spent_at ASC, id ASC");
    $ls->execute([$report_id, $org_id]);
    $rep['lines'] = $ls->fetchAll(PDO::FETCH_ASSOC) ?: [];
    return $rep;
}
}

if (!function_exists('nf_list_reports')) {
function nf_list_reports(PDO $pdo, int $org_id, ?int $restrict_user = null): array {
    $sql = "SELECT r.*, u.first_name, u.last_name FROM expense_reports r
            LEFT JOIN users u ON u.id = r.user_id
            WHERE r.org_id = ?";
    $args = [$org_id];
    if ($restrict_user !== null) { $sql .= " AND r.user_id = ?"; $args[] = $restrict_user; }
    $sql .= " ORDER BY r.created_at DESC LIMIT 300";
    $st = $pdo->prepare($sql); $st->execute($args);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}
}

if (!function_exists('nf_parse_cents')) {
/**
 * Montant saisi en euros -> centimes, ou null s'il n'est pas lisible.
 * Accepte « 24,50 », « 24.50 », « 1 234,56 », « 1.234,56 » (point de
 * milliers avec virgule décimale), espaces insécables et « € ». Refuse
 * ce qui reste ambigu (« 1.234 », « 1,2,3 ») au lieu de le deviner :
 * « 1.234,56 » était enregistré 1,23 €.
 */
function nf_parse_cents($s): ?int {
    $s = str_replace(["\u{00A0}", "\u{202F}", ' ', '€'], '', trim((string)$s));
    if ($s === '') return null;
    if (strpos($s, ',') !== false) {
        if (substr_count($s, ',') > 1) return null;
        // Points de milliers seulement par groupes de 3 chiffres
        if (strpos($s, '.') !== false && !preg_match('/^\d{1,3}(\.\d{3})+,/', $s)) return null;
        $s = str_replace(['.', ','], ['', '.'], $s);
    }
    if (!preg_match('/^\d+(\.\d{1,2})?$/', $s)) return null;
    return (int)round(((float)$s) * 100);
}
}

if (!function_exists('nf_has_receipts')) {
/** Vrai si la base sait garder un justificatif par ligne (migration passée). */
function nf_has_receipts(PDO $pdo): bool {
    require_once __DIR__ . '/trial-helpers.php';
    $cols = ak_db_columns($pdo, 'expense_report_lines');
    return isset($cols['receipt_file'], $cols['receipt_name']);
}
}

if (!function_exists('nf_receipt_dir')) {
/**
 * Dossier des justificatifs d'une org. Hors du dossier web d'abord (un
 * cran au-dessus de public_html) ; sinon dossier interne fermé par son
 * propre .htaccess. Un ticket de caisse n'a pas à être téléchargeable
 * par qui devine son nom.
 */
function nf_receipt_dir(int $org_id): string {
    $sub = '/' . max(0, $org_id);
    $dehors = dirname(__DIR__) . '/private_notes_frais';
    if ((is_dir($dehors) || @mkdir($dehors, 0700, true)) && is_writable($dehors)) {
        if (!is_dir($dehors . $sub)) @mkdir($dehors . $sub, 0700, true);
        return $dehors . $sub;
    }
    $dedans = __DIR__ . '/private_notes_frais';
    if (!is_dir($dedans)) @mkdir($dedans, 0700, true);
    if (!is_file($dedans . '/.htaccess')) {
        @file_put_contents($dedans . '/.htaccess', "Require all denied\nOptions -Indexes\n");
    }
    if (!is_dir($dedans . $sub)) @mkdir($dedans . $sub, 0700, true);
    return $dedans . $sub;
}
}

if (!function_exists('nf_receipt_path')) {
/** Chemin complet d'un justificatif, ou null s'il n'existe pas. */
function nf_receipt_path(int $org_id, ?string $file): ?string {
    $file = basename((string)$file);
    if ($file === '' || !preg_match('/^[a-f0-9]{32}\.(pdf|jpg|jpeg|png|webp|heic)$/', $file)) return null;
    $p = nf_receipt_dir($org_id) . '/' . $file;
    return is_file($p) ? $p : null;
}
}

if (!function_exists('nf_receipt_delete')) {
function nf_receipt_delete(int $org_id, ?string $file): void {
    $p = nf_receipt_path($org_id, $file);
    if ($p !== null) @unlink($p);
}
}

if (!function_exists('nf_ini_bytes')) {
/** Taille lue dans php.ini (« 8M », « 64M »…) en octets, 0 si absente. */
function nf_ini_bytes(string $key): int {
    $v = trim((string)ini_get($key));
    if ($v === '') return 0;
    $n = (float)$v;
    switch (strtolower(substr($v, -1))) {
        case 'g': $n *= 1024;
        case 'm': $n *= 1024;
        case 'k': $n *= 1024;
    }
    return (int)$n;
}
}

if (!function_exists('nf_mo')) {
/** « 10 Mo », « 1,5 Mo ». */
function nf_mo(int $bytes): string {
    return str_replace('.', ',', (string)round($bytes / 1048576, 1)) . ' Mo';
}
}

if (!function_exists('nf_receipt_max_bytes')) {
/**
 * Taille maximale d'un justificatif : 10 Mo, ou moins si l'hébergement
 * est réglé plus bas (upload_max_filesize, post_max_size) : c'est alors
 * cette limite qu'on annonce et qu'on contrôle.
 */
function nf_receipt_max_bytes(): int {
    $max = 10 * 1024 * 1024;
    foreach (['upload_max_filesize', 'post_max_size'] as $k) {
        $ini = nf_ini_bytes($k);
        if ($ini > 0 && $ini < $max) $max = $ini;
    }
    return $max;
}
}

if (!function_exists('nf_receipt_store')) {
/**
 * Range le justificatif envoyé ($_FILES[...]). Aucun fichier : ok sans
 * 'file'. Formats : PDF, JPG, PNG, WebP, HEIC (photo d'iPhone), 10 Mo
 * au plus (moins si l'hébergement le limite : nf_receipt_max_bytes()).
 * @return array{ok:bool, message:string, file?:string, name?:string}
 */
function nf_receipt_store(array $f, int $org_id): array {
    $max = nf_receipt_max_bytes();
    $too_big = 'Justificatif trop lourd (' . nf_mo($max) . ' maximum).';
    $err = (int)($f['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($err === UPLOAD_ERR_NO_FILE) return ['ok' => true, 'message' => ''];
    if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) return ['ok' => false, 'message' => $too_big];
    if ($err !== UPLOAD_ERR_OK || empty($f['tmp_name']) || !is_uploaded_file($f['tmp_name'])) {
        return ['ok' => false, 'message' => 'Le justificatif n\'a pas pu être envoyé. Réessayez.'];
    }
    if ((int)($f['size'] ?? 0) > $max) return ['ok' => false, 'message' => $too_big];
    $ext = strtolower(pathinfo((string)($f['name'] ?? ''), PATHINFO_EXTENSION));
    $mime_ok = ['pdf' => ['application/pdf'], 'jpg' => ['image/jpeg'], 'jpeg' => ['image/jpeg'],
                'png' => ['image/png'], 'webp' => ['image/webp'],
                // HEIC : une libmagic ancienne ne le reconnaît pas (octet-stream) ;
                // il n'est de toute façon jamais affiché, seulement téléchargé.
                'heic' => ['image/heic', 'image/heif', 'image/heic-sequence', 'application/octet-stream']];
    if (!isset($mime_ok[$ext])) return ['ok' => false, 'message' => 'Format non accepté : PDF, JPG, PNG, WebP ou HEIC.'];
    // Le contenu réel doit correspondre à l'extension (pas seulement le nom)
    $real = '';
    try { $real = (string)((new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']) ?: ''); } catch (Throwable $e) {}
    if ($real !== '' && !in_array($real, $mime_ok[$ext], true)) {
        return ['ok' => false, 'message' => 'Le contenu du justificatif ne correspond pas à son format.'];
    }
    $file = bin2hex(random_bytes(16)) . '.' . $ext;   // nom imprévisible
    $dir = nf_receipt_dir($org_id);
    if (!is_dir($dir) || !@move_uploaded_file($f['tmp_name'], $dir . '/' . $file)) {
        error_log('[notes-frais] justificatif non enregistré dans ' . $dir);
        return ['ok' => false, 'message' => 'Le justificatif n\'a pas pu être enregistré. Réessayez.'];
    }
    $name = preg_replace('/[^\w.\- ]+/u', '_', basename((string)($f['name'] ?? 'justificatif.' . $ext)));
    return ['ok' => true, 'message' => '', 'file' => $file, 'name' => mb_substr($name, 0, 190)];
}
}

if (!function_exists('nf_managers')) {
/**
 * Gestionnaires des finances de l'org (admin ou droit « finances »), sauf
 * $except. Un suiveur (financeur externe) n'en fait jamais partie : il n'a
 * pas accès aux notes de frais.
 */
function nf_managers(PDO $pdo, int $org_id, int $except = 0): array {
    try {
        $st = $pdo->prepare("SELECT id FROM users WHERE org_id = ? AND is_active = 1 AND deleted_at IS NULL
                               AND (role = 'admin' OR (can_manage_finances = 1 AND COALESCE(role, '') <> 'follower')) AND id <> ?");
        $st->execute([$org_id, $except]);
        return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    } catch (Throwable $e) { return []; }
}
}

if (!function_exists('nf_notify')) {
/** Notification dans l'appli (best-effort : ne bloque jamais l'action). */
function nf_notify(PDO $pdo, array $user_ids, string $title, string $body, int $report_id, int $actor_id): void {
    if (!$user_ids) return;
    require_once __DIR__ . '/notification-helpers.php';
    if (!function_exists('ak_notif_create')) return;
    foreach (array_unique($user_ids) as $u) {
        if ($u > 0) ak_notif_create($pdo, $u, 'expense_report', $title, $body, '/notes-de-frais?id=' . $report_id, null, null, null, $actor_id);
    }
}
}
