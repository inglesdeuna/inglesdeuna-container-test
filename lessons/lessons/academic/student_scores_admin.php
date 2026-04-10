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
        $stmt = $pdo->query("\n            SELECT\n              sur.student_id,\n              COALESCE(NULLIF(TRIM(s.name), ''), sur.student_id) AS student_name,\n              sur.assignment_id,\n              COALESCE(NULLIF(TRIM(c.name), ''), 'N/D') AS course_name,\n              COALESCE(NULLIF(TRIM(sa.program), ''), 'technical') AS program,\n              COALESCE(NULLIF(TRIM(t.name), ''), 'N/D') AS teacher_name,\n              sur.unit_id,\n              COALESCE(NULLIF(TRIM(u.name), ''), 'Unidad ' || sur.unit_id) AS unit_name,\n              sur.completion_percent,\n              sur.quiz_errors,\n              sur.quiz_total,\n              sur.updated_at\n            FROM student_unit_results sur\n            LEFT JOIN student_assignments sa ON sa.id = sur.assignment_id\n            LEFT JOIN students s ON s.id = sur.student_id\n            LEFT JOIN teachers t ON t.id = sa.teacher_id\n            LEFT JOIN courses c ON c.id::text = sa.course_id\n            LEFT JOIN units u ON u.id::text = sur.unit_id\n            ORDER BY student_name ASC, course_name ASC, unit_name ASC\n        ");

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

