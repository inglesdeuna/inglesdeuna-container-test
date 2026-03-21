<?php
require_once __DIR__ . '/../../config/db.php';

$activityId = isset($_GET['id']) ? trim((string)$_GET['id']) : '';
$unit = isset($_GET['unit']) ? trim((string)$_GET['unit']) : '';

if ($activityId === '') {
    die('ID de actividad no especificado');
}

// 1. Cargar datos de la actividad
$stmt = $pdo->prepare("SELECT * FROM activities WHERE id = :id LIMIT 1");
$stmt->execute(['id' => $activityId]);
$activity = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$activity) {
    die('Actividad no encontrada');
}

// Decodificar JSON de la columna 'data'
$data = json_decode($activity['data'] ?? '', true) ?: [];
$pdfUrl = $data['pdf_url'] ?? '';
$pageTexts = $data['page_texts'] ?? [];
$listenEnabled = $data['listen_enabled'] ?? true;
$language = $data['language'] ?? 'en-US';

// Si no hay PDF, mostrar mensaje amigable
if (empty($pdfUrl)) {
    die('<div style="text-align:center; padding:50px; color:#666;"><h3><i class="fas fa-file-pdf"></i> No hay un PDF cargado aún.</h3><p>Por favor, usa el editor para subir un archivo.</p></div>');
}

// Incluir el template base del viewer (Header/Layout general)
include __DIR__ . '/../../core/_activity_viewer_template.php';
?>

<!-- LIBRERÍAS ESPECÍFICAS PARA EL FLIPBOOK -->
<script src="https://cdnjs.cloudflare.com"></script>
<script src="https://cdn.rawgit.com"></script>

<style>
    #flipbook-container { 
        width: 100%; 
        max-width: 900px; 
        margin: 0 auto; 
        position: relative;
        display: flex;
        flex-direction: column;
        align-items: center;
    }
    
    #flipbook { display: none; box-shadow: 0 10px 30px rgba(0,0,0,0.2); }
    
    .page-canvas { background: white; width: 100%; height: 100%; }

    /* Barra estilo Heyzine unificada */
    .viewer-toolbar {
        margin-top: 20px;
        background: #333;
        padding: 8px 20px;
        border-radius: 40px;
        display: flex;
        align-items: center;
        gap: 15px;
        color: white;
        box-shadow: 0 4px 10px rgba(0,0,0,0.3);
    }

    .viewer-toolbar button {
        background: none;
        border: none;
        color: white;
        font-size: 1.1rem;
        cursor: pointer;
        transition: 0.2s;
    }

    .viewer-toolbar button:hover { color: #00d4ff; }
    .viewer-toolbar .page-info { font-size: 0.9rem; border-left: 1px solid #555; border-right: 1px solid #555; padding: 0 15px; }
    
    #loading-flipbook { padding: 40px; text-align: center; color: #555; }
</style>

<div id="flipbook-container">
    <div id="loading-flipbook">
        <i class="fas fa-spinner fa-spin fa-3x mb-3" style="color:#00d4ff;"></i>
        <p>Cargando libro interactivo...</p>
    </div>

    <!-- El Flipbook -->
    <div id="flipbook"></div>

    <!-- Barra de Herramientas -->
    <div class="viewer-toolbar" id="main-toolbar" style="display:none;">
        <button id="prev-btn"><i class="fas fa-chevron-left"></i></button>
        
        <div class="page-info">
            Página <span id="current-page">1</span> / <span id="total-pages">0</span>
        </div>

        <button id="next-btn"><i class="fas fa-chevron-right"></i></button>
        
        <?php if ($listenEnabled): ?>
        <button id="listen-btn" title="Listen Page"><i class="fas fa-volume-up"></i></button>
        <?php endif; ?>
        
        <button id="full-screen-btn"><i class="fas fa-expand"></i></button>
    </div>
</div>

<audio id="flip-sound" src="https://www.soundjay.com"></audio>

<script>
$(document).ready(function() {
    const pdfUrl = '<?php echo $pdfUrl; ?>';
    const pageTexts = <?php echo json_encode($pageTexts); ?>;
    const voiceLang = '<?php echo $language; ?>';
    
    const pdfjsLib = window['pdfjs-dist/build/pdf'];
    pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com';

    async function initFlipbook() {
        try {
            const loadingTask = pdfjsLib.getDocument(pdfUrl);
            const pdf = await loadingTask.promise;
            const $flipbook = $('#flipbook');
            const totalPages = pdf.numPages;
            $('#total-pages').text(totalPages);

            // Renderizado de páginas
            for (let i = 1; i <= totalPages; i++) {
                const page = await pdf.getPage(i);
                const viewport = page.getViewport({ scale: 1.5 });
                
                const pageDiv = $('<div class="page"></div>');
                const canvas = document.createElement('canvas');
                canvas.className = 'page-canvas';
                const context = canvas.getContext('2d');
                canvas.height = viewport.height;
                canvas.width = viewport.width;

                await page.render({ canvasContext: context, viewport: viewport }).promise;
                pageDiv.append(canvas);
                $flipbook.append(pageDiv);
            }

            // Inicializar Turn.js
            $flipbook.show().turn({
                width: 850,
                height: 550,
                autoCenter: true,
                acceleration: true,
                gradients: true,
                when: {
                    turning: function(e, page) {
                        $('#current-page').text(page);
                        document.getElementById('flip-sound').play();
                        stopSpeaking(); // Detener audio si cambia de página
                    }
                }
            });

            $('#loading-flipbook').hide();
            $('#main-toolbar').css('display', 'flex');

        } catch (err) {
            console.error(err);
            $('#loading-flipbook').html('<p class="text-danger">Error al cargar el PDF.</p>');
        }
    }

    // Navegación
    $('#prev-btn').click(() => $('#flipbook').turn('previous'));
    $('#next-btn').click(() => $('#flipbook').turn('next'));

    // Función Listen (Text-to-Speech)
    function stopSpeaking() { window.speechSynthesis.cancel(); }
    
    $('#listen-btn').click(function() {
        stopSpeaking();
        const currentPage = $('#flipbook').turn('page');
        const text = pageTexts[currentPage - 1]; // Los arrays en JS empiezan en 0

        if (text) {
            const msg = new SpeechSynthesisUtterance();
            msg.text = text;
            msg.lang = voiceLang;
            window.speechSynthesis.speak(msg);
            
            // Efecto visual en el botón
            $(this).addClass('text-info');
            msg.onend = () => $(this).removeClass('text-info');
        } else {
            console.log("No hay texto definido para esta página.");
        }
    });

    // Pantalla Completa
    $('#full-screen-btn').click(function() {
        const elem = document.getElementById('flipbook-container');
        if (!document.fullscreenElement) {
            elem.requestFullscreen();
            $(this).html('<i class="fas fa-compress"></i>');
        } else {
            document.exitFullscreen();
            $(this).html('<i class="fas fa-expand"></i>');
        }
    });

    initFlipbook();
});
</script>

<?php 
// Opcional: Footer si tu sistema lo usa
if (file_exists(__DIR__ . '/../../core/_activity_viewer_footer.php')) {
    include __DIR__ . '/../../core/_activity_viewer_footer.php';
}
?>
