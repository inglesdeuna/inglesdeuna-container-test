<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../core/_activity_editor_template.php';

$activityId = isset($_GET['id']) ? trim((string) $_GET['id']) : '';
$unit = isset($_GET['unit']) ? trim((string) $_GET['unit']) : '';
$source = isset($_GET['source']) ? trim((string) $_GET['source']) : '';
$assignment = isset($_GET['assignment']) ? trim((string) $_GET['assignment']) : '';

function resolve_unit_from_activity(PDO $pdo, string $activityId): string
{
    if ($activityId === '') {
        return '';
    }

    $stmt = $pdo->prepare("SELECT unit_id FROM activities WHERE id = :id LIMIT 1");
    $stmt->execute(['id' => $activityId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row && isset($row['unit_id']) ? (string) $row['unit_id'] : '';
}

function default_quiz_payload(): array
{
    return [
        'title' => 'Unit Quiz',
        'description' => 'Answer all questions and submit your score.',
        'questions' => [],
    ];
}

function normalize_quiz_payload($rawData): array
{
    $default = default_quiz_payload();

    if ($rawData === null || $rawData === '') {
        return $default;
    }

    $decoded = is_string($rawData) ? json_decode($rawData, true) : $rawData;
    if (!is_array($decoded)) {
        return $default;
    }

    $title = trim((string) ($decoded['title'] ?? $default['title']));
    $description = trim((string) ($decoded['description'] ?? $default['description']));
    $questionsRaw = isset($decoded['questions']) && is_array($decoded['questions']) ? $decoded['questions'] : [];

    $questions = [];
    foreach ($questionsRaw as $item) {
        if (!is_array($item)) {
            continue;
        }

        $options = isset($item['options']) && is_array($item['options']) ? $item['options'] : [];
        $normalizedOptions = [];
        for ($i = 0; $i < 4; $i++) {
            $normalizedOptions[] = trim((string) ($options[$i] ?? ''));
        }

        $correct = (int) ($item['correct'] ?? 0);
        if ($correct < 0 || $correct > 3) {
            $correct = 0;
        }

        $questions[] = [
            'id' => trim((string) ($item['id'] ?? uniqid('qz_'))),
            'question' => trim((string) ($item['question'] ?? '')),
            'options' => $normalizedOptions,
            'correct' => $correct,
            'explanation' => trim((string) ($item['explanation'] ?? '')),
        ];
    }

    return [
        'title' => $title !== '' ? $title : $default['title'],
        'description' => $description,
        'questions' => $questions,
    ];
}

function load_quiz_activity(PDO $pdo, string $unit, string $activityId): array
{
    $fallback = [
        'id' => '',
        'payload' => default_quiz_payload(),
    ];

    $row = null;

    if ($activityId !== '') {
        $stmt = $pdo->prepare("SELECT id, data FROM activities WHERE id = :id AND type = 'quiz' LIMIT 1");
        $stmt->execute(['id' => $activityId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    if (!$row && $unit !== '') {
        $stmt = $pdo->prepare("SELECT id, data FROM activities WHERE unit_id = :unit AND type = 'quiz' ORDER BY id ASC LIMIT 1");
        $stmt->execute(['unit' => $unit]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    if (!$row) {
        return $fallback;
    }

    return [
        'id' => (string) ($row['id'] ?? ''),
        'payload' => normalize_quiz_payload($row['data'] ?? null),
    ];
}

function save_quiz_activity(PDO $pdo, string $unit, string $activityId, array $payload): string
{
    $json = json_encode(normalize_quiz_payload($payload), JSON_UNESCAPED_UNICODE);
    $targetId = $activityId;

    if ($targetId === '') {
        $stmt = $pdo->prepare("SELECT id FROM activities WHERE unit_id = :unit AND type = 'quiz' ORDER BY id ASC LIMIT 1");
        $stmt->execute(['unit' => $unit]);
        $targetId = trim((string) $stmt->fetchColumn());
    }

    if ($targetId !== '') {
        $stmt = $pdo->prepare("UPDATE activities SET data = :data WHERE id = :id AND type = 'quiz'");
        $stmt->execute([
            'data' => $json,
            'id' => $targetId,
        ]);
        return $targetId;
    }

    $stmt = $pdo->prepare("
        INSERT INTO activities (unit_id, type, data, position, created_at)
        VALUES (
            :unit_id,
            'quiz',
            :data,
            (
                SELECT COALESCE(MAX(position), 0) + 1
                FROM activities
                WHERE unit_id = :unit_id2
            ),
            CURRENT_TIMESTAMP
        )
        RETURNING id
    ");
    $stmt->execute([
        'unit_id' => $unit,
        'unit_id2' => $unit,
        'data' => $json,
    ]);

    return (string) $stmt->fetchColumn();
}

if ($unit === '' && $activityId !== '') {
    $unit = resolve_unit_from_activity($pdo, $activityId);
}

if ($unit === '') {
    die('Unit not specified');
}

$loaded = load_quiz_activity($pdo, $unit, $activityId);
$activity = $loaded['payload'];

if ($activityId === '' && !empty($loaded['id'])) {
    $activityId = (string) $loaded['id'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = trim((string) ($_POST['quiz_title'] ?? ''));
    $description = trim((string) ($_POST['quiz_description'] ?? ''));
    $questionsJson = trim((string) ($_POST['questions_json'] ?? '[]'));

    $decodedQuestions = json_decode($questionsJson, true);
    if (!is_array($decodedQuestions)) {
        $decodedQuestions = [];
    }

    $savedActivityId = save_quiz_activity($pdo, $unit, $activityId, [
        'title' => $title,
        'description' => $description,
        'questions' => $decodedQuestions,
    ]);

    $params = [
        'unit=' . urlencode($unit),
        'saved=1',
    ];

    if ($savedActivityId !== '') {
        $params[] = 'id=' . urlencode($savedActivityId);
    }

    if ($assignment !== '') {
        $params[] = 'assignment=' . urlencode($assignment);
    }

    if ($source !== '') {
        $params[] = 'source=' . urlencode($source);
    }

    header('Location: editor.php?' . implode('&', $params));
    exit;
}

$saved = isset($_GET['saved']) && $_GET['saved'] === '1';

ob_start();
?>
<style>
.quiz-editor{display:flex;flex-direction:column;gap:14px}
.qz-field label{display:block;font-weight:700;margin-bottom:6px;color:#0f172a}
.qz-field input,.qz-field textarea,.qz-field select{width:100%;border:1px solid #d6e4ff;border-radius:10px;padding:10px 12px;font-size:14px}
.qz-card{border:1px solid #dbeafe;border-radius:12px;padding:12px;background:#f8fbff}
.qz-card-head{display:flex;justify-content:space-between;align-items:center;margin-bottom:8px}
.qz-btn{display:inline-flex;align-items:center;justify-content:center;border:none;border-radius:10px;padding:10px 14px;font-weight:700;cursor:pointer}
.qz-btn-add{background:linear-gradient(180deg,#3d73ee,#2563eb);color:#fff}
.qz-btn-remove{background:#fee2e2;color:#b91c1c;padding:7px 10px}
.qz-btn-save{background:linear-gradient(180deg,#3d73ee,#2563eb);color:#fff}
.qz-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px}
.qz-note{font-size:13px;color:#64748b}
@media (max-width:768px){.qz-grid{grid-template-columns:1fr}}
</style>

<div class="quiz-editor">
  <?php if ($saved) { ?>
    <div class="alert alert-success">Quiz guardado correctamente.</div>
  <?php } ?>

  <div class="qz-field">
    <label for="quiz_title">Título del quiz</label>
    <input id="quiz_title" name="quiz_title" form="quizForm" value="<?php echo htmlspecialchars((string) ($activity['title'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" placeholder="Unit Quiz">
  </div>

  <div class="qz-field">
    <label for="quiz_description">Descripción</label>
    <textarea id="quiz_description" name="quiz_description" form="quizForm" rows="3" placeholder="Instrucciones del quiz"><?php echo htmlspecialchars((string) ($activity['description'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></textarea>
  </div>

  <div class="d-flex justify-content-between align-items-center">
    <h5 class="m-0">Preguntas</h5>
    <button type="button" class="qz-btn qz-btn-add" id="btnAddQuestion">+ Agregar pregunta</button>
  </div>

  <div id="questionsContainer"></div>
  <div class="qz-note">Base creada: puedes agregar preguntas, opciones y respuesta correcta. Luego se renderiza en el viewer del quiz.</div>

  <form id="quizForm" method="post">
    <input type="hidden" name="questions_json" id="questions_json" value="[]">
    <button type="submit" class="qz-btn qz-btn-save">Guardar quiz</button>
  </form>
</div>

<script>
(function(){
  const container = document.getElementById('questionsContainer');
  const addBtn = document.getElementById('btnAddQuestion');
  const form = document.getElementById('quizForm');
  const hidden = document.getElementById('questions_json');
  const initial = <?php echo json_encode($activity['questions'] ?? [], JSON_UNESCAPED_UNICODE); ?>;

  function questionCard(item, index){
    const q = item && typeof item === 'object' ? item : {};
    const options = Array.isArray(q.options) ? q.options : ['', '', '', ''];

    const wrap = document.createElement('div');
    wrap.className = 'qz-card';
    wrap.innerHTML = `
      <div class="qz-card-head">
        <strong>Pregunta ${index + 1}</strong>
        <button type="button" class="qz-btn qz-btn-remove">Eliminar</button>
      </div>
      <div class="qz-field">
        <label>Enunciado</label>
        <textarea rows="2" class="q-question">${(q.question || '').replace(/</g,'&lt;')}</textarea>
      </div>
      <div class="qz-grid">
        <div class="qz-field"><label>Opción A</label><input class="q-opt" value="${(options[0] || '').replace(/</g,'&lt;')}"></div>
        <div class="qz-field"><label>Opción B</label><input class="q-opt" value="${(options[1] || '').replace(/</g,'&lt;')}"></div>
        <div class="qz-field"><label>Opción C</label><input class="q-opt" value="${(options[2] || '').replace(/</g,'&lt;')}"></div>
        <div class="qz-field"><label>Opción D</label><input class="q-opt" value="${(options[3] || '').replace(/</g,'&lt;')}"></div>
      </div>
      <div class="qz-grid">
        <div class="qz-field">
          <label>Respuesta correcta</label>
          <select class="q-correct">
            <option value="0">A</option>
            <option value="1">B</option>
            <option value="2">C</option>
            <option value="3">D</option>
          </select>
        </div>
        <div class="qz-field">
          <label>Explicación (opcional)</label>
          <input class="q-expl" value="${(q.explanation || '').replace(/</g,'&lt;')}">
        </div>
      </div>
    `;

    const select = wrap.querySelector('.q-correct');
    const correct = Number.isInteger(Number(q.correct)) ? Number(q.correct) : 0;
    select.value = String(Math.max(0, Math.min(3, correct)));

    wrap.querySelector('.qz-btn-remove').addEventListener('click', () => {
      wrap.remove();
      refreshTitles();
    });

    return wrap;
  }

  function refreshTitles(){
    [...container.querySelectorAll('.qz-card')].forEach((card, i) => {
      const strong = card.querySelector('.qz-card-head strong');
      if (strong) strong.textContent = `Pregunta ${i + 1}`;
    });
  }

  function collect(){
    const rows = [];
    [...container.querySelectorAll('.qz-card')].forEach(card => {
      const question = (card.querySelector('.q-question')?.value || '').trim();
      const opts = [...card.querySelectorAll('.q-opt')].map(el => (el.value || '').trim());
      const correct = parseInt(card.querySelector('.q-correct')?.value || '0', 10);
      const explanation = (card.querySelector('.q-expl')?.value || '').trim();

      if (!question && opts.every(v => v === '')) {
        return;
      }

      rows.push({
        id: `qz_${Date.now()}_${Math.random().toString(16).slice(2)}`,
        question,
        options: [opts[0] || '', opts[1] || '', opts[2] || '', opts[3] || ''],
        correct: Number.isFinite(correct) ? Math.max(0, Math.min(3, correct)) : 0,
        explanation
      });
    });

    return rows;
  }

  addBtn.addEventListener('click', () => {
    container.appendChild(questionCard({}, container.querySelectorAll('.qz-card').length));
  });

  form.addEventListener('submit', () => {
    hidden.value = JSON.stringify(collect());
  });

  if (Array.isArray(initial) && initial.length) {
    initial.forEach((q, i) => container.appendChild(questionCard(q, i)));
  } else {
    container.appendChild(questionCard({}, 0));
  }
})();
</script>
<?php
$content = ob_get_clean();
render_activity_editor('Quiz Editor', 'fas fa-circle-question', $content);
