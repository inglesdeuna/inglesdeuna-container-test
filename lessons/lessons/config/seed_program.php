<?php
require __DIR__ . "/db.php";

$programId = "prog_technical";
$programName = "Technical English"; // puedes cambiar nombre

try {

    $stmt = $pdo->prepare("
        INSERT INTO programs (id, name)
        VALUES (:id, :name)
        ON CONFLICT (id) DO NOTHING
    ");

    $stmt->execute([
        "id" => $programId,
        "name" => $programName
    ]);

    echo "✅ Program inserted OK";

} catch (Exception $e) {
    echo "❌ ERROR: " . $e->getMessage();
}
