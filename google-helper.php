<?php
/**
 * ============================================================
 * ASSOKIT — Helper Google Calendar OAuth & API
 * ============================================================
 * Gère :
 *   - Flow OAuth (échange de code, refresh tokens)
 *   - Appels API authentifiés
 *   - Sync bidirectionnelle des événements
 *   - Liste des calendriers
 * ============================================================
 */

function is_google_enabled() {
    return defined('GOOGLE_CLIENT_ID') && GOOGLE_CLIENT_ID !== ''
        && defined('GOOGLE_CLIENT_SECRET') && GOOGLE_CLIENT_SECRET !== ''
        && !in_array(GOOGLE_CLIENT_ID, ['METS_TON_CLIENT_ID_ICI'], true);
}

function get_org_google_connection($org_id) {
    global $pdo;
    $stmt = $pdo->prepare("SELECT * FROM org_google_calendar WHERE org_id = ? LIMIT 1");
    $stmt->execute([$org_id]);
    return $stmt->fetch() ?: null;
}

function sync_log($org_id, $event_id, $direction, $action, $status, $message = null) {
    global $pdo;
    try {
        $stmt = $pdo->prepare("INSERT INTO sync_logs (org_id, event_id, direction, action, status, message) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$org_id, $event_id, $direction, $action, $status, $message]);
    } catch (Exception $e) {}
}

function refresh_access_token($connection) {
    global $pdo;
    if (empty($connection['refresh_token'])) return false;

    $ch = curl_init('https://oauth2.googleapis.com/token');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
        'client_id' => GOOGLE_CLIENT_ID,
        'client_secret' => GOOGLE_CLIENT_SECRET,
        'refresh_token' => $connection['refresh_token'],
        'grant_type' => 'refresh_token',
    ]));
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code !== 200) {
        sync_log($connection['org_id'], null, 'push', 'refresh_token', 'error', 'HTTP ' . $http_code);
        return false;
    }

    $data = json_decode($response, true);
    if (!isset($data['access_token'])) return false;

    $expires_at = date('Y-m-d H:i:s', time() + ($data['expires_in'] ?? 3600) - 60);
    $stmt = $pdo->prepare("UPDATE org_google_calendar SET access_token = ?, token_expires_at = ? WHERE id = ?");
    $stmt->execute([$data['access_token'], $expires_at, $connection['id']]);

    return $data['access_token'];
}

function google_api_call($connection, $method, $endpoint, $body = null, $query_params = []) {
    $access_token = $connection['access_token'];
    if (!empty($connection['token_expires_at']) && strtotime($connection['token_expires_at']) <= time()) {
        $new_token = refresh_access_token($connection);
        if ($new_token === false) {
            return ['success' => false, 'error' => 'Impossible de rafraîchir le token Google', 'http_code' => 0];
        }
        $access_token = $new_token;
        $connection['access_token'] = $new_token;
    }

    $url = 'https://www.googleapis.com/calendar/v3/' . ltrim($endpoint, '/');
    if (!empty($query_params)) $url .= '?' . http_build_query($query_params);

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $access_token,
        'Content-Type: application/json',
    ]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_err = curl_error($ch);
    curl_close($ch);

    if ($curl_err) return ['success' => false, 'error' => 'Erreur réseau : ' . $curl_err, 'http_code' => 0];

    if ($http_code === 401) {
        $new_token = refresh_access_token($connection);
        if ($new_token) {
            $connection['access_token'] = $new_token;
            return google_api_call($connection, $method, $endpoint, $body, $query_params);
        }
    }

    if ($http_code === 204) return ['success' => true, 'data' => null, 'http_code' => 204];

    $data = json_decode($response, true);
    if ($http_code >= 200 && $http_code < 300) return ['success' => true, 'data' => $data, 'http_code' => $http_code];

    $error_msg = $data['error']['message'] ?? ('HTTP ' . $http_code);
    return ['success' => false, 'error' => $error_msg, 'data' => $data, 'http_code' => $http_code];
}

function get_google_user_email($access_token) {
    $ch = curl_init('https://www.googleapis.com/oauth2/v3/userinfo');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $access_token]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $response = curl_exec($ch);
    curl_close($ch);
    $data = json_decode($response, true);
    return $data['email'] ?? null;
}

function list_user_calendars($connection) {
    $result = google_api_call($connection, 'GET', 'users/me/calendarList', null, ['minAccessRole' => 'writer']);
    if (!$result['success']) return [];
    return $result['data']['items'] ?? [];
}

