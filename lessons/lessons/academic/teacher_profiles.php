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

        $stmtTechnical = $pdo->query("\n            SELECT c.id, c.name\n            FROM courses c\n            INNER JOIN programs p ON p.id = c.program_id\n            WHERE p.slug = 'prog_technical'\n            ORDER BY c.id ASC\n        ");
        $technical = $stmtTechnical->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $stmtEnglish = $pdo->query("\n            SELECT ph.id, CONCAT(l.name, ' - ', ph.name) AS name\n            FROM english_phases ph\n            INNER JOIN english_levels l ON l.id = ph.level_id\n            ORDER BY l.id ASC, ph.id ASC\n        ");
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
<title>Perfiles Docente</title>
<style>
body{font-family:Arial,sans-serif;background:#eef2f7;padding:30px;color:#1f2937}
.wrapper{max-width:1100px;margin:0 auto}
.card{background:#fff;border-radius:14px;padding:20px;box-shadow:0 8px 24px rgba(0,0,0,.08);margin-bottom:18px}
h1{margin-top:0}
.grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}
.full{grid-column:1/-1}
input,select,button{font:inherit;padding:10px;border:1px solid #d4dce8;border-radius:8px;width:100%}
button{background:#1f66cc;color:#fff;border:none;font-weight:700;cursor:pointer}
table{width:100%;border-collapse:collapse}
th,td{padding:10px;border-bottom:1px solid #e4e9f1;text-align:left;font-size:14px}
.back{display:inline-block;margin-bottom:15px;color:#1f66cc;text-decoration:none;font-weight:700}
.notice{padding:10px 12px;border-radius:8px;background:#eaf9ef;border:1px solid #bfe7cc;color:#1d6a40;margin-bottom:12px}
.error{padding:10px 12px;border-radius:8px;background:#fff2f2;border:1px solid #f3b5b5;color:#9f1d1d;margin-bottom:12px}
.links{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:14px}
.links a{display:inline-block;padding:8px 12px;border-radius:8px;text-decoration:none;font-weight:700;color:#fff;background:#2f66dd}
</style>
</head>
<body>
<div class="wrapper">
    <a class="back" href="../admin/dashboard.php">← Volver al dashboard</a>
    <div class="links">
      <a href="teacher_groups.php">Ver página Docentes y Grupos</a>
    </div>

    <div class="card">
        <h1>👩‍🏫 Crear perfil de docente</h1>
        <p>Aquí se crea usuario y contraseña del docente para su login y acceso a lo asignado.</p>
        <?php if (isset($_GET['saved'])) { ?><div class="notice">Perfil docente creado/actualizado.</div><?php } ?>
        <?php if (!empty($errors)) { ?>
            <div class="error">
                <?php foreach ($errors as $error) { ?>
                    <div>• <?php echo h($error); ?></div>
                <?php } ?>
            </div>
        <?php } ?>

        <form method="post" class="grid">
            <select name="teacher_id" required>
                <option value="">Seleccione docente inscrito</option>
                <?php foreach ($teachers as $teacher) { ?>
                    <?php $tid = (string) ($teacher['id'] ?? ''); ?>
                    <option value="<?php echo h($tid); ?>" <?php echo $tid === $form['teacher_id'] ? 'selected' : ''; ?>><?php echo h((string) ($teacher['name'] ?? 'Docente')); ?></option>
                <?php } ?>
            </select>

            <select name="scope" id="scopeSelect" required>
                <option value="technical" <?php echo $form['scope'] === 'technical' ? 'selected' : ''; ?>>Programa técnico (semestres)</option>
                <option value="english" <?php echo $form['scope'] === 'english' ? 'selected' : ''; ?>>Cursos de inglés</option>
            </select>

            <select name="target_id" id="targetId" required>
                <option value="">Seleccione semestre/curso</option>
            </select>

            <input type="text" name="target_name" id="targetName" placeholder="Nombre semestre/curso (auto)" value="<?php echo h($form['target_name']); ?>" required readonly>

            <select name="permission" required>
                <option value="viewer" <?php echo $form['permission'] === 'viewer' ? 'selected' : ''; ?>>Sólo ver</option>
                <option value="editor" <?php echo $form['permission'] === 'editor' ? 'selected' : ''; ?>>Puede editar</option>
            </select>

            <input type="text" name="username" placeholder="Crear usuario" value="<?php echo h($form['username']); ?>" required>

            <input class="full" type="text" name="password" placeholder="Crear password" value="<?php echo h($form['password']); ?>" required>

            <button class="full" type="submit">Crear perfil docente</button>
        </form>
    </div>

    <div class="card">
        <h2>Perfiles creados</h2>
        <table>
            <thead><tr><th>Docente</th><th>Usuario</th><th>Ámbito</th><th>Asignado</th><th>Permiso</th></tr></thead>
            <tbody>
            <?php if (empty($accounts)) { ?>
                <tr><td colspan="5">No hay perfiles creados todavía.</td></tr>
            <?php } else { ?>
                <?php foreach ($accounts as $account) { ?>
                    <tr>
                        <td><?php echo h((string) ($account['teacher_name'] ?? 'Docente')); ?></td>
                        <td><?php echo h((string) ($account['username'] ?? '')); ?></td>
                        <td><?php echo h((string) ($account['scope'] ?? 'technical')); ?></td>
                        <td><?php echo h((string) ($account['target_name'] ?? '')); ?></td>
                        <td><?php echo h((string) ($account['permission'] ?? 'viewer')); ?></td>
                    </tr>
                <?php } ?>
            <?php } ?>
            </tbody>
        </table>
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
