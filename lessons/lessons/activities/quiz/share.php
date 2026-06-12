<?php
// Compartir quiz: genera (o reutiliza) el enlace único del quiz seleccionado.
// No crea un quiz nuevo: solo crea un link compartible para el quiz existente.
if (session_status() === PHP_SESSION_NONE) session_start();

$qzsIsTeacher = isset($_SESSION['academic_logged']) && $_SESSION['academic_logged'] === true;
$qzsIsAdmin   = isset($_SESSION['admin_logged']) && $_SESSION['admin_logged'] === true;
if (!$qzsIsTeacher && !$qzsIsAdmin) {
    header('Location: /lessons/lessons/academic/login.php');
    exit;
}

require_once __DIR__ . '/../../../lessons/core/db.php';
require_once __DIR__ . '/_quiz_lib.php';

$unitId       = trim((string)($_GET['unit'] ?? ''));
$assignmentId = trim((string)($_GET['assignment'] ?? ''));
if ($unitId === '') die('Missing unit id.');

$pdo = get_pdo();
qz_ensure_share_tables($pdo);

// Nombre de la unidad / quiz
$unitName = 'Unidad ' . $unitId;
try {
    $st = $pdo->prepare('SELECT name FROM units WHERE id::text = :u LIMIT 1');
    $st->execute(['u' => $unitId]);
    $n = trim((string)$st->fetchColumn());
    if ($n !== '') $unitName = $n;
} catch (Throwable $e) {}

// Verificar que el quiz tenga preguntas calificables
$questionCount = 0;
try {
    $st = $pdo->prepare('SELECT * FROM activities WHERE unit_id = :u ORDER BY id ASC');
    $st->execute(['u' => $unitId]);
    $all = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $act) {
        foreach (qz_normalize_activity($act) as $q) $all[] = $q;
    }
    $questionCount = count(qz_build_shared($all, 'preview'));
} catch (Throwable $e) {}

