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
    max-width:1200px;
    margin:0 auto;
    text-align:center;
}

.viewer-tip{
    color:#64748b;
    font-size:13px;
    margin-bottom:8px;
}

.page-indicator{
    color:#475569;
    font-size:14px;
    margin-bottom:10px;
    font-weight:bold;
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

.book-stage{
    width:100%;
    max-width:1200px;
    margin:0 auto;
    background:#eef2f7;
    border-radius:18px;
    padding:18px;
    box-shadow:0 12px 28px rgba(0,0,0,.10);
}

.book-spread{
    position:relative;
    width:100%;
    aspect-ratio: 16 / 9.8;
    min-height:420px;
    background:linear-gradient(90deg,#f8fafc 0%,#ffffff 48%,#edf2f7 50%,#ffffff 52%,#f8fafc 100%);
    border-radius:14px;
    overflow:hidden;
    display:grid;
    grid-template-columns:1fr 1fr;
    gap:0;
    box-shadow:inset 0 0 0 1px rgba(0,0,0,.06);
}

.book-spread::before{
    content:"";
    position:absolute;
    top:0;
    bottom:0;
    left:50%;
    width:8px;
    transform:translateX(-50%);
    background:linear-gradient(180deg, rgba(0,0,0,.08), rgba(0,0,0,.03), rgba(0,0,0,.08));
    z-index:2;
    border-radius:20px;
}

.book-page{
    position:relative;
    display:flex;
    align-items:center;
    justify-content:center;
    overflow:hidden;
    background:white;
}

.book-page.left{
    box-shadow:inset -14px 0 18px rgba(0,0,0,.06);
}

.book-page.right{
    box-shadow:inset 14px 0 18px rgba(0,0,0,.06);
}

.book-page.empty{
    background:linear-gradient(180deg,#f8fafc,#eef2f7);
}

.book-page canvas{
    max-width:100%;
    max-height:100%;
    display:block;
    object-fit:contain;
}

.page-label{
    position:absolute;
    bottom:10px;
    right:14px;
    font-size:12px;
    color:#64748b;
    background:rgba(255,255,255,.88);
    padding:4px 8px;
    border-radius:999px;
    z-index:3;
}

.loading{color:#334155;font-weight:bold;padding:30px 0}
.error{color:#dc2626;font-weight:bold;padding:20px 0}

@media (max-width: 900px){
    .book-spread{
        grid-template-columns:1fr;
        aspect-ratio:auto;
        min-height:420px;
    }

    .book-spread::before{
        display:none;
    }

    .book-page.left{
        display:none;
    }

    .book-page.right{
        box-shadow:none;
    }
}

@media (max-width: 768px){
    .toolbar button{
        width:100%;
    }

    .book-stage{
        padding:12px;
    }

    .book-spread{
        min-height:340px;
    }
}
</style>

<div class="flipbook-wrap">
    <div class="viewer-tip">Ahora se muestra como libro abierto con páginas opuestas. En pantallas pequeñas se adapta a una página.</div>
    <div class="page-indicator" id="pageIndicator">Pages 1 - 2</div>

    <div class="toolbar">
        <button class="btn-nav" id="prevBtn" type="button">⬅ Prev</button>
        <button class="btn-listen <?= $listenEnabled ? '' : 'disabled' ?>" id="listenBtn" type="button" <?= $listenEnabled ? '' : 'disabled' ?>>🔊 Listen</button>
        <button class="btn-nav" id="nextBtn" type="button">Next ➡</button>
    </div>

    <div class="book-stage">
        <div id="status" class="loading">Loading book...</div>

        <div class="book-spread" id="bookSpread" style="display:none;">
            <div class="book-page left" id="leftPage">
                <div class="page-label" id="leftLabel"></div>
            </div>
            <div class="book-page right" id="rightPage">
                <div class="page-label" id="rightLabel"></div>
            </div>
        </div>
    </div>
</div>

<audio id="pageFlipSound" preload="auto">
    <source src="./sounds/page-flip.mp3" type="audio/mpeg">
</audio>

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
const bookSpread = document.getElementById('bookSpread');
const leftPage = document.getElementById('leftPage');
const rightPage = document.getElementById('rightPage');
const leftLabel = document.getElementById('leftLabel');
const rightLabel = document.getElementById('rightLabel');

let pdfDoc = null;
let totalPages = 0;
let currentSpreadStart = 1;
let renderedCache = {};

function safePlayFlipSound() {
    if (!flipSound) return;
    try {
        flipSound.currentTime = 0;
        const p = flipSound.play();
        if (p && typeof p.catch === 'function') {
            p.catch(function () {});
        }
    } catch (e) {}
}

function getSpreadStep() {
    return window.innerWidth <= 900 ? 1 : 2;
}

function getTextForPage(pageNumber) {
    const idx = pageNumber - 1;
    if (idx >= 0 && idx < pageTexts.length && pageTexts[idx]) {
        return String(pageTexts[idx]);
    }
    return '';
}

function updateIndicator() {
    const step = getSpreadStep();

    if (step === 1) {
        pageIndicator.textContent = 'Page ' + currentSpreadStart + ' / ' + totalPages;
    } else {
        const end = Math.min(currentSpreadStart + 1, totalPages);
        pageIndicator.textContent = 'Pages ' + currentSpreadStart + ' - ' + end + ' / ' + totalPages;
    }
}

function getTargetCanvasSize(containerEl) {
    const rect = containerEl.getBoundingClientRect();
    const width = Math.max(200, Math.floor(rect.width - 18));
    const height = Math.max(260, Math.floor(rect.height - 18));
    return { width, height };
}

function clearPage(containerEl, labelEl, pageNumber, isEmpty) {
    const oldCanvas = containerEl.querySelector('canvas');
    if (oldCanvas) {
        oldCanvas.remove();
    }

    if (isEmpty) {
        containerEl.classList.add('empty');
        labelEl.textContent = '';
    } else {
        containerEl.classList.remove('empty');
        labelEl.textContent = 'Page ' + pageNumber;
    }
}

async function renderPdfPageToContainer(pageNumber, containerEl, labelEl) {
    if (!pageNumber || pageNumber < 1 || pageNumber > totalPages) {
        clearPage(containerEl, labelEl, pageNumber, true);
        return;
    }

    clearPage(containerEl, labelEl, pageNumber, false);

    let sourceCanvas = renderedCache[pageNumber];

    if (!sourceCanvas) {
        const page = await pdfDoc.getPage(pageNumber);
        const baseViewport = page.getViewport({ scale: 1 });

        const target = getTargetCanvasSize(containerEl);
        const scale = Math.min(target.width / baseViewport.width, target.height / baseViewport.height);
        const viewport = page.getViewport({ scale: scale });

        sourceCanvas = document.createElement('canvas');
        const ctx = sourceCanvas.getContext('2d');

        sourceCanvas.width = viewport.width;
        sourceCanvas.height = viewport.height;

        await page.render({ canvasContext: ctx, viewport: viewport }).promise;
        renderedCache[pageNumber] = sourceCanvas;
    }

    const clonedCanvas = document.createElement('canvas');
    clonedCanvas.width = sourceCanvas.width;
    clonedCanvas.height = sourceCanvas.height;
    clonedCanvas.getContext('2d').drawImage(sourceCanvas, 0, 0);

    containerEl.appendChild(clonedCanvas);
}

async function renderCurrentSpread() {
    const step = getSpreadStep();
    updateIndicator();

    if (step === 1) {
        leftPage.style.display = 'none';
        await renderPdfPageToContainer(currentSpreadStart, rightPage, rightLabel);
    } else {
        leftPage.style.display = 'flex';
        await renderPdfPageToContainer(currentSpreadStart, leftPage, leftLabel);
        await renderPdfPageToContainer(currentSpreadStart + 1, rightPage, rightLabel);
    }
}

function goNext() {
    const step = getSpreadStep();
    let nextStart = currentSpreadStart + step;

    if (nextStart > totalPages) {
        nextStart = step === 1 ? totalPages : (totalPages % 2 === 0 ? totalPages - 1 : totalPages);
        if (nextStart < 1) nextStart = 1;
    }

    if (nextStart !== currentSpreadStart) {
        currentSpreadStart = nextStart;
        safePlayFlipSound();
        renderCurrentSpread();
    }
}

function goPrev() {
    const step = getSpreadStep();
    let prevStart = currentSpreadStart - step;

    if (prevStart < 1) {
        prevStart = 1;
    }

    if (prevStart !== currentSpreadStart) {
        currentSpreadStart = prevStart;
        safePlayFlipSound();
        renderCurrentSpread();
    }
}

document.getElementById('nextBtn').addEventListener('click', goNext);
document.getElementById('prevBtn').addEventListener('click', goPrev);

if (listenEnabledGlobal) {
    listenBtn.addEventListener('click', function () {
        try {
            speechSynthesis.cancel();

            const step = getSpreadStep();
            let text = getTextForPage(currentSpreadStart);

            if (step === 2) {
                const secondText = getTextForPage(currentSpreadStart + 1);
                if (secondText) {
                    text = text ? (text + '. ' + secondText) : secondText;
                }
            }

            if (!text) return;

            const utter = new SpeechSynthesisUtterance(text);
            utter.lang = speechLang || 'en-US';
            utter.rate = 0.9;
            speechSynthesis.speak(utter);
        } catch (e) {}
    });
}

let resizeTimer = null;
window.addEventListener('resize', function () {
    if (!pdfDoc) return;

    clearTimeout(resizeTimer);
    resizeTimer = setTimeout(function () {
        renderedCache = {};
        renderCurrentSpread();
    }, 250);
});

pdfjsLib.getDocument({ url: pdfUrl, withCredentials: false }).promise
    .then(function (pdf) {
        pdfDoc = pdf;
        totalPages = pdf.numPages;
        statusEl.style.display = 'none';
        bookSpread.style.display = 'grid';
        return renderCurrentSpread();
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
