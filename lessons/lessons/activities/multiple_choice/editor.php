<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../core/cloudinary_upload.php';
require_once __DIR__ . '/../../core/_activity_editor_template.php';

$activityId = isset($_GET['id']) ? trim((string) $_GET['id']) : '';
$unit = isset($_GET['unit']) ? trim((string) $_GET['unit']) : '';
$source = isset($_GET['source']) ? trim((string) $_GET['source']) : '';

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

function normalize_multiple_choice_questions($rawData): array
{
    if ($rawData === null || $rawData === '') {
        return array();
    }

    $decoded = is_string($rawData) ? json_decode($rawData, true) : $rawData;

    if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
        return array();
    }

    if (isset($decoded['questions']) && is_array($decoded['questions'])) {
        $decoded = $decoded['questions'];
    } elseif (isset($decoded['items']) && is_array($decoded['items'])) {
        $decoded = $decoded['items'];
    } elseif (isset($decoded['data']) && is_array($decoded['data'])) {
        $decoded = $decoded['data'];
    }

    $normalized = array();

    foreach ($decoded as $item) {
        if (!is_array($item)) {
            continue;
        }

        $options = isset($item['options']) && is_array($item['options'])
            ? $item['options']
            : array(
                isset($item['option_a']) ? (string) $item['option_a'] : '',
                isset($item['option_b']) ? (string) $item['option_b'] : '',
                isset($item['option_c']) ? (string) $item['option_c'] : '',
            );

        $normalized[] = array(
            'question' => isset($item['question']) ? trim((string) $item['question']) : '',
            'image' => isset($item['image']) ? trim((string) $item['image']) : '',
            'options' => array(
                isset($options[0]) ? trim((string) $options[0]) : '',
                isset($options[1]) ? trim((string) $options[1]) : '',
                isset($options[2]) ? trim((string) $options[2]) : '',
            ),
            'correct' => isset($item['correct']) ? max(0, min(2, (int) $item['correct'])) : 0,
        );
    }

    return $normalized;
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

function load_multiple_choice_questions(PDO $pdo, string $unit, string $activityId): array
{
    $columns = activities_columns($pdo);

    if ($activityId !== '' && in_array('data', $columns, true)) {
        $stmt = $pdo->prepare(
            "SELECT data
             FROM activities
             WHERE id = :id
               AND type = 'multiple_choice'
             LIMIT 1"
        );
        $stmt->execute(array('id' => $activityId));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row && isset($row['data'])) {
            return normalize_multiple_choice_questions($row['data']);
        }
    }

    if ($unit !== '' && in_array('unit_id', $columns, true) && in_array('data', $columns, true)) {
        $stmt = $pdo->prepare(
            "SELECT data
             FROM activities
             WHERE unit_id = :unit
               AND type = 'multiple_choice'
             LIMIT 1"
        );
        $stmt->execute(array('unit' => $unit));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row && isset($row['data'])) {
            return normalize_multiple_choice_questions($row['data']);
        }
    }

    if ($unit !== '' && in_array('unit', $columns, true) && in_array('content_json', $columns, true)) {
        $stmt = $pdo->prepare(
            "SELECT content_json
             FROM activities
             WHERE unit = :unit
               AND type = 'multiple_choice'
             LIMIT 1"
        );
        $stmt->execute(array('unit' => $unit));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row && isset($row['content_json'])) {
            return normalize_multiple_choice_questions($row['content_json']);
        }
    }

    return array();
}

