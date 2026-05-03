<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Dot to Dot Editor</title>

  <style>
    body {
      margin: 0;
      min-height: 100vh;
      display: grid;
      place-items: center;
      background: linear-gradient(135deg, #0f172a, #312e81);
      font-family: system-ui, sans-serif;
      color: white;
    }

    .app {
      width: min(1000px, 94vw);
      text-align: center;
    }

    h1 {
      margin-bottom: 6px;
      font-size: 42px;
    }

    p {
      opacity: 0.85;
    }

    .toolbar {
      display: flex;
      flex-wrap: wrap;
      justify-content: center;
      gap: 12px;
      margin: 22px 0;
    }

    button,
    .upload-btn {
      border: 0;
      padding: 12px 20px;
      border-radius: 999px;
      background: white;
      color: #312e81;
      font-weight: 800;
      cursor: pointer;
      transition: 0.25s ease;
    }

    button:hover,
    .upload-btn:hover {
      transform: translateY(-2px);
    }

    button.active {
      background: #38bdf8;
      color: #082f49;
    }

    .upload-btn input {
      display: none;
    }

    .game-card {
      position: relative;
      width: 100%;
      aspect-ratio: 8 / 5.5;
      background: rgba(255,255,255,0.1);
      border: 1px solid rgba(255,255,255,0.22);
      border-radius: 28px;
      overflow: hidden;
      box-shadow: 0 25px 70px rgba(0,0,0,0.35);
    }

    canvas {
      width: 100%;
      height: 100%;
      display: block;
      cursor: crosshair;
      touch-action: none;
    }

    .success {
      position: absolute;
      left: 20px;
      right: 20px;
      bottom: 20px;
      padding: 14px 20px;
      border-radius: 18px;
      background: rgba(34,197,94,0.95);
      color: white;
      font-weight: 800;
      opacity: 0;
      transform: translateY(15px);
      transition: 0.4s ease;
      pointer-events: none;
    }

    .success.show {
      opacity: 1;
      transform: translateY(0);
    }

    .instructions {
      margin-top: 18px;
      line-height: 1.6;
      opacity: 0.9;
    }

    textarea {
      width: 100%;
      min-height: 120px;
      margin-top: 20px;
      border-radius: 18px;
      border: 0;
      padding: 15px;
      resize: vertical;
      font-family: monospace;
    }
  </style>
</head>

<body>
  <div class="app">
    <h1>Dot to Dot Editor</h1>
    <p>Sube una imagen, marca los puntos y prueba la actividad.</p>

    <div class="toolbar">
      <label class="upload-btn">
        Subir imagen
        <input type="file" id="imageUpload" accept="image/*" />
      </label>

      <button id="editorBtn" class="active">Editor</button>
      <button id="viewerBtn">Viewer</button>
      <button id="undoDot">Deshacer punto</button>
      <button id="clearDots">Borrar puntos</button>
      <button id="resetGame">Reiniciar juego</button>
      <button id="exportData">Exportar actividad</button>
    </div>

    <div class="game-card">
      <canvas id="canvas" width="800" height="550"></canvas>
      <div id="success" class="success">¡Excelente! Imagen revelada 🎉</div>
    </div>

    <div class="instructions">
      <strong>Editor:</strong> haz clic sobre la imagen para crear puntos.<br />
      <strong>Viewer:</strong> arrastra desde el punto activo hasta el siguiente.
    </div>

    <textarea id="output" placeholder="Aquí aparecerá la data exportada..."></textarea>
  </div>

  <script>
    const canvas = document.getElementById("canvas");
    const ctx = canvas.getContext("2d");

    const imageUpload = document.getElementById("imageUpload");
    const editorBtn = document.getElementById("editorBtn");
    const viewerBtn = document.getElementById("viewerBtn");
    const undoDotBtn = document.getElementById("undoDot");
    const clearDotsBtn = document.getElementById("clearDots");
    const resetGameBtn = document.getElementById("resetGame");
    const exportDataBtn = document.getElementById("exportData");
    const output = document.getElementById("output");
    const success = document.getElementById("success");

    let mode = "editor";

    let uploadedImage = null;
    let uploadedImageData = "";
    let imageOpacity = 0;

    let dots = [];
    let current = 1;
    let completed = false;
    let dragging = false;
    let mouse = { x: 0, y: 0 };

    function getPointerPosition(e) {
      const rect = canvas.getBoundingClientRect();

      let clientX;
      let clientY;

      if (e.touches && e.touches.length > 0) {
        clientX = e.touches[0].clientX;
        clientY = e.touches[0].clientY;
      } else if (e.changedTouches && e.changedTouches.length > 0) {
        clientX = e.changedTouches[0].clientX;
        clientY = e.changedTouches[0].clientY;
      } else {
        clientX = e.clientX;
        clientY = e.clientY;
      }

      return {
        x: (clientX - rect.left) * (canvas.width / rect.width),
        y: (clientY - rect.top) * (canvas.height / rect.height)
      };
    }

    function isNearDot(pos, dot) {
      return Math.hypot(pos.x - dot.x, pos.y - dot.y) < 42;
    }

    function drawBackground() {
      ctx.clearRect(0, 0, canvas.width, canvas.height);

      const grad = ctx.createLinearGradient(0, 0, canvas.width, canvas.height);
      grad.addColorStop(0, "#020617");
      grad.addColorStop(1, "#1e1b4b");

      ctx.fillStyle = grad;
      ctx.fillRect(0, 0, canvas.width, canvas.height);
    }

    function drawImage(opacity = 1) {
      if (!uploadedImage) {
        ctx.fillStyle = "rgba(255,255,255,0.12)";
        ctx.fillRect(80, 70, canvas.width - 160, canvas.height - 140);

        ctx.fillStyle = "rgba(255,255,255,0.85)";
        ctx.font = "bold 28px system-ui";
        ctx.textAlign = "center";
        ctx.textBaseline = "middle";
        ctx.fillText("Sube una imagen para comenzar", canvas.width / 2, canvas.height / 2);
        return;
      }

      const imgRatio = uploadedImage.width / uploadedImage.height;
      const canvasRatio = canvas.width / canvas.height;

      let drawWidth;
      let drawHeight;
      let drawX;
      let drawY;

      if (imgRatio > canvasRatio) {
        drawWidth = canvas.width;
        drawHeight = canvas.width / imgRatio;
        drawX = 0;
        drawY = (canvas.height - drawHeight) / 2;
      } else {
        drawHeight = canvas.height;
        drawWidth = canvas.height * imgRatio;
        drawX = (canvas.width - drawWidth) / 2;
        drawY = 0;
      }

      ctx.save();
      ctx.globalAlpha = opacity;
      ctx.drawImage(uploadedImage, drawX, drawY, drawWidth, drawHeight);
      ctx.restore();
    }

    function drawEditorOverlay() {
      ctx.fillStyle = "rgba(2,6,23,0.48)";
      ctx.fillRect(0, 0, canvas.width, canvas.height);
    }

    function drawEditorLines() {
      if (dots.length < 2) return;

      ctx.strokeStyle = "rgba(56,189,248,0.6)";
      ctx.lineWidth = 4;
      ctx.lineCap = "round";
      ctx.lineJoin = "round";

      for (let i = 0; i < dots.length - 1; i++) {
        ctx.beginPath();
        ctx.moveTo(dots[i].x, dots[i].y);
        ctx.lineTo(dots[i + 1].x, dots[i + 1].y);
        ctx.stroke();
      }

      if (dots.length > 2) {
        ctx.setLineDash([8, 8]);
        ctx.beginPath();
        ctx.moveTo(dots[dots.length - 1].x, dots[dots.length - 1].y);
        ctx.lineTo(dots[0].x, dots[0].y);
        ctx.stroke();
        ctx.setLineDash([]);
      }
    }

    function drawPermanentLines() {
      if (dots.length < 2) return;

      ctx.strokeStyle = "#38bdf8";
      ctx.lineWidth = 5;
      ctx.lineCap = "round";
      ctx.lineJoin = "round";

      for (let i = 0; i < current - 1; i++) {
        if (!dots[i + 1]) continue;

        ctx.beginPath();
        ctx.moveTo(dots[i].x, dots[i].y);
        ctx.lineTo(dots[i + 1].x, dots[i + 1].y);
        ctx.stroke();
      }

      if (completed && dots.length > 2) {
        ctx.beginPath();
        ctx.moveTo(dots[dots.length - 1].x, dots[dots.length - 1].y);
        ctx.lineTo(dots[0].x, dots[0].y);
        ctx.stroke();
      }
    }

    function drawDraggingLine() {
      if (!dragging || completed || mode !== "viewer") return;
      if (current >= dots.length) return;

      ctx.strokeStyle = "rgba(56,189,248,0.55)";
      ctx.lineWidth = 4;
      ctx.lineCap = "round";
      ctx.setLineDash([10, 8]);

      ctx.beginPath();
      ctx.moveTo(dots[current - 1].x, dots[current - 1].y);
      ctx.lineTo(mouse.x, mouse.y);
      ctx.stroke();

      ctx.setLineDash([]);
    }

    function drawDots() {
      dots.forEach((dot, index) => {
        const active = mode === "viewer" && index === current - 1;
        const done = mode === "viewer" && index < current - 1;

        ctx.beginPath();
        ctx.arc(dot.x, dot.y, active ? 20 : 16, 0, Math.PI * 2);

        if (done) {
          ctx.fillStyle = "#22c55e";
        } else if (active) {
          ctx.fillStyle = "#f97316";
        } else {
          ctx.fillStyle = "#ffffff";
        }

        ctx.fill();

        ctx.strokeStyle = "rgba(255,255,255,0.9)";
        ctx.lineWidth = 3;
        ctx.stroke();

        ctx.fillStyle = done ? "#ffffff" : "#111827";
        ctx.font = "bold 15px system-ui";
        ctx.textAlign = "center";
        ctx.textBaseline = "middle";
        ctx.fillText(index + 1, dot.x, dot.y);
      });
    }

    function render() {
      drawBackground();

      if (mode === "editor") {
        drawImage(1);
        drawEditorOverlay();
        drawEditorLines();
        drawDots();
      }

      if (mode === "viewer") {
        if (completed) {
          drawImage(imageOpacity);
        } else {
          drawImage(0.08);
          ctx.fillStyle = "rgba(2,6,23,0.74)";
          ctx.fillRect(0, 0, canvas.width, canvas.height);
        }

        drawPermanentLines();
        drawDraggingLine();

        if (!completed) {
          drawDots();
        }
      }
    }

    function fadeInImage() {
      imageOpacity = 0;

      function animate() {
        imageOpacity += 0.025;

        if (imageOpacity > 1) {
          imageOpacity = 1;
        }

        render();

        if (imageOpacity < 1) {
          requestAnimationFrame(animate);
        }
      }

      animate();
    }

    function resetGame() {
      current = 1;
      completed = false;
      dragging = false;
      imageOpacity = 0;
      success.classList.remove("show");
      render();
    }

    function setMode(newMode) {
      mode = newMode;

      if (mode === "editor") {
        editorBtn.classList.add("active");
        viewerBtn.classList.remove("active");
        canvas.style.cursor = "crosshair";
      }

      if (mode === "viewer") {
        viewerBtn.classList.add("active");
        editorBtn.classList.remove("active");
        canvas.style.cursor = "grab";
        resetGame();
      }

      render();
    }

    function handleEditorClick(e) {
      if (mode !== "editor") return;

      const pos = getPointerPosition(e);

      dots.push({
        x: Math.round(pos.x),
        y: Math.round(pos.y)
      });

      render();
    }

    function startDrag(e) {
      if (mode !== "viewer") return;
      if (completed) return;
      if (dots.length < 2) return;

      const pos = getPointerPosition(e);

      if (isNearDot(pos, dots[current - 1])) {
        dragging = true;
        mouse = pos;
        canvas.style.cursor = "grabbing";
        render();
      }
    }

    function moveDrag(e) {
      if (!dragging || completed || mode !== "viewer") return;

      e.preventDefault();
      mouse = getPointerPosition(e);
      render();
    }

    function endDrag(e) {
      if (!dragging || completed || mode !== "viewer") return;

      const pos = getPointerPosition(e);
      const nextDot = dots[current];

      if (nextDot && isNearDot(pos, nextDot)) {
        current++;

        if (current === dots.length) {
          completed = true;
          dragging = false;
          canvas.style.cursor = "default";
          success.classList.add("show");
          fadeInImage();
          return;
        }
      }

      dragging = false;
      canvas.style.cursor = "grab";
      render();
    }

    function exportActivity() {
      const activity = {
        type: "dot-to-dot",
        image: uploadedImageData,
        dots: dots,
        canvas: {
          width: canvas.width,
          height: canvas.height
        }
      };

      output.value = JSON.stringify(activity, null, 2);
    }

    imageUpload.addEventListener("change", function () {
      const file = imageUpload.files[0];

      if (!file) return;

      const reader = new FileReader();

      reader.onload = function (event) {
        uploadedImageData = event.target.result;
        uploadedImage = new Image();

        uploadedImage.onload = function () {
          dots = [];
          output.value = "";
          resetGame();
          setMode("editor");
          render();
        };

        uploadedImage.src = uploadedImageData;
      };

      reader.readAsDataURL(file);
    });

    canvas.addEventListener("click", handleEditorClick);

    canvas.addEventListener("mousedown", startDrag);
    canvas.addEventListener("mousemove", moveDrag);
    canvas.addEventListener("mouseup", endDrag);

    canvas.addEventListener("mouseleave", () => {
      dragging = false;

      if (mode === "viewer" && !completed) {
        canvas.style.cursor = "grab";
      }

      render();
    });

    canvas.addEventListener("touchstart", function (e) {
      e.preventDefault();

      if (mode === "editor") {
        handleEditorClick(e);
      } else {
        startDrag(e);
      }
    }, { passive: false });

    canvas.addEventListener("touchmove", moveDrag, { passive: false });
    canvas.addEventListener("touchend", endDrag);

    editorBtn.addEventListener("click", () => {
      setMode("editor");
    });

    viewerBtn.addEventListener("click", () => {
      setMode("viewer");
    });

    undoDotBtn.addEventListener("click", () => {
      if (mode !== "editor") {
        setMode("editor");
      }

      dots.pop();
      resetGame();
      render();
    });

    clearDotsBtn.addEventListener("click", () => {
      dots = [];
      output.value = "";
      resetGame();
      setMode("editor");
    });

    resetGameBtn.addEventListener("click", () => {
      resetGame();
    });

    exportDataBtn.addEventListener("click", () => {
      exportActivity();
    });

    render();
  </script>
</body>
</html>
