<?php
session_start();

if (!isset($_SESSION["admin_logged"])) {
    header("Location: /lessons/lessons/admin/login.php");
    exit;
}

require_once __DIR__ . "/../../core/db.php";
require_once __DIR__ . "/../../core/_activity_editor_template.php";

$unit = $_GET['unit'] ?? null;
if (!$unit) die("Unidad no especificada");

/* ===== CARGAR DESDE DB ===== */
$stmt = $pdo->prepare("
    SELECT data
    FROM activities
    WHERE unit_id = :unit AND type = 'flipbooks'
    LIMIT 1
");
$stmt->execute([":unit" => $unit]);
$row = $stmt->fetchColumn();

$currentPdf = "";
if ($row) {
    $decoded = json_decode($row, true);
    $currentPdf = $decoded["pdf"] ?? "";
}

/* ===== PROCESAR POST ===== */
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    // ELIMINAR PDF
    if (isset($_POST["delete_pdf"])) {

        $stmt = $pdo->prepare("
            INSERT INTO activities (unit_id, type, data, created_at)
            VALUES (:unit, 'flipbooks', :data, NOW())
            ON CONFLICT (unit_id, type)
            DO UPDATE SET
                data = EXCLUDED.data,
                created_at = NOW()
        ");

        $stmt->execute([
            ":unit" => $unit,
            ":data" => json_encode(["pdf" => ""])
        ]);

        header("Location: editor.php?unit=" . urlencode($unit));
        exit;
    }

    // GUARDAR PDF NUEVO
    if (isset($_FILES["pdf"]) && $_FILES["pdf"]["error"] === 0) {

        $uploadDir = __DIR__ . "/uploads/";
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }

        $filename = time() . "_" . basename($_FILES["pdf"]["name"]);
        $targetPath = $uploadDir . $filename;

        move_uploaded_file($_FILES["pdf"]["tmp_name"], $targetPath);

        $relativePath = "lessons/lessons/activities/flipbooks/uploads/" . $filename;

        $stmt = $pdo->prepare("
            INSERT INTO activities (unit_id, type, data, created_at)
            VALUES (:unit, 'flipbooks', :data, NOW())
            ON CONFLICT (unit_id, type)
            DO UPDATE SET
                data = EXCLUDED.data,
                created_at = NOW()
        ");

        $stmt->execute([
            ":unit" => $unit,
            ":data" => json_encode(["pdf" => $relativePath])
        ]);

        header("Location: editor.php?unit=" . urlencode($unit));
        exit;
    }
}

/* ===== CONTENIDO PARA TEMPLATE ===== */
ob_start();
?>

<form method="POST" enctype="multipart/form-data" style="text-align:center;">
    <input type="file" name="pdf" accept="application/pdf" required>
    <br><br>
    <button type="submit" class="save-btn">💾 Save PDF</button>
</form>

<?php if ($currentPdf): ?>
    <div class="saved-row">
        <span class="file-name">
            <?= htmlspecialchars(basename($currentPdf)) ?>
        </span>

        <form method="POST" style="display:inline;">
            <input type="hidden" name="delete_pdf" value="1">
            <button type="submit" class="delete-btn">✖</button>
        </form>
    </div>
<?php endif; ?>

<?php
$content = ob_get_clean();

render_activity_editor("📖 Flipbook Editor", "📖", $content);
