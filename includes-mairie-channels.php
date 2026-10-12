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
     * Appelée à la liaison, mais aussi à chaque ouverture / envoi côté mairie :
     * un admin nommé après le rattachement (changement de bureau) est ainsi rattrapé.
     */
    function sync_mairie_channel_members(PDO $pdo, int $channel_id, int $org_id, int $parent_org_id): int
    {
        $stmt = $pdo->prepare("
            (SELECT id, 'moderator' AS member_role FROM users WHERE parent_org_id = ? AND is_active = 1 AND deleted_at IS NULL)
            UNION
            (SELECT id, 'member' AS member_role FROM users WHERE org_id = ? AND role = 'admin' AND is_active = 1 AND deleted_at IS NULL)
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

    /**
     * Côté association : donne à un admin de l'asso l'accès aux canaux de suivi
     * mairie de son asso (admin nommé après le rattachement). Ne fait rien si
     * l'utilisateur n'est pas admin actif de l'asso. Sans doublon, une seule
     * requête quand il n'y a rien à faire : à appeler au chargement de la messagerie.
     */
    function sync_mairie_channels_for_org_admin(PDO $pdo, int $user_id, int $org_id): int
    {
        if ($user_id <= 0 || $org_id <= 0) return 0;
        $stmt = $pdo->prepare("
            SELECT c.id
            FROM channels c
            INNER JOIN users u ON u.id = ? AND u.org_id = c.org_id AND u.role = 'admin' AND u.is_active = 1 AND u.deleted_at IS NULL
            WHERE c.org_id = ? AND c.is_mairie_channel = 1 AND c.is_archived = 0
              AND NOT EXISTS (SELECT 1 FROM channel_members cm WHERE cm.channel_id = c.id AND cm.user_id = u.id)
        ");
        $stmt->execute([$user_id, $org_id]);
        $added = 0;
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $cid) {
            $pdo->prepare("INSERT INTO channel_members (channel_id, user_id, role, joined_at) VALUES (?, ?, 'member', NOW())")
                ->execute([(int)$cid, $user_id]);
            $added++;
        }
        return $added;
    }
}

// ============================================================
// Comptes des agents de collectivité (cockpit + paramètres mairie)
// ============================================================
if (!function_exists('ak_mairie_agent_create')) {

    /**
     * Le compte n'a été créé que pour la collectivité (pas de rattachement à une
     * association, pas de casquette plateforme) : on peut le désactiver ou le réactiver.
     */
    function ak_mairie_agent_is_dedicated(array $u): bool
    {
        return (int)($u['org_id'] ?? 0) <= 0
            && empty($u['is_platform_admin']) && empty($u['is_super_admin']) && empty($u['is_founder'])
            && ($u['role'] ?? '') !== 'super_admin';
    }

    /**
     * Crée le compte d'un agent (ou réactive le compte d'un ancien agent retiré)
     * avec un mot de passe temporaire à changer à la première connexion,
     * l'ajoute aux canaux de suivi et lui envoie une invitation par e-mail.
     * Retourne ['id', 'temp_password', 'reactivated', 'invite_sent'].
     */
    function ak_mairie_agent_create(PDO $pdo, int $parent_org_id, string $first_name, string $last_name, string $email, string $role, ?int $created_by = null): array
    {
        $email = trim($email);
        if ($parent_org_id <= 0) throw new RuntimeException("Collectivité invalide.");
        if ($first_name === '' || $last_name === '' || $email === '') throw new RuntimeException("Prénom, nom et email obligatoires.");
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new RuntimeException("Email invalide.");
        $role = in_array($role, ['admin', 'agent'], true) ? $role : 'agent';
        $first_name = mb_substr($first_name, 0, 100);
        $last_name = mb_substr($last_name, 0, 100);

        $temp_password = bin2hex(random_bytes(4)); // ex: a1b2c3d4
        $hash = password_hash($temp_password, PASSWORD_DEFAULT);

        $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ? LIMIT 1");
        $stmt->execute([$email]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);

        $reactivated = false;
        if ($existing) {
            // Seul un ancien agent retiré (compte dédié, désactivé) peut être ré-invité
            $was_removed = (int)($existing['is_active'] ?? 1) === 0 || !empty($existing['deleted_at']);
            if (!empty($existing['parent_org_id']) || !$was_removed || !ak_mairie_agent_is_dedicated($existing)) {
                throw new RuntimeException("Cet email est déjà utilisé sur la plateforme.");
            }
            $pdo->prepare("
                UPDATE users SET first_name = ?, last_name = ?, password_hash = ?, role = 'admin',
                       parent_org_id = ?, parent_org_role = ?, is_active = 1, must_change_password = 1,
                       deleted_at = NULL, deleted_by_user_id = NULL
                WHERE id = ?
            ")->execute([$first_name, $last_name, $hash, $parent_org_id, $role, (int)$existing['id']]);
            $new_id = (int)$existing['id'];
            $reactivated = true;
        } else {
            $pdo->prepare("
                INSERT INTO users (first_name, last_name, email, password_hash, role, parent_org_id, parent_org_role, is_active, must_change_password, created_by_user_id, created_at)
                VALUES (?, ?, ?, ?, 'admin', ?, ?, 1, 1, ?, NOW())
            ")->execute([$first_name, $last_name, $email, $hash, $parent_org_id, $role, $created_by ?: null]);
            $new_id = (int)$pdo->lastInsertId();
        }

        // Ajoute l'agent à tous les channels mairie existants
        try { add_mairie_agent_to_channels($pdo, $new_id, $parent_org_id); }
        catch (Throwable $e) { error_log('[mairie-agent] channels: ' . $e->getMessage()); }

        $invite_sent = ak_mairie_agent_send_invite($pdo, $new_id, $email, $first_name, $parent_org_id);

        return ['id' => $new_id, 'temp_password' => $temp_password, 'reactivated' => $reactivated, 'invite_sent' => $invite_sent];
    }

    /**
     * E-mail d'invitation : lien (7 jours, usage unique) pour définir son mot de passe.
     */
    function ak_mairie_agent_send_invite(PDO $pdo, int $user_id, string $email, string $first_name, int $parent_org_id): bool
    {
        try {
            require_once __DIR__ . '/password-token-helper.php';
            if (!function_exists('create_password_token') || !function_exists('send_transactional_email')) return false;

            $stmt = $pdo->prepare("SELECT name FROM parent_orgs WHERE id = ?");
            $stmt->execute([$parent_org_id]);
            $mairie_name = (string)$stmt->fetchColumn() ?: 'votre collectivité';

            $token = create_password_token($pdo, $user_id, 'set_password');
            $url = 'https://assokit.fr/definir-mot-de-passe/' . $token;
            $days = defined('PW_TOKEN_EXPIRY_DAYS') ? PW_TOKEN_EXPIRY_DAYS : 7;

            $content = '<h1 style="font-size:22px;margin:0 0 14px;font-weight:500;letter-spacing:-0.01em;color:#1C1917">Bienvenue sur Assokit 🏛️</h1>'
                     . '<p>Bonjour ' . htmlspecialchars($first_name) . ',</p>'
                     . '<p>Un accès à l\'espace <strong>' . htmlspecialchars($mairie_name) . '</strong> vient de vous être ouvert sur Assokit : vous pourrez y suivre les associations rattachées et échanger avec elles.</p>'
                     . '<p>Pour activer votre compte, créez votre mot de passe en cliquant sur le bouton ci-dessous :</p>'
                     . '<div style="background:#ECFDF5;border-left:3px solid #059669;padding:12px 16px;border-radius:6px;margin:16px 0;font-size:13px;color:#065F46;line-height:1.6;">'
                     . '⏰ <strong>Ce lien expire dans ' . (int)$days . ' jours.</strong> Passé ce délai, demandez un nouvel accès à l\'administrateur de votre collectivité.'
                     . '</div>'
                     . '<p style="font-size:12px; color:#78716C; margin-top:18px;">Votre identifiant de connexion : ' . htmlspecialchars($email) . '. Si vous n\'attendiez pas ce message, vous pouvez l\'ignorer.</p>';

            $html = function_exists('email_wrap')
                ? email_wrap('Bienvenue sur Assokit', $content, '🔐 Créer mon mot de passe', $url)
                : $content . '<p><a href="' . $url . '">Créer mon mot de passe</a></p>';

            $res = send_transactional_email($email, 'Votre accès à l\'espace ' . $mairie_name . ' sur Assokit', $html, ['tag' => 'mairie_agent_invite']);
            return !empty($res['success']);
        } catch (Throwable $e) {
            error_log('[mairie-agent] invite: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Retire un agent de sa collectivité. Un compte dédié est désactivé
     * (plus de connexion, sorti de tous les canaux) et pourra être ré-invité ;
     * un compte qui a aussi une autre casquette est seulement détaché.
     * Retourne la ligne de l'agent, ou null s'il n'appartient pas à cette collectivité.
     */
    function ak_mairie_agent_remove(PDO $pdo, int $user_id, int $parent_org_id, ?int $removed_by = null): ?array
    {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? AND parent_org_id = ? LIMIT 1");
        $stmt->execute([$user_id, $parent_org_id]);
        $agent = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$agent) return null;

        if (ak_mairie_agent_is_dedicated($agent)) {
            $pdo->prepare("
                UPDATE users SET is_active = 0, deleted_at = COALESCE(deleted_at, NOW()), deleted_by_user_id = ?,
                       parent_org_id = NULL, parent_org_role = NULL
                WHERE id = ?
            ")->execute([$removed_by ?: null, $user_id]);
            $pdo->prepare("DELETE FROM channel_members WHERE user_id = ?")->execute([$user_id]);
            // Liens d'invitation encore ouverts : inutilisables
            try {
                $pdo->prepare("UPDATE user_password_tokens SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL")->execute([$user_id]);
            } catch (Throwable $e) { error_log('[mairie-agent] tokens: ' . $e->getMessage()); }
        } else {
            $pdo->prepare("UPDATE users SET parent_org_id = NULL, parent_org_role = NULL WHERE id = ?")->execute([$user_id]);
            $pdo->prepare("
                DELETE cm FROM channel_members cm
                INNER JOIN channels c ON c.id = cm.channel_id
                WHERE cm.user_id = ? AND c.parent_org_id = ? AND c.is_mairie_channel = 1
            ")->execute([$user_id, $parent_org_id]);
        }
        return $agent;
    }
}

// ============================================================
// Espace collectivité : libellés, recherche, droits
// ============================================================
if (!function_exists('ak_parent_org_type_label')) {

    /** Libellés des types de collectivité (parent_orgs.type). */
    function ak_parent_org_types(): array
    {
        return [
            'mairie' => 'Mairie', 'departement' => 'Département', 'region' => 'Région',
            'drac' => 'DRAC', 'caf' => 'CAF', 'federation' => 'Fédération', 'autre' => 'Autre',
        ];
    }

    /**
     * Type affiché : « Département » (« Collectivité » pour « autre ») ;
     * $in_sentence = true pour la suite de « votre … » (« département »).
     */
    function ak_parent_org_type_label(?string $type, bool $in_sentence = false): string
    {
        $type = (string)$type;
        $types = ak_parent_org_types();
        $label = ($type !== 'autre' && isset($types[$type])) ? $types[$type] : 'Collectivité';
        if ($in_sentence && $type !== 'drac' && $type !== 'caf') return mb_strtolower($label, 'UTF-8');
        return $label;
    }

    /** Statut du compte collectivité (parent_orgs.status). */
    function ak_parent_org_status_label(?string $status): string
    {
        return [
            'active' => 'Actif', 'pending' => 'En attente', 'suspended' => 'Suspendu', 'rejected' => 'Refusé',
        ][(string)$status] ?? '—';
    }

    /** Statut d'une campagne e-mailing (mairie_campaigns.status). */
    function ak_mairie_campaign_status_label(?string $status): string
    {
        return [
            'draft' => 'Brouillon', 'sending' => 'En cours', 'sent' => 'Envoyée', 'failed' => 'Échec',
        ][(string)$status] ?? '—';
    }

    /** Quota d'associations : 0 = illimité (comme dans action-mairie.php). */
    function ak_parent_org_quota_label($quota): string
    {
        return (int)$quota > 0 ? (string)(int)$quota : '∞';
    }

    /**
     * Texte comparable pour une recherche : minuscules, sans accents ni
     * apostrophe typographique (« Évry », « évry » et « evry » se valent).
     */
    function ak_mairie_fold(?string $s): string
    {
        $s = mb_strtolower(trim((string)$s), 'UTF-8');
        if (class_exists('Normalizer')) {
            $d = Normalizer::normalize($s, Normalizer::FORM_D);
            if ($d !== false) $s = preg_replace('/\p{Mn}+/u', '', $d);
        } else {
            $s = strtr($s, [
                'à' => 'a', 'â' => 'a', 'ä' => 'a', 'á' => 'a', 'ã' => 'a', 'å' => 'a', 'ç' => 'c',
                'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
                'ñ' => 'n', 'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'ö' => 'o', 'õ' => 'o',
                'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ý' => 'y', 'ÿ' => 'y',
            ]);
        }
        $s = strtr($s, ['œ' => 'oe', 'æ' => 'ae', '’' => "'", 'ʼ' => "'", '‘' => "'"]);
        return preg_replace('/\s+/u', ' ', $s);
    }

    /**
     * Rôle de l'utilisateur dans sa collectivité ('admin' | 'agent' | null), relu
     * en base : current_user() ne charge pas toutes les colonnes selon le serveur,
     * et le rôle peut avoir changé depuis la connexion.
     */
    function ak_mairie_user_role(PDO $pdo, ?array $user): ?string
    {
        if (empty($user['id'])) return null;
        try {
            $st = $pdo->prepare("SELECT parent_org_role FROM users WHERE id = ? AND parent_org_id IS NOT NULL LIMIT 1");
            $st->execute([(int)$user['id']]);
            $role = $st->fetchColumn();
            return $role === false || $role === null || $role === '' ? null : (string)$role;
        } catch (Throwable $e) {
            error_log('[mairie-role] ' . $e->getMessage());
            return isset($user['parent_org_role']) ? (string)$user['parent_org_role'] : null;
        }
    }

    /**
     * Gestion des collectivités depuis le cockpit (créer, lier des associations,
     * gérer les agents) : compte plateforme (is_platform_admin) ou fondateur.
     * Ne passe pas par is_platform_admin(), qui ouvre aussi la lecture de
     * toutes les associations.
     */
    function ak_can_manage_mairies(?array $user = null): bool
    {
        if ($user === null && function_exists('current_user')) $user = current_user();
        if (empty($user)) return false;
        if (is_platform_admin($user)) return true;
        require_once __DIR__ . '/platform-flags.php';
        $user = ak_platform_flags($user);
        return !empty($user['is_founder']);
    }

    function ak_require_mairie_manager(): void
    {
        if (!ak_can_manage_mairies()) {
            http_response_code(403);
            die('<h1>⛔ Accès refusé</h1><p>Cette page est réservée au Super Admin Fondateur.</p><p><a href="/dashboard">Retour</a></p>');
        }
    }
}