function save_multiple_choice_questions(PDO $pdo, string $unit, array $questions): void
{
    $columns = activities_columns($pdo);
    $json = json_encode($questions, JSON_UNESCAPED_UNICODE);

    if (in_array('unit_id', $columns, true) && in_array('data', $columns, true)) {
        $check = $pdo->prepare(
            "SELECT id
             FROM activities
             WHERE unit_id = :unit
               AND type = 'multiple_choice'
             LIMIT 1"
        );
        $check->execute(array('unit' => $unit));

        if ($check->fetch()) {
            $stmt = $pdo->prepare(
                "UPDATE activities
                 SET data = :data
                 WHERE unit_id = :unit
                   AND type = 'multiple_choice'"
            );
            $stmt->execute(array('data' => $json, 'unit' => $unit));
            return;
        }

        try {
            $stmt = $pdo->prepare(
                "INSERT INTO activities (unit_id, type, data)
                 VALUES (:unit, 'multiple_choice', :data)"
            );
            $stmt->execute(array('unit' => $unit, 'data' => $json));
            return;
        } catch (Exception $e) {
            if (in_array('id', $columns, true)) {
                $stmt = $pdo->prepare(
                    "INSERT INTO activities (id, unit_id, type, data)
                     VALUES (:id, :unit, 'multiple_choice', :data)"
                );
                $stmt->execute(array('id' => md5(random_bytes(16)), 'unit' => $unit, 'data' => $json));
                return;
            }

            throw $e;
        }
    }

    if (in_array('unit', $columns, true) && in_array('content_json', $columns, true)) {
        $check = $pdo->prepare(
            "SELECT id
             FROM activities
             WHERE unit = :unit
               AND type = 'multiple_choice'
             LIMIT 1"
        );
        $check->execute(array('unit' => $unit));

        if ($check->fetch()) {
            $stmt = $pdo->prepare(
                "UPDATE activities
                 SET content_json = :data
                 WHERE unit = :unit
                   AND type = 'multiple_choice'"
            );
            $stmt->execute(array('data' => $json, 'unit' => $unit));
            return;
        }

        $stmt = $pdo->prepare(
            "INSERT INTO activities (unit, type, content_json)
             VALUES (:unit, 'multiple_choice', :data)"
        );
        $stmt->execute(array('unit' => $unit, 'data' => $json));
    }
}

if ($unit === '' && $activityId !== '') {
    $unit = resolve_unit_from_activity($pdo, $activityId);
}

if ($unit === '') {
    die('Unidad no especificada');
}

$questions = load_multiple_choice_questions($pdo, $unit, $activityId);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $inputQuestions = isset($_POST['question']) && is_array($_POST['question']) ? $_POST['question'] : array();
    $optionA = isset($_POST['option_a']) && is_array($_POST['option_a']) ? $_POST['option_a'] : array();
    $optionB = isset($_POST['option_b']) && is_array($_POST['option_b']) ? $_POST['option_b'] : array();
    $optionC = isset($_POST['option_c']) && is_array($_POST['option_c']) ? $_POST['option_c'] : array();
    $corrects = isset($_POST['correct']) && is_array($_POST['correct']) ? $_POST['correct'] : array();
    $existingImages = isset($_POST['image']) && is_array($_POST['image']) ? $_POST['image'] : array();

    $uploadedImages = isset($_FILES['image_file']) ? $_FILES['image_file'] : null;

    $sanitized = array();

    foreach ($inputQuestions as $i => $question) {
        $questionText = trim((string) $question);
        if ($questionText === '') {
            continue;
        }

        $currentImage = isset($existingImages[$i]) ? trim((string) $existingImages[$i]) : '';

        if (
            $uploadedImages
            && isset($uploadedImages['name'][$i])
            && $uploadedImages['name'][$i]
            && isset($uploadedImages['tmp_name'][$i])
            && $uploadedImages['tmp_name'][$i] !== ''
        ) {
            $uploadedUrl = upload_to_cloudinary($uploadedImages['tmp_name'][$i]);
            if ($uploadedUrl) {
                $currentImage = $uploadedUrl;
            }
        }

        $a = isset($optionA[$i]) ? trim((string) $optionA[$i]) : '';
        $b = isset($optionB[$i]) ? trim((string) $optionB[$i]) : '';
        $c = isset($optionC[$i]) ? trim((string) $optionC[$i]) : '';
        $correct = isset($corrects[$i]) ? (int) $corrects[$i] : 0;

        if ($correct < 0 || $correct > 2) {
            $correct = 0;
        }

        $sanitized[] = array(
            'question' => $questionText,
            'image' => $currentImage,
            'options' => array($a, $b, $c),
            'correct' => $correct,
        );
    }

    save_multiple_choice_questions($pdo, $unit, $sanitized);

    $redirectParams = array('unit=' . urlencode($unit), 'saved=1');
    if ($activityId !== '') {
        $redirectParams[] = 'id=' . urlencode($activityId);
    }
    if ($source !== '') {
        $redirectParams[] = 'source=' . urlencode($source);
    }

    header('Location: editor.php?' . implode('&', $redirectParams));
    exit;
}

ob_start();

if (isset($_GET['saved'])) {
    echo '<p style="color:green;font-weight:bold;margin-bottom:15px;">✔ Guardado correctamente</p>';
}
?>

