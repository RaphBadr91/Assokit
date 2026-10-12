<?php
/**
 * Helpers Assemblées Générales
 *
 * Cycle de vie : draft → sent (convoquée) → in_progress (séance ouverte) → closed.
 *   - Avant la séance, le convoqué RÉPOND (« je viendrai » / « je m'excuse ») :
 *     colonne rsvp si la migration 2026-10-11-L04-agenda.sql est passée.
 *   - La présence (status 'present' + signed_at) ne s'enregistre QUE pendant
 *     la séance : c'est elle qui compte pour le quorum et le PV.
 *   - Une AG clôturée est figée (participants, résolutions, quorum) : le PV
 *     régénéré reste identique.
 *   - Pouvoir (status 'proxy') : le convoqué est représenté par un mandataire
 *     (proxy_attendee_id, même migration) qui vote aussi pour lui. Sans mandataire
 *     désigné (pouvoir en blanc, ou base sans la colonne), le pouvoir compte pour
 *     le quorum mais ne vote pas.
 */
require_once __DIR__ . '/trial-helpers.php';   // ak_db_columns(), ak_db_update() (schéma prod non versionné)

function ag_type_label(string $t): string {
    return [
        'ag_ord' => 'AG Ordinaire', 'ag_ext' => 'AG Extraordinaire',
        'ca' => 'Conseil d\'Administration', 'bureau' => 'Bureau'
    ][$t] ?? $t;
}

function ag_status_meta(?string $s): array {
    if ($s === null || $s === '') $s = 'draft';
    return [
        'draft'       => ['Brouillon',     '#6b7280', '#f3f4f6', '📝'],
        'sent'        => ['Convoquée',     '#3B82F6', '#DBEAFE', '📨'],
        'in_progress' => ['En cours',      '#10B981', '#ECFDF5', '🟢'],
        'closed'      => ['Clôturée',      '#8B5CF6', '#EDE9FE', '✅'],
        'archived'    => ['Archivée',      '#9ca3af', '#f9fafb', '📦'],
    ][$s] ?? ['—', '#6b7280', '#f3f4f6', '?'];
}

function ag_vote_type_label(string $t): string {
    return [
        'simple' => 'Majorité simple (>50%)',
        'qualifie_2_3' => 'Majorité qualifiée (2/3)',
        'qualifie_3_4' => 'Majorité qualifiée (3/4)',
        'unanime' => 'Unanimité',
        'consultatif' => 'Consultatif (sans effet)'
    ][$t] ?? $t;
}

function ag_load(PDO $pdo, int $id, int $org_id): ?array {
    $stmt = $pdo->prepare("SELECT * FROM assemblies WHERE id = ? AND org_id = ?");
    $stmt->execute([$id, $org_id]);
    $ag = $stmt->fetch() ?: null;
    // Une AG créée sans statut (colonne sans valeur par défaut) est un brouillon.
    if ($ag && empty($ag['status'])) $ag['status'] = 'draft';
    return $ag;
}

