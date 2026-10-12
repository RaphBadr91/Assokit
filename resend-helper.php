<?php
/**
 * resend-helper.php — Envoi d'emails transactionnels via Resend
 * ================================================================
 *
 * SETUP :
 *   1. Dans ton config.php, ajoute 2 constantes :
 *        define('RESEND_API_KEY', 're_xxxxxxxxxxxx');  // ta clé (NOUVELLE, après révocation)
 *        define('RESEND_FROM', 'noreply@assokit.fr');
 *        define('RESEND_FROM_NAME', 'Assokit');
 *
 *   2. Ce fichier doit etre inclus via require_once dans les pages qui envoient.
 *
 * USAGE :
 *   send_transactional_email(
 *       'hakim@example.com',
 *       'Bienvenue sur Assokit !',
 *       render_email_welcome(['name' => 'Hakim', 'temp_password' => 'abc123'])
 *   );
 *
 * TEMPLATES disponibles :
 *   - render_email_welcome(...)          : Bienvenue nouveau user
 *   - render_email_password_reset(...)   : Mdp reset
 *   - render_email_invoice_sent(...)     : Envoi facture
 *   - render_email_invoice_paid(...)     : Confirmation paiement
 *   - render_email_org_suspended(...)    : Notification suspension asso
 *
 * SECURITE :
 *   - Les emails sont logges dans la table email_log (qui, quand, succes/echec)
 *   - La cle API n'apparait jamais dans les URLs ni dans les logs
 *   - Timeout de 10s pour ne pas bloquer la page si Resend est down
 */

if (!defined('RESEND_API_KEY')) {
    // Securite : si pas configure, ne jamais laisser un appel passer
    // Tu dois definir RESEND_API_KEY dans config.php
}

if (!function_exists('ak_resend_pace')) {
    /**
     * Espace les appels à l'API Resend d'un même processus : 2 requêtes par seconde
     * au plus par défaut (limite standard d'un compte Resend). Au-delà, Resend répond
     * 429 et l'e-mail n'est pas envoyé. AK_RESEND_MIN_INTERVAL_MS (config.php) pour
     * l'ajuster si le compte a une limite plus haute.
     */
    function ak_resend_pace(): void {
        static $last = 0.0;
        $min = (defined('AK_RESEND_MIN_INTERVAL_MS') ? max(0, (int) AK_RESEND_MIN_INTERVAL_MS) : 550) / 1000;
        $wait = $last + $min - microtime(true);
        if ($wait > 0) usleep((int) ($wait * 1000000));
        $last = microtime(true);
    }
}

/**
 * Envoie un email transactionnel via Resend.
 *
 * @param string|array $to       Email destinataire (ou array de)
 * @param string       $subject  Sujet
 * @param string       $html     Corps HTML
 * @param array        $options  ['cc' => ..., 'bcc' => ..., 'reply_to' => ..., 'tag' => 'welcome',
 *                                'from_name' => ..., 'headers' => [...], 'pace' => true (envois en série)]
 *
 * @return array  ['success' => bool, 'id' => string|null, 'error' => string|null]
 */
