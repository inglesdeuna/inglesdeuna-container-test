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

function normalize_listen_order_payload(mixed $rawData): array
{
    $default = [
        "title"        => default_listen_order_title(),
        "instructions" => "",
        "blocks"       => [],
    ];

    if ($rawData === null || $rawData === "") {
        return $default;
    }

    $decoded = is_string($rawData) ? json_decode($rawData, true) : $rawData;
    if (!is_array($decoded)) {
        return $default;
    }

    $title        = isset($decoded["title"])        ? trim((string) $decoded["title"])        : "";
    $instructions = isset($decoded["instructions"]) ? trim((string) $decoded["instructions"]) : "";
    $blocksSource = $decoded;

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

        $dropZoneImages = [];
        if (isset($block["dropZoneImages"]) && is_array($block["dropZoneImages"])) {
            foreach ($block["dropZoneImages"] as $dzi) {
                if (!is_array($dzi)) {
                    continue;
                }
                $dzSrc = trim((string) ($dzi["src"] ?? ""));
                if ($dzSrc === "") {
                    continue;
                }
                $dropZoneImages[] = [
                    "id"    => trim((string) ($dzi["id"] ?? uniqid("dzi_"))),
                    "src"   => $dzSrc,
                    "left"  => (int) ($dzi["left"] ?? 0),
                    "top"   => (int) ($dzi["top"] ?? 0),
                    "width" => max(60, min(800, (int) ($dzi["width"] ?? 180))),
                ];
            }
        }

        if ($sentence === "") {
            continue;
        }

        $blocks[] = [
            "id"             => trim((string) ($block["id"] ?? uniqid("listen_order_"))),
            "sentence"       => $sentence,
            "video_url"      => trim((string) ($block["video_url"] ?? "")),
            "images"         => $images,
            "dropZoneImages" => $dropZoneImages,
        ];
    }

    return [
        "title"        => normalize_listen_order_title($title),
        "instructions" => $instructions,
        "blocks"       => $blocks,
    ];
}

function encode_listen_order_payload(array $payload): string
{
    return json_encode([
        "title"        => normalize_listen_order_title((string) ($payload["title"]        ?? "")),
        "instructions" => trim((string) ($payload["instructions"] ?? "")),
        "blocks"       => array_values($payload["blocks"] ?? []),
    ], JSON_UNESCAPED_UNICODE);
}

function load_listen_order_activity(PDO $pdo, string $unit, string $activityId): array
{
    $fallback = [
        "id"           => "",
        "title"        => default_listen_order_title(),
        "instructions" => "",
        "blocks"       => [],
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
        "id"           => (string) ($row["id"] ?? ""),
        "title"        => (string) ($payload["title"]        ?? default_listen_order_title()),
        "instructions" => (string) ($payload["instructions"] ?? ""),
        "blocks"       => is_array($payload["blocks"] ?? null) ? $payload["blocks"] : [],
    ];
}

