<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../core/_activity_viewer_template.php';

$activityId = isset($_GET['id']) ? trim((string) $_GET['id']) : '';
$unit = isset($_GET['unit']) ? trim((string) $_GET['unit']) : '';

if ($activityId === '' && $unit === '') {
    die('Actividad no especificada');
}

function activities_columns(PDO $pdo): array
{
    static $cache = null;

    if (is_array($cache)) {
        return $cache;
    }

    $cache = array();

    $stmt = $pdo->query(
        "SELECT column_name
         FROM information_schema.columns
         WHERE table_schema = 'public'
           AND table_name = 'activities'"
    );

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (isset($row['column_name'])) {
            $cache[] = (string) $row['column_name'];
        }
    }

    return $cache;
}

function resolve_unit_from_activity(PDO $pdo, string $activityId): string
{
    if ($activityId === '') {
        return '';
    }

    $columns = activities_columns($pdo);

    if (in_array('unit_id', $columns, true)) {
        $stmt = $pdo->prepare(
            "SELECT unit_id
             FROM activities
             WHERE id = :id
             LIMIT 1"
        );
        $stmt->execute(array('id' => $activityId));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row && isset($row['unit_id'])) {
            return (string) $row['unit_id'];
        }
    }

    if (in_array('unit', $columns, true)) {
        $stmt = $pdo->prepare(
            "SELECT unit
             FROM activities
             WHERE id = :id
             LIMIT 1"
        );
        $stmt->execute(array('id' => $activityId));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row && isset($row['unit'])) {
            return (string) $row['unit'];
        }
    }

    return '';
}

function default_flipbook_title(): string
{
    return 'Flipbook';
}

function normalize_flipbook_title(string $title): string
{
    $title = trim($title);
    return $title !== '' ? $title : default_flipbook_title();
}

function normalize_flipbook_payload($rawData): array
{
    $default = array(
        'title' => default_flipbook_title(),
        'language' => 'en-US',
        'listen_enabled' => true,
        'page_texts' => array(),
        'pdf_url' => '',
        'pdf_public_id' => '',
        'pdf_bytes' => 0,
        'pdf' => '',
    );

    if ($rawData === null || $rawData === '') {
        return $default;
    }

    $decoded = is_string($rawData) ? json_decode($rawData, true) : $rawData;
    if (!is_array($decoded)) {
        return $default;
    }

    return array(
        'title' => normalize_flipbook_title(isset($decoded['title']) ? (string) $decoded['title'] : ''),
        'language' => isset($decoded['language']) && trim((string) $decoded['language']) !== '' ? trim((string) $decoded['language']) : 'en-US',
        'listen_enabled' => !isset($decoded['listen_enabled']) || (bool) $decoded['listen_enabled'],
        'page_texts' => isset($decoded['page_texts']) && is_array($decoded['page_texts']) ? array_values($decoded['page_texts']) : array(),
        'pdf_url' => isset($decoded['pdf_url']) ? trim((string) $decoded['pdf_url']) : '',
        'pdf_public_id' => isset($decoded['pdf_public_id']) ? trim((string) $decoded['pdf_public_id']) : '',
        'pdf_bytes' => isset($decoded['pdf_bytes']) ? (int) $decoded['pdf_bytes'] : 0,
        'pdf' => isset($decoded['pdf']) ? trim((string) $decoded['pdf']) : '',
    );
}

