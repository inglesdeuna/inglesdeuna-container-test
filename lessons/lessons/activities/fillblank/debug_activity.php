<?php
if (session_status() === PHP_SESSION_NONE) session_start();
$isLoggedIn = !empty($_SESSION['academic_logged']) || !empty($_SESSION['admin_logged']);
if (!$isLoggedIn) { http_response_code(403); die('Forbidden'); }

require_once __DIR__ . '/../../core/db.php';

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$id) die('id required');

$stmt = $pdo->prepare("SELECT id, unit_id, type, data FROM activities WHERE id = :id LIMIT 1");
$stmt->execute(['id' => $id]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

header('Content-Type: application/json');
echo json_encode([
    'id'      => $row['id'] ?? null,
    'unit_id' => $row['unit_id'] ?? null,
    'type'    => $row['type'] ?? null,
    'data'    => json_decode($row['data'] ?? '{}', true),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