// Garde function_exists, comme les autres helpers du projet : plusieurs
// fichiers chargent celui-ci en @require_once puis testent function_exists,
// preuve qu'ils s'attendent à ce qu'un double chargement soit sans effet.
// Sans le garde, c'était une erreur fatale.
if (!function_exists('send_transactional_email')) {
function send_transactional_email($to, string $subject, string $html, array $options = []): array {
    global $pdo;

    // Espace de démonstration : envoi simulé (voir demo-guard.php)
    require_once __DIR__ . '/demo-guard.php';
    if (ak_demo_mail_blocked($to)) {
        return ['success' => true, 'id' => 'demo-simule', 'error' => null];
    }

    // Config check
    if (!defined('RESEND_API_KEY') || !RESEND_API_KEY || strpos(RESEND_API_KEY, 're_') !== 0) {
        error_log('[Resend] API key manquante ou invalide');
        return ['success' => false, 'id' => null, 'error' => 'Config Resend manquante'];
    }

    $from_email = !empty($options['from_email']) ? $options['from_email'] : (defined('RESEND_FROM') ? RESEND_FROM : 'noreply@assokit.fr');
    // from_name surchargeable (ex: nom de la mairie). L'email reste sur un domaine vérifié.
    $from_name = !empty($options['from_name']) ? $options['from_name'] : (defined('RESEND_FROM_NAME') ? RESEND_FROM_NAME : 'Assokit');
    // Nettoyer le from_name (retirer les caractères qui cassent l'en-tête)
    $from_name = trim(preg_replace('/[<>\r\n"]/', '', $from_name));
    if ($from_name === '') $from_name = 'Assokit';
    $from_name = mb_substr($from_name, 0, 80);
    // Nom d'association avec virgule, point, parenthèses… : entre guillemets, sinon
    // « Club, Sport & Loisirs <noreply@…> » se lit comme deux adresses.
    if (preg_match('/[(),.:;@\[\]\\\\]/', $from_name)) $from_name = '"' . str_replace('\\', '', $from_name) . '"';

    // Payload Resend
    $payload = [
        'from' => $from_name . ' <' . $from_email . '>',
        'to' => is_array($to) ? $to : [$to],
        'subject' => $subject,
        'html' => $html,
    ];

    if (!empty($options['cc'])) {
        $payload['cc'] = is_array($options['cc']) ? $options['cc'] : [$options['cc']];
    }
    if (!empty($options['bcc'])) {
        $payload['bcc'] = is_array($options['bcc']) ? $options['bcc'] : [$options['bcc']];
    }
    if (!empty($options['reply_to'])) {
        $payload['reply_to'] = $options['reply_to'];
    }
    if (!empty($options['tag'])) {
        $payload['tags'] = [['name' => 'category', 'value' => $options['tag']]];
    }
    // En-têtes supplémentaires (ex. List-Unsubscribe des envois groupés, voir ak_optout_headers)
    if (!empty($options['headers']) && is_array($options['headers'])) {
        $headers = [];
        foreach ($options['headers'] as $name => $value) {
            $name = preg_replace('/[^A-Za-z0-9-]/', '', (string) $name);
            if ($name === '') continue;
            $headers[$name] = preg_replace('/[\r\n]/', '', (string) $value);
        }
        if ($headers) $payload['headers'] = $headers;
    }
    // Pièces jointes : Resend les attend en base64, sous la forme
    // [['filename' => 'x.pdf', 'content' => '<base64>'], …].
    // L'appelant fournit déjà le contenu encodé — c'est lui qui sait où
    // vit le fichier, et ce helper n'a pas à lire le disque.
    if (!empty($options['attachments']) && is_array($options['attachments'])) {
        $pieces = [];
        foreach ($options['attachments'] as $a) {
            if (empty($a['filename']) || empty($a['content'])) continue;
            $pieces[] = [
                // Les retours à la ligne dans un nom de fichier cassent
                // l'en-tête MIME côté destinataire.
                'filename' => preg_replace('/[\r\n"]/', '', (string) $a['filename']),
                'content'  => (string) $a['content'],
            ];
        }
        if ($pieces) $payload['attachments'] = $pieces;
    }

    // Appel API Resend
    $api_url = defined('RESEND_API_URL') && RESEND_API_URL ? RESEND_API_URL : 'https://api.resend.com/emails';
    $attempt = 0;
    do {
        // Envois en série (diffusions) : on reste sous le débit autorisé par Resend
        if (!empty($options['pace'])) ak_resend_pace();
        $retry_after = 0;
        $ch = curl_init($api_url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . RESEND_API_KEY,
                'Content-Type: application/json',
            ],
            CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$retry_after) {
                if (stripos($line, 'retry-after:') === 0) $retry_after = (int) trim(substr($line, 12));
                return strlen($line);
            },
            // Dix secondes suffisent à un message transactionnel. Avec une
            // pièce jointe, il faut d'abord téléverser plusieurs mégaoctets
            // de base64 : au même délai, l'envoi échouerait sur les gros
            // fichiers alors que tout va bien.
            CURLOPT_TIMEOUT => empty($payload['attachments']) ? 10 : 60,
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($ch);
        curl_close($ch);

        // 429 = trop de requêtes : rien n'est parti. On attend ce que demande Resend
        // (entre 0,5 et 2 s) et on réessaie, deux fois au plus, plutôt que de compter un échec.
        $again = !$curl_error && (int) $http_code === 429 && $attempt < 2;
        if ($again) usleep(min(2000, max(500, $retry_after * 1000)) * 1000);
        $attempt++;
    } while ($again);

    $result_id = null;
    $error_msg = null;
    $success = false;

    if ($curl_error) {
        $error_msg = 'cURL: ' . $curl_error;
    } elseif ($http_code >= 200 && $http_code < 300) {
        $decoded = json_decode($response, true);
        $result_id = $decoded['id'] ?? null;
        $success = true;
    } else {
        $decoded = json_decode($response, true);
        $error_msg = 'HTTP ' . $http_code . ' : ' . ($decoded['message'] ?? $response);
    }

    // Log dans la table email_log (silencieux si table inexistante)
    try {
        $to_str = is_array($to) ? implode(', ', $to) : $to;
        $stmt = $pdo->prepare("
            INSERT INTO email_log
                (to_email, from_email, subject, tag, status, resend_id, error_message, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([
            mb_substr($to_str, 0, 255),
            $from_email,
            mb_substr($subject, 0, 255),
            $options['tag'] ?? null,
            $success ? 'sent' : 'failed',
            $result_id,
            $error_msg ? mb_substr($error_msg, 0, 500) : null,
        ]);
    } catch (Throwable $e) {
        // Table pas encore creee, on ignore
    }

    return [
        'success' => $success,
        'id' => $result_id,
        'error' => $error_msg,
    ];
}
}   // fin du garde function_exists

// ====================================================================
// DÉSINSCRIPTION DES ENVOIS GROUPÉS D'UNE ASSOCIATION
// ====================================================================
// Diffusions, invitations aux événements et diffusions IA envoyées par une association
// à ses adhérents (ou à des adresses saisies à la main) : chaque e-mail porte un lien
// de désinscription qui marche sans connexion (/desinscription?l=…, signé HMAC) et les
// en-têtes List-Unsubscribe. Une ligne par (association, adresse) dans email_optouts ;
// les envois suivants de cette association l'excluent. Les e-mails transactionnels
// (factures, mots de passe, mentions, messages de canal…) ne sont pas concernés.

if (!function_exists('ak_optout_secret')) {
    function ak_optout_secret(): string {
        if (defined('AK_CONTACT_SECRET') && AK_CONTACT_SECRET) return hash('sha256', 'akunsub|' . AK_CONTACT_SECRET);
        if (defined('RESEND_API_KEY') && RESEND_API_KEY) return hash('sha256', 'akunsub|' . RESEND_API_KEY);
        // Fail-closed : sans secret configuré, aucun lien n'est produit ni accepté
        // (et sans clé Resend, aucun e-mail ne part de toute façon).
        return '';
    }

    function ak_optout_b64(string $s): string {
        return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
    }

    /** Jeton « org.email.signature » du lien de désinscription ('' si aucun secret). */
    function ak_optout_token(int $org_id, string $email): string {
        $secret = ak_optout_secret();
        $email = strtolower(trim($email));
        if ($secret === '' || $org_id <= 0 || $email === '') return '';
        $sig = substr(hash_hmac('sha256', 'unsub|' . $org_id . '|' . $email, $secret), 0, 24);
        return $org_id . '.' . ak_optout_b64($email) . '.' . $sig;
    }

    function ak_optout_url(int $org_id, string $email): ?string {
        $t = ak_optout_token($org_id, $email);
        return $t === '' ? null : 'https://assokit.fr/desinscription?l=' . $t;
    }

    /** Vérifie un jeton : ['org_id' => int, 'email' => string] ou null. */
    function ak_optout_parse(string $token): ?array {
        if (!preg_match('/^(\d+)\.([A-Za-z0-9_-]{4,400})\.([a-f0-9]{24})$/', trim($token), $m)) return null;
        $email = base64_decode(strtr($m[2], '-_', '+/'), true);
        if ($email === false || !filter_var($email, FILTER_VALIDATE_EMAIL)) return null;
        $expected = ak_optout_token((int)$m[1], $email);
        if ($expected === '' || !hash_equals($expected, trim($token))) return null;
        return ['org_id' => (int)$m[1], 'email' => strtolower(trim($email))];
    }

    /** En-têtes List-Unsubscribe (désinscription en un clic depuis Gmail, Yahoo, Outlook…). */
    function ak_optout_headers(int $org_id, string $email): array {
        $url = ak_optout_url($org_id, $email);
        if (!$url) return [];
        return ['List-Unsubscribe' => '<' . $url . '>', 'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click'];
    }

    /** Mention de pied d'e-mail avec le lien de désinscription. */
    function ak_optout_footer_html(int $org_id, string $email, string $org_name): string {
        $url = ak_optout_url($org_id, $email);
        if (!$url) return '';
        return 'Vous recevez cet e-mail en tant que contact de <strong>' . htmlspecialchars($org_name) . '</strong>.<br>'
             . '<a href="' . htmlspecialchars($url) . '" style="color:#78716C;text-decoration:underline">Ne plus recevoir les envois groupés de cette association</a>';
    }

    function ak_optout_table_ensure(PDO $pdo): bool {
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS email_optouts (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                org_id INT UNSIGNED NOT NULL,
                email VARCHAR(190) NOT NULL,
                source VARCHAR(40) NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_optout_org_email (org_id, email),
                KEY idx_optout_email (email)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            return true;
        } catch (Throwable $e) {
            error_log('[ak_optout] table : ' . $e->getMessage());
            return false;
        }
    }

    /** Adresses désinscrites des envois groupés d'une association : [email => true]. */
    function ak_optout_emails(PDO $pdo, int $org_id): array {
        try {
            $st = $pdo->prepare("SELECT email FROM email_optouts WHERE org_id = ?");
            $st->execute([$org_id]);
            $out = [];
            foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $e) $out[strtolower(trim((string)$e))] = true;
            return $out;
        } catch (Throwable $e) {
            return []; // table pas encore créée : personne ne s'est désinscrit
        }
    }

    /** Désinscrit (ou réinscrit) une adresse des envois groupés d'une association. */
    function ak_optout_set(PDO $pdo, int $org_id, string $email, bool $optout, string $source = 'link'): bool {
        $email = strtolower(trim($email));
        if ($org_id <= 0 || $email === '' || !ak_optout_table_ensure($pdo)) return false;
        try {
            if ($optout) {
                $pdo->prepare("INSERT IGNORE INTO email_optouts (org_id, email, source, created_at) VALUES (?, ?, ?, NOW())")
                    ->execute([$org_id, mb_substr($email, 0, 190), mb_substr($source, 0, 40)]);
            } else {
                $pdo->prepare("DELETE FROM email_optouts WHERE org_id = ? AND email = ?")->execute([$org_id, $email]);
            }
            return true;
        } catch (Throwable $e) {
            error_log('[ak_optout] ' . $e->getMessage());
            return false;
        }
    }
}