function load_flipbook_activity(PDO $pdo, string $unit, string $activityId): array
{
    $columns = activities_columns($pdo);

    $selectFields = array('id');
    if (in_array('data', $columns, true)) {
        $selectFields[] = 'data';
    }
    if (in_array('content_json', $columns, true)) {
        $selectFields[] = 'content_json';
    }
    if (in_array('title', $columns, true)) {
        $selectFields[] = 'title';
    }
    if (in_array('name', $columns, true)) {
        $selectFields[] = 'name';
    }

    $fallback = array(
        'id' => '',
        'title' => default_flipbook_title(),
        'payload' => normalize_flipbook_payload(null),
    );

    $row = null;

    if ($activityId !== '') {
        $stmt = $pdo->prepare(
            "SELECT " . implode(', ', $selectFields) . "
             FROM activities
             WHERE id = :id
               AND type = 'flipbooks'
             LIMIT 1"
        );
        $stmt->execute(array('id' => $activityId));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    if (!$row && in_array('unit_id', $columns, true)) {
        $stmt = $pdo->prepare(
            "SELECT " . implode(', ', $selectFields) . "
             FROM activities
             WHERE unit_id = :unit
               AND type = 'flipbooks'
             ORDER BY id ASC
             LIMIT 1"
        );
        $stmt->execute(array('unit' => $unit));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    if (!$row && in_array('unit', $columns, true)) {
        $stmt = $pdo->prepare(
            "SELECT " . implode(', ', $selectFields) . "
             FROM activities
             WHERE unit = :unit
               AND type = 'flipbooks'
             ORDER BY id ASC
             LIMIT 1"
        );
        $stmt->execute(array('unit' => $unit));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    if (!$row) {
        return $fallback;
    }

    $rawData = null;
    if (isset($row['data'])) {
        $rawData = $row['data'];
    } elseif (isset($row['content_json'])) {
        $rawData = $row['content_json'];
    }

    $payload = normalize_flipbook_payload($rawData);

    $columnTitle = '';
    if (isset($row['title']) && trim((string) $row['title']) !== '') {
        $columnTitle = trim((string) $row['title']);
    } elseif (isset($row['name']) && trim((string) $row['name']) !== '') {
        $columnTitle = trim((string) $row['name']);
    }

    if ($columnTitle !== '') {
        $payload['title'] = $columnTitle;
    }

    return array(
        'id' => isset($row['id']) ? (string) $row['id'] : '',
        'title' => normalize_flipbook_title((string) $payload['title']),
        'payload' => $payload,
    );
}

if ($unit === '' && $activityId !== '') {
    $unit = resolve_unit_from_activity($pdo, $activityId);
}

$activity = load_flipbook_activity($pdo, $unit, $activityId);
$flipbook = isset($activity['payload']) && is_array($activity['payload']) ? $activity['payload'] : normalize_flipbook_payload(null);

$pdfUrl = '';
if (isset($flipbook['pdf_url']) && trim((string) $flipbook['pdf_url']) !== '') {
    $pdfUrl = trim((string) $flipbook['pdf_url']);
} elseif (isset($flipbook['pdf']) && trim((string) $flipbook['pdf']) !== '') {
    $legacyPdf = trim((string) $flipbook['pdf']);
    if (preg_match('/^https?:\/\//i', $legacyPdf)) {
        $pdfUrl = $legacyPdf;
    } else {
        $pdfUrl = '/lessons/lessons/' . ltrim($legacyPdf, '/');
    }
}

$language = isset($flipbook['language']) && trim((string) $flipbook['language']) !== ''
    ? trim((string) $flipbook['language'])
    : 'en-US';

$listenEnabled = !isset($flipbook['listen_enabled']) || (bool) $flipbook['listen_enabled'];
$pageTexts = isset($flipbook['page_texts']) && is_array($flipbook['page_texts']) ? $flipbook['page_texts'] : array();
$viewerTitle = isset($activity['title']) ? (string) $activity['title'] : default_flipbook_title();

if ($pdfUrl === '') {
    die('No hay flipbook para esta unidad');
}

ob_start();
?>
<style>
.flipbook-wrap{
    width:100%;
    max-width:100%;
    margin:0 auto;
    text-align:center;
}
.page-indicator{
    color:#475569;
    font-size:14px;
    margin-bottom:10px;
}
.toolbar{
    display:flex;
    justify-content:center;
    align-items:center;
    gap:10px;
    flex-wrap:wrap;
    margin-bottom:12px;
}
.toolbar button{
    border:none;
    border-radius:10px;
    padding:10px 14px;
    font-weight:bold;
    cursor:pointer;
    color:white;
}
.btn-nav{background:#0b5ed7}
.btn-listen{background:#16a34a}
.btn-listen.disabled{background:#94a3b8;cursor:not-allowed}
#book-shell{
    position:relative;
    margin:0 auto;
    width:100%;
    max-width:100%;
}
#flipbook{
    width:100%;
    height:min(72vh, 760px);
    min-height:480px;
    margin:0 auto;
    box-shadow:0 10px 30px rgba(0,0,0,.18);
    background:white;
    border-radius:16px;
    overflow:hidden;
}
.flip-page{
    background:#fff;
    overflow:hidden;
    display:flex;
    align-items:center;
    justify-content:center;
}
.flip-page canvas,
.flip-page iframe{
    width:100%;
    height:100%;
    display:block;
    border:none;
}
.loading{color:#334155;font-weight:bold;padding:30px 0}
.error{color:#dc2626;font-weight:bold;padding:20px 0}
.viewer-tip{
    color:#64748b;
    font-size:13px;
    margin-bottom:8px;
}
@media (max-width: 768px){
    #flipbook{
        height:min(64vh, 620px);
        min-height:380px;
    }
    .toolbar{
        gap:8px;
    }
    .toolbar button{
        width:100%;
    }
}
</style>

<div class="flipbook-wrap">
    <div class="viewer-tip">Pasa páginas dentro del contenedor. El botón Listen lee el texto asignado a la página actual.</div>
    <div class="page-indicator" id="pageIndicator">Page 1</div>

    <div class="toolbar">
        <button class="btn-nav" id="prevBtn" type="button">⬅ Prev</button>
        <button class="btn-listen <?= $listenEnabled ? '' : 'disabled' ?>" id="listenBtn" type="button" <?= $listenEnabled ? '' : 'disabled' ?>>🔊 Listen</button>
        <button class="btn-nav" id="nextBtn" type="button">Next ➡</button>
    </div>

    <div id="book-shell">
        <div id="flipbook"></div>
        <div id="status" class="loading">Loading book...</div>
    </div>
</div>

<audio id="pageFlipSound" preload="auto">
    <source src="./sounds/page-flip.mp3" type="audio/mpeg">
</audio>

<script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.6.0/jquery.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/turn.js/4.1.0/turn.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/2.16.105/pdf.min.js"></script>

<script>
const pdfUrl = <?= json_encode($pdfUrl, JSON_UNESCAPED_UNICODE) ?>;
const pageTexts = <?= json_encode($pageTexts, JSON_UNESCAPED_UNICODE) ?>;
const listenEnabledGlobal = <?= $listenEnabled ? 'true' : 'false' ?>;
const speechLang = <?= json_encode($language, JSON_UNESCAPED_UNICODE) ?>;

pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/2.16.105/pdf.worker.min.js';

const statusEl = document.getElementById('status');
const pageIndicator = document.getElementById('pageIndicator');
const listenBtn = document.getElementById('listenBtn');
const flipSound = document.getElementById('pageFlipSound');
const flipbookEl = document.getElementById('flipbook');

let totalPages = 0;
let currentPage = 1;
let simpleMode = false;

function escapeHtml(value) {
    return String(value || '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

function updatePageIndicator(page) {
    currentPage = page;
    pageIndicator.textContent = 'Page ' + page + ' / ' + totalPages;
}

function getTextForPage(page) {
    const idx = page - 1;
    if (idx >= 0 && idx < pageTexts.length && pageTexts[idx]) {
        return String(pageTexts[idx]);
    }
    return '';
}

function safePlayFlipSound() {
    if (!flipSound) return;
    try {
        flipSound.currentTime = 0;
        const promise = flipSound.play();
        if (promise && typeof promise.catch === 'function') {
            promise.catch(function () {});
        }
    } catch (e) {}
}

function getBookSize() {
    const shell = document.getElementById('book-shell');
    const shellWidth = shell ? shell.clientWidth : window.innerWidth;
    const maxWidth = Math.min(shellWidth, 1200);
    const width = Math.max(320, maxWidth);
    const height = Math.max(380, Math.min(window.innerHeight * 0.72, 760));
    return { width, height };
}

function buildPagesFromPdf(pdf) {
    totalPages = pdf.numPages;
    const size = getBookSize();
    const displayDouble = size.width >= 900;

    const pagePromises = [];

    for (let pageNum = 1; pageNum <= totalPages; pageNum += 1) {
        const promise = pdf.getPage(pageNum).then(function (page) {
            const pageDiv = document.createElement('div');
            pageDiv.className = 'flip-page';

            const canvas = document.createElement('canvas');
            pageDiv.appendChild(canvas);
            flipbookEl.appendChild(pageDiv);

            const pageWidth = displayDouble ? Math.floor(size.width / 2) : size.width;
            const pageHeight = size.height;

            const baseViewport = page.getViewport({ scale: 1 });
            const scale = Math.min(pageWidth / baseViewport.width, pageHeight / baseViewport.height);
            const viewport = page.getViewport({ scale: scale });

            const ctx = canvas.getContext('2d');
            canvas.width = viewport.width;
            canvas.height = viewport.height;

            return page.render({ canvasContext: ctx, viewport: viewport }).promise;
        });

        pagePromises.push(promise);
    }

    return Promise.all(pagePromises).then(function () {
        return { width: size.width, height: size.height, displayDouble: displayDouble };
    });
}

function initTurnBook(bookConfig) {
    const $book = $('#flipbook');

    if (!$book.length || typeof $book.turn !== 'function') {
        initSimpleFallback();
        return;
    }

    const displayMode = bookConfig.displayDouble ? 'double' : 'single';

    $book.turn({
        width: bookConfig.width,
        height: bookConfig.height,
        autoCenter: true,
        elevation: 50,
        gradients: true,
        display: displayMode,
        when: {
            turned: function (event, page) {
                updatePageIndicator(page);
            },
            turning: function () {
                safePlayFlipSound();
            }
        }
    });

    document.getElementById('prevBtn').addEventListener('click', function () {
        $book.turn('previous');
    });

    document.getElementById('nextBtn').addEventListener('click', function () {
        $book.turn('next');
    });

    updatePageIndicator(1);
}

function initSimpleFallback() {
    simpleMode = true;

    const pages = Array.prototype.slice.call(flipbookEl.querySelectorAll('.flip-page'));
    if (!pages.length) {
        return;
    }

    pages.forEach(function (p, i) {
        p.style.display = i === 0 ? 'flex' : 'none';
        p.style.width = '100%';
        p.style.height = '100%';
    });

    document.getElementById('prevBtn').addEventListener('click', function () {
        pages[currentPage - 1].style.display = 'none';
        currentPage = currentPage <= 1 ? totalPages : currentPage - 1;
        pages[currentPage - 1].style.display = 'flex';
        safePlayFlipSound();
        updatePageIndicator(currentPage);
    });

    document.getElementById('nextBtn').addEventListener('click', function () {
        pages[currentPage - 1].style.display = 'none';
        currentPage = currentPage >= totalPages ? 1 : currentPage + 1;
        pages[currentPage - 1].style.display = 'flex';
        safePlayFlipSound();
        updatePageIndicator(currentPage);
    });

    updatePageIndicator(1);
}

if (listenEnabledGlobal) {
    listenBtn.addEventListener('click', function () {
        const text = getTextForPage(currentPage);
        if (!text) return;

        try {
            speechSynthesis.cancel();
            const utter = new SpeechSynthesisUtterance(text);
            utter.lang = speechLang || 'en-US';
            utter.rate = 0.9;
            speechSynthesis.speak(utter);
        } catch (e) {}
    });
}

pdfjsLib.getDocument({ url: pdfUrl, withCredentials: false }).promise
    .then(function (pdf) {
        return buildPagesFromPdf(pdf);
    })
    .then(function (bookConfig) {
        statusEl.style.display = 'none';

        if (window.jQuery && typeof window.jQuery.fn.turn === 'function') {
            initTurnBook(bookConfig);
        } else {
            initSimpleFallback();
        }
    })
    .catch(function (error) {
        statusEl.className = 'error';
        statusEl.textContent = 'No se pudo cargar el flipbook PDF.';
        console.error('Flipbook PDF load error:', error);
    });
</script>
<?php
$content = ob_get_clean();
render_activity_viewer($viewerTitle, '📖', $content);
