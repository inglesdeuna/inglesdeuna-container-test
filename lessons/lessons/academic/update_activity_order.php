<?php
require_once "../config/db.php";

header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
    exit;
}

$rawOrder = $_POST['order'] ?? [];
if (is_string($rawOrder)) {
    $rawOrder = explode(',', $rawOrder);
}

$order = is_array($rawOrder)
    ? array_values(array_unique(array_filter(array_map(
        static fn ($value): string => trim((string) $value),
        $rawOrder
    ), static fn (string $value): bool => $value !== '')))
    : [];

if (empty($order)) {
    http_response_code(422);
    echo json_encode(['status' => 'error', 'message' => 'No valid order received']);
    exit;
}

$unitId = trim((string) ($_POST['unit_id'] ?? ''));

try {
    $placeholders = implode(',', array_fill(0, count($order), '?'));

    if ($unitId !== '') {
        $verifySql = "
            SELECT id
            FROM activities
            WHERE unit_id = ?
              AND id IN ({$placeholders})
        ";
        $verifyStmt = $pdo->prepare($verifySql);
        $verifyStmt->execute(array_merge([$unitId], $order));
    } else {
        $verifySql = "
            SELECT id
            FROM activities
            WHERE id IN ({$placeholders})
        ";
        $verifyStmt = $pdo->prepare($verifySql);
        $verifyStmt->execute($order);
    }

    $validIds = array_map('strval', $verifyStmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
    if (count($validIds) !== count($order)) {
        http_response_code(422);
        echo json_encode(['status' => 'error', 'message' => 'Some activity ids are invalid']);
        exit;
    }

    $pdo->beginTransaction();

    if ($unitId !== '') {
        $stmt = $pdo->prepare("
            UPDATE activities
            SET position = :position
            WHERE id = :id
              AND unit_id = :unit_id
        ");

        foreach ($order as $position => $id) {
            $stmt->execute([
                'position' => $position + 1,
                'id' => $id,
                'unit_id' => $unitId,
            ]);
        }
    } else {
        $stmt = $pdo->prepare("
            UPDATE activities
            SET position = :position
            WHERE id = :id
        ");

        foreach ($order as $position => $id) {
            $stmt->execute([
                'position' => $position + 1,
                'id' => $id,
            ]);
        }
    }

    $pdo->commit();
    echo json_encode(['status' => 'success']);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Unable to save order']);
}
