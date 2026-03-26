<?php
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

function default_hangman_title(): string
{
    return "Hangman";
}

function normalize_hangman_title(string $title): string
{
    $title = trim($title);
    return $title !== "" ? $title : default_hangman_title();
}

function normalize_hangman_payload($rawData): array
{
    $default = [
        "title" => default_hangman_title(),
        "items" => [],
    ];

    if ($rawData === null || $rawData === "") {
        return $default;
    }

    $decoded = is_string($rawData) ? json_decode($rawData, true) : $rawData;
    if (!is_array($decoded)) {
        return $default;
    }

    $title = "";
    $itemsSource = $decoded;

    if (isset($decoded["title"])) {
        $title = trim((string) $decoded["title"]);
    }

    if (isset($decoded["items"]) && is_array($decoded["items"])) {
        $itemsSource = $decoded["items"];
    } elseif (isset($decoded["words"]) && is_array($decoded["words"])) {
        $itemsSource = $decoded["words"];
    }

    $items = [];
    foreach ($itemsSource as $item) {
        if (is_string($item)) {
            $word = strtoupper(trim($item));
            if ($word !== "") {
                $items[] = [
                    "id" => uniqid("hang_"),
                    "word" => $word,
                    "hint" => "",
                    "image" => "",
                ];
            }
            continue;
        }

        if (!is_array($item)) {
            continue;
        }

        $word = strtoupper(trim((string) ($item["word"] ?? "")));
        $hint = trim((string) ($item["hint"] ?? ""));
        $image = trim((string) ($item["image"] ?? ""));

        if ($word === "") {
            continue;
        }

        $items[] = [
            "id" => trim((string) ($item["id"] ?? uniqid("hang_"))),
            "word" => $word,
            "hint" => $hint,
            "image" => $image,
        ];
    }

    return [
        "title" => normalize_hangman_title($title),
        "items" => $items,
    ];
}

function encode_hangman_payload(array $payload): string
{
    return json_encode([
        "title" => normalize_hangman_title((string) ($payload["title"] ?? "")),
        "items" => array_values($payload["items"] ?? []),
    ], JSON_UNESCAPED_UNICODE);
}

