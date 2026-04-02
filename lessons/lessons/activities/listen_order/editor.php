<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . "/../../config/db.php";
require_once __DIR__ . "/../../core/cloudinary_upload.php";
require_once __DIR__ . "/../../core/_activity_editor_template.php";

// Block student access to editor
if (isset($_SESSION['student_logged']) && $_SESSION['student_logged']) {
    header('Location: /lessons/lessons/academic/student_dashboard.php?error=access_denied');
    exit;
}

// Accept admin OR teacher session
$isLoggedIn = !empty($_SESSION['academic_logged']) || !empty($_SESSION['admin_logged']);
if (!$isLoggedIn) {
    header('Location: /lessons/lessons/academic/login.php');
    exit;
}

$activityId = isset($_GET["id"]) ? trim((string) $_GET["id"]) : "";
$unit = isset($_GET["unit"]) ? trim((string) $_GET["unit"]) : "";
$source = isset($_GET["source"]) ? trim((string) $_GET["source"]) : "";
$assignment = isset($_GET["assignment"]) ? trim((string) $_GET["assignment"]) : "";

function resolve_unit_from_activity(PDO $pdo, string $activityId): string
{
    if ($activityId === "") {
        return "";
    }

    $stmt = $pdo->prepare("
        SELECT unit_id
        FROM activities
        WHERE id = :id
        LIMIT 1
    ");
    $stmt->execute(["id" => $activityId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row && isset($row["unit_id"]) ? (string) $row["unit_id"] : "";
}

function default_listen_order_title(): string
{
    return "Listen & Order";
}

function normalize_listen_order_title(string $title): string
{
    $title = trim($title);
    return $title !== "" ? $title : default_listen_order_title();
}

function normalize_listen_order_payload($rawData): array
{
    $default = [
        "title" => default_listen_order_title(),
        "blocks" => [],
    ];

    if ($rawData === null || $rawData === "") {
        return $default;
    }

    $decoded = is_string($rawData) ? json_decode($rawData, true) : $rawData;
    if (!is_array($decoded)) {
        return $default;
    }

    $title = "";
    $blocksSource = $decoded;

    if (isset($decoded["title"])) {
        $title = trim((string) $decoded["title"]);
    }

    if (isset($decoded["blocks"]) && is_array($decoded["blocks"])) {
        $blocksSource = $decoded["blocks"];
    }

    $blocks = [];

    foreach ($blocksSource as $block) {
        if (!is_array($block)) {
            continue;
        }

        $sentence = trim((string) ($block["sentence"] ?? ""));
        $images = [];

        if (isset($block["images"]) && is_array($block["images"])) {
            foreach ($block["images"] as $img) {
                $url = trim((string) $img);
                if ($url !== "") {
                    $images[] = $url;
                }
            }
        }

        if ($sentence === "") {
            continue;
        }

        $blocks[] = [
            "id" => trim((string) ($block["id"] ?? uniqid("listen_order_"))),
            "sentence" => $sentence,
            "images" => $images,
        ];
    }

    return [
        "title" => normalize_listen_order_title($title),
        "blocks" => $blocks,
    ];
}

function encode_listen_order_payload(array $payload): string
{
    return json_encode([
        "title" => normalize_listen_order_title((string) ($payload["title"] ?? "")),
        "blocks" => array_values($payload["blocks"] ?? []),
    ], JSON_UNESCAPED_UNICODE);
}

function load_listen_order_activity(PDO $pdo, string $unit, string $activityId): array
{
    $fallback = [
        "id" => "",
        "title" => default_listen_order_title(),
        "blocks" => [],
    ];

    $row = null;

    if ($activityId !== "") {
        $stmt = $pdo->prepare("
            SELECT id, data
            FROM activities
            WHERE id = :id
              AND type = 'listen_order'
            LIMIT 1
        ");
        $stmt->execute(["id" => $activityId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    if (!$row && $unit !== "") {
        $stmt = $pdo->prepare("
            SELECT id, data
            FROM activities
            WHERE unit_id = :unit
              AND type = 'listen_order'
            ORDER BY id ASC
            LIMIT 1
        ");
        $stmt->execute(["unit" => $unit]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    if (!$row) {
        return $fallback;
    }

    $payload = normalize_listen_order_payload($row["data"] ?? null);

    return [
        "id" => (string) ($row["id"] ?? ""),
        "title" => (string) ($payload["title"] ?? default_listen_order_title()),
        "blocks" => is_array($payload["blocks"] ?? null) ? $payload["blocks"] : [],
    ];
}

function save_listen_order_activity(PDO $pdo, string $unit, string $activityId, string $title, array $blocks): string
{
    $json = encode_listen_order_payload([
        "title" => $title,
        "blocks" => $blocks,
    ]);

    $targetId = $activityId;

    if ($targetId === "") {
        $stmt = $pdo->prepare("
            SELECT id
            FROM activities
            WHERE unit_id = :unit
              AND type = 'listen_order'
            ORDER BY id ASC
            LIMIT 1
        ");
        $stmt->execute(["unit" => $unit]);
        $targetId = trim((string) $stmt->fetchColumn());
    }

    if ($targetId !== "") {
        $stmt = $pdo->prepare("
            UPDATE activities
            SET data = :data
            WHERE id = :id
              AND type = 'listen_order'
        ");
        $stmt->execute([
            "data" => $json,
            "id" => $targetId,
        ]);

        return $targetId;
    }

    $stmt = $pdo->prepare("
        INSERT INTO activities (unit_id, type, data, position, created_at)
        VALUES (
            :unit_id,
            'listen_order',
            :data,
            (
                SELECT COALESCE(MAX(position), 0) + 1
                FROM activities
                WHERE unit_id = :unit_id2
            ),
            CURRENT_TIMESTAMP
        )
        RETURNING id
    ");
    $stmt->execute([
        "unit_id" => $unit,
        "unit_id2" => $unit,
        "data" => $json,
    ]);

    return (string) $stmt->fetchColumn();
}

if ($unit === "" && $activityId !== "") {
    $unit = resolve_unit_from_activity($pdo, $activityId);
}

if ($unit === "") {
    die("Unit not specified");
}

$activity = load_listen_order_activity($pdo, $unit, $activityId);
$activityTitle = (string) ($activity["title"] ?? default_listen_order_title());
$blocks = is_array($activity["blocks"] ?? null) ? $activity["blocks"] : [];

if ($activityId === "" && !empty($activity["id"])) {
    $activityId = (string) $activity["id"];
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $postedTitle = trim((string) ($_POST["activity_title"] ?? ""));
    $blockIds = isset($_POST["block_id"]) && is_array($_POST["block_id"]) ? $_POST["block_id"] : [];
    $sentences = isset($_POST["sentence"]) && is_array($_POST["sentence"]) ? $_POST["sentence"] : [];
    $existingImages = isset($_POST["images_existing"]) && is_array($_POST["images_existing"]) ? $_POST["images_existing"] : [];
    $imageFiles = isset($_FILES["images"]) ? $_FILES["images"] : null;

    $sanitized = [];

    foreach ($sentences as $i => $sentenceRaw) {
        $sentence = trim((string) $sentenceRaw);
        $blockId = trim((string) ($blockIds[$i] ?? uniqid("listen_order_")));

        $images = [];
        if (isset($existingImages[$i]) && is_array($existingImages[$i])) {
            foreach ($existingImages[$i] as $img) {
                $url = trim((string) $img);
                if ($url !== "") {
                    $images[] = $url;
                }
            }
        }

        if (
            $imageFiles &&
            isset($imageFiles["name"][$i]) &&
            is_array($imageFiles["name"][$i])
        ) {
            foreach ($imageFiles["name"][$i] as $k => $name) {
                if (!$name || empty($imageFiles["tmp_name"][$i][$k])) {
                    continue;
                }

                $url = upload_to_cloudinary($imageFiles["tmp_name"][$i][$k]);
                if ($url) {
                    $images[] = $url;
                }
            }
        }

        if ($sentence === "") {
            continue;
        }

        $sanitized[] = [
            "id" => $blockId !== "" ? $blockId : uniqid("listen_order_"),
            "sentence" => $sentence,
            "images" => array_values($images),
        ];
    }

    $savedActivityId = save_listen_order_activity($pdo, $unit, $activityId, $postedTitle, $sanitized);

    $params = [
        "unit=" . urlencode($unit),
        "saved=1"
    ];

    if ($savedActivityId !== "") {
        $params[] = "id=" . urlencode($savedActivityId);
    }

    if ($assignment !== "") {
        $params[] = "assignment=" . urlencode($assignment);
    }

    if ($source !== "") {
        $params[] = "source=" . urlencode($source);
    }

    header("Location: editor.php?" . implode("&", $params));
    exit;
}

ob_start();

if (isset($_GET["saved"])) {
    echo '<p style="color:green;font-weight:bold;margin-bottom:15px;">✔ Saved successfully</p>';
}
?>

<style>
.lo-form{
    max-width:860px;
    margin:0 auto;
    text-align:left;
}
.title-box,
.block-item{
    background:#f9fafb;
    padding:14px;
    margin-bottom:14px;
    border-radius:12px;
    border:1px solid #e5e7eb;
}
.title-box label,
.block-item label{
    display:block;
    font-weight:700;
    margin-bottom:8px;
}
.title-box input,
.block-item input,
.block-item textarea{
    width:100%;
    padding:10px 12px;
    border-radius:8px;
    border:1px solid #d1d5db;
    box-sizing:border-box;
    margin-bottom:12px;
    font-size:14px;
}
.block-item textarea{
    min-height:90px;
    resize:vertical;
}
.image-preview-wrap{
    display:flex;
    gap:8px;
    flex-wrap:wrap;
    margin-bottom:10px;
}
.image-preview{
    display:block;
    max-width:110px;
    max-height:110px;
    object-fit:cover;
    border-radius:10px;
    border:1px solid #d1d5db;
    background:#fff;
}
.toolbar-row{
    display:flex;
    gap:10px;
    flex-wrap:wrap;
    justify-content:center;
    margin-top:8px;
}
.btn-add{
    background:#16a34a;
    color:#fff;
    padding:10px 14px;
    border:none;
    border-radius:8px;
    cursor:pointer;
    font-weight:700;
}
.btn-remove{
    background:#ef4444;
    color:#fff;
    border:none;
    padding:8px 12px;
    border-radius:8px;
    cursor:pointer;
    font-weight:700;
}
.help{
    margin:-6px 0 12px 0;
    color:#6b7280;
    font-size:13px;
}
.save-btn{
    background:linear-gradient(180deg,#0d9488,#0f766e);
    color:#fff;
    padding:10px 20px;
    border:none;
    border-radius:10px;
    cursor:pointer;
    font-weight:800;
    font-family:'Nunito','Segoe UI',sans-serif;
    font-size:15px;
    transition:transform .15s ease, filter .15s ease;
    box-shadow:0 2px 8px rgba(13,148,136,.22);
}
.save-btn:hover{
    filter:brightness(1.07);
    transform:translateY(-1px);
}
</style>

<form method="post" enctype="multipart/form-data" class="lo-form" id="listenOrderForm">
    <div class="title-box">
        <label for="activity_title">Activity title</label>
        <input
            id="activity_title"
            type="text"
            name="activity_title"
            value="<?= htmlspecialchars($activityTitle, ENT_QUOTES, 'UTF-8') ?>"
            placeholder="Example: Listen and order"
            required
        >
    </div>

    <div id="blocksContainer">
        <?php foreach ($blocks as $blockIndex => $block) { ?>
            <div class="block-item">
                <input type="hidden" name="block_id[]" value="<?= htmlspecialchars((string) ($block["id"] ?? uniqid("listen_order_")), ENT_QUOTES, 'UTF-8') ?>">

                <label>Sentence (what students listen to)</label>
                <textarea name="sentence[]" required><?= htmlspecialchars((string) ($block["sentence"] ?? ""), ENT_QUOTES, 'UTF-8') ?></textarea>

                <label>Images in the correct order</label>
                <?php $blockImages = is_array($block["images"] ?? null) ? $block["images"] : []; ?>
                <?php if (!empty($blockImages)) { ?>
                    <div class="image-preview-wrap">
                        <?php foreach ($blockImages as $img) { ?>
                            <div>
                                <img src="<?= htmlspecialchars($img, ENT_QUOTES, 'UTF-8') ?>" class="image-preview" alt="listen-order-image">
                                <input type="hidden" name="images_existing[<?= (int) $blockIndex ?>][]" value="<?= htmlspecialchars($img, ENT_QUOTES, 'UTF-8') ?>">
                            </div>
                        <?php } ?>
                    </div>
                <?php } else { ?>
                    <input type="hidden" name="images_existing[<?= (int) $blockIndex ?>][]" value="">
                <?php } ?>

                <input type="file" name="images[<?= (int) $blockIndex ?>][]" multiple accept="image/*">
                <p class="help">Upload the images in the exact correct order.</p>

                <button type="button" class="btn-remove" onclick="removeBlock(this)">✖ Remove</button>
            </div>
        <?php } ?>
    </div>

    <div class="toolbar-row">
        <button type="button" class="btn-add" onclick="addBlock()">+ Add Block</button>
        <button type="submit" class="save-btn">💾 Save</button>
    </div>
</form>

<script>
let formChanged = false;
let formSubmitted = false;

function markChanged() {
    formChanged = true;
}

function reindexBlockInputs() {
    const blocks = document.querySelectorAll('#blocksContainer .block-item');

    blocks.forEach(function (block, index) {
        const fileInput = block.querySelector('input[type="file"][name^="images["]');
        if (fileInput) {
            fileInput.name = 'images[' + index + '][]';
        }

        const existingInputs = block.querySelectorAll('input[type="hidden"][name^="images_existing["]');
        existingInputs.forEach(function (input) {
            input.name = 'images_existing[' + index + '][]';
        });
    });
}

function removeBlock(button) {
    const item = button.closest('.block-item');
    if (item) {
        item.remove();
        reindexBlockInputs();
        markChanged();
    }
}

function addBlock() {
    const container = document.getElementById('blocksContainer');
    const index = container.querySelectorAll('.block-item').length;
    const div = document.createElement('div');
    div.className = 'block-item';
    div.innerHTML = `
        <input type="hidden" name="block_id[]" value="listen_order_${Date.now()}_${Math.floor(Math.random() * 1000)}">

        <label>Sentence (what students listen to)</label>
        <textarea name="sentence[]" required></textarea>

        <label>Images in the correct order</label>
        <input type="file" name="images[${index}][]" multiple accept="image/*">
        <p class="help">Upload the images in the exact correct order.</p>

        <button type="button" class="btn-remove" onclick="removeBlock(this)">✖ Remove</button>
    `;
    container.appendChild(div);
    reindexBlockInputs();
    bindChangeTracking(div);
    markChanged();
}

function bindChangeTracking(scope) {
    const elements = scope.querySelectorAll('input, textarea, select');
    elements.forEach(function(el) {
        el.addEventListener('input', markChanged);
        el.addEventListener('change', markChanged);
    });
}

document.addEventListener('DOMContentLoaded', function () {
    bindChangeTracking(document);
    reindexBlockInputs();

    const form = document.getElementById('listenOrderForm');
    if (form) {
        form.addEventListener('submit', function () {
            reindexBlockInputs();
            formSubmitted = true;
            formChanged = false;
        });
    }
});

window.addEventListener('beforeunload', function (e) {
    if (formChanged && !formSubmitted) {
        e.preventDefault();
        e.returnValue = '';
    }
});
</script>

<?php
$content = ob_get_clean();
render_activity_editor("🎧 Listen & Order Editor", "🎧", $content);
