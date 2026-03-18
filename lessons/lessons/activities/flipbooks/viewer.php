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

body{
    background:#d8e1ee;
}

.flipbook-wrap{
    width:100%;
    max-width:1260px;
    margin:0 auto;
    text-align:center;
}

.page-indicator{
    color:#475569;
    font-size:14px;
    margin-bottom:8px;
    font-weight:600;
}

.book-stage{
    position:relative;
    width:100%;
    max-width:1260px;
    margin:0 auto;
    padding-top:2px;
}

.book-view{
    position:relative;
    width:100%;
    min-height:760px;
}

.spread{
    position:relative;
    width:100%;
    display:grid;
    grid-template-columns:1fr 1fr;
    gap:24px;
    align-items:center;
    justify-items:center;
    min-height:760px;
}

.single-cover{
    position:relative;
    width:100%;
    display:flex;
    align-items:center;
    justify-content:center;
    min-height:760px;
}

.sheet-wrap{
    position:relative;
    display:flex;
    align-items:center;
    justify-content:center;
    width:100%;
}

.sheet{
    position:relative;
    width:min(100%, 520px);
    aspect-ratio:var(--page-ratio);
    background:transparent;
    overflow:visible;
}

.sheet canvas{
    width:100%;
    height:100%;
    display:block;
    object-fit:contain;
    border-radius:2px;
    box-shadow:
        0 18px 38px rgba(0,0,0,.16),
        0 4px 10px rgba(0,0,0,.10);
    background:#fff;
}

.sheet.left canvas{
    box-shadow:
        -10px 18px 38px rgba(0,0,0,.16),
        -2px 4px 10px rgba(0,0,0,.08);
}

.sheet.right canvas{
    box-shadow:
        10px 18px 38px rgba(0,0,0,.16),
        2px 4px 10px rgba(0,0,0,.08);
}

.single-cover .sheet canvas{
    box-shadow:
        0 18px 38px rgba(0,0,0,.18),
        0 4px 10px rgba(0,0,0,.10);
}

.page-shadow-center{
    position:absolute;
    top:2%;
    bottom:8%;
    left:50%;
    width:30px;
    transform:translateX(-50%);
    background:radial-gradient(ellipse at center, rgba(0,0,0,.16) 0%, rgba(0,0,0,.07) 28%, rgba(0,0,0,0) 72%);
    pointer-events:none;
    z-index:0;
}

.listen-btn{
    position:absolute;
    top:8px;
    right:14px;
    border:none;
    border-radius:999px;
    color:#fff;
    background:#16a34a;
    padding:10px 16px;
    font-weight:700;
    cursor:pointer;
    z-index:30;
    box-shadow:0 8px 18px rgba(0,0,0,.14);
}

.listen-btn.disabled{
    background:#94a3b8;
    cursor:not-allowed;
}

.corner-arrow{
    position:absolute;
    bottom:12px;
    width:54px;
    height:54px;
    border:none;
    background:transparent;
    color:#4b5563;
    font-size:44px;
    line-height:1;
    cursor:pointer;
    z-index:30;
    transition:transform .18s ease, opacity .18s ease;
    text-shadow:0 4px 10px rgba(0,0,0,.18);
}

.corner-arrow:hover{
    transform:scale(1.06);
}

.corner-arrow.prev{
    left:0;
}

.corner-arrow.next{
    right:0;
}

.corner-arrow.hidden{
    opacity:0;
    pointer-events:none;
}

.page-turn-hint{
    position:absolute;
    bottom:36px;
    width:34px;
    height:34px;
    border-right:2px solid rgba(0,0,0,.28);
    border-bottom:2px solid rgba(0,0,0,.28);
    border-radius:0 0 8px 0;
    transform:rotate(-45deg);
    opacity:.75;
    pointer-events:none;
    z-index:20;
}

.page-turn-hint.prev{
    left:18px;
    transform:rotate(135deg);
}

.page-turn-hint.next{
    right:18px;
    transform:rotate(-45deg);
}

.loading{
    color:#334155;
    font-weight:bold;
    padding:30px 0;
}

.error{
    color:#dc2626;
    font-weight:bold;
    padding:20px 0;
}

@media (max-width: 1024px){
    .spread{
        gap:14px;
    }

    .sheet{
        width:min(100%, 470px);
    }
}

@media (max-width: 900px){
    .book-view,
    .spread,
    .single-cover{
        min-height:680px;
    }

    .spread{
        grid-template-columns:1fr;
        gap:0;
    }

    .spread .sheet-wrap:first-child{
        display:none;
    }

    .page-shadow-center{
        display:none;
    }

    .sheet{
        width:min(100%, 540px);
    }
}