// ====================================================================
// DIFFUSIONS D'UNE ASSOCIATION (communication_broadcasts)
// ====================================================================
// Diffusion classique (/communication-diffuser-nouveau) et invitation à un événement
// (/communication-evenement) passent par ak_comm_broadcast_send() :
//   - l'e-mail part au nom de l'association (adresse Assokit vérifiée) et les réponses
//     arrivent à l'association (Reply-To), pas à noreply@ ;
//   - aucun envoi dans une transaction : la diffusion et ses destinataires (« pending »)
//     sont enregistrés d'abord, puis chaque destinataire est noté dès son envoi. Une requête
//     coupée laisse une trace exacte, et « Reprendre l'envoi » (fiche de la diffusion)
//     n'écrit qu'aux destinataires restants ;
//   - débit tenu (ak_resend_pace), nouvel essai sur 429 ;
//   - verrou GET_LOCK : jamais deux envois simultanés de la même diffusion.

if (!function_exists('ak_comm_broadcast_send')) {
    /** Nom d'expéditeur (l'association) et adresse de réponse de ses envois groupés. */
    function ak_comm_sender_options(PDO $pdo, int $org_id, int $author_id = 0): array {
        $opts = [];
        $reply = '';
        try {
            $st = $pdo->prepare("SELECT * FROM organizations WHERE id = ?");
            $st->execute([$org_id]);
            $org = $st->fetch(PDO::FETCH_ASSOC) ?: [];
            $name = trim((string)($org['name'] ?? ''));
            if ($name !== '') $opts['from_name'] = $name;
            $reply = trim((string)($org['billing_email'] ?? ''));
        } catch (Throwable $e) {}
        // Sans e-mail de contact de l'association : les réponses vont à l'auteur de l'envoi
        if (!filter_var($reply, FILTER_VALIDATE_EMAIL) && $author_id > 0) {
            try {
                $st = $pdo->prepare("SELECT email FROM users WHERE id = ? AND org_id = ?");
                $st->execute([$author_id, $org_id]);
                $reply = trim((string)$st->fetchColumn());
            } catch (Throwable $e) {}
        }
        if (filter_var($reply, FILTER_VALIDATE_EMAIL)) $opts['reply_to'] = $reply;
        return $opts;
    }

    function ak_comm_broadcast_lock_name(PDO $pdo, int $broadcast_id): string {
        try { $db = (string)$pdo->query('SELECT DATABASE()')->fetchColumn(); } catch (Throwable $e) { $db = ''; }
        return mb_substr($db . ':broadcast:' . $broadcast_id, 0, 64);
    }

    /** Un envoi de cette diffusion tourne-t-il en ce moment (autre onglet, autre appareil) ? */
    function ak_comm_broadcast_running(PDO $pdo, int $broadcast_id): bool {
        try {
            $st = $pdo->prepare('SELECT IS_FREE_LOCK(?)');
            $st->execute([ak_comm_broadcast_lock_name($pdo, $broadcast_id)]);
            $v = $st->fetchColumn();
            return $v !== null && $v !== false && (int)$v === 0;
        } catch (Throwable $e) {
            return false;
        }
    }

    /** Corps HTML d'une diffusion (ou d'une invitation si $event) pour un destinataire. */
    function ak_comm_broadcast_html(array $b, ?array $event, string $first_name, string $org_name, int $org_id, string $email, bool $can_reply): string {
        $e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
        $hello = '<p>Bonjour' . (trim($first_name) !== '' ? ' ' . $e(trim($first_name)) : '') . ',</p>';
        $cta_label = null;
        $cta_url = null;
        if ($event) {
            $desc = (string)($event['description'] ?? '');
            $content = '<h1 style="font-size:22px;margin:0 0 10px;font-weight:500;letter-spacing:-0.01em;color:#1C1917">🎪 ' . $e($event['title']) . '</h1>'
                     . $hello
                     . '<p>Nous vous invitons à notre événement :</p>'
                     . '<table role="presentation" cellspacing="0" cellpadding="0" border="0" style="background:#FAFAF9;border:1px solid #E7E5E4;border-radius:10px;padding:16px;margin:14px 0;width:100%;font-size:13.5px">'
                     . '<tr><td style="padding:4px 8px;color:#78716C;width:30%">📅 Date :</td><td style="padding:4px 8px"><strong>' . date('d/m/Y à H:i', strtotime((string)$event['start_date'])) . '</strong></td></tr>'
                     . (!empty($event['location']) ? '<tr><td style="padding:4px 8px;color:#78716C">📍 Lieu :</td><td style="padding:4px 8px">' . $e($event['location']) . '</td></tr>' : '')
                     . '</table>'
                     . ($desc !== '' ? '<div style="background:#D1FAE5;border-left:3px solid #059669;padding:12px 14px;border-radius:6px;margin:14px 0;font-size:13.5px;color:#065F46;line-height:1.6;">'
                         . (function_exists('ak_render_rich_text') ? ak_render_rich_text($desc) : nl2br($e($desc))) . '</div>' : '');
            // Lien de réponse seulement si la page publique existe (evenement-public.php :
            // is_public = 1 et status = 'published') ; sinon on répond à l'association par e-mail.
            $is_public = !empty($event['is_public']) && !empty($event['public_slug']) && ($event['status'] ?? 'published') === 'published';
            if ($is_public) {
                $cta_label = !empty($event['rsvp_enabled']) ? 'Confirmer ma venue' : "Voir l'événement";
                $cta_url = 'https://assokit.fr/evenement-public/' . $event['public_slug'];
            } elseif (!empty($event['rsvp_enabled']) && $can_reply) {
                $content .= '<p>Pour nous dire si vous venez, répondez simplement à cet e-mail.</p>';
            }
            $content .= '<p style="font-size:12.5px; color:#78716C;">— L\'équipe de ' . $e($org_name) . '</p>';
        } else {
            $content = '<h1 style="font-size:20px;margin:0 0 10px;font-weight:500;letter-spacing:-0.01em;color:#1C1917">' . $e($b['subject']) . '</h1>'
                     . $hello
                     . '<div style="font-size:14px; line-height:1.6; color:#3F3F46; margin:14px 0;">' . nl2br($e($b['body'])) . '</div>'
                     . '<p style="font-size:12px; color:#78716C; margin-top:18px;">— L\'équipe de ' . $e($org_name) . '</p>';
        }
        // Lien de désinscription en pied (les en-têtes List-Unsubscribe partent avec l'envoi)
        return email_wrap((string)$b['subject'], $content, $cta_label, $cta_url, ak_optout_footer_html($org_id, $email, $org_name));
    }

    /**
     * Envoie une diffusion de communication_broadcasts et met son bilan à jour.
     * $users : destinataires d'un nouvel envoi [['id', 'email', 'first_name'], …] ;
     *          vide = reprise des destinataires restés « pending ».
     * @return array{sent:int, failed:int, pending:int, total:int, status:string, locked:bool}
     */
    function ak_comm_broadcast_send(PDO $pdo, int $org_id, int $broadcast_id, array $users = []): array {
        $out = ['sent' => 0, 'failed' => 0, 'pending' => 0, 'total' => 0, 'status' => 'missing', 'locked' => false];
        if (!function_exists('ak_db_accepts')) require_once __DIR__ . '/trial-helpers.php';

        $st = $pdo->prepare("SELECT * FROM communication_broadcasts WHERE id = ? AND org_id = ?");
        $st->execute([$broadcast_id, $org_id]);
        $b = $st->fetch(PDO::FETCH_ASSOC);
        if (!$b) return $out;

        // Un seul envoi à la fois pour cette diffusion (double clic, reprise lancée deux fois).
        // Le verrou tombe tout seul si le processus est tué.
        $lock = ak_comm_broadcast_lock_name($pdo, $broadcast_id);
        try {
            $q = $pdo->prepare('SELECT GET_LOCK(?, 0)');
            $q->execute([$lock]);
            if ((int)$q->fetchColumn() !== 1) return ['locked' => true, 'status' => (string)$b['status']] + $out;
        } catch (Throwable $e) {
            $lock = null;
        }

        try {
            $sender = ak_comm_sender_options($pdo, $org_id, (int)($b['created_by_user_id'] ?? 0));
            $org_name = $sender['from_name'] ?? 'votre association';

            // Invitation à un événement : l'événement porte l'id de la diffusion
            $event = null;
            try {
                $q = $pdo->prepare("SELECT * FROM communication_events WHERE org_id = ? AND broadcast_id = ? LIMIT 1");
                $q->execute([$org_id, $broadcast_id]);
                $event = $q->fetch(PDO::FETCH_ASSOC) ?: null;
            } catch (Throwable $e) {}

            // Destinataires notés « pending » avant le premier envoi (si la colonne admet ce statut)
            $pending_mode = ak_db_accepts($pdo, 'communication_broadcast_recipients', 'status', 'pending');
            if ($users && $pending_mode) {
                $pdo->beginTransaction();
                try {
                    $ins = $pdo->prepare("INSERT INTO communication_broadcast_recipients (broadcast_id, user_id, email, status) VALUES (?, ?, ?, 'pending')");
                    foreach ($users as $u) $ins->execute([$broadcast_id, (int)$u['id'], (string)$u['email']]);
                    $pdo->commit();
                    $users = [];
                } catch (Throwable $e) {
                    $pdo->rollBack();
                    error_log('[ak_comm_broadcast_send] destinataires : ' . $e->getMessage());
                    $pending_mode = false;
                }
            }
            $todo = [];
            if ($users) {
                foreach ($users as $u) {
                    $todo[] = ['id' => 0, 'user_id' => (int)$u['id'], 'email' => (string)$u['email'], 'first_name' => (string)($u['first_name'] ?? '')];
                }
            } elseif ($pending_mode) {
                $q = $pdo->prepare("
                    SELECT r.id, r.user_id, r.email, u.first_name
                    FROM communication_broadcast_recipients r
                    LEFT JOIN users u ON u.id = r.user_id AND u.org_id = ?
                    WHERE r.broadcast_id = ? AND r.status = 'pending'
                    ORDER BY r.id
                ");
                $q->execute([$org_id, $broadcast_id]);
                $todo = $q->fetchAll(PDO::FETCH_ASSOC);
            }

            // L'envoi va au bout même si l'onglet est fermé
            ignore_user_abort(true);
            @set_time_limit(0);

            $optouts = ak_optout_emails($pdo, $org_id);   // désinscrit depuis le début de l'envoi
            $tag = $event ? 'event_invite' : 'broadcast';
            $sent = $failed = 0;
            foreach ($todo as $r) {
                $email = trim((string)$r['email']);
                if (isset($optouts[strtolower($email)])) {
                    if (!empty($r['id'])) {
                        try { $pdo->prepare("DELETE FROM communication_broadcast_recipients WHERE id = ? AND status = 'pending'")->execute([(int)$r['id']]); } catch (Throwable $e) {}
                    }
                    continue;
                }
                try {
                    $html = ak_comm_broadcast_html($b, $event, (string)($r['first_name'] ?? ''), $org_name, $org_id, $email, !empty($sender['reply_to']));
                    $res = send_transactional_email($email, (string)$b['subject'], $html,
                        $sender + ['tag' => $tag, 'headers' => ak_optout_headers($org_id, $email), 'pace' => true]);
                } catch (Throwable $e) {
                    $res = ['success' => false, 'error' => $e->getMessage()];
                }
                $ok = !empty($res['success']);
                $ok ? $sent++ : $failed++;
                $err = $ok ? null : mb_substr((string)($res['error'] ?? 'Erreur inconnue'), 0, 255);
                $at = $ok ? date('Y-m-d H:i:s') : null;
                try {
                    if (!empty($r['id'])) {
                        $pdo->prepare("UPDATE communication_broadcast_recipients SET status = ?, error_message = ?, sent_at = ? WHERE id = ?")
                            ->execute([$ok ? 'sent' : 'failed', $err, $at, (int)$r['id']]);
                    } else {
                        $pdo->prepare("INSERT INTO communication_broadcast_recipients (broadcast_id, user_id, email, status, error_message, sent_at) VALUES (?, ?, ?, ?, ?, ?)")
                            ->execute([$broadcast_id, (int)$r['user_id'], $email, $ok ? 'sent' : 'failed', $err, $at]);
                    }
                } catch (Throwable $e) {
                    error_log('[ak_comm_broadcast_send] suivi du destinataire : ' . $e->getMessage());
                }
            }

            // Bilan lu en base : une reprise compte aussi les envois précédents
            $counts = [];
            try {
                $q = $pdo->prepare("SELECT status, COUNT(*) FROM communication_broadcast_recipients WHERE broadcast_id = ? GROUP BY status");
                $q->execute([$broadcast_id]);
                $counts = $q->fetchAll(PDO::FETCH_KEY_PAIR);
            } catch (Throwable $e) {}
            if ($counts) {
                $out['sent'] = (int)($counts['sent'] ?? 0);
                $out['pending'] = (int)($counts['pending'] ?? 0);
                $out['total'] = (int)array_sum(array_map('intval', $counts));
                $out['failed'] = max(0, $out['total'] - $out['sent'] - $out['pending']);
            } else {
                $out['sent'] = $sent;
                $out['failed'] = $failed;
                $out['total'] = $sent + $failed;
            }
            if ($out['pending'] > 0) {
                $status = 'sending';
            } elseif ($out['sent'] === 0) {
                $status = 'failed';
            } elseif ($out['failed'] > 0) {
                $status = ak_db_pick($pdo, 'communication_broadcasts', 'status', ['partial', 'sent']) ?? 'sent';
            } else {
                $status = 'sent';
            }
            try {
                $pdo->prepare("UPDATE communication_broadcasts SET nb_total = ?, nb_sent = ?, nb_failed = ?, status = ? WHERE id = ? AND org_id = ?")
                    ->execute([$out['total'], $out['sent'], $out['failed'], $status, $broadcast_id, $org_id]);
            } catch (Throwable $e) {
                error_log('[ak_comm_broadcast_send] bilan : ' . $e->getMessage());
            }
            $out['status'] = $status;
            return $out;
        } finally {
            if ($lock) {
                try { $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$lock]); } catch (Throwable $e) {}
            }
        }
    }

    /**
     * Message à afficher après un envoi : ['type' => success|warning|error, 'message' => …].
     * $what : « Diffusion » ou « Invitation » (féminin).
     */
    function ak_comm_broadcast_flash(array $res, string $what = 'Diffusion'): array {
        $s = $res['sent'] ?? 0;
        $f = $res['failed'] ?? 0;
        $p = $res['pending'] ?? 0;
        if (!empty($res['locked'])) {
            return ['type' => 'warning', 'message' => 'Cet envoi est déjà en cours. Patientez quelques minutes, puis rechargez la page.'];
        }
        if ($p > 0) {
            return ['type' => 'warning', 'message' => "$what interrompue : $s e-mail(s) envoyé(s), $p restant(s). Reprenez l’envoi depuis la fiche de la diffusion."];
        }
        if ($s === 0) {
            return ['type' => 'error', 'message' => "$what non envoyée : aucun e-mail n’est parti ($f échec(s)). Le détail des erreurs est sur la fiche de la diffusion."];
        }
        if ($f > 0) {
            return ['type' => 'warning', 'message' => "$what envoyée en partie : $s e-mail(s) envoyé(s), $f échec(s)."];
        }
        return ['type' => 'success', 'message' => "$what envoyée : $s e-mail(s) envoyé(s)."];
    }
}

