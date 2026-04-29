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

function load_teachers_from_database(): array
{
    $pdo = get_pdo_connection();
    if (!$pdo) {
        return [];
    }

    try {
        $stmt = $pdo->query("
            SELECT id, name, id_number, phone, bank_account, created_at, updated_at
            FROM teachers
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
            'id_number' => (string) ($row['id_number'] ?? ''),
            'phone' => (string) ($row['phone'] ?? ''),
            'bank_account' => (string) ($row['bank_account'] ?? ''),
            'created_at' => (string) ($row['created_at'] ?? ''),
            'updated_at' => (string) ($row['updated_at'] ?? ''),
        ];
    }, is_array($rows) ? $rows : []));
}

function find_existing_teacher(array $teachers, string $name, string $idNumber): ?array
{
    $normalizedName = mb_strtolower(trim($name));
    $normalizedId = trim($idNumber);

    foreach ($teachers as $teacher) {
        $teacherName = mb_strtolower(trim((string) ($teacher['name'] ?? '')));
        $teacherIdNumber = trim((string) ($teacher['id_number'] ?? ''));

        if ($normalizedId !== '' && $teacherIdNumber !== '' && $teacherIdNumber === $normalizedId) {
            return (array) $teacher;
        }

        if ($normalizedName !== '' && $teacherName === $normalizedName) {
            return (array) $teacher;
        }
    }

    return null;
}

