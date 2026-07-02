<?php
/**
 * ============================================================
 * ASSOKIT — Actions sur canaux (admin uniquement)
 * ============================================================
 * Gère :
 *   - create : créer un nouveau canal
 *   - update : modifier un canal existant
 *   - archive : archiver un canal (le masque sans supprimer)
 *   - delete : supprimer définitivement (+ tous les messages)
 *   - add_member : ajouter un utilisateur à un canal privé
 *   - remove_member : retirer un utilisateur
 * ============================================================
 */
require_once __DIR__ . '/config.php';

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
                    // Vérifier que l'utilisateur appartient à l'org
                    $check = $pdo->prepare("SELECT id FROM users WHERE id = ? AND org_id = ?");
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
    $stmt = $pdo->prepare("SELECT * FROM channels WHERE id = ? AND org_id = ?");
    $stmt->execute([$channel_id, $org_id]);
    $channel = $stmt->fetch();
    if (!$channel) {
        header('Location: /messages?error=notfound');
        exit;
    }

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

    header('Location: /messages?c=' . urlencode($channel['slug']) . '&updated=1');
    exit;
}

// =========================================================
// ACTION : Archiver un canal
// =========================================================
if ($action === 'archive') {
    $channel_id = (int)($_POST['channel_id'] ?? 0);
    $stmt = $pdo->prepare("UPDATE channels SET is_archived = 1 WHERE id = ? AND org_id = ?");
    $stmt->execute([$channel_id, $org_id]);
    header('Location: /messages?archived=1');
    exit;
}

// =========================================================
// ACTION : Supprimer un canal (définitif)
// =========================================================
if ($action === 'delete') {
    $channel_id = (int)($_POST['channel_id'] ?? 0);
    $stmt = $pdo->prepare("DELETE FROM channels WHERE id = ? AND org_id = ?");
    $stmt->execute([$channel_id, $org_id]);
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

    // Vérifs
    $check = $pdo->prepare("SELECT id FROM users WHERE id = ? AND org_id = ?");
    $check->execute([$new_user_id, $org_id]);
    if (!$check->fetch()) {
        header('Location: /canal/' . urlencode($channel_slug) . '/parametres?error=user');
        exit;
    }

    try {
        $pdo->prepare("INSERT INTO channel_members (channel_id, user_id, role) VALUES (?, ?, 'member')")
            ->execute([$channel_id, $new_user_id]);
    } catch (Exception $e) {
        // Doublon : déjà membre
    }

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

    $pdo->prepare("DELETE FROM channel_members WHERE channel_id = ? AND user_id = ?")
        ->execute([$channel_id, $remove_user_id]);

    header('Location: /canal/' . urlencode($channel_slug) . '/parametres?member_removed=1');
    exit;
}

header('Location: /messages');
exit;