// ====================================================================
// TEMPLATE DE BASE (wrapper commun a tous les emails)
// ====================================================================
// $footer_html : mention ajoutée au pied (ex. lien de désinscription des envois groupés).
function email_wrap(string $title, string $content_html, ?string $cta_text = null, ?string $cta_url = null, string $footer_html = ''): string {
    $acc = '#059669';
    $bg = '#FAFAF9';
    $ink = '#1C1917';
    $ink2 = '#44403C';
    $ink3 = '#78716C';
    $border = '#E7E5E4';

    $cta_html = '';
    if ($cta_text && $cta_url) {
        $cta_html = '
        <table role="presentation" cellspacing="0" cellpadding="0" border="0" style="margin:28px 0">
          <tr><td style="border-radius:10px;background:' . $acc . '">
            <a href="' . htmlspecialchars($cta_url) . '" target="_blank"
               style="display:inline-block;padding:13px 28px;color:#fff;text-decoration:none;font-family:Arial,sans-serif;font-size:14px;font-weight:600;border-radius:10px">
              ' . htmlspecialchars($cta_text) . '
            </a>
          </td></tr>
        </table>';
    }

    return '<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>' . htmlspecialchars($title) . '</title>
</head>
<body style="margin:0;padding:0;background:' . $bg . ';font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Arial,sans-serif;color:' . $ink . ';line-height:1.55">
  <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background:' . $bg . ';padding:30px 16px">
    <tr><td align="center">
      <table role="presentation" width="560" cellspacing="0" cellpadding="0" border="0" style="max-width:560px;width:100%;background:#fff;border:1px solid ' . $border . ';border-radius:14px;overflow:hidden">
        <tr><td style="padding:28px 36px 8px">
          <div style="font-size:22px;font-weight:700;letter-spacing:-0.02em">
            asso<span style="color:' . $acc . '">kit</span>.
          </div>
          <div style="font-size:12px;color:' . $ink3 . ';margin-top:2px">L\'art de mener vos projets</div>
        </td></tr>
        <tr><td style="padding:16px 36px 30px;font-size:14.5px;color:' . $ink2 . '">
          ' . $content_html . '
          ' . $cta_html . '
        </td></tr>
        <tr><td style="padding:18px 36px;background:#FAFAF9;border-top:1px solid ' . $border . ';font-size:11.5px;color:' . $ink3 . ';text-align:center">
          Cet email vous a été envoyé par <strong style="color:' . $ink2 . '">Assokit</strong><br>
          <a href="https://assokit.fr" style="color:' . $acc . ';text-decoration:none">assokit.fr</a>'
          . ($footer_html !== '' ? '<div style="margin-top:10px">' . $footer_html . '</div>' : '') . '
        </td></tr>
      </table>
    </td></tr>
  </table>
</body>
</html>';
}

