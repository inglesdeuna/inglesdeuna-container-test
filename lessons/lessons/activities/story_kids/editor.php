<?php
if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../core/_activity_editor_template.php';
require_once __DIR__ . '/../../core/cloudinary_upload.php';

if (isset($_SESSION['student_logged']) && $_SESSION['student_logged']) {
    header('Location: /lessons/lessons/academic/student_dashboard.php?error=access_denied');
    exit;
}
$isLoggedIn = !empty($_SESSION['academic_logged']) || !empty($_SESSION['admin_logged']);
if (!$isLoggedIn) {
    header('Location: /lessons/lessons/academic/login.php');
    exit;
}

function h(string $v): string { return htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); }

$activityId = isset($_GET['id'])         ? trim((string) $_GET['id'])         : '';
$unit       = isset($_GET['unit'])       ? trim((string) $_GET['unit'])       : '';
$source     = isset($_GET['source'])     ? trim((string) $_GET['source'])     : '';
$assignment = isset($_GET['assignment']) ? trim((string) $_GET['assignment']) : '';

if ($unit === '' && $activityId !== '') {
    $stmt = $pdo->prepare('SELECT unit_id FROM activities WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $activityId]);
    $row  = $stmt->fetch(PDO::FETCH_ASSOC);
    $unit = $row ? (string)($row['unit_id'] ?? '') : '';
}
if ($unit === '') die('Unit not specified');

function stke_normalize($raw): array
{
    $default = ['title' => 'My Story', 'pages' => []];
    if ($raw === null || $raw === '') return $default;
    $d = is_string($raw) ? json_decode($raw, true) : $raw;
    if (!is_array($d)) return $default;
    $pages = [];
    foreach ($d['pages'] ?? [] as $p) {
        if (!is_array($p)) continue;
        $kw = $p['keywords'] ?? [];
        if (!is_array($kw)) $kw = [];
        $wt = $p['word_timings'] ?? [];
        if (!is_array($wt)) $wt = [];
        $pages[] = [
            'id'          => (int)($p['id'] ?? 0),
            'image_url'   => trim((string)($p['image_url'] ?? '')),
            'text'        => trim((string)($p['text'] ?? '')),
            'audio_url'   => trim((string)($p['audio_url'] ?? '')),
            'keywords'    => array_values(array_filter(array_map('trim', array_map('strval', $kw)))),
            'word_timings'=> $wt,
        ];
    }
    return [
        'title' => trim((string)($d['title'] ?? '')) ?: 'My Story',
        'pages' => $pages,
    ];
}

$error = '';
$saved = false;

// Handle AJAX image upload — must exit before any HTML
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    if ($_POST['action'] === 'upload_image') {
        if (empty($_FILES['image']['tmp_name'])) {
            echo json_encode(['error' => 'No file received']); exit;
        }
        $url = upload_to_cloudinary($_FILES['image']['tmp_name']);
        echo ($url !== null) ? json_encode(['url' => $url]) : json_encode(['error' => 'Upload failed']);
        exit;
    }
    if ($_POST['action'] === 'upload_audio') {
        if (empty($_FILES['audio']['tmp_name'])) {
            echo json_encode(['error' => 'No file received']); exit;
        }
        $url = upload_audio_to_cloudinary($_FILES['audio']['tmp_name']);
        echo ($url !== null) ? json_encode(['url' => $url]) : json_encode(['error' => 'Upload failed']);
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['action'])) {
    $title     = trim($_POST['title'] ?? '');
    $pagesJson = $_POST['pages_json'] ?? '[]';
    $pages     = json_decode($pagesJson, true);
    if (!is_array($pages)) $pages = [];

    $cleanPages = [];
    $idx = 1;
    foreach ($pages as $p) {
        if (!is_array($p)) continue;
        $imgUrl   = trim((string)($p['image_url'] ?? ''));
        $text     = trim((string)($p['text'] ?? ''));
        $audioUrl = trim((string)($p['audio_url'] ?? ''));
        if ($imgUrl === '' && $text === '') continue;
        $kw = $p['keywords'] ?? [];
        if (!is_array($kw)) $kw = [];
        $wt = $p['word_timings'] ?? [];
        if (!is_array($wt)) $wt = [];
        $cleanPages[] = [
            'id'          => $idx++,
            'image_url'   => $imgUrl,
            'text'        => $text,
            'audio_url'   => $audioUrl,
            'keywords'    => array_values(array_filter(array_map('trim', array_map('strval', $kw)))),
            'word_timings'=> $wt,
        ];
    }

    if (count($cleanPages) === 0) {
        $error = 'Add at least one page with an image or text.';
    } else {
        $payload = json_encode([
            'title' => $title !== '' ? $title : 'My Story',
            'pages' => $cleanPages,
        ], JSON_UNESCAPED_UNICODE);

        if ($activityId !== '') {
            $stmt = $pdo->prepare("UPDATE activities SET data = :data WHERE id = :id AND type = 'story_kids'");
            $stmt->execute(['data' => $payload, 'id' => $activityId]);
        } else {
            $stmt = $pdo->prepare("INSERT INTO activities (unit_id, type, data) VALUES (:unit, 'story_kids', :data)");
            $stmt->execute(['unit' => $unit, 'data' => $payload]);
            $activityId = (string) $pdo->lastInsertId();
        }
        $saved = true;
    }
}

