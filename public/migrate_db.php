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
    $stmt = $pdo->query("SHOW FIELDS FROM ft_elements WHERE Field = 'birth_date'");
    $row = $stmt->fetch();
    if ($row && strpos(strtolower($row['Type']), 'date') !== false) {
        echo "Converting birth_date and death_date to VARCHAR...\n";
        // Convert existing dates to string format first? 
        // MySQL handles DATE -> VARCHAR conversion automatically (YYYY-MM-DD).
        $pdo->exec("ALTER TABLE ft_elements MODIFY COLUMN birth_date VARCHAR(50) NULL");
        $pdo->exec("ALTER TABLE ft_elements MODIFY COLUMN death_date VARCHAR(50) NULL");
        echo "Columns birth_date and death_date converted to VARCHAR.\n";
    } else {
        echo "Columns birth_date/death_date are already VARCHAR (or check failed).\n";
    }
    
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