// ====================================================================
// TEMPLATES SPECIFIQUES
// ====================================================================

/**
 * Email de bienvenue avec mdp temporaire
 * @param array $data ['first_name', 'last_name', 'email', 'temp_password', 'org_name'?, 'is_super_admin'?]
 */
function render_email_welcome(array $data): string {
    $name = htmlspecialchars($data['first_name'] ?? '');
    $email = htmlspecialchars($data['email'] ?? '');
    $pwd = htmlspecialchars($data['temp_password'] ?? '');
    $is_sa = !empty($data['is_super_admin']);
    $org_name = $data['org_name'] ?? null;

    $intro = $is_sa
        ? '<strong>un compte super administrateur</strong> vient d\'être créé pour vous sur la plateforme Assokit.'
        : ($org_name
            ? 'votre compte sur <strong>' . htmlspecialchars($org_name) . '</strong> vient d\'être créé.'
            : 'votre compte Assokit vient d\'être créé.');

    $content = '
      <h1 style="font-size:20px;margin:0 0 10px;font-weight:500;letter-spacing:-0.01em;color:#1C1917">Bienvenue ' . $name . ' 👋</h1>
      <p>Bonjour ' . $name . ',</p>
      <p>' . $intro . '</p>
      <p style="margin-top:18px"><strong>Vos identifiants de connexion :</strong></p>
      <table role="presentation" cellspacing="0" cellpadding="0" border="0" style="background:#FAFAF9;border:1px solid #E7E5E4;border-radius:10px;padding:16px;margin:10px 0;width:100%;font-family:Menlo,Consolas,monospace;font-size:13.5px">
        <tr><td style="padding:4px 8px"><span style="color:#78716C">Email :</span> ' . $email . '</td></tr>
        <tr><td style="padding:4px 8px"><span style="color:#78716C">Mot de passe :</span> <strong style="background:#FEF3C7;color:#92400E;padding:2px 8px;border-radius:4px;letter-spacing:0.05em">' . $pwd . '</strong></td></tr>
      </table>
      <p style="font-size:13px;color:#78716C;margin-top:16px">
        🔐 À la première connexion, il vous sera demandé de changer ce mot de passe temporaire.
      </p>';

    return email_wrap('Bienvenue sur Assokit', $content, 'Se connecter maintenant', 'https://assokit.fr/connexion');
}

