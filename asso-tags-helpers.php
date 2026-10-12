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

/** Table de chaque type d'élément taguable (toutes portent org_id). */
function ak_tag_entity_tables(): array {
    return ['invoice' => 'asso_invoices', 'quote' => 'asso_quotes', 'client' => 'asso_clients'];
}

/** L'élément (facture, devis, client) appartient-il à l'association ? */
function ak_tag_entity_in_org(PDO $pdo, int $org_id, string $entity_type, int $entity_id): bool {
    $table = ak_tag_entity_tables()[$entity_type] ?? null;
    if (!$table || $entity_id <= 0) return false;
    $stmt = $pdo->prepare("SELECT 1 FROM `$table` WHERE id = ? AND org_id = ? LIMIT 1");
    $stmt->execute([$entity_id, $org_id]);
    return (bool)$stmt->fetchColumn();
}

/** Ne garde, parmi $tag_ids, que les tags de l'association. */
function ak_tag_filter_org(PDO $pdo, int $org_id, array $tag_ids): array {
    $ids = array_values(array_unique(array_filter(array_map('intval', $tag_ids), fn($v) => $v > 0)));
    if (!$ids) return [];
    $in = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("SELECT id FROM asso_tags WHERE org_id = ? AND id IN ($in)");
    $stmt->execute(array_merge([$org_id], $ids));
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

/**
 * Lie un tag à un élément. Avec $org_id, le tag ET l'élément doivent appartenir
 * à cette association (sinon rien n'est écrit).
 */
function ak_tag_link(PDO $pdo, int $tag_id, string $entity_type, int $entity_id, ?int $org_id = null): void {
    if (!in_array($entity_type, ['invoice','quote','client'], true)) return;
    if ($org_id !== null && (!ak_tag_filter_org($pdo, $org_id, [$tag_id]) || !ak_tag_entity_in_org($pdo, $org_id, $entity_type, $entity_id))) return;
    $stmt = $pdo->prepare("INSERT IGNORE INTO asso_tag_links (tag_id, entity_type, entity_id) VALUES (:t, :et, :ei)");
    $stmt->execute([':t' => $tag_id, ':et' => $entity_type, ':ei' => $entity_id]);
}

function ak_tag_unlink(PDO $pdo, int $tag_id, string $entity_type, int $entity_id): void {
    $stmt = $pdo->prepare("DELETE FROM asso_tag_links WHERE tag_id = :t AND entity_type = :et AND entity_id = :ei");
    $stmt->execute([':t' => $tag_id, ':et' => $entity_type, ':ei' => $entity_id]);
}

/**
 * Remplace les tags d'un élément. L'élément doit appartenir à l'association et seuls
 * ses tags à elle sont posés (un id de tag d'une autre association est ignoré).
 */
function ak_tag_set_for_entity(PDO $pdo, int $org_id, string $entity_type, int $entity_id, array $tag_ids): void {
    if (!ak_tag_entity_in_org($pdo, $org_id, $entity_type, $entity_id)) return;
    $tag_ids = ak_tag_filter_org($pdo, $org_id, $tag_ids);
    $pdo->prepare("DELETE FROM asso_tag_links WHERE entity_type = :et AND entity_id = :ei AND tag_id IN (SELECT id FROM asso_tags WHERE org_id = :o)")
        ->execute([':et' => $entity_type, ':ei' => $entity_id, ':o' => $org_id]);
    foreach ($tag_ids as $tid) ak_tag_link($pdo, $tid, $entity_type, $entity_id);
}

/**
 * Sélecteur de tags à placer dans un formulaire de facture, devis ou client.
 * À l'enregistrement : ak_tag_save_from_post($pdo, $org_id, $type, $id, $_POST).
 */
function ak_tag_picker_html(PDO $pdo, int $org_id, string $entity_type, ?int $entity_id = null, string $label = 'Tags'): string {
    if (!isset(ak_tag_entity_tables()[$entity_type])) return '';
    try { $tags = ak_tag_list($pdo, $org_id); } catch (Throwable $e) { return ''; }
    $on = [];
    if ($entity_id) {
        foreach (ak_tag_get_for_entity($pdo, $entity_type, $entity_id) as $t) $on[(int)$t['id']] = true;
    }
    $h = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    $html = '<div class="ak-tag-picker" style="margin:10px 0;">'
          . '<input type="hidden" name="ak_tags_present" value="1">'
          . '<div style="font-size:12px; font-weight:600; color:#374151; margin-bottom:6px;">' . $h($label) . '</div>';
    if (!$tags) {
        return $html . '<div style="font-size:12.5px; color:#6B7280;">Aucun tag pour le moment. <a href="/mon-asso-tags">Créer des tags</a></div></div>';
    }
    $html .= '<div style="display:flex; gap:6px; flex-wrap:wrap;">';
    foreach ($tags as $t) {
        $c = preg_match('/^#[0-9a-f]{6}$/i', (string)$t['color']) ? $t['color'] : '#6B7280';
        $html .= '<label style="display:inline-flex; align-items:center; gap:5px; padding:4px 10px; border-radius:12px; font-size:12px; font-weight:600; cursor:pointer; background:' . $c . '22; color:' . $c . '; border:1px solid ' . $c . '44;">'
               . '<input type="checkbox" name="ak_tag_ids[]" value="' . (int)$t['id'] . '"' . (isset($on[(int)$t['id']]) ? ' checked' : '') . ' style="margin:0;">'
               . $h($t['name']) . '</label>';
    }
    return $html . '</div></div>';
}

/** Enregistre les tags cochés dans ak_tag_picker_html() ; sans effet si le formulaire n'avait pas le sélecteur. */
function ak_tag_save_from_post(PDO $pdo, int $org_id, string $entity_type, int $entity_id, array $post): void {
    if (empty($post['ak_tags_present'])) return;
    ak_tag_set_for_entity($pdo, $org_id, $entity_type, $entity_id, (array)($post['ak_tag_ids'] ?? []));
}

/**
 * Éléments de l'association auxquels un tag peut être attribué depuis /mon-asso-tags
 * (les plus récents d'abord pour les factures et devis). [['id','label','sub'], ...]
 */
function ak_tag_assignable(PDO $pdo, int $org_id, string $entity_type, int $limit = 500): array {
    $limit = max(1, min(2000, $limit));
    try {
        if ($entity_type === 'client') {
            $stmt = $pdo->prepare("SELECT id, display_name AS label, email AS sub FROM asso_clients
                                   WHERE org_id = ? AND deleted_at IS NULL ORDER BY display_name ASC, id ASC LIMIT $limit");
        } elseif ($entity_type === 'invoice' || $entity_type === 'quote') {
            [$t, $num] = $entity_type === 'invoice' ? ['asso_invoices', 'invoice_number'] : ['asso_quotes', 'quote_number'];
            $stmt = $pdo->prepare("SELECT d.id, d.`$num` AS num, d.issued_at, d.amount_ttc_cents, d.currency, c.display_name AS client
                                   FROM `$t` d LEFT JOIN asso_clients c ON c.id = d.client_id AND c.org_id = d.org_id
                                   WHERE d.org_id = ? ORDER BY d.issued_at DESC, d.id DESC LIMIT $limit");
            $stmt->execute([$org_id]);
            $out = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $cur = strtoupper((string)($r['currency'] ?? '')) ?: 'EUR';
                $sub = array_filter([
                    (string)($r['client'] ?? ''),
                    !empty($r['issued_at']) ? date('d/m/Y', strtotime($r['issued_at'])) : '',
                    number_format((int)($r['amount_ttc_cents'] ?? 0) / 100, 2, ',', ' ') . ' ' . ($cur === 'EUR' ? '€' : $cur),
                ], fn($v) => $v !== '');
                $out[] = ['id' => (int)$r['id'], 'label' => trim((string)$r['num']) !== '' ? (string)$r['num'] : 'Brouillon #' . (int)$r['id'], 'sub' => implode(' · ', $sub)];
            }
            return $out;
        } else {
            return [];
        }
        $stmt->execute([$org_id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('[asso-tags] ak_tag_assignable(' . $entity_type . ') : ' . $e->getMessage());
        return [];
    }
}

/**
 * Attribution depuis /mon-asso-tags : pour ce tag et ce type, les éléments cochés
 * parmi $shown_ids (ceux affichés à l'écran) sont liés, les autres affichés sont déliés.
 * Les éléments non affichés (au-delà de la limite) ne sont pas touchés.
 */
function ak_tag_set_entities_for_tag(PDO $pdo, int $org_id, int $tag_id, string $entity_type, array $checked_ids, array $shown_ids): void {
    if (!isset(ak_tag_entity_tables()[$entity_type]) || !ak_tag_filter_org($pdo, $org_id, [$tag_id])) return;
    $shown = array_values(array_unique(array_map('intval', $shown_ids)));
    if (!$shown) return;
    $checked = array_values(array_intersect(array_unique(array_map('intval', $checked_ids)), $shown));
    $off = array_values(array_diff($shown, $checked));
    if ($off) {
        $in = implode(',', array_fill(0, count($off), '?'));
        $pdo->prepare("DELETE FROM asso_tag_links WHERE tag_id = ? AND entity_type = ? AND entity_id IN ($in)")
            ->execute(array_merge([$tag_id, $entity_type], $off));
    }
    if ($checked) {
        $stmt = $pdo->prepare("INSERT IGNORE INTO asso_tag_links (tag_id, entity_type, entity_id) VALUES (?, ?, ?)");
        foreach ($checked as $eid) $stmt->execute([$tag_id, $entity_type, $eid]);
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
