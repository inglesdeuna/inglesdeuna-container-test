<?php
session_start();

if (!isset($_SESSION['admin_logged']) || $_SESSION['admin_logged'] !== true) {
    header('Location: ../admin/dashboard.php');
    exit;
}

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
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
        $stmt = $pdo->prepare("\n            SELECT 1\n            FROM information_schema.tables\n            WHERE table_schema = 'public'\n              AND table_name = :table_name\n            LIMIT 1\n        ");
        $stmt->execute(['table_name' => $tableName]);
        return (bool) $stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function load_student_scores(PDO $pdo): array
{
    if (!table_exists($pdo, 'student_unit_results')) {
        return [];
    }

    try {
        $stmt = $pdo->query("\n            SELECT\n              sur.student_id,\n              COALESCE(NULLIF(TRIM(s.name), ''), sur.student_id) AS student_name,\n              sur.assignment_id,\n              COALESCE(NULLIF(TRIM(c.name), ''), 'N/D') AS course_name,\n              COALESCE(NULLIF(TRIM(sa.program), ''), 'technical') AS program,\n              sur.unit_id,\n              COALESCE(NULLIF(TRIM(u.name), ''), 'Unidad ' || sur.unit_id) AS unit_name,\n              sur.completion_percent,\n              sur.quiz_errors,\n              sur.quiz_total,\n              sur.updated_at\n            FROM student_unit_results sur\n            LEFT JOIN student_assignments sa ON sa.id = sur.assignment_id\n            LEFT JOIN students s ON s.id = sur.student_id\n            LEFT JOIN courses c ON c.id::text = sa.course_id\n            LEFT JOIN units u ON u.id::text = sur.unit_id\n            ORDER BY student_name ASC, course_name ASC, unit_name ASC\n        ");

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

$pdo = get_pdo_connection();
$rows = $pdo ? load_student_scores($pdo) : [];
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Scores de estudiantes</title>
<style>
:root{
  --bg:#eef5f0;
  --card:#ffffff;
  --line:#dce8e0;
  --title:#16352a;
  --text:#264538;
  --muted:#5e766b;
  --green:#2f9e44;
  --green-dark:#227a34;
}
*{box-sizing:border-box}
body{margin:0;font-family:Arial,sans-serif;background:var(--bg);color:var(--text);padding:20px}
.page{max-width:1180px;margin:0 auto}
.top{display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:14px}
h1{margin:0;color:var(--title);font-size:28px}
.back{display:inline-block;padding:10px 14px;border-radius:10px;text-decoration:none;background:#7a8f84;color:#fff;font-weight:700}
.back:hover{background:#5f7468}
.card{background:var(--card);border:1px solid var(--line);border-radius:14px;padding:14px;box-shadow:0 8px 20px rgba(0,0,0,.06)}
.meta{margin:0 0 10px;color:var(--muted);font-size:14px}
.table-wrap{overflow:auto}
table{width:100%;border-collapse:collapse;min-width:900px}
th,td{padding:10px;border-bottom:1px solid #edf3ef;text-align:left;font-size:14px}
th{color:var(--title);background:#f6fbf8;position:sticky;top:0}
.badge{display:inline-block;padding:3px 8px;border-radius:999px;font-size:12px;font-weight:700}
.badge-en{background:#e0ecff;color:#24417c}
.badge-tech{background:#dff7e6;color:#1e6f31}
.empty{padding:24px;text-align:center;color:var(--muted)}
</style>
</head>
<body>
<div class="page">
  <div class="top">
    <h1>Lista de estudiantes con scores</h1>
    <a class="back" href="student_assignments.php">← Volver a asignaciones</a>
  </div>

  <div class="card">
    <?php if (!$pdo) { ?>
      <div class="empty">No hay conexion a base de datos disponible.</div>
    <?php } elseif (empty($rows)) { ?>
      <div class="empty">Aun no hay scores registrados.</div>
    <?php } else { ?>
      <p class="meta">Registros: <strong><?php echo (int) count($rows); ?></strong></p>
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>Estudiante</th>
              <th>Curso</th>
              <th>Programa</th>
              <th>Unidad</th>
              <th>Score</th>
              <th>Errores</th>
              <th>Actualizado</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($rows as $row) { ?>
              <?php $program = (string) ($row['program'] ?? 'technical'); ?>
              <tr>
                <td><?php echo h((string) ($row['student_name'] ?? 'N/D')); ?></td>
                <td><?php echo h((string) ($row['course_name'] ?? 'N/D')); ?></td>
                <td>
                  <?php if ($program === 'english') { ?>
                    <span class="badge badge-en">Ingles</span>
                  <?php } else { ?>
                    <span class="badge badge-tech">Tecnico</span>
                  <?php } ?>
                </td>
                <td><?php echo h((string) ($row['unit_name'] ?? 'N/D')); ?></td>
                <td><strong><?php echo (int) ($row['completion_percent'] ?? 0); ?>%</strong></td>
                <td><?php echo (int) ($row['quiz_errors'] ?? 0); ?>/<?php echo (int) ($row['quiz_total'] ?? 0); ?></td>
                <td><?php echo h((string) ($row['updated_at'] ?? '')); ?></td>
              </tr>
            <?php } ?>
          </tbody>
        </table>
      </div>
    <?php } ?>
  </div>
</div>
</body>
</html>
