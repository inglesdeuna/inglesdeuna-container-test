<?php
require_once __DIR__ . "/config/db.php";

echo "<h2>CHECK CONSTRAINTS</h2>";

$stmt = $pdo->query("
SELECT conname
FROM pg_constraint
WHERE conrelid = 'activities'::regclass
");

$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "<pre>";
print_r($rows);
echo "</pre>";