$activity = ['title' => 'My Story', 'pages' => []];
if ($activityId !== '') {
    $stmt = $pdo->prepare("SELECT data FROM activities WHERE id = :id AND type = 'story_kids' LIMIT 1");
    $stmt->execute(['id' => $activityId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $activity = stke_normalize($row['data'] ?? null);
    }
}

ob_start();
?>
<style>
@import url('https://fonts.googleapis.com/css2?family=Fredoka:wght@500;600;700&family=Nunito:wght@500;700;800&display=swap');

.stke-wrap {
    max-width: 860px;
    margin: 0 auto;
    font-family: 'Nunito', sans-serif;
}
.stke-title-row {
    background: #fff;
    border: 1px solid #EDE9FA;
    border-radius: 16px;
    padding: 18px 20px;
    box-shadow: 0 4px 14px rgba(127,119,221,.08);
    margin-bottom: 20px;
}
.stke-label {
    display: block;
    font-size: 11px;
    font-weight: 800;
    color: #7F77DD;
    text-transform: uppercase;
    letter-spacing: .05em;
    margin-bottom: 6px;
}
.stke-input {
    width: 100%;
    border: 1.5px solid #EDE9FA;
    border-radius: 10px;
    padding: 10px 14px;
    font-family: 'Fredoka', sans-serif;
    font-size: 18px;
    font-weight: 600;
    color: #1e1b4b;
    box-sizing: border-box;
    transition: border-color .15s;
}
.stke-input:focus { outline: none; border-color: #7F77DD; }

/* Pages list */
#stkePageList { display: flex; flex-direction: column; gap: 16px; margin-bottom: 20px; }

.stke-page-card {
    background: #fff;
    border: 1.5px solid #EDE9FA;
    border-radius: 20px;
    padding: 18px 20px;
    box-shadow: 0 4px 14px rgba(127,119,221,.08);
    position: relative;
}
.stke-page-header {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 14px;
}
.stke-page-num {
    width: 30px; height: 30px;
    border-radius: 50%;
    background: linear-gradient(135deg, #F97316, #fb923c);
    color: #fff;
    font-family: 'Fredoka', sans-serif;
    font-size: 15px;
    font-weight: 700;
    display: flex; align-items: center; justify-content: center;
    flex-shrink: 0;
}
.stke-page-title {
    font-family: 'Fredoka', sans-serif;
    font-size: 16px;
    font-weight: 600;
    color: #1e1b4b;
    flex: 1;
}
.stke-del-page {
    background: none;
    border: none;
    color: #ef4444;
    font-size: 20px;
    cursor: pointer;
    padding: 2px 6px;
    border-radius: 6px;
    transition: background .15s;
    line-height: 1;
}
.stke-del-page:hover { background: #fee2e2; }

.stke-page-body {
    display: grid;
    grid-template-columns: 200px 1fr;
    gap: 16px;
    align-items: start;
}
@media (max-width: 600px) {
    .stke-page-body { grid-template-columns: 1fr; }
}

.stke-img-box {
    border: 2px dashed #EDE9FA;
    border-radius: 14px;
    overflow: hidden;
    background: #f8f7fe;
    min-height: 130px;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    transition: border-color .15s;
    position: relative;
}
.stke-img-box:hover { border-color: #7F77DD; }
.stke-img-box.has-img { border-style: solid; border-color: #7F77DD; }
.stke-img-box img {
    width: 100%; height: 100%;
    object-fit: cover;
    border-radius: 12px;
    display: block;
}
.stke-img-placeholder {
    text-align: center;
    color: #a09acc;
    font-size: 13px;
    font-weight: 600;
    padding: 16px;
    pointer-events: none;
}
.stke-img-placeholder span { display: block; font-size: 28px; margin-bottom: 4px; }
.stke-uploading {
    position: absolute;
    inset: 0;
    background: rgba(255,255,255,.85);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 12px;
    font-weight: 700;
    color: #7F77DD;
    border-radius: 12px;
}

.stke-right-col { display: flex; flex-direction: column; gap: 10px; }
.stke-textarea {
    width: 100%;
    border: 1.5px solid #EDE9FA;
    border-radius: 10px;
    padding: 10px 12px;
    font-family: 'Nunito', sans-serif;
    font-size: 14px;
    color: #1e1b4b;
    resize: vertical;
    min-height: 80px;
    box-sizing: border-box;
    transition: border-color .15s;
}
.stke-textarea:focus { outline: none; border-color: #7F77DD; }

.stke-audio-row {
    display: flex;
    gap: 8px;
    align-items: center;
    flex-wrap: wrap;
}
.stke-btn-gen {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 8px 14px;
    border: none;
    border-radius: 10px;
    background: linear-gradient(135deg, #7F77DD, #6366f1);
    color: #fff;
    font-family: 'Nunito', sans-serif;
    font-weight: 800;
    font-size: 13px;
    cursor: pointer;
    transition: filter .15s, transform .12s;
    white-space: nowrap;
}
.stke-btn-gen:hover { filter: brightness(1.08); transform: translateY(-1px); }
.stke-btn-gen:disabled { opacity: .6; cursor: not-allowed; transform: none; }

.stke-audio-upload-label {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 8px 14px;
    border: 1.5px solid #EDE9FA;
    border-radius: 10px;
    background: #fff;
    color: #7F77DD;
    font-family: 'Nunito', sans-serif;
    font-weight: 800;
    font-size: 13px;
    cursor: pointer;
    transition: border-color .15s;
    white-space: nowrap;
}
.stke-audio-upload-label:hover { border-color: #7F77DD; }

.stke-audio-player {
    display: flex;
    align-items: center;
    gap: 6px;
    background: #F5F3FF;
    border: 1px solid #EDE9FA;
    border-radius: 10px;
    padding: 6px 10px;
    font-size: 12px;
    color: #6c63c7;
    font-weight: 700;
}
.stke-audio-player audio { height: 28px; }

/* Toolbar */
.stke-add-btn {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    width: 100%;
    padding: 14px;
    border: 2px dashed #7F77DD;
    border-radius: 16px;
    background: #F5F3FF;
    color: #7F77DD;
    font-family: 'Fredoka', sans-serif;
    font-size: 16px;
    font-weight: 600;
    cursor: pointer;
    transition: background .15s, border-color .15s;
    margin-bottom: 20px;
}
.stke-add-btn:hover { background: #ede9fa; border-color: #6c63c7; }

.stke-save-btn {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    width: 100%;
    padding: 14px;
    border: none;
    border-radius: 14px;
    background: linear-gradient(135deg, #22c55e, #16a34a);
    color: #fff;
    font-family: 'Fredoka', sans-serif;
    font-size: 18px;
    font-weight: 700;
    cursor: pointer;
    box-shadow: 0 8px 20px rgba(22,163,74,.25);
    transition: filter .15s, transform .12s;
    margin-bottom: 12px;
}
.stke-save-btn:hover { filter: brightness(1.06); transform: translateY(-1px); }

.stke-preview-link {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    width: 100%;
    padding: 12px;
    border: none;
    border-radius: 14px;
    background: linear-gradient(135deg, #F97316, #fb923c);
    color: #fff;
    font-family: 'Fredoka', sans-serif;
    font-size: 16px;
    font-weight: 700;
    text-decoration: none;
    cursor: pointer;
    box-shadow: 0 8px 20px rgba(249,115,22,.25);
    transition: filter .15s, transform .12s;
    box-sizing: border-box;
    text-align: center;
}
.stke-preview-link:hover { filter: brightness(1.06); transform: translateY(-1px); color: #fff; }

.stke-alert {
    padding: 12px 16px;
    border-radius: 10px;
    font-size: 14px;
    font-weight: 600;
    margin-bottom: 16px;
}
.stke-alert-error   { background: #fee2e2; color: #991b1b; border: 1px solid #fca5a5; }
.stke-alert-success { background: #dcfce7; color: #14532d; border: 1px solid #86efac; }
</style>

<?php if ($error !== ''): ?>
<div class="stke-alert stke-alert-error"><?= h($error) ?></div>
<?php endif; ?>
<?php if ($saved): ?>
<div class="stke-alert stke-alert-success">✔ Story saved successfully!</div>
<?php endif; ?>

<div class="stke-wrap">

    <form method="POST" id="stkeForm">
        <input type="hidden" name="pages_json" id="stkePageJson" value="">

        <!-- Title -->
        <div class="stke-title-row">
            <label class="stke-label">Story Title</label>
            <input class="stke-input" type="text" name="title" id="stkeTitle"
                   value="<?= h($activity['title']) ?>"
                   placeholder="e.g. Bananas for Lunch">
        </div>

        <!-- Pages list -->
        <div id="stkePageList"></div>

        <!-- Add page button -->
        <button type="button" class="stke-add-btn" id="stkeAddBtn">
            ➕ Add Page
        </button>

        <!-- Save -->
        <button type="submit" class="stke-save-btn" id="stkeSaveBtn">
            💾 Save Story
        </button>
    </form>

    <?php if ($activityId !== ''): ?>
    <a class="stke-preview-link"
       href="/lessons/lessons/activities/story_kids/viewer.php?id=<?= rawurlencode($activityId) ?>&unit=<?= rawurlencode($unit) ?>"
       target="_blank">
        📖 Preview Story
    </a>
    <?php endif; ?>

</div>

<script>
(function () {
    /* ── initial data from PHP ── */
    const INITIAL_PAGES = <?= json_encode(array_values($activity['pages']), JSON_UNESCAPED_UNICODE) ?>;
    const UPLOAD_URL    = '/lessons/lessons/activities/story_kids/tts.php';
    const IMG_UPLOAD    = '/lessons/lessons/activities/story_kids/editor.php?id=<?= rawurlencode($activityId) ?>&unit=<?= rawurlencode($unit) ?>&source=<?= rawurlencode($source) ?>';

    let pages = INITIAL_PAGES.length ? INITIAL_PAGES.map(p => Object.assign({}, p)) : [];
    let nextId = pages.reduce((m, p) => Math.max(m, p.id || 0), 0) + 1;

    const listEl   = document.getElementById('stkePageList');
    const addBtn   = document.getElementById('stkeAddBtn');
    const form     = document.getElementById('stkeForm');
    const jsonInput = document.getElementById('stkePageJson');

    /* ── render ── */
    function render() {
        listEl.innerHTML = '';
        pages.forEach((page, idx) => {
            listEl.appendChild(buildCard(page, idx));
        });
        updateNumbers();
    }

    function buildCard(page, idx) {
        const card = document.createElement('div');
        card.className = 'stke-page-card';
        card.dataset.idx = idx;

        /* header */
        const hdr = document.createElement('div');
        hdr.className = 'stke-page-header';
        const num = document.createElement('div');
        num.className = 'stke-page-num';
        num.textContent = idx + 1;
        const ttl = document.createElement('div');
        ttl.className = 'stke-page-title';
        ttl.textContent = 'Page ' + (idx + 1);
        const del = document.createElement('button');
        del.type = 'button';
        del.className = 'stke-del-page';
        del.title = 'Remove page';
        del.textContent = '✕';
        del.addEventListener('click', () => { pages.splice(idx, 1); render(); });
        hdr.append(num, ttl, del);

        /* body */
        const body = document.createElement('div');
        body.className = 'stke-page-body';

        /* image box */
        const imgBox = document.createElement('div');
        imgBox.className = 'stke-img-box' + (page.image_url ? ' has-img' : '');
        imgBox.title = 'Click to upload image';
        if (page.image_url) {
            const im = document.createElement('img');
            im.src = page.image_url;
            im.alt = 'Page ' + (idx + 1);
            imgBox.appendChild(im);
        } else {
            const ph = document.createElement('div');
            ph.className = 'stke-img-placeholder';
            ph.innerHTML = '<span>🖼️</span>Click to upload image';
            imgBox.appendChild(ph);
        }
        const fileInput = document.createElement('input');
        fileInput.type = 'file';
        fileInput.accept = 'image/*';
        fileInput.style.display = 'none';
        fileInput.addEventListener('change', () => uploadImage(fileInput, page, imgBox));
        imgBox.addEventListener('click', () => fileInput.click());
        imgBox.appendChild(fileInput);

        /* right col */
        const right = document.createElement('div');
        right.className = 'stke-right-col';

        /* text */
        const textLabel = document.createElement('label');
        textLabel.className = 'stke-label';
        textLabel.textContent = 'Narration Text';
        const textarea = document.createElement('textarea');
        textarea.className = 'stke-textarea';
        textarea.placeholder = 'e.g. "I like bananas for lunch!"';
        textarea.value = page.text || '';
        textarea.addEventListener('input', () => { page.text = textarea.value; });

        /* keywords */
        const kwLabel = document.createElement('label');
        kwLabel.className = 'stke-label';
        kwLabel.style.marginTop = '4px';
        kwLabel.textContent = 'Key Words (comma-separated, shown in red)';
        const kwInput = document.createElement('input');
        kwInput.type = 'text';
        kwInput.className = 'stke-input';
        kwInput.style.fontSize = '14px';
        kwInput.placeholder = 'e.g. banana, lunch, school';
        kwInput.value = (page.keywords || []).join(', ');
        kwInput.addEventListener('input', () => {
            page.keywords = kwInput.value.split(',').map(s => s.trim()).filter(s => s !== '');
        });

        /* audio row */
        const audioLabel = document.createElement('label');
        audioLabel.className = 'stke-label';
        audioLabel.style.marginTop = '4px';
        audioLabel.textContent = 'Audio';

        const audioRow = document.createElement('div');
        audioRow.className = 'stke-audio-row';

        const genBtn = document.createElement('button');
        genBtn.type = 'button';
        genBtn.className = 'stke-btn-gen';
        genBtn.innerHTML = '🔊 Generate Audio';
        genBtn.addEventListener('click', () => generateAudio(textarea, page, audioRow, genBtn));

        const uploadLabel = document.createElement('label');
        uploadLabel.className = 'stke-audio-upload-label';
        uploadLabel.innerHTML = '📎 Upload MP3';
        const audioFile = document.createElement('input');
        audioFile.type = 'file';
        audioFile.accept = 'audio/*';
        audioFile.style.display = 'none';
        audioFile.addEventListener('change', () => uploadAudioFile(audioFile, page, audioRow));
        uploadLabel.appendChild(audioFile);

        audioRow.append(genBtn, uploadLabel);

        if (page.audio_url) {
            audioRow.appendChild(buildAudioPlayer(page.audio_url));
        }

        right.append(textLabel, textarea, kwLabel, kwInput, audioLabel, audioRow);
        body.append(imgBox, right);
        card.append(hdr, body);
        return card;
    }

    function updateNumbers() {
        listEl.querySelectorAll('.stke-page-num').forEach((el, i) => { el.textContent = i + 1; });
        listEl.querySelectorAll('.stke-page-title').forEach((el, i) => { el.textContent = 'Page ' + (i + 1); });
    }

    function buildAudioPlayer(url) {
        const wrap = document.createElement('div');
        wrap.className = 'stke-audio-player';
        wrap.innerHTML = '🔊';
        const audio = document.createElement('audio');
        audio.controls = true;
        audio.src = url;
        wrap.appendChild(audio);
        return wrap;
    }

    /* ── image upload ── */
    function uploadImage(input, page, box) {
        const file = input.files[0];
        if (!file) return;
        const spinner = document.createElement('div');
        spinner.className = 'stke-uploading';
        spinner.textContent = 'Uploading…';
        box.appendChild(spinner);

        const fd = new FormData();
        fd.append('action', 'upload_image');
        fd.append('image', file);
        fetch(IMG_UPLOAD + '&upload_image=1', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(d => {
                box.removeChild(spinner);
                if (d.url) {
                    page.image_url = d.url;
                    box.classList.add('has-img');
                    box.innerHTML = '';
                    const im = document.createElement('img');
                    im.src = d.url;
                    box.appendChild(im);
                    const fi2 = document.createElement('input');
                    fi2.type = 'file'; fi2.accept = 'image/*'; fi2.style.display = 'none';
                    fi2.addEventListener('change', () => uploadImage(fi2, page, box));
                    box.addEventListener('click', () => fi2.click());
                    box.appendChild(fi2);
                } else {
                    alert('Image upload failed: ' + (d.error || 'unknown error'));
                }
            })
            .catch(() => { box.removeChild(spinner); alert('Image upload failed.'); });
    }

    /* ── TTS generate ── */
    function generateAudio(textarea, page, audioRow, genBtn) {
        const text = textarea.value.trim();
        if (!text) { alert('Please enter narration text first.'); return; }
        genBtn.disabled = true;
        genBtn.innerHTML = '⏳ Generating…';
        const fd = new FormData();
        fd.append('text', text);
        fetch(UPLOAD_URL, { method: 'POST', body: fd })
            .then(r => r.json())
            .then(d => {
                genBtn.disabled = false;
                genBtn.innerHTML = '🔊 Generate Audio';
                if (d.audio_url) {
                    page.audio_url = d.audio_url;
                    if (Array.isArray(d.word_timings)) page.word_timings = d.word_timings;
                    // Remove old player if any
                    const old = audioRow.querySelector('.stke-audio-player');
                    if (old) old.remove();
                    audioRow.appendChild(buildAudioPlayer(d.audio_url));
                } else {
                    alert('TTS error: ' + (d.error || 'unknown'));
                }
            })
            .catch(() => {
                genBtn.disabled = false;
                genBtn.innerHTML = '🔊 Generate Audio';
                alert('TTS request failed.');
            });
    }

    /* ── Audio file upload ── */
    function uploadAudioFile(input, page, audioRow) {
        const file = input.files[0];
        if (!file) return;
        const fd = new FormData();
        fd.append('action', 'upload_audio');
        fd.append('audio', file);
        fetch(IMG_UPLOAD, { method: 'POST', body: fd })
            .then(r => r.json())
            .then(d => {
                if (d.url) {
                    page.audio_url = d.url;
                    const old = audioRow.querySelector('.stke-audio-player');
                    if (old) old.remove();
                    audioRow.appendChild(buildAudioPlayer(d.url));
                } else {
                    alert('Audio upload failed: ' + (d.error || 'unknown error'));
                }
            })
            .catch(() => alert('Audio upload failed.'));
    }

    /* ── Add page ── */
    addBtn.addEventListener('click', () => {
        pages.push({ id: nextId++, image_url: '', text: '', audio_url: '', keywords: [], word_timings: [] });
        render();
    });

    /* ── Form submit ── */
    form.addEventListener('submit', () => {
        jsonInput.value = JSON.stringify(pages);
    });

    render();
})();
</script>

<?php
$content = ob_get_clean();
render_activity_editor('Story (Kids) Editor', '📖', $content);
