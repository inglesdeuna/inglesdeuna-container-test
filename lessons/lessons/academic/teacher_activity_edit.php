<?php
session_start();

if (!isset($_SESSION['academic_logged']) || $_SESSION['academic_logged'] !== true) {
    header('Location: login.php');
    exit;
}

$assignmentId = trim((string) ($_GET['assignment'] ?? ''));
$unitId = trim((string) ($_GET['unit'] ?? ''));
$activityId = trim((string) ($_GET['activity'] ?? ''));
$teacherId = (string) ($_SESSION['teacher_id'] ?? '');

if ($assignmentId === '' || $unitId === '' || $activityId === '') {
    die('Faltan parámetros para editar la actividad.');
}

function get_pdo_connection(): ?PDO
{
    if (!getenv('DATABASE_URL')) {
        return null;
    }

    static $cachedPdo = null;
    static $loaded = false;

    if ($loaded) {
        return $cachedPdo;
    }

    $loaded = true;

    $dbFile = __DIR__ . '/../config/db.php';
    if (!file_exists($dbFile)) {
        return null;
    }

    try {
        require $dbFile;
        if (isset($pdo) && $pdo instanceof PDO) {
            $cachedPdo = $pdo;
        }
    } catch (Throwable $e) {
        return null;
    }

    return $cachedPdo;
}

function table_exists(PDO $pdo, string $tableName): bool
{
    try {
        $stmt = $pdo->prepare("
            SELECT 1
            FROM information_schema.tables
            WHERE table_schema = 'public'
              AND table_name = :table_name
            LIMIT 1
        ");
        $stmt->execute(['table_name' => $tableName]);
        return (bool) $stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

$pdo = get_pdo_connection();
if (!$pdo) {
    die('Base de datos no disponible.');
}

try {
    $stmt = $pdo->prepare("
        SELECT id, teacher_id, course_id, unit_id
        FROM teacher_assignments
        WHERE id = :id
        LIMIT 1
    ");
    $stmt->execute(['id' => $assignmentId]);
    $assignment = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $assignment = false;
}

if (!$assignment || (string) ($assignment['teacher_id'] ?? '') !== $teacherId) {
    die('No tienes permiso para esta asignación.');
}

try {
    $stmt = $pdo->prepare("
        SELECT id, unit_id, type
        FROM activities
        WHERE id = :id
        LIMIT 1
    ");
    $stmt->execute(['id' => $activityId]);
    $activity = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $activity = false;
}

if (!$activity) {
    die('Actividad no encontrada.');
}

if ((string) ($activity['unit_id'] ?? '') !== $unitId) {
    die('La actividad no pertenece a esta unidad.');
}

try {
    if (!table_exists($pdo, 'teacher_accounts')) {
        die('No se encontró configuración de permisos.');
    }

    $stmt = $pdo->prepare("
        SELECT permission
        FROM teacher_accounts
        WHERE teacher_id = :teacher_id
        ORDER BY updated_at DESC NULLS LAST
        LIMIT 1
    ");
    $stmt->execute(['teacher_id' => $teacherId]);
    $permission = (string) $stmt->fetchColumn();
} catch (Throwable $e) {
    $permission = 'viewer';
}

if ($permission !== 'editor') {
    die('No tienes permisos para editar actividades.');
}

$type = trim((string) ($activity['type'] ?? ''));
if ($type === '' || !preg_match('/^[a-zA-Z0-9_]+$/', $type)) {
    die('Tipo de actividad inválido.');
}

$editorFile = __DIR__ . '/../activities/' . $type . '/editor.php';
if (!file_exists($editorFile)) {
    die('Esta actividad no tiene editor disponible.');
}

$query = http_build_query([
    'id' => (string) ($activity['id'] ?? ''),
    'unit' => $unitId,
    'assignment' => $assignmentId,
]);

$editorUrl = '/lessons/lessons/activities/' . rawurlencode($type) . '/editor.php?' . $query;
header('Location: ' . $editorUrl);
exit;
