<?php
session_start();

if (!isset($_SESSION["admin_logged"])) {
    header("Location: ../admin/login.php");
    exit;
}

$unit = $_GET['unit'] ?? null;
if (!$unit) die("Unidad no especificada");

$jsonFile = __DIR__ . "/pronunciation.json";

if (!file_exists($jsonFile)) {
    file_put_contents($jsonFile, json_encode([]));
}

$data = json_decode(file_get_contents($jsonFile), true);

if (!isset($data[$unit])) {
    $data[$unit] = [
        "word" => "",
        "audio" => ""
    ];
}

$currentWord = $data[$unit]["word"];
$currentAudio = $data[$unit]["audio"];

/* ===== PROCESAR POST ===== */
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    // ELIMINAR
    if (isset($_POST["delete_item"])) {

        if ($currentAudio) {
            $filePath = __DIR__ . "/uploads/" . basename($currentAudio);
            if (file_exists($filePath)) {
                unlink($filePath);
            }
        }

        $data[$unit] = ["word" => "", "audio" => ""];
        file_put_contents($jsonFile, json_encode($data, JSON_PRETTY_PRINT));

        header("Location: editor.php?unit=" . urlencode($unit));
        exit;
    }

    // GUARDAR NUEVO
    if (!empty($_POST["word"]) && isset($_FILES["audio"]) && $_FILES["audio"]["error"] === 0) {

        $uploadDir = __DIR__ . "/uploads/";
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }

        $filename = time() . "_" . basename($_FILES["audio"]["name"]);
        $targetPath = $uploadDir . $filename;

        move_uploaded_file($_FILES["audio"]["tmp_name"], $targetPath);

        $data[$unit]["word"] = trim($_POST["word"]);
        $data[$unit]["audio"] = "activities/pronunciation/uploads/" . $filename;

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
<title>Pronunciation Editor</title>

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

input[type="text"]{
    width:60%;
    padding:8px;
    border-radius:6px;
    border:1px solid #ccc;
}

input[type="file"]{
    margin:20px 0;
}

.save-btn{
    padding:10px 20px;
    background:#0b5ed7;
    border:none;
    border-radius:8px;
    color:white;
    cursor:pointer;
    font-weight:bold;
}

.saved-row{
    margin-top:30px;
    display:flex;
    justify-content:center;
    align-items:center;
    gap:20px;
}

.word-box{
    background:#f3f4f6;
    padding:10px 15px;
    border-radius:8px;
}

.delete-btn{
    background:#ef4444;
    border:none;
    color:white;
    border-radius:50%;
    width:30px;
    height:30px;
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

<h1>🔊 Pronunciation Editor</h1>

<form method="POST" enctype="multipart/form-data">
    <input type="text" name="word" placeholder="Enter word..." required>
    <br>
    <input type="file" name="audio" accept="audio/*" required>
    <br>
    <button type="submit" class="save-btn">💾 Save</button>
</form>

<?php if($currentWord && $currentAudio): ?>
    <div class="saved-row">
        <div class="word-box">
            <?= htmlspecialchars($currentWord) ?>
        </div>

        <audio controls>
            <source src="/lessons/lessons/<?= $currentAudio ?>">
        </audio>

        <form method="POST">
            <input type="hidden" name="delete_item" value="1">
            <button type="submit" class="delete-btn">✖</button>
        </form>
    </div>
<?php endif; ?>

</div>

</body>
</html>