function load_hangman_activity(PDO $pdo, string $unit, string $activityId): array
{
    $fallback = [
        "id" => "",
        "title" => default_hangman_title(),
        "items" => [],
    ];

    $row = null;

    if ($activityId !== "") {
        $stmt = $pdo->prepare("
            SELECT id, data
            FROM activities
            WHERE id = :id
              AND type = 'hangman'
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
              AND type = 'hangman'
            ORDER BY id ASC
            LIMIT 1
        ");
        $stmt->execute(["unit" => $unit]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    if (!$row) {
        return $fallback;
    }

    $payload = normalize_hangman_payload($row["data"] ?? null);

    return [
        "id" => (string) ($row["id"] ?? ""),
        "title" => (string) ($payload["title"] ?? default_hangman_title()),
        "items" => is_array($payload["items"] ?? null) ? $payload["items"] : [],
    ];
}

function save_hangman_activity(PDO $pdo, string $unit, string $activityId, string $title, array $items): string
{
    $json = encode_hangman_payload([
        "title" => $title,
        "items" => $items,
    ]);

    $targetId = $activityId;

    if ($targetId === "") {
        $stmt = $pdo->prepare("
            SELECT id
            FROM activities
            WHERE unit_id = :unit
              AND type = 'hangman'
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
              AND type = 'hangman'
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
            'hangman',
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

$activity = load_hangman_activity($pdo, $unit, $activityId);
$activityTitle = (string) ($activity["title"] ?? default_hangman_title());
$items = is_array($activity["items"] ?? null) ? $activity["items"] : [];

if ($activityId === "" && !empty($activity["id"])) {
    $activityId = (string) $activity["id"];
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $postedTitle = trim((string) ($_POST["activity_title"] ?? ""));
    $itemIds = isset($_POST["item_id"]) && is_array($_POST["item_id"]) ? $_POST["item_id"] : [];
    $words = isset($_POST["word"]) && is_array($_POST["word"]) ? $_POST["word"] : [];
    $hints = isset($_POST["hint"]) && is_array($_POST["hint"]) ? $_POST["hint"] : [];
    $images = isset($_POST["image_existing"]) && is_array($_POST["image_existing"]) ? $_POST["image_existing"] : [];
    $imageFiles = isset($_FILES["image_file"]) ? $_FILES["image_file"] : null;

    $sanitized = [];

    foreach ($words as $i => $wordRaw) {
        $word = strtoupper(trim((string) $wordRaw));
        $hint = trim((string) ($hints[$i] ?? ""));
        $image = trim((string) ($images[$i] ?? ""));
        $itemId = trim((string) ($itemIds[$i] ?? uniqid("hang_")));

        if (
            $imageFiles &&
            isset($imageFiles["name"][$i]) &&
            $imageFiles["name"][$i] !== "" &&
            isset($imageFiles["tmp_name"][$i]) &&
            $imageFiles["tmp_name"][$i] !== ""
        ) {
            $uploadedImage = upload_to_cloudinary($imageFiles["tmp_name"][$i]);
            if ($uploadedImage) {
                $image = $uploadedImage;
            }
        }

        if ($word === "") {
            continue;
        }

        $sanitized[] = [
            "id" => $itemId !== "" ? $itemId : uniqid("hang_"),
            "word" => $word,
            "hint" => $hint,
            "image" => $image,
        ];
    }

    $savedActivityId = save_hangman_activity($pdo, $unit, $activityId, $postedTitle, $sanitized);

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
?>

<style>
.hg-form{
    max-width:900px;
    margin:0 auto;
    text-align:left;
}
.title-box,
.word-item{
    background:#f9fafb;
    padding:14px;
    margin-bottom:14px;
    border-radius:12px;
    border:1px solid #e5e7eb;
}
.title-box label,
.word-item label{
    display:block;
    font-weight:700;
    margin-bottom:8px;
}
.title-box input,
.word-item input,
.word-item textarea{
    width:100%;
    padding:10px 12px;
    border-radius:8px;
    border:1px solid #d1d5db;
    box-sizing:border-box;
    margin-bottom:12px;
    font-size:14px;
}
.word-item textarea{
    min-height:80px;
    resize:vertical;
}
.image-preview{
    display:block;
    max-width:140px;
    max-height:140px;
    object-fit:contain;
    border-radius:10px;
    border:1px solid #d1d5db;
    background:#fff;
    margin-bottom:10px;
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
</style>

<?php if (isset($_GET["saved"])) { ?>
    <p style="color:green;font-weight:bold;margin-bottom:15px;">✔ Saved successfully</p>
<?php } ?>

<form class="hg-form" id="hangmanForm" method="post" enctype="multipart/form-data">
    <div class="title-box">
        <label for="activity_title">Activity title</label>
        <input
            id="activity_title"
            type="text"
            name="activity_title"
            value="<?= htmlspecialchars($activityTitle, ENT_QUOTES, 'UTF-8') ?>"
            placeholder="Example: Guess the shapes"
            required
        >
    </div>

    <div id="itemsContainer">
        <?php foreach ($items as $item) { ?>
            <div class="word-item">
                <input type="hidden" name="item_id[]" value="<?= htmlspecialchars((string) ($item["id"] ?? uniqid("hang_")), ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="image_existing[]" value="<?= htmlspecialchars((string) ($item["image"] ?? ""), ENT_QUOTES, 'UTF-8') ?>">

                <label>Word or phrase</label>
                <input type="text" name="word[]" value="<?= htmlspecialchars((string) ($item["word"] ?? ""), ENT_QUOTES, 'UTF-8') ?>" required>

                <label>Hint text</label>
                <textarea name="hint[]" placeholder="Example: A shape with 4 sides."><?= htmlspecialchars((string) ($item["hint"] ?? ""), ENT_QUOTES, 'UTF-8') ?></textarea>

                <label>Hint image (optional)</label>
                <?php if (!empty($item["image"])) { ?>
                    <img src="<?= htmlspecialchars((string) $item["image"], ENT_QUOTES, 'UTF-8') ?>" alt="hint-image" class="image-preview">
                <?php } ?>
                <input type="file" name="image_file[]" accept="image/*">

                <button type="button" class="btn-remove" onclick="removeItem(this)">✖ Remove</button>
            </div>
        <?php } ?>
    </div>

    <div class="toolbar-row">
        <button type="button" class="btn-add" onclick="addItem()">+ Add Word</button>
        <button type="submit" class="save-btn">💾 Save</button>
    </div>
</form>

<script>
let formChanged = false;
let formSubmitted = false;

function markChanged() {
    formChanged = true;
}

function removeItem(button) {
    const item = button.closest('.word-item');
    if (item) {
        item.remove();
        markChanged();
    }
}

function addItem() {
    const container = document.getElementById('itemsContainer');
    const div = document.createElement('div');
    div.className = 'word-item';
    div.innerHTML = `
        <input type="hidden" name="item_id[]" value="hang_${Date.now()}_${Math.floor(Math.random() * 1000)}">
        <input type="hidden" name="image_existing[]" value="">

        <label>Word or phrase</label>
        <input type="text" name="word[]" required>

        <label>Hint text</label>
        <textarea name="hint[]" placeholder="Example: A shape with 4 sides."></textarea>

        <label>Hint image (optional)</label>
        <input type="file" name="image_file[]" accept="image/*">

        <button type="button" class="btn-remove" onclick="removeItem(this)">✖ Remove</button>
    `;
    container.appendChild(div);
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

    const form = document.getElementById('hangmanForm');
    if (form) {
        form.addEventListener('submit', function () {
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
render_activity_editor("🎯 Hangman Editor", "🎯", $content);
