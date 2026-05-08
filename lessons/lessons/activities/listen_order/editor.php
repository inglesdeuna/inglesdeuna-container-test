<?php
if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . "/../../config/db.php";
require_once __DIR__ . "/../../core/cloudinary_upload.php";
require_once __DIR__ . "/../../core/_activity_editor_template.php";

if (isset($_SESSION['student_logged']) && $_SESSION['student_logged']) {
    header('Location: /lessons/lessons/academic/student_dashboard.php?error=access_denied');
    exit;
}

if (empty($_SESSION['academic_logged']) && empty($_SESSION['admin_logged'])) {
    header('Location: /lessons/lessons/academic/login.php');
    exit;
}

$activityId = trim((string)($_GET["id"] ?? ""));
$unit       = trim((string)($_GET["unit"] ?? ""));
$source     = trim((string)($_GET["source"] ?? ""));
$assignment = trim((string)($_GET["assignment"] ?? ""));

function lo_default_title(): string {
    return "Listen & Order";
}

function lo_resolve_unit(PDO $pdo, string $id): string {
    if ($id === "") return "";
    $st = $pdo->prepare("SELECT unit_id FROM activities WHERE id=:id LIMIT 1");
    $st->execute(["id" => $id]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r ? (string)$r["unit_id"] : "";
}

function lo_normalize(mixed $raw): array {
    $def = [
        "title" => lo_default_title(),
        "instructions" => "",
        "blocks" => [],
    ];

    if (!$raw) return $def;

    $d = is_string($raw) ? json_decode($raw, true) : $raw;
    if (!is_array($d)) return $def;

    $title = trim((string)($d["title"] ?? ""));
    $instr = trim((string)($d["instructions"] ?? ""));
    $src   = isset($d["blocks"]) && is_array($d["blocks"]) ? $d["blocks"] : $d;

    $blocks = [];

    foreach ($src as $b) {
        if (!is_array($b)) continue;

        $sentence = trim((string)($b["sentence"] ?? ""));
        $videoUrl = trim((string)($b["video_url"] ?? ""));

        $images = [];
        foreach ((array)($b["images"] ?? []) as $img) {
            $u = trim((string)$img);
            if ($u !== "") $images[] = $u;
        }

        $dropZoneImages = [];
        foreach ((array)($b["dropZoneImages"] ?? []) as $dzi) {
            if (!is_array($dzi)) continue;
            $srcUrl = trim((string)($dzi["src"] ?? ""));
            if ($srcUrl === "") continue;

            $dropZoneImages[] = [
                "id"    => trim((string)($dzi["id"] ?? uniqid("dzi_"))),
                "src"   => $srcUrl,
                "left"  => (int)($dzi["left"] ?? 0),
                "top"   => (int)($dzi["top"] ?? 0),
                "width" => max(60, min(800, (int)($dzi["width"] ?? 180))),
            ];
        }

        $audioUrl = trim((string)($b["audio_url"] ?? ""));
        $voiceId  = trim((string)($b["voice_id"]  ?? "JBFqnCBsd6RMkjVDRZzb"));
        if ($voiceId === "") $voiceId = "JBFqnCBsd6RMkjVDRZzb";

        if ($sentence === "" && $videoUrl === "" && empty($images)) continue;

        $blocks[] = [
            "id"             => trim((string)($b["id"] ?? uniqid("lo_"))),
            "sentence"       => $sentence,
            "voice_id"       => $voiceId,
            "audio_url"      => $audioUrl,
            "video_url"      => $videoUrl,
            "images"         => $images,
            "dropZoneImages" => $dropZoneImages,
        ];
    }

    return [
        "title"        => $title !== "" ? $title : lo_default_title(),
        "instructions" => $instr,
        "blocks"       => $blocks,
    ];
}

function lo_load(PDO $pdo, string $unit, string $activityId): array {
    $fallback = [
        "id" => "",
        "title" => lo_default_title(),
        "instructions" => "",
        "blocks" => [],
    ];

    $row = null;

    if ($activityId !== "") {
        $st = $pdo->prepare("
            SELECT id, data
            FROM activities
            WHERE id=:id
              AND type IN ('listen_order','listen_and_order','listenorder')
            LIMIT 1
        ");
        $st->execute(["id" => $activityId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
    }

    if (!$row && $unit !== "") {
        $st = $pdo->prepare("
            SELECT id, data
            FROM activities
            WHERE unit_id=:unit
              AND type IN ('listen_order','listen_and_order','listenorder')
            ORDER BY id ASC
            LIMIT 1
        ");
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
        $st = $pdo->prepare("
            SELECT id
            FROM activities
            WHERE unit_id=:unit
              AND type IN ('listen_order','listen_and_order','listenorder')
            ORDER BY id ASC
            LIMIT 1
        ");
        $st->execute(["unit" => $unit]);
        $tid = trim((string)$st->fetchColumn());
    }

    if ($tid !== "") {
        $st = $pdo->prepare("
            UPDATE activities
            SET data=:data, type='listen_order'
            WHERE id=:id
        ");
        $st->execute(["data" => $json, "id" => $tid]);
        return $tid;
    }

    $st = $pdo->prepare("
        INSERT INTO activities (unit_id,type,data,position,created_at)
        VALUES (
            :u,
            'listen_order',
            :d,
            (SELECT COALESCE(MAX(position),0)+1 FROM activities WHERE unit_id=:u2),
            CURRENT_TIMESTAMP
        )
        RETURNING id
    ");
    $st->execute(["u" => $unit, "u2" => $unit, "d" => $json]);

    return (string)$st->fetchColumn();
}

if ($unit === "" && $activityId !== "") {
    $unit = lo_resolve_unit($pdo, $activityId);
}

if ($unit === "") die("Unit not specified");

$activity = lo_load($pdo, $unit, $activityId);
$edTitle  = (string)($activity["title"] ?? lo_default_title());
$edInstr  = (string)($activity["instructions"] ?? "");
$blocks   = is_array($activity["blocks"] ?? null) ? $activity["blocks"] : [];

if ($activityId === "" && !empty($activity["id"])) {
    $activityId = (string)$activity["id"];
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $postedTitle = trim((string)($_POST["activity_title"] ?? ""));
    $postedInstr = trim((string)($_POST["activity_instructions"] ?? ""));

    $blockIds       = is_array($_POST["block_id"] ?? null) ? $_POST["block_id"] : [];
    $videoExisting  = is_array($_POST["video_url_existing"] ?? null) ? $_POST["video_url_existing"] : [];
    $audioExisting  = is_array($_POST["audio_url_existing"] ?? null) ? $_POST["audio_url_existing"] : [];
    $sentences      = is_array($_POST["sentence"] ?? null) ? $_POST["sentence"] : [];
    $voiceIds       = is_array($_POST["voice_id"] ?? null) ? $_POST["voice_id"] : [];
    $imagesExisting = is_array($_POST["images_existing"] ?? null) ? $_POST["images_existing"] : [];

    $videoFiles = $_FILES["video_file"] ?? null;
    $imageFiles = $_FILES["images"] ?? null;

    $blockCount = max(
        count($blockIds),
        count($videoExisting),
        count($audioExisting),
        count($sentences),
        count($voiceIds),
        count($imagesExisting),
        is_array($videoFiles["name"] ?? null) ? count($videoFiles["name"]) : 0,
        is_array($imageFiles["name"] ?? null) ? count($imageFiles["name"]) : 0
    );

    $sanitized = [];

    for ($i = 0; $i < $blockCount; $i++) {
        $blockId  = trim((string)($blockIds[$i] ?? uniqid("lo_")));

        $sentence = trim((string)($sentences[$i] ?? ""));
        $audioUrl = trim((string)($audioExisting[$i] ?? ""));
        $videoUrl = trim((string)($videoExisting[$i] ?? ""));
        $voiceId  = trim((string)($voiceIds[$i] ?? "JBFqnCBsd6RMkjVDRZzb"));
        if ($voiceId === "" || !preg_match('/^[A-Za-z0-9]+$/', $voiceId)) $voiceId = "JBFqnCBsd6RMkjVDRZzb";

        if (
            $videoFiles &&
            isset($videoFiles["tmp_name"][$i]) &&
            !empty($videoFiles["tmp_name"][$i]) &&
            ($videoFiles["error"][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK
        ) {
            $uploadedVideo = upload_video_to_cloudinary($videoFiles["tmp_name"][$i]);
            if ($uploadedVideo) {
                $videoUrl = $uploadedVideo;
            }
        }

        $images = [];

        if (isset($imagesExisting[$i]) && is_array($imagesExisting[$i])) {
            foreach ($imagesExisting[$i] as $img) {
                $u = trim((string)$img);
                if ($u !== "") $images[] = $u;
            }
        }

        if ($imageFiles && isset($imageFiles["name"][$i]) && is_array($imageFiles["name"][$i])) {
            foreach ($imageFiles["name"][$i] as $k => $name) {
                if (!$name || empty($imageFiles["tmp_name"][$i][$k])) continue;
                if (($imageFiles["error"][$i][$k] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) continue;

                $uploadedImage = upload_to_cloudinary($imageFiles["tmp_name"][$i][$k]);
                if ($uploadedImage) {
                    $images[] = $uploadedImage;
                }
            }
        }

        if ($videoUrl === "" && empty($images)) {
            continue;
        }

        $sanitized[] = [
            "id"             => $blockId !== "" ? $blockId : uniqid("lo_"),
            "sentence"       => $sentence,
            "voice_id"       => $voiceId,
            "audio_url"      => $audioUrl,
            "video_url"      => $videoUrl,
            "images"         => array_values($images),
            "dropZoneImages" => [],
        ];
    }

    $savedId = lo_save($pdo, $unit, $activityId, $postedTitle, $postedInstr, $sanitized);

    $qs = "unit=" . urlencode($unit) . "&saved=1";
    if ($savedId !== "") $qs .= "&id=" . urlencode($savedId);
    if ($assignment !== "") $qs .= "&assignment=" . urlencode($assignment);
    if ($source !== "") $qs .= "&source=" . urlencode($source);

    header("Location: editor.php?" . $qs);
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
body{background:#f8f7ff!important;font-family:'Nunito',sans-serif!important}
.lo-form{max-width:780px;margin:0 auto}
.lo-saved{background:#E6F9F2;border:1px solid #9FE1CB;border-radius:12px;padding:10px 16px;color:#0F6E56;font-size:13px;font-weight:900;margin-bottom:16px}
.lo-card,.block-item{background:#fff;border:1px solid #F0EEF8;border-radius:20px;padding:20px 22px;margin-bottom:14px;box-shadow:0 4px 18px rgba(127,119,221,.08)}
.field-label{display:block;font-size:12px;font-weight:900;letter-spacing:.08em;text-transform:uppercase;color:#9B94BE;margin-bottom:8px}
.field-badge{display:inline-block;background:#EEEDFE;color:#534AB7;border-radius:999px;padding:2px 8px;font-size:10px;font-weight:700;text-transform:none;margin-left:6px}
.lo-form input[type=text],.lo-form textarea{width:100%;border:1.5px solid #EDE9FA;border-radius:12px;padding:11px 14px;font-family:'Nunito',sans-serif;font-size:14px;font-weight:700;color:#271B5D;background:#fff;outline:none;margin-bottom:12px}
.lo-form input:focus,.lo-form textarea:focus{border-color:#7F77DD;box-shadow:0 0 0 3px rgba(127,119,221,.1)}
.lo-form textarea{min-height:60px;resize:vertical}
.block-header-row{display:flex;justify-content:space-between;align-items:center;margin-bottom:16px}
.block-badge{background:#EEEDFE;color:#534AB7;border-radius:999px;padding:4px 14px;font-size:12px;font-weight:900}
.btn-remove{background:#FCEBEB;color:#E24B4A;border:1px solid #F7C1C1;border-radius:999px;padding:5px 14px;font-size:12px;font-weight:900;cursor:pointer}
.media-box{border:1.5px solid #EDE9FA;border-radius:16px;padding:14px;margin-bottom:16px;background:#FAFAFE}
.upload-zone{border:2px dashed #EDE9FA;border-radius:14px;background:#fff;padding:20px;text-align:center;cursor:pointer;margin-bottom:12px}
.upload-zone:hover{border-color:#7F77DD;background:#EEEDFE}
.upload-zone-icon{width:40px;height:40px;border-radius:50%;background:#EEEDFE;display:flex;align-items:center;justify-content:center;margin:0 auto 8px;font-size:18px}
.upload-zone-title{font-size:13px;font-weight:900;color:#534AB7;margin-bottom:3px}
.upload-zone-sub{font-size:11px;font-weight:700;color:#9B94BE}
.file-input-hidden{position:absolute;width:1px;height:1px;opacity:0;left:-9999px}
.video-preview-wrap{border-radius:12px;overflow:hidden;background:#000;margin-bottom:10px;max-height:240px;display:flex;align-items:center;justify-content:center}
.video-preview-wrap video{width:100%;max-height:240px;object-fit:contain;display:block}
.btn-remove-video{background:#FCEBEB;color:#E24B4A;border:1px solid #F7C1C1;border-radius:999px;padding:5px 14px;font-size:12px;font-weight:900;cursor:pointer;margin-bottom:10px}
.img-grid{display:flex;flex-wrap:wrap;gap:10px;margin-bottom:8px;align-items:flex-start}
.img-card{position:relative;width:86px;border-radius:14px;border:1.5px solid #EDE9FA;overflow:visible;background:#fff;display:flex;flex-direction:column;align-items:center}
.img-card img{width:86px;height:86px;object-fit:cover;border-radius:12px;display:block}
.img-pos-badge{position:absolute;top:4px;left:4px;width:20px;height:20px;border-radius:50%;background:#7F77DD;color:#fff;font-size:10px;font-weight:900;display:flex;align-items:center;justify-content:center;z-index:2}
.img-remove-btn{border:none;background:none;color:#E24B4A;font-size:10px;font-weight:900;cursor:pointer;padding:3px 0 2px;text-align:center;width:100%;line-height:1}
.img-add-slot{width:86px;height:86px;border-radius:14px;border:1.5px dashed #EDE9FA;background:#FAFAFE;display:flex;align-items:center;justify-content:center;cursor:pointer}
.img-add-slot label{cursor:pointer;display:flex;flex-direction:column;align-items:center;justify-content:center;width:100%;height:100%;font-size:11px;font-weight:900;color:#9B94BE;gap:4px;margin:0}
.img-add-slot .plus{font-size:22px;color:#7F77DD;line-height:1}
.field-hint{color:#9B94BE;font-size:12px;font-weight:800;margin:-6px 0 12px}
.tts-box{border:1.5px solid #EDE9FA;border-radius:16px;padding:14px;margin-bottom:16px;background:#FAFAFE}
.tts-row{display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap}
.tts-row input[type=text]{flex:1;min-width:160px;margin-bottom:0!important}
.tts-voice{border:1.5px solid #EDE9FA;border-radius:12px;padding:10px 12px;font-family:'Nunito',sans-serif;font-size:13px;font-weight:700;color:#271B5D;background:#fff;outline:none;cursor:pointer;margin-bottom:0!important}
.tts-voice:focus{border-color:#7F77DD;box-shadow:0 0 0 3px rgba(127,119,221,.1)}
.btn-tts{background:#7F77DD;color:#fff;border:none;border-radius:999px;padding:11px 18px;font-size:12px;font-weight:900;cursor:pointer;white-space:nowrap;flex-shrink:0;display:inline-flex;align-items:center;gap:6px}
.btn-tts:disabled{opacity:.55;cursor:not-allowed}
.tts-status{font-size:12px;font-weight:800;margin-top:8px;min-height:18px}
.tts-status.ok{color:#1D9E75}.tts-status.err{color:#E24B4A}
.tts-preview{margin-top:10px;display:flex;align-items:center;gap:10px}
.tts-preview audio{flex:1;height:36px}
.btn-tts-remove{background:none;border:none;color:#E24B4A;font-size:11px;font-weight:900;cursor:pointer;padding:0}
.toolbar-row{display:flex;gap:10px;flex-wrap:wrap;justify-content:center;padding-top:20px;border-top:1px solid #F0EEF8;margin-top:8px}
.btn-add{background:#fff;color:#534AB7;border:1.5px solid #EDE9FA;border-radius:999px;padding:12px 26px;font-size:13px;font-weight:900;cursor:pointer}
.save-btn{background:#F97316;color:#fff;border:none;border-radius:999px;padding:12px 26px;font-size:13px;font-weight:900;cursor:pointer;box-shadow:0 6px 18px rgba(249,115,22,.22)}
@media(max-width:640px){.toolbar-row{flex-direction:column;align-items:center}.btn-add,.save-btn{width:100%;max-width:300px}}
</style>

<form method="post" enctype="multipart/form-data" class="lo-form" id="loForm">
    <div class="lo-card">
        <label class="field-label" for="lo_title">Activity title</label>
        <input id="lo_title" type="text" name="activity_title" value="<?= htmlspecialchars($edTitle, ENT_QUOTES, 'UTF-8') ?>" placeholder="Listen and order" required>

        <label class="field-label" for="lo_instr">Instructions <span class="field-badge">shown below title</span></label>
        <textarea id="lo_instr" name="activity_instructions" placeholder="Watch the video and order the images."><?= htmlspecialchars($edInstr, ENT_QUOTES, 'UTF-8') ?></textarea>
    </div>

    <div id="blocksContainer">
        <?php foreach ($blocks as $bi => $block):
            $bVideoUrl  = trim((string)($block["video_url"]  ?? ""));
            $bSentence  = trim((string)($block["sentence"]   ?? ""));
            $bAudioUrl  = trim((string)($block["audio_url"]  ?? ""));
            $bImages    = is_array($block["images"] ?? null) ? $block["images"] : [];
        ?>
        <div class="block-item">
            <input type="hidden" name="block_id[]" value="<?= htmlspecialchars((string)($block["id"] ?? uniqid("lo_")), ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="video_url_existing[]" class="js-vidurl" value="<?= htmlspecialchars($bVideoUrl, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="audio_url_existing[]" class="js-audiourl" value="<?= htmlspecialchars($bAudioUrl, ENT_QUOTES, 'UTF-8') ?>">

            <div class="block-header-row">
                <span class="block-badge">Block <?= $bi + 1 ?></span>
                <button type="button" class="btn-remove" onclick="loRemoveBlock(this)">✖ Remove</button>
            </div>

            <div class="tts-box">
                <label class="field-label">Sentence to speak <span class="field-badge">ElevenLabs TTS</span></label>
                <div class="tts-row">
                    <input type="text" name="sentence[]" class="js-sentence" value="<?= htmlspecialchars($bSentence, ENT_QUOTES, 'UTF-8') ?>" placeholder="Type the sentence students will hear…">
                    <select name="voice_id[]" class="js-voiceid tts-voice">
                        <option value="JBFqnCBsd6RMkjVDRZzb"<?= ($block["voice_id"]??"JBFqnCBsd6RMkjVDRZzb")==="JBFqnCBsd6RMkjVDRZzb"?" selected":"" ?>>👨 Adult Male (George)</option>
                        <option value="21m00Tcm4TlvDq8ikWAM"<?= ($block["voice_id"]??"")==="21m00Tcm4TlvDq8ikWAM"?" selected":"" ?>>👩 Adult Female (Rachel)</option>
                        <option value="pFZP5JQG7iQjIQuC4Bku"<?= ($block["voice_id"]??"")==="pFZP5JQG7iQjIQuC4Bku"?" selected":"" ?>>🧒 Child (Lily)</option>
                    </select>
                    <button type="button" class="btn-tts" onclick="loGenerateTTS(this)">🔊 Generate audio</button>
                </div>
                <div class="tts-status"></div>
                <?php if ($bAudioUrl !== ""): ?>
                <div class="tts-preview">
                    <audio src="<?= htmlspecialchars($bAudioUrl, ENT_QUOTES, 'UTF-8') ?>" controls preload="none"></audio>
                    <button type="button" class="btn-tts-remove" onclick="loRemoveAudio(this)">✖ Remove</button>
                </div>
                <?php endif; ?>
            </div>

            <div class="media-box">
                <label class="field-label">Video for this order activity</label>

                <input type="file" name="video_file[<?= $bi ?>]" accept="video/*" class="js-vf-input file-input-hidden" onchange="loVideoPreview(this)">

                <?php if ($bVideoUrl !== ""): ?>
                    <div class="video-preview-wrap">
                        <video src="<?= htmlspecialchars($bVideoUrl, ENT_QUOTES, 'UTF-8') ?>" controls preload="metadata"></video>
                    </div>
                    <button type="button" class="btn-remove-video" onclick="loRemoveVideo(this)">✖ Remove video</button>
                <?php else: ?>
                    <div class="upload-zone js-vf-zone" onclick="this.closest('.media-box').querySelector('.js-vf-input').click()">
                        <div class="upload-zone-icon">🎬</div>
                        <div class="upload-zone-title">Upload video file</div>
                        <div class="upload-zone-sub">MP4, MOV, WEBM</div>
                    </div>
                <?php endif; ?>

                <div class="js-video-anchor"></div>
            </div>

            <label class="field-label">Images in correct order <span class="field-badge">students see them shuffled</span></label>
            <div class="img-grid">
                <?php foreach ($bImages as $ii => $img): ?>
                    <div class="img-card">
                        <span class="img-pos-badge"><?= $ii + 1 ?></span>
                        <img src="<?= htmlspecialchars($img, ENT_QUOTES, 'UTF-8') ?>" alt="">
                        <input type="hidden" name="images_existing[<?= $bi ?>][]" value="<?= htmlspecialchars($img, ENT_QUOTES, 'UTF-8') ?>">
                        <button type="button" class="img-remove-btn" onclick="loRemoveImg(this)">Remove</button>
                    </div>
                <?php endforeach; ?>

                <?php if (empty($bImages)): ?>
                    <input type="hidden" name="images_existing[<?= $bi ?>][]" value="">
                <?php endif; ?>

                <div class="img-add-slot">
                    <label>
                        <span class="plus">+</span>
                        <span>Add</span>
                        <input type="file" name="images[<?= $bi ?>][]" multiple accept="image/*" style="display:none" onchange="loPreviewImages(this)">
                    </label>
                </div>
            </div>

            <div class="field-hint">Upload images in the correct order. Students will see them shuffled.</div>
        </div>
        <?php endforeach; ?>
    </div>

    <div class="toolbar-row">
        <button type="button" class="btn-add" onclick="loAddBlock()">+ Add Block</button>
        <button type="submit" class="save-btn">💾 Save</button>
    </div>
</form>

<script>
var loChanged=false, loSubmitted=false;
var loInputSeq=0;
function loMark(){ loChanged=true; }

function loEnsureInputId(input){
    if (!input) return '';
    if (!input.dataset.inputId) {
        loInputSeq += 1;
        input.dataset.inputId = 'lo_img_input_' + Date.now() + '_' + loInputSeq;
    }
    return input.dataset.inputId;
}

function loVideoPreview(input){
    if (!input.files || !input.files[0]) return;

    var box = input.closest('.media-box');
    var zone = box.querySelector('.js-vf-zone');
    if (zone) zone.remove();

    var oldWrap = box.querySelector('.video-preview-wrap');
    if (oldWrap) oldWrap.remove();

    var oldBtn = box.querySelector('.btn-remove-video');
    if (oldBtn) oldBtn.remove();

    var wrap = document.createElement('div');
    wrap.className = 'video-preview-wrap';
    wrap.innerHTML = '<video src="'+URL.createObjectURL(input.files[0])+'" controls preload="metadata"></video>';

    var btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'btn-remove-video';
    btn.textContent = '✖ Remove video';
    btn.onclick = function(){ loRemoveVideo(btn); };

    var anchor = box.querySelector('.js-video-anchor');
    if (anchor) {
        box.insertBefore(wrap, anchor);
        box.insertBefore(btn, anchor);
    } else {
        box.appendChild(wrap);
        box.appendChild(btn);
    }

    loMark();
}

function loRemoveVideo(btn){
    var block = btn.closest('.block-item');
    var box = btn.closest('.media-box');

    var wrap = box.querySelector('.video-preview-wrap');
    if (wrap) wrap.remove();

    btn.remove();

    var input = box.querySelector('.js-vf-input');
    if (input) input.value = '';

    var hidden = block.querySelector('.js-vidurl');
    if (hidden) hidden.value = '';

    if (!box.querySelector('.js-vf-zone')) {
        var zone = document.createElement('div');
        zone.className = 'upload-zone js-vf-zone';
        zone.onclick = function(){
            box.querySelector('.js-vf-input').click();
        };
        zone.innerHTML =
            '<div class="upload-zone-icon">🎬</div>'+
            '<div class="upload-zone-title">Upload video file</div>'+
            '<div class="upload-zone-sub">MP4, MOV, WEBM</div>';

        var anchor = box.querySelector('.js-video-anchor');
        if (anchor) {
            box.insertBefore(zone, anchor);
        } else {
            box.appendChild(zone);
        }
    }

    loMark();
}

function loPreviewImages(input){
    if (!input.files || !input.files.length) return;

    var grid = input.closest('.img-grid');
    var slot = input.closest('.img-add-slot');
    if (!grid || !slot) return;

    var block = input.closest('.block-item');
    var blocks = Array.from(document.querySelectorAll('#blocksContainer .block-item'));
    var bi = blocks.indexOf(block);
    if (bi < 0) bi = 0;

    var base = grid.querySelectorAll('.img-card').length;
    var inputId = loEnsureInputId(input);

    Array.from(input.files).forEach(function(file, idx){
        var r = new FileReader();
        r.onload = function(e){
            var card = document.createElement('div');
            card.className = 'img-card';
            card.dataset.fileInputId = inputId;
            card.dataset.fileIndex = String(idx);
            card.innerHTML =
                '<span class="img-pos-badge">'+(base + idx + 1)+'</span>'+
                '<img src="'+e.target.result+'" alt="">'+
                '<button type="button" class="img-remove-btn" onclick="loRemoveImg(this)">Remove</button>';
            grid.insertBefore(card, slot);
        };
        r.readAsDataURL(file);
    });

    input.name = 'images['+bi+'][]';
    input.style.display = 'none';
    input.onchange = null;
    grid.insertBefore(input, slot);

    var clone = document.createElement('input');
    clone.type = 'file';
    clone.multiple = true;
    clone.accept = 'image/*';
    clone.name = 'images['+bi+'][]';
    clone.style.display = 'none';
    clone.onchange = function(){ loPreviewImages(clone); };
    slot.querySelector('label').appendChild(clone);

    loMark();
}

function loRemoveImg(btn){
    var card = btn.closest('.img-card');
    var grid = card ? card.closest('.img-grid') : null;

    if (card && grid && card.dataset.fileInputId) {
        var fileInputId = card.dataset.fileInputId;
        var fileIndex = parseInt(card.dataset.fileIndex || '-1', 10);
        var fileInput = grid.querySelector('input[type="file"][data-input-id="'+fileInputId+'"]');

        if (fileInput && fileInput.files && fileInput.files.length && fileIndex >= 0 && typeof DataTransfer !== 'undefined') {
            try {
                var dt = new DataTransfer();
                Array.from(fileInput.files).forEach(function(file, idx){
                    if (idx !== fileIndex) dt.items.add(file);
                });
                fileInput.files = dt.files;
            } catch (e) {
                // If browser blocks DataTransfer mutation, keep UI removal only.
            }
        }
    }

    if (card) card.remove();

    if (grid) {
        grid.querySelectorAll('.img-card').forEach(function(c, i){
            var badge = c.querySelector('.img-pos-badge');
            if (badge) badge.textContent = String(i + 1);
        });

        var groupedCards = {};
        grid.querySelectorAll('.img-card[data-file-input-id]').forEach(function(c){
            var id = c.dataset.fileInputId;
            if (!groupedCards[id]) groupedCards[id] = [];
            groupedCards[id].push(c);
        });
        Object.keys(groupedCards).forEach(function(id){
            groupedCards[id].forEach(function(c, idx){
                c.dataset.fileIndex = String(idx);
            });
        });
    }

    loMark();
}

function loReindex(){
    document.querySelectorAll('#blocksContainer .block-item').forEach(function(block, idx){
        var videoInput = block.querySelector('.js-vf-input');
        if (videoInput) videoInput.name = 'video_file['+idx+']';

        block.querySelectorAll('input[type="file"][name^="images["]').forEach(function(input){
            input.name = 'images['+idx+'][]';
        });

        block.querySelectorAll('input[type="hidden"][name^="images_existing["]').forEach(function(input){
            input.name = 'images_existing['+idx+'][]';
        });
    });
}

function loRenumber(){
    document.querySelectorAll('#blocksContainer .block-item').forEach(function(block, idx){
        var badge = block.querySelector('.block-badge');
        if (badge) badge.textContent = 'Block ' + (idx + 1);
    });
}

function loRemoveBlock(btn){
    var item = btn.closest('.block-item');
    if (item) {
        item.remove();
        loReindex();
        loRenumber();
        loMark();
    }
}

function loRemoveAudio(btn){
    var box = btn.closest('.tts-box');
    if (!box) return;
    var hidden = btn.closest('.block-item').querySelector('.js-audiourl');
    if (hidden) hidden.value = '';
    var preview = box.querySelector('.tts-preview');
    if (preview) preview.remove();
    var statusEl = box.querySelector('.tts-status');
    if (statusEl) { statusEl.textContent = 'Audio removed.'; statusEl.className = 'tts-status'; }
    loMark();
}

function loGenerateTTS(btn){
    var box = btn.closest('.tts-box');
    if (!box) return;
    var sentenceInput = box.querySelector('.js-sentence');
    var text = sentenceInput ? sentenceInput.value.trim() : '';
    if (!text) { alert('Please enter a sentence first.'); return; }
    var voiceSelect = box.querySelector('.js-voiceid');
    var voiceId = voiceSelect ? voiceSelect.value : 'JBFqnCBsd6RMkjVDRZzb';
    var statusEl  = box.querySelector('.tts-status');
    var blockItem = btn.closest('.block-item');
    var audioHidden = blockItem ? blockItem.querySelector('.js-audiourl') : null;

    btn.disabled = true;
    if (statusEl) { statusEl.textContent = 'Generating…'; statusEl.className = 'tts-status'; }

    var fd = new FormData();
    fd.append('text', text);
    fd.append('voice_id', voiceId);

    fetch('tts.php', { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function(r){ return r.json(); })
        .then(function(data){
            if (data.error) throw new Error(data.error);
            if (audioHidden) audioHidden.value = data.url;

            // Remove old preview if any
            var old = box.querySelector('.tts-preview');
            if (old) old.remove();

            var div = document.createElement('div');
            div.className = 'tts-preview';
            div.innerHTML =
                '<audio src="'+data.url+'" controls preload="none"></audio>'+
                '<button type="button" class="btn-tts-remove" onclick="loRemoveAudio(this)">✖ Remove</button>';
            box.appendChild(div);

            if (statusEl) { statusEl.textContent = '✓ Audio generated successfully'; statusEl.className = 'tts-status ok'; }
            loMark();
        })
        .catch(function(err){
            if (statusEl) { statusEl.textContent = '✘ ' + (err.message || 'Generation failed'); statusEl.className = 'tts-status err'; }
        })
        .finally(function(){ btn.disabled = false; });
}

function loAddBlock(){
    var container = document.getElementById('blocksContainer');
    var idx = container.querySelectorAll('.block-item').length;

    var div = document.createElement('div');
    div.className = 'block-item';

    div.innerHTML =
        '<input type="hidden" name="block_id[]" value="lo_'+Date.now()+'_'+(Math.random()*10000|0)+'">'+
        '<input type="hidden" name="video_url_existing[]" class="js-vidurl" value="">'+
        '<input type="hidden" name="audio_url_existing[]" class="js-audiourl" value="">'+

        '<div class="block-header-row">'+
            '<span class="block-badge">Block '+(idx+1)+'</span>'+
            '<button type="button" class="btn-remove" onclick="loRemoveBlock(this)">✖ Remove</button>'+
        '</div>'+

        '<div class="tts-box">'+
            '<label class="field-label">Sentence to speak <span class="field-badge">ElevenLabs TTS</span></label>'+
            '<div class="tts-row">'+
                '<input type="text" name="sentence[]" class="js-sentence" value="" placeholder="Type the sentence students will hear…">'+
                '<select name="voice_id[]" class="js-voiceid tts-voice">'+
                    '<option value="JBFqnCBsd6RMkjVDRZzb" selected>\u{1F468} Adult Male (George)</option>'+
                    '<option value="21m00Tcm4TlvDq8ikWAM">\u{1F469} Adult Female (Rachel)</option>'+
                    '<option value="pFZP5JQG7iQjIQuC4Bku">\u{1F9D2} Child (Lily)</option>'+
                '</select>'+
                '<button type="button" class="btn-tts" onclick="loGenerateTTS(this)">\uD83D\uDD0A Generate audio</button>'+
            '</div>'+
            '<div class="tts-status"></div>'+
        '</div>'+

        '<div class="media-box">'+
            '<label class="field-label">Video for this order activity</label>'+
            '<input type="file" name="video_file['+idx+']" accept="video/*" class="js-vf-input file-input-hidden" onchange="loVideoPreview(this)">'+
            '<div class="upload-zone js-vf-zone" onclick="this.closest(\'.media-box\').querySelector(\'.js-vf-input\').click()">'+
                '<div class="upload-zone-icon">🎬</div>'+
                '<div class="upload-zone-title">Upload video file</div>'+
                '<div class="upload-zone-sub">MP4, MOV, WEBM</div>'+
            '</div>'+
            '<div class="js-video-anchor"></div>'+
        '</div>'+

        '<label class="field-label">Images in correct order <span class="field-badge">students see them shuffled</span></label>'+
        '<div class="img-grid">'+
            '<input type="hidden" name="images_existing['+idx+'][]" value="">'+
            '<div class="img-add-slot">'+
                '<label>'+
                    '<span class="plus">+</span>'+
                    '<span>Add</span>'+
                    '<input type="file" name="images['+idx+'][]" multiple accept="image/*" style="display:none" onchange="loPreviewImages(this)">'+
                '</label>'+
            '</div>'+
        '</div>'+
        '<div class="field-hint">Upload images in the correct order. Students will see them shuffled.</div>';

    container.appendChild(div);
    loReindex();
    loRenumber();
    loMark();
}

document.addEventListener('DOMContentLoaded', function(){
    document.querySelectorAll('#blocksContainer input[type="file"][name^="images["]').forEach(function(input){
        loEnsureInputId(input);
    });

    var form = document.getElementById('loForm');
    if (form) {
        form.addEventListener('submit', function(){
            loReindex();
            loSubmitted = true;
            loChanged = false;
        });

        form.addEventListener('input', loMark);
        form.addEventListener('change', loMark);
    }

    if (document.querySelectorAll('#blocksContainer .block-item').length === 0) {
        loAddBlock();
        loChanged = false;
    }
});

window.addEventListener('beforeunload', function(e){
    if (loChanged && !loSubmitted) {
        e.preventDefault();
        e.returnValue = '';
    }
});
</script>

<?php
$content = ob_get_clean();
render_activity_editor("🎧 Listen & Order Editor", "🎧", $content, $source, $assignment);
