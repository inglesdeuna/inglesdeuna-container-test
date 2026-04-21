<?php
// Fill-in-the-Blank Activity Editor
require_once __DIR__ . '/../../core/_activity_editor_template.php';

// UI: Title, Instructions, Text Area, Word Bank, Answer Key
// TODO: Implement save/load logic, use same design as crossword/quiz
?>
<div class="activity-editor fillblank-editor">
  <h2>Fill-in-the-Blank Editor</h2>
  <form id="fillblankForm">
    <label>Instructions:</label>
    <input type="text" name="instructions" value="Write the missing words in the blanks." class="input-wide" />
    <label>Text (use [blank] for missing words):</label>
    <textarea name="text" rows="6" class="input-wide"></textarea>
    <label>Word Bank (optional, comma separated):</label>
    <input type="text" name="wordbank" class="input-wide" />
    <label>Answer Key (comma separated, in order):</label>
    <input type="text" name="answerkey" class="input-wide" />
    <button type="submit" class="btn-save">Save Activity</button>
  </form>
</div>
