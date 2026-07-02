<?php
/**
 * Migration Session 9 — Mairies/Collectivités + Projets
 * Lancement : php migrations/migrate-session9-mairie-projets.php
 * Idempotent : peut être relancé sans erreur.
 */
require_once __DIR__ . '/../config.php';

function table_exists(PDO $pdo, string $t): bool {
    $s = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?");
    $s->execute([$t]); return $s->fetchColumn() > 0;
}
function column_exists(PDO $pdo, string $t, string $c): bool {
    $s = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?");
    $s->execute([$t, $c]); return $s->fetchColumn() > 0;
}
function run(PDO $pdo, string $label, string $sql): void {
    try { $pdo->exec($sql); echo "✅ $label\n"; }
    catch (Throwable $e) { echo "❌ $label : " . $e->getMessage() . "\n"; }
}

echo "=== Migration Session 9 ===\n\n";

// ============================================================
// 1) Table parent_orgs (mairies/collectivités)
// ============================================================
if (!table_exists($pdo, 'parent_orgs')) {
    run($pdo, "Création table parent_orgs", "
        CREATE TABLE parent_orgs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(255) NOT NULL,
            type ENUM('mairie','departement','region','drac','caf','federation','autre') NOT NULL DEFAULT 'mairie',
            siret VARCHAR(20),
            contact_first_name VARCHAR(100),
            contact_last_name VARCHAR(100),
            contact_email VARCHAR(255),
            contact_phone VARCHAR(30),
            address_street VARCHAR(255),
            address_zip VARCHAR(10),
            address_city VARCHAR(100),
            address_complement VARCHAR(255),
            department VARCHAR(100),
            region VARCHAR(100),
            logo_path VARCHAR(255),
            requested_quota INT NOT NULL DEFAULT 0,
            validated_quota INT NOT NULL DEFAULT 0,
            status ENUM('pending','active','suspended','rejected') NOT NULL DEFAULT 'pending',
            created_by INT,
            validated_by INT,
            validated_at DATETIME,
            notes TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_status (status),
            INDEX idx_type (type)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} else { echo "ℹ️  Table parent_orgs déjà présente\n"; }

// ============================================================
// 2) ALTER organizations : ajout parent_org_id
// ============================================================
if (!column_exists($pdo, 'organizations', 'parent_org_id')) {
    run($pdo, "Ajout organizations.parent_org_id",
        "ALTER TABLE organizations ADD COLUMN parent_org_id INT DEFAULT NULL AFTER id, ADD INDEX idx_parent_org (parent_org_id)");
} else { echo "ℹ️  organizations.parent_org_id déjà présent\n"; }

if (!column_exists($pdo, 'organizations', 'parent_org_linked_at')) {
    run($pdo, "Ajout organizations.parent_org_linked_at",
        "ALTER TABLE organizations ADD COLUMN parent_org_linked_at DATETIME DEFAULT NULL");
} else { echo "ℹ️  organizations.parent_org_linked_at déjà présent\n"; }

// ============================================================
// 3) ALTER users : ajout parent_org_id + parent_org_role + is_platform_admin
// ============================================================
if (!column_exists($pdo, 'users', 'parent_org_id')) {
    run($pdo, "Ajout users.parent_org_id",
        "ALTER TABLE users ADD COLUMN parent_org_id INT DEFAULT NULL, ADD INDEX idx_parent_org_user (parent_org_id)");
} else { echo "ℹ️  users.parent_org_id déjà présent\n"; }

if (!column_exists($pdo, 'users', 'parent_org_role')) {
    run($pdo, "Ajout users.parent_org_role",
        "ALTER TABLE users ADD COLUMN parent_org_role ENUM('admin','agent') DEFAULT NULL");
} else { echo "ℹ️  users.parent_org_role déjà présent\n"; }

if (!column_exists($pdo, 'users', 'is_platform_admin')) {
    run($pdo, "Ajout users.is_platform_admin",
        "ALTER TABLE users ADD COLUMN is_platform_admin TINYINT(1) NOT NULL DEFAULT 0, ADD INDEX idx_platform_admin (is_platform_admin)");
} else { echo "ℹ️  users.is_platform_admin déjà présent\n"; }

// ============================================================
// 4) Marquer Raphaël (id=18) comme Super Admin Fondateur
// ============================================================
$stmt = $pdo->prepare("UPDATE users SET is_platform_admin = 1 WHERE id = 18");
$stmt->execute();
echo "✅ Raphaël (id=18) marqué is_platform_admin=1 (lignes affectées : " . $stmt->rowCount() . ")\n";

// ============================================================
// 5) Table projects
// ============================================================
if (!table_exists($pdo, 'projects')) {
    run($pdo, "Création table projects", "
        CREATE TABLE projects (
            id INT AUTO_INCREMENT PRIMARY KEY,
            org_id INT NOT NULL,
            title VARCHAR(255) NOT NULL,
            slug VARCHAR(255),
            description TEXT,
            objectives TEXT,
            status ENUM('planning','active','completed','cancelled','archived') NOT NULL DEFAULT 'planning',
            start_date DATE,
            end_date DATE,
            budget_total DECIMAL(12,2) DEFAULT 0,
            budget_spent DECIMAL(12,2) DEFAULT 0,
            target_audience VARCHAR(255),
            estimated_beneficiaries INT,
            location VARCHAR(255),
            coordinator_user_id INT,
            cover_image_path VARCHAR(500),
            tags VARCHAR(500),
            visibility ENUM('private','parent_org','public') NOT NULL DEFAULT 'private',
            created_by INT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            archived_at DATETIME DEFAULT NULL,
            INDEX idx_org (org_id),
            INDEX idx_status (status),
            INDEX idx_dates (start_date, end_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} else { echo "ℹ️  Table projects déjà présente\n"; }

// ============================================================
// 6) Table project_grants (subventions liées à un projet)
// ============================================================
if (!table_exists($pdo, 'project_grants')) {
    run($pdo, "Création table project_grants", "
        CREATE TABLE project_grants (
            id INT AUTO_INCREMENT PRIMARY KEY,
            project_id INT NOT NULL,
            grant_id INT NOT NULL,
            amount_allocated DECIMAL(12,2) DEFAULT 0,
            notes TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_project_grant (project_id, grant_id),
            INDEX idx_project (project_id),
            INDEX idx_grant (grant_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} else { echo "ℹ️  Table project_grants déjà présente\n"; }

// ============================================================
// 7) Table project_members (équipe d'un projet)
// ============================================================
if (!table_exists($pdo, 'project_members')) {
    run($pdo, "Création table project_members", "
        CREATE TABLE project_members (
            id INT AUTO_INCREMENT PRIMARY KEY,
            project_id INT NOT NULL,
            user_id INT NOT NULL,
            role ENUM('coordinator','member','volunteer','observer') NOT NULL DEFAULT 'member',
            tasks TEXT,
            hours_committed DECIMAL(8,2) DEFAULT 0,
            assigned_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_project_member (project_id, user_id),
            INDEX idx_project (project_id),
            INDEX idx_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} else { echo "ℹ️  Table project_members déjà présente\n"; }

// ============================================================
// 8) Table project_documents
// ============================================================
if (!table_exists($pdo, 'project_documents')) {
    run($pdo, "Création table project_documents", "
        CREATE TABLE project_documents (
            id INT AUTO_INCREMENT PRIMARY KEY,
            project_id INT NOT NULL,
            file_name VARCHAR(255) NOT NULL,
            file_path VARCHAR(500) NOT NULL,
            file_size INT,
            file_type ENUM('proposal','report','budget','photo','contract','minutes','other') NOT NULL DEFAULT 'other',
            description TEXT,
            uploaded_by INT,
            uploaded_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_project (project_id),
            INDEX idx_type (file_type)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} else { echo "ℹ️  Table project_documents déjà présente\n"; }

// ============================================================
// 9) Récapitulatif
// ============================================================
echo "\n=== Récapitulatif ===\n";
foreach (['parent_orgs','projects','project_grants','project_members','project_documents'] as $t) {
    $count = $pdo->query("SELECT COUNT(*) FROM $t")->fetchColumn();
    echo "📊 $t : $count ligne(s)\n";
}

$stmt = $pdo->query("SELECT id, first_name, last_name, is_platform_admin FROM users WHERE is_platform_admin = 1");
echo "\n=== Super Admins Fondateurs ===\n";
foreach ($stmt as $row) {
    echo "👑 #{$row['id']} {$row['first_name']} {$row['last_name']}\n";
}

echo "\n✅ Migration terminée.\n";
