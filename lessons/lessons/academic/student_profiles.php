<?php
session_start();

if (!isset($_SESSION['admin_logged']) || $_SESSION['admin_logged'] !== true) {
    header('Location: ../admin/login.php');
    exit;
}

$dataDir = __DIR__ . '/data';
$studentsFile = $dataDir . '/students.json';
$accountsFile = $dataDir . '/student_accounts.json';

if (!is_dir($dataDir)) {
    mkdir($dataDir, 0777, true);
}

foreach ([$studentsFile, $accountsFile] as $file) {
    if (!file_exists($file)) {
        file_put_contents($file, '[]');
    }
}

$students = json_decode((string) file_get_contents($studentsFile), true);
$accounts = json_decode((string) file_get_contents($accountsFile), true);
$students = is_array($students) ? $students : [];
$accounts = is_array($accounts) ? $accounts : [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $studentId = trim((string) ($_POST['student_id'] ?? ''));
    $username = trim((string) ($_POST['username'] ?? ''));
    $password = trim((string) ($_POST['password'] ?? ''));

    if ($studentId !== '' && $username !== '' && $password !== '') {
        $studentName = 'Estudiante';
        foreach ($students as $student) {
            if ((string) ($student['id'] ?? '') === $studentId) {
                $studentName = (string) ($student['name'] ?? 'Estudiante');
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
            'id' => $foundIndex === null ? uniqid('stu_acc_') : (string) ($accounts[$foundIndex]['id'] ?? uniqid('stu_acc_')),
            'student_id' => $studentId,
            'student_name' => $studentName,
            'username' => $username,
            'password' => $password,
        ];

        if ($foundIndex === null) {
            $accounts[] = $record;
        } else {
            $accounts[$foundIndex] = $record;
        }

        file_put_contents($accountsFile, json_encode(array_values($accounts), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        header('Location: student_profiles.php?saved=1');
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Perfiles Estudiante</title>
<style>
body{font-family:Arial,sans-serif;background:#eef2f7;padding:30px;color:#1f2937}
.wrapper{max-width:1100px;margin:0 auto}
.card{background:#fff;border-radius:14px;padding:20px;box-shadow:0 8px 24px rgba(0,0,0,.08);margin-bottom:18px}
h1{margin-top:0}
.grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}
.full{grid-column:1/-1}
input,select,button{font:inherit;padding:10px;border:1px solid #d4dce8;border-radius:8px;width:100%}
button{background:#7c3aed;color:#fff;border:none;font-weight:700;cursor:pointer}
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
        <h1>🎓 Crear perfil de estudiante</h1>
        <p>Aquí creas usuario y contraseña para el login del estudiante.</p>
        <?php if (isset($_GET['saved'])) { ?><div class="notice">Perfil estudiante creado/actualizado.</div><?php } ?>

        <form method="post" class="grid">
            <select name="student_id" required>
                <option value="">Seleccione estudiante inscrito</option>
                <?php foreach ($students as $student) { ?>
                    <option value="<?php echo htmlspecialchars((string) ($student['id'] ?? '')); ?>"><?php echo htmlspecialchars((string) ($student['name'] ?? 'Estudiante')); ?></option>
                <?php } ?>
            </select>

            <input type="text" name="username" placeholder="Crear usuario" required>
            <input class="full" type="text" name="password" placeholder="Crear password" required>

            <button class="full" type="submit">Crear perfil estudiante</button>
        </form>
    </div>

    <div class="card">
        <h2>Perfiles de estudiantes creados</h2>
        <table>
            <thead><tr><th>Estudiante</th><th>Usuario</th></tr></thead>
            <tbody>
            <?php if (empty($accounts)) { ?>
                <tr><td colspan="2">No hay perfiles creados todavía.</td></tr>
            <?php } else { ?>
                <?php foreach ($accounts as $account) { ?>
                    <tr>
                        <td><?php echo htmlspecialchars((string) ($account['student_name'] ?? 'Estudiante')); ?></td>
                        <td><?php echo htmlspecialchars((string) ($account['username'] ?? '')); ?></td>
                    </tr>
                <?php } ?>
            <?php } ?>
            </tbody>
        </table>
    </div>
</div>
</body>
</html>