<form method="post" enctype="multipart/form-data" style="text-align:left; max-width:820px; margin:0 auto;">
    <div id="questions">
        <?php foreach ($questions as $item) { ?>
            <?php
            $questionText = isset($item['question']) ? (string) $item['question'] : '';
            $image = isset($item['image']) ? (string) $item['image'] : '';
            $options = isset($item['options']) && is_array($item['options']) ? $item['options'] : array('', '', '');
            $correct = isset($item['correct']) ? (int) $item['correct'] : 0;
            ?>
            <div class="mc-block">
                <label>Question</label>
                <input type="text" name="question[]" value="<?php echo htmlspecialchars($questionText); ?>" placeholder="Question" required>

                <label>Image (optional)</label>
                <input type="file" name="image_file[]" accept="image/*">
                <input type="hidden" name="image[]" value="<?php echo htmlspecialchars($image); ?>">

                <?php if ($image !== '') { ?>
                    <img src="<?php echo htmlspecialchars($image); ?>" alt="current-image" class="mc-preview">
                <?php } ?>

                <label>Options</label>
                <div class="mc-options-grid">
                    <input type="text" name="option_a[]" value="<?php echo htmlspecialchars(isset($options[0]) ? (string) $options[0] : ''); ?>" placeholder="Option A" required>
                    <input type="text" name="option_b[]" value="<?php echo htmlspecialchars(isset($options[1]) ? (string) $options[1] : ''); ?>" placeholder="Option B" required>
                    <input type="text" name="option_c[]" value="<?php echo htmlspecialchars(isset($options[2]) ? (string) $options[2] : ''); ?>" placeholder="Option C" required>
                </div>

                <div class="mc-block-footer">
                    <select name="correct[]">
                        <option value="0" <?php echo $correct === 0 ? 'selected' : ''; ?>>Correct: A</option>
                        <option value="1" <?php echo $correct === 1 ? 'selected' : ''; ?>>Correct: B</option>
                        <option value="2" <?php echo $correct === 2 ? 'selected' : ''; ?>>Correct: C</option>
                    </select>
                    <button type="button" class="mc-remove" onclick="removeQuestion(this)">✖ Remove</button>
                </div>
            </div>
        <?php } ?>
    </div>

    <div class="mc-actions">
        <button type="button" class="mc-add" onclick="addQuestion()">+ Add Question</button>
        <button type="submit" class="mc-save">💾 Save</button>
    </div>
</form>

<script>
function addQuestion() {
    var wrapper = document.createElement('div');
    wrapper.className = 'mc-block';
    wrapper.innerHTML = '' +
        '<label>Question</label>' +
        '<input type="text" name="question[]" placeholder="Question" required>' +
        '<label>Image (optional)</label>' +
        '<input type="file" name="image_file[]" accept="image/*">' +
        '<input type="hidden" name="image[]" value="">' +
        '<label>Options</label>' +
        '<div class="mc-options-grid">' +
            '<input type="text" name="option_a[]" placeholder="Option A" required>' +
            '<input type="text" name="option_b[]" placeholder="Option B" required>' +
            '<input type="text" name="option_c[]" placeholder="Option C" required>' +
        '</div>' +
        '<div class="mc-block-footer">' +
            '<select name="correct[]">' +
                '<option value="0">Correct: A</option>' +
                '<option value="1">Correct: B</option>' +
                '<option value="2">Correct: C</option>' +
            '</select>' +
            '<button type="button" class="mc-remove" onclick="removeQuestion(this)">✖ Remove</button>' +
        '</div>';

    document.getElementById('questions').appendChild(wrapper);
}

function removeQuestion(button) {
    var block = button.closest('.mc-block');
    if (block) {
        block.remove();
    }
}
</script>

<style>
.mc-block { border:1px solid #dbe3f1; border-radius:12px; padding:14px; margin-bottom:12px; background:#f8fbff; }
.mc-block label { display:block; font-weight:700; margin:6px 0; }
.mc-block input[type="text"], .mc-block select { width:100%; padding:9px; border:1px solid #cfd8ea; border-radius:8px; }
.mc-options-grid { display:grid; grid-template-columns:1fr 1fr 1fr; gap:8px; margin-top:6px; }
.mc-block-footer { margin-top:10px; display:flex; gap:8px; align-items:center; }
.mc-remove { background:#ef4444; color:#fff; border:none; border-radius:8px; padding:8px 10px; cursor:pointer; }
.mc-actions { display:flex; gap:10px; justify-content:center; margin-top:12px; }
.mc-add { background:#16a34a; color:#fff; border:none; border-radius:10px; padding:10px 14px; cursor:pointer; }
.mc-save { background:#0b5ed7; color:#fff; border:none; border-radius:10px; padding:10px 14px; cursor:pointer; }
.mc-preview { width:120px; border-radius:8px; margin-top:8px; }
@media (max-width:760px){ .mc-options-grid{ grid-template-columns:1fr; } }
</style>

<?php
$content = ob_get_clean();
render_activity_editor('📝 Multiple Choice Editor', '📝', $content);
