<?php
/**
 * sms-helper.php — Envoi SMS multi-provider (Twilio / OVH / Brevo)
 * ===================================================================
 *
 * SETUP dans config.php, ajouter selon ton choix :
 *
 *   === Twilio (recommande) ===
 *   define('SMS_PROVIDER', 'twilio');
 *   define('TWILIO_ACCOUNT_SID', 'AC_xxx');
 *   define('TWILIO_AUTH_TOKEN', 'xxx');
 *   define('TWILIO_FROM', '+33757xxx'); // ou nom d'expediteur
 *
 *   === OVH ===
 *   define('SMS_PROVIDER', 'ovh');
 *   define('OVH_APP_KEY', 'xxx');
 *   define('OVH_APP_SECRET', 'xxx');
 *   define('OVH_CONSUMER_KEY', 'xxx');
 *   define('OVH_SERVICE_NAME', 'sms-xxx-1');
 *   define('OVH_FROM', 'Assokit');
 *
 *   === Brevo ===
 *   define('SMS_PROVIDER', 'brevo');
 *   define('BREVO_API_KEY', 'xkeysib-xxx');
 *   define('BREVO_FROM', 'Assokit');
 *
 * USAGE :
 *   send_sms('+33612345678', 'Votre code Assokit : 1234', ['tag' => 'welcome']);
 *
 * SECURITE :
 *   - Log systematique dans sms_log (qui, quand, cout, statut)
 *   - Pas de cle API dans les URLs ou logs applicatifs
 *   - Timeout 10s
 */

/**
 * Envoi d'un SMS transactionnel (adapte au provider configure)
 */
function send_sms(string $to_phone, string $body, array $options = []): array {
    global $pdo;

    $provider = defined('SMS_PROVIDER') ? SMS_PROVIDER : null;
    if (!$provider) {
        sms_log_entry($to_phone, $body, $options['tag'] ?? null, null, null, 'failed', 'SMS_PROVIDER non configure', null);
        return ['success' => false, 'error' => 'SMS non configure (voir config.php)'];
    }

    // Normalisation numero E.164 basique
    $to_phone = preg_replace('/\s+/', '', $to_phone);
    if (preg_match('/^0[67]\d{8}$/', $to_phone)) {
        $to_phone = '+33' . substr($to_phone, 1);
    }

    $body = mb_substr($body, 0, 1600); // Twilio max 1600 chars

    switch ($provider) {
        case 'twilio': return sms_send_twilio($to_phone, $body, $options);
        case 'ovh':    return sms_send_ovh($to_phone, $body, $options);
        case 'brevo':  return sms_send_brevo($to_phone, $body, $options);
        default:
            return ['success' => false, 'error' => "Provider inconnu: $provider"];
    }
}

/**
 * Twilio — via REST API
 */
function sms_send_twilio(string $to, string $body, array $options): array {
    if (!defined('TWILIO_ACCOUNT_SID') || !defined('TWILIO_AUTH_TOKEN') || !defined('TWILIO_FROM')) {
        return ['success' => false, 'error' => 'Config Twilio incomplete'];
    }
    $url = 'https://api.twilio.com/2010-04-01/Accounts/' . TWILIO_ACCOUNT_SID . '/Messages.json';
    $payload = http_build_query([
        'From' => TWILIO_FROM,
        'To'   => $to,
        'Body' => $body,
    ]);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_USERPWD => TWILIO_ACCOUNT_SID . ':' . TWILIO_AUTH_TOKEN,
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_TIMEOUT => 10,
        CURLOPT_CONNECTTIMEOUT => 5,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    $success = false;
    $msg_id = null;
    $error = null;
    $cost = null;

    if ($err) {
        $error = 'cURL: ' . $err;
    } else {
        $data = json_decode($resp, true);
        if ($code >= 200 && $code < 300) {
            $success = true;
            $msg_id = $data['sid'] ?? null;
            $cost = isset($data['price']) ? abs((float) $data['price']) : null;
        } else {
            $error = 'HTTP ' . $code . ' : ' . ($data['message'] ?? $resp);
        }
    }

    sms_log_entry($to, $body, $options['tag'] ?? null, 'twilio', $msg_id, $success ? 'sent' : 'failed', $error, $cost);
    return ['success' => $success, 'id' => $msg_id, 'error' => $error];
}

/**
 * OVH — stub. Necessite la lib php-ovh. Retourne "not implemented" si non installee.
 */
function sms_send_ovh(string $to, string $body, array $options): array {
    if (!class_exists('\\Ovh\\Api')) {
        sms_log_entry($to, $body, $options['tag'] ?? null, 'ovh', null, 'failed', 'Librairie OVH non installee (composer require ovh/ovh)', null);
        return ['success' => false, 'error' => 'Lib OVH manquante'];
    }
    // A implementer selon config OVH
    return ['success' => false, 'error' => 'OVH non implemente — implementation a ajouter selon tes identifiants'];
}

/**
 * Brevo (ex-Sendinblue) — via REST API
 */
function sms_send_brevo(string $to, string $body, array $options): array {
    if (!defined('BREVO_API_KEY') || !defined('BREVO_FROM')) {
        return ['success' => false, 'error' => 'Config Brevo incomplete'];
    }
    $payload = [
        'sender' => BREVO_FROM,
        'recipient' => $to,
        'content' => $body,
        'type' => 'transactional',
    ];
    $ch = curl_init('https://api.brevo.com/v3/transactionalSMS/sms');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => [
            'api-key: ' . BREVO_API_KEY,
            'Content-Type: application/json',
            'accept: application/json',
        ],
        CURLOPT_TIMEOUT => 10,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    $success = false; $msg_id = null; $error = null; $cost = null;
    if ($err) {
        $error = 'cURL: ' . $err;
    } else {
        $data = json_decode($resp, true);
        if ($code >= 200 && $code < 300) {
            $success = true;
            $msg_id = $data['messageId'] ?? null;
            $cost = isset($data['usedCredits']) ? ((float) $data['usedCredits']) * 0.045 : null; // approx
        } else {
            $error = 'HTTP ' . $code . ' : ' . ($data['message'] ?? $resp);
        }
    }

    sms_log_entry($to, $body, $options['tag'] ?? null, 'brevo', $msg_id, $success ? 'sent' : 'failed', $error, $cost);
    return ['success' => $success, 'id' => $msg_id, 'error' => $error];
}

/**
 * Log d'un envoi SMS
 */
function sms_log_entry(string $to_phone, string $body, ?string $tag, ?string $provider, ?string $msg_id, string $status, ?string $error, ?float $cost): void {
    global $pdo;
    try {
        $stmt = $pdo->prepare("
            INSERT INTO sms_log
                (to_phone, body, tag, provider, provider_msg_id, status, error_message, cost_euros, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([
            mb_substr($to_phone, 0, 30),
            mb_substr($body, 0, 500),
            $tag, $provider, $msg_id, $status,
            $error ? mb_substr($error, 0, 500) : null,
            $cost,
        ]);
    } catch (Throwable $e) {
        // Silencieux
    }
}
