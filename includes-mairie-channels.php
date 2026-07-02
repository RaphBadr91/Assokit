<?php
/**
 * Helpers de gestion des channels mairie ↔ asso.
 * Un channel privé est créé automatiquement pour chaque asso liée à une mairie,
 * réunissant tous les agents de la mairie et tous les admins de l'asso.
 */

if (!function_exists('ensure_mairie_channel_for_org')) {

    /**
     * Crée (ou récupère) le channel mairie ↔ asso, et synchronise les membres.
     * Retourne l'ID du channel.
     */
    function ensure_mairie_channel_for_org(PDO $pdo, int $org_id, int $parent_org_id): int
    {
        if ($org_id <= 0 || $parent_org_id <= 0) {
            throw new RuntimeException("ensure_mairie_channel_for_org: IDs invalides");
        }

        // Channel déjà existant ?
        $stmt = $pdo->prepare("SELECT id FROM channels WHERE org_id = ? AND parent_org_id = ? AND is_mairie_channel = 1 LIMIT 1");
        $stmt->execute([$org_id, $parent_org_id]);
        $channel_id = (int)$stmt->fetchColumn();

        if (!$channel_id) {
            // Mairie + org
            $stmt = $pdo->prepare("SELECT name FROM parent_orgs WHERE id = ?");
            $stmt->execute([$parent_org_id]);
            $mairie_name = (string)$stmt->fetchColumn();

            $stmt = $pdo->prepare("SELECT name FROM organizations WHERE id = ?");
            $stmt->execute([$org_id]);
            $org_name = (string)$stmt->fetchColumn();

            // Created_by : premier agent mairie ou fallback admin asso
            $stmt = $pdo->prepare("SELECT id FROM users WHERE parent_org_id = ? AND deleted_at IS NULL ORDER BY id LIMIT 1");
            $stmt->execute([$parent_org_id]);
            $created_by = (int)$stmt->fetchColumn();
            if (!$created_by) {
                $stmt = $pdo->prepare("SELECT id FROM users WHERE org_id = ? AND role = 'admin' AND deleted_at IS NULL ORDER BY id LIMIT 1");
                $stmt->execute([$org_id]);
                $created_by = (int)$stmt->fetchColumn();
            }

            // Position
            $stmt = $pdo->prepare("SELECT COALESCE(MAX(position), 0) + 1 FROM channels WHERE org_id = ?");
            $stmt->execute([$org_id]);
            $position = (int)$stmt->fetchColumn();

            $name = mb_substr("Suivi · " . $mairie_name, 0, 80);
            $slug = "mairie-" . $parent_org_id;
            $description = mb_substr("Conversation entre " . $org_name . " et " . $mairie_name . ".", 0, 500);

            $pdo->prepare("
                INSERT INTO channels (org_id, parent_org_id, created_by, name, slug, description, icon, color_theme, type, is_mairie_channel, position, is_archived, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, '🏛️', 'amber', 'private', 1, ?, 0, NOW(), NOW())
            ")->execute([$org_id, $parent_org_id, $created_by ?: null, $name, $slug, $description, $position]);

            $channel_id = (int)$pdo->lastInsertId();
        } else {
            // Réactiver si archivé
            $pdo->prepare("UPDATE channels SET is_archived = 0, updated_at = NOW() WHERE id = ?")->execute([$channel_id]);
        }

        // Synchroniser les membres
        sync_mairie_channel_members($pdo, $channel_id, $org_id, $parent_org_id);

        return $channel_id;
    }

    /**
     * Ajoute (sans dupliquer) tous les agents mairie + admins asso au channel.
     */
    function sync_mairie_channel_members(PDO $pdo, int $channel_id, int $org_id, int $parent_org_id): int
    {
        $stmt = $pdo->prepare("
            (SELECT id, 'moderator' AS member_role FROM users WHERE parent_org_id = ? AND deleted_at IS NULL)
            UNION
            (SELECT id, 'member' AS member_role FROM users WHERE org_id = ? AND role = 'admin' AND deleted_at IS NULL)
        ");
        $stmt->execute([$parent_org_id, $org_id]);
        $candidates = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $added = 0;
        foreach ($candidates as $c) {
            $check = $pdo->prepare("SELECT id FROM channel_members WHERE channel_id = ? AND user_id = ?");
            $check->execute([$channel_id, $c['id']]);
            if ($check->fetchColumn()) continue;

            $pdo->prepare("INSERT INTO channel_members (channel_id, user_id, role, joined_at) VALUES (?, ?, ?, NOW())")
                ->execute([$channel_id, $c['id'], $c['member_role']]);
            $added++;
        }
        return $added;
    }

    /**
     * Quand on délie une asso, archive le channel et retire les agents mairie
     * (les admins asso restent par sécurité historique).
     */
    function archive_mairie_channel_for_org(PDO $pdo, int $org_id, int $parent_org_id): bool
    {
        $stmt = $pdo->prepare("SELECT id FROM channels WHERE org_id = ? AND parent_org_id = ? AND is_mairie_channel = 1 LIMIT 1");
        $stmt->execute([$org_id, $parent_org_id]);
        $channel_id = (int)$stmt->fetchColumn();
        if (!$channel_id) return false;

        $pdo->prepare("UPDATE channels SET is_archived = 1, updated_at = NOW() WHERE id = ?")->execute([$channel_id]);

        // Retirer les agents mairie (mais garder l'historique des messages)
        $pdo->prepare("
            DELETE cm FROM channel_members cm
            INNER JOIN users u ON u.id = cm.user_id
            WHERE cm.channel_id = ? AND u.parent_org_id = ?
        ")->execute([$channel_id, $parent_org_id]);

        return true;
    }

    /**
     * Quand on crée un nouvel agent mairie, l'ajoute à tous les channels existants
     * de sa mairie. Retourne le nombre de channels où il a été ajouté.
     */
    function add_mairie_agent_to_channels(PDO $pdo, int $user_id, int $parent_org_id): int
    {
        $stmt = $pdo->prepare("SELECT id FROM channels WHERE parent_org_id = ? AND is_mairie_channel = 1 AND is_archived = 0");
        $stmt->execute([$parent_org_id]);
        $channels = $stmt->fetchAll(PDO::FETCH_COLUMN);

        $added = 0;
        foreach ($channels as $cid) {
            $check = $pdo->prepare("SELECT id FROM channel_members WHERE channel_id = ? AND user_id = ?");
            $check->execute([$cid, $user_id]);
            if ($check->fetchColumn()) continue;

            $pdo->prepare("INSERT INTO channel_members (channel_id, user_id, role, joined_at) VALUES (?, ?, 'moderator', NOW())")
                ->execute([$cid, $user_id]);
            $added++;
        }
        return $added;
    }
}
