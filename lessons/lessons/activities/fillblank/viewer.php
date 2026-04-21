<?php
// Fill-in-the-Blank Activity Viewer
require_once __DIR__ . '/../../core/_activity_viewer_template.php';

// TODO: Load activity data, render text with blanks as input fields, show word bank if present
// Use same design/colors as crossword/quiz
?>
<div class="activity-viewer fillblank-viewer">
  <h2>Fill in the Blanks</h2>
  <div class="instructions">Write the missing words in the blanks.</div>
  <div class="fillblank-text">
    <!-- Rendered text with <input> for blanks -->
    <!-- Example: The cat is <input> the mat. -->
  </div>
  <div class="word-bank">
    <!-- Optional word bank -->
  </div>
  <button class="btn-check">Check Answers</button>
</div>
