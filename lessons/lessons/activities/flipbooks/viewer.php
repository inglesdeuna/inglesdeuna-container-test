<?php
session_start();

require_once __DIR__ . "/../../core/db.php";

/* 1️⃣ DEFINIR UNIT */
$unit = $_GET['unit'] ?? null;
if (!$unit) die("Unidad no especificada");

/* 2️⃣ CONSULTA DB */
$stmt = $pdo->prepare("
    SELECT data 
    FROM activities 
    WHERE unit_id = :unit 
    AND type = 'flipbooks'
    LIMIT 1
");
$stmt->execute(['unit' => $unit]);
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

$pdfPath = "";
if ($row) {
    $decoded = json_decode($row['data'], true);
    $pdfPath = $decoded['pdf'] ?? "";
$raw = isset($row['data']) ? $row['data'] : '{}';
$decoded = json_decode($raw, true);
$flipbook = is_array($decoded) ? $decoded : array();

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

if ($pdfUrl === '') {
    die('No hay flipbook para esta unidad');
}

/* 3️⃣ GENERAR CONTENIDO */
ob_start();
?>
<style>
.flipbook-wrap{ max-width:1100px; margin:0 auto; text-align:center; }
.page-indicator{ color:#475569; font-size:14px; margin-bottom:12px; }

.toolbar{
  display:flex;
  justify-content:center;
  align-items:center;
  gap:10px;
  flex-wrap:wrap;
  margin-bottom:14px;
}

<?php if ($pdfPath): ?>
.toolbar button{
  border:none;
  border-radius:10px;
  padding:10px 14px;
  font-weight:bold;
  cursor:pointer;
  color:white;
}

<div style="position:relative; width:900px; margin:auto;">
    <div id="flipbook" style="width:900px; height:600px;"></div>
.btn-nav{ background:#0b5ed7; }
.btn-listen{ background:#16a34a; }
.btn-listen.disabled{ background:#94a3b8; cursor:not-allowed; }

    <div id="prevBtn" style="position:absolute; bottom:15px; left:10px; cursor:pointer; font-size:28px;">⬅</div>
    <div id="nextBtn" style="position:absolute; bottom:15px; right:10px; cursor:pointer; font-size:28px;">➡</div>
</div>
#book-shell{ position:relative; margin:0 auto; width:980px; max-width:100%; }

<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/turn.js/4.1.0/turn.min.css" />
<script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.6.0/jquery.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/turn.js/4.1.0/turn.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/2.16.105/pdf.min.js"></script>
#flipbook{
  width:980px;
  height:680px;
  margin:0 auto;
  box-shadow:0 10px 30px rgba(0,0,0,.2);
  background:white;
}

<script>
const pdfUrl = "/lessons/lessons/<?= htmlspecialchars($pdfPath) ?>";
.flip-page{
  width:490px;
  height:680px;
  background:#fff;
  overflow:hidden;
  display:flex;
  align-items:center;
  justify-content:center;
}

pdfjsLib.getDocument(pdfUrl).promise.then(function(pdf) {
.flip-page canvas{ width:100%; height:100%; display:block; }

    const flipbook = document.getElementById("flipbook");
    const totalPages = pdf.numPages;
    let renderedPages = 0;
.loading{ color:#334155; font-weight:bold; padding:30px 0; }
.error{ color:#dc2626; font-weight:bold; padding:20px 0; }

    for (let i = 1; i <= totalPages; i++) {
@media (max-width: 1024px){
  #flipbook{ width:100%; height:560px; }
  .flip-page{ width:100%; height:560px; }
}
</style>

        const pageDiv = document.createElement("div");
        pageDiv.style.width = "450px";
        pageDiv.style.height = "600px";
        pageDiv.style.background = "#fff";
<div class="flipbook-wrap">
  <div class="page-indicator" id="pageIndicator">Page 1</div>

        const canvas = document.createElement("canvas");
        pageDiv.appendChild(canvas);
        flipbook.appendChild(pageDiv);
  <div class="toolbar">
    <button class="btn-nav" id="prevBtn">⬅ Prev</button>
    <button class="btn-listen <?= $listenEnabled ? '' : 'disabled' ?>" id="listenBtn" <?= $listenEnabled ? '' : 'disabled' ?>>🔊 Listen</button>
    <button class="btn-nav" id="nextBtn">Next ➡</button>
  </div>

        pdf.getPage(i).then(function(page) {
  <div id="book-shell">
    <div id="flipbook"></div>
    <div id="status" class="loading">Loading book...</div>
  </div>
</div>

            const viewport = page.getViewport({ scale: 1 });
<audio id="pageFlipSound" preload="auto" src="./sounds/page-flip.mp3"></audio>

const scale = 450 / viewport.width;
const scaledViewport = page.getViewport({ scale: scale });
<script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.6.0/jquery.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/turn.js/4.1.0/turn.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/2.16.105/pdf.min.js"></script>

canvas.width = 450;        // FORZAR EXACTO
canvas.height = 600;       // mismo alto que el flipbook
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

function updatePageIndicator(page) {
  currentPage = page;
  pageIndicator.textContent = 'Page ' + page + ' / ' + totalPages;
}

page.render({
    canvasContext: canvas.getContext("2d"),
    viewport: scaledViewport
}).promise.then(function() {
function getTextForPage(page) {
  const idx = page - 1;
  if (idx >= 0 && idx < pageTexts.length && pageTexts[idx]) {
    return String(pageTexts[idx]);
  }
  return 'Page ' + page;
}

                renderedPages++;
function safePlayFlipSound() {
  if (!flipSound) return;
  try {
    flipSound.currentTime = 0;
    flipSound.play().catch(function () {});
  } catch (e) {}
}

                if (renderedPages === totalPages) {
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

                    $("#flipbook").turn({
                        width: 900,
                        height: 600,
                        autoCenter: true,
                        display: "single",
                        elevation: 50,
                        gradients: true,
                        when: {
                            turning: function(event, page) {
                                if (page === 1) {
                                    $(this).turn("display", "single");
                                } else {
                                    $(this).turn("display", "double");
                                }
                            }
                        }
                    });
  document.getElementById('prevBtn').addEventListener('click', function () {
    $book.turn('previous');
  });

                    document.getElementById("prevBtn").onclick = function() {
                        $("#flipbook").turn("previous");
                    };
  document.getElementById('nextBtn').addEventListener('click', function () {
    $book.turn('next');
  });

                    document.getElementById("nextBtn").onclick = function() {
                        $("#flipbook").turn("next");
                    };
  updatePageIndicator(1);
}

                }
function initSimpleFallback() {
  simpleMode = true;
  const pages = Array.prototype.slice.call(flipbookEl.querySelectorAll('.flip-page'));

  pages.forEach(function (p, i) {
    p.style.display = i === 0 ? 'flex' : 'none';
    p.style.width = '100%';
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

            });
function renderPdfPages(pdf) {
  totalPages = pdf.numPages;
  const pagePromises = [];

        });
  for (let pageNum = 1; pageNum <= totalPages; pageNum += 1) {
    const promise = pdf.getPage(pageNum).then(function (page) {
      const pageDiv = document.createElement('div');
      pageDiv.className = 'flip-page';

    }
      const canvas = document.createElement('canvas');
      pageDiv.appendChild(canvas);
      flipbookEl.appendChild(pageDiv);

});
</script>
      const baseViewport = page.getViewport({ scale: 1 });
      const targetWidth = 490;
      const targetHeight = 680;
      const scale = Math.min(targetWidth / baseViewport.width, targetHeight / baseViewport.height);
      const viewport = page.getViewport({ scale: scale });

<?php else: ?>
      const ctx = canvas.getContext('2d');
      canvas.width = viewport.width;
      canvas.height = viewport.height;

<p style="color:#dc2626; font-weight:600; text-align:center;">
    No PDF uploaded for this unit.
</p>
      return page.render({ canvasContext: ctx, viewport: viewport }).promise;
    });

<?php endif; ?>
    pagePromises.push(promise);
  }

<?php
$activityContent = ob_get_clean();
  return Promise.all(pagePromises);
}

/* 4️⃣ VARIABLES PARA TEMPLATE */
$activityTitle = "📖 Flipbooks";
$activitySubtitle = "Let's read together and explore a new story.";
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

/* 5️⃣ TEMPLATE */
require_once __DIR__ . "/../../core/_activity_viewer_template.php";
pdfjsLib.getDocument({ url: pdfUrl, withCredentials: false }).promise
  .then(function (pdf) {
    return renderPdfPages(pdf);
  })
  .then(function () {
    statusEl.style.display = 'none';

    if (window.jQuery && typeof window.jQuery.fn.turn === 'function') {
      initTurnBook();
    } else {
      initSimpleFallback();
    }
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
