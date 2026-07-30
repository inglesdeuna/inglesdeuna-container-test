<?php
// Auto-appended to quiz viewers through .user.ini so the legacy question markup
// is replaced by the same shared renderer used by Admin Eval.
$script = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
$modeNow = (string)($GLOBALS['mode'] ?? ($_GET['mode'] ?? ''));
if (!in_array($script, ['viewer.php', 'teacher_viewer.php'], true) || $modeNow !== 'quiz') {
    return;
}
$quizNow = $GLOBALS['quiz'] ?? null;
$qIndexNow = (int)($GLOBALS['qIndex'] ?? ($_GET['q'] ?? 0));
if (!is_array($quizNow) || !isset($quizNow[$qIndexNow]) || !is_array($quizNow[$qIndexNow])) {
    return;
}
require_once __DIR__ . '/_question_renderer.php';
$qNow = $quizNow[$qIndexNow];
$prefix = 'unit_shared_' . preg_replace('/[^a-zA-Z0-9_-]/', '', (string)($GLOBALS['unitId'] ?? '0')) . '_' . $qIndexNow;
$shared = qzr_render($qNow, [
    'prefix' => $prefix,
    'submit_label' => 'Next',
    'skip_label' => 'Skip',
]);
?>
<div id="qzr-unit-shared" style="display:none">
  <div class="qzr-unit-card"><?=$shared?></div>
</div>
<style>
.qzr-unit-card{width:min(900px,calc(100% - 32px));margin:0 auto;background:#fff;border:1px solid #EDE9FA;border-radius:24px;padding:24px;box-shadow:0 8px 30px rgba(127,119,221,.12)}
</style>
<script>
(function(){
  var holder=document.getElementById('qzr-unit-shared');
  if(!holder)return;
  var titles=Array.prototype.slice.call(document.querySelectorAll('.screen-title'));
  var title=titles.find(function(el){return /pregunta|question/i.test(el.textContent||'');});
  var oldCard=title&&title.nextElementSibling&&title.nextElementSibling.classList.contains('card')?title.nextElementSibling:null;
  if(!oldCard){
    var cards=Array.prototype.slice.call(document.querySelectorAll('.card'));
    oldCard=cards.find(function(card){return card.querySelector('form');})||null;
  }
  if(!oldCard)return;
  holder.style.display='block';
  oldCard.parentNode.insertBefore(holder,oldCard);
  oldCard.style.display='none';
})();
</script>
