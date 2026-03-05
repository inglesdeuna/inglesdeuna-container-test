<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../core/cloudinary_upload.php';
require_once __DIR__ . '/../../core/_activity_editor_template.php';

$unit = isset($_GET['unit']) ? $_GET['unit'] : null;
if (!$unit) {
    die('Unidad no especificada');
}

function load_multiple_choice_questions($pdo, $unit)
{
    $stmt = $pdo->prepare(
        "SELECT data
         FROM activities
         WHERE unit_id = :unit
           AND type = 'multiple_choice'
         LIMIT 1"
    );
    $stmt->execute(array('unit' => $unit));

    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $raw = isset($row['data']) ? $row['data'] : '[]';
    $decoded = json_decode($raw, true);

    if (!is_array($decoded)) {
        return array();
    }

    $isList = array_keys($decoded) === range(0, count($decoded) - 1);
    if ($isList) {
        return $decoded;
    }

    if (isset($decoded['questions']) && is_array($decoded['questions'])) {
        return $decoded['questions'];
    }

    return array();
}

function save_multiple_choice_questions($pdo, $unit, $questions)
{
    $json = json_encode($questions, JSON_UNESCAPED_UNICODE);

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
        $stmt->execute(array(
            'data' => $json,
            'unit' => $unit,
        ));
    } else {
        $stmt = $pdo->prepare(
            "INSERT INTO activities (id, unit_id, type, data)
             VALUES (:id, :unit, 'multiple_choice', :data)"
        );
        $stmt->execute(array(
            'id' => md5(random_bytes(16)),
            'unit' => $unit,
            'data' => $json,
        ));
    }
}

$questions = load_multiple_choice_questions($pdo, $unit);

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

    header('Location: editor.php?unit=' . urlencode($unit) . '&saved=1');
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

                    <button type="button" onclick="removeQuestion(this)" class="btn-remove">✖ Eliminar</button>
                </div>
            </div>
        <?php } ?>
    </div>

    <div class="mc-actions">
        <button type="button" onclick="addQuestion()" class="btn-add">+ Add Question</button>
        <button type="submit" class="btn-save">💾 Save</button>
    </div>
</form>

<style>
.mc-block{
    background:#f9fafb;
    border:1px solid #e5e7eb;
    border-radius:12px;
    padding:14px;
    margin-bottom:14px;
    display:flex;
    flex-direction:column;
    gap:8px;
}

.mc-block label{
    font-size:13px;
    font-weight:bold;
    color:#334155;
}

.mc-block input,
.mc-block select{
    width:100%;
    padding:9px 10px;
    border-radius:8px;
    border:1px solid #cbd5e1;
    font-size:14px;
    box-sizing:border-box;
}

.mc-options-grid{
    display:grid;
    grid-template-columns:repeat(auto-fit, minmax(180px, 1fr));
    gap:8px;
}

.mc-preview{
    width:120px;
    border-radius:8px;
    border:1px solid #e2e8f0;
}

.mc-block-footer{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:10px;
}

.mc-actions{
    display:flex;
    gap:10px;
    margin-top:14px;
}

.btn-add,
.btn-save,
.btn-remove{
    border:none;
    border-radius:8px;
    color:#fff;
    cursor:pointer;
    font-weight:bold;
}

.btn-add{ background:#16a34a; padding:10px 14px; }
.btn-save{ background:#0b5ed7; padding:10px 14px; }
.btn-remove{ background:#ef4444; padding:9px 12px; }
</style>

<script>
function addQuestion(){
    const container = document.getElementById('questions');

    const block = document.createElement('div');
    block.className = 'mc-block';
    block.innerHTML = `
        <label>Question</label>
        <input type="text" name="question[]" placeholder="Question" required>

        <label>Image (optional)</label>
        <input type="file" name="image_file[]" accept="image/*">
        <input type="hidden" name="image[]" value="">

        <label>Options</label>
        <div class="mc-options-grid">
            <input type="text" name="option_a[]" placeholder="Option A" required>
            <input type="text" name="option_b[]" placeholder="Option B" required>
            <input type="text" name="option_c[]" placeholder="Option C" required>
        </div>

        <div class="mc-block-footer">
            <select name="correct[]">
                <option value="0">Correct: A</option>
                <option value="1">Correct: B</option>
                <option value="2">Correct: C</option>
            </select>
            <button type="button" onclick="removeQuestion(this)" class="btn-remove">✖ Eliminar</button>
        </div>
    `;

    container.appendChild(block);
}

function removeQuestion(btn){
    const block = btn.closest('.mc-block');
    if (block) {
        block.remove();
    }
}
</script>

<?php
$content = ob_get_clean();
render_activity_editor('📝 Multiple Choice Editor', '📝', $content);
