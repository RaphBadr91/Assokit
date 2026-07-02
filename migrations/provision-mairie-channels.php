<?php
require __DIR__ . '/../config.php';

echo "=== Provisionnement des channels mairie ↔ asso ===\n\n";

// 1. Récupérer toutes les asso liées à une mairie
$orgs = $pdo->query("
    SELECT o.id AS org_id, o.name AS org_name, o.parent_org_id, p.name AS mairie_name
    FROM organizations o
    INNER JOIN parent_orgs p ON p.id = o.parent_org_id
    WHERE o.parent_org_id IS NOT NULL
")->fetchAll(PDO::FETCH_ASSOC);

if (!$orgs) {
    echo "Aucune asso liée à une mairie. Rien à faire.\n";
    exit;
}

echo "Asso liées trouvées : " . count($orgs) . "\n\n";

foreach ($orgs as $o) {
    echo "▶ Org #{$o['org_id']} \"{$o['org_name']}\" ↔ Mairie #{$o['parent_org_id']} \"{$o['mairie_name']}\"\n";

    // 2. Vérifier si channel mairie existe déjà
    $stmt = $pdo->prepare("SELECT id FROM channels WHERE org_id = ? AND is_mairie_channel = 1 LIMIT 1");
    $stmt->execute([$o['org_id']]);
    $channel_id = $stmt->fetchColumn();

    if ($channel_id) {
        echo "  ⚠️  Channel mairie existe déjà (#$channel_id) — skip création\n";
    } else {
        // 3. Trouver un agent mairie pour created_by (sinon first admin asso)
        $stmt = $pdo->prepare("SELECT id FROM users WHERE parent_org_id = ? AND deleted_at IS NULL ORDER BY id LIMIT 1");
        $stmt->execute([$o['parent_org_id']]);
        $created_by = $stmt->fetchColumn();

        if (!$created_by) {
            $stmt = $pdo->prepare("SELECT id FROM users WHERE org_id = ? AND role = 'admin' AND deleted_at IS NULL ORDER BY id LIMIT 1");
            $stmt->execute([$o['org_id']]);
            $created_by = $stmt->fetchColumn();
        }

        // 4. Position : max + 1 dans l'asso
        $stmt = $pdo->prepare("SELECT COALESCE(MAX(position), 0) + 1 FROM channels WHERE org_id = ?");
        $stmt->execute([$o['org_id']]);
        $position = $stmt->fetchColumn();

        // 5. Créer le channel
        $name = "Suivi · " . $o['mairie_name'];
        $name = mb_substr($name, 0, 80);
        $slug = "mairie-{$o['parent_org_id']}";
        $description = "Conversation entre " . $o['org_name'] . " et " . $o['mairie_name'] . ".";
        $description = mb_substr($description, 0, 500);

        $pdo->prepare("
            INSERT INTO channels (org_id, parent_org_id, created_by, name, slug, description, icon, color_theme, type, is_mairie_channel, position, is_archived, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, '🏛️', 'amber', 'private', 1, ?, 0, NOW(), NOW())
        ")->execute([$o['org_id'], $o['parent_org_id'], $created_by, $name, $slug, $description, $position]);

        $channel_id = (int)$pdo->lastInsertId();
        echo "  ✅ Channel #$channel_id créé : \"$name\" (slug=$slug, créé par user #$created_by)\n";
    }

    // 6. Ajouter membres : tous agents mairie + tous admins asso
    $stmt = $pdo->prepare("
        (SELECT id, 'moderator' AS member_role, 'agent_mairie' AS source FROM users WHERE parent_org_id = ? AND deleted_at IS NULL)
        UNION
        (SELECT id, 'member' AS member_role, 'admin_asso' AS source FROM users WHERE org_id = ? AND role = 'admin' AND deleted_at IS NULL)
    ");
    $stmt->execute([$o['parent_org_id'], $o['org_id']]);
    $candidates = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $added = 0;
    foreach ($candidates as $c) {
        $stmt = $pdo->prepare("SELECT id FROM channel_members WHERE channel_id = ? AND user_id = ?");
        $stmt->execute([$channel_id, $c['id']]);
        if ($stmt->fetchColumn()) continue;

        $pdo->prepare("INSERT INTO channel_members (channel_id, user_id, role, joined_at) VALUES (?, ?, ?, NOW())")
            ->execute([$channel_id, $c['id'], $c['member_role']]);
        $added++;
        echo "    + user #{$c['id']} [{$c['source']}] role={$c['member_role']}\n";
    }

    if (!$added) echo "  ℹ️  Aucun nouveau membre (tous déjà présents)\n";
    echo "\n";
}

echo "=== Bilan ===\n";
foreach ($pdo->query("SELECT c.id, c.name, c.slug, c.org_id, c.parent_org_id, (SELECT COUNT(*) FROM channel_members WHERE channel_id = c.id) AS nb_members FROM channels c WHERE c.is_mairie_channel = 1") as $c) {
    printf("  Channel #%d %-40s [org=%d, mairie=%d] %d membres\n", $c['id'], $c['name'], $c['org_id'], $c['parent_org_id'], $c['nb_members']);
}
