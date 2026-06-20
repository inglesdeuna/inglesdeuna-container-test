<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../core/_activity_editor_template.php';
require_once __DIR__ . '/../../core/cloudinary_upload.php';

if (!empty($_SESSION['student_logged'])) {
    header('Location: /lessons/lessons/academic/student_dashboard.php?error=access_denied');
    exit;
}
$isLoggedIn = !empty($_SESSION['academic_logged']) || !empty($_SESSION['admin_logged']);
if (!$isLoggedIn) {
    header('Location: /lessons/lessons/academic/login.php');
    exit;
}

$activityId = isset($_GET['id'])         ? trim((string) $_GET['id'])         : '';
$unit       = isset($_GET['unit'])       ? trim((string) $_GET['unit'])       : '';
$source     = isset($_GET['source'])     ? trim((string) $_GET['source'])     : '';
$assignment = isset($_GET['assignment']) ? trim((string) $_GET['assignment']) : '';

const USK_ALLOWED_VOICES = [
    'Nggzl2QAXh3OijoXD116', // Child (Candy)
    'nzFihrBIvB34imQBuxub', // Adult Male (Josh)
    'NoOVOzCQFLOvtsMoNcdT', // Adult Female (Lily)
];
const USK_DEFAULT_VOICE  = 'Nggzl2QAXh3OijoXD116';
const USK_DEFAULT_TITLE  = 'Spell the Word';

