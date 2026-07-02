<?php
require __DIR__ . '/../config.php';

echo "=== Migration Emailing Mairie ===\n";

// === Table 1 : mairie_campaigns ===
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS mairie_campaigns (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            parent_org_id INT UNSIGNED NOT NULL,
            created_by INT UNSIGNED NOT NULL,
            subject VARCHAR(255) NOT NULL,
            content MEDIUMTEXT NOT NULL,
            recipients_filter_json LONGTEXT NULL,
            recipients_count INT NOT NULL DEFAULT 0,
            sent_count INT NOT NULL DEFAULT 0,
            failed_count INT NOT NULL DEFAULT 0,
            status ENUM('draft','sending','sent','failed') NOT NULL DEFAULT 'draft',
            scheduled_at DATETIME NULL,
            sent_at DATETIME NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            INDEX idx_parent_org (parent_org_id),
            INDEX idx_status (status),
            INDEX idx_created_at (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "✅ Table mairie_campaigns créée\n";
} catch (PDOException $e) {
    echo "⚠️  mairie_campaigns : " . $e->getMessage() . "\n";
}

// === Table 2 : mairie_campaign_recipients ===
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS mairie_campaign_recipients (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            campaign_id INT UNSIGNED NOT NULL,
            user_id INT UNSIGNED NULL,
            org_id INT UNSIGNED NULL,
            email VARCHAR(255) NOT NULL,
            first_name VARCHAR(100) NULL,
            last_name VARCHAR(100) NULL,
            org_name VARCHAR(255) NULL,
            status ENUM('pending','sent','delivered','opened','clicked','bounced','failed') NOT NULL DEFAULT 'pending',
            resend_id VARCHAR(100) NULL,
            error_message VARCHAR(500) NULL,
            sent_at DATETIME NULL,
            opened_at DATETIME NULL,
            clicked_at DATETIME NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            INDEX idx_campaign (campaign_id),
            INDEX idx_email (email),
            INDEX idx_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "✅ Table mairie_campaign_recipients créée\n";
} catch (PDOException $e) {
    echo "⚠️  mairie_campaign_recipients : " . $e->getMessage() . "\n";
}

echo "\n=== Structure mairie_campaigns ===\n";
foreach ($pdo->query('SHOW COLUMNS FROM mairie_campaigns') as $c) printf("  %-25s %s\n", $c['Field'], $c['Type']);

echo "\n=== Structure mairie_campaign_recipients ===\n";
foreach ($pdo->query('SHOW COLUMNS FROM mairie_campaign_recipients') as $c) printf("  %-25s %s\n", $c['Field'], $c['Type']);
