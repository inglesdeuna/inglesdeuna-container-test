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

function read_json_array(string $file): array
{
    if (!file_exists($file)) {
        return [];
    }

    $raw = file_get_contents($file);
    if ($raw === false) {
        return [];
    }

    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function write_json_array(string $file, array $rows): bool
{
    $json = json_encode(array_values($rows), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        return false;
    }

    return file_put_contents($file, $json) !== false;
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

/* ===============================
   ARCHIVOS
=============================== */
$dataDir = __DIR__ . '/data';
$teachersFile = $dataDir . '/teachers.json';

if (!is_dir($dataDir)) {
    mkdir($dataDir, 0777, true);
}

if (!file_exists($teachersFile)) {
    file_put_contents($teachersFile, '[]');
}

/* ===============================
   CARGA
=============================== */
$teachersJson = read_json_array($teachersFile);
$teachersDb = load_teachers_from_database();
$teachers = !empty($teachersDb) ? $teachersDb : $teachersJson;

$errors = [];
$form = [
    'teacher_name' => '',
    'teacher_id_number' => '',
    'teacher_phone' => '',
    'teacher_bank_account' => '',
];

/* ===============================
   GUARDAR
=============================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $form['teacher_name'] = trim((string) ($_POST['teacher_name'] ?? ''));
    $form['teacher_id_number'] = trim((string) ($_POST['teacher_id_number'] ?? ''));
    $form['teacher_phone'] = trim((string) ($_POST['teacher_phone'] ?? ''));
    $form['teacher_bank_account'] = trim((string) ($_POST['teacher_bank_account'] ?? ''));

    if ($form['teacher_name'] === '') {
        $errors[] = 'Debe escribir el nombre del docente.';
    }

    if ($form['teacher_id_number'] === '') {
        $errors[] = 'Debe escribir la cédula.';
    }

    $existingTeacher = find_existing_teacher($teachers, $form['teacher_name'], $form['teacher_id_number']);

    if (empty($errors)) {
        $now = date('Y-m-d H:i:s');

        $teacherRecord = [
            'id' => $existingTeacher
                ? (string) ($existingTeacher['id'] ?? uniqid('teacher_', true))
                : uniqid('teacher_', true),
            'name' => $form['teacher_name'],
            'id_number' => $form['teacher_id_number'],
            'phone' => $form['teacher_phone'],
            'bank_account' => $form['teacher_bank_account'],
            'created_at' => $existingTeacher
                ? (string) ($existingTeacher['created_at'] ?? $now)
                : $now,
            'updated_at' => $now,
        ];

        $savedInDb = save_teacher_to_database($teacherRecord);

        if ($savedInDb) {
            header('Location: teacher_enrollments.php?saved=1');
            exit;
        }

        $updated = false;
        foreach ($teachers as $index => $teacher) {
            if ((string) ($teacher['id'] ?? '') === (string) $teacherRecord['id']) {
                $teachers[$index] = $teacherRecord;
                $updated = true;
                break;
            }
        }

        if (!$updated) {
            $teachers[] = $teacherRecord;
        }

        if (write_json_array($teachersFile, $teachers)) {
            header('Location: teacher_enrollments.php?saved=1');
            exit;
        }

        $errors[] = 'No se pudo guardar el docente. Intente nuevamente.';
    }
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
    --bg:#eef2f7;
    --card:#ffffff;
    --line:#dce4f0;
    --text:#1f2937;
    --title:#1f3c75;
    --subtitle:#2c3e50;
    --muted:#5b6577;
    --blue:#1f66cc;
    --blue-hover:#2f5bb5;
    --badge-bg:#eef2ff;
    --badge-text:#1f4ec9;
    --danger:#dc2626;
    --shadow:0 8px 24px rgba(0,0,0,.08);
    --success-bg:#ecfdf3;
    --success-border:#b9eacb;
    --success-text:#166534;
    --error-bg:#fff2f2;
    --error-border:#f3b5b5;
    --error-text:#9f1d1d;
}

*{
    box-sizing:border-box;
}

body{
    font-family: Arial, sans-serif;
    background:#eef2f7;
    padding:30px;
    color:#1f2937;
    margin:0;
}

.wrapper{
    max-width:1100px;
    margin:0 auto;
}

.back{
    display:inline-block;
    margin-bottom:16px;
    color:#1f66cc;
    text-decoration:none;
    font-weight:700;
    font-size:14px;
}

.page-title{
    font-size:28px;
    font-weight:700;
    color:#1f3c75;
    margin:0 0 18px;
}

.stack{
    display:flex;
    flex-direction:column;
    gap:18px;
}

.card{
    background:#ffffff;
    border:1px solid #dce4f0;
    border-radius:14px;
    padding:20px;
    box-shadow:0 8px 24px rgba(0,0,0,.08);
}

.card-header{
    margin-bottom:18px;
}

.card-header h2{
    font-size:22px;
    font-weight:600;
    color:#2c3e50;
    margin:0 0 8px;
}

.subtitle{
    font-size:14px;
    color:#5b6577;
    margin:0;
    line-height:1.5;
}

.notice{
    padding:12px 14px;
    border-radius:10px;
    background:#ecfdf3;
    border:1px solid #b9eacb;
    color:#166534;
    margin-bottom:16px;
    font-size:14px;
    font-weight:600;
}

.error{
    padding:12px 14px;
    border-radius:10px;
    background:#fff2f2;
    border:1px solid #f3b5b5;
    color:#9f1d1d;
    margin-bottom:16px;
    font-size:14px;
}

.error div + div{
    margin-top:6px;
}

.form-grid{
    display:grid;
    grid-template-columns:repeat(2, minmax(0, 1fr));
    gap:14px;
}

.field{
    display:flex;
    flex-direction:column;
}

.field.full{
    grid-column:1 / -1;
}

label{
    font-size:12px;
    font-weight:700;
    color:#1f2937;
    margin:0 0 8px;
    text-transform:uppercase;
    letter-spacing:.2px;
}

input,
button{
    width:100%;
    min-height:42px;
    border-radius:10px;
    border:1px solid #c7d3e3;
    background:#fff;
    color:#1f2937;
    padding:10px 12px;
    font-size:14px;
    font-family:Arial, sans-serif;
}

input:focus,
button:focus{
    outline:none;
    border-color:#7d9dff;
    box-shadow:0 0 0 3px rgba(70,96,220,.10);
}

.button-primary{
    border:none;
    background:#1f66cc;
    color:#fff;
    font-weight:700;
    cursor:pointer;
    transition:background .2s ease;
}

.button-primary:hover{
    background:#2f5bb5;
}

.table-wrap{
    width:100%;
    border:1px solid #dce4f0;
    border-radius:14px;
    background:#fff;
    overflow:hidden;
}

.table-scroll{
    width:100%;
    overflow-x:auto;
}

table{
    width:100%;
    min-width:900px;
    border-collapse:separate;
    border-spacing:0;
}

thead th{
    background:#f7faff;
    color:#1f2937;
    font-size:12px;
    font-weight:700;
    text-transform:uppercase;
    padding:12px;
    text-align:left;
    white-space:nowrap;
}

tbody td{
    padding:12px;
    border-bottom:1px solid #e8eef6;
    font-size:14px;
    color:#1f2937;
    vertical-align:top;
}

tbody tr:last-child td{
    border-bottom:none;
}

.badge{
    display:inline-block;
    padding:4px 8px;
    border-radius:999px;
    background:#eef2ff;
    color:#1f4ec9;
    font-size:12px;
    font-weight:700;
}

.small{
    font-size:13px;
    color:#5b6577;
}

.empty-row{
    color:#5b6577;
}

@media (max-width:768px){
    body{
        padding:20px;
    }

    .page-title{
        font-size:24px;
    }

    .card-header h2{
        font-size:20px;
    }

    .form-grid{
        grid-template-columns:1fr;
    }

    .button-primary{
        font-size:12px;
    }
}
</style>
</head>
<body>
<div class="wrapper">
    <a class="back" href="../admin/dashboard.php">← Volver al dashboard</a>
    <h1 class="page-title">Inscripciones de docentes</h1>

    <div class="stack">
        <section class="card">
            <div class="card-header">
                <h2>🧾 Registrar docente</h2>
                <p class="subtitle">La inscripción queda guardada en la base de datos. Si la base falla, el sistema usa JSON como respaldo.</p>
            </div>

            <?php if (isset($_GET['saved'])) { ?>
                <div class="notice">Docente guardado correctamente.</div>
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
                    <label for="teacher_name">Nombre del docente</label>
                    <input
                        id="teacher_name"
                        type="text"
                        name="teacher_name"
                        placeholder="Nombre completo"
                        value="<?php echo h($form['teacher_name']); ?>"
                        required
                    >
                </div>

                <div class="field">
                    <label for="teacher_id_number">C.C.</label>
                    <input
                        id="teacher_id_number"
                        type="text"
                        name="teacher_id_number"
                        placeholder="Número de documento"
                        value="<?php echo h($form['teacher_id_number']); ?>"
                    >
                </div>

                <div class="field">
                    <label for="teacher_phone">Teléfono</label>
                    <input
                        id="teacher_phone"
                        type="text"
                        name="teacher_phone"
                        placeholder="Número de contacto"
                        value="<?php echo h($form['teacher_phone']); ?>"
                    >
                </div>

                <div class="field full">
                    <label for="teacher_bank_account">Cuenta bancaria</label>
                    <input
                        id="teacher_bank_account"
                        type="text"
                        name="teacher_bank_account"
                        placeholder="Número de cuenta"
                        value="<?php echo h($form['teacher_bank_account']); ?>"
                    >
                </div>

                <div class="field full">
                    <button class="button-primary" type="submit">Guardar docente</button>
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
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (empty($teachers)) { ?>
                            <tr>
                                <td colspan="4" class="empty-row">No hay docentes inscritos.</td>
                            </tr>
                        <?php } else { ?>
                            <?php foreach ($teachers as $teacher) { ?>
                                <tr>
                                    <td>
                                        <strong><?php echo h((string) ($teacher['name'] ?? 'Docente')); ?></strong>
                                    </td>
                                    <td>
                                        <?php
                                            $idNumber = (string) ($teacher['id_number'] ?? '');
                                            echo $idNumber !== '' ? h($idNumber) : '<span class="small">Sin dato</span>';
                                        ?>
                                    </td>
                                    <td>
                                        <?php
                                            $phone = (string) ($teacher['phone'] ?? '');
                                            echo $phone !== '' ? h($phone) : '<span class="small">Sin dato</span>';
                                        ?>
                                    </td>
                                    <td>
                                        <?php
                                            $bank = (string) ($teacher['bank_account'] ?? '');
                                            echo $bank !== '' ? '<span class="badge">' . h($bank) . '</span>' : '<span class="small">Sin dato</span>';
                                        ?>
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
