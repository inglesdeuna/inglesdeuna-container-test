<?php
session_start();

if (!isset($_SESSION["admin_logged"])) {
    header("Location: ../admin/login.php");
    exit;
}

$unit = $_GET['unit'] ?? null;
if (!$unit) die("Unidad no especificada");

$jsonFile = __DIR__ . "/flipbooks.json";

if (!file_exists($jsonFile)) {
    file_put_contents($jsonFile, json_encode([]));
}

$data = json_decode(file_get_contents($jsonFile), true);

if (!isset($data[$unit])) {
    $data[$unit] = ["pdf" => ""];
}

$currentPdf = $data[$unit]["pdf"] ?? "";

/* ===== PROCESAR POST ===== */
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    // ELIMINAR PDF
    if (isset($_POST["delete_pdf"]) && $currentPdf) {

        $fileName = basename($currentPdf);
        $filePath = __DIR__ . "/uploads/" . $fileName;

        if (file_exists($filePath)) {
            unlink($filePath);
        }

        $data[$unit]["pdf"] = "";
        file_put_contents($jsonFile, json_encode($data, JSON_PRETTY_PRINT));

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

        $data[$unit]["pdf"] = "activities/flipbooks/uploads/" . $filename;

        file_put_contents($jsonFile, json_encode($data, JSON_PRETTY_PRINT));

        header("Location: editor.php?unit=" . urlencode($unit));
        exit;
    }
}
?>

<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Flipbook Editor</title>

<style>
body{
    margin:0;
    background:#eef6ff;
    font-family:Arial;
}

.back-btn{
    position:absolute;
    top:20px;
    left:20px;
    background:#16a34a;
    padding:8px 14px;
    border:none;
    border-radius:10px;
    color:white;
    cursor:pointer;
    font-weight:bold;
}

.editor-container{
    max-width:900px;
    margin:100px auto 40px auto;
    background:white;
    padding:30px;
    border-radius:16px;
    box-shadow:0 4px 20px rgba(0,0,0,.1);
    text-align:center;
}

h1{
    color:#0b5ed7;
    margin-bottom:25px;
}

input[type="file"]{
    margin:20px 0;
}

button.save-btn{
    padding:10px 20px;
    background:#0b5ed7;
    border:none;
    border-radius:8px;
    color:white;
    cursor:pointer;
    font-weight:bold;
}

.saved-row{
    margin-top:25px;
    display:flex;
    justify-content:center;
    align-items:center;
    gap:15px;
    font-size:14px;
}

.file-name{
    background:#f3f4f6;
    padding:8px 12px;
    border-radius:8px;
}

.delete-btn{
    background:#ef4444;
    border:none;
    color:white;
    border-radius:50%;
    width:28px;
    height:28px;
    cursor:pointer;
    font-weight:bold;
}

.delete-btn:hover{
    background:#dc2626;
}
</style>
</head>

<body>

<button 
class="back-btn"
onclick="window.location.href='../hub/index.php?unit=<?= urlencode($unit) ?>'">
↩ Back
</button>

<div class="editor-container">

<h1>📖 Flipbook Editor</h1>

<form method="POST" enctype="multipart/form-data">
    <input type="file" name="pdf" accept="application/pdf" required>
    <br>
    <button type="submit" class="save-btn">💾 Save PDF</button>
</form>

<?php if($currentPdf): ?>
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

</div>

</body>
</html>
