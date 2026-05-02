<?php
if ($mediaType === 'audio') {
        if (isset($_FILES['media_file'])
            && !empty($_FILES['media_file']['name'])
            && ($_FILES['media_file']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
            $uploaded = upload_audio_to_cloudinary($_FILES['media_file']['tmp_name']);
            if ($uploaded) $mediaUrl = $uploaded;
        }
        if ($mediaUrl === '') $mediaUrl = trim((string)($_POST['current_media_url'] ?? ''));
    }

    $blockTexts   = isset($_POST['text'])      && is_array($_POST['text'])      ? $_POST['text']      : [];
    $blockAnswers = isset($_POST['answers'])   && is_array($_POST['answers'])   ? $_POST['answers']   : [];
    $blockImages  = isset($_POST['image_url']) && is_array($_POST['image_url']) ? $_POST['image_url'] : [];
    $imageUploads = $_FILES['image_upload'] ?? null;

    $blocks = [];
    foreach ($blockTexts as $i => $text) {
        $text    = trim((string)$text);
        $rawAns = isset($blockAnswers[$i]) ? (string)$blockAnswers[$i] : '';
        if (strpos($rawAns, '|') !== false) {
            $answers = array_values(array_filter(array_map('trim', explode('|', $rawAns)), fn($a) => $a !== ''));
        } else {
            $answers = array_values(array_filter(array_map('trim', explode(',', $rawAns)), fn($a) => $a !== ''));
        }
        $imgUrl  = isset($blockImages[$i])  ? trim((string)$blockImages[$i]) : '';
        if ($imageUploads
            && isset($imageUploads['tmp_name'][$i])
            && $imageUploads['error'][$i] === UPLOAD_ERR_OK
            && !empty($imageUploads['name'][$i])) {
            $up = upload_to_cloudinary($imageUploads['tmp_name'][$i]);
            if ($up) $imgUrl = $up;
        }
        $blocks[] = ['text' => $text, 'answers' => $answers, 'image' => $imgUrl];
    }

    $savedId = fb_ed_save($pdo, $unit, $activityId, [
        'instructions' => trim((string)($_POST['instructions'] ?? '')),
        'wordbank'     => trim((string)($_POST['wordbank']     ?? '')),
        'media_type'   => $mediaType,
        'media_url'    => $mediaUrl,
        'tts_text'     => $ttsText,
        'blocks'       => $blocks,
    ]);

    $params = ['unit='.urlencode($unit), 'saved=1', 'id='.urlencode($savedId)];
    if ($assignment !== '') $params[] = 'assignment='.urlencode($assignment);
    if ($source     !== '') $params[] = 'source='.urlencode($source);
    header('Location: editor.php?'.implode('&', $params));
    exit;
}

$activity   = fb_ed_load($pdo, $unit, $activityId);
if ($activityId === '' && $activity['id'] !== '') $activityId = $activity['id'];

ob_start();
if (isset($_GET['saved'])) {
    echo '<div class="fb-saved-banner">✔ Saved successfully</div>';
}
?>

<link href="https://fonts.googleapis.com/css2?family=Fredoka:wght@500;600;700&family=Nunito:wght@600;700;800&display=swap" rel="stylesheet">

<style>
:root {
    --t50:  #E1F5EE; --t100: #9FE1CB; --t200: #5DCAA5;
    --t400: #1D9E75; --t600: #0F6E56; --t800: #085041; --t900: #04342C;
    --purple: #7F77DD; --purple-d: #534AB7; --purple-l: #EEEDFE; --purple-b: #AFA9EC;
    --red: #dc2626; --radius: 10px; --radius-lg: 14px;
}
.fb-editor { max-width: 820px; margin: 0 auto; font-family: 'Nunito','Segoe UI',sans-serif; padding-bottom: 40px; }
.fb-saved-banner { background: var(--t50); border: 1.5px solid var(--t200); color: var(--t600);
    font-weight: 800; font-size: 14px; padding: 10px 16px; border-radius: var(--radius); margin-bottom: 16px; }

.fb-section { background: #fff; border: 1px solid var(--t100); border-radius: var(--radius-lg);
    margin-bottom: 16px; overflow: hidden; box-shadow: 0 2px 12px rgba(4,52,44,.08); }
