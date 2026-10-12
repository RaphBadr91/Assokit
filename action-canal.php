<?php
/**
 * ============================================================
 * ASSOKIT — Actions sur canaux (admin uniquement)
 * ============================================================
 * Gère :
 *   - create : créer un nouveau canal
 *   - update : modifier un canal existant
 *   - archive : archiver un canal (le masque sans supprimer)
 *   - restore : restaurer un canal archivé
 *   - delete : supprimer définitivement (+ tous les messages)
 *   - add_member : ajouter un utilisateur à un canal privé
 *   - remove_member : retirer un utilisateur
 * Le canal « Suivi · Mairie » (is_mairie_channel = 1) est verrouillé : il n'est géré
 * que par le rattachement / détachement de la mairie (includes-mairie-channels.php).
 * ============================================================
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/activity-tracker.php';  // journal fondateur : qui a fait quoi, à la minute près
activity_log_request();

require_login();
$user = current_user();
$user_id = (int)$user['id'];
$org_id = (int)$user['org_id'];

// Seuls les admins peuvent gérer les canaux
if ($user['role'] !== 'admin') {
    header('Location: /messages?error=not_admin');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /messages');
    exit;
}

if (!check_csrf($_POST['csrf_token'] ?? '')) {
    header('Location: /messages?error=csrf');
    exit;
}

$action = $_POST['action'] ?? '';

// Helper : générer un slug URL-safe depuis le nom
function make_slug($name) {
    $s = mb_strtolower(trim($name), 'UTF-8');
    // Translittération basique des accents
    $s = str_replace(
        ['à','á','â','ã','ä','å','ç','è','é','ê','ë','ì','í','î','ï','ñ','ò','ó','ô','õ','ö','ù','ú','û','ü','ý','ÿ'],
        ['a','a','a','a','a','a','c','e','e','e','e','i','i','i','i','n','o','o','o','o','o','u','u','u','u','y','y'],
        $s
    );
    $s = preg_replace('/[^a-z0-9]+/', '-', $s);
    $s = trim($s, '-');
    return $s ?: 'canal';
}

// Helper : charger un canal de l'association (null s'il n'est pas à elle)
function load_org_channel(PDO $pdo, int $channel_id, int $org_id): ?array {
    if ($channel_id <= 0) return null;
    $stmt = $pdo->prepare("SELECT * FROM channels WHERE id = ? AND org_id = ?");
    $stmt->execute([$channel_id, $org_id]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

// Helper : refuser toute modification du canal « Suivi · Mairie » (la mairie perdrait la conversation)
function refuse_if_mairie_channel(array $channel): void {
    if (!empty($channel['is_mairie_channel'])) {
        header('Location: /canal/' . urlencode((string)$channel['slug']) . '/parametres?error=mairie_locked');
        exit;
    }
}

// Helper : ajouter un utilisateur à un canal privé (sans doublon)
function add_channel_member(PDO $pdo, int $channel_id, int $user_id, string $role = 'member'): void {
    $check = $pdo->prepare("SELECT id FROM channel_members WHERE channel_id = ? AND user_id = ?");
    $check->execute([$channel_id, $user_id]);
    if ($check->fetch()) return;
    try {
        $pdo->prepare("INSERT INTO channel_members (channel_id, user_id, role) VALUES (?, ?, ?)")
            ->execute([$channel_id, $user_id, $role]);
    } catch (Exception $e) {
        // Doublon concurrent : déjà membre
    }
}

// =========================================================
// ACTION : Créer un canal
// =========================================================
if ($action === 'create') {
    $name = trim($_POST['name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $icon = trim($_POST['icon'] ?? '');
    $color_theme = $_POST['color_theme'] ?? 'gray';
    $type = $_POST['type'] ?? 'public';

    if ($name === '' || mb_strlen($name) > 80) {
        header('Location: /nouveau-canal?error=invalid_name');
        exit;
    }

    $valid_types = ['public', 'private', 'announce'];
    $valid_colors = ['blue', 'purple', 'amber', 'pink', 'teal', 'green', 'red', 'gray'];
    if (!in_array($type, $valid_types, true)) $type = 'public';
    if (!in_array($color_theme, $valid_colors, true)) $color_theme = 'gray';

    // Icône : accepter 1-4 caractères (emoji) ou vide
    if (mb_strlen($icon) > 10) $icon = mb_substr($icon, 0, 10);

    // Générer un slug unique
    $base_slug = make_slug($name);
    $slug = $base_slug;
    $counter = 1;
    while (true) {
        $check = $pdo->prepare("SELECT id FROM channels WHERE org_id = ? AND slug = ?");
        $check->execute([$org_id, $slug]);
        if (!$check->fetch()) break;
        $counter++;
        $slug = $base_slug . '-' . $counter;
    }

    // Déterminer la position (dernière + 1)
    $pos_stmt = $pdo->prepare("SELECT COALESCE(MAX(position), 0) + 1 FROM channels WHERE org_id = ?");
    $pos_stmt->execute([$org_id]);
    $position = (int)$pos_stmt->fetchColumn();

    // Insertion
    $stmt = $pdo->prepare("
        INSERT INTO channels (org_id, created_by, name, slug, description, icon, color_theme, type, position)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $org_id,
        $user_id,
        $name,
        $slug,
        $description ?: null,
        $icon ?: null,
        $color_theme,
        $type,
        $position,
    ]);

    $new_id = (int)$pdo->lastInsertId();

    // Si canal privé : ajouter automatiquement le créateur comme modérateur
    if ($type === 'private') {
        $pdo->prepare("INSERT INTO channel_members (channel_id, user_id, role) VALUES (?, ?, 'moderator')")
            ->execute([$new_id, $user_id]);

        // Et ajouter les membres initiaux si fournis
        $initial_members = $_POST['initial_members'] ?? [];
        if (is_array($initial_members)) {
            foreach ($initial_members as $m_id) {
                $m_id = (int)$m_id;
                if ($m_id > 0 && $m_id !== $user_id) {
                    // Vérifier que l'utilisateur appartient à l'org (les suiveurs n'ont pas accès à la messagerie)
                    $check = $pdo->prepare("SELECT id FROM users WHERE id = ? AND org_id = ? AND (role IS NULL OR role <> 'follower')");
                    $check->execute([$m_id, $org_id]);
                    if ($check->fetch()) {
                        try {
                            $pdo->prepare("INSERT INTO channel_members (channel_id, user_id, role) VALUES (?, ?, 'member')")
                                ->execute([$new_id, $m_id]);
                        } catch (Exception $e) {
                            // Ignorer les doublons
                        }
                    }
                }
            }
        }
    }

    header('Location: /messages?c=' . urlencode($slug) . '&created=1');
    exit;
}

// =========================================================
// ACTION : Modifier un canal
// =========================================================
if ($action === 'update') {
    $channel_id = (int)($_POST['channel_id'] ?? 0);
    $name = trim($_POST['name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $icon = trim($_POST['icon'] ?? '');
    $color_theme = $_POST['color_theme'] ?? 'gray';
    $type = $_POST['type'] ?? 'public';

    if ($channel_id <= 0 || $name === '') {
        header('Location: /messages?error=invalid');
        exit;
    }

    // Vérifier que le canal appartient bien à l'org
    $channel = load_org_channel($pdo, $channel_id, $org_id);
    if (!$channel) {
        header('Location: /messages?error=notfound');
        exit;
    }
    refuse_if_mairie_channel($channel);

    $valid_types = ['public', 'private', 'announce'];
    $valid_colors = ['blue', 'purple', 'amber', 'pink', 'teal', 'green', 'red', 'gray'];
    if (!in_array($type, $valid_types, true)) $type = $channel['type'];
    if (!in_array($color_theme, $valid_colors, true)) $color_theme = $channel['color_theme'];
    if (mb_strlen($icon) > 10) $icon = mb_substr($icon, 0, 10);

    $stmt = $pdo->prepare("
        UPDATE channels
        SET name = ?, description = ?, icon = ?, color_theme = ?, type = ?
        WHERE id = ?
    ");
    $stmt->execute([
        $name,
        $description ?: null,
        $icon ?: null,
        $color_theme,
        $type,
        $channel_id,
    ]);

    // Passage en privé : seuls les membres le voient. L'auteur du changement en devient
    // modérateur, sinon le canal (et ses messages) disparaît pour tout le monde, lui compris.
    if ($type === 'private' && $channel['type'] !== 'private') {
        add_channel_member($pdo, $channel_id, $user_id, 'moderator');
    }

    // Canal archivé : on reste sur ses paramètres (il n'apparaît pas dans la liste des canaux)
    if (!empty($channel['is_archived'])) {
        header('Location: /canal/' . urlencode($channel['slug']) . '/parametres?updated=1');
        exit;
    }
    header('Location: /messages?c=' . urlencode($channel['slug']) . '&updated=1');
    exit;
}

// =========================================================
// ACTION : Archiver un canal
// =========================================================
if ($action === 'archive') {
    $channel = load_org_channel($pdo, (int)($_POST['channel_id'] ?? 0), $org_id);
    if (!$channel) {
        header('Location: /messages?error=notfound');
        exit;
    }
    refuse_if_mairie_channel($channel);
    $stmt = $pdo->prepare("UPDATE channels SET is_archived = 1 WHERE id = ? AND org_id = ?");
    $stmt->execute([(int)$channel['id'], $org_id]);
    header('Location: /messages?archived=1');
    exit;
}

// =========================================================
// ACTION : Restaurer un canal archivé
// =========================================================
if ($action === 'restore') {
    $channel = load_org_channel($pdo, (int)($_POST['channel_id'] ?? 0), $org_id);
    if (!$channel) {
        header('Location: /messages?error=notfound');
        exit;
    }
    refuse_if_mairie_channel($channel);
    $stmt = $pdo->prepare("UPDATE channels SET is_archived = 0 WHERE id = ? AND org_id = ?");
    $stmt->execute([(int)$channel['id'], $org_id]);
    // Canal privé : l'admin qui le restaure doit pouvoir le retrouver dans sa liste
    if ($channel['type'] === 'private') {
        add_channel_member($pdo, (int)$channel['id'], $user_id, 'moderator');
    }
    header('Location: /messages?c=' . urlencode($channel['slug']) . '&restored=1');
    exit;
}

// =========================================================
// ACTION : Supprimer un canal (définitif)
// =========================================================
if ($action === 'delete') {
    $channel = load_org_channel($pdo, (int)($_POST['channel_id'] ?? 0), $org_id);
    if (!$channel) {
        header('Location: /messages?error=notfound');
        exit;
    }
    refuse_if_mairie_channel($channel);
    $stmt = $pdo->prepare("DELETE FROM channels WHERE id = ? AND org_id = ?");
    $stmt->execute([(int)$channel['id'], $org_id]);
    header('Location: /messages?deleted=1');
    exit;
}

// =========================================================
// ACTION : Ajouter un membre à un canal privé
// =========================================================
if ($action === 'add_member') {
    $channel_id = (int)($_POST['channel_id'] ?? 0);
    $new_user_id = (int)($_POST['user_id'] ?? 0);
    $channel_slug = $_POST['channel_slug'] ?? '';

    if ($channel_id <= 0 || $new_user_id <= 0) {
        header('Location: /canal/' . urlencode($channel_slug) . '/parametres?error=invalid');
        exit;
    }

    // Vérifs : le canal doit appartenir à cette association
    $channel = load_org_channel($pdo, $channel_id, $org_id);
    if (!$channel) {
        header('Location: /canal/' . urlencode($channel_slug) . '/parametres?error=invalid');
        exit;
    }
    refuse_if_mairie_channel($channel);
    // Membre actif de l'association, hors suiveurs (financeurs externes, sans accès à la messagerie)
    $check = $pdo->prepare("SELECT id FROM users WHERE id = ? AND org_id = ? AND (role IS NULL OR role <> 'follower')");
    $check->execute([$new_user_id, $org_id]);
    if (!$check->fetch()) {
        header('Location: /canal/' . urlencode($channel_slug) . '/parametres?error=user');
        exit;
    }

    add_channel_member($pdo, $channel_id, $new_user_id, 'member');

    header('Location: /canal/' . urlencode($channel_slug) . '/parametres?member_added=1');
    exit;
}

// =========================================================
// ACTION : Retirer un membre d'un canal privé
// =========================================================
if ($action === 'remove_member') {
    $channel_id = (int)($_POST['channel_id'] ?? 0);
    $remove_user_id = (int)($_POST['user_id'] ?? 0);
    $channel_slug = $_POST['channel_slug'] ?? '';

    $channel = load_org_channel($pdo, $channel_id, $org_id);
    if (!$channel) {
        header('Location: /canal/' . urlencode($channel_slug) . '/parametres?error=invalid');
        exit;
    }
    refuse_if_mairie_channel($channel);

    $pdo->prepare("DELETE cm FROM channel_members cm JOIN channels c ON c.id = cm.channel_id AND c.org_id = ?
                   WHERE cm.channel_id = ? AND cm.user_id = ?")
        ->execute([$org_id, $channel_id, $remove_user_id]);

    header('Location: /canal/' . urlencode($channel_slug) . '/parametres?member_removed=1');
    exit;
}

header('Location: /messages');
exit;