function ag_load_by_token(PDO $pdo, string $token): ?array {
    $stmt = $pdo->prepare("SELECT a.*, ass.org_id, ass.title AS ag_title, ass.scheduled_at, ass.location, ass.location_url, ass.status AS ag_status,
        ass.description AS ag_description, ass.type AS ag_type
        FROM assembly_attendees a JOIN assemblies ass ON ass.id = a.assembly_id
        WHERE a.access_token = ? LIMIT 1");
    $stmt->execute([$token]);
    $att = $stmt->fetch() ?: null;
    if ($att && empty($att['ag_status'])) $att['ag_status'] = 'draft';
    return $att;
}

/** AG figée : plus aucune modification des participants, des résolutions ni du quorum. */
function ag_is_frozen(?string $status): bool {
    return in_array($status, ['closed', 'archived'], true);
}

/** La colonne existe-t-elle en base ? (migrations facultatives : le code s'en passe) */
function ag_has_col(PDO $pdo, string $table, string $col): bool {
    return isset(ak_db_columns($pdo, $table)[$col]);
}

/** Lien visio affichable : http(s) uniquement (jamais javascript: ou data:). */
function ag_safe_url(?string $url): ?string {
    $url = trim((string)$url);
    return preg_match('#^https?://#i', $url) ? $url : null;
}

/**
 * Réponse du convoqué à la convocation : 'yes', 'excused' ou null.
 * Le statut fixé par l'organisateur l'emporte (excusé, absent, représenté par un
 * pouvoir) ; sans la colonne rsvp, seule l'excuse se lit dans le statut.
 */
function ag_rsvp(array $att): ?string {
    $status = $att['status'] ?? '';
    if ($status === 'excused') return 'excused';
    if ($status === 'absent' || $status === 'proxy') return null;
    $r = $att['rsvp'] ?? null;
    return ($r === 'yes' || $r === 'excused') ? $r : null;
}

/** Résolution déclarée « non délibérée » : séance close sans quorum. */
function ag_not_deliberated(array $assembly, array $attendees): bool {
    if (($assembly['status'] ?? '') !== 'closed' || empty($assembly['quorum_required'])) return false;
    return !ag_quorum_status($assembly, $attendees)['ok'];
}

/** Présentation + ordre du jour (titre et majorité de chaque résolution), en HTML d'e-mail. */
function ag_agenda_html(array $assembly, array $resos): string {
    $html = '';
    if (!empty($assembly['description'])) {
        $html .= '<p style="color:#4b5563;font-size:14px;line-height:1.6;white-space:pre-line;">' . htmlspecialchars($assembly['description']) . '</p>';
    }
    if ($resos) {
        $html .= '<p style="color:#111827;font-size:14px;font-weight:600;margin:16px 0 6px;">Ordre du jour</p>'
               . '<ol style="color:#374151;font-size:14px;line-height:1.6;padding-left:20px;margin:0 0 14px;">';
        foreach ($resos as $r) {
            $html .= '<li>' . htmlspecialchars((string)$r['title'])
                   . ' <span style="color:#6b7280;font-size:12px;">· ' . htmlspecialchars(ag_vote_type_label((string)$r['vote_type'])) . '</span></li>';
        }
        $html .= '</ol>';
    }
    return $html;
}

function ag_load_attendees(PDO $pdo, int $assembly_id): array {
    $stmt = $pdo->prepare("SELECT * FROM assembly_attendees WHERE assembly_id = ? ORDER BY full_name");
    $stmt->execute([$assembly_id]);
    return $stmt->fetchAll();
}

function ag_load_resolutions(PDO $pdo, int $assembly_id): array {
    $stmt = $pdo->prepare("SELECT * FROM assembly_resolutions WHERE assembly_id = ? ORDER BY position, id");
    $stmt->execute([$assembly_id]);
    return $stmt->fetchAll();
}

/** Le mandataire d'un pouvoir peut-il être enregistré ? (colonne proxy_attendee_id) */
function ag_has_proxy_holder(PDO $pdo): bool {
    return ag_has_col($pdo, 'assembly_attendees', 'proxy_attendee_id');
}

/** Participants indexés par id. */
function ag_index_attendees(array $attendees): array {
    $by = [];
    foreach ($attendees as $a) $by[(int)$a['id']] = $a;
    return $by;
}

/** Peut porter un pouvoir : ni excusé, ni absent, ni lui-même représenté. */
function ag_can_hold_proxy(array $att): bool {
    return !in_array($att['status'] ?? '', ['excused', 'absent', 'proxy'], true);
}

/**
 * Un pouvoir compte-t-il comme « représenté » (quorum) ? Sans mandataire désigné : oui,
 * comme avant. Avec un mandataire : il doit pouvoir le porter, et être présent une fois
 * la séance ouverte (le pouvoir d'un mandataire absent est caduc).
 */
function ag_proxy_valid(array $att, array $by_id, ?string $ag_status): bool {
    if (($att['status'] ?? '') !== 'proxy') return false;
    $mid = (int)($att['proxy_attendee_id'] ?? 0);
    if ($mid <= 0) return true;
    $m = $by_id[$mid] ?? null;
    if (!$m) return false;
    if (in_array($ag_status, ['in_progress', 'closed', 'archived'], true)) return ($m['status'] ?? '') === 'present';
    return ag_can_hold_proxy($m);
}

function ag_resolution_stats(PDO $pdo, int $resolution_id): array {
    $r = ['for' => 0, 'against' => 0, 'abstain' => 0, 'total' => 0, 'by_proxy' => 0];
    // Un seul vote par participant (le dernier), et seulement les participants
    // encore convoqués à cette AG : un vote changé n'est jamais compté deux fois,
    // même sans index unique (resolution_id, attendee_id) en base.
    $stmt = $pdo->prepare("SELECT v.choice, COUNT(*) c FROM assembly_votes v
        JOIN (SELECT MAX(v2.id) id FROM assembly_votes v2
                JOIN assembly_resolutions r ON r.id = v2.resolution_id
                JOIN assembly_attendees a ON a.id = v2.attendee_id AND a.assembly_id = r.assembly_id
               WHERE v2.resolution_id = ? GROUP BY v2.attendee_id) last ON last.id = v.id
        GROUP BY v.choice");
    $stmt->execute([$resolution_id]);
    foreach ($stmt->fetchAll() as $row) {
        if (isset($r[$row['choice']])) $r[$row['choice']] = (int)$row['c'];
    }
    // Pouvoirs : la voix du représenté suit le dernier vote de son mandataire présent
    // (sauf s'il a voté lui-même avant de donner pouvoir).
    if (ag_has_proxy_holder($pdo)) {
        try {
            $stmt = $pdo->prepare("SELECT v.choice, COUNT(*) c
                FROM assembly_resolutions r
                JOIN assembly_attendees p ON p.assembly_id = r.assembly_id AND p.status = 'proxy'
                JOIN assembly_attendees m ON m.id = p.proxy_attendee_id AND m.assembly_id = r.assembly_id AND m.status = 'present'
                JOIN (SELECT v2.attendee_id, MAX(v2.id) id FROM assembly_votes v2 WHERE v2.resolution_id = ? GROUP BY v2.attendee_id) last ON last.attendee_id = m.id
                JOIN assembly_votes v ON v.id = last.id
                WHERE r.id = ?
                  AND NOT EXISTS (SELECT 1 FROM assembly_votes ov WHERE ov.resolution_id = r.id AND ov.attendee_id = p.id)
                GROUP BY v.choice");
            $stmt->execute([$resolution_id, $resolution_id]);
            foreach ($stmt->fetchAll() as $row) {
                if (!isset($r[$row['choice']])) continue;
                $r[$row['choice']] += (int)$row['c'];
                $r['by_proxy'] += (int)$row['c'];
            }
        } catch (Throwable $e) {
            error_log('[includes-assemblies] votes par pouvoir : ' . $e->getMessage());
        }
    }
    $r['total'] = $r['for'] + $r['against'] + $r['abstain'];
    return $r;
}

function ag_compute_result(string $vote_type, array $stats): string {
    $expr = $stats['for'] + $stats['against']; // abstentions exclues du calcul majoritaire
    if ($expr === 0) return 'rejected';
    $ratio = $stats['for'] / $expr;
    switch ($vote_type) {
        case 'simple':       return $ratio > 0.5 ? 'adopted' : 'rejected';
        case 'qualifie_2_3': return $ratio >= (2/3) ? 'adopted' : 'rejected';
        case 'qualifie_3_4': return $ratio >= 0.75 ? 'adopted' : 'rejected';
        case 'unanime':      return ($stats['against'] === 0 && $stats['for'] > 0) ? 'adopted' : 'rejected';
        case 'consultatif':  return $ratio > 0.5 ? 'adopted' : 'rejected';
    }
    return 'rejected';
}

/** Quorum : 'present' = présents + représentés (pouvoirs valables), détaillés dans 'here' et 'represented'. */
function ag_quorum_status(array $assembly, array $attendees): array {
    $here = 0; $represented = 0;
    $by_id = ag_index_attendees($attendees);
    foreach ($attendees as $a) {
        if ($a['status'] === 'present') $here++;
        elseif (ag_proxy_valid($a, $by_id, $assembly['status'] ?? null)) $represented++;
    }
    $present = $here + $represented;
    $req = (int)($assembly['quorum_required'] ?? 0);
    if ($req <= 0) return ['ok' => true, 'present' => $present, 'here' => $here, 'represented' => $represented, 'required' => 0, 'percent' => 100];
    return [
        'ok' => $present >= $req,
        'present' => $present,
        'here' => $here,
        'represented' => $represented,
        'required' => $req,
        'percent' => $req > 0 ? round(($present / $req) * 100) : 100,
    ];
}

function ag_org_stats(PDO $pdo, int $org_id): array {
    $r = ['total' => 0, 'upcoming' => 0, 'in_progress' => 0, 'closed' => 0];
    try {
        $stmt = $pdo->prepare("SELECT
            COUNT(*) total,
            SUM(CASE WHEN status IN ('draft','sent') AND scheduled_at >= NOW() THEN 1 ELSE 0 END) upcoming,
            SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END) in_progress,
            SUM(CASE WHEN status = 'closed' THEN 1 ELSE 0 END) closed
            FROM assemblies WHERE org_id = ? AND archived_at IS NULL");
        $stmt->execute([$org_id]);
        if ($x = $stmt->fetch()) $r = array_merge($r, array_map('intval', $x));
    } catch (Throwable $e) {}
    return $r;
}

function ag_random_token(): string {
    return bin2hex(random_bytes(20));
}
