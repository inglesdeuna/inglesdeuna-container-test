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
:root{
    --page-ratio: 0.7727;
}

.flipbook-wrap{
    width:100%;
    max-width:1120px;
    margin:0 auto;
    text-align:center;
}

.page-indicator{
    color:#475569;
    font-size:14px;
    margin-bottom:10px;
    font-weight:bold;
}

.book-stage{
    position:relative;
    width:100%;
    max-width:1120px;
    margin:0 auto;
    background:transparent;
    border-radius:18px;
    padding:0;
}

.book-spread{
    position:relative;
    width:100%;
    max-width:1120px;
    margin:0 auto;
    min-height:760px;
    display:grid;
    grid-template-columns:1fr 1fr;
    gap:0;
    align-items:center;
    justify-items:center;
    padding:20px 22px 60px;
    background:linear-gradient(180deg,#f1f5f9 0%,#e2e8f0 100%);
    border-radius:18px;
    box-shadow:0 18px 36px rgba(0,0,0,.12);
    overflow:hidden;
}

.book-spread::before{
    content:"";
    position:absolute;
    top:20px;
    bottom:60px;
    left:50%;
    width:10px;
    transform:translateX(-50%);
    background:linear-gradient(180deg, rgba(0,0,0,.12), rgba(0,0,0,.03), rgba(0,0,0,.12));
    border-radius:30px;
    z-index:3;
}

.book-page{
    position:relative;
    width:100%;
    height:100%;
    display:flex;
    align-items:center;
    justify-content:center;
    overflow:hidden;
    background:transparent;
}

.book-page.empty{
    opacity:.75;
}

.page-sheet{
    position:relative;
    width:min(94%, 490px);
    aspect-ratio: var(--page-ratio);
    background:#fff;
    border-radius:3px;
    box-shadow:
        0 10px 24px rgba(0,0,0,.10),
        0 2px 8px rgba(0,0,0,.06);
    display:flex;
    align-items:center;
    justify-content:center;
    overflow:hidden;
    transition:transform .22s ease, box-shadow .22s ease;
}

.book-page.left .page-sheet{
    box-shadow:
        -8px 10px 24px rgba(0,0,0,.10),
        -2px 2px 8px rgba(0,0,0,.05);
}

.book-page.right .page-sheet{
    box-shadow:
        8px 10px 24px rgba(0,0,0,.10),
        2px 2px 8px rgba(0,0,0,.05);
}

.page-sheet.cover{
    background:linear-gradient(180deg,#ffffff 0%,#f8fafc 100%);
}

.page-sheet.canvas-loaded::after{
    content:"";
    position:absolute;
    inset:0;
    pointer-events:none;
    box-shadow:inset 0 0 0 1px rgba(0,0,0,.04);
}

.page-sheet canvas{
    width:calc(100% - 2px);
    height:calc(100% - 2px);
    object-fit:contain;
    display:block;
    margin:1px;
    border-radius:1px;
}

.page-sheet.empty-sheet{
    background:linear-gradient(180deg,#f8fafc,#eef2f7);
}

.page-number{
    position:absolute;
    bottom:8px;
    right:10px;
    font-size:11px;
    color:#64748b;
    background:rgba(255,255,255,.92);
    padding:3px 7px;
    border-radius:999px;
    z-index:5;
}

.corner-nav{
    position:absolute;
    bottom:14px;
    width:54px;
    height:54px;
    border:none;
    border-radius:999px;
    color:#fff;
    font-size:24px;
    font-weight:bold;
    cursor:pointer;
    z-index:20;
    box-shadow:0 10px 22px rgba(0,0,0,.18);
    transition:transform .18s ease, opacity .18s ease;
}

.corner-nav:hover{
    transform:translateY(-2px);
}

.corner-nav.prev{
    left:18px;
    background:#0b5ed7;
}

.corner-nav.next{
    right:18px;
    background:#0b5ed7;
}

.listen-btn{
    position:absolute;
    top:14px;
    right:18px;
    border:none;
    border-radius:999px;
    color:#fff;
    background:#16a34a;
    padding:10px 16px;
    font-weight:700;
    cursor:pointer;
    z-index:20;
    box-shadow:0 10px 22px rgba(0,0,0,.14);
}

.listen-btn.disabled{
    background:#94a3b8;
    cursor:not-allowed;
}

.loading{color:#334155;font-weight:bold;padding:30px 0}
.error{color:#dc2626;font-weight:bold;padding:20px 0}

@media (max-width: 900px){
    .book-spread{
        grid-template-columns:1fr;
        min-height:700px;
        padding:20px 14px 60px;
    }

    .book-spread::before{
        display:none;
    }

    .book-page.left{
        display:none;
    }

    .page-sheet{
        width:min(96%, 520px);
    }

    .listen-btn{
        top:12px;
        right:12px;
    }

    .corner-nav.prev{
        left:12px;
    }

    .corner-nav.next{
        right:12px;
    }
}

@media (max-width: 640px){
    .book-spread{
        min-height:540px;
    }

    .page-sheet{
        width:min(97%, 380px);
    }

    .corner-nav{
        width:48px;
        height:48px;
        font-size:20px;
    }

    .listen-btn{
        padding:9px 14px;
        font-size:13px;
    }
}
</style>

<div class="flipbook-wrap">
    <div class="page-indicator" id="pageIndicator">Loading...</div>

    <div class="book-stage">
        <button
            class="listen-btn <?= $listenEnabled ? '' : 'disabled' ?>"
            id="listenBtn"
            type="button"
            <?= $listenEnabled ? '' : 'disabled' ?>
        >🔊 Listen</button>

        <div id="status" class="loading">Loading book...</div>

        <div class="book-spread" id="bookSpread" style="display:none;">
            <div class="book-page left" id="leftPage"></div>
            <div class="book-page right" id="rightPage"></div>

            <button class="corner-nav prev" id="prevBtn" type="button" aria-label="Previous page">‹</button>
            <button class="corner-nav next" id="nextBtn" type="button" aria-label="Next page">›</button>
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
const prevBtn = document.getElementById('prevBtn');
const nextBtn = document.getElementById('nextBtn');

let pdfDoc = null;
let realPdfPages = 0;
let virtualPageCount = 0;
let currentSpreadStart = 1;
let renderedCache = {};
let realPageRatio = 0.7727;

function setPageRatio(ratio) {
    if (!ratio || !isFinite(ratio) || ratio <= 0) return;
    document.documentElement.style.setProperty('--page-ratio', String(ratio));
}

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

function isSinglePageMode() {
    return window.innerWidth <= 900;
}

function getSpreadStep() {
    return isSinglePageMode() ? 1 : 2;
}

function getVirtualPdfPageForVirtualPage(virtualPage) {
    if (virtualPage <= 1) {
        return null;
    }
    if (virtualPage >= virtualPageCount) {
        return null;
    }
    return virtualPage - 1;
}

function getTextForVirtualPage(virtualPage) {
    const realPage = getVirtualPdfPageForVirtualPage(virtualPage);
    if (!realPage) return '';

    const idx = realPage - 1;
    if (idx >= 0 && idx < pageTexts.length && pageTexts[idx]) {
        return String(pageTexts[idx]);
    }
    return '';
}

function updateIndicator() {
    if (isSinglePageMode()) {
        pageIndicator.textContent = 'Page ' + currentSpreadStart + ' / ' + virtualPageCount;
    } else {
        const end = Math.min(currentSpreadStart + 1, virtualPageCount);
        pageIndicator.textContent = 'Pages ' + currentSpreadStart + ' - ' + end + ' / ' + virtualPageCount;
    }
}

function createSheetShell(pageNumber, isCover) {
    const sheet = document.createElement('div');
    sheet.className = 'page-sheet' + (isCover ? ' cover' : '');
    sheet.innerHTML = '<div class="page-number">' + (pageNumber ? ('Page ' + pageNumber) : '') + '</div>';
    return sheet;
}

function createEmptySheet(pageNumber, coverLabel) {
    const sheet = createSheetShell(pageNumber, true);
    sheet.classList.add('empty-sheet');

    const label = document.createElement('div');
    label.style.fontWeight = '700';
    label.style.color = '#64748b';
    label.style.fontSize = '16px';
    label.textContent = coverLabel;

    sheet.appendChild(label);
    return sheet;
}

function getTargetCanvasSize(sheetEl) {
    const rect = sheetEl.getBoundingClientRect();
    const width = Math.max(220, Math.floor(rect.width - 2));
    const height = Math.max(280, Math.floor(rect.height - 2));
    return { width, height };
}

async function renderRealPdfPage(realPageNumber, sheetEl) {
    let sourceCanvas = renderedCache[realPageNumber];

    if (!sourceCanvas) {
        const page = await pdfDoc.getPage(realPageNumber);
        const baseViewport = page.getViewport({ scale: 1 });

        const target = getTargetCanvasSize(sheetEl);
        const scale = Math.min(target.width / baseViewport.width, target.height / baseViewport.height);
        const viewport = page.getViewport({ scale: scale });

        sourceCanvas = document.createElement('canvas');
        const ctx = sourceCanvas.getContext('2d');

        sourceCanvas.width = viewport.width;
        sourceCanvas.height = viewport.height;

        await page.render({ canvasContext: ctx, viewport: viewport }).promise;
        renderedCache[realPageNumber] = sourceCanvas;
    }

    const canvas = document.createElement('canvas');
    canvas.width = sourceCanvas.width;
    canvas.height = sourceCanvas.height;
    canvas.getContext('2d').drawImage(sourceCanvas, 0, 0);

    sheetEl.classList.add('canvas-loaded');
    sheetEl.appendChild(canvas);
}

async function renderVirtualPageInto(containerEl, virtualPageNumber) {
    containerEl.innerHTML = '';

    const isCoverFront = virtualPageNumber === 1;
    const isCoverBack = virtualPageNumber === virtualPageCount;

    if (virtualPageNumber < 1 || virtualPageNumber > virtualPageCount) {
        const empty = createEmptySheet(null, '');
        empty.classList.add('empty-sheet');
        containerEl.classList.add('empty');
        containerEl.appendChild(empty);
        return;
    }

    containerEl.classList.remove('empty');

    if (isCoverFront) {
        const sheet = createEmptySheet(virtualPageNumber, 'Cover');
        containerEl.appendChild(sheet);
        return;
    }

    if (isCoverBack) {
        const sheet = createEmptySheet(virtualPageNumber, 'Back Cover');
        containerEl.appendChild(sheet);
        return;
    }

    const realPage = getVirtualPdfPageForVirtualPage(virtualPageNumber);
    const sheet = createSheetShell(virtualPageNumber, false);
    containerEl.appendChild(sheet);

    await renderRealPdfPage(realPage, sheet);
}

async function renderCurrentSpread() {
    updateIndicator();

    if (isSinglePageMode()) {
        leftPage.style.display = 'none';
        rightPage.style.display = 'flex';
        await renderVirtualPageInto(rightPage, currentSpreadStart);
        return;
    }

    leftPage.style.display = 'flex';
    rightPage.style.display = 'flex';

    await renderVirtualPageInto(leftPage, currentSpreadStart);
    await renderVirtualPageInto(rightPage, currentSpreadStart + 1);
}

function clampStart(pageStart) {
    const step = getSpreadStep();

    if (step === 1) {
        if (pageStart < 1) return 1;
        if (pageStart > virtualPageCount) return virtualPageCount;
        return pageStart;
    }

    if (pageStart < 1) return 1;

    const maxStart = virtualPageCount % 2 === 0 ? virtualPageCount - 1 : virtualPageCount;
    if (pageStart > maxStart) return maxStart;
    return pageStart;
}

function goNext() {
    const next = clampStart(currentSpreadStart + getSpreadStep());
    if (next !== currentSpreadStart) {
        currentSpreadStart = next;
        safePlayFlipSound();
        renderCurrentSpread();
    }
}

function goPrev() {
    const prev = clampStart(currentSpreadStart - getSpreadStep());
    if (prev !== currentSpreadStart) {
        currentSpreadStart = prev;
        safePlayFlipSound();
        renderCurrentSpread();
    }
}

prevBtn.addEventListener('click', goPrev);
nextBtn.addEventListener('click', goNext);

if (listenEnabledGlobal) {
    listenBtn.addEventListener('click', function () {
        try {
            speechSynthesis.cancel();

            let text = getTextForVirtualPage(currentSpreadStart);

            if (!isSinglePageMode()) {
                const secondText = getTextForVirtualPage(currentSpreadStart + 1);
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
        currentSpreadStart = clampStart(currentSpreadStart);
        renderCurrentSpread();
    }, 250);
});

pdfjsLib.getDocument({ url: pdfUrl, withCredentials: false }).promise
    .then(async function (pdf) {
        pdfDoc = pdf;
        realPdfPages = pdf.numPages;
        virtualPageCount = realPdfPages + 2;

        const firstPage = await pdf.getPage(1);
        const firstViewport = firstPage.getViewport({ scale: 1 });
        realPageRatio = firstViewport.width / firstViewport.height;
        setPageRatio(realPageRatio);

        statusEl.style.display = 'none';
        bookSpread.style.display = 'grid';
        currentSpreadStart = 1;

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
