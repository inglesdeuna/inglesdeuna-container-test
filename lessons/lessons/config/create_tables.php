<?php
require __DIR__ . "/db.php";

try {

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS courses (
            id VARCHAR(50) PRIMARY KEY,
            program_id VARCHAR(50) NOT NULL,
            name VARCHAR(255) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        );
    ");

    echo "✅ COURSES TABLE OK";

} catch (Exception $e) {
    echo "❌ ERROR: " . $e->getMessage();
}