function save_teacher_to_database(array $teacher): bool
{
    $pdo = get_pdo_connection();
    if (!$pdo) {
        return false;
    }

    try {
        $stmt = $pdo->prepare("
            INSERT INTO teachers (
                id, name, id_number, phone, bank_account, created_at, updated_at
            ) VALUES (
                :id, :name, :id_number, :phone, :bank_account, :created_at, :updated_at
            )
            ON CONFLICT (id) DO UPDATE SET
                name = EXCLUDED.name,
                id_number = EXCLUDED.id_number,
                phone = EXCLUDED.phone,
                bank_account = EXCLUDED.bank_account,
                updated_at = EXCLUDED.updated_at
        ");

        return $stmt->execute([
            'id' => (string) ($teacher['id'] ?? ''),
            'name' => (string) ($teacher['name'] ?? ''),
            'id_number' => (string) ($teacher['id_number'] ?? ''),
            'phone' => (string) ($teacher['phone'] ?? ''),
            'bank_account' => (string) ($teacher['bank_account'] ?? ''),
            'created_at' => (string) ($teacher['created_at'] ?? date('Y-m-d H:i:s')),
            'updated_at' => (string) ($teacher['updated_at'] ?? date('Y-m-d H:i:s')),
        ]);
    } catch (Throwable $e) {
        return false;
    }
}


function database_is_available(): bool
{
    return get_pdo_connection() instanceof PDO;
}

/* ===============================
   CARGA
=============================== */
$teachers = load_teachers_from_database();

$errors = [];

if (!database_is_available()) {
    $errors[] = 'No hay conexión a la base de datos. Revise la variable de entorno DATABASE_URL y el archivo config/db.php.';
}

$editingTeacher = null;
$editTeacherId = trim((string) ($_GET['edit'] ?? ''));

if ($editTeacherId !== '') {
    foreach ($teachers as $t) {
        if ((string) ($t['id'] ?? '') === $editTeacherId) {
            $editingTeacher = $t;
            break;
        }
    }
}

$form = [
    'teacher_name'        => (string) ($editingTeacher['name'] ?? ''),
    'teacher_id_number'   => (string) ($editingTeacher['id_number'] ?? ''),
    'teacher_phone'       => (string) ($editingTeacher['phone'] ?? ''),
    'teacher_bank_account'=> (string) ($editingTeacher['bank_account'] ?? ''),
];

/* ===============================
   GUARDAR
=============================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $form['teacher_name']         = trim((string) ($_POST['teacher_name'] ?? ''));
    $form['teacher_id_number']    = trim((string) ($_POST['teacher_id_number'] ?? ''));
    $form['teacher_phone']        = trim((string) ($_POST['teacher_phone'] ?? ''));
    $form['teacher_bank_account'] = trim((string) ($_POST['teacher_bank_account'] ?? ''));
    $postTeacherId                = trim((string) ($_POST['edit_teacher_id'] ?? ''));

    if ($form['teacher_name'] === '') {
        $errors[] = 'Debe escribir el nombre del docente.';
    }

    if (empty($errors)) {
        $now = date('Y-m-d H:i:s');

        if ($postTeacherId !== '') {
            // Edición por ID directo
            $existingForEdit = null;
            foreach ($teachers as $t) {
                if ((string) ($t['id'] ?? '') === $postTeacherId) {
                    $existingForEdit = $t;
                    break;
                }
            }
            $teacherRecord = [
                'id'           => $postTeacherId,
                'name'         => $form['teacher_name'],
                'id_number'    => $form['teacher_id_number'],
                'phone'        => $form['teacher_phone'],
                'bank_account' => $form['teacher_bank_account'],
                'created_at'   => (string) ($existingForEdit['created_at'] ?? $now),
                'updated_at'   => $now,
            ];
        } else {
            // Creación o deduplicación por nombre/cédula
            $existingTeacher = find_existing_teacher($teachers, $form['teacher_name'], $form['teacher_id_number']);
            $teacherRecord = [
                'id'           => $existingTeacher
                    ? (string) ($existingTeacher['id'] ?? uniqid('teacher_', true))
                    : uniqid('teacher_', true),
                'name'         => $form['teacher_name'],
                'id_number'    => $form['teacher_id_number'],
                'phone'        => $form['teacher_phone'],
                'bank_account' => $form['teacher_bank_account'],
                'created_at'   => $existingTeacher
                    ? (string) ($existingTeacher['created_at'] ?? $now)
                    : $now,
                'updated_at'   => $now,
            ];
        }

        $savedInDb = save_teacher_to_database($teacherRecord);

        if ($savedInDb) {
            header('Location: teacher_enrollments.php?saved=1');
            exit;
        }

        $errors[] = 'No se pudo guardar el docente en la base de datos.';
    }

    $teachers = load_teachers_from_database();
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Inscripciones Docentes</title>
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
.back{display:inline-block;margin-bottom:18px;padding:10px 18px;border-radius:12px;background:linear-gradient(180deg,#7b8b7f,#66756a);color:#fff;text-decoration:none;font-weight:700;font-size:13px;box-shadow:0 2px 8px rgba(0,0,0,.1);transition:filter .2s,transform .15s}
.back:hover{filter:brightness(1.07);transform:translateY(-1px)}
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
input{width:100%;min-height:42px;border-radius:10px;border:1px solid var(--line);background:#fff;color:var(--text);padding:10px 12px;font-size:14px;font-family:Arial,sans-serif}
input:focus{outline:none;border-color:var(--blue);box-shadow:0 0 0 3px rgba(47,158,68,.15)}
.button-primary{display:block;width:100%;min-height:42px;border-radius:10px;border:none;background:linear-gradient(180deg,#41b95a,#2f9e44);color:#fff;font-weight:700;cursor:pointer;transition:filter .2s,transform .15s;font-size:14px;font-family:Arial,sans-serif;padding:10px 12px;text-align:center}
.button-primary:hover{filter:brightness(1.07);transform:translateY(-1px)}
.table-wrap{width:100%;border:1px solid var(--line);border-radius:14px;background:var(--card);overflow:hidden}
.table-scroll{width:100%;overflow-x:auto}
table{width:100%;min-width:900px;border-collapse:separate;border-spacing:0}
thead th{background:#f3fbf5;color:var(--text);font-size:12px;font-weight:700;text-transform:uppercase;padding:12px;text-align:left;white-space:nowrap}
tbody td{padding:12px;border-bottom:1px solid var(--line);font-size:14px;color:var(--text);vertical-align:top}
.btn-edit{display:inline-block;padding:5px 10px;border-radius:8px;background:linear-gradient(180deg,#64b5f6,#1976d2);color:#fff;font-size:12px;font-weight:700;text-decoration:none;transition:filter .2s,transform .15s;margin-right:4px}
.btn-edit:hover{filter:brightness(1.1);transform:translateY(-1px)}
.btn-del{display:inline-block;padding:5px 10px;border-radius:8px;background:linear-gradient(180deg,#e57373,#c62828);color:#fff;font-size:12px;font-weight:700;text-decoration:none;transition:filter .2s,transform .15s}
.btn-del:hover{filter:brightness(1.1);transform:translateY(-1px)}
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
    <h1 class="page-title">Inscripciones de docentes</h1>

    <div class="stack">
        <section class="card">
            <div class="card-header">
                <?php if ($editingTeacher !== null) { ?>
                    <h2>✏️ Editar docente</h2>
                    <p class="subtitle">Corrigiendo datos de: <strong><?php echo h((string) ($editingTeacher['name'] ?? '')); ?></strong></p>
                <?php } else { ?>
                    <h2>🧾 Registrar docente</h2>
                    <p class="subtitle">La inscripción queda guardada en la base de datos.</p>
                <?php } ?>
            </div>

            <?php if (isset($_GET['saved'])) { ?>
                <div class="notice">Docente guardado correctamente. &nbsp; <a href="teacher_profiles.php" style="color:#237a35;font-weight:800;">→ Siguiente paso: Crear perfil de acceso</a></div>
            <?php } ?>

            <?php if (!empty($errors)) { ?>
                <div class="error">
                    <?php foreach ($errors as $error) { ?>
                        <div>• <?php echo h($error); ?></div>
                    <?php } ?>
                </div>
            <?php } ?>

            <form method="post" class="form-grid">
                <?php if ($editingTeacher !== null) { ?>
                    <input type="hidden" name="edit_teacher_id" value="<?php echo h((string) ($editingTeacher['id'] ?? '')); ?>">
                <?php } ?>

                <div class="field full">
                    <label for="teacher_name">Nombre del docente</label>
                    <input id="teacher_name" type="text" name="teacher_name" placeholder="Nombre completo" value="<?php echo h($form['teacher_name']); ?>" required>
                </div>

                <div class="field">
                    <label for="teacher_id_number">C.C.</label>
                    <input id="teacher_id_number" type="text" name="teacher_id_number" placeholder="Número de documento" value="<?php echo h($form['teacher_id_number']); ?>">
                </div>

                <div class="field">
                    <label for="teacher_phone">Teléfono</label>
                    <input id="teacher_phone" type="text" name="teacher_phone" placeholder="Número de contacto" value="<?php echo h($form['teacher_phone']); ?>">
                </div>

                <div class="field full">
                    <label for="teacher_bank_account">Cuenta bancaria</label>
                    <input id="teacher_bank_account" type="text" name="teacher_bank_account" placeholder="Número de cuenta" value="<?php echo h($form['teacher_bank_account']); ?>">
                </div>

                <div class="field full">
                    <button class="button-primary" type="submit">
                        <?php echo $editingTeacher !== null ? '💾 Guardar cambios' : 'Guardar docente'; ?>
                    </button>
                    <?php if ($editingTeacher !== null) { ?>
                        <a href="teacher_enrollments.php" style="display:block;text-align:center;margin-top:10px;color:#5d7465;font-size:13px;">Cancelar edición</a>
                    <?php } ?>
                </div>
            </form>
        </section>

        <section class="card">
            <div class="card-header">
                <h2>👩‍🏫 Docentes inscritos</h2>
                <p class="subtitle">Listado persistente de docentes registrados.</p>
            </div>

            <div class="table-wrap">
                <div class="table-scroll">
                    <table>
                        <thead>
                            <tr>
                                <th>Nombre</th>
                                <th>C.C.</th>
                                <th>Teléfono</th>
                                <th>Cuenta bancaria</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (empty($teachers)) { ?>
                            <tr><td colspan="5" class="empty-row">No hay docentes inscritos.</td></tr>
                        <?php } else { ?>
                            <?php foreach ($teachers as $teacher) { ?>
                                <tr>
                                    <td><strong><?php echo h((string) ($teacher['name'] ?? 'Docente')); ?></strong></td>
                                    <td><?php echo ((string) ($teacher['id_number'] ?? '')) !== '' ? h((string) $teacher['id_number']) : '<span class="small">Sin dato</span>'; ?></td>
                                    <td><?php echo ((string) ($teacher['phone'] ?? '')) !== '' ? h((string) $teacher['phone']) : '<span class="small">Sin dato</span>'; ?></td>
                                    <td><?php echo ((string) ($teacher['bank_account'] ?? '')) !== '' ? '<span class="badge">' . h((string) $teacher['bank_account']) . '</span>' : '<span class="small">Sin dato</span>'; ?></td>
                                    <td style="white-space:nowrap">
                                        <a class="btn-edit"
                                           href="teacher_enrollments.php?edit=<?php echo h((string) ($teacher['id'] ?? '')); ?>">
                                            ✏️ Editar
                                        </a>
                                        <a class="btn-del"
                                           href="delete_teacher.php?id=<?php echo h((string) ($teacher['id'] ?? '')); ?>"
                                           onclick="return confirm('¿Eliminar a <?php echo h(addslashes((string) ($teacher['name'] ?? ''))); ?>? Se eliminarán también sus asignaciones y cuenta de acceso. Esta acción no se puede deshacer.')">
                                            🗑️ Eliminar
                                        </a>
                                    </td>
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