// Obtener el enlace activo existente para este quiz o crear uno nuevo (una sola vez)
$link = null;
try {
    $st = $pdo->prepare('SELECT * FROM quiz_share_links WHERE unit_id = :u AND is_active = TRUE ORDER BY id DESC LIMIT 1');
    $st->execute(['u' => $unitId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (is_array($row)) $link = $row;
} catch (Throwable $e) {}

if ($link === null) {
    $token     = bin2hex(random_bytes(16));
    $createdBy = trim((string)($_SESSION['teacher_id'] ?? $_SESSION['admin_username'] ?? 'academic'));
    $st = $pdo->prepare('INSERT INTO quiz_share_links (unit_id, token, created_by) VALUES (:u, :t, :c) RETURNING *');
    $st->execute(['u' => $unitId, 't' => $token, 'c' => $createdBy]);
    $link = $st->fetch(PDO::FETCH_ASSOC);
}

// Conteo de respuestas recibidas para este quiz
$responsesCount = 0;
try {
    $st = $pdo->prepare('SELECT COUNT(*) FROM quiz_share_responses WHERE link_id = :l');
    $st->execute(['l' => (int)$link['id']]);
    $responsesCount = (int)$st->fetchColumn();
} catch (Throwable $e) {}

// URL absoluta del enlace compartible
$https  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
$scheme = $https ? 'https' : 'http';
$host   = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
$shareUrl = $scheme . '://' . $host . '/lessons/lessons/activities/quiz/public.php?t=' . urlencode((string)$link['token']);

$backHref    = '../../academic/teacher_quiz.php?assignment=' . urlencode($assignmentId) . '&unit=' . urlencode($unitId);
$resultsHref = 'share_results.php?unit=' . urlencode($unitId) . '&assignment=' . urlencode($assignmentId);
$createdAt   = trim((string)($link['created_at'] ?? ''));
$createdAtTxt = $createdAt !== '' ? date('d/m/Y H:i', strtotime($createdAt)) : '';
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Compartir quiz</title>
<style>
:root{--bg:#eef5ff;--card:#ffffff;--line:#d8e2f2;--text:#1b3050;--title:#0f1f42;--muted:#5d6f8f;
  --blue:#2563eb;--blue-dark:#1d4ed8;--blue-soft:#e9f1ff;--green:#16a34a;--green-dark:#15803d;
  --shadow:0 10px 24px rgba(0,0,0,.08);--shadow-sm:0 2px 8px rgba(0,0,0,.06);}
*{box-sizing:border-box}
body{margin:0;font-family:Arial,sans-serif;background:var(--bg);color:var(--text);}
.topbar{background:linear-gradient(180deg,var(--blue),var(--blue-dark));color:#fff;padding:16px 24px;}
.topbar-inner{max-width:900px;margin:0 auto;display:flex;align-items:center;justify-content:space-between;gap:12px;}
.top-btn{display:inline-flex;align-items:center;padding:10px 14px;border-radius:10px;text-decoration:none;
  font-size:13px;font-weight:700;color:#fff;background:rgba(255,255,255,.2);box-shadow:var(--shadow-sm);}
.top-title{margin:0;font-size:22px;font-weight:800;}
.page{max-width:900px;margin:0 auto;padding:20px;}
.card{background:var(--card);border:1px solid var(--line);border-radius:22px;box-shadow:var(--shadow);padding:26px;margin-bottom:18px;}
.title{margin:0 0 10px;color:var(--title);font-size:26px;font-weight:800;}
.text{margin:0 0 14px;color:var(--muted);font-size:14px;line-height:1.6;}
.badges{display:flex;gap:10px;flex-wrap:wrap;margin:12px 0 18px;}
.badge{display:inline-flex;align-items:center;padding:7px 12px;border-radius:999px;background:var(--blue-soft);
  color:var(--blue-dark);font-size:12px;font-weight:800;}
.badge.green{background:#e7f8ee;color:var(--green-dark);}
.badge.warn{background:#fff3d9;color:#d97706;}
.link-row{display:flex;gap:10px;flex-wrap:wrap;align-items:stretch;}
.link-input{flex:1;min-width:240px;padding:12px 14px;border:2px solid var(--line);border-radius:12px;
  font-size:14px;color:var(--text);background:#f8fafd;font-family:monospace;}
.btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;min-width:150px;padding:12px 18px;
  border-radius:12px;text-decoration:none;color:#fff;font-size:14px;font-weight:700;border:none;cursor:pointer;
  box-shadow:var(--shadow-sm);background:linear-gradient(180deg,#3d73ee,#2563eb);}
.btn.green{background:linear-gradient(180deg,#22c55e,#16a34a);}
.btn.secondary{background:linear-gradient(180deg,#7b8b9e,#66758b);}
.btn:hover{filter:brightness(1.07);}
.actions{display:flex;gap:12px;flex-wrap:wrap;margin-top:20px;}
.copied-msg{display:none;margin-top:10px;font-size:13px;font-weight:700;color:var(--green-dark);}
.steps{margin:0;padding-left:20px;color:var(--muted);font-size:14px;line-height:1.9;}
@media(max-width:768px){.page{padding:12px}.card{padding:20px}.title{font-size:22px}.actions{flex-direction:column}.btn{width:100%;min-width:0}}
</style>
</head>
<body>
<header class="topbar">
  <div class="topbar-inner">
    <a class="top-btn" href="<?php echo qz_h($backHref); ?>">&larr; Volver al quiz</a>
    <h1 class="top-title">Compartir quiz</h1>
    <span></span>
  </div>
</header>

<main class="page">
  <section class="card">
    <h2 class="title">🔗 Enlace para estudiantes</h2>
    <div class="badges">
      <span class="badge">Quiz: <?php echo qz_h($unitName); ?></span>
      <span class="badge green">Preguntas: <?php echo (int)$questionCount; ?></span>
      <span class="badge green">Respuestas recibidas: <?php echo (int)$responsesCount; ?></span>
      <?php if ($createdAtTxt !== '') { ?>
        <span class="badge">Enlace creado: <?php echo qz_h($createdAtTxt); ?></span>
      <?php } ?>
    </div>
    <?php if ($questionCount === 0) { ?>
      <p class="text" style="color:#d97706;font-weight:700;">⚠️ Este quiz aún no tiene preguntas calificables. Agrega actividades a la unidad antes de compartirlo.</p>
    <?php } ?>
    <p class="text">Este es el enlace único de este quiz. Se guarda en la base de datos y siempre es el mismo: compártelo con tus estudiantes por WhatsApp, correo o como prefieras. No se crea un quiz nuevo, los estudiantes responderán exactamente este quiz.</p>

    <div class="link-row">
      <input class="link-input" id="shareUrl" type="text" readonly value="<?php echo qz_h($shareUrl); ?>" onclick="this.select()">
      <button class="btn green" type="button" onclick="copyShareUrl()">📋 Copiar enlace</button>
    </div>
    <div class="copied-msg" id="copiedMsg">✅ Enlace copiado al portapapeles</div>

    <div class="actions">
      <a class="btn" href="<?php echo qz_h($resultsHref); ?>">📊 Ver resultados (<?php echo (int)$responsesCount; ?>)</a>
      <a class="btn secondary" href="<?php echo qz_h($shareUrl); ?>" target="_blank" rel="noopener">👁 Ver como estudiante</a>
    </div>
  </section>

  <section class="card">
    <h2 class="title" style="font-size:20px;">¿Cómo funciona?</h2>
    <ol class="steps">
      <li>Copia el enlace y compártelo con tus estudiantes.</li>
      <li>Al abrirlo, el estudiante verá únicamente este quiz.</li>
      <li>Antes de responder, deberá escribir su nombre.</li>
      <li>Responde el quiz como un formulario y lo envía.</li>
      <li>Sus respuestas, puntaje y fecha de envío llegan automáticamente a <strong>Resultados</strong>.</li>
    </ol>
  </section>
</main>

<script>
function copyShareUrl(){
  var input = document.getElementById('shareUrl');
  var msg = document.getElementById('copiedMsg');
  var show = function(){ msg.style.display = 'block'; setTimeout(function(){ msg.style.display = 'none'; }, 2500); };
  if (navigator.clipboard && navigator.clipboard.writeText) {
    navigator.clipboard.writeText(input.value).then(show).catch(function(){
      input.select(); document.execCommand('copy'); show();
    });
  } else {
    input.select(); document.execCommand('copy'); show();
  }
}
</script>
</body>
</html>
