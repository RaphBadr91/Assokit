<?php
/**
 * asso-tags-helpers.php — Helpers tags
 */

if (!function_exists('ak_tag_slug')) {

function ak_tag_slug(string $name): string {
    $s = strtolower(trim($name));
    $s = preg_replace('/[éèêë]/u', 'e', $s);
    $s = preg_replace('/[àâä]/u', 'a', $s);
    $s = preg_replace('/[ùûü]/u', 'u', $s);
    $s = preg_replace('/[ôö]/u', 'o', $s);
    $s = preg_replace('/[îï]/u', 'i', $s);
    $s = preg_replace('/[ç]/u', 'c', $s);
    $s = preg_replace('/[^a-z0-9]+/', '-', $s);
    return trim($s, '-') ?: 'tag';
}

function ak_tag_create(PDO $pdo, int $org_id, string $name, string $color = '#6B7280', ?int $created_by = null): int {
    $slug = ak_tag_slug($name);
    $stmt = $pdo->prepare("SELECT id FROM asso_tags WHERE org_id = :o AND slug = :s LIMIT 1");
    $stmt->execute([':o' => $org_id, ':s' => $slug]);
    $exists = $stmt->fetchColumn();
    if ($exists) return (int)$exists;

    $stmt = $pdo->prepare("INSERT INTO asso_tags (org_id, name, slug, color, created_by_user_id) VALUES (:o, :n, :s, :c, :u)");
    $stmt->execute([
        ':o' => $org_id,
        ':n' => mb_substr($name, 0, 80),
        ':s' => $slug,
        ':c' => $color,
        ':u' => $created_by,
    ]);
    return (int) $pdo->lastInsertId();
}

function ak_tag_list(PDO $pdo, int $org_id): array {
    $stmt = $pdo->prepare("SELECT * FROM asso_tags WHERE org_id = :o ORDER BY name");
    $stmt->execute([':o' => $org_id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function ak_tag_link(PDO $pdo, int $tag_id, string $entity_type, int $entity_id): void {
    if (!in_array($entity_type, ['invoice','quote','client'], true)) return;
    $stmt = $pdo->prepare("INSERT IGNORE INTO asso_tag_links (tag_id, entity_type, entity_id) VALUES (:t, :et, :ei)");
    $stmt->execute([':t' => $tag_id, ':et' => $entity_type, ':ei' => $entity_id]);
}

function ak_tag_unlink(PDO $pdo, int $tag_id, string $entity_type, int $entity_id): void {
    $stmt = $pdo->prepare("DELETE FROM asso_tag_links WHERE tag_id = :t AND entity_type = :et AND entity_id = :ei");
    $stmt->execute([':t' => $tag_id, ':et' => $entity_type, ':ei' => $entity_id]);
}

function ak_tag_set_for_entity(PDO $pdo, int $org_id, string $entity_type, int $entity_id, array $tag_ids): void {
    $pdo->prepare("DELETE FROM asso_tag_links WHERE entity_type = :et AND entity_id = :ei AND tag_id IN (SELECT id FROM asso_tags WHERE org_id = :o)")
        ->execute([':et' => $entity_type, ':ei' => $entity_id, ':o' => $org_id]);
    foreach ($tag_ids as $tid) {
        $tid = (int)$tid;
        if ($tid > 0) ak_tag_link($pdo, $tid, $entity_type, $entity_id);
    }
}

function ak_tag_get_for_entity(PDO $pdo, string $entity_type, int $entity_id): array {
    $stmt = $pdo->prepare("
        SELECT t.* FROM asso_tags t
        JOIN asso_tag_links l ON l.tag_id = t.id
        WHERE l.entity_type = :et AND l.entity_id = :ei
        ORDER BY t.name
    ");
    $stmt->execute([':et' => $entity_type, ':ei' => $entity_id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function ak_tag_get_for_entities(PDO $pdo, string $entity_type, array $entity_ids): array {
    if (empty($entity_ids)) return [];
    $entity_ids = array_map('intval', $entity_ids);
    $in = implode(',', $entity_ids);
    $stmt = $pdo->prepare("
        SELECT l.entity_id, t.id, t.name, t.color, t.slug
        FROM asso_tags t
        JOIN asso_tag_links l ON l.tag_id = t.id
        WHERE l.entity_type = :et AND l.entity_id IN ($in)
    ");
    $stmt->execute([':et' => $entity_type]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $out = [];
    foreach ($rows as $r) {
        $out[(int)$r['entity_id']][] = $r;
    }
    return $out;
}

function ak_tag_render_chips(array $tags): string {
    if (empty($tags)) return '';
    $html = '<div style="display:flex; gap:4px; flex-wrap:wrap; margin-top:4px;">';
    foreach ($tags as $t) {
        $color = $t['color'] ?? '#6B7280';
        $html .= '<span style="display:inline-block; padding:2px 8px; border-radius:10px; font-size:10px; font-weight:600; background:' . htmlspecialchars($color) . '22; color:' . htmlspecialchars($color) . '; border:1px solid ' . htmlspecialchars($color) . '44;">' . htmlspecialchars($t['name']) . '</span>';
    }
    $html .= '</div>';
    return $html;
}

}
