<?php
require_once __DIR__ . '/dot_to_dot_functions.php';
require_once __DIR__ . '/../../config/db.php';

$unit = isset($_GET['unit']) ? trim((string) $_GET['unit']) : '';
$id   = isset($_GET['id'])   ? trim((string) $_GET['id'])   : '';

$title = '';
$instruction = '';
$image = '';
$points = [];
$canvasWidth = 320;
$canvasHeight = 320;

if ($id !== '') {
    $activity = load_dot_to_dot_activity($pdo, $unit, $id);

    $title       = (string)($activity['title'] ?? '');
    $instruction = (string)($activity['instruction'] ?? '');
    $image       = (string)($activity['image'] ?? '');
    $points      = $activity['points'] ?? [];
    $canvasWidth = (int)($activity['canvas_width'] ?? 320);
    $canvasHeight = (int)($activity['canvas_height'] ?? 320);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title       = trim((string)($_POST['title'] ?? ''));
    $instruction = trim((string)($_POST['instruction'] ?? ''));
    $image       = trim((string)($_POST['image_data'] ?? ''));
    $pointsJson  = trim((string)($_POST['points'] ?? '[]'));

    $canvasWidth  = (int)($_POST['canvas_width'] ?? 320);
    $canvasHeight = (int)($_POST['canvas_height'] ?? 320);

    $points = json_decode($pointsJson, true);

    if (!is_array($points)) {
        $points = [];
    }

    $payload = [
        'title' => $title,
        'instruction' => $instruction,
        'image' => $image,
        'points' => $points,
        'canvas_width' => $canvasWidth,
        'canvas_height' => $canvasHeight,
    ];

    $savedId = save_dot_to_dot_activity($pdo, $unit, $id, $payload);

    header('Location: viewer.php?id=' . urlencode((string)$savedId) . '&unit=' . urlencode($unit));
    exit;
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Dot to Dot Editor</title>

    <style>
        body {
            margin: 0;
            background: #f3f4f6;
            font-family: system-ui, sans-serif;
            color: #111827;
        }

        .editor-page {
            max-width: 1050px;
            margin: 30px auto;
            padding: 24px;
        }

        .editor-card {
            background: #ffffff;
            border-radius: 22px;
            padding: 28px;
            box-shadow: 0 18px 45px rgba(15, 23, 42, 0.12);
        }

        h1 {
            margin-top: 0;
            font-size: 32px;
            color: #111827;
        }

        .form-grid {
            display: grid;
            grid-template-columns: 1fr 420px;
            gap: 28px;
            align-items: start;
        }

        label {
            display: block;
            font-weight: 700;
            margin-bottom: 8px;
            color: #374151;
        }

        input[type="text"],
        textarea,
        input[type="file"] {
            width: 100%;
            box-sizing: border-box;
            border: 1px solid #d1d5db;
            border-radius: 14px;
            padding: 12px 14px;
            font: inherit;
            background: #ffffff;
        }

        textarea {
            min-height: 110px;
            resize: vertical;
        }

        .field {
            margin-bottom: 18px;
        }

        .preview-box {
            background: #f8fafc;
            border: 1px solid #e5e7eb;
            border-radius: 20px;
            padding: 18px;
            text-align: center;
        }

        .canvas-shell {
            display: inline-block;
            border-radius: 18px;
            overflow: hidden;
            background: #e5e7eb;
            border: 1px solid #cbd5e1;
        }

        canvas {
            display: block;
            cursor: crosshair;
            touch-action: none;
        }

        .actions {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            margin-top: 22px;
        }

        button,
        .button-link {
            border: 0;
            border-radius: 999px;
            padding: 12px 20px;
            font-weight: 800;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 15px;
        }

        .btn-primary {
            background: #2563eb;
            color: white;
        }

        .btn-soft {
            background: #e5e7eb;
            color: #111827;
        }

        .btn-danger {
            background: #fee2e2;
            color: #991b1b;
        }

        .help {
            font-size: 14px;
            color: #6b7280;
            line-height: 1.5;
            margin-top: 12px;
        }

        @media (max-width: 850px) {
            .form-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>

<body>
    <main class="editor-page">
        <form class="editor-card" method="post">
            <h1>Dot to Dot Editor</h1>

            <div class="form-grid">
                <section>
                    <div class="field">
                        <label for="title">Título</label>
                        <input
                            type="text"
                            id="title"
                            name="title"
                            value="<?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?>"
                            placeholder="Dot to Dot Activity"
                        >
                    </div>

                    <div class="field">
                        <label for="instruction">Instrucción</label>
                        <textarea
                            id="instruction"
                            name="instruction"
                            placeholder="Connect the dots in order to reveal the picture."
                        ><?= htmlspecialchars($instruction, ENT_QUOTES, 'UTF-8') ?></textarea>
                    </div>

                    <div class="field">
                        <label for="image">Imagen</label>
                        <input type="file" id="image" accept="image/*">
                    </div>

                    <input type="hidden" name="image_data" id="image_data">
                    <input type="hidden" name="points" id="points">
                    <input type="hidden" name="canvas_width" id="canvas_width">
                    <input type="hidden" name="canvas_height" id="canvas_height">

                    <div class="actions">
                        <button type="submit" class="btn-primary">
                            Guardar actividad
                        </button>

                        <button type="button" id="undoPoint" class="btn-soft">
                            Deshacer punto
                        </button>

                        <button type="button" id="clearPoints" class="btn-danger">
                            Borrar puntos
                        </button>
                    </div>

                    <p class="help">
                        Sube una imagen y haz clic sobre ella para marcar los puntos en orden.
                        El viewer aparecerá después de guardar.
                    </p>
                </section>

                <section class="preview-box">
                    <label>Vista del editor</label>

                    <div class="canvas-shell">
                        <canvas id="dotCanvas"></canvas>
                    </div>

                    <p class="help">
                        Línea continua: orden de conexión.<br>
                        Línea punteada: cierre del último punto al primero.
                    </p>
                </section>
            </div>
        </form>
    </main>

    <script>
        const imageInput = document.getElementById("image");
        const canvas = document.getElementById("dotCanvas");
        const ctx = canvas.getContext("2d");

        const pointsInput = document.getElementById("points");
        const imageDataInput = document.getElementById("image_data");
        const canvasWidthInput = document.getElementById("canvas_width");
        const canvasHeightInput = document.getElementById("canvas_height");

        const undoBtn = document.getElementById("undoPoint");
        const clearBtn = document.getElementById("clearPoints");

        let uploadedImage = null;
        let uploadedImageData = <?= json_encode($image, JSON_UNESCAPED_UNICODE) ?>;
        let points = <?= json_encode(array_values($points), JSON_UNESCAPED_UNICODE) ?>;

        canvas.width = <?= json_encode($canvasWidth) ?>;
        canvas.height = <?= json_encode($canvasHeight) ?>;
        canvas.style.width = canvas.width + "px";
        canvas.style.height = canvas.height + "px";

        function resizeCanvasToImage() {
            if (!uploadedImage) return;

            const maxWidth = 380;
            const maxHeight = 380;
            const imageRatio = uploadedImage.width / uploadedImage.height;

            let width = maxWidth;
            let height = maxWidth / imageRatio;

            if (height > maxHeight) {
                height = maxHeight;
                width = maxHeight * imageRatio;
            }

            canvas.width = Math.round(width);
            canvas.height = Math.round(height);
            canvas.style.width = Math.round(width) + "px";
            canvas.style.height = Math.round(height) + "px";
        }

        function getPointerPosition(event) {
            const rect = canvas.getBoundingClientRect();

            let clientX;
            let clientY;

            if (event.touches && event.touches.length > 0) {
                clientX = event.touches[0].clientX;
                clientY = event.touches[0].clientY;
            } else if (event.changedTouches && event.changedTouches.length > 0) {
                clientX = event.changedTouches[0].clientX;
                clientY = event.changedTouches[0].clientY;
            } else {
                clientX = event.clientX;
                clientY = event.clientY;
            }

            return {
                x: Math.round((clientX - rect.left) * (canvas.width / rect.width)),
                y: Math.round((clientY - rect.top) * (canvas.height / rect.height))
            };
        }

        function syncHiddenInputs() {
            pointsInput.value = JSON.stringify(points);
            imageDataInput.value = uploadedImageData;
            canvasWidthInput.value = canvas.width;
            canvasHeightInput.value = canvas.height;
        }

        function drawEmptyCanvas() {
            ctx.clearRect(0, 0, canvas.width, canvas.height);

            ctx.fillStyle = "#f8fafc";
            ctx.fillRect(0, 0, canvas.width, canvas.height);

            ctx.fillStyle = "#64748b";
            ctx.font = "14px system-ui";
            ctx.textAlign = "center";
            ctx.textBaseline = "middle";
            ctx.fillText("Upload an image first", canvas.width / 2, canvas.height / 2);
        }

        function drawImage() {
            if (!uploadedImage) {
                drawEmptyCanvas();
                return;
            }

            ctx.clearRect(0, 0, canvas.width, canvas.height);
            ctx.drawImage(uploadedImage, 0, 0, canvas.width, canvas.height);

            ctx.fillStyle = "rgba(255,255,255,0.45)";
            ctx.fillRect(0, 0, canvas.width, canvas.height);
        }

        function drawEditorLines() {
            if (points.length < 2) return;

            ctx.strokeStyle = "#38bdf8";
            ctx.lineWidth = 3;
            ctx.lineCap = "round";
            ctx.lineJoin = "round";

            for (let i = 0; i < points.length - 1; i++) {
                ctx.beginPath();
                ctx.moveTo(points[i].x, points[i].y);
                ctx.lineTo(points[i + 1].x, points[i + 1].y);
                ctx.stroke();
            }

            if (points.length > 2) {
                ctx.save();
                ctx.setLineDash([7, 7]);
                ctx.beginPath();
                ctx.moveTo(points[points.length - 1].x, points[points.length - 1].y);
                ctx.lineTo(points[0].x, points[0].y);
                ctx.stroke();
                ctx.restore();
            }
        }

        function drawEditorDots() {
            points.forEach((point, index) => {
                ctx.beginPath();
                ctx.arc(point.x, point.y, 13, 0, Math.PI * 2);
                ctx.fillStyle = "#ffffff";
                ctx.fill();

                ctx.strokeStyle = "#2563eb";
                ctx.lineWidth = 3;
                ctx.stroke();

                ctx.fillStyle = "#1d4ed8";
                ctx.font = "bold 12px system-ui";
                ctx.textAlign = "center";
                ctx.textBaseline = "middle";
                ctx.fillText(index + 1, point.x, point.y);
            });
        }

        function renderEditor() {
            drawImage();
            drawEditorLines();
            drawEditorDots();
            syncHiddenInputs();
        }

        function loadExistingImage() {
            if (!uploadedImageData) {
                drawEmptyCanvas();
                syncHiddenInputs();
                return;
            }

            uploadedImage = new Image();

            uploadedImage.onload = function () {
                renderEditor();
            };

            uploadedImage.src = uploadedImageData;
        }

        imageInput.addEventListener("change", function () {
            const file = imageInput.files[0];

            if (!file) return;

            const reader = new FileReader();

            reader.onload = function (event) {
                uploadedImageData = event.target.result;
                uploadedImage = new Image();

                uploadedImage.onload = function () {
                    points = [];
                    resizeCanvasToImage();
                    renderEditor();
                };

                uploadedImage.src = uploadedImageData;
            };

            reader.readAsDataURL(file);
        });

        canvas.addEventListener("click", function (event) {
            if (!uploadedImage) return;

            const point = getPointerPosition(event);

            points.push({
                x: point.x,
                y: point.y
            });

            renderEditor();
        });

        canvas.addEventListener("touchstart", function (event) {
            event.preventDefault();

            if (!uploadedImage) return;

            const point = getPointerPosition(event);

            points.push({
                x: point.x,
                y: point.y
            });

            renderEditor();
        }, { passive: false });

        undoBtn.addEventListener("click", function () {
            points.pop();
            renderEditor();
        });

        clearBtn.addEventListener("click", function () {
            points = [];
            renderEditor();
        });

        loadExistingImage();
    </script>
</body>
</html>
