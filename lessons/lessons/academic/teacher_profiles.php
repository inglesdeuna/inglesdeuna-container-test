<?php
session_start();

if (!isset($_SESSION['admin_logged']) || $_SESSION['admin_logged'] !== true) {
    header('Location: ../admin/login.php');
    exit;
}

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

$dataDir = __DIR__ . '/data';
$teachersFile = $dataDir . '/teachers.json';
$accountsFile = $dataDir . '/teacher_accounts.json';

if (!is_dir($dataDir)) {
    mkdir($dataDir, 0777, true);
}

foreach ([$teachersFile, $accountsFile] as $file) {
    if (!file_exists($file)) {
        file_put_contents($file, '[]');
    }
}

$teachers = read_json_array($teachersFile);
$accounts = read_json_array($accountsFile);

$technical = [];
$english = [];
if (getenv('DATABASE_URL')) {
    try {
        require __DIR__ . '/../config/db.php';

        $stmtTechnical = $pdo->query("
            SELECT c.id, c.name
            FROM courses c
            INNER JOIN programs p ON p.id = c.program_id
            WHERE p.slug = 'prog_technical'
            ORDER BY c.id ASC
        ");
        $technical = $stmtTechnical->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $stmtEnglish = $pdo->query("
            SELECT ph.id, CONCAT(l.name, ' - ', ph.name) AS name
            FROM english_phases ph
            INNER JOIN english_levels l ON l.id = ph.level_id
            ORDER BY l.id ASC, ph.id ASC
        ");
        $english = $stmtEnglish->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        $technical = [];
        $english = [];
    }
}

$errors = [];
$form = [
    'teacher_id' => '',
    'scope' => 'technical',
    'target_id' => '',
    'target_name' => '',
    'permission' => 'viewer',
    'username' => '',
    'password' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $form['teacher_id'] = trim((string) ($_POST['teacher_id'] ?? ''));
    $form['scope'] = trim((string) ($_POST['scope'] ?? 'technical'));
    $form['target_id'] = trim((string) ($_POST['target_id'] ?? ''));
    $form['target_name'] = trim((string) ($_POST['target_name'] ?? ''));
    $form['permission'] = trim((string) ($_POST['permission'] ?? 'viewer'));
    $form['username'] = trim((string) ($_POST['username'] ?? ''));
    $form['password'] = trim((string) ($_POST['password'] ?? ''));

    if ($form['scope'] !== 'technical' && $form['scope'] !== 'english') {
        $form['scope'] = 'technical';
    }

    if ($form['permission'] !== 'viewer' && $form['permission'] !== 'editor') {
        $form['permission'] = 'viewer';
    }

    if ($form['teacher_id'] === '') {
        $errors[] = 'Debe seleccionar un docente.';
    }

    if ($form['target_id'] === '' || $form['target_name'] === '') {
        $errors[] = 'Debe seleccionar un semestre/curso válido.';
    }

    if ($form['username'] === '' || mb_strlen($form['username']) < 3) {
        $errors[] = 'El usuario debe tener mínimo 3 caracteres.';
    }

    if ($form['password'] === '' || mb_strlen($form['password']) < 4) {
        $errors[] = 'La contraseña debe tener mínimo 4 caracteres.';
    }

    $teacherName = '';
    foreach ($teachers as $teacher) {
        if ((string) ($teacher['id'] ?? '') === $form['teacher_id']) {
            $teacherName = (string) ($teacher['name'] ?? 'Docente');
            break;
        }
    }

    if ($teacherName === '') {
        $errors[] = 'El docente seleccionado no existe en la lista de inscritos.';
    }

    if (empty($errors)) {
        $foundIndex = null;
        $normalizedUsername = mb_strtolower($form['username']);

        foreach ($accounts as $index => $account) {
            if (mb_strtolower((string) ($account['username'] ?? '')) === $normalizedUsername) {
                $foundIndex = $index;
                break;
            }
        }

        $record = [
            'id' => $foundIndex === null
                ? uniqid('acc_', true)
                : (string) ($accounts[$foundIndex]['id'] ?? uniqid('acc_', true)),
            'teacher_id' => $form['teacher_id'],
            'teacher_name' => $teacherName,
            'scope' => $form['scope'],
            'target_id' => $form['target_id'],
            'target_name' => $form['target_name'],
            'permission' => $form['permission'],
            'username' => $form['username'],
            'password' => $form['password'],
            'updated_at' => date('c'),
        ];

        if ($foundIndex === null) {
            $accounts[] = $record;
        } else {
            $accounts[$foundIndex] = $record;
        }

        if (write_json_array($accountsFile, $accounts)) {
            header('Location: teacher_profiles.php?saved=1');
            exit;
        }

        $errors[] = 'No se pudo guardar el perfil. Intente nuevamente.';
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Perfiles Docente</title>
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
    --head:#f7faff;
    --success-bg:#ecfdf3;
    --success-border:#b9eacb;
    --success-text:#166534;
    --error-bg:#fff2f2;
    --error-border:#f3b5b5;
    --error-text:#9f1d1d;
    --shadow:0 8px 24px rgba(0,0,0,.08);
    --radius:14px;
}

*{
    box-sizing:border-box;
}

body{
    margin:0;
    font-family:Arial, sans-serif;
    background:var(--bg);
    color:var(--text);
    min-height:100vh;
    padding:32px 20px;
}

.page-shell{
    width:100%;
    display:flex;
    justify-content:center;
}

.wrapper{
    width:100%;
    max-width:980px;
    margin:0 auto;
}

.topbar{
    display:flex;
    flex-direction:column;
    align-items:flex-start;
    gap:12px;
    margin-bottom:20px;
}

.back{
    display:inline-flex;
    align-items:center;
    gap:6px;
    color:var(--blue);
    text-decoration:none;
    font-weight:700;
    font-size:14px;
}

.links{
    display:flex;
    gap:10px;
    flex-wrap:wrap;
}

.links a{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    min-height:40px;
    padding:10px 14px;
    border-radius:10px;
    text-decoration:none;
    font-weight:700;
    color:#fff;
    background:var(--blue);
    transition:background .2s ease;
}

.links a:hover{
    background:var(--blue-hover);
}

.card{
    background:var(--card);
    border:1px solid var(--line);
    border-radius:var(--radius);
    box-shadow:var(--shadow);
    padding:24px;
    margin-bottom:18px;
}

.card-header{
    margin-bottom:18px;
}

.card-header h1,
.card-header h2{
    margin:0;
    color:var(--subtitle);
    line-height:1.2;
}

.card-header h1{
    font-size:22px;
    font-weight:700;
}

.card-header h2{
    font-size:20px;
    font-weight:700;
}

.subtitle{
    margin:10px 0 0;
    font-size:14px;
    color:var(--muted);
    line-height:1.5;
}

.notice{
    padding:12px 14px;
    border-radius:10px;
    background:var(--success-bg);
    border:1px solid var(--success-border);
    color:var(--success-text);
    margin-bottom:16px;
    font-size:14px;
    font-weight:600;
}

.error{
    padding:12px 14px;
    border-radius:10px;
    background:var(--error-bg);
    border:1px solid var(--error-border);
    color:var(--error-text);
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
    color:var(--text);
    margin:0 0 8px;
    text-transform:uppercase;
    letter-spacing:.3px;
}

input,
select,
button{
    width:100%;
    min-height:44px;
    border-radius:10px;
    border:1px solid #c9d4e3;
    background:#fff;
    color:var(--text);
    font:inherit;
    padding:10px 12px;
}

input[readonly]{
    background:#f8fafc;
    color:#6b7280;
}

input:focus,
select:focus,
button:focus{
    outline:none;
    border-color:#7d9dff;
    box-shadow:0 0 0 3px rgba(70,96,220,.10);
}

.button-primary{
    border:none;
    background:var(--blue);
    color:#fff;
    font-weight:700;
    cursor:pointer;
    transition:background .2s ease;
}

.button-primary:hover{
    background:var(--blue-hover);
}

.table-wrap{
    border:1px solid var(--line);
    border-radius:12px;
    overflow:hidden;
    background:#fff;
}

.table-scroll{
    width:100%;
    overflow-x:auto;
}

table{
    width:100%;
    min-width:760px;
    border-collapse:separate;
    border-spacing:0;
}

thead th{
    background:var(--head);
    color:var(--text);
    font-size:12px;
    font-weight:700;
    text-transform:uppercase;
    letter-spacing:.25px;
    text-align:left;
    padding:12px 14px;
    border-bottom:1px solid var(--line);
}

tbody td{
    padding:12px 14px;
    border-bottom:1px solid #e7edf6;
    font-size:14px;
    color:#27415f;
    vertical-align:top;
}

tbody tr:last-child td{
    border-bottom:none;
}

.scope-badge,
.permission-badge{
    display:inline-block;
    padding:5px 10px;
    border-radius:999px;
    font-size:12px;
    font-weight:700;
    white-space:nowrap;
}

.scope-badge{
    background:#eef4ff;
    color:#1f66cc;
}

.permission-badge{
    background:#eef8f2;
    color:#1d6a40;
}

.empty-row{
    color:var(--muted);
}

@media (max-width: 768px){
    body{
        padding:20px 14px;
    }

    .wrapper{
        max-width:100%;
    }

    .card{
        padding:18px;
    }

    .form-grid{
        grid-template-columns:1fr;
    }

    .card-header h1{
        font-size:20px;
    }

    .card-header h2{
        font-size:18px;
    }
}
</style>
</head>
<body>
<div class="page-shell">
    <div class="wrapper">
        <div class="topbar">
            <a class="back" href="../admin/dashboard.php">← Volver al dashboard</a>

            <div class="links">
                <a href="teacher_groups.php">Ver página Docentes y Grupos</a>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h1>👩‍🏫 Crear perfil de docente</h1>
                <p class="subtitle">Aquí se crea el usuario y contraseña del docente para su login y acceso a lo asignado.</p>
            </div>

            <?php if (isset($_GET['saved'])) { ?>
                <div class="notice">Perfil docente creado o actualizado correctamente.</div>
            <?php } ?>

            <?php if (!empty($errors)) { ?>
                <div class="error">
                    <?php foreach ($errors as $error) { ?>
                        <div>• <?php echo h($error); ?></div>
                    <?php } ?>
                </div>
            <?php } ?>

            <form method="post" class="form-grid">
                <div class="field">
                    <label for="teacher_id">Docente</label>
                    <select name="teacher_id" id="teacher_id" required>
                        <option value="">Seleccione docente inscrito</option>
                        <?php foreach ($teachers as $teacher) { ?>
                            <?php $tid = (string) ($teacher['id'] ?? ''); ?>
                            <option value="<?php echo h($tid); ?>" <?php echo $tid === $form['teacher_id'] ? 'selected' : ''; ?>>
                                <?php echo h((string) ($teacher['name'] ?? 'Docente')); ?>
                            </option>
                        <?php } ?>
                    </select>
                </div>

                <div class="field">
                    <label for="scopeSelect">Programa</label>
                    <select name="scope" id="scopeSelect" required>
                        <option value="technical" <?php echo $form['scope'] === 'technical' ? 'selected' : ''; ?>>Programa técnico (semestres)</option>
                        <option value="english" <?php echo $form['scope'] === 'english' ? 'selected' : ''; ?>>Cursos de inglés</option>
                    </select>
                </div>

                <div class="field">
                    <label for="targetId">Semestre / Curso</label>
                    <select name="target_id" id="targetId" required>
                        <option value="">Seleccione semestre/curso</option>
                    </select>
                </div>

                <div class="field">
                    <label for="targetName">Asignado</label>
                    <input
                        type="text"
                        name="target_name"
                        id="targetName"
                        placeholder="Nombre semestre/curso (auto)"
                        value="<?php echo h($form['target_name']); ?>"
                        required
                        readonly
                    >
                </div>

                <div class="field">
                    <label for="permission">Permiso</label>
                    <select name="permission" id="permission" required>
                        <option value="viewer" <?php echo $form['permission'] === 'viewer' ? 'selected' : ''; ?>>Sólo ver</option>
                        <option value="editor" <?php echo $form['permission'] === 'editor' ? 'selected' : ''; ?>>Puede editar</option>
                    </select>
                </div>

                <div class="field">
                    <label for="username">Usuario</label>
                    <input
                        type="text"
                        name="username"
                        id="username"
                        placeholder="Crear usuario"
                        value="<?php echo h($form['username']); ?>"
                        required
                    >
                </div>

                <div class="field full">
                    <label for="password">Contraseña</label>
                    <input
                        type="text"
                        name="password"
                        id="password"
                        placeholder="Crear password"
                        value="<?php echo h($form['password']); ?>"
                        required
                    >
                </div>

                <div class="field full">
                    <button class="button-primary" type="submit">Crear perfil docente</button>
                </div>
            </form>
        </div>

        <div class="card">
            <div class="card-header">
                <h2>Perfiles creados</h2>
            </div>

            <div class="table-wrap">
                <div class="table-scroll">
                    <table>
                        <thead>
                            <tr>
                                <th>Docente</th>
                                <th>Usuario</th>
                                <th>Ámbito</th>
                                <th>Asignado</th>
                                <th>Permiso</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (empty($accounts)) { ?>
                            <tr>
                                <td colspan="5" class="empty-row">No hay perfiles creados todavía.</td>
                            </tr>
                        <?php } else { ?>
                            <?php foreach ($accounts as $account) { ?>
                                <?php
                                    $scopeValue = (string) ($account['scope'] ?? 'technical');
                                    $scopeLabel = $scopeValue === 'english' ? 'Cursos de inglés' : 'Programa técnico';
                                    $permissionValue = (string) ($account['permission'] ?? 'viewer');
                                    $permissionLabel = $permissionValue === 'editor' ? 'Puede editar' : 'Sólo ver';
                                ?>
                                <tr>
                                    <td><?php echo h((string) ($account['teacher_name'] ?? 'Docente')); ?></td>
                                    <td><?php echo h((string) ($account['username'] ?? '')); ?></td>
                                    <td><span class="scope-badge"><?php echo h($scopeLabel); ?></span></td>
                                    <td><?php echo h((string) ($account['target_name'] ?? '')); ?></td>
                                    <td><span class="permission-badge"><?php echo h($permissionLabel); ?></span></td>
                                </tr>
                            <?php } ?>
                        <?php } ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
const technical = <?php echo json_encode(array_values($technical), JSON_UNESCAPED_UNICODE); ?>;
const english = <?php echo json_encode(array_values($english), JSON_UNESCAPED_UNICODE); ?>;
const preselectedScope = <?php echo json_encode($form['scope'], JSON_UNESCAPED_UNICODE); ?>;
const preselectedTargetId = <?php echo json_encode($form['target_id'], JSON_UNESCAPED_UNICODE); ?>;

const scopeSelect = document.getElementById('scopeSelect');
const targetId = document.getElementById('targetId');
const targetName = document.getElementById('targetName');

function renderOptions() {
    if (!scopeSelect || !targetId || !targetName) {
        return;
    }

    const scope = scopeSelect.value;
    const source = scope === 'english' ? english : technical;

    targetId.innerHTML = '<option value="">Seleccione semestre/curso</option>';

    source.forEach(item => {
        const option = document.createElement('option');
        option.value = String(item.id || '');
        option.textContent = String(item.name || 'Curso');
        option.dataset.name = String(item.name || 'Curso');

        if (String(option.value) === String(preselectedTargetId || '')) {
            option.selected = true;
        }

        targetId.appendChild(option);
    });

    const selected = targetId.options[targetId.selectedIndex];
    targetName.value = selected && selected.value !== '' ? (selected.dataset.name || '') : '';
}

if (scopeSelect && targetId && targetName) {
    scopeSelect.value = preselectedScope || 'technical';
    renderOptions();

    scopeSelect.addEventListener('change', () => {
        targetId.selectedIndex = 0;
        targetName.value = '';
        renderOptions();
    });

    targetId.addEventListener('change', () => {
        const selected = targetId.options[targetId.selectedIndex];
        targetName.value = selected ? (selected.dataset.name || '') : '';
    });
}
</script>
</body>
</html>
