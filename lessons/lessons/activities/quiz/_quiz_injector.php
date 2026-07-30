<?php
// Auto-appended only inside activities/quiz. It replaces the legacy quiz card
// exclusively for normalized Fill in the Blank questions. Original activity
// viewers are never loaded, modified, hidden, or intercepted.
$script = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
$modeNow = (string)($GLOBALS['mode'] ?? ($_GET['mode'] ?? ''));
if (!in_array($script, ['viewer.php', 'teacher_viewer.php'], true) || $modeNow !== 'quiz') return;

$quizNow = $GLOBALS['quiz'] ?? null;
$qIndexNow = (int)($GLOBALS['qIndex'] ?? ($_GET['q'] ?? 0));
if (!is_array($quizNow) || !isset($quizNow[$qIndexNow]) || !is_array($quizNow[$qIndexNow])) return;
$qNow = $quizNow[$qIndexNow];
if ((string)($qNow['type'] ?? '') !== 'fill') return;

$question = trim((string)($qNow['question'] ?? ''));
$correctRaw = trim((string)($qNow['correct'] ?? ''));
$answers = array_values(array_filter(array_map('trim', preg_split('/\s*[|,]\s*/', $correctRaw)), static fn($v) => $v !== ''));
if (!$answers && $correctRaw !== '') $answers = [$correctRaw];
$parts = preg_split('/_{3,}/', $question);
$blankCount = max(1, count($parts) - 1);
if (count($answers) < $blankCount && $correctRaw !== '') {
    $answers = array_pad($answers, $blankCount, $correctRaw);
}
$bank = $answers;
shuffle($bank);
$prefix = 'qzfb_' . preg_replace('/[^a-zA-Z0-9_-]/', '', (string)($GLOBALS['unitId'] ?? '0')) . '_' . $qIndexNow;
$audio = trim((string)($qNow['audio'] ?? ''));
$image = trim((string)($qNow['image'] ?? ''));
?>
<div id="<?=$prefix?>_holder" class="qzfb-holder" hidden>
  <div class="qzfb-app" data-qzfb-root>
    <div class="qzfb-hero">
      <div class="qzfb-kicker">Activity</div>
      <h1>Fill in the Blank</h1>
      <p>Write the missing words in the blanks.</p>
    </div>
    <div class="qzfb-stage">
      <?php if ($audio !== ''): ?>
      <div class="qzfb-listen"><button type="button" class="qzfb-btn qzfb-btn-listen" data-listen>Listen</button></div>
      <?php endif; ?>
      <?php if ($image !== ''): ?><div class="qzfb-image"><img src="<?=htmlspecialchars($image, ENT_QUOTES, 'UTF-8')?>" alt="Question image"></div><?php endif; ?>
      <form method="post" data-form>
        <div class="qzfb-sentence" data-sentence>
          <?php if (count($parts) > 1): ?>
            <?php foreach ($parts as $i => $part): ?>
              <span><?=htmlspecialchars($part, ENT_QUOTES, 'UTF-8')?></span>
              <?php if ($i < count($parts)-1): ?><button type="button" class="qzfb-blank" data-blank="<?=$i?>" aria-label="Blank <?=$i+1?>"></button><?php endif; ?>
            <?php endforeach; ?>
          <?php else: ?>
            <span><?=htmlspecialchars($question, ENT_QUOTES, 'UTF-8')?></span>
            <button type="button" class="qzfb-blank" data-blank="0" aria-label="Blank 1"></button>
          <?php endif; ?>
        </div>
        <div class="qzfb-wordbank">
          <div class="qzfb-label">Word bank</div>
          <div class="qzfb-words" data-bank>
            <?php foreach ($bank as $i => $word): ?><button type="button" class="qzfb-chip" data-chip="<?=$i?>" data-word="<?=htmlspecialchars($word, ENT_QUOTES, 'UTF-8')?>"><?=htmlspecialchars($word, ENT_QUOTES, 'UTF-8')?></button><?php endforeach; ?>
          </div>
        </div>
        <input type="hidden" name="answer" data-answer>
        <div class="qzfb-actions">
          <button type="button" class="qzfb-btn qzfb-btn-check" data-check>Check</button>
          <button type="button" class="qzfb-btn qzfb-btn-show" data-show>Show Answer</button>
          <button type="submit" class="qzfb-btn qzfb-btn-next" data-next>Next</button>
          <button type="submit" class="qzfb-btn qzfb-btn-skip" name="skip" value="1" formnovalidate>Skip</button>
        </div>
        <div class="qzfb-feedback" data-feedback></div>
      </form>
    </div>
  </div>
