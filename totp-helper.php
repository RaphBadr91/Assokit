<?php
/**
 * ASSOKIT — Helper TOTP (2FA) — RFC 6238, PHP pur, sans dépendance.
 * Compatible Google Authenticator / Authy / Microsoft Authenticator.
 */

/** Génère un secret base32 (longueur = nb de caractères). */
function ak_totp_generate_secret(int $length = 20): string {
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bytes = random_bytes($length);
    $secret = '';
    for ($i = 0; $i < $length; $i++) $secret .= $alphabet[ord($bytes[$i]) & 31];
    return $secret;
}

/** Décode une chaîne base32 en binaire. */
function ak_totp_base32_decode(string $b32): string {
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $b32 = strtoupper($b32);
    $buffer = 0; $bitsLeft = 0; $out = '';
    $len = strlen($b32);
    for ($i = 0; $i < $len; $i++) {
        $ch = $b32[$i];
        if ($ch === '=' || $ch === ' ') continue;
        $val = strpos($alphabet, $ch);
        if ($val === false) continue;
        $buffer = ($buffer << 5) | $val;
        $bitsLeft += 5;
        if ($bitsLeft >= 8) { $bitsLeft -= 8; $out .= chr(($buffer >> $bitsLeft) & 0xFF); }
    }
    return $out;
}

/** Calcule le code TOTP à 6 chiffres pour un créneau de 30s. */
function ak_totp_code(string $secret, ?int $timeSlice = null): string {
    if ($timeSlice === null) $timeSlice = (int)floor(time() / 30);
    $key = ak_totp_base32_decode($secret);
    $bin = pack('N*', 0) . pack('N*', $timeSlice); // compteur 64-bit big-endian
    $hash = hash_hmac('sha1', $bin, $key, true);
    $offset = ord($hash[strlen($hash) - 1]) & 0x0F;
    $value = unpack('N', substr($hash, $offset, 4))[1] & 0x7FFFFFFF;
    return str_pad((string)($value % 1000000), 6, '0', STR_PAD_LEFT);
}

/** Vérifie un code avec tolérance ±window créneaux (anti-décalage horloge). */
function ak_totp_verify(string $secret, string $code, int $window = 1): bool {
    $code = preg_replace('/\s+/', '', $code);
    if (!preg_match('/^\d{6}$/', $code)) return false;
    $current = (int)floor(time() / 30);
    for ($i = -$window; $i <= $window; $i++) {
        if (hash_equals(ak_totp_code($secret, $current + $i), $code)) return true;
    }
    return false;
}

/** Construit l'URI otpauth:// pour le QR code. */
function ak_totp_uri(string $secret, string $label, string $issuer = 'Assokit'): string {
    return 'otpauth://totp/' . rawurlencode($issuer . ':' . $label)
        . '?secret=' . $secret . '&issuer=' . rawurlencode($issuer)
        . '&algorithm=SHA1&digits=6&period=30';
}

/** Génère N codes de secours (usage unique). */
function ak_totp_generate_backup_codes(int $n = 8): array {
    $codes = [];
    for ($i = 0; $i < $n; $i++) $codes[] = strtoupper(bin2hex(random_bytes(4)));
    return $codes;
}

/** Codes de secours hachés → valeur de users.totp_backup_codes (JSON compact, « / » non échappés). */
function ak_totp_backup_encode(array $hashes): string {
    return (string)json_encode(array_values($hashes), JSON_UNESCAPED_SLASHES);
}

/**
 * Nombre de codes de secours (hachés en bcrypt) que users.totp_backup_codes peut contenir, au plus
 * $wanted. Les 8 codes font environ 500 caractères : une colonne VARCHAR(255) n'en tient que 4.
 * Sans cette limite, l'activation de la 2FA échouait en erreur 500 (« Data too long »). La
 * migration 2026-10-11-L01-auth.sql passe la colonne en TEXT (8 codes).
 */
function ak_totp_backup_capacity(PDO $pdo, int $wanted = 8): int {
    if (!function_exists('ak_db_columns')) require_once __DIR__ . '/trial-helpers.php';
    $type = (string)(ak_db_columns($pdo, 'users')['totp_backup_codes']['type'] ?? '');
    if (preg_match('/^(?:var)?char\((\d+)\)/', $type, $m)) $max = (int)$m[1];
    elseif (strpos($type, 'tinytext') === 0) $max = 255;
    else return $wanted; // TEXT, JSON… ou type illisible
    // ["<60>","<60>",…] : 2 crochets, 62 caractères par code, une virgule entre deux codes
    $fit = intdiv($max - 1, 63);
    return max(1, min($wanted, $fit));
}

/**
 * Réinitialise la double authentification d'un compte (téléphone perdu sans code de secours) :
 * à la prochaine connexion, le mot de passe suffit, et l'utilisateur peut la réactiver.
 * Réservé à l'administrateur de l'association du compte ($org_id : limite la requête à cette
 * association) ou au Fondateur ($org_id null). L'appelant journalise l'action.
 * @return bool vrai si un compte avec la 2FA active a été réinitialisé
 */
function ak_totp_reset(PDO $pdo, int $user_id, ?int $org_id = null): bool {
    $sql = "UPDATE users SET totp_enabled = 0, totp_secret = NULL, totp_backup_codes = NULL WHERE id = ? AND totp_enabled = 1";
    $params = [$user_id];
    if ($org_id !== null) { $sql .= " AND org_id = ?"; $params[] = $org_id; }
    $st = $pdo->prepare($sql);
    $st->execute($params);
    return $st->rowCount() > 0;
}
