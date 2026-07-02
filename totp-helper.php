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
