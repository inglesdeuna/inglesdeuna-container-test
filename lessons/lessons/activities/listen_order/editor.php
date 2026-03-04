<?php
$unit = $_GET['unit'] ?? null;
if (!$unit) die("Unidad no especificada");

$jsonFile = __DIR__ . "/listen_order.json";
require_once __DIR__ . "/../../config/db.php";
require_once __DIR__ . "/../../core/cloudinary_upload.php";
require_once __DIR__ . "/../../core/_activity_editor_template.php";

$data = file_exists($jsonFile)
    ? json_decode(file_get_contents($jsonFile), true)
    : [];
$unit = $_GET['unit'] ?? null;
if (!$unit) {
    die("Unidad no especificada");
}

if (!isset($data[$unit])) {
    $data[$unit] = [];
function load_listen_order_blocks(PDO $pdo, string $unit): array
{
    $stmt = $pdo->prepare("
        SELECT data
        FROM activities
        WHERE unit_id = :unit
        AND type = 'listen_order'
        LIMIT 1
    ");
    $stmt->execute(["unit" => $unit]);

    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $decoded = json_decode($row["data"] ?? "[]", true);

    return is_array($decoded) ? $decoded : [];
}

/* ========= UPLOAD DIR ========= */
$uploadDir = __DIR__ . "/uploads/" . $unit;
$publicPath = "activities/listen_order/uploads/" . $unit;
function save_listen_order_blocks(PDO $pdo, string $unit, array $blocks): void
{
    $json = json_encode($blocks, JSON_UNESCAPED_UNICODE);

    $check = $pdo->prepare("
        SELECT id
        FROM activities
        WHERE unit_id = :unit
        AND type = 'listen_order'
        LIMIT 1
    ");
    $check->execute(["unit" => $unit]);

    if ($check->fetch()) {
        $stmt = $pdo->prepare("
            UPDATE activities
            SET data = :data
            WHERE unit_id = :unit
            AND type = 'listen_order'
        ");
        $stmt->execute([
            "data" => $json,
            "unit" => $unit
        ]);
    } else {
        $stmt = $pdo->prepare("
            INSERT INTO activities (id, unit_id, type, data)
            VALUES (:id, :unit, 'listen_order', :data)
        ");
        $stmt->execute([
            "id" => md5(random_bytes(16)),
            "unit" => $unit,
            "data" => $json
        ]);
    }
}

if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);
$blocks = load_listen_order_blocks($pdo, $unit);

/* ========= GUARDAR ========= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['delete_index'])) {
        $deleteIndex = (int) $_POST['delete_index'];

    $sentence = trim($_POST['sentence'] ?? "");
    $images = [];

    if (!empty($_FILES['images']['name'][0])) {

        foreach ($_FILES['images']['name'] as $i => $name) {
        if (isset($blocks[$deleteIndex])) {
            array_splice($blocks, $deleteIndex, 1);
            save_listen_order_blocks($pdo, $unit, $blocks);
        }

            if (!$name) continue;
        header("Location: editor.php?unit=" . urlencode($unit) . "&saved=1");
        exit;
    }

            $imgName = time() . "_" . basename($name);
    $sentence = trim($_POST['sentence'] ?? '');
    $imageFiles = $_FILES['images'] ?? null;
    $images = [];

            move_uploaded_file(
                $_FILES['images']['tmp_name'][$i],
                $uploadDir . "/" . $imgName
            );
    if ($imageFiles && !empty($imageFiles['name'][0])) {
        foreach ($imageFiles['name'] as $i => $name) {
            if (!$name || empty($imageFiles['tmp_name'][$i])) {
                continue;
            }

            $images[] = $publicPath . "/" . $imgName;
            $url = upload_to_cloudinary($imageFiles['tmp_name'][$i]);
            if ($url) {
                $images[] = $url;
            }
        }
    }

    if ($sentence && count($images) > 0) {

        $data[$unit][] = [
    if ($sentence !== '' && count($images) > 0) {
        $blocks[] = [
            "id" => uniqid("listen_order_"),
            "sentence" => $sentence,
            "images" => $images
        ];

        file_put_contents(
            $jsonFile,
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );
    }
}

/* ========= DELETE ========= */
if (isset($_GET['delete'])) {

    $i = (int)$_GET['delete'];

    if (isset($data[$unit][$i])) {
        array_splice($data[$unit], $i, 1);

        file_put_contents(
            $jsonFile,
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );
        save_listen_order_blocks($pdo, $unit, $blocks);
    }
}
?>

<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Listen & Order Editor</title>

<style>
body{
    font-family: Arial;
    background:#eef6ff;
    padding:30px;
}

.box{
    background:white;
    padding:25px;
    border-radius:16px;
    max-width:900px;
    margin:auto;
    box-shadow:0 4px 10px rgba(0,0,0,.1);
}

h2{
    color:#0b5ed7;
    margin-bottom:20px;
}

input[type=text], input[type=file]{
    width:100%;
    padding:8px;
    margin-top:6px;
    margin-bottom:15px;
}

button{
    background:#0b5ed7;
    color:white;
    border:none;
    padding:10px 18px;
    border-radius:10px;
    cursor:pointer;
    header("Location: editor.php?unit=" . urlencode($unit) . "&saved=1");
    exit;
}

.green{
    background:#16a34a;
}

.block{
    display:flex;
    justify-content:space-between;
    align-items:center;
    background:#f8f9fa;
    padding:12px;
    border-radius:12px;
    margin-bottom:10px;
}

.imgs img{
    height:60px;
    margin-right:6px;
    border-radius:8px;
    object-fit:contain;
}

.delete{
    color:red;
    font-size:22px;
    text-decoration:none;
    font-weight:bold;
}
</style>
</head>
<body>

<div class="box">

<h2>🎧 Listen & Order — Editor</h2>

<form method="post" enctype="multipart/form-data">

<label>Sentence (what the system will read)</label>
<input type="text" name="sentence" required>

<label>Images</label>
<input type="file" name="images[]" multiple accept="image/*" required>

<button type="submit">💾 Save</button>
ob_start();
?>

<?php if (isset($_GET['saved'])): ?>
    <p style="color:green;font-weight:bold;margin-bottom:15px;">✔ Guardado correctamente</p>
<?php endif; ?>

<form method="post" enctype="multipart/form-data" style="text-align:left; max-width:760px; margin:0 auto 20px auto;">
    <h3 style="margin:0 0 12px 0;">➕ Nuevo bloque</h3>

    <label style="font-weight:bold;">Sentence (lo que se escucha)</label>
    <input
        type="text"
        name="sentence"
        required
        placeholder="Ej: I eat an apple every morning"
        style="width:100%;padding:10px;margin:8px 0 14px 0;border:1px solid #ccc;border-radius:8px;"
    >

    <label style="font-weight:bold;">Images (en el orden correcto)</label>
    <input
        type="file"
        name="images[]"
        multiple
        accept="image/*"
        required
        style="width:100%;padding:10px;margin:8px 0 16px 0;border:1px solid #ccc;border-radius:8px;"
    >

    <button type="submit" style="background:#0b5ed7;color:#fff;border:none;padding:10px 16px;border-radius:8px;cursor:pointer;">
        💾 Guardar bloque
    </button>
</form>

<hr>

<h3>📦 Blocks</h3>

<?php foreach($data[$unit] as $i=>$b): ?>

<div class="block">

<div>
<div>📝 <?= htmlspecialchars($b["sentence"]) ?></div>

<div class="imgs">
<?php foreach($b["images"] as $img): ?>
<img src="../../<?= htmlspecialchars($img) ?>">
<?php endforeach; ?>
<hr style="margin:24px 0; border:none; border-top:1px solid #e5e7eb;">

<div style="max-width:760px; margin:0 auto; text-align:left;">
    <h3 style="margin:0 0 12px 0;">📦 Bloques guardados</h3>

    <?php if (empty($blocks)): ?>
        <p style="color:#6b7280;">No hay bloques guardados todavía.</p>
    <?php else: ?>
        <?php foreach ($blocks as $i => $block): ?>
            <div style="border:1px solid #e5e7eb;border-radius:12px;padding:12px;margin-bottom:12px;background:#f9fafb;">
                <div style="font-weight:bold; margin-bottom:8px;">📝 <?= htmlspecialchars($block['sentence'] ?? '') ?></div>

                <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:10px;">
                    <?php foreach (($block['images'] ?? []) as $img): ?>
                        <img src="<?= htmlspecialchars($img) ?>" style="height:72px;border-radius:8px;object-fit:cover;">
                    <?php endforeach; ?>
                </div>

                <form method="post" style="margin:0;">
                    <input type="hidden" name="delete_index" value="<?= $i ?>">
                    <button
                        type="submit"
                        style="background:#ef4444;color:#fff;border:none;padding:8px 12px;border-radius:8px;cursor:pointer;"
                    >
                        ✖ Eliminar
                    </button>
                </form>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

</div>

<a class="delete" href="?unit=<?= $unit ?>&delete=<?= $i ?>">✖</a>

</div>

<?php endforeach; ?>

<br>

<a href="../../academic/unit_view.php?unit=<?= urlencode($unit) ?>">
<button class="green">↩ Back</button>
</a>

</div>

</body>
</html>
<?php
$content = ob_get_clean();
render_activity_editor("🎧 Listen & Order Editor", "🎧", $content);
