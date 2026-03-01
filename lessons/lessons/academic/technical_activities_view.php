<?php
require_once("../../config/database.php"); // ajusta si cambia la ruta

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

    while ($row = $result->fetch_assoc()) {

        $activity_id = $row['id'];
        $type = strtolower(trim($row['type']));
        $title = htmlspecialchars($row['title']);

        // Seguridad básica
        $allowed_types = ['quiz','assignment','exam'];

        if (!in_array($type, $allowed_types)) {
            continue;
        }

        $viewer_url = "/lessons/lessons/activities/$type/viewer.php?id=$activity_id";
        $editor_url = "/lessons/lessons/activities/$type/editor.php?id=$activity_id";
        $delete_url = "technical_activities_view.php?unit=$unit_id&delete=$activity_id";
?>
<tr>
    <td><?php echo $title; ?></td>
    <td><?php echo $type; ?></td>
    <td>
        <a href="<?php echo $viewer_url; ?>">👁 Ver</a> |
        <a href="<?php echo $editor_url; ?>">✏️ Editar</a> |
        <a href="<?php echo $delete_url; ?>" 
           onclick="return confirm('¿Eliminar esta actividad?')">
           🗑 Eliminar
        </a>
    </td>
</tr>
<?php
    }

} else {
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
