<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

// Simple migration script to add gedcom_id column
try {
    $pdo = db();
    
    // Check if column exists
    $stmt = $pdo->query("SHOW COLUMNS FROM ft_elements LIKE 'gedcom_id'");
    if ($stmt->rowCount() === 0) {
        echo "Adding gedcom_id column to ft_elements...\n";
        $pdo->exec("ALTER TABLE ft_elements ADD COLUMN gedcom_id VARCHAR(50) NULL AFTER gender");
        echo "Column added successfully.\n";
    } else {
        echo "Column gedcom_id already exists.\n";
    }
    
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
