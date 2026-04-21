
<?php
require_once __DIR__ . '/../../core/_activity_viewer_template.php';
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
  color: #14b8a6;
  font-weight: 800;
  margin-bottom: 8px;
  text-align: center;
}
.fbk-instructions {
  font-weight: 700;
  color: #0e7490;
  margin-bottom: 8px;
  text-align: center;
}
.fbk-wordbank {
  background: #f3f0ff;
  border-radius: 12px;
  padding: 8px 14px;
  margin: 10px 0 0 0;
  color: #5b21b6;
  font-size: 1rem;
  text-align: center;
}
.fbk-text {
  font-size: 1.1rem;
  margin: 18px 0 10px 0;
  color: #334155;
  line-height: 1.7;
  text-align: left;
}
.fbk-blank-input {
  width: 110px;
  border: 2px solid #7dd3fc;
  border-radius: 8px;
  padding: 6px 10px;
  font-size: 1rem;
  margin: 0 4px;
  background: #fff;
  font-family: 'Nunito', 'Segoe UI', sans-serif;
}
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
  background: linear-gradient(180deg, #14b8a6 0%, #7c3aed 100%);
  box-shadow: 0 8px 20px rgba(15, 23, 42, .16);
  transition: transform .15s, filter .15s;
}
.fbk-btn:hover { transform: translateY(-2px); filter: brightness(1.06); }
.fbk-btn.secondary {
  background: linear-gradient(180deg, #94a3b8 0%, #64748b 100%);
}
.fbk-feedback {
  margin-top: 18px;
  font-size: 1.1rem;
  color: #7c3aed;
  text-align: center;
  min-height: 24px;
}
@media (max-width: 600px) {
  .fbk-card { padding: 12px 4vw; }
  .fbk-title { font-size: 1.3rem; }
  .fbk-blank-input { width: 70px; }
}
</style>

<div class="fbk-card">
  <div class="fbk-title">Fill-in-the-Blank Activity</div>
  <div class="fbk-instructions" id="fbk-instructions">Write the missing words in the blanks.</div>
  <div class="fbk-wordbank" id="fbk-wordbank" style="display:none;"></div>
  <form id="fbk-form">
    <div class="fbk-text" id="fbk-text"></div>
    <div class="fbk-btn-row">
      <button type="button" class="fbk-btn secondary">Previous</button>
      <button type="submit" class="fbk-btn">Submit Answers</button>
      <button type="button" class="fbk-btn secondary">Next</button>
    </div>
  </form>
  <div class="fbk-feedback" id="fbk-feedback"></div>
</div>

<script>
// --- Placeholder: Load activity data (simulate) ---
const activityData = {
  instructions: 'Write the missing words in the blanks.',
  text: 'The [blank] jumps over the [blank] dog.',
  wordbank: 'quick, lazy',
  answerkey: 'quick,lazy'
};

// Render instructions, wordbank, and blanks
document.getElementById('fbk-instructions').textContent = activityData.instructions;
if (activityData.wordbank) {
  document.getElementById('fbk-wordbank').textContent = 'Word Bank: ' + activityData.wordbank;
  document.getElementById('fbk-wordbank').style.display = '';
}

function renderTextWithBlanks(text) {
  let idx = 0;
  return text.replace(/\[blank\]/g, function() {
    return `<input class=\"fbk-blank-input\" name=\"blank${++idx}\" autocomplete=\"off\" />`;
  });
}
document.getElementById('fbk-text').innerHTML = renderTextWithBlanks(activityData.text);

// Handle form submit
document.getElementById('fbk-form').onsubmit = function(e) {
  e.preventDefault();
  // Simple check: compare input values to answerkey
  const answers = activityData.answerkey.split(',');
  let correct = 0;
  for (let i = 0; i < answers.length; i++) {
    const val = document.querySelector(`[name=blank${i+1}]`).value.trim().toLowerCase();
    if (val === answers[i].trim().toLowerCase()) correct++;
  }
  const fb = document.getElementById('fbk-feedback');
  if (correct === answers.length) {
    fb.textContent = '✅ All correct!';
    fb.style.color = '#14b8a6';
  } else {
    fb.textContent = `❌ ${correct} of ${answers.length} correct. Try again!`;
    fb.style.color = '#7c3aed';
  }
};
</script>
