<?php
require __DIR__ . '/../config.php';

echo "=== Migration mairie channels ===\n";

// Ajouter parent_org_id
try {
    $pdo->exec("ALTER TABLE channels ADD COLUMN parent_org_id INT UNSIGNED NULL AFTER org_id");
    echo "✅ Colonne channels.parent_org_id ajoutée\n";
} catch (PDOException $e) {
    echo "⚠️  parent_org_id : " . $e->getMessage() . "\n";
}

// Ajouter is_mairie_channel
try {
    $pdo->exec("ALTER TABLE channels ADD COLUMN is_mairie_channel TINYINT(1) NOT NULL DEFAULT 0 AFTER type");
    echo "✅ Colonne channels.is_mairie_channel ajoutée\n";
} catch (PDOException $e) {
    echo "⚠️  is_mairie_channel : " . $e->getMessage() . "\n";
}

// Index
try {
    $pdo->exec("ALTER TABLE channels ADD INDEX idx_parent_org (parent_org_id)");
    echo "✅ Index idx_parent_org créé\n";
} catch (PDOException $e) {
    echo "⚠️  idx_parent_org : " . $e->getMessage() . "\n";
}

echo "\n=== Structure finale channels ===\n";
foreach ($pdo->query('SHOW COLUMNS FROM channels') as $c) {
    printf("  %-25s %s\n", $c['Field'], $c['Type']);
}