</div>
<style>
.qzfb-holder{width:100%}.qzfb-app{width:min(580px,100%);margin:0 auto;font-family:Nunito,Arial,sans-serif}.qzfb-hero{text-align:center;margin-bottom:18px}.qzfb-kicker{display:inline-flex;padding:3px 14px;border-radius:99px;background:#FFF0E6;color:#F97316;font-size:11px;font-weight:800;letter-spacing:.08em;text-transform:uppercase;margin-bottom:10px}.qzfb-hero h1{font-family:Fredoka,Nunito,sans-serif;font-size:32px;color:#F97316;margin:0;line-height:1}.qzfb-hero p{font-size:13px;color:#9B8FCC;margin:8px 0 0}.qzfb-stage{background:#fff;border:1px solid #EDE9FA;border-radius:24px;box-shadow:0 8px 40px rgba(127,119,221,.13);padding:18px}.qzfb-sentence{background:#F9F8FF;border-radius:14px;padding:18px 20px;font-size:20px;font-weight:600;color:#5A51C0;line-height:1.8;margin-bottom:20px}.qzfb-blank{display:inline-flex;min-width:110px;height:29px;margin:0 6px;padding:2px 12px;vertical-align:middle;border:0;border-bottom:2.5px solid #7F77DD;background:transparent;color:#fff;font:800 15px Nunito;cursor:pointer}.qzfb-blank.filled{align-items:center;justify-content:center;background:#7F77DD;border:0;border-radius:8px;color:#fff}.qzfb-blank.correct{background:#16a34a}.qzfb-blank.wrong{background:#ef4444}.qzfb-wordbank{border:1.5px dashed #C5C1ED;border-radius:14px;padding:14px 16px;margin-bottom:20px}.qzfb-label{font-size:11px;font-weight:800;color:#C5C1ED;letter-spacing:.08em;text-transform:uppercase;margin-bottom:8px}.qzfb-words{display:flex;flex-wrap:wrap;justify-content:center;gap:8px}.qzfb-chip{padding:8px 16px;border-radius:10px;background:#fff;color:#534AB7;border:1.5px solid #7F77DD;box-shadow:0 3px 0 #534AB7;font:900 15px Nunito;cursor:pointer}.qzfb-chip:hover{transform:translateY(-1px);box-shadow:0 4px 0 #534AB7}.qzfb-chip.used{opacity:.35;text-decoration:line-through;box-shadow:none;cursor:default}.qzfb-actions{display:flex;justify-content:center;gap:10px;flex-wrap:wrap}.qzfb-btn{border:0;border-radius:8px;padding:10px 22px;color:#fff;font:800 14px Nunito;cursor:pointer}.qzfb-btn-check,.qzfb-btn-next{background:#F97316}.qzfb-btn-show,.qzfb-btn-listen{background:#7F77DD}.qzfb-btn-skip{background:#fff;color:#7F77DD;border:1px solid #EDE9FA}.qzfb-listen{text-align:center;margin-bottom:14px}.qzfb-image{margin:0 auto 16px;border:1px solid #EDE9FA;border-radius:14px;padding:8px}.qzfb-image img{display:block;width:100%;max-height:280px;object-fit:contain;border-radius:10px}.qzfb-feedback{min-height:20px;margin-top:10px;text-align:center;color:#9B8FCC;font-size:13px;font-weight:800}.qzfb-feedback.good{color:#15803d}.qzfb-feedback.bad{color:#b91c1c}@media(max-width:480px){.qzfb-stage{padding:14px}.qzfb-actions{display:grid;grid-template-columns:1fr}.qzfb-btn{width:100%}.qzfb-sentence{font-size:17px}.qzfb-blank{min-width:82px}}
</style>
<script>
(function(){
 var holder=document.getElementById('<?=$prefix?>_holder');if(!holder)return;
 var oldCard=null,titles=Array.prototype.slice.call(document.querySelectorAll('.screen-title'));
 var title=titles.find(function(el){return /pregunta|question/i.test(el.textContent||'');});
 if(title&&title.nextElementSibling&&title.nextElementSibling.classList.contains('card'))oldCard=title.nextElementSibling;
 if(!oldCard){oldCard=Array.prototype.slice.call(document.querySelectorAll('.card')).find(function(c){return c.querySelector('form');})||null;}
 if(!oldCard)return;oldCard.parentNode.insertBefore(holder,oldCard);oldCard.style.display='none';holder.hidden=false;
 var root=holder.querySelector('[data-qzfb-root]'),form=root.querySelector('[data-form]'),blanks=Array.prototype.slice.call(root.querySelectorAll('[data-blank]')),chips=Array.prototype.slice.call(root.querySelectorAll('[data-chip]')),answer=root.querySelector('[data-answer]'),feedback=root.querySelector('[data-feedback]');
 var expected=<?=json_encode($answers, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?>,audioUrl=<?=json_encode($audio)?>,questionText=<?=json_encode($question, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?>;var selected=null,player=null;
 function norm(v){return String(v||'').toLowerCase().trim().replace(/\s+/g,' ')}
 function sync(){answer.value=blanks.map(function(b){return b.dataset.value||''}).join(' | ')}
 function restore(blank){var id=blank.dataset.chip;if(id!==''){var chip=root.querySelector('[data-chip="'+id+'"]');if(chip)chip.classList.remove('used')}blank.dataset.value='';blank.dataset.chip='';blank.textContent='';blank.className='qzfb-blank';sync()}
 function place(chip,blank){if(blank.dataset.value)restore(blank);blank.dataset.value=chip.dataset.word||chip.textContent.trim();blank.dataset.chip=chip.dataset.chip;blank.textContent=blank.dataset.value;blank.className='qzfb-blank filled';chip.classList.add('used');selected=null;sync()}
 chips.forEach(function(chip){chip.addEventListener('click',function(){if(chip.classList.contains('used'))return;selected=chip;var blank=blanks.find(function(b){return !b.dataset.value});if(blank)place(chip,blank)})});
 blanks.forEach(function(blank){blank.addEventListener('click',function(){if(selected)place(selected,blank);else if(blank.dataset.value)restore(blank)})});
 root.querySelector('[data-check]').addEventListener('click',function(){var ok=true;blanks.forEach(function(b,i){var good=norm(b.dataset.value)===norm(expected[i]||'');b.classList.remove('correct','wrong');b.classList.add(good?'correct':'wrong');if(!good)ok=false});feedback.textContent=ok?'Excellent! All answers are correct.':'Check the red answers and try again.';feedback.className='qzfb-feedback '+(ok?'good':'bad')});
 root.querySelector('[data-show]').addEventListener('click',function(){blanks.forEach(function(b,i){if(b.dataset.value)restore(b);b.dataset.value=expected[i]||'';b.textContent=b.dataset.value;b.className='qzfb-blank filled correct'});chips.forEach(function(c){c.classList.add('used')});sync();feedback.textContent='The correct answer is shown.';feedback.className='qzfb-feedback good'});
 form.addEventListener('submit',function(e){if(e.submitter&&e.submitter.name==='skip')return;sync();if(!answer.value.trim()){e.preventDefault();feedback.textContent='Complete at least one blank before continuing.';feedback.className='qzfb-feedback bad'}});
 var listen=root.querySelector('[data-listen]');if(listen)listen.addEventListener('click',function(){try{if(player){player.pause();player.currentTime=0}if(window.speechSynthesis)window.speechSynthesis.cancel()}catch(e){}if(audioUrl){player=new Audio(audioUrl);player.play().catch(function(){});return}var u=new SpeechSynthesisUtterance(questionText.replace(/_{3,}/g,' blank '));u.lang='en-US';u.rate=.85;window.speechSynthesis.speak(u)});
})();
</script>