.fb-section-header { background: var(--t50); border-bottom: 1px solid var(--t100);
    padding: 10px 18px; display: flex; align-items: center; gap: 8px; }
.fb-section-header h3 { font-family: 'Fredoka',sans-serif; font-size: 16px; font-weight: 600;
    color: var(--t800); margin: 0; }
.fb-section-body { padding: 16px 18px; }

.fb-field { margin-bottom: 14px; }
.fb-field:last-child { margin-bottom: 0; }
.fb-label { display: block; font-size: 12px; font-weight: 800; color: var(--t600);
    letter-spacing: .06em; text-transform: uppercase; margin-bottom: 5px; }
.fb-label small { text-transform: none; font-weight: 600; letter-spacing: 0; color: #6b7280; font-size: 11px; }
.fb-input, .fb-textarea, .fb-select {
    width: 100%; padding: 9px 12px; border: 1.5px solid var(--t100); border-radius: var(--radius);
    font-size: 14px; font-family: 'Nunito',sans-serif; font-weight: 600; color: #1e293b;
    background: #fff; box-sizing: border-box; outline: none;
    transition: border-color .15s, box-shadow .15s; }
.fb-input:focus, .fb-textarea:focus, .fb-select:focus {
    border-color: var(--t400); box-shadow: 0 0 0 3px rgba(29,158,117,.12); }
.fb-textarea { min-height: 80px; resize: vertical; }
.fb-select { appearance: none; cursor: pointer; padding-right: 32px;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='8' viewBox='0 0 12 8'%3E%3Cpath d='M1 1l5 5 5-5' stroke='%230F6E56' stroke-width='1.8' fill='none' stroke-linecap='round'/%3E%3C/svg%3E");
    background-repeat: no-repeat; background-position: right 12px center; }
.fb-help { font-size: 12px; color: #6b7280; font-weight: 600; margin-top: 4px; }

.fb-media-panel { display: none; margin-top: 12px; padding-top: 12px; border-top: 1px dashed var(--t100); }
.fb-media-panel.active { display: block; }

#fb-blocks-list { display: flex; flex-direction: column; gap: 10px; }
.fb-block-item { background: #f9fffe; border: 1.5px solid var(--t100); border-radius: var(--radius);
    padding: 12px 14px; transition: border-color .15s, box-shadow .15s; }
.fb-block-item:hover { border-color: var(--t200); box-shadow: 0 2px 8px rgba(29,158,117,.10); }
.fb-block-num { font-family: 'Fredoka',sans-serif; font-size: 13px; font-weight: 600;
    color: var(--t400); margin-bottom: 10px; }

.fb-thumb { width: 56px; height: 56px; border-radius: 8px; object-fit: contain;
    border: 1.5px solid var(--t100); background: var(--t50); margin-top: 4px; }

.fb-btn-add { display: inline-flex; align-items: center; gap: 6px; background: var(--t400); color: #fff;
    border: none; border-radius: var(--radius); padding: 9px 16px; font-size: 13px; font-weight: 800;
    font-family: 'Nunito',sans-serif; cursor: pointer; transition: background .15s, transform .12s; }
.fb-btn-add:hover { background: var(--t600); transform: translateY(-1px); }
.fb-btn-remove { background: #fee2e2; color: var(--red); border: 1px solid #fca5a5;
    border-radius: 8px; padding: 6px 12px; font-size: 12px; font-weight: 800;
    font-family: 'Nunito',sans-serif; cursor: pointer; transition: background .12s; }
.fb-btn-remove:hover { background: #fecaca; }
.fb-btn-save { display: inline-flex; align-items: center; gap: 8px; background: var(--t800); color: #fff;
    border: none; border-radius: var(--radius-lg); padding: 12px 28px; font-size: 15px; font-weight: 800;
    font-family: 'Fredoka',sans-serif; cursor: pointer;
    box-shadow: 0 4px 14px rgba(8,80,65,.25); transition: background .15s, transform .15s; }
.fb-btn-save:hover { background: var(--t900); transform: translateY(-2px); }
.fb-save-row { display: flex; justify-content: center; margin-top: 8px; }
.fb-toolbar   { display: flex; gap: 10px; flex-wrap: wrap; align-items: center; margin-top: 12px; }
</style>

<form method="post" enctype="multipart/form-data" class="fb-editor" id="fbEditorForm" novalidate>
<input type="hidden" name="current_media_url" value="<?= htmlspecialchars($activity['media_url'], ENT_QUOTES, 'UTF-8') ?>">

    <!-- Settings -->
    <div class="fb-section">
        <div class="fb-section-header"><span>📝</span><h3>Activity settings</h3></div>
        <div class="fb-section-body">
            <div class="fb-field">
                <label class="fb-label">Instructions</label>
                <input class="fb-input" type="text" name="instructions"
                    value="<?= htmlspecialchars($activity['instructions'], ENT_QUOTES, 'UTF-8') ?>"
                    placeholder="Write the missing words in the blanks." required>
            </div>
            <div class="fb-field">
                <label class="fb-label">Word bank <small>(optional — leave blank to hide)</small></label>
                <input class="fb-input" type="text" name="wordbank"
                    value="<?= htmlspecialchars($activity['wordbank'], ENT_QUOTES, 'UTF-8') ?>"
                    placeholder="word1, word2, word3">
            </div>
        </div>
    </div>

    <!-- Media -->
    <div class="fb-section">
        <div class="fb-section-header"><span>🎵</span><h3>Media</h3></div>
        <div class="fb-section-body">
            <div class="fb-field">
                <label class="fb-label">Media type</label>
                <select class="fb-select" name="media_type" id="fb-media-type" onchange="fbToggleMedia(this.value)">
                    <option value="none" <?= $activity['media_type']==='none'  ? 'selected' : '' ?>>— No media</option>
                    <option value="tts"  <?= $activity['media_type']==='tts'   ? 'selected' : '' ?>>🔊 Text-to-Speech (TTS)</option>
                    <option value="audio"<?= $activity['media_type']==='audio' ? 'selected' : '' ?>>🎵 Audio file upload</option>
                </select>
            </div>

            <div id="fb-panel-tts" class="fb-media-panel <?= $activity['media_type']==='tts' ? 'active' : '' ?>">
                <div class="fb-field">
                    <label class="fb-label">TTS text <small>(students listen while filling blanks)</small></label>
                    <textarea class="fb-textarea" name="tts_text"
                        placeholder="Paste the full text here for TTS playback…"
                    ><?= htmlspecialchars($activity['tts_text'], ENT_QUOTES, 'UTF-8') ?></textarea>
                    <p class="fb-help">Leave blank to auto-read all block texts in order.</p>
                </div>
            </div>

            <div id="fb-panel-audio" class="fb-media-panel <?= $activity['media_type']==='audio' ? 'active' : '' ?>">
                <div class="fb-field">
                    <label class="fb-label">Audio URL</label>
                    <input class="fb-input" type="text" name="media_url"
                        value="<?= $activity['media_type']==='audio' ? htmlspecialchars($activity['media_url'], ENT_QUOTES, 'UTF-8') : '' ?>"
                        placeholder="https://...">
                </div>
                <div class="fb-field">
                    <label class="fb-label">Or upload audio file</label>
                    <input type="file" name="media_file" accept="audio/*" style="font-size:13px;font-family:'Nunito',sans-serif">
                    <?php if ($activity['media_type']==='audio' && !empty($activity['media_url'])): ?>
                        <p class="fb-help">Current: <a href="<?= htmlspecialchars($activity['media_url'], ENT_QUOTES, 'UTF-8') ?>" target="_blank" style="color:var(--t600)">Listen</a></p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Blocks -->
    <div class="fb-section">
        <div class="fb-section-header"><span>🗂️</span>
            <h3>Blocks — use <code style="background:var(--t50);padding:1px 6px;border-radius:4px;font-size:13px;color:var(--t600)">___</code> for each blank</h3>
        </div>
        <div class="fb-section-body">
            <p class="fb-help" style="margin-bottom:12px">
                Each block = one screen. Count of <code>___</code> must equal count of <code>|</code>-separated answers.
            </p>
            <div id="fb-blocks-list"></div>
            <div class="fb-toolbar">
                <button type="button" class="fb-btn-add" onclick="fbAddBlock()">+ Add Block</button>
            </div>
        </div>
    </div>

    <div class="fb-save-row">
        <button type="submit" class="fb-btn-save">💾 Save Activity</button>
    </div>

</form>

<script>
var FB_BLOCKS = <?= json_encode($activity['blocks'], JSON_UNESCAPED_UNICODE) ?>;

function fbToggleMedia(val) {
    ['tts','audio'].forEach(function(id) {
        var el = document.getElementById('fb-panel-' + id);
        if (el) el.classList.toggle('active', id === val);
    });
}

function fbRenderBlocks() {
    var container = document.getElementById('fb-blocks-list');
    container.innerHTML = '';
    if (FB_BLOCKS.length === 0) { fbAddBlock(); return; }
    FB_BLOCKS.forEach(function(block, idx) {
        var div = document.createElement('div');
        div.className = 'fb-block-item';
        div.innerHTML =
            '<div class="fb-block-num">Block ' + (idx + 1) + '</div>' +

            '<div class="fb-field">' +
                '<label class="fb-label">Sentence / paragraph <small>— use ___ for each blank</small></label>' +
                '<textarea class="fb-textarea" name="text[]" required placeholder="The dog ___ in the park every ___.">' +
                    (block.text ? block.text.replace(/</g,'&lt;').replace(/>/g,'&gt;') : '') +
                '</textarea>' +
            '</div>' +

            '<div class="fb-field">' +
                '<label class="fb-label">Answers <small>— comma separated, same order as blanks</small></label>' +
                '<input class="fb-input" type="text" name="answers[]"' +
                    ' value="' + (block.answers ? block.answers.join(' | ') : '') + '"' +
                    ' required placeholder="e.g. raining cats and dogs | watched me like a hawk | red herring">' +
            '</div>' +

            '<div class="fb-field">' +
                '<label class="fb-label">Image URL <small>(optional)</small></label>' +
                '<input class="fb-input" type="text" name="image_url[]"' +
                    ' value="' + (block.image || '') + '"' +
                    ' placeholder="https://...">' +
            '</div>' +

            '<div class="fb-field">' +
                '<label class="fb-label">Or upload image</label>' +
                '<input type="file" name="image_upload[]" accept="image/*"' +
                    ' style="font-size:13px;font-family:\'Nunito\',sans-serif">' +
                (block.image ? '<img class="fb-thumb" src="' + block.image + '" alt="">' : '') +
            '</div>' +

            '<button type="button" class="fb-btn-remove" onclick="fbRemoveBlock(this)">✖ Remove block</button>';
        container.appendChild(div);
    });
}

function fbAddBlock() {
    FB_BLOCKS.push({ text: '', answers: [], image: '' });
    fbRenderBlocks();
    var cards = document.querySelectorAll('.fb-block-item');
    if (cards.length) cards[cards.length-1].scrollIntoView({ behavior:'smooth', block:'start' });
    var tas = document.querySelectorAll('.fb-block-item textarea');
    if (tas.length) tas[tas.length-1].focus();
}

function fbRemoveBlock(btn) {
    var idx = Array.from(document.querySelectorAll('.fb-block-item')).indexOf(btn.closest('.fb-block-item'));
    if (idx > -1) FB_BLOCKS.splice(idx, 1);
    fbRenderBlocks();
}

/* Validate blank count == answer count */
document.getElementById('fbEditorForm').addEventListener('submit', function(e) {
    var cards = document.querySelectorAll('.fb-block-item');
    for (var i = 0; i < cards.length; i++) {
        var text    = cards[i].querySelector('textarea').value;
        var rawAns = cards[i].querySelector('input[name="answers[]"]').value;
        var answers = rawAns
                        .indexOf('|') !== -1
                            ? rawAns.split('|').map(function(s){ return s.trim(); }).filter(Boolean)
                            : rawAns.split(',').map(function(s){ return s.trim(); }).filter(Boolean);
        var blanks  = (text.match(/___/g) || []).length;
        if (blanks !== answers.length) {
            alert('Block ' + (i+1) + ': ' + blanks + ' blank(s) but ' + answers.length + ' answer(s). Please fix before saving.');
            e.preventDefault();
            cards[i].querySelector('textarea').focus();
            return false;
        }
    }
});

document.addEventListener('DOMContentLoaded', fbRenderBlocks);
</script>

<?php
$content = ob_get_clean();
render_activity_editor('Fill-in-the-Blank Editor', 'fa-solid fa-pen-to-square', $content);
