<?php
/**
 * verif-sql-app.php — Contrôle des requêtes des nouvelles API de l'app.
 *
 * À lancer EN LIGNE DE COMMANDE sur le serveur :
 *     php verif-sql-app.php
 *
 * Rejoue chaque requête SELECT des API ajoutées pour l'application, contre la
 * vraie base. Aucune écriture : ni UPDATE, ni DELETE, ni INSERT. Le script est
 * donc sans risque et peut être relancé autant de fois que nécessaire.
 *
 * Il répond à une question que l'analyse du code ne peut pas trancher : les
 * colonnes existent-elles vraiment, avec les bons types, dans CETTE base ?
 *
 * Supprimez ce fichier une fois le contrôle passé — il n'a rien à faire en
 * production sur la durée.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Ce script s'exécute uniquement en ligne de commande.\n");
}

require_once __DIR__ . '/config.php';

$org = 0;
try {
    $org = (int) $pdo->query("SELECT id FROM organizations ORDER BY id ASC LIMIT 1")->fetchColumn();
} catch (Throwable $e) {
    exit("Impossible de lire la table organizations : " . $e->getMessage() . "\n");
}
if ($org <= 0) exit("Aucune organisation en base : rien à contrôler.\n");

$uid = 0;
try {
    $st = $pdo->prepare("SELECT id FROM users WHERE org_id = ? ORDER BY id ASC LIMIT 1");
    $st->execute([$org]);
    $uid = (int) $st->fetchColumn();
} catch (Throwable $e) {}

echo "Organisation de test : #$org   ·   utilisateur : #$uid\n";
echo str_repeat('─', 78), "\n";

/** [libellé, requête, paramètres] */
$tests = [
    ['dashboard · projets actifs',
     "SELECT COUNT(*) FROM projects p JOIN folders f ON p.folder_id = f.id
      WHERE f.org_id = ? AND p.status IN ('active','warning')
        AND p.archived_at IS NULL AND f.archived_at IS NULL", [$org]],

    ['dashboard · membres',
     "SELECT COUNT(*) FROM users WHERE org_id = ? AND is_active = 1
        AND (deleted_at IS NULL OR deleted_at = '')", [$org]],

    ['dashboard · événements à venir',
     "SELECT COUNT(*) FROM events WHERE org_id = ? AND starts_at >= CURDATE() AND deleted_at IS NULL", [$org]],

    ['dashboard · activité 30 j (journal)',
     "SELECT DATE(pal.created_at) AS day, COUNT(*) AS cnt
      FROM project_activity_log pal
      JOIN projects p ON pal.project_id = p.id
      JOIN folders f ON p.folder_id = f.id
      WHERE f.org_id = ? AND pal.created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
      GROUP BY DATE(pal.created_at)", [$org]],

    ['dashboard · activité 30 j (repli messages)',
     "SELECT DATE(m.created_at) AS day, COUNT(*) AS cnt
      FROM project_messages m
      JOIN projects p ON m.project_id = p.id
      JOIN folders f ON p.folder_id = f.id
      WHERE f.org_id = ? AND m.created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
      GROUP BY DATE(m.created_at)", [$org]],

    ['dashboard · répartition par statut',
     "SELECT p.status, COUNT(*) AS cnt FROM projects p JOIN folders f ON p.folder_id = f.id
      WHERE f.org_id = ? AND p.archived_at IS NULL AND f.archived_at IS NULL
      GROUP BY p.status", [$org]],

    ['dashboard · étapes assignées',
     "SELECT ps.title, p.id AS pid, p.name AS pname
      FROM project_steps ps
      JOIN projects p ON ps.project_id = p.id
      JOIN folders f ON p.folder_id = f.id
      WHERE f.org_id = ? AND ps.assigned_to_user_id = ? AND ps.is_completed = 0
        AND p.archived_at IS NULL AND f.archived_at IS NULL
      ORDER BY ps.position ASC LIMIT 5", [$org, $uid]],

    ['dashboard · projets ≥ 75 %',
     "SELECT p.id, p.name, p.progress_percent FROM projects p JOIN folders f ON p.folder_id = f.id
      WHERE f.org_id = ? AND p.status IN ('active','warning') AND p.progress_percent >= 75
        AND p.archived_at IS NULL AND f.archived_at IS NULL
      ORDER BY p.progress_percent DESC LIMIT 5", [$org]],

    ['dashboard · projet au repos',
     "SELECT p.id, p.name FROM projects p JOIN folders f ON p.folder_id = f.id
      WHERE f.org_id = ? AND p.status IN ('active','warning')
        AND p.updated_at < DATE_SUB(NOW(), INTERVAL 14 DAY)
        AND p.archived_at IS NULL AND f.archived_at IS NULL
      ORDER BY p.updated_at ASC LIMIT 1", [$org]],

    ['dashboard · prochaine assemblée',
     "SELECT id, title, scheduled_at, location FROM assemblies
      WHERE org_id = ? AND archived_at IS NULL AND status IN ('draft','sent','in_progress')
        AND scheduled_at >= NOW() ORDER BY scheduled_at ASC LIMIT 1", [$org]],

    ['dashboard · subventions à échéance',
     "SELECT id, name, funder, deadline_apply, deadline_report, status FROM grants
      WHERE org_id = ? AND archived_at IS NULL
        AND ((status IN ('draft','submitted','in_review') AND deadline_apply IS NOT NULL
              AND DATEDIFF(deadline_apply, CURDATE()) BETWEEN -7 AND 14)
          OR (status = 'granted' AND deadline_report IS NOT NULL AND reported_at IS NULL
              AND DATEDIFF(deadline_report, CURDATE()) BETWEEN -7 AND 30))
      ORDER BY COALESCE(deadline_apply, deadline_report) ASC LIMIT 4", [$org]],

    ['dashboard · projets en cours',
     "SELECT p.id, p.name, p.progress_percent, p.status, f.name AS folder_name
      FROM projects p JOIN folders f ON p.folder_id = f.id
      WHERE f.org_id = ? AND p.status IN ('active','warning')
        AND p.archived_at IS NULL AND f.archived_at IS NULL
      ORDER BY p.status = 'warning' DESC, p.updated_at DESC LIMIT 6", [$org]],

    ['organisation · lecture des réglages',
     "SELECT name, legal_name, legal_form, siren, siret, rna_number,
             billing_address_street, billing_address_complement, billing_address_zip,
             billing_address_city, billing_address_country,
             vat_subject, vat_number, billing_email, billing_phone,
             branding_primary_color, branding_secondary_color
      FROM organizations WHERE id = ? LIMIT 1", [$org]],

    ['sécurité · lecture du hash',
     "SELECT password_hash, must_change_password FROM users WHERE id = ? LIMIT 1", [$uid]],

    ['événement · fiche complète',
     "SELECT e.*, p.name AS project_name, f.color_theme AS folder_color
      FROM events e
      LEFT JOIN projects p ON e.project_id = p.id
      LEFT JOIN folders f ON p.folder_id = f.id
      WHERE e.org_id = ? LIMIT 1", [$org]],

    ['événement · colonnes d’édition',
     "SELECT id, title, description, location, event_type, color_theme, visibility,
             project_id, starts_at, ends_at, is_all_day, created_by, google_event_id, deleted_at
      FROM events WHERE org_id = ? LIMIT 1", [$org]],
];

$ok = 0; $ko = 0;
foreach ($tests as [$label, $sql, $params]) {
    try {
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        printf("  ✅ %-42s %d ligne(s)\n", $label, count($rows));
        $ok++;
    } catch (Throwable $e) {
        printf("  ❌ %-42s %s\n", $label, $e->getMessage());
        $ko++;
    }
}

echo str_repeat('─', 78), "\n";
echo "Requêtes passées : $ok   ·   en échec : $ko\n";
echo $ko === 0
    ? "Toutes les requêtes des nouvelles API fonctionnent sur cette base.\n"
    : "Corrigez les requêtes en échec avant de publier le build.\n";
exit($ko === 0 ? 0 : 1);