/**
 * Email de reset de mdp (nouveau mdp temporaire)
 * @param array $data ['first_name', 'email', 'temp_password']
 */
function render_email_password_reset(array $data): string {
    $name = htmlspecialchars($data['first_name'] ?? '');
    $email = htmlspecialchars($data['email'] ?? '');
    $pwd = htmlspecialchars($data['temp_password'] ?? '');

    $content = '
      <h1 style="font-size:20px;margin:0 0 10px;font-weight:500;letter-spacing:-0.01em;color:#1C1917">Mot de passe réinitialisé 🔑</h1>
      <p>Bonjour ' . $name . ',</p>
      <p>Votre mot de passe Assokit a été réinitialisé par un administrateur.</p>
      <table role="presentation" cellspacing="0" cellpadding="0" border="0" style="background:#FAFAF9;border:1px solid #E7E5E4;border-radius:10px;padding:16px;margin:10px 0;width:100%;font-family:Menlo,Consolas,monospace;font-size:13.5px">
        <tr><td style="padding:4px 8px"><span style="color:#78716C">Email :</span> ' . $email . '</td></tr>
        <tr><td style="padding:4px 8px"><span style="color:#78716C">Nouveau mot de passe :</span> <strong style="background:#FEF3C7;color:#92400E;padding:2px 8px;border-radius:4px;letter-spacing:0.05em">' . $pwd . '</strong></td></tr>
      </table>
      <p style="font-size:13px;color:#78716C;margin-top:16px">
        🔐 Ce mot de passe est temporaire. À la prochaine connexion, un nouveau vous sera demandé.
      </p>
      <p style="font-size:13px;color:#78716C">
        ❗ Si vous n\'êtes pas à l\'origine de ce reset, contactez immédiatement l\'administrateur.
      </p>';

    return email_wrap('Mot de passe réinitialisé', $content, 'Se connecter', 'https://assokit.fr/connexion');
}