function assokit_event_to_google($event) {
    $is_all_day = !empty($event['is_all_day']);
    $payload = ['summary' => $event['title']];

    if (!empty($event['description']) || !empty($event['project_name'])) {
        $desc = $event['description'] ?? '';
        if (!empty($event['project_name'])) {
            $desc .= (empty($desc) ? '' : "\n\n") . "Projet : " . $event['project_name'];
        }
        $desc .= "\n\nOuvrir dans Assokit : https://" . ($_SERVER['HTTP_HOST'] ?? 'assokit.fr') . "/evenement/" . $event['id'];
        $payload['description'] = $desc;
    }

    if (!empty($event['location'])) {
        $payload['location'] = $event['location'];
    }

    if ($is_all_day) {
        $payload['start'] = ['date' => date('Y-m-d', strtotime($event['starts_at']))];
        $payload['end'] = ['date' => date('Y-m-d', strtotime($event['ends_at'] . ' +1 day'))];
    } else {
        $payload['start'] = [
            'dateTime' => date('c', strtotime($event['starts_at'])),
            'timeZone' => 'Europe/Paris',
        ];
        $payload['end'] = [
            'dateTime' => date('c', strtotime($event['ends_at'])),
            'timeZone' => 'Europe/Paris',
        ];
    }

    $payload['extendedProperties'] = [
        'private' => [
            'assokit_event_id' => (string)$event['id'],
            'assokit_org_id' => (string)$event['org_id'],
        ],
    ];

    return $payload;
}

