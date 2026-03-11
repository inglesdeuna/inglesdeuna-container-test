<?php
session_start();

if (!isset($_SESSION['admin_logged']) || $_SESSION['admin_logged'] !== true) {
    header('Location: ../admin/login.php');
    exit;
}

$teacherId = trim((string) ($_GET['teacher_id'] ?? ''));
$program = trim((string) ($_GET['program'] ?? 'technical'));
$targetId = trim((string) ($_GET['target_id'] ?? ''));

if ($teacherId === '' || $targetId === '') {
    header('Location: assignments_editor.php?program=' . urlencode($program));
    exit;
}

function get_pdo_connection(): ?PDO
{
    if (!getenv('DATABASE_URL')) {
        return null;
    }

    $dbFile = __DIR__ . '/../config/db.php';
    if (!file_exists($dbFile)) {
        return null;
    }

    try {
        require $dbFile;
        return (isset($pdo) && $pdo instanceof PDO) ? $pdo : null;
    } catch (Throwable $e) {
        return null;
    }
}

function table_has_column(PDO $pdo, string $tableName, string $columnName): bool
{
    try {
        $stmt = $pdo->prepare("
            SELECT 1
            FROM information_schema.columns
            WHERE table_schema = 'public'
              AND table_name = :table_name
              AND column_name = :column_name
            LIMIT 1
        ");
        $stmt->execute([
            'table_name' => $tableName,
            'column_name' => $columnName,
        ]);
        return (bool) $stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

$pdo = get_pdo_connection();
if (!$pdo) {
    header('Location: assignments_editor.php?program=' . urlencode($program) . '&error_reset=1');
    exit;
}

$tempPassword = '123456';

$setParts = [
    'password = :password',
    'updated_at = NOW()',
];

if (table_has_column($pdo, 'teacher_accounts', 'must_change_password')) {
    $setParts[] = 'must_change_password = TRUE';
}

if (table_has_column($pdo, 'teacher_accounts', 'password_updated_at')) {
    $setParts[] = 'password_updated_at = NULL';
}

try {
    $sql = "
        UPDATE teacher_accounts
        SET " . implode(",\n            ", $setParts) . "
        WHERE teacher_id = :teacher_id
          AND scope = :scope
          AND target_id = :target_id
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        'password' => $tempPassword,
        'teacher_id' => $teacherId,
        'scope' => $program,
        'target_id' => $targetId,
    ]);

    header(
        'Location: assignments_editor.php?' . http_build_query([
            'program' => $program,
            'saved' => '1',
            'reset_user' => $teacherId,
            'temp_password' => $tempPassword,
        ])
    );
    exit;
} catch (Throwable $e) {
    header('Location: assignments_editor.php?program=' . urlencode($program) . '&error_reset=1');
    exit;
}