/* ── helpers ─────────────────────────────────────────────────────────── */
function usk_ed_resolve_unit(PDO $pdo, string $id): string
{
    if ($id === '') return '';
    $st = $pdo->prepare("SELECT unit_id FROM activities WHERE id = :id LIMIT 1");
    $st->execute(['id' => $id]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r && isset($r['unit_id']) ? (string) $r['unit_id'] : '';
}

function usk_ed_default(): array
{
    return ['title' => USK_DEFAULT_TITLE, 'voice_id' => USK_DEFAULT_VOICE, 'words' => []];
}

function usk_ed_norm($raw): array
{
    $df = usk_ed_default();
    if ($raw === null || $raw === '') return $df;
    $d = is_string($raw) ? json_decode($raw, true) : $raw;
    if (!is_array($d)) return $df;

    $words = [];
    foreach (($d['words'] ?? []) as $it) {
        if (!is_array($it)) continue;
        $w = strtoupper(preg_replace('/[^A-Za-z]/', '', trim((string) ($it['word'] ?? ''))));
        if ($w === '') continue;
        $words[] = [
            'id'       => trim((string) ($it['id'] ?? uniqid('usk_', true))),
            'word'     => $w,
            'emoji'    => trim((string) ($it['emoji']    ?? '')),
            'hint'     => trim((string) ($it['hint']     ?? '')),
            'image'    => trim((string) ($it['image']    ?? '')),
            'audio'    => trim((string) ($it['audio']    ?? '')),
            'voice_id' => trim((string) ($it['voice_id'] ?? '')),
        ];
    }

    $title = trim((string) ($d['title']    ?? ''));
    $void  = trim((string) ($d['voice_id'] ?? USK_DEFAULT_VOICE));
    if (!in_array($void, USK_ALLOWED_VOICES, true)) $void = USK_DEFAULT_VOICE;

    return [
        'title'    => $title !== '' ? $title : USK_DEFAULT_TITLE,
        'voice_id' => $void,
        'words'    => $words,
    ];
}

function usk_ed_encode(array $p): string
{
    $title = trim((string) ($p['title'] ?? '')) ?: USK_DEFAULT_TITLE;
    $void  = trim((string) ($p['voice_id'] ?? USK_DEFAULT_VOICE));
    if (!in_array($void, USK_ALLOWED_VOICES, true)) $void = USK_DEFAULT_VOICE;
    return json_encode([
        'title'    => $title,
        'voice_id' => $void,
        'words'    => array_values($p['words'] ?? []),
    ], JSON_UNESCAPED_UNICODE);
}

function usk_ed_load(PDO $pdo, string $unit, string $id): array
{
    $fb  = ['id' => ''] + usk_ed_default();
    $row = null;

    if ($id !== '') {
        $st = $pdo->prepare("SELECT id, data FROM activities WHERE id = :id AND type = 'unscramble_kids' LIMIT 1");
        $st->execute(['id' => $id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
    }
    if (!$row && $unit !== '') {
        $st = $pdo->prepare("SELECT id, data FROM activities WHERE unit_id = :u AND type = 'unscramble_kids' ORDER BY id ASC LIMIT 1");
        $st->execute(['u' => $unit]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
    }
    if (!$row) return $fb;

    $p = usk_ed_norm($row['data'] ?? null);
    return [
        'id'       => (string) ($row['id'] ?? ''),
        'title'    => $p['title'],
        'voice_id' => $p['voice_id'],
        'words'    => $p['words'],
    ];
}

function usk_ed_save(PDO $pdo, string $unit, string $id, string $title, string $void, array $words): string
{
    $json = usk_ed_encode(['title' => $title, 'voice_id' => $void, 'words' => $words]);

    $tid = $id;
    if ($tid === '') {
        $st = $pdo->prepare("SELECT id FROM activities WHERE unit_id = :u AND type = 'unscramble_kids' ORDER BY id ASC LIMIT 1");
        $st->execute(['u' => $unit]);
        $tid = trim((string) $st->fetchColumn());
    }

    if ($tid !== '') {
        $st = $pdo->prepare("UPDATE activities SET data = :data WHERE id = :id AND type = 'unscramble_kids'");
        $st->execute(['data' => $json, 'id' => $tid]);
        return $tid;
    }

    $st = $pdo->prepare(
        "INSERT INTO activities (unit_id, type, data, position, created_at)
         VALUES (:u, 'unscramble_kids', :d,
                 (SELECT COALESCE(MAX(position), 0) + 1 FROM activities WHERE unit_id = :u2),
                 CURRENT_TIMESTAMP)
         RETURNING id"
    );
    $st->execute(['u' => $unit, 'u2' => $unit, 'd' => $json]);
    return (string) $st->fetchColumn();
}

/* helper: safely fetch a value from a per-item file array */
function usk_file_at(array $files, string $key, int $i): ?array
{
    if (!isset($files[$key]) || !is_array($files[$key]['name'] ?? null)) return null;
    if (!isset($files[$key]['name'][$i])) return null;
    return [
        'name'     => $files[$key]['name'][$i]     ?? '',
        'type'     => $files[$key]['type'][$i]     ?? '',
        'tmp_name' => $files[$key]['tmp_name'][$i] ?? '',
        'error'    => $files[$key]['error'][$i]    ?? UPLOAD_ERR_NO_FILE,
        'size'     => $files[$key]['size'][$i]     ?? 0,
    ];
}

/* ── resolve unit + load ─────────────────────────────────────────────── */
if ($unit === '' && $activityId !== '') {
    $unit = usk_ed_resolve_unit($pdo, $activityId);
}
if ($unit === '') die('Unit not specified');

$activity      = usk_ed_load($pdo, $unit, $activityId);
$activityTitle = $activity['title'];
$activityVoice = $activity['voice_id'];
$words         = $activity['words'];
if ($activityId === '' && !empty($activity['id'])) $activityId = $activity['id'];

/* ── POST: save ──────────────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedTitle = trim((string) ($_POST['activity_title'] ?? ''));
    $postedVoiceRaw = trim((string) ($_POST['voice_id'] ?? ''));
    $postedVoice = in_array($postedVoiceRaw, USK_ALLOWED_VOICES, true) ? $postedVoiceRaw : USK_DEFAULT_VOICE;

    $ids      = is_array($_POST['word_id']           ?? null) ? $_POST['word_id']           : [];
    $wt       = is_array($_POST['word']              ?? null) ? $_POST['word']              : [];
    $em       = is_array($_POST['emoji']             ?? null) ? $_POST['emoji']             : [];
    $hi       = is_array($_POST['hint']              ?? null) ? $_POST['hint']              : [];
    $imgExist = is_array($_POST['image_existing']    ?? null) ? $_POST['image_existing']    : [];
    $auExist  = is_array($_POST['audio_existing']    ?? null) ? $_POST['audio_existing']    : [];
    $vd       = is_array($_POST['item_voice_id']     ?? null) ? $_POST['item_voice_id']     : [];

    $san = [];
    foreach ($wt as $i => $rw) {
        $w = strtoupper(preg_replace('/[^A-Za-z]/', '', trim((string) $rw)));
        if ($w === '') continue;

        $itemVoice = trim((string) ($vd[$i] ?? ''));
        if ($itemVoice !== '' && !in_array($itemVoice, USK_ALLOWED_VOICES, true)) $itemVoice = '';

        $imageUrl = trim((string) ($imgExist[$i] ?? ''));
        $imgFile  = usk_file_at($_FILES, 'image_file', $i);
        if ($imgFile && $imgFile['error'] === UPLOAD_ERR_OK && $imgFile['tmp_name'] !== '') {
            $up = upload_to_cloudinary($imgFile['tmp_name']);
            if ($up) $imageUrl = $up;
        }

        $audioUrl = trim((string) ($auExist[$i] ?? ''));
        $auFile   = usk_file_at($_FILES, 'audio_file', $i);
        if ($auFile && $auFile['error'] === UPLOAD_ERR_OK && $auFile['tmp_name'] !== '') {
            $up = upload_audio_to_cloudinary($auFile['tmp_name']);
            if ($up) $audioUrl = $up;
        }

        $san[] = [
            'id'       => trim((string) ($ids[$i] ?? '')) ?: uniqid('usk_', true),
            'word'     => $w,
            'emoji'    => trim((string) ($em[$i] ?? '')),
            'hint'     => trim((string) ($hi[$i] ?? '')),
            'image'    => $imageUrl,
            'audio'    => $audioUrl,
            'voice_id' => $itemVoice,
        ];
    }

    $sid = usk_ed_save($pdo, $unit, $activityId, $postedTitle, $postedVoice, $san);

    $pr = ['unit=' . urlencode($unit), 'saved=1'];
    if ($sid !== '')        $pr[] = 'id='         . urlencode($sid);
    if ($assignment !== '') $pr[] = 'assignment=' . urlencode($assignment);
    if ($source !== '')     $pr[] = 'source='     . urlencode($source);
    header('Location: editor.php?' . implode('&', $pr));
    exit;
}

/* ── render ──────────────────────────────────────────────────────────── */
ob_start();
if (isset($_GET['saved'])) {
    echo '<p style="color:#16a34a;font-weight:700;margin-bottom:15px">✔ Saved successfully</p>';
}
?>
<link href="https://fonts.googleapis.com/css2?family=Fredoka:wght@500;600;700&family=Nunito:wght@600;700;800;900&display=swap" rel="stylesheet">
<style>
.usk-form{max-width:880px;margin:0 auto;font-family:'Nunito','Segoe UI',sans-serif}
.usk-title-box,.usk-word-item{background:#f9fafb;padding:18px;margin-bottom:14px;border-radius:14px;border:1px solid #e5e7eb}
.usk-title-box label,.usk-word-item label{display:block;font-weight:800;margin-bottom:6px;font-size:12px;color:#374151;text-transform:uppercase;letter-spacing:.04em}
.usk-title-box input,.usk-title-box select,.usk-word-item input[type=text],.usk-word-item textarea,.usk-word-item select{width:100%;padding:10px 12px;border-radius:8px;border:1px solid #d1d5db;box-sizing:border-box;margin-bottom:12px;font-size:14px;font-family:inherit;background:#fff}
.usk-word-item .usk-row2{display:grid;grid-template-columns:1fr 1fr;gap:12px}
.usk-word-item .usk-row3{display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px}
.usk-word-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:12px}
.usk-word-num{font-family:'Fredoka',sans-serif;font-size:17px;font-weight:600;color:#7F77DD}
.usk-toolbar{display:flex;gap:10px;justify-content:center;margin-top:14px;flex-wrap:wrap}
.usk-btn-add{background:#16a34a;color:#fff;padding:11px 16px;border:none;border-radius:9px;cursor:pointer;font-weight:800;font-family:inherit}
.usk-btn-remove{background:#ef4444;color:#fff;border:none;padding:7px 12px;border-radius:8px;cursor:pointer;font-weight:700;font-family:inherit}
.usk-btn-save{background:linear-gradient(180deg,#7c3aed,#6d28d9);color:#fff;padding:11px 26px;border:none;border-radius:10px;cursor:pointer;font-weight:800;font-size:15px;font-family:inherit}
.usk-btn-tts{display:inline-flex;align-items:center;gap:6px;background:#F97316;color:#fff;border:none;padding:8px 12px;border-radius:8px;cursor:pointer;font-weight:700;font-size:12px;font-family:inherit;margin-bottom:8px}
.usk-btn-tts:disabled{opacity:.6;cursor:not-allowed}
.usk-file-row{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:10px}
.usk-file-row input[type=file]{flex:1;min-width:180px;padding:7px;border:1px dashed #cbd5e1;border-radius:8px;background:#fff;font-size:12px}
.usk-existing{font-size:11px;color:#6b7280;word-break:break-all;display:none;margin-bottom:6px}
.usk-existing.has{display:block;color:#16a34a}
.usk-thumb{display:none;width:64px;height:64px;border-radius:10px;object-fit:cover;border:1px solid #e5e7eb;background:#fff}
.usk-thumb.has{display:inline-block}
.usk-empty{text-align:center;color:#9ca3af;font-size:13px;padding:20px}
</style>

<form method="post" enctype="multipart/form-data" class="usk-form" id="uskEdForm">

    <div class="usk-title-box">
        <label for="usk_activity_title">Activity title</label>
        <input id="usk_activity_title" type="text" name="activity_title"
               value="<?= htmlspecialchars($activityTitle, ENT_QUOTES, 'UTF-8') ?>"
               placeholder="e.g. Spell the Animals" required>

        <label for="usk_voice_id">Default voice for students</label>
        <select id="usk_voice_id" name="voice_id">
            <option value="Nggzl2QAXh3OijoXD116"<?= $activityVoice === 'Nggzl2QAXh3OijoXD116' ? ' selected' : '' ?>>Child (Candy) — recommended</option>
            <option value="nzFihrBIvB34imQBuxub"<?= $activityVoice === 'nzFihrBIvB34imQBuxub' ? ' selected' : '' ?>>Adult Male (Josh)</option>
            <option value="NoOVOzCQFLOvtsMoNcdT"<?= $activityVoice === 'NoOVOzCQFLOvtsMoNcdT' ? ' selected' : '' ?>>Adult Female (Lily)</option>
        </select>
    </div>

    <div id="uskWordsContainer">
    <?php if (empty($words)): ?>
        <div class="usk-empty" id="uskEmptyMsg">No words yet. Click <strong>+ Add Word</strong> to start.</div>
    <?php else: foreach ($words as $idx => $item): ?>
        <div class="usk-word-item">
            <input type="hidden" name="word_id[]" value="<?= htmlspecialchars((string) ($item['id'] ?? uniqid('usk_', true)), ENT_QUOTES, 'UTF-8') ?>">

            <div class="usk-word-header">
                <span class="usk-word-num">Word <?= $idx + 1 ?></span>
                <button type="button" class="usk-btn-remove" onclick="uskRemoveWord(this)">✖ Remove</button>
            </div>

            <div class="usk-row2">
                <div>
                    <label>Word</label>
                    <input type="text" name="word[]"
                           value="<?= htmlspecialchars((string) ($item['word'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                           placeholder="CAT"
                           style="text-transform:uppercase;font-family:'Fredoka',sans-serif;font-size:18px;font-weight:600"
                           required>
                </div>
                <div>
                    <label>Emoji (picture clue)</label>
                    <input type="text" name="emoji[]"
                           value="<?= htmlspecialchars((string) ($item['emoji'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                           placeholder="🐱" style="font-size:22px">
                </div>
            </div>

            <label>Hint text (shown below picture)</label>
            <input type="text" name="hint[]"
                   value="<?= htmlspecialchars((string) ($item['hint'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                   placeholder="A pet that meows">

            <label>Picture (optional, overrides emoji)</label>
            <div class="usk-file-row">
                <input type="file" name="image_file[]" accept="image/*">
                <img class="usk-thumb<?= !empty($item['image']) ? ' has' : '' ?>"
                     src="<?= htmlspecialchars((string) ($item['image'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                     alt="">
            </div>
            <input type="hidden" name="image_existing[]" value="<?= htmlspecialchars((string) ($item['image'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
            <div class="usk-existing<?= !empty($item['image']) ? ' has' : '' ?>">
                <?= !empty($item['image']) ? '✔ ' . htmlspecialchars((string) $item['image'], ENT_QUOTES, 'UTF-8') : '' ?>
            </div>

            <label>Audio (optional — overrides TTS)</label>
            <div class="usk-file-row">
                <input type="file" name="audio_file[]" accept="audio/*">
                <button type="button" class="usk-btn-tts" onclick="uskGenerateAudio(this)">🔊 Preview TTS</button>
            </div>
            <input type="hidden" name="audio_existing[]" value="<?= htmlspecialchars((string) ($item['audio'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
            <div class="usk-existing<?= !empty($item['audio']) ? ' has' : '' ?>">
                <?= !empty($item['audio']) ? '✔ ' . htmlspecialchars((string) $item['audio'], ENT_QUOTES, 'UTF-8') : '' ?>
            </div>

            <input type="hidden" name="item_voice_id[]" value="<?= htmlspecialchars((string) ($item['voice_id'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
        </div>
    <?php endforeach; endif; ?>
    </div>

    <div class="usk-toolbar">
        <button type="button" class="usk-btn-add"  onclick="uskAddWord()">+ Add Word</button>
        <button type="submit" class="usk-btn-save">💾 Save</button>
    </div>
</form>

<audio id="uskPreviewAudio" preload="none"></audio>

<script>
let uskFormChanged = false;
let uskFormSubmit  = false;

function uskMarkChanged(){ uskFormChanged = true; }

function uskRemoveWord(btn){
    const item = btn.closest('.usk-word-item');
    if (!item) return;
    item.remove();
    uskMarkChanged();
    uskRenumber();
    uskUpdateEmpty();
}

function uskRenumber(){
    document.querySelectorAll('.usk-word-item .usk-word-num').forEach((el, i) => {
        el.textContent = 'Word ' + (i + 1);
    });
}

function uskUpdateEmpty(){
    const empty = document.getElementById('uskEmptyMsg');
    const has   = document.querySelectorAll('.usk-word-item').length > 0;
    if (empty) empty.style.display = has ? 'none' : '';
}

function uskAddWord(){
    const id  = 'usk_' + Date.now() + '_' + Math.floor(Math.random() * 1000);
    const div = document.createElement('div');
    div.className = 'usk-word-item';
    div.innerHTML = `
        <input type="hidden" name="word_id[]" value="${id}">
        <div class="usk-word-header">
            <span class="usk-word-num">Word ${document.querySelectorAll('.usk-word-item').length + 1}</span>
            <button type="button" class="usk-btn-remove" onclick="uskRemoveWord(this)">✖ Remove</button>
        </div>
        <div class="usk-row2">
            <div>
                <label>Word</label>
                <input type="text" name="word[]" placeholder="DOG"
                       style="text-transform:uppercase;font-family:'Fredoka',sans-serif;font-size:18px;font-weight:600" required>
            </div>
            <div>
                <label>Emoji (picture clue)</label>
                <input type="text" name="emoji[]" placeholder="🐶" style="font-size:22px">
            </div>
        </div>
        <label>Hint text (shown below picture)</label>
        <input type="text" name="hint[]" placeholder="A pet that barks">

        <label>Picture (optional, overrides emoji)</label>
        <div class="usk-file-row">
            <input type="file" name="image_file[]" accept="image/*">
            <img class="usk-thumb" src="" alt="">
        </div>
        <input type="hidden" name="image_existing[]" value="">
        <div class="usk-existing"></div>

        <label>Audio (optional — overrides TTS)</label>
        <div class="usk-file-row">
            <input type="file" name="audio_file[]" accept="audio/*">
            <button type="button" class="usk-btn-tts" onclick="uskGenerateAudio(this)">🔊 Preview TTS</button>
        </div>
        <input type="hidden" name="audio_existing[]" value="">
        <div class="usk-existing"></div>

        <input type="hidden" name="item_voice_id[]" value="">
    `;
    document.getElementById('uskWordsContainer').appendChild(div);
    uskBindChange(div);
    uskMarkChanged();
    uskRenumber();
    uskUpdateEmpty();
}

function uskGenerateAudio(btn){
    const item = btn.closest('.usk-word-item');
    if (!item) return;
    const wordEl = item.querySelector('input[name="word[]"]');
    const w      = (wordEl ? wordEl.value : '').trim().toLowerCase();
    if (!w) { alert('Enter the word first'); return; }

    const voiceSel = document.getElementById('usk_voice_id');
    const voiceId  = voiceSel ? voiceSel.value : 'Nggzl2QAXh3OijoXD116';

    btn.disabled = true;
    const orig = btn.textContent;
    btn.textContent = '⏳';

    const fd = new FormData();
    fd.append('text', w);
    fd.append('voice_id', voiceId);

    fetch('tts.php', { method:'POST', body:fd, credentials:'same-origin' })
        .then(r => { if (!r.ok) throw new Error('TTS ' + r.status); return r.blob(); })
        .then(blob => {
            const a = document.getElementById('uskPreviewAudio');
            a.src = URL.createObjectURL(blob);
            return a.play();
        })
        .catch(e => alert('Could not preview audio: ' + e.message))
        .finally(() => { btn.disabled = false; btn.textContent = orig; });
}

function uskBindChange(scope){
    scope.querySelectorAll('input,textarea,select').forEach(el => {
        el.addEventListener('input',  uskMarkChanged);
        el.addEventListener('change', uskMarkChanged);
    });

    /* preview thumbnail when an image file is picked */
    scope.querySelectorAll('input[type="file"][name="image_file[]"]').forEach(input => {
        input.addEventListener('change', e => {
            const file = e.target.files && e.target.files[0];
            const thumb = e.target.closest('.usk-file-row').querySelector('.usk-thumb');
            if (!thumb) return;
            if (file) {
                thumb.src = URL.createObjectURL(file);
                thumb.classList.add('has');
            }
        });
    });
}

document.addEventListener('DOMContentLoaded', () => {
    uskBindChange(document);
    uskUpdateEmpty();
    const f = document.getElementById('uskEdForm');
    if (f) f.addEventListener('submit', () => { uskFormSubmit = true; uskFormChanged = false; });
});

window.addEventListener('beforeunload', e => {
    if (uskFormChanged && !uskFormSubmit) { e.preventDefault(); e.returnValue = ''; }
});
</script>
<?php
$content = ob_get_clean();
render_activity_editor('🔤 Unscramble Kids Editor', 'fa-solid fa-spell-check', $content);
