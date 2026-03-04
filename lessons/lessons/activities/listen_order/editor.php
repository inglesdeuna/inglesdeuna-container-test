<?php
require_once __DIR__ . "/../../config/db.php";
require_once __DIR__ . "/../../core/cloudinary_upload.php";
require_once __DIR__ . "/../../core/_activity_editor_template.php";

$unit = isset($_GET['unit']) ? $_GET['unit'] : null;
if (!$unit) {
    die("Unidad no especificada");
}

function load_listen_order_blocks($pdo, $unit)
{
    $stmt = $pdo->prepare("\n        SELECT data\n        FROM activities\n        WHERE unit_id = :unit\n        AND type = 'listen_order'\n        LIMIT 1\n    ");
    $stmt->execute(array("unit" => $unit));

    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $raw = isset($row['data']) ? $row['data'] : '[]';
    $decoded = json_decode($raw, true);

    return is_array($decoded) ? $decoded : array();
}

function save_listen_order_blocks($pdo, $unit, $blocks)
{
    $json = json_encode($blocks, JSON_UNESCAPED_UNICODE);

    $check = $pdo->prepare("\n        SELECT id\n        FROM activities\n        WHERE unit_id = :unit\n        AND type = 'listen_order'\n        LIMIT 1\n    ");
    $check->execute(array("unit" => $unit));

    if ($check->fetch()) {
        $stmt = $pdo->prepare("\n            UPDATE activities\n            SET data = :data\n            WHERE unit_id = :unit\n            AND type = 'listen_order'\n        ");
        $stmt->execute(array(
            "data" => $json,
            "unit" => $unit
        ));
    } else {
        $stmt = $pdo->prepare("\n            INSERT INTO activities (id, unit_id, type, data)\n            VALUES (:id, :unit, 'listen_order', :data)\n        ");
        $stmt->execute(array(
            "id" => md5(random_bytes(16)),
            "unit" => $unit,
            "data" => $json
        ));
    }
}

$blocks = load_listen_order_blocks($pdo, $unit);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['delete_index'])) {
        $deleteIndex = (int) $_POST['delete_index'];

        if (isset($blocks[$deleteIndex])) {
            array_splice($blocks, $deleteIndex, 1);
            save_listen_order_blocks($pdo, $unit, $blocks);
        }

        header("Location: editor.php?unit=" . urlencode($unit) . "&saved=1");
        exit;
    }

    $sentence = isset($_POST['sentence']) ? trim($_POST['sentence']) : '';
    $images = array();

    if (isset($_FILES['images']) && isset($_FILES['images']['name'][0]) && !empty($_FILES['images']['name'][0])) {
        $imageFiles = $_FILES['images'];

        foreach ($imageFiles['name'] as $i => $name) {
            if (!$name || empty($imageFiles['tmp_name'][$i])) {
                continue;
            }

            $url = upload_to_cloudinary($imageFiles['tmp_name'][$i]);
            if ($url) {
                $images[] = $url;
            }
        }
    }

    if ($sentence !== '' && count($images) > 0) {
        $blocks[] = array(
            "id" => uniqid("listen_order_"),
            "sentence" => $sentence,
            "images" => $images
        );

        save_listen_order_blocks($pdo, $unit, $blocks);
    }

    header("Location: editor.php?unit=" . urlencode($unit) . "&saved=1");
    exit;
}

ob_start();

if (isset($_GET['saved'])) {
    echo '<p style="color:green;font-weight:bold;margin-bottom:15px;">✔ Guardado correctamente</p>';
}
?>

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

<hr style="margin:24px 0; border:none; border-top:1px solid #e5e7eb;">

<div style="max-width:760px; margin:0 auto; text-align:left;">
    <h3 style="margin:0 0 12px 0;">📦 Bloques guardados</h3>

    <?php if (empty($blocks)) { ?>
        <p style="color:#6b7280;">No hay bloques guardados todavía.</p>
    <?php } else { ?>
        <?php foreach ($blocks as $i => $block) { ?>
            <div style="border:1px solid #e5e7eb;border-radius:12px;padding:12px;margin-bottom:12px;background:#f9fafb;">
                <div style="font-weight:bold; margin-bottom:8px;">📝 <?php echo htmlspecialchars(isset($block['sentence']) ? $block['sentence'] : ''); ?></div>

                <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:10px;">
                    <?php
                    $blockImages = (isset($block['images']) && is_array($block['images'])) ? $block['images'] : array();
                    foreach ($blockImages as $img) {
                    ?>
                        <img src="<?php echo htmlspecialchars($img); ?>" style="height:72px;border-radius:8px;object-fit:cover;">
                    <?php } ?>
                </div>

                <form method="post" style="margin:0;">
                    <input type="hidden" name="delete_index" value="<?php echo $i; ?>">
                    <button
                        type="submit"
                        style="background:#ef4444;color:#fff;border:none;padding:8px 12px;border-radius:8px;cursor:pointer;"
                    >
                        ✖ Eliminar
                    </button>
                </form>
            </div>
        <?php } ?>
    <?php } ?>
</div>

<?php
$content = ob_get_clean();
render_activity_editor("🎧 Listen & Order Editor", "🎧", $content);