/**
 * Email d'envoi de facture
 * @param array $data ['org_name', 'invoice_number', 'amount_ttc', 'period_start', 'period_end', 'due_date']
 */
function render_email_invoice_sent(array $data): string {
    $org_name = htmlspecialchars($data['org_name'] ?? '');
    $number = htmlspecialchars($data['invoice_number'] ?? '');
    $amount = number_format((float) ($data['amount_ttc'] ?? 0), 2, ',', ' ');
    $period = date('d/m/Y', strtotime($data['period_start'])) . ' → ' . date('d/m/Y', strtotime($data['period_end']));
    $due = $data['due_date'] ? date('d/m/Y', strtotime($data['due_date'])) : '—';

    $content = '
      <h1 style="font-size:20px;margin:0 0 10px;font-weight:500;letter-spacing:-0.01em;color:#1C1917">Nouvelle facture 📄</h1>
      <p>Bonjour,</p>
      <p>Voici la facture <strong>' . $number . '</strong> pour <strong>' . $org_name . '</strong>.</p>
      <table role="presentation" cellspacing="0" cellpadding="0" border="0" style="background:#FAFAF9;border:1px solid #E7E5E4;border-radius:10px;padding:16px;margin:14px 0;width:100%;font-size:14px">
        <tr><td style="padding:6px 8px;color:#78716C;width:40%">Numéro :</td><td style="padding:6px 8px"><strong>' . $number . '</strong></td></tr>
        <tr><td style="padding:6px 8px;color:#78716C">Période :</td><td style="padding:6px 8px">' . $period . '</td></tr>
        <tr><td style="padding:6px 8px;color:#78716C">Échéance :</td><td style="padding:6px 8px">' . $due . '</td></tr>
        <tr><td style="padding:6px 8px;color:#78716C">Montant TTC :</td><td style="padding:6px 8px"><strong style="color:#059669;font-size:16px">' . $amount . ' €</strong></td></tr>
      </table>
      <p style="font-size:13px;color:#78716C">
        💳 Règlement par virement bancaire. Vous recevrez une confirmation dès réception du paiement.
      </p>';

    return email_wrap('Nouvelle facture Assokit — ' . $number, $content);
}

