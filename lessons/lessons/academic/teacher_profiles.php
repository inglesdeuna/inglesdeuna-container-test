<?php
session_start();

if (!isset($_SESSION['admin_logged']) || $_SESSION['admin_logged'] !== true) {
    header('Location: ../admin/login.php');
    exit;
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

$teachers = json_decode((string) file_get_contents($teachersFile), true);
$accounts = json_decode((string) file_get_contents($accountsFile), true);
$teachers = is_array($teachers) ? $teachers : [];
$accounts = is_array($accounts) ? $accounts : [];

$technical = [];
$english = [];
$canUseDb = getenv('DATABASE_URL');
if ($canUseDb) {
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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $teacherId = trim((string) ($_POST['teacher_id'] ?? ''));
    $scope = trim((string) ($_POST['scope'] ?? 'technical'));
    $targetId = trim((string) ($_POST['target_id'] ?? ''));
    $targetName = trim((string) ($_POST['target_name'] ?? ''));
    $permission = trim((string) ($_POST['permission'] ?? 'viewer'));
    $username = trim((string) ($_POST['username'] ?? ''));
    $password = trim((string) ($_POST['password'] ?? ''));

    if ($scope !== 'technical' && $scope !== 'english') {
        $scope = 'technical';
    }

    if ($permission !== 'viewer' && $permission !== 'editor') {
        $permission = 'viewer';
    }

    if ($teacherId !== '' && $targetId !== '' && $username !== '' && $password !== '') {
        $teacherName = 'Docente';
        foreach ($teachers as $teacher) {
            if ((string) ($teacher['id'] ?? '') === $teacherId) {
                $teacherName = (string) ($teacher['name'] ?? 'Docente');
                break;
            }
        }

        $foundIndex = null;
        foreach ($accounts as $index => $account) {
            if ((string) ($account['username'] ?? '') === $username) {
                $foundIndex = $index;
                break;
            }
        }

        $record = [
            'id' => $foundIndex === null ? uniqid('acc_') : (string) ($accounts[$foundIndex]['id'] ?? uniqid('acc_')),
            'teacher_id' => $teacherId,
            'teacher_name' => $teacherName,
            'scope' => $scope,
            'target_id' => $targetId,
            'target_name' => $targetName,
            'permission' => $permission,
            'username' => $username,
            'password' => $password,
        ];

        if ($foundIndex === null) {
            $accounts[] = $record;
        } else {
            $accounts[$foundIndex] = $record;
        }

        file_put_contents($accountsFile, json_encode(array_values($accounts), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        header('Location: teacher_profiles.php?saved=1');
        exit;
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
</style>
</head>
<body>
<div class="wrapper">
    <a class="back" href="../admin/dashboard.php">← Volver al dashboard</a>

    <div class="card">
        <h1>👩‍🏫 Crear perfil de docente</h1>
        <p>Con este botón/forma se crea el usuario y contraseña del docente para su login y acceso a lo asignado.</p>
        <?php if (isset($_GET['saved'])) { ?><div class="notice">Perfil docente creado/actualizado.</div><?php } ?>

        <form method="post" class="grid">
            <select name="teacher_id" required>
                <option value="">Seleccione docente inscrito</option>
                <?php foreach ($teachers as $teacher) { ?>
                    <option value="<?php echo htmlspecialchars((string) ($teacher['id'] ?? '')); ?>"><?php echo htmlspecialchars((string) ($teacher['name'] ?? 'Docente')); ?></option>
                <?php } ?>
            </select>

            <select name="scope" id="scopeSelect" required>
                <option value="technical">Programa técnico (semestres)</option>
                <option value="english">Cursos de inglés</option>
            </select>

            <select name="target_id" id="targetId" required>
                <option value="">Seleccione semestre/curso</option>
            </select>

            <input type="text" name="target_name" id="targetName" placeholder="Nombre semestre/curso (auto)" required readonly>

            <select name="permission" required>
                <option value="viewer">Sólo ver</option>
                <option value="editor">Puede editar</option>
            </select>

            <input type="text" name="username" placeholder="Crear usuario" required>

            <input class="full" type="text" name="password" placeholder="Crear password" required>

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
                        <td><?php echo htmlspecialchars((string) ($account['teacher_name'] ?? 'Docente')); ?></td>
                        <td><?php echo htmlspecialchars((string) ($account['username'] ?? '')); ?></td>
                        <td><?php echo htmlspecialchars((string) ($account['scope'] ?? 'technical')); ?></td>
                        <td><?php echo htmlspecialchars((string) ($account['target_name'] ?? '')); ?></td>
                        <td><?php echo htmlspecialchars((string) ($account['permission'] ?? 'viewer')); ?></td>
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
const scopeSelect = document.getElementById('scopeSelect');
const targetId = document.getElementById('targetId');
const targetName = document.getElementById('targetName');

function renderOptions() {
    const scope = scopeSelect.value;
    const source = scope === 'english' ? english : technical;

    targetId.innerHTML = '<option value="">Seleccione semestre/curso</option>';
    source.forEach(item => {
        const option = document.createElement('option');
        option.value = String(item.id || '');
        option.textContent = String(item.name || 'Curso');
        option.dataset.name = String(item.name || 'Curso');
        targetId.appendChild(option);
    });

    targetName.value = '';
}

scopeSelect.addEventListener('change', renderOptions);
targetId.addEventListener('change', () => {
    const selected = targetId.options[targetId.selectedIndex];
    targetName.value = selected ? (selected.dataset.name || '') : '';
});

renderOptions();
</script>
</body>
</html>
