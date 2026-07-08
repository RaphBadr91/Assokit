<?php
/**
 * cron-push-notifications.php — Envoie les notifications push (Expo) vers l'app mobile.
 * A lancer regulierement (ex: toutes les 5 minutes) via cron O2switch.
 * App-only : lit user_notifications + asso_push_tokens, envoie via l'API push Expo.
 * NE MODIFIE PAS le site (aucune ecriture dans les tables du site).
 */
require_once __DIR__ . '/config.php';

if (php_sapi_name() !== 'cli' && empty($_GET['cron_key'])) {
    // Protection minimale si appele en HTTP
    http_response_code(403);
    exit('CLI only');
}

function expo_push_send(array $messages): array {
    if (empty($messages)) return [];
    $ch = curl_init('https://exp.host/--/api/v2/push/send');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
        CURLOPT_POSTFIELDS => json_encode(array_values($messages)),
        CURLOPT_TIMEOUT => 30,
    ]);
    $res = curl_exec($ch);
    curl_close($ch);
    $j = json_decode((string) $res, true);
    return is_array($j) ? $j : [];
}

// Table absente => rien a faire
try {
    $pdo->query("SELECT 1 FROM asso_push_tokens LIMIT 1");
} catch (Throwable $e) {
    fwrite(STDOUT, "asso_push_tokens absente, rien a faire.\n");
    exit;
}

$tokens = $pdo->query("SELECT id, user_id, token, last_pushed_notif_id FROM asso_push_tokens")->fetchAll(PDO::FETCH_ASSOC);
$sent = 0; $dead = [];

foreach ($tokens as $tk) {
    $uid = (int) $tk['user_id'];
    $last = (int) $tk['last_pushed_notif_id'];

    // Nouvelles notifications non lues depuis le dernier envoi
    $st = $pdo->prepare("
        SELECT id, title, body, link_url
        FROM user_notifications
        WHERE user_id = ? AND id > ? AND is_read = 0
        ORDER BY id ASC LIMIT 10
    ");
    $st->execute([$uid, $last]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // Avancer le curseur au-dela de toutes les notifs (lues incluses) pour ne pas re-scanner
    $maxSt = $pdo->prepare("SELECT COALESCE(MAX(id),0) FROM user_notifications WHERE user_id = ?");
    $maxSt->execute([$uid]);
    $maxId = (int) $maxSt->fetchColumn();

    if ($rows) {
        $messages = [];
        if (count($rows) <= 3) {
            foreach ($rows as $r) {
                $messages[] = [
                    'to'    => $tk['token'],
                    'title' => (string) ($r['title'] ?: 'Assokit'),
                    'body'  => (string) ($r['body'] ?: ''),
                    'sound' => 'default',
                    'data'  => ['url' => (string) ($r['link_url'] ?? '')],
                ];
            }
        } else {
            // Regroupe si beaucoup
            $messages[] = [
                'to'    => $tk['token'],
                'title' => 'Assokit',
                'body'  => count($rows) . ' nouvelles notifications',
                'sound' => 'default',
            ];
        }
        $resp = expo_push_send($messages);
        $sent += count($messages);

        // Detecte les tokens morts (DeviceNotRegistered)
        if (!empty($resp['data']) && is_array($resp['data'])) {
            foreach ($resp['data'] as $d) {
                if (($d['status'] ?? '') === 'error' && (($d['details']['error'] ?? '') === 'DeviceNotRegistered')) {
                    $dead[] = (int) $tk['id'];
                }
            }
        }
    }

    if ($maxId > $last) {
        $pdo->prepare("UPDATE asso_push_tokens SET last_pushed_notif_id = ? WHERE id = ?")->execute([$maxId, (int) $tk['id']]);
    }
}

// Nettoyage des tokens morts
if ($dead) {
    $in = implode(',', array_map('intval', array_unique($dead)));
    $pdo->exec("DELETE FROM asso_push_tokens WHERE id IN ($in)");
}

/* ------------------------------------------------------------------ */
/*  RELANCES AUTOMATIQUES (proactives)                                 */
/*  Ex: rappeler au responsable d'un projet actif SANS facture         */
/*  d'ajouter ses factures. Throttle : 1 rappel / semaine / projet.    */
/* ------------------------------------------------------------------ */
$reminders = 0;
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS asso_push_reminder_log (
            user_id INT NOT NULL,
            reminder_key VARCHAR(80) NOT NULL,
            last_sent_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (user_id, reminder_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    // Projets actifs, avec responsable disposant d'un token, et 0 facture
    $q = $pdo->query("
        SELECT p.id AS pid, p.name AS pname, p.referent_id AS uid, t.token AS token
        FROM projects p
        JOIN folders f ON p.folder_id = f.id
        JOIN asso_push_tokens t ON t.user_id = p.referent_id
        WHERE p.status IN ('active','warning')
          AND (p.archived_at IS NULL OR p.archived_at = '0000-00-00 00:00:00')
          AND (f.archived_at IS NULL OR f.archived_at = '0000-00-00 00:00:00')
          AND p.referent_id IS NOT NULL AND p.referent_id > 0
          AND NOT EXISTS (SELECT 1 FROM project_invoices pi WHERE pi.project_id = p.id)
    ");
    $rows = $q ? $q->fetchAll(PDO::FETCH_ASSOC) : [];

    $chk = $pdo->prepare("SELECT last_sent_at FROM asso_push_reminder_log WHERE user_id = ? AND reminder_key = ?");
    $ins = $pdo->prepare("INSERT INTO asso_push_reminder_log (user_id, reminder_key, last_sent_at) VALUES (?, ?, NOW()) ON DUPLICATE KEY UPDATE last_sent_at = NOW()");

    $rmsgs = [];
    foreach ($rows as $r) {
        $key = 'inv:' . (int) $r['pid'];
        $chk->execute([(int) $r['uid'], $key]);
        $lastSent = $chk->fetchColumn();
        if ($lastSent && (time() - strtotime((string) $lastSent)) < 7 * 86400) continue; // throttle 7 jours
        $rmsgs[] = [
            'to'    => $r['token'],
            'title' => '📸 ' . (string) $r['pname'],
            'body'  => 'Pensez à ajouter vos factures pour suivre le budget de ce projet.',
            'sound' => 'default',
            'data'  => ['url' => '/projet/' . (int) $r['pid']],
        ];
        $ins->execute([(int) $r['uid'], $key]);
    }
    foreach (array_chunk($rmsgs, 90) as $chunk) {
        expo_push_send($chunk);
        $reminders += count($chunk);
    }
} catch (Throwable $e) {
    error_log('[cron-push reminders] ' . $e->getMessage());
}

fwrite(STDOUT, "Push envoyes: $sent — relances: $reminders — tokens morts supprimes: " . count(array_unique($dead)) . "\n");