@media (max-width: 640px){
    .book-view,
    .spread,
    .single-cover{
        min-height:520px;
    }

    .sheet{
        width:min(100%, 380px);
    }

    .corner-arrow{
        width:46px;
        height:46px;
        font-size:36px;
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

        <div class="book-view" id="bookView" style="display:none;"></div>

        <button class="corner-arrow prev" id="prevBtn" type="button" aria-label="Previous page">‹</button>
        <button class="corner-arrow next" id="nextBtn" type="button" aria-label="Next page">›</button>

        <div class="page-turn-hint prev" id="prevHint"></div>
        <div class="page-turn-hint next" id="nextHint"></div>
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
const bookView = document.getElementById('bookView');
const prevBtn = document.getElementById('prevBtn');
const nextBtn = document.getElementById('nextBtn');
const prevHint = document.getElementById('prevHint');
const nextHint = document.getElementById('nextHint');

let pdfDoc = null;
let realPdfPages = 0;
let virtualPageCount = 0;
let currentViewStart = 1;
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

function getRealPageFromVirtual(virtualPage) {
    if (virtualPage <= 1) return null;
    if (virtualPage >= virtualPageCount) return null;
    return virtualPage - 1;
}

function isCoverPage(virtualPage) {
    return virtualPage === 1;
}

function isBackCoverPage(virtualPage) {
    return virtualPage === virtualPageCount;
}

function getTextForVirtualPage(virtualPage) {
    const realPage = getRealPageFromVirtual(virtualPage);
    if (!realPage) return '';

    const idx = realPage - 1;
    if (idx >= 0 && idx < pageTexts.length && pageTexts[idx]) {
        return String(pageTexts[idx]);
    }
    return '';
}

function updateIndicator() {
    if (isSinglePageMode()) {
        pageIndicator.textContent = 'Page ' + currentViewStart + ' / ' + virtualPageCount;
        return;
    }

    if (isCoverPage(currentViewStart) || isBackCoverPage(currentViewStart)) {
        pageIndicator.textContent = 'Page ' + currentViewStart + ' / ' + virtualPageCount;
        return;
    }

    const second = Math.min(currentViewStart + 1, virtualPageCount);
    pageIndicator.textContent = 'Pages ' + currentViewStart + ' - ' + second + ' / ' + virtualPageCount;
}

function updateNavState() {
    prevBtn.classList.toggle('hidden', currentViewStart <= 1);
    prevHint.style.display = currentViewStart <= 1 ? 'none' : 'block';

    nextBtn.classList.toggle('hidden', currentViewStart >= virtualPageCount);
    nextHint.style.display = currentViewStart >= virtualPageCount ? 'none' : 'block';
}

function buildSheetShell(sideClass) {
    const wrap = document.createElement('div');
    wrap.className = 'sheet-wrap';

    const sheet = document.createElement('div');
    sheet.className = 'sheet ' + sideClass;

    wrap.appendChild(sheet);
    return { wrap, sheet };
}

function addCanvasPageNumber(sheet, pageNumber) {
    const badge = document.createElement('div');
    badge.className = 'page-number';
    badge.textContent = 'Page ' + pageNumber;
    sheet.appendChild(badge);
}

async function getRenderedCanvas(realPageNumber, targetWidth, targetHeight) {
    let cacheKey = realPageNumber + '_' + targetWidth + 'x' + targetHeight;
    if (renderedCache[cacheKey]) {
        return renderedCache[cacheKey];
    }

    const page = await pdfDoc.getPage(realPageNumber);
    const baseViewport = page.getViewport({ scale: 1 });
    const scale = Math.min(targetWidth / baseViewport.width, targetHeight / baseViewport.height);
    const viewport = page.getViewport({ scale: scale });

    const canvas = document.createElement('canvas');
    const ctx = canvas.getContext('2d');
    canvas.width = viewport.width;
    canvas.height = viewport.height;

    await page.render({ canvasContext: ctx, viewport: viewport }).promise;
    renderedCache[cacheKey] = canvas;
    return canvas;
}

async function fillSheetWithVirtualPage(sheet, virtualPageNumber, sideClass) {
    sheet.innerHTML = '';

    const rect = sheet.getBoundingClientRect();
    const targetWidth = Math.max(240, Math.floor(rect.width));
    const targetHeight = Math.max(320, Math.floor(rect.height));

    if (isCoverPage(virtualPageNumber)) {
        const canvas = await getRenderedCanvas(1, targetWidth, targetHeight);
        const clone = document.createElement('canvas');
        clone.width = canvas.width;
        clone.height = canvas.height;
        clone.getContext('2d').drawImage(canvas, 0, 0);
        sheet.appendChild(clone);
        addCanvasPageNumber(sheet, virtualPageNumber);
        return;
    }

    if (isBackCoverPage(virtualPageNumber)) {
        const canvas = await getRenderedCanvas(realPdfPages, targetWidth, targetHeight);
        const clone = document.createElement('canvas');
        clone.width = canvas.width;
        clone.height = canvas.height;
        clone.getContext('2d').drawImage(canvas, 0, 0);
        sheet.appendChild(clone);
        addCanvasPageNumber(sheet, virtualPageNumber);
        return;
    }

    const realPage = getRealPageFromVirtual(virtualPageNumber);
    if (!realPage) return;

    const canvas = await getRenderedCanvas(realPage, targetWidth, targetHeight);
    const clone = document.createElement('canvas');
    clone.width = canvas.width;
    clone.height = canvas.height;
    clone.getContext('2d').drawImage(canvas, 0, 0);
    sheet.appendChild(clone);
    addCanvasPageNumber(sheet, virtualPageNumber);
}

async function renderCurrentView() {
    bookView.innerHTML = '';
    updateIndicator();
    updateNavState();

    if (isSinglePageMode()) {
        const single = document.createElement('div');
        single.className = 'single-cover';

        const singleSheet = buildSheetShell('right');
        single.appendChild(singleSheet.wrap);
        bookView.appendChild(single);

        await fillSheetWithVirtualPage(singleSheet.sheet, currentViewStart, 'right');
        return;
    }

    if (isCoverPage(currentViewStart) || isBackCoverPage(currentViewStart)) {
        const single = document.createElement('div');
        single.className = 'single-cover';

        const singleSheet = buildSheetShell('right');
        single.appendChild(singleSheet.wrap);
        bookView.appendChild(single);

        await fillSheetWithVirtualPage(singleSheet.sheet, currentViewStart, 'right');
        return;
    }

    const spread = document.createElement('div');
    spread.className = 'spread';

    const centerShadow = document.createElement('div');
    centerShadow.className = 'page-shadow-center';
    spread.appendChild(centerShadow);

    const leftSheet = buildSheetShell('left');
    const rightSheet = buildSheetShell('right');

    spread.appendChild(leftSheet.wrap);
    spread.appendChild(rightSheet.wrap);
    bookView.appendChild(spread);

    await fillSheetWithVirtualPage(leftSheet.sheet, currentViewStart, 'left');
    await fillSheetWithVirtualPage(rightSheet.sheet, currentViewStart + 1, 'right');
}

function getNextStart(current) {
    if (isSinglePageMode()) {
        return Math.min(current + 1, virtualPageCount);
    }

    if (isCoverPage(current)) {
        return 2;
    }

    if (current + 2 >= virtualPageCount) {
        return virtualPageCount;
    }

    return current + 2;
}

function getPrevStart(current) {
    if (isSinglePageMode()) {
        return Math.max(current - 1, 1);
    }

    if (isBackCoverPage(current)) {
        const prev = virtualPageCount - 2;
        return prev >= 2 ? prev : 1;
    }

    if (current <= 2) {
        return 1;
    }

    return current - 2;
}

function goNext() {
    const next = getNextStart(currentViewStart);
    if (next !== currentViewStart) {
        currentViewStart = next;
        safePlayFlipSound();
        renderCurrentView();
    }
}

function goPrev() {
    const prev = getPrevStart(currentViewStart);
    if (prev !== currentViewStart) {
        currentViewStart = prev;
        safePlayFlipSound();
        renderCurrentView();
    }
}

prevBtn.addEventListener('click', goPrev);
nextBtn.addEventListener('click', goNext);

if (listenEnabledGlobal) {
    listenBtn.addEventListener('click', function () {
        try {
            speechSynthesis.cancel();

            let text = getTextForVirtualPage(currentViewStart);

            if (!isSinglePageMode() && !isCoverPage(currentViewStart) && !isBackCoverPage(currentViewStart)) {
                const secondText = getTextForVirtualPage(currentViewStart + 1);
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
        renderCurrentView();
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
        bookView.style.display = 'block';
        currentViewStart = 1;

        return renderCurrentView();
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