function save_listen_order_activity(PDO $pdo, string $unit, string $activityId, string $title, string $instructions, array $blocks): string
{
    $json = encode_listen_order_payload([
        "title"        => $title,
        "instructions" => $instructions,
        "blocks"       => $blocks,
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
$activityTitle        = (string) ($activity["title"]        ?? default_listen_order_title());
$activityInstructions = (string) ($activity["instructions"] ?? "");
$blocks               = is_array($activity["blocks"] ?? null) ? $activity["blocks"] : [];

if ($activityId === "" && !empty($activity["id"])) {
    $activityId = (string) $activity["id"];
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $postedTitle        = trim((string) ($_POST["activity_title"]        ?? ""));
    $postedInstructions = trim((string) ($_POST["activity_instructions"] ?? ""));
    $blockIds        = isset($_POST["block_id"])           && is_array($_POST["block_id"])           ? $_POST["block_id"]           : [];
    $sentences       = isset($_POST["sentence"])           && is_array($_POST["sentence"])           ? $_POST["sentence"]           : [];
    $existingImages  = isset($_POST["images_existing"])    && is_array($_POST["images_existing"])    ? $_POST["images_existing"]    : [];
    $videoExisting   = isset($_POST["video_url_existing"]) && is_array($_POST["video_url_existing"]) ? $_POST["video_url_existing"] : [];
    $imageFiles      = isset($_FILES["images"])            ? $_FILES["images"]                       : null;
    $videoFiles      = isset($_FILES["video_file"])        ? $_FILES["video_file"]                   : null;

    /* Drop zone image POST fields */
    $dzExistingByBlock = isset($_POST["dz_image_existing"]) && is_array($_POST["dz_image_existing"]) ? $_POST["dz_image_existing"] : [];
    $dzLeftByBlock     = isset($_POST["dz_image_left"])     && is_array($_POST["dz_image_left"])     ? $_POST["dz_image_left"]     : [];
    $dzTopByBlock      = isset($_POST["dz_image_top"])      && is_array($_POST["dz_image_top"])      ? $_POST["dz_image_top"]      : [];
    $dzWidthByBlock    = isset($_POST["dz_image_width"])    && is_array($_POST["dz_image_width"])    ? $_POST["dz_image_width"]    : [];
    $dzIdByBlock       = isset($_POST["dz_image_id"])       && is_array($_POST["dz_image_id"])       ? $_POST["dz_image_id"]       : [];
    $dzFileInput       = isset($_FILES["dz_image_file"])    ? $_FILES["dz_image_file"]               : null;

    $sanitized = [];

    foreach ($sentences as $i => $sentenceRaw) {
        $sentence = trim((string) $sentenceRaw);
        $blockId  = trim((string) ($blockIds[$i] ?? uniqid("listen_order_")));

        /* Chip images */
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

        /* Video file upload */
        $videoUrl = trim((string) ($videoExisting[$i] ?? ""));
        if (
            $videoFiles &&
            isset($videoFiles["tmp_name"][$i]) &&
            !empty($videoFiles["tmp_name"][$i]) &&
            ($videoFiles["error"][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK
        ) {
            $uploadedVideo = upload_to_cloudinary($videoFiles["tmp_name"][$i]);
            if ($uploadedVideo) {
                $videoUrl = $uploadedVideo;
            }
        }

        /* Drop zone background image */
        $dzSrc   = trim((string) ($dzExistingByBlock[$i] ?? ""));
        $dzLeft  = (int) ($dzLeftByBlock[$i]  ?? 0);
        $dzTop   = (int) ($dzTopByBlock[$i]   ?? 0);
        $dzWidth = max(60, min(800, (int) ($dzWidthByBlock[$i] ?? 180)));
        $dzId    = trim((string) ($dzIdByBlock[$i] ?? ""));

        if (
            $dzFileInput &&
            isset($dzFileInput["tmp_name"][$i]) &&
            !empty($dzFileInput["tmp_name"][$i]) &&
            ($dzFileInput["error"][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK
        ) {
            $uploadedDz = upload_to_cloudinary($dzFileInput["tmp_name"][$i]);
            if ($uploadedDz) {
                $dzSrc = $uploadedDz;
                $dzId  = uniqid("dzi_");
            }
        }

        if ($dzId === "") {
            $dzId = uniqid("dzi_");
        }

        $dzImages = [];
        if ($dzSrc !== "") {
            $dzImages = [
                [
                    "id"    => $dzId,
                    "src"   => $dzSrc,
                    "left"  => $dzLeft,
                    "top"   => $dzTop,
                    "width" => $dzWidth,
                ],
            ];
        }

        if ($sentence === "" && $videoUrl === "") {
            continue;
        }

        $sanitized[] = [
            "id"             => $blockId !== "" ? $blockId : uniqid("listen_order_"),
            "sentence"       => $sentence,
            "video_url"      => $videoUrl,
            "images"         => array_values($images),
            "dropZoneImages" => $dzImages,
        ];
    }

    $savedActivityId = save_listen_order_activity($pdo, $unit, $activityId, $postedTitle, $postedInstructions, $sanitized);

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
    echo '<div class="lo-saved">✓ Saved successfully</div>';
}
?>

<link href="https://fonts.googleapis.com/css2?family=Fredoka:wght@500;600;700&family=Nunito:wght@600;700;800;900&display=swap" rel="stylesheet">

<style>
*{box-sizing:border-box}

body{background:#f8f7ff!important;font-family:'Nunito','Segoe UI',sans-serif!important}

.lo-form{
    max-width:780px;
    margin:0 auto;
    text-align:left;
    font-family:'Nunito','Segoe UI',sans-serif;
}

/* Saved message */
.lo-saved{
    background:#E6F9F2;
    border:1px solid #9FE1CB;
    border-radius:12px;
    padding:10px 16px;
    color:#0F6E56;
    font-family:'Nunito',sans-serif;
    font-size:13px;
    font-weight:900;
    margin-bottom:16px;
}

/* Cards */
.lo-card,
.block-item{
    background:#ffffff;
    border:1px solid #F0EEF8;
    border-radius:20px;
    padding:20px 22px;
    margin-bottom:14px;
    box-shadow:0 4px 18px rgba(127,119,221,.08);
}

/* Field labels */
.field-label{
    display:block;
    font-size:12px;
    font-weight:900;
    letter-spacing:.08em;
    text-transform:uppercase;
    color:#9B94BE;
    margin-bottom:8px;
    font-family:'Nunito',sans-serif;
}

.field-badge{
    display:inline-block;
    background:#EEEDFE;
    color:#534AB7;
    border-radius:999px;
    padding:2px 8px;
    font-size:10px;
    font-weight:700;
    letter-spacing:0;
    text-transform:none;
    margin-left:6px;
    vertical-align:middle;
}

/* Inputs */
.lo-form input[type="text"],
.lo-form input[type="url"],
.lo-form input[type="number"],
.lo-form textarea{
    width:100%;
    border:1.5px solid #EDE9FA;
    border-radius:12px;
    padding:11px 14px;
    font-family:'Nunito',sans-serif;
    font-size:14px;
    font-weight:700;
    color:#271B5D;
    background:#ffffff;
    outline:none;
    margin-bottom:12px;
    transition:border-color .15s,box-shadow .15s;
}

.lo-form input[type="text"]:focus,
.lo-form input[type="url"]:focus,
.lo-form input[type="number"]:focus,
.lo-form textarea:focus{
    border-color:#7F77DD;
    box-shadow:0 0 0 3px rgba(127,119,221,.10);
}

.lo-form textarea{
    min-height:60px;
    resize:vertical;
}

/* Block header */
.block-header-row{
    display:flex;
    justify-content:space-between;
    align-items:center;
    margin-bottom:16px;
}

.block-badge{
    background:#EEEDFE;
    color:#534AB7;
    border-radius:999px;
    padding:4px 14px;
    font-family:'Nunito',sans-serif;
    font-size:12px;
    font-weight:900;
}

.btn-remove{
    background:#FCEBEB;
    color:#E24B4A;
    border:1px solid #F7C1C1;
    border-radius:999px;
    padding:5px 14px;
    font-family:'Nunito',sans-serif;
    font-size:12px;
    font-weight:900;
    cursor:pointer;
    transition:transform .12s;
}
.btn-remove:hover{transform:translateY(-1px)}

/* Media toggle */
.media-toggle-row{
    display:flex;
    gap:8px;
    flex-wrap:wrap;
    margin-bottom:14px;
}

.media-tab{
    border:1.5px solid #EDE9FA;
    background:#ffffff;
    color:#534AB7;
    border-radius:999px;
    padding:7px 18px;
    font-family:'Nunito',sans-serif;
    font-size:12px;
    font-weight:900;
    cursor:pointer;
    transition:all .15s;
}

.media-tab.active{
    background:#7F77DD;
    color:#ffffff;
    border-color:#7F77DD;
    box-shadow:0 6px 18px rgba(127,119,221,.22);
}

/* Audio upload zone */
.audio-upload-zone{
    border:2px dashed #EDE9FA;
    border-radius:14px;
    background:#FAFAFE;
    padding:20px;
    text-align:center;
    cursor:pointer;
    margin-bottom:14px;
    transition:border-color .15s,background .15s;
}
.audio-upload-zone:hover{
    border-color:#7F77DD;
    background:#EEEDFE;
}
.audio-upload-icon{
    width:40px;
    height:40px;
    border-radius:50%;
    background:#EEEDFE;
    display:flex;
    align-items:center;
    justify-content:center;
    margin:0 auto 8px;
    font-size:18px;
}
.audio-upload-title{
    font-family:'Nunito',sans-serif;
    font-size:13px;
    font-weight:900;
    color:#534AB7;
    margin-bottom:3px;
}
.audio-upload-sub{
    font-family:'Nunito',sans-serif;
    font-size:11px;
    font-weight:700;
    color:#9B94BE;
}
.audio-file-pill{
    display:inline-block;
    background:#EEEDFE;
    color:#534AB7;
    border-radius:999px;
    padding:4px 12px;
    font-family:'Nunito',sans-serif;
    font-size:12px;
    font-weight:900;
    margin-top:8px;
}

/* Video upload zone (mirrors audio) */
.video-upload-zone{
    border:2px dashed #EDE9FA;
    border-radius:14px;
    background:#FAFAFE;
    padding:20px;
    text-align:center;
    cursor:pointer;
    margin-bottom:14px;
    transition:border-color .15s,background .15s;
}
.video-upload-zone:hover{
    border-color:#7F77DD;
    background:#EEEDFE;
}
.video-upload-icon{
    width:40px;
    height:40px;
    border-radius:50%;
    background:#EEEDFE;
    display:flex;
    align-items:center;
    justify-content:center;
    margin:0 auto 8px;
    font-size:18px;
}
.video-upload-title{
    font-family:'Nunito',sans-serif;
    font-size:13px;
    font-weight:900;
    color:#534AB7;
    margin-bottom:3px;
}
.video-upload-sub{
    font-family:'Nunito',sans-serif;
    font-size:11px;
    font-weight:700;
    color:#9B94BE;
}
.video-file-pill{
    display:inline-block;
    background:#EEEDFE;
    color:#534AB7;
    border-radius:999px;
    padding:4px 12px;
    font-family:'Nunito',sans-serif;
    font-size:12px;
    font-weight:900;
    margin-top:8px;
}
.video-preview-wrap{
    position:relative;
    border-radius:12px;
    overflow:hidden;
    background:#000;
    margin-bottom:10px;
    max-height:220px;
    display:flex;
    align-items:center;
    justify-content:center;
}
.video-preview-wrap video{
    width:100%;
    max-height:220px;
    object-fit:contain;
    display:block;
}
.btn-remove-video{
    background:#FCEBEB;
    color:#E24B4A;
    border:1px solid #F7C1C1;
    border-radius:999px;
    padding:5px 14px;
    font-family:'Nunito',sans-serif;
    font-size:12px;
    font-weight:900;
    cursor:pointer;
    transition:transform .12s;
    margin-bottom:10px;
}
.btn-remove-video:hover{transform:translateY(-1px)}

/* Video URL hint */
.field-hint{
    color:#9B94BE;
    font-family:'Nunito',sans-serif;
    font-size:12px;
    font-weight:800;
    margin:-6px 0 12px;
}

/* Image grid */
.img-grid{
    display:flex;
    flex-wrap:wrap;
    gap:10px;
    margin-bottom:8px;
    align-items:flex-start;
}

.img-card{
    position:relative;
    width:86px;
    border-radius:14px;
    border:1.5px solid #EDE9FA;
    overflow:visible;
    background:#ffffff;
    display:flex;
    flex-direction:column;
    align-items:center;
}

.img-card img{
    width:86px;
    height:86px;
    object-fit:cover;
    border-radius:12px;
    display:block;
}

.img-pos-badge{
    position:absolute;
    top:4px;
    left:4px;
    width:20px;
    height:20px;
    border-radius:50%;
    background:#7F77DD;
    color:#ffffff;
    font-size:10px;
    font-family:'Nunito',sans-serif;
    font-weight:900;
    display:flex;
    align-items:center;
    justify-content:center;
    z-index:2;
    pointer-events:none;
}

.img-remove-btn{
    border:none;
    background:none;
    color:#E24B4A;
    font-size:10px;
    font-family:'Nunito',sans-serif;
    font-weight:900;
    cursor:pointer;
    padding:3px 0 2px;
    text-align:center;
    width:100%;
    line-height:1;
}

.img-add-slot{
    width:86px;
    height:86px;
    border-radius:14px;
    border:1.5px dashed #EDE9FA;
    background:#FAFAFE;
    display:flex;
    align-items:center;
    justify-content:center;
    cursor:pointer;
    transition:border-color .15s,background .15s;
}

.img-add-slot:hover{
    border-color:#7F77DD;
    background:#EEEDFE;
}

.img-add-slot label{
    cursor:pointer;
    display:flex;
    flex-direction:column;
    align-items:center;
    justify-content:center;
    width:100%;
    height:100%;
    font-family:'Nunito',sans-serif;
    font-size:11px;
    font-weight:900;
    color:#9B94BE;
    gap:4px;
    margin:0;
    letter-spacing:0;
    text-transform:none;
}

.img-add-slot label .plus{
    font-size:22px;
    color:#7F77DD;
    line-height:1;
}

/* Drop zone section */
.dz-section{
    margin-top:14px;
    padding-top:14px;
    border-top:1px solid #F0EEF8;
}

.dz-preview-area{
    position:relative;
    width:100%;
    max-width:680px;
    height:160px;
    border:2px dashed #EDE9FA;
    border-radius:14px;
    background:#FAFAFE;
    overflow:hidden;
    margin-bottom:8px;
    cursor:default;
}

.dz-preview-hint{
    position:absolute;
    inset:0;
    display:flex;
    align-items:center;
    justify-content:center;
    color:#9B94BE;
    font-size:13px;
    font-style:italic;
    pointer-events:none;
    font-family:'Nunito',sans-serif;
}

.dz-preview-image{
    position:absolute;
    cursor:move;
    user-select:none;
    touch-action:none;
    height:auto;
    border:2px solid #7F77DD;
    border-radius:6px;
    box-shadow:0 2px 8px rgba(0,0,0,.15);
}

.dz-controls-row{
    display:flex;
    gap:8px;
    align-items:center;
    flex-wrap:wrap;
    margin-bottom:8px;
}

.dz-width-control{
    display:flex;
    align-items:center;
    gap:6px;
    font-size:13px;
    color:#534AB7;
    font-family:'Nunito',sans-serif;
    font-weight:700;
}

.dz-width-control input{
    width:70px!important;
    padding:4px 6px!important;
    margin-bottom:0!important;
}

.btn-remove-dz{
    background:#FCEBEB;
    color:#E24B4A;
    border:1px solid #F7C1C1;
    border-radius:999px;
    padding:5px 14px;
    font-family:'Nunito',sans-serif;
    font-size:12px;
    font-weight:900;
    cursor:pointer;
    transition:transform .12s;
    white-space:nowrap;
}
.btn-remove-dz:hover{transform:translateY(-1px)}

/* Toolbar */
.toolbar-row{
    display:flex;
    gap:10px;
    flex-wrap:wrap;
    justify-content:center;
    padding-top:20px;
    border-top:1px solid #F0EEF8;
    margin-top:8px;
}

.btn-add{
    background:#ffffff;
    color:#534AB7;
    border:1.5px solid #EDE9FA;
    border-radius:999px;
    padding:12px 26px;
    font-family:'Nunito',sans-serif;
    font-size:13px;
    font-weight:900;
    cursor:pointer;
    transition:transform .12s;
}
.btn-add:hover{transform:translateY(-2px)}

.save-btn{
    background:#F97316;
    color:#ffffff;
    border:none;
    border-radius:999px;
    padding:12px 26px;
    font-family:'Nunito',sans-serif;
    font-size:13px;
    font-weight:900;
    cursor:pointer;
    box-shadow:0 6px 18px rgba(249,115,22,.22);
    transition:transform .12s,filter .12s;
}
.save-btn:hover{transform:translateY(-2px);filter:brightness(1.07)}

@media(max-width:640px){
    .lo-form{padding:0 4px}
    .media-toggle-row{gap:6px}
    .media-tab{padding:6px 12px;font-size:11px}
    .toolbar-row{flex-direction:column;align-items:center}
    .btn-add,.save-btn{width:100%;max-width:300px;justify-content:center}
}
</style>

<form method="post" enctype="multipart/form-data" class="lo-form" id="listenOrderForm">

    <!-- Activity meta -->
    <div class="lo-card">
        <label class="field-label" for="activity_title">Activity title</label>
        <input
            id="activity_title"
            type="text"
            name="activity_title"
            value="<?= htmlspecialchars($activityTitle, ENT_QUOTES, 'UTF-8') ?>"
            placeholder="Example: Listen and order"
            required
        >

        <label class="field-label" for="activity_instructions">
            Instructions
            <span class="field-badge">shown below the title</span>
        </label>
        <textarea
            id="activity_instructions"
            name="activity_instructions"
            placeholder="Example: Listen to the audio and put the pictures in the correct order."
        ><?= htmlspecialchars($activityInstructions, ENT_QUOTES, 'UTF-8') ?></textarea>
    </div>

    <div id="blocksContainer">
        <?php foreach ($blocks as $blockIndex => $block):
            $dzImages  = is_array($block["dropZoneImages"] ?? null) ? $block["dropZoneImages"] : [];
            $firstDzi  = $dzImages[0] ?? null;
            $dzSrc     = (string)  ($firstDzi["src"]   ?? "");
            $dzLeft    = (int)     ($firstDzi["left"]  ?? 10);
            $dzTop     = (int)     ($firstDzi["top"]   ?? 10);
            $dzWidth   = max(60, min(800, (int) ($firstDzi["width"] ?? 180)));
            $dzId      = (string)  ($firstDzi["id"]    ?? uniqid("dzi_"));
            $blockImages = is_array($block["images"] ?? null) ? $block["images"] : [];
            $hasSentence = trim((string) ($block["sentence"] ?? "")) !== "";
        ?>
        <div class="block-item">
            <input type="hidden" name="block_id[]" value="<?= htmlspecialchars((string) ($block["id"] ?? uniqid("listen_order_")), ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="video_url_existing[]" class="lo-block-vidurl" value="<?= htmlspecialchars($blockVideoUrl, ENT_QUOTES, 'UTF-8') ?>">

            <!-- Block header -->
            <div class="block-header-row">
                <span class="block-badge">Block <?= (int) $blockIndex + 1 ?></span>
                <button type="button" class="btn-remove" onclick="removeBlock(this)">✖ Remove</button>
            </div>

            <!-- Media type toggle -->
            <?php
                $blockVideoUrl = trim((string) ($block["video_url"] ?? ""));
                $activeMode = $blockVideoUrl !== "" ? "video-file" : ($hasSentence ? "audio" : "none");
            ?>
            <div class="media-toggle-row">
                <button type="button" class="media-tab<?= $activeMode === 'audio'      ? ' active' : '' ?>" data-mode="audio"      onclick="setMediaMode(this,'audio')">Audio file</button>
                <button type="button" class="media-tab<?= $activeMode === 'video-file' ? ' active' : '' ?>" data-mode="video-file" onclick="setMediaMode(this,'video-file')">Video file</button>
                <button type="button" class="media-tab<?= $activeMode === 'video'      ? ' active' : '' ?>" data-mode="video"      onclick="setMediaMode(this,'video')">Video URL</button>
                <button type="button" class="media-tab<?= $activeMode === 'none'       ? ' active' : '' ?>" data-mode="none"       onclick="setMediaMode(this,'none')">No media</button>
            </div>

            <!-- Audio section -->
            <div class="media-section audio-section"<?= $activeMode !== 'audio' ? ' style="display:none"' : '' ?>>
                <div class="audio-upload-zone" onclick="this.querySelector('input[type=file]').click()">
                    <div class="audio-upload-icon">🎵</div>
                    <div class="audio-upload-title">Upload audio file</div>
                    <div class="audio-upload-sub">MP3, WAV, OGG</div>
                    <input type="file" accept="audio/*" style="display:none" onchange="showAudioPill(this)">
                </div>
                <label class="field-label">
                    Sentence / transcript
                    <span class="field-badge">optional — shown to students</span>
                </label>
                <textarea name="sentence[]"><?= htmlspecialchars((string) ($block["sentence"] ?? ""), ENT_QUOTES, 'UTF-8') ?></textarea>
            </div>

            <!-- Video file section -->
            <div class="media-section video-file-section"<?= $activeMode !== 'video-file' ? ' style="display:none"' : '' ?>>
                <?php if ($blockVideoUrl !== ""): ?>
                <div class="video-preview-wrap">
                    <video src="<?= htmlspecialchars($blockVideoUrl, ENT_QUOTES, 'UTF-8') ?>" controls preload="metadata"></video>
                </div>
                <button type="button" class="btn-remove-video" onclick="removeVideoFile(this)">✖ Remove video</button>
                <?php else: ?>
                <div class="video-upload-zone" onclick="this.querySelector('input[type=file]').click()">
                    <div class="video-upload-icon">🎬</div>
                    <div class="video-upload-title">Upload video file</div>
                    <div class="video-upload-sub">MP4, MOV, WEBM</div>
                    <input type="file" name="video_file[<?= (int) $blockIndex ?>]" accept="video/*" style="display:none" onchange="showVideoPreview(this)">
                </div>
                <?php endif; ?>
            </div>

            <!-- Video URL section -->
            <div class="media-section video-section"<?= $activeMode !== 'video' ? ' style="display:none"' : '' ?>>
                <label class="field-label">Video URL</label>
                <input type="url" placeholder="https://youtube.com/watch?v=... or direct video URL">
                <div class="field-hint">Supports YouTube, Vimeo, or direct MP4 links.</div>
            </div>

            <!-- No media section -->
            <div class="media-section none-section"<?= $activeMode !== 'none' ? ' style="display:none"' : '' ?>>
                <input type="hidden" name="video_url_existing[]" class="vf-url-existing" value="">
                <textarea name="sentence[]" style="display:none"><?= htmlspecialchars((string) ($block["sentence"] ?? ""), ENT_QUOTES, 'UTF-8') ?></textarea>
            </div>

            <!-- Images in correct order -->
            <label class="field-label" style="margin-top:4px;">
                Images in correct order
                <span class="field-badge">upload in the exact order students should arrange them</span>
            </label>

            <div class="img-grid">
                <?php foreach ($blockImages as $imgIdx => $img): ?>
                <div class="img-card">
                    <span class="img-pos-badge"><?= (int) $imgIdx + 1 ?></span>
                    <img src="<?= htmlspecialchars($img, ENT_QUOTES, 'UTF-8') ?>" alt="image <?= (int) $imgIdx + 1 ?>">
                    <input type="hidden" name="images_existing[<?= (int) $blockIndex ?>][]" value="<?= htmlspecialchars($img, ENT_QUOTES, 'UTF-8') ?>">
                    <button type="button" class="img-remove-btn" onclick="removeImgCard(this)">Remove</button>
                </div>
                <?php endforeach; ?>
                <?php if (empty($blockImages)): ?>
                    <input type="hidden" name="images_existing[<?= (int) $blockIndex ?>][]" value="">
                <?php endif; ?>
                <div class="img-add-slot">
                    <label>
                        <span class="plus">+</span>
                        <span>Add more</span>
                        <input type="file" name="images[<?= (int) $blockIndex ?>][]" multiple accept="image/*" style="display:none">
                    </label>
                </div>
            </div>
            <div class="field-hint">Upload images in the exact correct order. Students will see them shuffled.</div>

            <!-- Drop zone background image -->
            <div class="dz-section">
                <label class="field-label">
                    Drop zone background image
                    <span class="field-badge">optional — visible behind answer chips</span>
                </label>

                <input type="hidden" name="dz_image_existing[<?= (int) $blockIndex ?>]" class="dz-src"   value="<?= htmlspecialchars($dzSrc, ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="dz_image_left[<?= (int) $blockIndex ?>]"     class="dz-left"  value="<?= $dzLeft ?>">
                <input type="hidden" name="dz_image_top[<?= (int) $blockIndex ?>]"      class="dz-top"   value="<?= $dzTop ?>">
                <input type="hidden" name="dz_image_width[<?= (int) $blockIndex ?>]"    class="dz-width" value="<?= $dzWidth ?>">
                <input type="hidden" name="dz_image_id[<?= (int) $blockIndex ?>]"       class="dz-imgid" value="<?= htmlspecialchars($dzId, ENT_QUOTES, 'UTF-8') ?>">

                <div class="dz-preview-area"<?= $dzSrc ? '' : ' style="display:none;"' ?>>
                    <div class="dz-preview-hint">Drag the image to reposition it</div>
                    <?php if ($dzSrc): ?>
                    <img
                        src="<?= htmlspecialchars($dzSrc, ENT_QUOTES, 'UTF-8') ?>"
                        class="dz-preview-image"
                        style="left:<?= $dzLeft ?>px;top:<?= $dzTop ?>px;width:<?= $dzWidth ?>px;"
                        draggable="false"
                        alt=""
                    >
                    <?php endif; ?>
                </div>

                <div class="dz-controls-row">
                    <input type="file" name="dz_image_file[<?= (int) $blockIndex ?>]" accept="image/*" class="dz-file-input" style="margin-bottom:0;">
                    <button type="button" class="btn-remove-dz" onclick="removeDzImage(this)"<?= $dzSrc ? '' : ' style="display:none;"' ?>>✖ Remove BG image</button>
                </div>

                <div class="dz-width-control"<?= $dzSrc ? '' : ' style="display:none;"' ?>>
                    <span>Width:</span>
                    <input type="number" min="60" max="800" value="<?= $dzWidth ?>" class="dz-width-display">
                    <span>px</span>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <div class="toolbar-row">
        <button type="button" class="btn-add" onclick="addBlock()">+ Add Block</button>
        <button type="submit" class="save-btn">💾 Save</button>
    </div>
</form>

<script>
var formChanged = false;
var formSubmitted = false;

function markChanged() {
    formChanged = true;
}

/* ── Media mode toggle ── */
function setMediaMode(btn, mode) {
    var block = btn.closest('.block-item');
    if (!block) return;
    block.querySelectorAll('.media-tab').forEach(function (tab) {
        tab.classList.remove('active');
    });
    btn.classList.add('active');
    block.querySelectorAll('.media-section').forEach(function (section) {
        section.style.display = 'none';
    });
    var target = block.querySelector('.' + mode + '-section');
    if (target) target.style.display = '';
    markChanged();
}

/* ── Video file preview ── */
function showVideoPreview(input) {
    if (!input.files || !input.files[0]) return;
    var section = input.closest('.video-file-section');
    if (!section) return;
    var file = input.files[0];
    var url  = URL.createObjectURL(file);

    // Replace the upload zone with a preview + remove button
    var zone = input.closest('.video-upload-zone');
    if (zone) zone.remove();

    var wrap = document.createElement('div');
    wrap.className = 'video-preview-wrap';
    wrap.innerHTML = '<video src="' + url + '" controls preload="metadata"></video>';

    var removeBtn = document.createElement('button');
    removeBtn.type = 'button';
    removeBtn.className = 'btn-remove-video';
    removeBtn.textContent = '✖ Remove video';
    removeBtn.onclick = function () { removeVideoFile(removeBtn); };

    var labelEl = section.querySelector('.field-label');
    section.insertBefore(removeBtn, labelEl);
    section.insertBefore(wrap, removeBtn);

    // Move the file input outside the zone so it still submits
    section.insertBefore(input, wrap);
    input.style.display = 'none';
    markChanged();
}

function removeVideoFile(btn) {
    var section = btn.closest('.video-file-section');
    if (!section) return;

    var preview = section.querySelector('.video-preview-wrap');
    if (preview) preview.remove();
    btn.remove();

    var existingInput = section.querySelector('.vf-url-existing');
    if (existingInput) existingInput.value = '';

    var fileInput = section.querySelector('input[type="file"][name="video_file[]"]');
    if (fileInput) { fileInput.value = ''; fileInput.remove(); }

    // Restore the upload zone
    var zone = document.createElement('div');
    zone.className = 'video-upload-zone';
    zone.onclick = function () { zone.querySelector('input[type=file]').click(); };
    zone.innerHTML =
        '<div class="video-upload-icon">🎬</div>' +
        '<div class="video-upload-title">Upload video file</div>' +
        '<div class="video-upload-sub">MP4, MOV, WEBM</div>' +
        '<input type="file" name="video_file[]" accept="video/*" style="display:none" onchange="showVideoPreview(this)">';
    var labelEl = section.querySelector('.field-label');
    section.insertBefore(zone, labelEl);
    markChanged();
}

/* ── Audio file pill ── */
function showAudioPill(input) {
    var zone = input.closest('.audio-upload-zone');
    if (!zone) return;
    var existing = zone.querySelector('.audio-file-pill');
    if (existing) existing.remove();
    if (input.files && input.files[0]) {
        var pill = document.createElement('div');
        pill.className = 'audio-file-pill';
        pill.textContent = input.files[0].name;
        zone.appendChild(pill);
    }
}

/* ── Image card removal ── */
function removeImgCard(btn) {
    var card = btn.closest('.img-card');
    if (!card) return;
    var grid = card.closest('.img-grid');
    card.remove();
    if (grid) {
        grid.querySelectorAll('.img-card').forEach(function (c, i) {
            var badge = c.querySelector('.img-pos-badge');
            if (badge) badge.textContent = String(i + 1);
        });
    }
    markChanged();
}

/* ── Block badge renumber ── */
function reindexBlockBadges() {
    document.querySelectorAll('#blocksContainer .block-item').forEach(function (block, i) {
        var badge = block.querySelector('.block-badge');
        if (badge) badge.textContent = 'Block ' + (i + 1);
    });
}

function reindexBlockInputs() {
    var blocks = document.querySelectorAll('#blocksContainer .block-item');

    blocks.forEach(function (block, index) {
        var fileInput = block.querySelector('input[type="file"][name^="images["]');
        if (fileInput) {
            fileInput.name = 'images[' + index + '][]';
        }

        var existingInputs = block.querySelectorAll('input[type="hidden"][name^="images_existing["]');
        existingInputs.forEach(function (input) {
            input.name = 'images_existing[' + index + '][]';
        });

        var dzFields = ['dz_image_existing', 'dz_image_left', 'dz_image_top', 'dz_image_width', 'dz_image_id'];
        dzFields.forEach(function (field) {
            var el = block.querySelector('[name^="' + field + '["]');
            if (el) el.name = field + '[' + index + ']';
        });
        var dzFile = block.querySelector('input[type="file"][name^="dz_image_file["]');
        if (dzFile) dzFile.name = 'dz_image_file[' + index + ']';

        var vfFile = block.querySelector('input[type="file"][name="video_file[]"]');
        if (vfFile) vfFile.name = 'video_file[]';
    });
}

function removeBlock(button) {
    var item = button.closest('.block-item');
    if (item) {
        item.remove();
        reindexBlockInputs();
        reindexBlockBadges();
        markChanged();
    }
}

function addBlock() {
    var container = document.getElementById('blocksContainer');
    var index = container.querySelectorAll('.block-item').length;
    var blockNum = index + 1;
    var div = document.createElement('div');
    div.className = 'block-item';
    div.innerHTML =
        '<input type="hidden" name="block_id[]" value="listen_order_' + Date.now() + '_' + Math.floor(Math.random() * 1000) + '">' +

        '<div class="block-header-row">' +
            '<span class="block-badge">Block ' + blockNum + '</span>' +
            '<button type="button" class="btn-remove" onclick="removeBlock(this)">&#x2716; Remove</button>' +
        '</div>' +

        '<div class="media-toggle-row">' +
            '<button type="button" class="media-tab active" data-mode="audio"      onclick="setMediaMode(this,\'audio\')">Audio file</button>' +
            '<button type="button" class="media-tab"        data-mode="video-file" onclick="setMediaMode(this,\'video-file\')">Video file</button>' +
            '<button type="button" class="media-tab"        data-mode="video"      onclick="setMediaMode(this,\'video\')">Video URL</button>' +
            '<button type="button" class="media-tab"        data-mode="none"       onclick="setMediaMode(this,\'none\')">No media</button>' +
        '</div>' +

        '<div class="media-section audio-section">' +
            '<input type="hidden" name="video_url_existing[]" class="vf-url-existing" value="">' +
            '<div class="audio-upload-zone" onclick="this.querySelector(\'input[type=file]\').click()">' +
                '<div class="audio-upload-icon">&#x1F3B5;</div>' +
                '<div class="audio-upload-title">Upload audio file</div>' +
                '<div class="audio-upload-sub">MP3, WAV, OGG</div>' +
                '<input type="file" accept="audio/*" style="display:none" onchange="showAudioPill(this)">' +
            '</div>' +
            '<label class="field-label">Sentence / transcript <span class="field-badge">optional — shown to students</span></label>' +
            '<textarea name="sentence[]" required></textarea>' +
        '</div>' +

        '<div class="media-section video-file-section" style="display:none">' +
            '<input type="hidden" name="video_url_existing[]" class="vf-url-existing" value="">' +
            '<div class="video-upload-zone" onclick="this.querySelector(\'input[type=file]\').click()">' +
                '<div class="video-upload-icon">&#x1F3AC;</div>' +
                '<div class="video-upload-title">Upload video file</div>' +
                '<div class="video-upload-sub">MP4, MOV, WEBM</div>' +
                '<input type="file" name="video_file[]" accept="video/*" style="display:none" onchange="showVideoPreview(this)">' +
            '</div>' +
            '<label class="field-label">Transcript <span class="field-badge">optional — shown to students</span></label>' +
            '<textarea name="sentence[]"></textarea>' +
        '</div>' +

        '<div class="media-section video-section" style="display:none">' +
            '<input type="hidden" name="video_url_existing[]" class="vf-url-existing" value="">' +
            '<label class="field-label">Video URL</label>' +
            '<input type="url" placeholder="https://youtube.com/watch?v=... or direct video URL">' +
            '<div class="field-hint">Supports YouTube, Vimeo, or direct MP4 links.</div>' +
            '<textarea name="sentence[]" style="display:none"></textarea>' +
        '</div>' +

        '<div class="media-section none-section" style="display:none">' +
            '<input type="hidden" name="video_url_existing[]" class="vf-url-existing" value="">' +
            '<textarea name="sentence[]" style="display:none"></textarea>' +
        '</div>' +

        '<label class="field-label" style="margin-top:4px;">Images in correct order <span class="field-badge">upload in the exact order students should arrange them</span></label>' +

        '<div class="img-grid">' +
            '<input type="hidden" name="images_existing[' + index + '][]" value="">' +
            '<div class="img-add-slot">' +
                '<label>' +
                    '<span class="plus">+</span>' +
                    '<span>Add more</span>' +
                    '<input type="file" name="images[' + index + '][]" multiple accept="image/*" style="display:none">' +
                '</label>' +
            '</div>' +
        '</div>' +
        '<div class="field-hint">Upload images in the exact correct order. Students will see them shuffled.</div>' +

        '<div class="dz-section">' +
            '<label class="field-label">Drop zone background image <span class="field-badge">optional — visible behind answer chips</span></label>' +
            '<input type="hidden" name="dz_image_existing[' + index + ']" class="dz-src"   value="">' +
            '<input type="hidden" name="dz_image_left[' + index + ']"     class="dz-left"  value="10">' +
            '<input type="hidden" name="dz_image_top[' + index + ']"      class="dz-top"   value="10">' +
            '<input type="hidden" name="dz_image_width[' + index + ']"    class="dz-width" value="180">' +
            '<input type="hidden" name="dz_image_id[' + index + ']"       class="dz-imgid" value="">' +
            '<div class="dz-preview-area" style="display:none;">' +
                '<div class="dz-preview-hint">Drag the image to reposition it</div>' +
            '</div>' +
            '<div class="dz-controls-row">' +
                '<input type="file" name="dz_image_file[' + index + ']" accept="image/*" class="dz-file-input" style="margin-bottom:0;">' +
                '<button type="button" class="btn-remove-dz" onclick="removeDzImage(this)" style="display:none;">&#x2716; Remove BG image</button>' +
            '</div>' +
            '<div class="dz-width-control" style="display:none;">' +
                '<span>Width:</span>' +
                '<input type="number" min="60" max="800" value="180" class="dz-width-display">' +
                '<span>px</span>' +
            '</div>' +
        '</div>';

    container.appendChild(div);
    reindexBlockInputs();
    initDzPreview(div);
    bindChangeTracking(div);
    markChanged();
}

/* ══════════════════════════════════════════
   Drop Zone Image — preview drag logic
   ══════════════════════════════════════════ */

function initDzPreview(blockEl) {
    var previewArea  = blockEl.querySelector('.dz-preview-area');
    var fileInput    = blockEl.querySelector('.dz-file-input');
    var removeBtn    = blockEl.querySelector('.btn-remove-dz');
    var widthControl = blockEl.querySelector('.dz-width-control');
    var widthDisplay = blockEl.querySelector('.dz-width-display');
    var srcInput     = blockEl.querySelector('.dz-src');
    var leftInput    = blockEl.querySelector('.dz-left');
    var topInput     = blockEl.querySelector('.dz-top');
    var widthInput   = blockEl.querySelector('.dz-width');
    var idInput      = blockEl.querySelector('.dz-imgid');

    if (!previewArea) return;

    function getImg() {
        return previewArea.querySelector('.dz-preview-image');
    }

    function syncHiddens() {
        var img = getImg();
        if (!img) return;
        leftInput.value  = parseInt(img.style.left,  10) || 0;
        topInput.value   = parseInt(img.style.top,   10) || 0;
        widthInput.value = parseInt(img.style.width, 10) || 180;
    }

    function clamp(img) {
        var pw = previewArea.offsetWidth;
        var ph = previewArea.offsetHeight;
        var iw = img.offsetWidth;
        var ih = img.offsetHeight;
        var left = parseInt(img.style.left,  10) || 0;
        var top  = parseInt(img.style.top,   10) || 0;
        img.style.left = Math.max(0, Math.min(pw - Math.max(iw, 1), left)) + 'px';
        img.style.top  = Math.max(0, Math.min(ph - Math.max(ih, 1), top))  + 'px';
        syncHiddens();
    }

    function addDragBehavior(img) {
        var active = false;
        var startX, startY, startLeft, startTop;

        img.addEventListener('mousedown', function (e) {
            e.preventDefault();
            active    = true;
            startX    = e.clientX;
            startY    = e.clientY;
            startLeft = parseInt(img.style.left, 10) || 0;
            startTop  = parseInt(img.style.top,  10) || 0;
        });

        document.addEventListener('mousemove', function (e) {
            if (!active) return;
            img.style.left = (startLeft + (e.clientX - startX)) + 'px';
            img.style.top  = (startTop  + (e.clientY - startY)) + 'px';
        });

        document.addEventListener('mouseup', function () {
            if (!active) return;
            active = false;
            clamp(img);
            markChanged();
        });

        img.addEventListener('touchstart', function (e) {
            e.preventDefault();
            var t = e.touches[0];
            active    = true;
            startX    = t.clientX;
            startY    = t.clientY;
            startLeft = parseInt(img.style.left, 10) || 0;
            startTop  = parseInt(img.style.top,  10) || 0;
        }, { passive: false });

        document.addEventListener('touchmove', function (e) {
            if (!active) return;
            var t = e.touches[0];
            img.style.left = (startLeft + (t.clientX - startX)) + 'px';
            img.style.top  = (startTop  + (t.clientY - startY)) + 'px';
        }, { passive: true });

        document.addEventListener('touchend', function () {
            if (!active) return;
            active = false;
            clamp(img);
            markChanged();
        });
    }

    function showDzUI() {
        previewArea.style.display  = '';
        if (removeBtn)    removeBtn.style.display    = '';
        if (widthControl) widthControl.style.display = '';
    }

    function createPreviewImg(src, left, top, width) {
        var img = document.createElement('img');
        img.className    = 'dz-preview-image';
        img.src          = src;
        img.draggable    = false;
        img.style.left   = (left  || 10)  + 'px';
        img.style.top    = (top   || 10)  + 'px';
        img.style.width  = (width || 180) + 'px';
        img.alt          = '';
        previewArea.appendChild(img);
        addDragBehavior(img);
        return img;
    }

    if (fileInput) {
        fileInput.addEventListener('change', function () {
            if (!fileInput.files || !fileInput.files[0]) return;
            var reader = new FileReader();
            reader.onload = function (e) {
                var existingImg = getImg();
                if (existingImg) existingImg.remove();

                var w = parseInt(widthInput.value, 10) || 180;
                createPreviewImg(e.target.result, 10, 10, w);

                if (srcInput) srcInput.value = '';
                if (idInput)  idInput.value  = 'dzi_' + Date.now();
                if (widthDisplay) widthDisplay.value = w;
                showDzUI();
                syncHiddens();
                markChanged();
            };
            reader.readAsDataURL(fileInput.files[0]);
        });
    }

    if (widthDisplay) {
        widthDisplay.addEventListener('input', function () {
            var img = getImg();
            if (!img) return;
            var w = Math.max(60, Math.min(800, parseInt(widthDisplay.value, 10) || 180));
            img.style.width = w + 'px';
            widthInput.value = w;
            markChanged();
        });
    }

    var existingImg = getImg();
    if (existingImg) {
        addDragBehavior(existingImg);
        showDzUI();
        if (widthDisplay) widthDisplay.value = parseInt(widthInput.value, 10) || 180;
    }
}

function removeDzImage(btn) {
    var blockEl      = btn.closest('.block-item');
    if (!blockEl) return;
    var previewArea  = blockEl.querySelector('.dz-preview-area');
    var srcInput     = blockEl.querySelector('.dz-src');
    var leftInput    = blockEl.querySelector('.dz-left');
    var topInput     = blockEl.querySelector('.dz-top');
    var widthInput   = blockEl.querySelector('.dz-width');
    var idInput      = blockEl.querySelector('.dz-imgid');
    var fileInput    = blockEl.querySelector('.dz-file-input');
    var widthControl = blockEl.querySelector('.dz-width-control');

    if (previewArea) {
        var img = previewArea.querySelector('.dz-preview-image');
        if (img) img.remove();
        previewArea.style.display = 'none';
    }
    if (srcInput)     srcInput.value   = '';
    if (leftInput)    leftInput.value  = '10';
    if (topInput)     topInput.value   = '10';
    if (widthInput)   widthInput.value = '180';
    if (idInput)      idInput.value    = '';
    if (fileInput)    fileInput.value  = '';
    if (widthControl) widthControl.style.display = 'none';
    btn.style.display = 'none';
    markChanged();
}

function bindChangeTracking(scope) {
    var elements = scope.querySelectorAll('input, textarea, select');
    elements.forEach(function (el) {
        el.addEventListener('input',  markChanged);
        el.addEventListener('change', markChanged);
    });
}

document.addEventListener('DOMContentLoaded', function () {
    bindChangeTracking(document);
    reindexBlockInputs();

    document.querySelectorAll('#blocksContainer .block-item').forEach(function (blockEl) {
        initDzPreview(blockEl);
    });

    var form = document.getElementById('listenOrderForm');
    if (form) {
        form.addEventListener('submit', function () {
            reindexBlockInputs();
            formSubmitted = true;
            formChanged   = false;
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