/**
 * Email de confirmation de paiement recu
 * @param array $data ['org_name', 'invoice_number', 'amount_ttc', 'payment_date', 'payment_method']
 */
function render_email_invoice_paid(array $data): string {
    $org_name = htmlspecialchars($data['org_name'] ?? '');
    $number = htmlspecialchars($data['invoice_number'] ?? '');
    $amount = number_format((float) ($data['amount_ttc'] ?? 0), 2, ',', ' ');
    $date = date('d/m/Y', strtotime($data['payment_date']));
    $method = htmlspecialchars($data['payment_method'] ?? '');

    $content = '
      <h1 style="font-size:20px;margin:0 0 10px;font-weight:500;letter-spacing:-0.01em;color:#1C1917">Paiement reçu ✅</h1>
      <p>Bonjour,</p>
      <p>Nous confirmons la réception de votre règlement pour <strong>' . $org_name . '</strong>.</p>
      <table role="presentation" cellspacing="0" cellpadding="0" border="0" style="background:#ECFDF5;border:1px solid #6EE7B7;border-radius:10px;padding:16px;margin:14px 0;width:100%;font-size:14px">
        <tr><td style="padding:6px 8px;color:#78716C;width:40%">Facture :</td><td style="padding:6px 8px"><strong>' . $number . '</strong></td></tr>
        <tr><td style="padding:6px 8px;color:#78716C">Montant :</td><td style="padding:6px 8px"><strong>' . $amount . ' €</strong></td></tr>
        <tr><td style="padding:6px 8px;color:#78716C">Date :</td><td style="padding:6px 8px">' . $date . '</td></tr>
        <tr><td style="padding:6px 8px;color:#78716C">Méthode :</td><td style="padding:6px 8px">' . $method . '</td></tr>
      </table>
      <p>Merci pour votre confiance !</p>';

    return email_wrap('Paiement reçu — ' . $number, $content);
}

/**
 * Email de promotion en super admin (compte existant)
 * @param array $data ['first_name', 'email', 'promoted_by_name']
 */
function render_email_super_admin_promotion(array $data): string {
    $name = htmlspecialchars($data['first_name'] ?? '');
    $email = htmlspecialchars($data['email'] ?? '');
    $by = htmlspecialchars($data['promoted_by_name'] ?? '');

    $content = '
      <h1 style="font-size:20px;margin:0 0 10px;font-weight:500;letter-spacing:-0.01em;color:#1C1917">Nouveau rôle : Super Admin 👑</h1>
      <p>Bonjour ' . $name . ',</p>
      <p>Votre compte Assokit (<strong>' . $email . '</strong>) a été promu <strong>super administrateur</strong> de la plateforme' . ($by ? ' par ' . $by : '') . '.</p>
      <p>Vous conservez votre accès à votre association, et vous avez désormais accès au <strong>cockpit Super Admin</strong> :</p>
      <ul style="padding-left:20px;margin:10px 0;font-size:13.5px;color:#44403C">
        <li>Gestion de toutes les associations</li>
        <li>Vue sur tous les projets de la plateforme</li>
        <li>Suivi des abonnements et paiements</li>
        <li>Historique des actions</li>
      </ul>
      <p style="font-size:13px;color:#78716C;margin-top:16px">
        💡 À votre prochaine connexion, vous serez dirigé vers votre cockpit super admin. Un bouton "Mode asso" vous permettra de revenir à votre espace association.
      </p>';

    return email_wrap('Vous êtes désormais Super Admin Assokit', $content, 'Accéder au cockpit', 'https://assokit.fr/super-admin');
}
