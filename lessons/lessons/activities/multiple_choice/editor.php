<?php
require_once __DIR__ . "/../../config/db.php";

$unit = $_GET['unit'] ?? null;
if (!$unit) die("Unit not specified");

/* ===============================
   GUARDAR
=============================== */
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $question = $_POST["question"] ?? "";
    $options  = $_POST["options"] ?? [];
    $correct  = $_POST["correct"] ?? 0;

    if ($question && count($options) >= 3) {

        $stmt = $pdo->prepare("
            SELECT data FROM activities
            WHERE unit_id = :unit
            AND type = 'multiple_choice'
        ");
        $stmt->execute(["unit"=>$unit]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        $data = json_decode($row["data"] ?? "[]", true);

        $data[] = [
            "question" => $question,
            "options"  => $options,
            "answer"   => $options[$correct] ?? ""
        ];

        if ($row) {
            $update = $pdo->prepare("
                UPDATE activities
                SET data = :data
                WHERE unit_id = :unit
                AND type = 'multiple_choice'
            ");
            $update->execute([
                "data"=>json_encode($data),
                "unit"=>$unit
            ]);
        } else {
            $insert = $pdo->prepare("
                INSERT INTO activities (unit_id, type, data)
                VALUES (:unit,'multiple_choice',:data)
            ");
            $insert->execute([
                "unit"=>$unit,
                "data"=>json_encode($data)
            ]);
        }

        header("Location: editor.php?unit=".$unit);
        exit;
    }
}

/* ===============================
   OBTENER DATA
=============================== */
$stmt = $pdo->prepare("
    SELECT data FROM activities
    WHERE unit_id = :unit
    AND type = 'multiple_choice'
");
$stmt->execute(["unit"=>$unit]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
$data = json_decode($row["data"] ?? "[]", true);

/* ===============================
   TEMPLATE CONFIG
=============================== */
$activityTitle = "🧠 Multiple Choice Editor";
$activitySubtitle = "Add questions for this unit.";

/* ===============================
   CONTENIDO
=============================== */
ob_start();
?>

<form method="POST">

    <input type="text" name="question" placeholder="Enter question" required>

    <input type="text" name="options[]" placeholder="Option 1" required>
    <input type="text" name="options[]" placeholder="Option 2" required>
    <input type="text" name="options[]" placeholder="Option 3" required>

    <label>Correct answer index (0, 1 or 2)</label>
    <input type="number" name="correct" min="0" max="2" required>

    <button type="submit" class="primary-btn">💾 Save</button>

</form>

<hr>

<h3>Saved Questions</h3>

<?php if(empty($data)): ?>
    <p>No questions yet.</p>
<?php else: ?>
    <?php foreach($data as $index=>$q): ?>
        <div class="saved-item">
            <strong><?= htmlspecialchars($q["question"]) ?></strong>

            <form method="POST" action="delete.php" style="display:inline;">
                <input type="hidden" name="index" value="<?= $index ?>">
                <input type="hidden" name="unit" value="<?= $unit ?>">
                <button type="submit" class="delete-btn">✖</button>
            </form>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

<?php
$editorContent = ob_get_clean();
include "../../core/_activity_editor_template.php";
