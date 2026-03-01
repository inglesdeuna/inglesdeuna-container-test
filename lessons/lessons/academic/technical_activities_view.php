<?php
require_once("../../config/database.php"); // ajusta si cambia la ruta
require_once(__DIR__ . "/../config/db.php");

if (!isset($_GET['unit']) || empty($_GET['unit'])) {
    die("Unidad no especificada.");
}

$unit_id = intval($_GET['unit']);

// Obtener actividades
$sql = "SELECT id, title, type FROM activities WHERE unit_id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $unit_id);
$stmt->execute();
$result = $stmt->get_result();
if (isset($_GET['delete'])) {
    $delete_id = intval($_GET['delete']);

    $delete_stmt = $pdo->prepare("DELETE FROM activities WHERE id = :id AND unit_id = :unit_id");
    $delete_stmt->execute([
        ':id' => $delete_id,
        ':unit_id' => $unit_id,
    ]);

    header("Location: technical_activities_view.php?unit=$unit_id");
    exit();
}

$stmt = $pdo->prepare("SELECT id, title, type FROM activities WHERE unit_id = :unit_id ORDER BY id ASC");
$stmt->execute([':unit_id' => $unit_id]);
$activities = $stmt->fetchAll(PDO::FETCH_ASSOC);

$allowed_types = ['quiz', 'assignment', 'exam'];
?>

<!DOCTYPE html>
<html>
<head>
    <title>Actividades de la Unidad</title>
</head>
<body>

<h2>Actividades de la Unidad</h2>

<table border="1" cellpadding="10">
<tr>
    <th>Título</th>
    <th>Tipo</th>
    <th>Acciones</th>
</tr>

<?php
if ($result->num_rows > 0) {
$has_rows = false;

    while ($row = $result->fetch_assoc()) {
foreach ($activities as $row) {
    $activity_id = (int) $row['id'];
    $type = strtolower(trim((string) $row['type']));

        $activity_id = $row['id'];
        $type = strtolower(trim($row['type']));
        $title = htmlspecialchars($row['title']);

        // Seguridad básica
        $allowed_types = ['quiz','assignment','exam'];
    if (!in_array($type, $allowed_types, true)) {
        continue;
    }

        if (!in_array($type, $allowed_types)) {
            continue;
        }
    $has_rows = true;
    $title = htmlspecialchars((string) $row['title'], ENT_QUOTES, 'UTF-8');

        $viewer_url = "/lessons/lessons/activities/$type/viewer.php?id=$activity_id";
        $editor_url = "/lessons/lessons/activities/$type/editor.php?id=$activity_id";
        $delete_url = "technical_activities_view.php?unit=$unit_id&delete=$activity_id";
    $viewer_url = "/lessons/lessons/activities/$type/viewer.php?id=$activity_id";
    $editor_url = "/lessons/lessons/activities/$type/editor.php?id=$activity_id";
    $delete_url = "technical_activities_view.php?unit=$unit_id&delete=$activity_id";
?>
<tr>
    <td><?php echo $title; ?></td>
    <td><?php echo $type; ?></td>
    <td><?php echo htmlspecialchars($type, ENT_QUOTES, 'UTF-8'); ?></td>
    <td>
        <a href="<?php echo $viewer_url; ?>">👁 Ver</a> |
        <a href="<?php echo $editor_url; ?>">✏️ Editar</a> |
        <a href="<?php echo $delete_url; ?>" 
           onclick="return confirm('¿Eliminar esta actividad?')">
           🗑 Eliminar
        <a href="<?php echo htmlspecialchars($viewer_url, ENT_QUOTES, 'UTF-8'); ?>">👁 Ver</a> |
        <a href="<?php echo htmlspecialchars($editor_url, ENT_QUOTES, 'UTF-8'); ?>">✏️ Editar</a> |
        <a href="<?php echo htmlspecialchars($delete_url, ENT_QUOTES, 'UTF-8'); ?>" onclick="return confirm('¿Eliminar esta actividad?')">
            🗑 Eliminar
        </a>
    </td>
</tr>
<?php
    }
}

} else {
if (!$has_rows) {
    echo "<tr><td colspan='3'>No hay actividades.</td></tr>";
}
?>

</table>

</body>
</html>

<?php
// Eliminar
if (isset($_GET['delete'])) {

    $delete_id = intval($_GET['delete']);

    $delete_sql = "DELETE FROM activities WHERE id = ?";
    $delete_stmt = $conn->prepare($delete_sql);
    $delete_stmt->bind_param("i", $delete_id);
    $delete_stmt->execute();

    header("Location: technical_activities_view.php?unit=$unit_id");
    exit();
}
?>
