<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

// Migration script
try {
    $pdo = db();
    
    // 1. Add gedcom_id column if missing
    $stmt = $pdo->query("SHOW COLUMNS FROM ft_elements LIKE 'gedcom_id'");
    if ($stmt->rowCount() === 0) {
        echo "Adding gedcom_id column to ft_elements...\n";
        $pdo->exec("ALTER TABLE ft_elements ADD COLUMN gedcom_id VARCHAR(50) NULL AFTER gender");
        echo "Column gedcom_id added successfully.\n";
    } else {
        echo "Column gedcom_id already exists.\n";
    }

    // 2. Convert birth_date and death_date to VARCHAR if they are DATE
    // Force conversion to ensure it is VARCHAR even if previous check failed or was skipped
    echo "Ensuring birth_date and death_date are VARCHAR...\n";
    $pdo->exec("ALTER TABLE ft_elements MODIFY COLUMN birth_date VARCHAR(50) NULL");
    $pdo->exec("ALTER TABLE ft_elements MODIFY COLUMN death_date VARCHAR(50) NULL");
    echo "Columns birth_date and death_date set to VARCHAR.\n";
    
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