function sync_event_to_google($event_id) {
    global $pdo;
    $stmt = $pdo->prepare("
        SELECT e.*, p.name AS project_name
        FROM events e
        LEFT JOIN projects p ON e.project_id = p.id
        WHERE e.id = ? AND (e.deleted_at IS NULL)
    ");
    $stmt->execute([$event_id]);
    $event = $stmt->fetch();
    if (!$event) return ['success' => false, 'error' => 'Événement introuvable'];

    $connection = get_org_google_connection($event['org_id']);
    if (!$connection) return ['success' => false, 'skipped' => true];
    if (!$connection['sync_enabled']) return ['success' => false, 'skipped' => true];

    $payload = assokit_event_to_google($event);
    $calendar_id = urlencode($connection['google_calendar_id']);

    if (!empty($event['google_event_id'])) {
        $result = google_api_call($connection, 'PATCH', "calendars/{$calendar_id}/events/" . urlencode($event['google_event_id']), $payload);
        $action = 'update';
    } else {
        $result = google_api_call($connection, 'POST', "calendars/{$calendar_id}/events", $payload);
        $action = 'create';
    }

    if (!$result['success']) {
        sync_log($event['org_id'], $event_id, 'push', $action, 'error', $result['error']);
        return $result;
    }

    if ($action === 'create' && isset($result['data']['id'])) {
        $pdo->prepare("UPDATE events SET google_event_id = ?, synced_at = NOW() WHERE id = ?")
            ->execute([$result['data']['id'], $event_id]);
    } else {
        $pdo->prepare("UPDATE events SET synced_at = NOW() WHERE id = ?")->execute([$event_id]);
    }

    $pdo->prepare("UPDATE org_google_calendar SET last_push_at = NOW() WHERE id = ?")
        ->execute([$connection['id']]);

    sync_log($event['org_id'], $event_id, 'push', $action, 'success');
    return ['success' => true, 'google_event_id' => $result['data']['id'] ?? null];
}

function delete_event_from_google($event_id) {
    global $pdo;
    $stmt = $pdo->prepare("SELECT * FROM events WHERE id = ?");
    $stmt->execute([$event_id]);
    $event = $stmt->fetch();
    if (!$event || empty($event['google_event_id'])) return ['success' => true, 'skipped' => true];

    $connection = get_org_google_connection($event['org_id']);
    if (!$connection || !$connection['sync_enabled']) return ['success' => true, 'skipped' => true];

    $calendar_id = urlencode($connection['google_calendar_id']);
    $result = google_api_call($connection, 'DELETE', "calendars/{$calendar_id}/events/" . urlencode($event['google_event_id']));

    if ($result['success'] || in_array($result['http_code'] ?? 0, [404, 410], true)) {
        sync_log($event['org_id'], $event_id, 'push', 'delete', 'success');
        return ['success' => true];
    }

    sync_log($event['org_id'], $event_id, 'push', 'delete', 'error', $result['error']);
    return $result;
}

function pull_events_from_google($org_id) {
    global $pdo;
    $connection = get_org_google_connection($org_id);
    if (!$connection) return ['success' => false, 'error' => 'Pas de connexion Google'];

    $calendar_id = urlencode($connection['google_calendar_id']);
    $query_params = [];

    if (!empty($connection['sync_token'])) {
        $query_params['syncToken'] = $connection['sync_token'];
    } else {
        $query_params['timeMin'] = date('c', strtotime('-1 month'));
        $query_params['timeMax'] = date('c', strtotime('+1 year'));
        $query_params['singleEvents'] = 'true';
    }

    $result = google_api_call($connection, 'GET', "calendars/{$calendar_id}/events", null, $query_params);

    if (!$result['success'] && ($result['http_code'] ?? 0) === 410) {
        $pdo->prepare("UPDATE org_google_calendar SET sync_token = NULL WHERE id = ?")->execute([$connection['id']]);
        return pull_events_from_google($org_id);
    }

    if (!$result['success']) {
        sync_log($org_id, null, 'pull', 'list', 'error', $result['error']);
        return ['success' => false, 'error' => $result['error']];
    }

    $stats = ['imported' => 0, 'updated' => 0, 'deleted' => 0, 'errors' => []];
    $items = $result['data']['items'] ?? [];

    foreach ($items as $item) {
        try {
            $outcome = google_event_to_assokit($item, $org_id, $connection['connected_by_user_id']);
            if ($outcome) $stats[$outcome]++;
        } catch (Exception $e) {
            $stats['errors'][] = $e->getMessage();
        }
    }

    if (!empty($result['data']['nextSyncToken'])) {
        $pdo->prepare("UPDATE org_google_calendar SET sync_token = ?, last_pull_at = NOW() WHERE id = ?")
            ->execute([$result['data']['nextSyncToken'], $connection['id']]);
    } else {
        $pdo->prepare("UPDATE org_google_calendar SET last_pull_at = NOW() WHERE id = ?")->execute([$connection['id']]);
    }

    sync_log($org_id, null, 'pull', 'list', 'success', "Importés : {$stats['imported']}, MAJ : {$stats['updated']}, Supprimés : {$stats['deleted']}");
    return array_merge(['success' => true], $stats);
}

// Palette officielle des couleurs d'événement Google Agenda (colorId => hex)
function gcal_color_hex($colorId) {
    $map = [
        '1'  => '#7986CB', // Lavande
        '2'  => '#33B679', // Sauge
        '3'  => '#8E24AA', // Raisin
        '4'  => '#E67C73', // Flamant
        '5'  => '#F6BF26', // Banane
        '6'  => '#F4511E', // Mandarine
        '7'  => '#039BE5', // Paon
        '8'  => '#616161', // Graphite
        '9'  => '#3F51B5', // Myrtille
        '10' => '#0B8043', // Basilic
        '11' => '#D50000', // Tomate
    ];
    $colorId = (string) $colorId;
    return $map[$colorId] ?? null;
}

function google_event_to_assokit($google_event, $org_id, $default_user_id) {
    global $pdo;
    $google_id = $google_event['id'] ?? null;
    if (!$google_id) return null;
    $gcolor = gcal_color_hex($google_event['colorId'] ?? '');

    $stmt = $pdo->prepare("SELECT id, sync_origin FROM events WHERE google_event_id = ? LIMIT 1");
    $stmt->execute([$google_id]);
    $existing = $stmt->fetch();

    if (($google_event['status'] ?? '') === 'cancelled') {
        if ($existing) {
            $pdo->prepare("UPDATE events SET deleted_at = NOW() WHERE id = ?")->execute([$existing['id']]);
            return 'deleted';
        }
        return null;
    }

    $start = $google_event['start'] ?? [];
    $end = $google_event['end'] ?? [];
    $is_all_day = isset($start['date']);

    if ($is_all_day) {
        $starts_at = $start['date'] . ' 00:00:00';
        $ends_at = date('Y-m-d', strtotime($end['date'] . ' -1 day')) . ' 23:59:59';
    } else {
        if (empty($start['dateTime']) || empty($end['dateTime'])) return null;
        $starts_at = date('Y-m-d H:i:s', strtotime($start['dateTime']));
        $ends_at = date('Y-m-d H:i:s', strtotime($end['dateTime']));
    }

    $title = $google_event['summary'] ?? '(sans titre)';
    $description = $google_event['description'] ?? null;
    $location = $google_event['location'] ?? null;

    if ($existing) {
        if ($existing['sync_origin'] === 'google') {
            $pdo->prepare("
                UPDATE events
                SET title = ?, description = ?, location = ?, starts_at = ?, ends_at = ?, is_all_day = ?, color_theme = ?, synced_at = NOW()
                WHERE id = ?
            ")->execute([$title, $description, $location, $starts_at, $ends_at, $is_all_day ? 1 : 0, $gcolor, $existing['id']]);
            return 'updated';
        }
        return null;
    }

    $pdo->prepare("
        INSERT INTO events (org_id, created_by, title, description, location,
                           event_type, color_theme, starts_at, ends_at, is_all_day, visibility,
                           google_event_id, sync_origin, synced_at)
        VALUES (?, ?, ?, ?, ?, 'other', ?, ?, ?, ?, 'organization', ?, 'google', NOW())
    ")->execute([$org_id, $default_user_id, $title, $description, $location,
                 $gcolor, $starts_at, $ends_at, $is_all_day ? 1 : 0, $google_id]);

    return 'imported';
}

function disconnect_google($org_id) {
    global $pdo;
    $connection = get_org_google_connection($org_id);
    if (!$connection) return true;

    if (!empty($connection['access_token'])) {
        $ch = curl_init('https://oauth2.googleapis.com/revoke?token=' . urlencode($connection['access_token']));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        @curl_exec($ch);
        curl_close($ch);
    }

    $pdo->prepare("DELETE FROM org_google_calendar WHERE org_id = ?")->execute([$org_id]);
    $pdo->prepare("UPDATE events SET google_event_id = NULL, synced_at = NULL WHERE org_id = ?")->execute([$org_id]);

    return true;
}
