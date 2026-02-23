<?php
require_once "../config/db.php";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $order = $_POST['order'] ?? [];

    foreach ($order as $position => $id) {

        $stmt = $pdo->prepare("
            UPDATE activities 
            SET position = :position 
            WHERE id = :id
        ");

        $stmt->execute([
            'position' => $position + 1,
            'id' => $id
        ]);
    }

    echo json_encode(['status' => 'success']);
}
