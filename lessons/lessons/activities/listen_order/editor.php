<?php
if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . "/../../config/db.php";
require_once __DIR__ . "/../../core/cloudinary_upload.php";
require_once __DIR__ . "/../../core/_activity_editor_template.php";

if (isset($_SESSION['student_logged']) && $_SESSION['student_logged']) {
    header('Location: /lessons/lessons/academic/student_dashboard.php?error=access_denied'); exit;
}
if (empty($_SESSION['academic_logged']) && empty($_SESSION['admin_logged'])) {
    header('Location: /lessons/lessons/academic/login.php'); exit;
}

$activityId = trim((string)($_GET["id"]         ?? ""));
$unit       = trim((string)($_GET["unit"]       ?? ""));
$source     = trim((string)($_GET["source"]     ?? ""));
$assignment = trim((string)($_GET["assignment"] ?? ""));

function lo_default_title(): string { return "Listen & Order"; }

function lo_resolve_unit(PDO $pdo, string $id): string {
    if ($id === "") return "";
    $st = $pdo->prepare("SELECT unit_id FROM activities WHERE id=:id LIMIT 1");
    $st->execute(["id" => $id]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r ? (string)$r["unit_id"] : "";
}

function lo_normalize(mixed $raw): array {
    $def = ["title" => lo_default_title(), "instructions" => "", "blocks" => []];
    if (!$raw) return $def;

    $d = is_string($raw) ? json_decode($raw, true) : $raw;
    if (!is_array($d)) return $def;

    $title = trim((string)($d["title"] ?? ""));
    $instr = trim((string)($d["instructions"] ?? ""));
    $src   = isset($d["blocks"]) && is_array($d["blocks"]) ? $d["blocks"] : $d;
    $out   = [];

    foreach ($src as $b) {
        if (!is_array($b)) continue;

        $sentence  = trim((string)($b["sentence"]  ?? ""));
        $video_url = trim((string)($b["video_url"] ?? ""));

        $images = [];
        foreach ((array)($b["images"] ?? []) as $img) {
            $u = trim((string)$img);
            if ($u !== "") $images[] = $u;
        }

        $dzImages = [];
        foreach ((array)($b["dropZoneImages"] ?? []) as $dzi) {
            if (!is_array($dzi)) continue;

            $dzSrc = trim((string)($dzi["src"] ?? ""));
            if ($dzSrc === "") continue;

            $dzImages[] = [
                "id"    => trim((string)($dzi["id"] ?? uniqid("dzi_"))),
                "src"   => $dzSrc,
                "left"  => (int)($dzi["left"] ?? 0),
                "top"   => (int)($dzi["top"] ?? 0),
                "width" => max(60, min(800, (int)($dzi["width"] ?? 180))),
            ];
        }

        if ($sentence === "" && $video_url === "" && empty($images)) continue;

        $out[] = [
            "id"             => trim((string)($b["id"] ?? uniqid("lo_"))),
            "sentence"       => $sentence,
            "video_url"      => $video_url,
            "images"         => $images,
            "dropZoneImages" => $dzImages,
        ];
    }

    return [
        "title"        => $title !== "" ? $title : lo_default_title(),
        "instructions" => $instr,
        "blocks"       => $out,
    ];
}

function lo_load(PDO $pdo, string $unit, string $activityId): array {
    $fallback = ["id" => "", "title" => lo_default_title(), "instructions" => "", "blocks" => []];
    $row = null;

    if ($activityId !== "") {
        $st = $pdo->prepare("SELECT id,data FROM activities WHERE id=:id AND type IN ('listen_order','listen_and_order') LIMIT 1");
        $st->execute(["id" => $activityId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
    }

    if (!$row && $unit !== "") {
        $st = $pdo->prepare("SELECT id,data FROM activities WHERE unit_id=:unit AND type IN ('listen_order','listen_and_order') ORDER BY id ASC LIMIT 1");
        $st->execute(["unit" => $unit]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
    }

    if (!$row) return $fallback;

    $p = lo_normalize($row["data"] ?? null);
    return array_merge($p, ["id" => (string)($row["id"] ?? "")]);
}

function lo_save(PDO $pdo, string $unit, string $actId, string $title, string $instr, array $blocks): string {
    $json = json_encode([
        "title"        => $title !== "" ? $title : lo_default_title(),
        "instructions" => $instr,
        "blocks"       => array_values($blocks),
    ], JSON_UNESCAPED_UNICODE);

    $tid = $actId;

    if ($tid === "") {
        $st = $pdo->prepare("SELECT id FROM activities WHERE unit_id=:unit AND type IN ('listen_order','listen_and_order') ORDER BY id ASC LIMIT 1");
        $st->execute(["unit" => $unit]);
        $tid = trim((string)$st->fetchColumn());
    }

    if ($tid !== "") {
        $st = $pdo->prepare("UPDATE activities SET data=:data, type='listen_order' WHERE id=:id");
        $st->execute(["data" => $json, "id" => $tid]);
        return $tid;
    }

    $st = $pdo->prepare("INSERT INTO activities (unit_id,type,data,position,created_at) VALUES (:u,'listen_order',:d,(SELECT COALESCE(MAX(position),0)+1 FROM activities WHERE unit_id=:u2),CURRENT_TIMESTAMP) RETURNING id");
    $st->execute(["u" => $unit, "u2" => $unit, "d" => $json]);

    return (string)$st->fetchColumn();
}

if ($unit === "" && $activityId !== "") $unit = lo_resolve_unit($pdo, $activityId);
if ($unit === "") die("Unit not specified");

$activity   = lo_load($pdo, $unit, $activityId);
$edTitle    = (string)($activity["title"]        ?? lo_default_title());
$edInstr    = (string)($activity["instructions"] ?? "");
$blocks     = is_array($activity["blocks"] ?? null) ? $activity["blocks"] : [];

if ($activityId === "" && !empty($activity["id"])) $activityId = (string)$activity["id"];

// ── POST ──────────────────────────────────────────────────────────────────────
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $postedTitle    = trim((string)($_POST["activity_title"]        ?? ""));
    $postedInstr    = trim((string)($_POST["activity_instructions"] ?? ""));

    $blockIds       = (array)($_POST["block_id"]           ?? []);
    $sentences      = (array)($_POST["sentence"]           ?? []);
    $existingImages = (array)($_POST["images_existing"]    ?? []);
    $videoExisting  = (array)($_POST["video_url_existing"] ?? []);

    $imageFiles     = $_FILES["images"]         ?? null;
    $videoFiles     = $_FILES["video_file"]     ?? null;

    $dzExisting     = (array)($_POST["dz_image_existing"]  ?? []);
    $dzLeftArr      = (array)($_POST["dz_image_left"]      ?? []);
    $dzTopArr       = (array)($_POST["dz_image_top"]       ?? []);
    $dzWidthArr     = (array)($_POST["dz_image_width"]     ?? []);
    $dzIdArr        = (array)($_POST["dz_image_id"]        ?? []);
    $dzFiles        = $_FILES["dz_image_file"]  ?? null;

    $videoFileCount = is_array($videoFiles["name"] ?? null) ? count($videoFiles["name"]) : 0;
    $imageFileCount = is_array($imageFiles["name"] ?? null) ? count($imageFiles["name"]) : 0;
    $dzFileCount    = is_array($dzFiles["name"] ?? null) ? count($dzFiles["name"]) : 0;

    $blockCount = max(
        count($blockIds),
        count($sentences),
        count($existingImages),
        count($videoExisting),
        count($dzExisting),
        $videoFileCount,
        $imageFileCount,
        $dzFileCount
    );

    $sanitized = [];

    for ($i = 0; $i < $blockCount; $i++) {
        $sentence = trim((string)($sentences[$i] ?? ""));
        $blockId  = trim((string)($blockIds[$i] ?? uniqid("lo_")));

        $images = [];

        if (isset($existingImages[$i]) && is_array($existingImages[$i])) {
            foreach ($existingImages[$i] as $img) {
                $u = trim((string)$img);
                if ($u !== "") $images[] = $u;
            }
        }

        if ($imageFiles && isset($imageFiles["name"][$i]) && is_array($imageFiles["name"][$i])) {
            foreach ($imageFiles["name"][$i] as $k => $name) {
                if (!$name || empty($imageFiles["tmp_name"][$i][$k])) continue;
                if (($imageFiles["error"][$i][$k] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) continue;

                $up = upload_to_cloudinary($imageFiles["tmp_name"][$i][$k]);
                if ($up) $images[] = $up;
            }
        }

        // Uploaded video is indexed by block position after loReindex() on submit.
        // This loop now runs by block count, not only by sentence[], so video-only
        // blocks are not skipped.
        $videoUrl = trim((string)($videoExisting[$i] ?? ""));

        $vTmp   = $videoFiles["tmp_name"][$i] ?? "";
        $vError = $videoFiles["error"][$i]    ?? UPLOAD_ERR_NO_FILE;

        if ($videoFiles && $vTmp !== "" && $vError === UPLOAD_ERR_OK) {
            $up = upload_to_cloudinary($vTmp);
            if ($up) $videoUrl = $up;
        }

        $dzSrc = trim((string)($dzExisting[$i] ?? ""));
        $dzL   = (int)($dzLeftArr[$i]  ?? 0);
        $dzT   = (int)($dzTopArr[$i]   ?? 0);
        $dzW   = max(60, min(800, (int)($dzWidthArr[$i] ?? 180)));
        $dzId  = trim((string)($dzIdArr[$i] ?? ""));

        if ($dzFiles && !empty($dzFiles["tmp_name"][$i]) && ($dzFiles["error"][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
            $up = upload_to_cloudinary($dzFiles["tmp_name"][$i]);
            if ($up) {
                $dzSrc = $up;
                $dzId  = uniqid("dzi_");
            }
        }

        if ($dzId === "") $dzId = uniqid("dzi_");

        $dzImages = $dzSrc !== "" ? [[
            "id"    => $dzId,
            "src"   => $dzSrc,
            "left"  => $dzL,
            "top"   => $dzT,
            "width" => $dzW,
        ]] : [];

        if ($sentence === "" && $videoUrl === "" && empty($images)) continue;

        $sanitized[] = [
            "id"             => $blockId !== "" ? $blockId : uniqid("lo_"),
            "sentence"       => $sentence,
            "video_url"      => $videoUrl,
            "images"         => array_values($images),
            "dropZoneImages" => $dzImages,
        ];
    }

    $savedId = lo_save($pdo, $unit, $activityId, $postedTitle, $postedInstr, $sanitized);

    $qs = "unit=".urlencode($unit)."&saved=1";
    if ($savedId    !== "") $qs .= "&id=".urlencode($savedId);
    if ($assignment !== "") $qs .= "&assignment=".urlencode($assignment);
    if ($source     !== "") $qs .= "&source=".urlencode($source);

    header("Location: editor.php?".$qs);
    exit;
}

ob_start();
if (isset($_GET["saved"])) echo '<div class="lo-saved">✓ Saved successfully</div>';
?>
<link href="https://fonts.googleapis.com/css2?family=Fredoka:wght@500;600;700&family=Nunito:wght@600;700;800;900&display=swap" rel="stylesheet">
<style>
*{box-sizing:border-box}
body{background:#f8f7ff!important;font-family:'Nunito',sans-serif!important}
.lo-saved{background:#E6F9F2;border:1px solid #9FE1CB;border-radius:12px;padding:10px 16px;color:#0F6E56;font-size:13px;font-weight:900;margin-bottom:16px}
.lo-form{max-width:780px;margin:0 auto}
.lo-card,.block-item{background:#fff;border:1px solid #F0EEF8;border-radius:20px;padding:20px 22px;margin-bottom:14px;box-shadow:0 4px 18px rgba(127,119,221,.08)}
.field-label{display:block;font-size:12px;font-weight:900;letter-spacing:.08em;text-transform:uppercase;color:#9B94BE;margin-bottom:8px}
.field-badge{background:#EEEDFE;color:#534AB7;border-radius:999px;padding:2px 8px;font-size:10px;font-weight:700;text-transform:none;margin-left:6px;vertical-align:middle}
.lo-form input[type=text],.lo-form input[type=url],.lo-form input[type=number],.lo-form textarea{width:100%;border:1.5px solid #EDE9FA;border-radius:12px;padding:11px 14px;font-family:'Nunito',sans-serif;font-size:14px;font-weight:700;color:#271B5D;background:#fff;outline:none;margin-bottom:12px;transition:border-color .15s,box-shadow .15s}
.lo-form input:focus,.lo-form textarea:focus{border-color:#7F77DD;box-shadow:0 0 0 3px rgba(127,119,221,.1)}
.lo-form textarea{min-height:60px;resize:vertical}
.block-header-row{display:flex;justify-content:space-between;align-items:center;margin-bottom:16px}
.block-badge{background:#EEEDFE;color:#534AB7;border-radius:999px;padding:4px 14px;font-size:12px;font-weight:900}
.btn-remove{background:#FCEBEB;color:#E24B4A;border:1px solid #F7C1C1;border-radius:999px;padding:5px 14px;font-size:12px;font-weight:900;cursor:pointer}
.media-toggle-row{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:14px}
.media-tab{border:1.5px solid #EDE9FA;background:#fff;color:#534AB7;border-radius:999px;padding:7px 18px;font-size:12px;font-weight:900;cursor:pointer;transition:all .15s}
.media-tab.active{background:#7F77DD;color:#fff;border-color:#7F77DD;box-shadow:0 6px 18px rgba(127,119,221,.22)}
.upload-zone{border:2px dashed #EDE9FA;border-radius:14px;background:#FAFAFE;padding:20px;text-align:center;cursor:pointer;margin-bottom:14px;transition:border-color .15s,background .15s}
.upload-zone:hover{border-color:#7F77DD;background:#EEEDFE}
.upload-zone-icon{width:40px;height:40px;border-radius:50%;background:#EEEDFE;display:flex;align-items:center;justify-content:center;margin:0 auto 8px;font-size:18px}
.upload-zone-title{font-size:13px;font-weight:900;color:#534AB7;margin-bottom:3px}
.upload-zone-sub{font-size:11px;font-weight:700;color:#9B94BE}
.file-pill{display:inline-block;background:#EEEDFE;color:#534AB7;border-radius:999px;padding:4px 12px;font-size:12px;font-weight:900;margin-top:8px}
.video-preview-wrap{border-radius:12px;overflow:hidden;background:#000;margin-bottom:10px;max-height:220px;display:flex;align-items:center;justify-content:center}
.video-preview-wrap video{width:100%;max-height:220px;object-fit:contain;display:block}
.btn-remove-video{background:#FCEBEB;color:#E24B4A;border:1px solid #F7C1C1;border-radius:999px;padding:5px 14px;font-size:12px;font-weight:900;cursor:pointer;margin-bottom:10px;display:inline-block}
.field-hint{color:#9B94BE;font-size:12px;font-weight:800;margin:-6px 0 12px}
.img-grid{display:flex;flex-wrap:wrap;gap:10px;margin-bottom:8px;align-items:flex-start}
.img-card{position:relative;width:86px;border-radius:14px;border:1.5px solid #EDE9FA;overflow:visible;background:#fff;display:flex;flex-direction:column;align-items:center}
.img-card img{width:86px;height:86px;object-fit:cover;border-radius:12px;display:block}
.img-pos-badge{position:absolute;top:4px;left:4px;width:20px;height:20px;border-radius:50%;background:#7F77DD;color:#fff;font-size:10px;font-weight:900;display:flex;align-items:center;justify-content:center;z-index:2;pointer-events:none}
.img-remove-btn{border:none;background:none;color:#E24B4A;font-size:10px;font-weight:900;cursor:pointer;padding:3px 0 2px;text-align:center;width:100%;line-height:1}
.img-add-slot{width:86px;height:86px;border-radius:14px;border:1.5px dashed #EDE9FA;background:#FAFAFE;display:flex;align-items:center;justify-content:center;cursor:pointer}
.img-add-slot label{cursor:pointer;display:flex;flex-direction:column;align-items:center;justify-content:center;width:100%;height:100%;font-size:11px;font-weight:900;color:#9B94BE;gap:4px;margin:0}
.img-add-slot label .plus{font-size:22px;color:#7F77DD;line-height:1}
.dz-section{margin-top:14px;padding-top:14px;border-top:1px solid #F0EEF8}
.dz-preview-area{position:relative;width:100%;max-width:680px;height:160px;border:2px dashed #EDE9FA;border-radius:14px;background:#FAFAFE;overflow:hidden;margin-bottom:8px}
.dz-preview-hint{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;color:#9B94BE;font-size:13px;font-style:italic;pointer-events:none}
.dz-preview-image{position:absolute;cursor:move;user-select:none;touch-action:none;height:auto;border:2px solid #7F77DD;border-radius:6px;box-shadow:0 2px 8px rgba(0,0,0,.15)}
.dz-controls-row{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-bottom:8px}
.dz-width-control{display:flex;align-items:center;gap:6px;font-size:13px;color:#534AB7;font-weight:700}
.dz-width-control input{width:70px!important;padding:4px 6px!important;margin-bottom:0!important}
.btn-remove-dz{background:#FCEBEB;color:#E24B4A;border:1px solid #F7C1C1;border-radius:999px;padding:5px 14px;font-size:12px;font-weight:900;cursor:pointer;white-space:nowrap}
.toolbar-row{display:flex;gap:10px;flex-wrap:wrap;justify-content:center;padding-top:20px;border-top:1px solid #F0EEF8;margin-top:8px}
.btn-add{background:#fff;color:#534AB7;border:1.5px solid #EDE9FA;border-radius:999px;padding:12px 26px;font-size:13px;font-weight:900;cursor:pointer;transition:transform .12s}
.btn-add:hover{transform:translateY(-2px)}
.save-btn{background:#F97316;color:#fff;border:none;border-radius:999px;padding:12px 26px;font-size:13px;font-weight:900;cursor:pointer;box-shadow:0 6px 18px rgba(249,115,22,.22);transition:transform .12s}
.save-btn:hover{transform:translateY(-2px)}
@media(max-width:640px){.toolbar-row{flex-direction:column;align-items:center}.btn-add,.save-btn{width:100%;max-width:300px}}
</style>

<form method="post" enctype="multipart/form-data" class="lo-form" id="loForm">
<div class="lo-card">
    <label class="field-label" for="lo_title">Activity title</label>
    <input id="lo_title" type="text" name="activity_title" value="<?= htmlspecialchars($edTitle,ENT_QUOTES) ?>" placeholder="Listen and order" required>
    <label class="field-label" for="lo_instr">Instructions <span class="field-badge">shown below title</span></label>
    <textarea id="lo_instr" name="activity_instructions" placeholder="Listen to the audio and put the pictures in the correct order."><?= htmlspecialchars($edInstr,ENT_QUOTES) ?></textarea>
</div>

<div id="blocksContainer">
<?php foreach ($blocks as $bi => $block):
    $bVideoUrl = trim((string)($block["video_url"] ?? ""));
    $bSentence = trim((string)($block["sentence"]  ?? ""));
    $bImages   = is_array($block["images"]         ?? null) ? $block["images"]         : [];
    $bDzArr    = is_array($block["dropZoneImages"]  ?? null) ? $block["dropZoneImages"]  : [];
    $bDz       = $bDzArr[0] ?? null;
    $dzSrc     = (string)($bDz["src"]   ?? "");
    $dzLeft    = (int)   ($bDz["left"]  ?? 10);
    $dzTop     = (int)   ($bDz["top"]   ?? 10);
    $dzWidth   = max(60, min(800, (int)($bDz["width"] ?? 180)));
    $dzId      = (string)($bDz["id"]    ?? uniqid("dzi_"));
    $mode      = $bVideoUrl !== "" ? "video-file" : ($bSentence !== "" ? "audio" : "none");
?>
<div class="block-item">
    <input type="hidden" name="block_id[]" value="<?= htmlspecialchars((string)($block["id"] ?? uniqid("lo_")),ENT_QUOTES) ?>">
    <input type="hidden" name="video_url_existing[]" class="js-vidurl" value="<?= htmlspecialchars($bVideoUrl,ENT_QUOTES) ?>">

    <div class="block-header-row">
        <span class="block-badge">Block <?= $bi+1 ?></span>
        <button type="button" class="btn-remove" onclick="loRemoveBlock(this)">✖ Remove</button>
    </div>

    <div class="media-toggle-row">
        <button type="button" class="media-tab<?= $mode==='audio'      ?' active':'' ?>" onclick="loSetMode(this,'audio')">Audio file</button>
        <button type="button" class="media-tab<?= $mode==='video-file' ?' active':'' ?>" onclick="loSetMode(this,'video-file')">Video file</button>
        <button type="button" class="media-tab<?= $mode==='video'      ?' active':'' ?>" onclick="loSetMode(this,'video')">Video URL</button>
        <button type="button" class="media-tab<?= $mode==='none'       ?' active':'' ?>" onclick="loSetMode(this,'none')">No media</button>
    </div>

    <div class="media-section audio-section"<?= $mode!=='audio'?' style="display:none"':'' ?>>
        <div class="upload-zone" onclick="this.querySelector('input').click()">
            <div class="upload-zone-icon">🎵</div><div class="upload-zone-title">Upload audio file</div><div class="upload-zone-sub">MP3, WAV, OGG</div>
            <input type="file" accept="audio/*" style="display:none" onchange="loAudioPill(this)">
        </div>
        <label class="field-label">Sentence / transcript <span class="field-badge">optional</span></label>
        <textarea name="sentence[]"<?= $mode!=='audio'?' disabled':'' ?>><?= htmlspecialchars($bSentence,ENT_QUOTES) ?></textarea>
    </div>

    <div class="media-section video-file-section"<?= $mode!=='video-file'?' style="display:none"':'' ?>>
        <textarea name="sentence[]"<?= $mode!=='video-file'?' disabled':'' ?> style="display:none"><?= htmlspecialchars($bSentence,ENT_QUOTES) ?></textarea>
        <input type="file" name="video_file[<?= $bi ?>]" accept="video/*" class="js-vf-input"
               style="position:absolute;width:1px;height:1px;opacity:0;left:-9999px"
               onchange="loVideoPreview(this)">
        <?php if ($bVideoUrl !== ""): ?>
        <div class="video-preview-wrap"><video src="<?= htmlspecialchars($bVideoUrl,ENT_QUOTES) ?>" controls preload="metadata"></video></div>
        <button type="button" class="btn-remove-video" onclick="loRemoveVideo(this)">✖ Remove video</button>
        <?php else: ?>
        <div class="upload-zone js-vf-zone" onclick="this.closest('.video-file-section').querySelector('.js-vf-input').click()">
            <div class="upload-zone-icon">🎬</div><div class="upload-zone-title">Upload video file</div><div class="upload-zone-sub">MP4, MOV, WEBM</div>
        </div>
        <?php endif; ?>
    </div>

    <div class="media-section video-section"<?= $mode!=='video'?' style="display:none"':'' ?>>
        <textarea name="sentence[]"<?= $mode!=='video'?' disabled':'' ?> style="display:none"></textarea>
        <label class="field-label">Video URL</label>
        <input type="url" placeholder="https://...">
        <div class="field-hint">YouTube, Vimeo, or direct MP4.</div>
    </div>

    <div class="media-section none-section"<?= $mode!=='none'?' style="display:none"':'' ?>>
        <textarea name="sentence[]"<?= $mode!=='none'?' disabled':'' ?> style="display:none"></textarea>
    </div>

    <label class="field-label" style="margin-top:4px">Images in correct order <span class="field-badge">students see them shuffled</span></label>
    <div class="img-grid">
        <?php foreach ($bImages as $ii => $img): ?>
        <div class="img-card">
            <span class="img-pos-badge"><?= $ii+1 ?></span>
            <img src="<?= htmlspecialchars($img,ENT_QUOTES) ?>" alt="">
            <input type="hidden" name="images_existing[<?= $bi ?>][]" value="<?= htmlspecialchars($img,ENT_QUOTES) ?>">
            <button type="button" class="img-remove-btn" onclick="loRemoveImg(this)">Remove</button>
        </div>
        <?php endforeach; ?>
        <?php if (empty($bImages)): ?><input type="hidden" name="images_existing[<?= $bi ?>][]" value=""><?php endif; ?>
        <div class="img-add-slot">
            <label><span class="plus">+</span><span>Add</span>
            <input type="file" name="images[<?= $bi ?>][]" multiple accept="image/*" style="display:none" onchange="loPreviewImages(this)">
            </label>
        </div>
    </div>

    <div class="field-hint">Upload in correct order — students will see them shuffled.</div>

    <div class="dz-section">
        <label class="field-label">Drop zone background <span class="field-badge">optional</span></label>
        <input type="hidden" name="dz_image_existing[<?= $bi ?>]" class="js-dz-src"   value="<?= htmlspecialchars($dzSrc,ENT_QUOTES) ?>">
        <input type="hidden" name="dz_image_left[<?= $bi ?>]"     class="js-dz-left"  value="<?= $dzLeft ?>">
        <input type="hidden" name="dz_image_top[<?= $bi ?>]"      class="js-dz-top"   value="<?= $dzTop ?>">
        <input type="hidden" name="dz_image_width[<?= $bi ?>]"    class="js-dz-width" value="<?= $dzWidth ?>">
        <input type="hidden" name="dz_image_id[<?= $bi ?>]"       class="js-dz-id"    value="<?= htmlspecialchars($dzId,ENT_QUOTES) ?>">

        <div class="dz-preview-area"<?= $dzSrc?'':' style="display:none"' ?>>
            <div class="dz-preview-hint">Drag to reposition</div>
            <?php if ($dzSrc): ?>
            <img src="<?= htmlspecialchars($dzSrc,ENT_QUOTES) ?>" class="dz-preview-image"
                style="left:<?= $dzLeft ?>px;top:<?= $dzTop ?>px;width:<?= $dzWidth ?>px" draggable="false" alt="">
            <?php endif; ?>
        </div>

        <div class="dz-controls-row">
            <input type="file" name="dz_image_file[<?= $bi ?>]" accept="image/*" class="js-dz-file" style="margin-bottom:0">
            <button type="button" class="btn-remove-dz" onclick="loDzRemove(this)"<?= $dzSrc?'':' style="display:none"' ?>>✖ Remove</button>
        </div>

        <div class="dz-width-control"<?= $dzSrc?'':' style="display:none"' ?>>
            <span>Width:</span><input type="number" min="60" max="800" value="<?= $dzWidth ?>" class="js-dz-wdisplay"><span>px</span>
        </div>
    </div>
</div>
<?php endforeach; ?>
</div>

<div class="toolbar-row">
    <button type="button" class="btn-add" onclick="loAddBlock()">+ Add Block</button>
    <button type="submit" class="save-btn">💾 Save</button>
</div>
</form>

<script>
var loChanged=false,loSubmitted=false;
function loMark(){ loChanged=true; }

function loSetMode(btn,mode){
    var b=btn.closest('.block-item');
    b.querySelectorAll('.media-tab').forEach(function(t){ t.classList.remove('active'); });
    btn.classList.add('active');
    b.querySelectorAll('.media-section').forEach(function(s){ s.style.display='none'; });

    var active=b.querySelector('.'+mode+'-section');
    if (active) active.style.display='';

    b.querySelectorAll('.media-section textarea[name="sentence[]"]').forEach(function(ta){ ta.disabled=true; });
    if (active){
        var ta=active.querySelector('textarea[name="sentence[]"]');
        if(ta) ta.disabled=false;
    }

    loMark();
}

function loVideoPreview(input){
    if (!input.files||!input.files[0]) return;

    var sec=input.closest('.video-file-section');
    var z=sec.querySelector('.js-vf-zone'); if(z) z.remove();
    var ow=sec.querySelector('.video-preview-wrap'); if(ow) ow.remove();
    var ob=sec.querySelector('.btn-remove-video'); if(ob) ob.remove();

    var wrap=document.createElement('div');
    wrap.className='video-preview-wrap';
    wrap.innerHTML='<video src="'+URL.createObjectURL(input.files[0])+'" controls preload="metadata"></video>';

    var btn=document.createElement('button');
    btn.type='button';
    btn.className='btn-remove-video';
    btn.textContent='✖ Remove video';
    btn.onclick=function(){ loRemoveVideo(btn); };

    sec.appendChild(btn);
    sec.appendChild(wrap);

    loMark();
}

function loRemoveVideo(btn){
    var sec=btn.closest('.video-file-section'),bl=btn.closest('.block-item');

    var w=sec.querySelector('.video-preview-wrap');
    if(w) w.remove();

    btn.remove();

    var vi=sec.querySelector('.js-vf-input');
    if(vi) vi.value='';

    var hu=bl?bl.querySelector('.js-vidurl'):null;
    if(hu) hu.value='';

    var z=document.createElement('div');
    z.className='upload-zone js-vf-zone';
    z.onclick=function(){ sec.querySelector('.js-vf-input').click(); };
    z.innerHTML='<div class="upload-zone-icon">&#x1F3AC;</div><div class="upload-zone-title">Upload video file</div><div class="upload-zone-sub">MP4, MOV, WEBM</div>';

    sec.appendChild(z);

    loMark();
}

function loAudioPill(input){
    var z=input.closest('.upload-zone');
    if(!z) return;

    var p=z.querySelector('.file-pill');
    if(p) p.remove();

    if (input.files&&input.files[0]){
        var pill=document.createElement('div');
        pill.className='file-pill';
        pill.textContent=input.files[0].name;
        z.appendChild(pill);
    }
}

function loPreviewImages(input){
    if (!input.files||!input.files.length) return;

    var grid=input.closest('.img-grid'),slot=input.closest('.img-add-slot');
    if (!grid) return;

    var bl=input.closest('.block-item');
    var allBl=Array.from(document.querySelectorAll('#blocksContainer .block-item'));
    var bi=bl?allBl.indexOf(bl):0;
    if(bi<0) bi=0;

    var base=grid.querySelectorAll('.img-card').length;

    Array.from(input.files).forEach(function(file,fi){
        var r=new FileReader();
        r.onload=function(e){
            var card=document.createElement('div');
            card.className='img-card';
            card.innerHTML='<span class="img-pos-badge">'+(base+fi+1)+'</span>'+
                '<img src="'+e.target.result+'" alt="">'+
                '<button type="button" class="img-remove-btn" onclick="loRemoveImg(this)">Remove</button>';
            grid.insertBefore(card,slot);
        };
        r.readAsDataURL(file);
    });

    input.name='images['+bi+'][]';
    input.style.display='none';
    input.onchange=null;
    grid.insertBefore(input,slot);

    var clone=document.createElement('input');
    clone.type='file';
    clone.multiple=true;
    clone.accept='image/*';
    clone.name='images['+bi+'][]';
    clone.style.display='none';
    clone.onchange=function(){ loPreviewImages(clone); };

    var lbl=slot?slot.querySelector('label'):null;
    if(lbl) lbl.appendChild(clone);

    loMark();
}

function loRemoveImg(btn){
    var card=btn.closest('.img-card'),grid=card?card.closest('.img-grid'):null;

    if(card) card.remove();

    if(grid) grid.querySelectorAll('.img-card').forEach(function(c,i){
        var b=c.querySelector('.img-pos-badge');
        if(b) b.textContent=String(i+1);
    });

    loMark();
}

function loReindex(){
    document.querySelectorAll('#blocksContainer .block-item').forEach(function(block,idx){
        block.querySelectorAll('input[type="file"][name^="images["]').forEach(function(i){ i.name='images['+idx+'][]'; });
        block.querySelectorAll('input[type="hidden"][name^="images_existing["]').forEach(function(i){ i.name='images_existing['+idx+'][]'; });

        ['dz_image_existing','dz_image_left','dz_image_top','dz_image_width','dz_image_id'].forEach(function(f){
            var el=block.querySelector('[name^="'+f+'["]');
            if(el) el.name=f+'['+idx+']';
        });

        var dzf=block.querySelector('input[type="file"][name^="dz_image_file["]');
        if(dzf) dzf.name='dz_image_file['+idx+']';

        var vf=block.querySelector('.js-vf-input');
        if(vf) vf.name='video_file['+idx+']';
    });
}

function loRenumber(){
    document.querySelectorAll('#blocksContainer .block-item').forEach(function(b,i){
        var badge=b.querySelector('.block-badge');
        if(badge) badge.textContent='Block '+(i+1);
    });
}

function loRemoveBlock(btn){
    var item=btn.closest('.block-item');
    if(item){
        item.remove();
        loReindex();
        loRenumber();
        loMark();
    }
}

function loAddBlock(){
    var c=document.getElementById('blocksContainer');
    var idx=c.querySelectorAll('.block-item').length;
    var div=document.createElement('div');

    div.className='block-item';
    div.innerHTML=
        '<input type="hidden" name="block_id[]" value="lo_'+Date.now()+'_'+(Math.random()*1e4|0)+'">'+
        '<input type="hidden" name="video_url_existing[]" class="js-vidurl" value="">'+
        '<div class="block-header-row"><span class="block-badge">Block '+(idx+1)+'</span>'+
        '<button type="button" class="btn-remove" onclick="loRemoveBlock(this)">&#x2716; Remove</button></div>'+
        '<div class="media-toggle-row">'+
            '<button type="button" class="media-tab active" onclick="loSetMode(this,\'audio\')">Audio file</button>'+
            '<button type="button" class="media-tab" onclick="loSetMode(this,\'video-file\')">Video file</button>'+
            '<button type="button" class="media-tab" onclick="loSetMode(this,\'video\')">Video URL</button>'+
            '<button type="button" class="media-tab" onclick="loSetMode(this,\'none\')">No media</button>'+
        '</div>'+
        '<div class="media-section audio-section">'+
            '<div class="upload-zone" onclick="this.querySelector(\'input\').click()">'+
                '<div class="upload-zone-icon">&#x1F3B5;</div><div class="upload-zone-title">Upload audio file</div><div class="upload-zone-sub">MP3, WAV, OGG</div>'+
                '<input type="file" accept="audio/*" style="display:none" onchange="loAudioPill(this)">'+
            '</div>'+
            '<label class="field-label">Sentence / transcript <span class="field-badge">optional</span></label>'+
            '<textarea name="sentence[]"></textarea>'+
        '</div>'+
        '<div class="media-section video-file-section" style="display:none">'+
            '<textarea name="sentence[]" disabled style="display:none"></textarea>'+
            '<input type="file" name="video_file['+idx+']" accept="video/*" class="js-vf-input"'+
                ' style="position:absolute;width:1px;height:1px;opacity:0;left:-9999px"'+
                ' onchange="loVideoPreview(this)">'+
            '<div class="upload-zone js-vf-zone" onclick="this.closest(\'.video-file-section\').querySelector(\'.js-vf-input\').click()">'+
                '<div class="upload-zone-icon">&#x1F3AC;</div><div class="upload-zone-title">Upload video file</div><div class="upload-zone-sub">MP4, MOV, WEBM</div>'+
            '</div>'+
        '</div>'+
        '<div class="media-section video-section" style="display:none">'+
            '<textarea name="sentence[]" disabled style="display:none"></textarea>'+
            '<label class="field-label">Video URL</label><input type="url" placeholder="https://...">'+
            '<div class="field-hint">YouTube, Vimeo, or direct MP4.</div>'+
        '</div>'+
        '<div class="media-section none-section" style="display:none">'+
            '<textarea name="sentence[]" disabled style="display:none"></textarea>'+
        '</div>'+
        '<label class="field-label" style="margin-top:4px">Images in correct order <span class="field-badge">students see them shuffled</span></label>'+
        '<div class="img-grid">'+
            '<input type="hidden" name="images_existing['+idx+'][]" value="">'+
            '<div class="img-add-slot"><label><span class="plus">+</span><span>Add</span>'+
            '<input type="file" name="images['+idx+'][]" multiple accept="image/*" style="display:none" onchange="loPreviewImages(this)">'+
            '</label></div>'+
        '</div>'+
        '<div class="field-hint">Upload in correct order — students will see them shuffled.</div>'+
        '<div class="dz-section">'+
            '<label class="field-label">Drop zone background <span class="field-badge">optional</span></label>'+
            '<input type="hidden" name="dz_image_existing['+idx+']" class="js-dz-src" value="">'+
            '<input type="hidden" name="dz_image_left['+idx+']" class="js-dz-left" value="10">'+
            '<input type="hidden" name="dz_image_top['+idx+']" class="js-dz-top" value="10">'+
            '<input type="hidden" name="dz_image_width['+idx+']" class="js-dz-width" value="180">'+
            '<input type="hidden" name="dz_image_id['+idx+']" class="js-dz-id" value="">'+
            '<div class="dz-preview-area" style="display:none"><div class="dz-preview-hint">Drag to reposition</div></div>'+
            '<div class="dz-controls-row">'+
                '<input type="file" name="dz_image_file['+idx+']" accept="image/*" class="js-dz-file" style="margin-bottom:0">'+
                '<button type="button" class="btn-remove-dz" onclick="loDzRemove(this)" style="display:none">&#x2716; Remove</button>'+
            '</div>'+
            '<div class="dz-width-control" style="display:none">'+
                '<span>Width:</span><input type="number" min="60" max="800" value="180" class="js-dz-wdisplay"><span>px</span>'+
            '</div>'+
        '</div>';

    c.appendChild(div);
    loReindex();
    loDzInit(div);
    loMark();
}

function loDzInit(blockEl){
    var area=blockEl.querySelector('.dz-preview-area'),file=blockEl.querySelector('.js-dz-file');
    var rBtn=blockEl.querySelector('.btn-remove-dz'),wCtrl=blockEl.querySelector('.dz-width-control');
    var wDisp=blockEl.querySelector('.js-dz-wdisplay'),srcH=blockEl.querySelector('.js-dz-src');
    var leftH=blockEl.querySelector('.js-dz-left'),topH=blockEl.querySelector('.js-dz-top');
    var widH=blockEl.querySelector('.js-dz-width'),idH=blockEl.querySelector('.js-dz-id');

    if (!area) return;

    function getImg(){ return area.querySelector('.dz-preview-image'); }

    function sync(){
        var img=getImg();
        if(!img) return;
        leftH.value=parseInt(img.style.left,10)||0;
        topH.value=parseInt(img.style.top,10)||0;
        widH.value=parseInt(img.style.width,10)||180;
    }

    function clamp(img){
        img.style.left=Math.max(0,Math.min(area.offsetWidth-img.offsetWidth,parseInt(img.style.left,10)||0))+'px';
        img.style.top=Math.max(0,Math.min(area.offsetHeight-img.offsetHeight,parseInt(img.style.top,10)||0))+'px';
        sync();
    }

    function addDrag(img){
        var on=false,sx,sy,sl,st;

        img.addEventListener('mousedown',function(e){
            e.preventDefault();
            on=true;
            sx=e.clientX;
            sy=e.clientY;
            sl=parseInt(img.style.left,10)||0;
            st=parseInt(img.style.top,10)||0;
        });

        document.addEventListener('mousemove',function(e){
            if(!on) return;
            img.style.left=(sl+e.clientX-sx)+'px';
            img.style.top=(st+e.clientY-sy)+'px';
        });

        document.addEventListener('mouseup',function(){
            if(!on) return;
            on=false;
            clamp(img);
            loMark();
        });

        img.addEventListener('touchstart',function(e){
            e.preventDefault();
            on=true;
            sx=e.touches[0].clientX;
            sy=e.touches[0].clientY;
            sl=parseInt(img.style.left,10)||0;
            st=parseInt(img.style.top,10)||0;
        },{passive:false});

        document.addEventListener('touchmove',function(e){
            if(!on) return;
            img.style.left=(sl+e.touches[0].clientX-sx)+'px';
            img.style.top=(st+e.touches[0].clientY-sy)+'px';
        },{passive:true});

        document.addEventListener('touchend',function(){
            if(!on) return;
            on=false;
            clamp(img);
            loMark();
        });
    }

    function showUI(){
        area.style.display='';
        if(rBtn) rBtn.style.display='';
        if(wCtrl) wCtrl.style.display='';
    }

    if (file) file.addEventListener('change',function(){
        if (!file.files||!file.files[0]) return;

        var r=new FileReader();
        r.onload=function(e){
            var old=getImg();
            if(old) old.remove();

            var w=parseInt(widH.value,10)||180;

            var img=document.createElement('img');
            img.className='dz-preview-image';
            img.src=e.target.result;
            img.draggable=false;
            img.alt='';
            img.style.left='10px';
            img.style.top='10px';
            img.style.width=w+'px';

            area.appendChild(img);
            addDrag(img);

            if(srcH) srcH.value='';
            if(idH) idH.value='dzi_'+Date.now();
            if(wDisp) wDisp.value=w;

            showUI();
            sync();
            loMark();
        };

        r.readAsDataURL(file.files[0]);
    });

    if (wDisp) wDisp.addEventListener('input',function(){
        var img=getImg();
        if(!img) return;

        var w=Math.max(60,Math.min(800,parseInt(wDisp.value,10)||180));
        img.style.width=w+'px';
        widH.value=w;

        loMark();
    });

    var ex=getImg();
    if(ex){
        addDrag(ex);
        showUI();
        if(wDisp) wDisp.value=parseInt(widH.value,10)||180;
    }
}

function loDzRemove(btn){
    var b=btn.closest('.block-item');
    if(!b) return;

    var img=b.querySelector('.dz-preview-image');
    if(img) img.remove();

    var area=b.querySelector('.dz-preview-area');
    if(area) area.style.display='none';

    [['js-dz-src',''],['js-dz-left','10'],['js-dz-top','10'],['js-dz-width','180'],['js-dz-id','']].forEach(function(p){
        var el=b.querySelector('.'+p[0]);
        if(el) el.value=p[1];
    });

    var fi=b.querySelector('.js-dz-file');
    if(fi) fi.value='';

    var wc=b.querySelector('.dz-width-control');
    if(wc) wc.style.display='none';

    btn.style.display='none';

    loMark();
}

document.addEventListener('DOMContentLoaded',function(){
    document.querySelectorAll('#blocksContainer .block-item').forEach(loDzInit);

    var form=document.getElementById('loForm');
    if(form) form.addEventListener('submit',function(){
        loReindex();
        loSubmitted=true;
        loChanged=false;
    });
});

window.addEventListener('beforeunload',function(e){
    if(loChanged&&!loSubmitted){
        e.preventDefault();
        e.returnValue='';
    }
});
</script>
<?php
$content=ob_get_clean();
render_activity_editor("🎧 Listen & Order Editor","🎧",$content);
