<?php
session_start();

if (!isset($_SESSION['admin_logged']) || $_SESSION['admin_logged'] !== true) { 
    header('Location: ../admin/login.php');
    exit;
}

/* ===============================
   HELPERS
=============================== */
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

    require $dbFile;

    if (!isset($pdo) || !($pdo instanceof PDO)) {
        return null;
    }

    $cachedPdo = $pdo;
    return $cachedPdo;
}

function load_students_from_database(): array
{
    $pdo = get_pdo_connection();
    if (!$pdo) {
        return [];
    }

    try {
        $stmt = $pdo->query("
            SELECT id, name, guardian, contact, eps, created_at, updated_at
            FROM students
            ORDER BY name ASC, id ASC
        ");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return [];
    }

    return array_values(array_map(function ($row) {
        return [
            'id' => (string) ($row['id'] ?? ''),
            'name' => (string) ($row['name'] ?? ''),
            'guardian' => (string) ($row['guardian'] ?? ''),
            'contact' => (string) ($row['contact'] ?? ''),
            'eps' => (string) ($row['eps'] ?? ''),
            'created_at' => (string) ($row['created_at'] ?? ''),
            'updated_at' => (string) ($row['updated_at'] ?? ''),
        ];
    }, is_array($rows) ? $rows : []));
}

function find_existing_student(array $students, string $name, string $contact): ?array
{
    $normalizedName = mb_strtolower(trim($name));
    $normalizedContact = trim($contact);

    foreach ($students as $student) {
        $studentName = mb_strtolower(trim((string) ($student['name'] ?? '')));
        $studentContact = trim((string) ($student['contact'] ?? ''));

        if ($normalizedName !== '' && $studentName === $normalizedName) {
            return (array) $student;
        }

        if ($normalizedContact !== '' && $studentContact !== '' && $studentContact === $normalizedContact) {
            return (array) $student;
        }
    }

    return null;
}

function save_student_to_database(array $student): bool
{
    $pdo = get_pdo_connection();
    if (!$pdo) {
        return false;
    }

    try {
        $stmt = $pdo->prepare("
            INSERT INTO students (
                id, name, guardian, contact, eps, created_at, updated_at
            ) VALUES (
                :id, :name, :guardian, :contact, :eps, :created_at, :updated_at
            )
            ON CONFLICT (id) DO UPDATE SET
                name = EXCLUDED.name,
                guardian = EXCLUDED.guardian,
                contact = EXCLUDED.contact,
                eps = EXCLUDED.eps,
                updated_at = EXCLUDED.updated_at
        ");

        return $stmt->execute([
            'id' => (string) ($student['id'] ?? ''),
            'name' => (string) ($student['name'] ?? ''),
            'guardian' => (string) ($student['guardian'] ?? ''),
            'contact' => (string) ($student['contact'] ?? ''),
            'eps' => (string) ($student['eps'] ?? ''),
            'created_at' => (string) ($student['created_at'] ?? date('Y-m-d H:i:s')),
            'updated_at' => (string) ($student['updated_at'] ?? date('Y-m-d H:i:s')),
        ]);
    } catch (Throwable $e) {
        return false;
    }
}

/* ===============================
   CARGA
=============================== */
$students = load_students_from_database();

$errors = [];
$form = [
    'student_name' => '',
    'student_guardian' => '',
    'student_contact' => '',
    'student_eps' => '',
];

/* ===============================
   GUARDAR
=============================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $form['student_name'] = trim((string) ($_POST['student_name'] ?? ''));
    $form['student_guardian'] = trim((string) ($_POST['student_guardian'] ?? ''));
    $form['student_contact'] = trim((string) ($_POST['student_contact'] ?? ''));
    $form['student_eps'] = trim((string) ($_POST['student_eps'] ?? ''));

    if ($form['student_name'] === '') {
        $errors[] = 'Debe escribir el nombre del estudiante.';
    }

    $existingStudent = find_existing_student($students, $form['student_name'], $form['student_contact']);

    if (empty($errors)) {
        $now = date('Y-m-d H:i:s');

        $studentRecord = [
            'id' => $existingStudent
                ? (string) ($existingStudent['id'] ?? uniqid('student_', true))
                : uniqid('student_', true),
            'name' => $form['student_name'],
            'guardian' => $form['student_guardian'],
            'contact' => $form['student_contact'],
            'eps' => $form['student_eps'],
            'created_at' => $existingStudent
                ? (string) ($existingStudent['created_at'] ?? $now)
                : $now,
            'updated_at' => $now,
        ];

        $savedInDb = save_student_to_database($studentRecord);

        if ($savedInDb) {
            header('Location: student_enrollments.php?saved=1');
            exit;
        }

        $errors[] = 'No se pudo guardar el estudiante en la base de datos.';
    }

    $students = load_students_from_database();
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Inscripciones Estudiantes</title>
<style>
:root{
    --bg:#eef7f0;--card:#ffffff;--line:#d8e8dc;--text:#1f3b28;--title:#1f3b28;--subtitle:#2a5136;
    --muted:#5d7465;--blue:#2f9e44;--blue-hover:#237a35;--badge-bg:#e9f8ee;--badge-text:#237a35;
    --shadow:0 8px 24px rgba(0,0,0,.08);--success-bg:#ecfdf3;--success-border:#b9eacb;--success-text:#166534;
    --error-bg:#fff2f2;--error-border:#f3b5b5;--error-text:#9f1d1d;
}
*{box-sizing:border-box}
body{font-family:Arial,sans-serif;background:var(--bg);padding:30px;color:var(--text);margin:0}
.wrapper{max-width:1100px;margin:0 auto}
.back{display:inline-block;margin-bottom:16px;color:var(--blue);text-decoration:none;font-weight:700;font-size:14px}
.page-title{font-size:28px;font-weight:700;color:var(--title);margin:0 0 18px}
.stack{display:flex;flex-direction:column;gap:18px}
.card{background:var(--card);border:1px solid var(--line);border-radius:14px;padding:20px;box-shadow:var(--shadow)}
.card-header{margin-bottom:18px}
.card-header h2{font-size:22px;font-weight:600;color:var(--title);margin:0 0 8px}
.subtitle{font-size:14px;color:var(--muted);margin:0;line-height:1.5}
.notice{padding:12px 14px;border-radius:10px;background:#ecfdf3;border:1px solid #b9eacb;color:#166534;margin-bottom:16px;font-size:14px;font-weight:600}
.error{padding:12px 14px;border-radius:10px;background:#fff2f2;border:1px solid #f3b5b5;color:#9f1d1d;margin-bottom:16px;font-size:14px}
.error div + div{margin-top:6px}
.form-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}
.field{display:flex;flex-direction:column}
.field.full{grid-column:1/-1}
label{font-size:12px;font-weight:700;color:var(--text);margin:0 0 8px;text-transform:uppercase;letter-spacing:.2px}
input,button{width:100%;min-height:42px;border-radius:10px;border:1px solid var(--line);background:#fff;color:var(--text);padding:10px 12px;font-size:14px;font-family:Arial,sans-serif}
input:focus,button:focus{outline:none;border-color:var(--blue);box-shadow:0 0 0 3px rgba(47,158,68,.15)}
.button-primary{border:none;background:linear-gradient(180deg,#41b95a,#2f9e44);color:#fff;font-weight:700;cursor:pointer;transition:filter .2s,transform .15s}
.button-primary:hover{filter:brightness(1.07);transform:translateY(-1px)}
.table-wrap{width:100%;border:1px solid var(--line);border-radius:14px;background:var(--card);overflow:hidden}
.table-scroll{width:100%;overflow-x:auto}
table{width:100%;min-width:900px;border-collapse:separate;border-spacing:0}
thead th{background:#f3fbf5;color:var(--text);font-size:12px;font-weight:700;text-transform:uppercase;padding:12px;text-align:left;white-space:nowrap}
tbody td{padding:12px;border-bottom:1px solid var(--line);font-size:14px;color:var(--text);vertical-align:top}
tbody tr:last-child td{border-bottom:none}
.badge{display:inline-block;padding:4px 8px;border-radius:999px;background:var(--badge-bg);color:var(--badge-text);font-size:12px;font-weight:700}
.small{font-size:13px;color:var(--muted)}
.empty-row{color:var(--muted)}
@media (max-width:768px){body{padding:20px}.page-title{font-size:24px}.card-header h2{font-size:20px}.form-grid{grid-template-columns:1fr}.button-primary{font-size:12px}}
</style>
</head>
<body>
<div class="wrapper">
    <a class="back" href="../admin/dashboard.php">← Volver al dashboard</a>
    <h1 class="page-title">Inscripciones de estudiantes</h1>

    <div class="stack">
        <section class="card">
            <div class="card-header">
                <h2>🧾 Registrar estudiante</h2>
                <p class="subtitle">La inscripción queda guardada en la base de datos.</p>
            </div>

            <?php if (isset($_GET['saved'])) { ?>
                <div class="notice">Estudiante guardado correctamente.</div>
            <?php } ?>

            <?php if (!empty($errors)) { ?>
                <div class="error">
                    <?php foreach ($errors as $error) { ?>
                        <div>• <?php echo h($error); ?></div>
                    <?php } ?>
                </div>
            <?php } ?>

            <form method="post" class="form-grid">
                <div class="field full">
                    <label for="student_name">Nombre del estudiante</label>
                    <input id="student_name" type="text" name="student_name" placeholder="Nombre completo" value="<?php echo h($form['student_name']); ?>" required>
                </div>

                <div class="field">
                    <label for="student_guardian">Acudiente</label>
                    <input id="student_guardian" type="text" name="student_guardian" placeholder="Nombre del acudiente" value="<?php echo h($form['student_guardian']); ?>">
                </div>

                <div class="field">
                    <label for="student_contact">Contacto</label>
                    <input id="student_contact" type="text" name="student_contact" placeholder="Número de contacto" value="<?php echo h($form['student_contact']); ?>">
                </div>

                <div class="field full">
                    <label for="student_eps">EPS</label>
                    <input id="student_eps" type="text" name="student_eps" placeholder="Entidad de salud" value="<?php echo h($form['student_eps']); ?>">
                </div>

                <div class="field full">
                    <button class="button-primary" type="submit">Guardar estudiante</button>
                </div>
            </form>
        </section>

        <section class="card">
            <div class="card-header">
                <h2>🎓 Estudiantes inscritos</h2>
                <p class="subtitle">Listado persistente de estudiantes registrados.</p>
            </div>

            <div class="table-wrap">
                <div class="table-scroll">
                    <table>
                        <thead>
                            <tr>
                                <th>Nombre</th>
                                <th>Acudiente</th>
                                <th>Contacto</th>
                                <th>EPS</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (empty($students)) { ?>
                            <tr><td colspan="4" class="empty-row">No hay estudiantes inscritos.</td></tr>
                        <?php } else { ?>
                            <?php foreach ($students as $student) { ?>
                                <tr>
                                    <td><strong><?php echo h((string) ($student['name'] ?? 'Estudiante')); ?></strong></td>
                                    <td><?php echo ((string) ($student['guardian'] ?? '')) !== '' ? h((string) $student['guardian']) : '<span class="small">Sin dato</span>'; ?></td>
                                    <td><?php echo ((string) ($student['contact'] ?? '')) !== '' ? h((string) $student['contact']) : '<span class="small">Sin dato</span>'; ?></td>
                                    <td><?php echo ((string) ($student['eps'] ?? '')) !== '' ? '<span class="badge">' . h((string) $student['eps']) . '</span>' : '<span class="small">Sin dato</span>'; ?></td>
                                </tr>
                            <?php } ?>
                        <?php } ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>
    </div>
</div>
</body>
</html>
