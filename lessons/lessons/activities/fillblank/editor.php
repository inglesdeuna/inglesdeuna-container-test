

<?php
require_once __DIR__ . '/../../core/_activity_editor_template.php';

ob_start();
?>
<form id="fillblankForm">
  <div class="mb-3">
    <label class="form-label fw-bold">Instructions:</label>
    <input type="text" name="instructions" value="Write the missing words in the blanks." class="form-control" />
  </div>
  <div class="mb-3">
    <label class="form-label fw-bold">Text (use [blank] for missing words):</label>
    <textarea name="text" rows="6" class="form-control"></textarea>
  </div>
  <div class="mb-3">
    <label class="form-label fw-bold">Word Bank (optional, comma separated):</label>
    <input type="text" name="wordbank" class="form-control" />
  </div>
  <div class="mb-3">
    <label class="form-label fw-bold">Answer Key (comma separated, in order):</label>
    <input type="text" name="answerkey" class="form-control" />
  </div>
  <div class="d-flex gap-2 justify-content-end mt-4">
    <button type="button" class="btn btn-outline-primary" id="fbk-add-block">+ Add Block</button>
    <button type="submit" class="btn btn-success fw-bold">Save Activity</button>
  </div>
</form>
<script>
// Simulación de "Add Block" (solo UI, no funcionalidad real)
document.getElementById('fbk-add-block').onclick = function() {
  alert('Add Block: Aquí puedes implementar la lógica para agregar bloques de texto o preguntas.');
};
</script>
<?php
$content = ob_get_clean();
render_activity_editor('Fill-in-the-Blank Editor', 'fa-solid fa-pen-to-square', $content);
?>
