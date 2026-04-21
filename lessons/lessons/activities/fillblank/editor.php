
<?php
require_once __DIR__ . '/../../core/_activity_editor_template.php';
?>
<style>
.fbk-card {
  max-width: 700px;
  margin: 32px auto;
  background: linear-gradient(135deg, #ede9fe 0%, #f8fafc 100%);
  border-radius: 22px;
  box-shadow: 0 6px 32px rgba(124,58,237,.07);
  padding: 32px 28px 24px 28px;
  display: flex;
  flex-direction: column;
  gap: 18px;
}
.fbk-title {
  font-family: 'Fredoka', 'Trebuchet MS', sans-serif;
  font-size: 2rem;
  color: #7c3aed;
  font-weight: 800;
  margin-bottom: 8px;
  text-align: center;
}
.fbk-label {
  font-weight: 700;
  color: #5b21b6;
  margin-top: 10px;
  margin-bottom: 4px;
  display: block;
}
.fbk-input, .fbk-textarea {
  width: 100%;
  border: 2px solid #c4b5fd;
  border-radius: 10px;
  padding: 10px 12px;
  font-size: 1rem;
  font-family: 'Nunito', 'Segoe UI', sans-serif;
  margin-bottom: 8px;
  background: #fff;
  box-sizing: border-box;
}
.fbk-textarea { min-height: 90px; resize: vertical; }
.fbk-btn-row {
  display: flex;
  gap: 12px;
  justify-content: flex-end;
  margin-top: 18px;
}
.fbk-btn {
  padding: 12px 28px;
  border: none;
  border-radius: 999px;
  font-family: 'Nunito', 'Segoe UI', sans-serif;
  font-weight: 900;
  font-size: 15px;
  cursor: pointer;
  color: #fff;
  background: linear-gradient(180deg, #8b5cf6 0%, #7c3aed 100%);
  box-shadow: 0 8px 20px rgba(15, 23, 42, .16);
  transition: transform .15s, filter .15s;
}
.fbk-btn:hover { transform: translateY(-2px); filter: brightness(1.06); }
.fbk-btn.secondary {
  background: linear-gradient(180deg, #94a3b8 0%, #64748b 100%);
}
.fbk-answers {
  background: #f3f0ff;
  border-radius: 12px;
  padding: 10px 14px;
  margin-top: 10px;
  color: #5b21b6;
  font-size: 1rem;
}
@media (max-width: 600px) {
  .fbk-card { padding: 12px 4vw; }
  .fbk-title { font-size: 1.3rem; }
}
</style>

<div class="fbk-card">
  <button type="button" onclick="window.history.back()" style="position:absolute;left:calc(50% - 350px);top:24px;background:linear-gradient(90deg,#7c3aed,#14b8a6);color:#fff;border:none;border-radius:8px;padding:8px 18px;font-weight:800;font-size:15px;box-shadow:0 2px 8px rgba(124,58,237,.13);cursor:pointer;z-index:2;">← Back</button>
  <div class="fbk-title">Fill-in-the-Blank Editor</div>
  <form id="fillblankForm">
    <label class="fbk-label">Instructions:</label>
    <input type="text" name="instructions" value="Write the missing words in the blanks." class="fbk-input" />

    <label class="fbk-label">Text (use [blank] for missing words):</label>
    <textarea name="text" rows="6" class="fbk-textarea"></textarea>

    <label class="fbk-label">Word Bank (optional, comma separated):</label>
    <input type="text" name="wordbank" class="fbk-input" />

    <label class="fbk-label">Answer Key (comma separated, in order):</label>
    <input type="text" name="answerkey" class="fbk-input" />

    <div class="fbk-btn-row">
      <button type="button" class="fbk-btn secondary" id="fbk-add-block">+ Add Block</button>
      <button type="submit" class="fbk-btn">Save Activity</button>
    </div>
  </form>
</div>

<script>
// Simulación de "Add Block" (solo UI, no funcionalidad real)
document.getElementById('fbk-add-block').onclick = function() {
  alert('Add Block: Aquí puedes implementar la lógica para agregar bloques de texto o preguntas.');
};
</script>
