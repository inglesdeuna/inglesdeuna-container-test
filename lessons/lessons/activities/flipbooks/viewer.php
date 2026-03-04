<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../core/_activity_viewer_template.php';

$unit = isset($_GET['unit']) ? $_GET['unit'] : null;
if (!$unit) {
    die('Unidad no especificada');
}

$stmt = $pdo->prepare(
    "SELECT data
     FROM activities
     WHERE unit_id = :unit
       AND type = 'flipbooks'
     LIMIT 1"
);
$stmt->execute(array('unit' => $unit));
$row = $stmt->fetch(PDO::FETCH_ASSOC);

$raw = isset($row['data']) ? $row['data'] : '{}';
$decoded = json_decode($raw, true);
$flipbook = is_array($decoded) ? $decoded : array();

$pdfUrl = isset($flipbook['pdf_url']) ? trim((string) $flipbook['pdf_url']) : '';
$title = isset($flipbook['title']) && trim((string) $flipbook['title']) !== ''
    ? trim((string) $flipbook['title'])
    : 'Flipbook';
$language = isset($flipbook['language']) && trim((string) $flipbook['language']) !== ''
    ? trim((string) $flipbook['language'])
    : 'en-US';
$listenEnabled = !isset($flipbook['listen_enabled']) || (bool) $flipbook['listen_enabled'];
$pageTexts = isset($flipbook['page_texts']) && is_array($flipbook['page_texts']) ? $flipbook['page_texts'] : array();

if ($pdfUrl === '') {
    die('No hay flipbook para esta unidad');
}

ob_start();
?>
<style>
.flipbook-wrap{ max-width:1100px; margin:0 auto; text-align:center; }

.book-header{ margin-bottom:14px; }
.book-title{ font-size:24px; color:#1d4ed8; font-weight:bold; margin:0 0 8px 0; }
.page-indicator{ color:#475569; font-size:14px; }

.toolbar{
  display:flex;
  justify-content:center;
  align-items:center;
  gap:10px;
  flex-wrap:wrap;
  margin-bottom:14px;
}

.toolbar button{
  border:none;
  border-radius:10px;
  padding:10px 14px;
  font-weight:bold;
  cursor:pointer;
  color:white;
}

.btn-nav{ background:#0b5ed7; }
.btn-listen{ background:#16a34a; }
.btn-listen.disabled{ background:#94a3b8; cursor:not-allowed; }

#book-shell{
  position:relative;
  margin:0 auto;
  width:980px;
  max-width:100%;
}

#flipbook{
  width:980px;
  height:680px;
  margin:0 auto;
}

.flip-page{
  background:#fff;
  overflow:hidden;
}

.flip-page canvas{
  width:100%;
  height:100%;
  display:block;
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
  #flipbook{ height:560px; }
}
</style>

<div class="flipbook-wrap">
  <div class="book-header">
    <p class="book-title">📖 <?= htmlspecialchars($title) ?></p>
    <div class="page-indicator" id="pageIndicator">Page 1</div>
  </div>

  <div class="toolbar">
    <button class="btn-nav" id="prevBtn">⬅ Prev</button>
    <button class="btn-listen <?= $listenEnabled ? '' : 'disabled' ?>" id="listenBtn" <?= $listenEnabled ? '' : 'disabled' ?>>🔊 Listen</button>
    <button class="btn-nav" id="nextBtn">Next ➡</button>
  </div>

  <div id="book-shell">
    <div id="flipbook"></div>
    <div id="status" class="loading">Loading book...</div>
  </div>
</div>

<audio id="pageFlipSound" preload="auto" src="./sounds/page-flip.mp3"></audio>

<script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.6.0/jquery.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/turn.js/4.1.0/turn.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/2.16.105/pdf.min.js"></script>

<script>
const pdfUrl = <?= json_encode($pdfUrl, JSON_UNESCAPED_UNICODE) ?>;
const pageTexts = <?= json_encode($pageTexts, JSON_UNESCAPED_UNICODE) ?>;
const listenEnabledGlobal = <?= $listenEnabled ? 'true' : 'false' ?>;
const speechLang = <?= json_encode($language, JSON_UNESCAPED_UNICODE) ?>;

const statusEl = document.getElementById('status');
const pageIndicator = document.getElementById('pageIndicator');
const listenBtn = document.getElementById('listenBtn');
const flipSound = document.getElementById('pageFlipSound');

let totalPages = 0;
let currentPage = 1;

function updatePageIndicator(page) {
  currentPage = page;
  pageIndicator.textContent = 'Page ' + page + ' / ' + totalPages;
}

function getTextForPage(page) {
  const idx = page - 1;
  if (idx >= 0 && idx < pageTexts.length && pageTexts[idx]) {
    return String(pageTexts[idx]);
  }
  return 'Page ' + page;
}

function safePlayFlipSound() {
  if (!flipSound) return;
  try {
    flipSound.currentTime = 0;
    flipSound.play().catch(function () {});
  } catch (e) {}
}

function initTurnBook() {
  const $book = $('#flipbook');

  $book.turn({
    width: 980,
    height: 680,
    autoCenter: true,
    elevation: 50,
    gradients: true,
    display: 'double',
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

function renderPdfPages(pdf) {
  totalPages = pdf.numPages;
  const flipbook = document.getElementById('flipbook');

  const pagePromises = [];

  for (let pageNum = 1; pageNum <= totalPages; pageNum += 1) {
    const promise = pdf.getPage(pageNum).then(function (page) {
      const pageDiv = document.createElement('div');
      pageDiv.className = 'flip-page';

      const canvas = document.createElement('canvas');
      pageDiv.appendChild(canvas);
      flipbook.appendChild(pageDiv);

      const baseViewport = page.getViewport({ scale: 1 });
      const targetWidth = 490;
      const targetHeight = 680;
      const scale = Math.min(targetWidth / baseViewport.width, targetHeight / baseViewport.height);
      const viewport = page.getViewport({ scale: scale });

      const ctx = canvas.getContext('2d');
      canvas.width = viewport.width;
      canvas.height = viewport.height;

      const renderTask = page.render({
        canvasContext: ctx,
        viewport: viewport,
      });

      return renderTask.promise;
    });

    pagePromises.push(promise);
  }

  return Promise.all(pagePromises);
}

if (listenEnabledGlobal) {
  listenBtn.addEventListener('click', function () {
    speechSynthesis.cancel();
    const text = getTextForPage(currentPage);
    if (!text) return;

    const utter = new SpeechSynthesisUtterance(text);
    utter.lang = speechLang || 'en-US';
    utter.rate = 0.9;
    speechSynthesis.speak(utter);
  });
}

pdfjsLib.getDocument(pdfUrl).promise
  .then(function (pdf) {
    return renderPdfPages(pdf);
  })
  .then(function () {
    statusEl.style.display = 'none';
    initTurnBook();
  })
  .catch(function (error) {
    statusEl.className = 'error';
    statusEl.textContent = 'No se pudo cargar el flipbook PDF.';
    console.error(error);
  });
</script>
<?php
$content = ob_get_clean();
render_activity_viewer('Flipbook', '📖', $content);