function load_student_activity_scores(PDO $pdo): array
{
    if (!table_exists($pdo, 'student_activity_results')) {
        return [];
    }

    try {
        $stmt = $pdo->query("
            SELECT
              sar.student_id,
              COALESCE(NULLIF(TRIM(s.name), ''), sar.student_id) AS student_name,
              sar.assignment_id,
              COALESCE(NULLIF(TRIM(c.name), ''), 'N/D') AS course_name,
              COALESCE(NULLIF(TRIM(sa.program), ''), 'technical') AS program,
              COALESCE(NULLIF(TRIM(t.name), ''), 'N/D') AS teacher_name,
              sar.unit_id,
              COALESCE(NULLIF(TRIM(u.name), ''), 'Unidad ' || sar.unit_id) AS unit_name,
              sar.activity_id,
              sar.activity_type,
              sar.completion_percent,
              sar.errors_count,
              sar.total_count,
              sar.updated_at
            FROM student_activity_results sar
            LEFT JOIN student_assignments sa ON sa.id = sar.assignment_id
            LEFT JOIN students s ON s.id = sar.student_id
            LEFT JOIN teachers t ON t.id = sa.teacher_id
            LEFT JOIN courses c ON c.id::text = sa.course_id
            LEFT JOIN units u ON u.id::text = sar.unit_id
            ORDER BY student_name ASC, course_name ASC, unit_name ASC, sar.activity_type ASC
        ");

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

$pdo = get_pdo_connection();
$rows = $pdo ? load_student_scores($pdo) : [];
$activityRows = $pdo ? load_student_activity_scores($pdo) : [];

$filterStudent = trim((string) ($_GET['student'] ?? ''));
$filterCourse = trim((string) ($_GET['course'] ?? ''));
$filterTeacher = trim((string) ($_GET['teacher'] ?? ''));
$filterProgram = trim((string) ($_GET['program'] ?? ''));

$studentOptions = [];
$courseOptions = [];
$teacherOptions = [];

foreach ($rows as $row) {
  $studentName = trim((string) ($row['student_name'] ?? ''));
  $courseName = trim((string) ($row['course_name'] ?? ''));
  $teacherName = trim((string) ($row['teacher_name'] ?? ''));

  if ($studentName !== '') {
    $studentOptions[$studentName] = true;
  }
  if ($courseName !== '') {
    $courseOptions[$courseName] = true;
  }
  if ($teacherName !== '') {
    $teacherOptions[$teacherName] = true;
  }
}

$studentOptions = array_keys($studentOptions);
$courseOptions = array_keys($courseOptions);
$teacherOptions = array_keys($teacherOptions);
sort($studentOptions, SORT_NATURAL | SORT_FLAG_CASE);
sort($courseOptions, SORT_NATURAL | SORT_FLAG_CASE);
sort($teacherOptions, SORT_NATURAL | SORT_FLAG_CASE);

$applyFilters = static function (array $row) use ($filterStudent, $filterCourse, $filterTeacher, $filterProgram): bool {
  $studentName = trim((string) ($row['student_name'] ?? ''));
  $courseName = trim((string) ($row['course_name'] ?? ''));
  $teacherName = trim((string) ($row['teacher_name'] ?? ''));
  $program = trim((string) ($row['program'] ?? ''));

  if ($filterStudent !== '' && $studentName !== $filterStudent) {
    return false;
  }
  if ($filterCourse !== '' && $courseName !== $filterCourse) {
    return false;
  }
  if ($filterTeacher !== '' && $teacherName !== $filterTeacher) {
    return false;
  }
  if ($filterProgram !== '' && $program !== $filterProgram) {
    return false;
  }

  return true;
};

$filteredRows = array_values(array_filter($rows, $applyFilters));
$filteredActivityRows = array_values(array_filter($activityRows, $applyFilters));
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
.filters{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;margin:0 0 12px}
.field{display:flex;flex-direction:column;gap:6px}
.field label{font-size:12px;color:var(--muted);font-weight:700;text-transform:uppercase;letter-spacing:.3px}
.field select{min-height:38px;border:1px solid var(--line);border-radius:10px;padding:8px 10px;background:#fff;color:var(--text)}
.filters-actions{display:flex;gap:8px;align-items:end}
.btn-filter,.btn-clear{display:inline-flex;align-items:center;justify-content:center;min-height:38px;padding:0 12px;border:none;border-radius:10px;font-weight:700;cursor:pointer;text-decoration:none}
.btn-filter{background:var(--green);color:#fff}
.btn-filter:hover{background:var(--green-dark)}
.btn-clear{background:#e7efea;color:#375648}
.btn-clear:hover{background:#d8e6de}
@media (max-width: 980px){
  .filters{grid-template-columns:repeat(2,minmax(0,1fr))}
}
@media (max-width: 640px){
  .filters{grid-template-columns:1fr}
}
</style>
</head>
<body>
<div class="page">
  <div class="top">
    <h1>Lista de estudiantes con scores</h1>
    <a class="back" href="student_assignments.php">← Volver a asignaciones</a>
  </div>
      <form method="get" class="filters">
        <div class="field">
          <label for="student">Estudiante</label>
          <select id="student" name="student">
            <option value="">Todos</option>
            <?php foreach ($studentOptions as $option) { ?>
              <option value="<?php echo h($option); ?>" <?php echo $filterStudent === $option ? 'selected' : ''; ?>><?php echo h($option); ?></option>
            <?php } ?>
          </select>
        </div>

        <div class="field">
          <label for="course">Curso</label>
          <select id="course" name="course">
            <option value="">Todos</option>
            <?php foreach ($courseOptions as $option) { ?>
              <option value="<?php echo h($option); ?>" <?php echo $filterCourse === $option ? 'selected' : ''; ?>><?php echo h($option); ?></option>
            <?php } ?>
          </select>
        </div>

        <div class="field">
          <label for="teacher">Docente</label>
          <select id="teacher" name="teacher">
            <option value="">Todos</option>
            <?php foreach ($teacherOptions as $option) { ?>
              <option value="<?php echo h($option); ?>" <?php echo $filterTeacher === $option ? 'selected' : ''; ?>><?php echo h($option); ?></option>
            <?php } ?>
          </select>
        </div>

        <div class="field">
          <label for="program">Programa</label>
          <select id="program" name="program">
            <option value="">Todos</option>
            <option value="english" <?php echo $filterProgram === 'english' ? 'selected' : ''; ?>>Ingles</option>
            <option value="technical" <?php echo $filterProgram === 'technical' ? 'selected' : ''; ?>>Tecnico</option>
          </select>
        </div>

        <div class="filters-actions">
          <button type="submit" class="btn-filter">Filtrar</button>
          <a class="btn-clear" href="student_scores_admin.php">Limpiar</a>
        </div>
      </form>

      <p class="meta">Registros mostrados: <strong><?php echo (int) count($filteredRows); ?></strong> de <strong><?php echo (int) count($rows); ?></strong></p>

  <div class="card">
    <?php if (!$pdo) { ?>
      <div class="empty">No hay conexion a base de datos disponible.</div>
    <?php } elseif (empty($rows)) { ?>
      <div class="empty">Aun no hay scores registrados.</div>
    <?php } else { ?>
      <p class="meta">Registros mostrados: <strong><?php echo (int) count($filteredRows); ?></strong></p>
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>Estudiante</th>
              <th>Curso</th>
              <th>Programa</th>
              <th>Docente</th>
              <th>Unidad</th>
              <th>Score</th>
              <th>Errores</th>
              <th>Actualizado</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($filteredRows)) { ?>
              <tr>
                <td colspan="8" class="empty">No hay resultados para los filtros seleccionados.</td>
              </tr>
            <?php } ?>
            <?php foreach ($filteredRows as $row) { ?>
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
                <td><?php echo h((string) ($row['teacher_name'] ?? 'N/D')); ?></td>
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

  <h2 style="color:var(--title);margin:28px 0 10px;">Scores por actividad</h2>
  <div class="card">
    <?php if (!$pdo) { ?>
      <div class="empty">No hay conexion a base de datos disponible.</div>
    <?php } elseif (empty($activityRows)) { ?>
      <div class="empty">Aun no hay scores de actividades registrados.</div>
    <?php } else { ?>
      <p class="meta">Registros mostrados: <strong><?php echo (int) count($filteredActivityRows); ?></strong> de <strong><?php echo (int) count($activityRows); ?></strong></p>
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>Estudiante</th>
              <th>Curso</th>
              <th>Programa</th>
              <th>Docente</th>
              <th>Unidad</th>
              <th>Actividad</th>
              <th>Score</th>
              <th>Aciertos</th>
              <th>Actualizado</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($filteredActivityRows)) { ?>
              <tr>
                <td colspan="9" class="empty">No hay resultados para los filtros seleccionados.</td>
              </tr>
            <?php } ?>
            <?php foreach ($filteredActivityRows as $arow) { ?>
              <?php $aprogram = (string) ($arow['program'] ?? 'technical'); ?>
              <tr>
                <td><?php echo h((string) ($arow['student_name'] ?? 'N/D')); ?></td>
                <td><?php echo h((string) ($arow['course_name'] ?? 'N/D')); ?></td>
                <td>
                  <?php if ($aprogram === 'english') { ?>
                    <span class="badge badge-en">Ingles</span>
                  <?php } else { ?>
                    <span class="badge badge-tech">Tecnico</span>
                  <?php } ?>
                </td>
                <td><?php echo h((string) ($arow['teacher_name'] ?? 'N/D')); ?></td>
                <td><?php echo h((string) ($arow['unit_name'] ?? 'N/D')); ?></td>
                <td><?php echo h(ucfirst((string) ($arow['activity_type'] ?? 'N/D'))); ?></td>
                <td><strong><?php echo (int) ($arow['completion_percent'] ?? 0); ?>%</strong></td>
                <td><?php echo (int) ($arow['errors_count'] ?? 0); ?>/<?php echo (int) ($arow['total_count'] ?? 0); ?></td>
                <td><?php echo h((string) ($arow['updated_at'] ?? '')); ?></td>
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
