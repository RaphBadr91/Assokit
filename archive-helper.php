<?php
/**
 * ============================================================
 * ASSOKIT — Helper pour la gestion des archives
 * ============================================================
 * Fonctions :
 *   - archive_folder()         : archive un dossier + ses projets
 *   - restore_folder()         : restaure un dossier archive
 *   - archive_log()            : log une action dans archive_logs
 *   - get_archive_days_left()  : jours avant purge definitive (max 30)
 * ============================================================
 */

if (!defined('ARCHIVE_RETENTION_DAYS')) {
    define('ARCHIVE_RETENTION_DAYS', 30);
}

/**
 * Archive un dossier et tous ses projets.
 * Si des projets sont actifs, ils sont archives avec un flag special.
 * @return array ['ok'=>bool, 'nb_projects'=>int, 'nb_active'=>int, 'error'=>?string]
 */
function archive_folder(PDO $pdo, int $folder_id, int $admin_user_id): array
{
    try {
        $pdo->beginTransaction();

        // Verifier dossier existe et non deja archive
        $stmt = $pdo->prepare("SELECT id, name, org_id, archived_at FROM folders WHERE id = ? LIMIT 1");
        $stmt->execute([$folder_id]);
        $folder = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$folder) {
            $pdo->rollBack();
            return ['ok' => false, 'error' => 'folder_not_found'];
        }
        if (!empty($folder['archived_at'])) {
            $pdo->rollBack();
            return ['ok' => false, 'error' => 'already_archived'];
        }

        // Compter les projets actifs
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM projects
            WHERE folder_id = ? AND archived_at IS NULL AND status IN ('active','warning')
        ");
        $stmt->execute([$folder_id]);
        $nb_active = (int) $stmt->fetchColumn();

        // Compter total projets (actifs + finis) non encore archives
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM projects WHERE folder_id = ? AND archived_at IS NULL");
        $stmt->execute([$folder_id]);
        $nb_total = (int) $stmt->fetchColumn();

        // Archiver les projets du dossier (via_folder = 1 pour tracer)
        $stmt = $pdo->prepare("
            UPDATE projects
            SET archived_at = NOW(), archived_by_user_id = ?, archived_via_folder = 1
            WHERE folder_id = ? AND archived_at IS NULL
        ");
        $stmt->execute([$admin_user_id, $folder_id]);

        // Archiver le dossier
        $stmt = $pdo->prepare("
            UPDATE folders
            SET archived_at = NOW(), archived_by_user_id = ?, archived_with_active_projects = ?
            WHERE id = ?
        ");
        $stmt->execute([$admin_user_id, $nb_active > 0 ? 1 : 0, $folder_id]);

        // Log
        $action = $nb_active > 0 ? 'archive_folder_with_active' : 'archive_folder';
        archive_log($pdo, (int)$folder['org_id'], $action, 'folder', $folder_id, $folder['name'], $admin_user_id, [
            'total_projects' => $nb_total,
            'active_projects' => $nb_active,
        ]);

        $pdo->commit();
        return [
            'ok' => true,
            'nb_projects' => $nb_total,
            'nb_active' => $nb_active,
        ];
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('archive_folder error: ' . $e->getMessage());
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Restaure un dossier archive (possible dans les 30 jours).
 * Restaure aussi ses projets archives via_folder.
 */
function restore_folder(PDO $pdo, int $folder_id, int $admin_user_id): array
{
    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("SELECT id, name, org_id, archived_at FROM folders WHERE id = ? LIMIT 1");
        $stmt->execute([$folder_id]);
        $folder = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$folder) {
            $pdo->rollBack();
            return ['ok' => false, 'error' => 'folder_not_found'];
        }
        if (empty($folder['archived_at'])) {
            $pdo->rollBack();
            return ['ok' => false, 'error' => 'not_archived'];
        }

        // Verifier delai de retention (30 jours max)
        $days_since = (time() - strtotime($folder['archived_at'])) / 86400;
        if ($days_since > ARCHIVE_RETENTION_DAYS) {
            $pdo->rollBack();
            return ['ok' => false, 'error' => 'retention_expired'];
        }

        // Restaurer les projets qui ont ete archives VIA ce dossier
        $stmt = $pdo->prepare("
            UPDATE projects
            SET archived_at = NULL, archived_by_user_id = NULL, archived_via_folder = 0
            WHERE folder_id = ? AND archived_via_folder = 1
        ");
        $stmt->execute([$folder_id]);

        // Restaurer le dossier
        $stmt = $pdo->prepare("
            UPDATE folders
            SET archived_at = NULL, archived_by_user_id = NULL, archived_with_active_projects = 0
            WHERE id = ?
        ");
        $stmt->execute([$folder_id]);

        archive_log($pdo, (int)$folder['org_id'], 'restore_folder', 'folder', $folder_id, $folder['name'], $admin_user_id, []);

        $pdo->commit();
        return ['ok' => true];
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('restore_folder error: ' . $e->getMessage());
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Restaure un projet archive individuellement.
 */
function restore_project(PDO $pdo, int $project_id, int $admin_user_id): array
{
    try {
        $stmt = $pdo->prepare("
            SELECT p.id, p.name, p.archived_at, p.folder_id, f.name AS folder_name, f.archived_at AS folder_archived, f.org_id
            FROM projects p
            JOIN folders f ON p.folder_id = f.id
            WHERE p.id = ? LIMIT 1
        ");
        $stmt->execute([$project_id]);
        $proj = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$proj) return ['ok' => false, 'error' => 'project_not_found'];
        if (empty($proj['archived_at'])) return ['ok' => false, 'error' => 'not_archived'];
        if (!empty($proj['folder_archived'])) return ['ok' => false, 'error' => 'folder_still_archived'];

        $days_since = (time() - strtotime($proj['archived_at'])) / 86400;
        if ($days_since > ARCHIVE_RETENTION_DAYS) {
            return ['ok' => false, 'error' => 'retention_expired'];
        }

        $stmt = $pdo->prepare("
            UPDATE projects
            SET archived_at = NULL, archived_by_user_id = NULL, archived_via_folder = 0
            WHERE id = ?
        ");
        $stmt->execute([$project_id]);

        archive_log($pdo, (int)$proj['org_id'], 'restore_project', 'project', $project_id, $proj['name'], $admin_user_id, []);

        return ['ok' => true];
    } catch (Throwable $e) {
        error_log('restore_project error: ' . $e->getMessage());
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Log une action dans archive_logs.
 */
function archive_log(PDO $pdo, int $org_id, string $action, string $target_type, int $target_id, string $target_name, int $admin_user_id, array $details = []): void
{
    try {
        // Recuperer nom admin
        $stmt = $pdo->prepare("SELECT first_name, last_name FROM users WHERE id = ?");
        $stmt->execute([$admin_user_id]);
        $admin = $stmt->fetch(PDO::FETCH_ASSOC);
        $admin_name = $admin ? trim(($admin['first_name'] ?? '') . ' ' . ($admin['last_name'] ?? '')) : '';

        $stmt = $pdo->prepare("
            INSERT INTO archive_logs
                (org_id, action, target_type, target_id, target_name, admin_user_id, admin_name, details, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([
            $org_id, $action, $target_type, $target_id, $target_name,
            $admin_user_id, $admin_name,
            !empty($details) ? json_encode($details) : null,
        ]);
    } catch (Throwable $e) {
        error_log('archive_log error: ' . $e->getMessage());
    }
}

/**
 * Calcule le nombre de jours restants avant purge definitive.
 * Retourne un int >= 0 (0 = purge aujourd'hui/demain).
 */
function get_archive_days_left(string $archived_at): int
{
    $purge_at = strtotime($archived_at) + (ARCHIVE_RETENTION_DAYS * 86400);
    $diff = ($purge_at - time()) / 86400;
    return max(0, (int) ceil($diff));
}
