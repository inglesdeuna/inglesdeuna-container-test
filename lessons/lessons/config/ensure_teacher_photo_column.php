<?php

require_once __DIR__ . '/db.php';

/*
Añade la columna teacher_photo en teacher_accounts si aún no existe.
Uso:
  php lessons/lessons/config/ensure_teacher_photo_column.php
*/

try {
    $pdo->exec("\n        ALTER TABLE teacher_accounts\n        ADD COLUMN IF NOT EXISTS teacher_photo TEXT\n    ");

    echo "OK: columna teacher_accounts.teacher_photo lista.\n";
} catch (Throwable $e) {
    http_response_code(500);
    echo "ERROR al crear teacher_accounts.teacher_photo: " . $e->getMessage() . "\n";
    exit(1);
}
