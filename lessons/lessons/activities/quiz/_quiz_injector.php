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
$answers = array_values(array_filter(
    array_map('trim', preg_split('/\s*[|,]\s*/', $correctRaw)),
    static fn($value) => $value !== ''
));
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
$quizCount = max(1, count($quizNow));
$quizPosition = min($quizCount, $qIndexNow + 1);
$quizProgress = (int)round(($quizPosition / $quizCount) * 100);

if (!function_exists('qzfb_highlight_caps')) {
    function qzfb_highlight_caps(string $text): string
    {
        $escaped = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
        return (string)preg_replace('/\b([A-Z]{2,}:?)/', '<span class="qzfb-caps-label">$1</span>', $escaped);
    }
}
?>
<div id="<?=$prefix?>_holder" class="qzfb-holder" hidden>
  <div class="qzfb-app" data-qzfb-root>
    <div class="qzfb-hero">
      <div class="qzfb-kicker">Activity</div>
      <h1>Fill in the Blank</h1>
      <p>Write the missing words in the blanks.</p>
    </div>

    <div class="qzfb-stage-shell">
      <div class="qzfb-progress">
        <span class="qzfb-progress-label"><?=$quizPosition?> / <?=$quizCount?></span>
        <div class="qzfb-track"><div class="qzfb-fill" style="width:<?=$quizProgress?>%"></div></div>
        <div class="qzfb-badge">Q <?=$quizPosition?> of <?=$quizCount?></div>
      </div>

      <div class="qzfb-card-shell">
        <?php if ($audio !== ''): ?>
          <div class="qzfb-listen-panel">
            <button type="button" class="qzfb-btn qzfb-btn-listen" data-listen>Listen</button>
          </div>
        <?php endif; ?>

        <form method="post" data-form>
          <div class="qzfb-sentence" data-sentence>
            <?php if (count($parts) > 1): ?>
              <?php foreach ($parts as $i => $part): ?>
                <span><?=qzfb_highlight_caps((string)$part)?></span>
                <?php if ($i < count($parts) - 1): ?>
                  <button type="button" class="qzfb-blank" data-blank="<?=$i?>" aria-label="Blank <?=$i + 1?>"></button>
                <?php endif; ?>
              <?php endforeach; ?>
            <?php else: ?>
              <span><?=qzfb_highlight_caps($question)?></span>
              <button type="button" class="qzfb-blank" data-blank="0" aria-label="Blank 1"></button>
            <?php endif; ?>
          </div>

          <?php if ($image !== ''): ?>
            <div class="qzfb-image-wrap">
              <img src="<?=htmlspecialchars($image, ENT_QUOTES, 'UTF-8')?>" alt="Question image">
            </div>
          <?php endif; ?>

          <div class="qzfb-wordbank">
            <p class="qzfb-wb-label">Word bank</p>
            <div class="qzfb-wb-words" data-bank>
              <?php foreach ($bank as $i => $word): ?>
                <button type="button" class="qzfb-chip" data-chip="<?=$i?>" data-word="<?=htmlspecialchars($word, ENT_QUOTES, 'UTF-8')?>"><?=htmlspecialchars($word, ENT_QUOTES, 'UTF-8')?></button>
              <?php endforeach; ?>
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
</div>

<style>
.qzfb-holder{width:100%}
.qzfb-app{width:min(580px,100%);margin:0 auto;font-family:Nunito,Arial,sans-serif}
.qzfb-hero{text-align:center;margin-bottom:clamp(14px,2vw,22px)}
.qzfb-kicker{display:inline-flex;align-items:center;justify-content:center;padding:3px 14px;border-radius:99px;background:#FFF0E6;border:0;color:#F97316;font-size:11px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;margin-bottom:10px;font-family:Nunito,Arial,sans-serif}
.qzfb-hero h1{font-family:Fredoka,Nunito,sans-serif;font-size:32px;font-weight:700;color:#F97316;margin:0;line-height:1}
.qzfb-hero p{font-size:13px;font-weight:400;color:#9B8FCC;margin:8px 0 0;font-family:Nunito,Arial,sans-serif}
.qzfb-stage-shell{width:min(860px,100%);margin:0 auto;background:#fff;border:1px solid #EDE9FA;border-radius:24px;box-shadow:0 8px 40px rgba(127,119,221,.13);padding:18px}
.qzfb-progress{display:grid;grid-template-columns:auto 1fr auto;align-items:center;gap:12px;margin-bottom:16px}
.qzfb-progress-label{font-size:13px;font-weight:700;color:#9B8FCC;min-width:48px;font-family:Nunito,Arial,sans-serif}
.qzfb-track{height:6px;background:#EDE9FA;border-radius:99px;overflow:hidden}
.qzfb-fill{height:100%;background:linear-gradient(90deg,#F97316,#7F77DD);border-radius:99px}
.qzfb-badge{padding:3px 12px;border-radius:99px;background:#7F77DD;color:#fff;font-size:12px;font-weight:700;font-family:Nunito,Arial,sans-serif}
.qzfb-card-shell{background:#fff;border:1px solid #EDE9FA;border-radius:24px;padding:1.5rem;box-shadow:0 2px 8px rgba(127,119,221,.08);margin-bottom:16px}
.qzfb-sentence{background:#F9F8FF;border-radius:14px;padding:18px 20px;font-size:20px;font-weight:600;color:#5A51C0;line-height:1.6;text-align:left;margin-bottom:1.25rem;display:block;font-family:Nunito,Arial,sans-serif}
.qzfb-caps-label{color:#F97316;font-weight:800}
.qzfb-blank{display:inline-block;border:0;border-bottom:2.5px solid #7F77DD;min-width:110px;height:24px;margin:0 6px;padding:0;vertical-align:bottom;background:transparent;cursor:pointer}
.qzfb-blank.filled{display:inline-flex;align-items:center;justify-content:center;padding:4px 14px;height:auto;border-radius:10px;background:#F5F3FF;border:1.5px solid #7F77DD;color:#534AB7;box-shadow:0 3px 0 #534AB7;font-weight:900;font-family:Nunito,Arial,sans-serif;font-size:clamp(15px,2vw,19px);vertical-align:middle}
.qzfb-blank.correct{background:#f0fdf4;border-color:#16a34a;color:#15803d;box-shadow:0 3px 0 #15803d}
.qzfb-blank.wrong{background:#fef2f2;border-color:#ef4444;color:#b91c1c;box-shadow:0 3px 0 #b91c1c}
.qzfb-wordbank{border:1.5px dashed #C5C1ED;border-radius:14px;padding:14px 16px;margin-bottom:1.25rem}
.qzfb-wb-label{font:700 11px Nunito,Arial,sans-serif;color:#C5C1ED;letter-spacing:.08em;text-transform:uppercase;margin:0 0 8px}
.qzfb-wb-words{display:flex;flex-wrap:wrap;gap:8px;justify-content:center}
.qzfb-chip{display:inline-flex;align-items:center;justify-content:center;padding:8px 16px;border-radius:10px;font-family:Nunito,Arial,sans-serif;font-size:clamp(14px,1.8vw,16px);font-weight:900;cursor:pointer;user-select:none;background:#fff;border:1.5px solid #7F77DD;color:#534AB7;box-shadow:0 3px 0 #534AB7;transition:transform .12s,box-shadow .12s}
.qzfb-chip:hover{transform:translateY(-1px);box-shadow:0 4px 0 #534AB7}
.qzfb-chip:active{transform:translateY(2px);box-shadow:0 1px 0 #534AB7}
.qzfb-chip.used{opacity:.35;cursor:default;box-shadow:none;text-decoration:line-through}
.qzfb-actions{display:flex;justify-content:center;gap:10px;flex-wrap:wrap}
.qzfb-btn{border:0;border-radius:8px;padding:10px 24px;color:#fff;cursor:pointer;font-family:Nunito,Arial,sans-serif;font-size:14px;font-weight:700;transition:.18s}
.qzfb-btn-check,.qzfb-btn-next{background:#F97316}
.qzfb-btn-show,.qzfb-btn-listen{background:#7F77DD}
.qzfb-btn-skip{background:#fff;color:#7F77DD;border:1px solid #EDE9FA}
.qzfb-btn:hover{opacity:.9}
.qzfb-listen-panel{display:flex;align-items:center;justify-content:center;margin-bottom:14px}
.qzfb-image-wrap{margin:0 auto 16px;border:1px solid #EDE9FA;border-radius:14px;padding:8px;background:#fff;max-width:100%}
.qzfb-image-wrap img{width:100%;max-height:280px;object-fit:contain;border-radius:10px;display:block}
.qzfb-feedback{min-height:18px;margin-top:8px;text-align:center;color:#9B8FCC;font-size:13px;font-weight:800}
.qzfb-feedback.good{color:#15803d}
.qzfb-feedback.bad{color:#b91c1c}
@media(max-width:760px){.qzfb-stage-shell{padding:14px}.qzfb-progress{grid-template-columns:1fr;gap:8px}}
@media(max-width:480px){.qzfb-card-shell{padding:1rem}.qzfb-actions{display:grid;grid-template-columns:1fr;gap:9px}.qzfb-btn{width:100%}.qzfb-chip{padding:6px 12px;font-size:12px}.qzfb-blank{min-width:82px}}
</style>

<script>
(function(){
 var holder=document.getElementById('<?=$prefix?>_holder');if(!holder)return;
 var oldCard=null,titles=Array.prototype.slice.call(document.querySelectorAll('.screen-title'));
 var title=titles.find(function(el){return /pregunta|question/i.test(el.textContent||'');});
 if(title&&title.nextElementSibling&&title.nextElementSibling.classList.contains('card'))oldCard=title.nextElementSibling;
 if(!oldCard)oldCard=Array.prototype.slice.call(document.querySelectorAll('.card')).find(function(card){return card.querySelector('form');})||null;
 if(!oldCard)return;
 oldCard.parentNode.insertBefore(holder,oldCard);
 oldCard.style.display='none';
 holder.hidden=false;

 var root=holder.querySelector('[data-qzfb-root]');
 var form=root.querySelector('[data-form]');
 var blanks=Array.prototype.slice.call(root.querySelectorAll('[data-blank]'));
 var chips=Array.prototype.slice.call(root.querySelectorAll('[data-chip]'));
 var answer=root.querySelector('[data-answer]');
 var feedback=root.querySelector('[data-feedback]');
 var expected=<?=json_encode($answers, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?>;
 var audioUrl=<?=json_encode($audio)?>;
 var questionText=<?=json_encode($question, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?>;
 var selected=null,player=null;

 function norm(value){return String(value||'').toLowerCase().trim().replace(/\s+/g,' ')}
 function sync(){answer.value=blanks.map(function(blank){return blank.dataset.value||''}).join(' | ')}
 function restore(blank){
   var id=blank.dataset.chip;
   if(id!==''){var chip=root.querySelector('[data-chip="'+id+'"]');if(chip)chip.classList.remove('used')}
   blank.dataset.value='';
   blank.dataset.chip='';
   blank.textContent='';
   blank.className='qzfb-blank';
   sync();
 }
 function place(chip,blank){
   if(blank.dataset.value)restore(blank);
   blank.dataset.value=chip.dataset.word||chip.textContent.trim();
   blank.dataset.chip=chip.dataset.chip;
   blank.textContent=blank.dataset.value;
   blank.className='qzfb-blank filled';
   chip.classList.add('used');
   selected=null;
   sync();
 }

 chips.forEach(function(chip){
   chip.addEventListener('click',function(){
     if(chip.classList.contains('used'))return;
     selected=chip;
     var blank=blanks.find(function(candidate){return !candidate.dataset.value});
     if(blank)place(chip,blank);
   });
 });
 blanks.forEach(function(blank){
   blank.addEventListener('click',function(){
     if(selected)place(selected,blank);
     else if(blank.dataset.value)restore(blank);
   });
 });
 root.querySelector('[data-check]').addEventListener('click',function(){
   var ok=true;
   blanks.forEach(function(blank,index){
     var good=norm(blank.dataset.value)===norm(expected[index]||'');
     blank.classList.remove('correct','wrong');
     blank.classList.add(good?'correct':'wrong');
     if(!good)ok=false;
   });
   feedback.textContent=ok?'Excellent! All answers are correct.':'Check the red answers and try again.';
   feedback.className='qzfb-feedback '+(ok?'good':'bad');
 });
 root.querySelector('[data-show]').addEventListener('click',function(){
   blanks.forEach(function(blank,index){
     if(blank.dataset.value)restore(blank);
     blank.dataset.value=expected[index]||'';
     blank.textContent=blank.dataset.value;
     blank.className='qzfb-blank filled correct';
   });
   chips.forEach(function(chip){chip.classList.add('used')});
   sync();
   feedback.textContent='The correct answer is shown.';
   feedback.className='qzfb-feedback good';
 });
 form.addEventListener('submit',function(event){
   if(event.submitter&&event.submitter.name==='skip')return;
   sync();
   if(!answer.value.trim()){
     event.preventDefault();
     feedback.textContent='Complete at least one blank before continuing.';
     feedback.className='qzfb-feedback bad';
   }
 });
 var listen=root.querySelector('[data-listen]');
 if(listen)listen.addEventListener('click',function(){
   try{
     if(player){player.pause();player.currentTime=0}
     if(window.speechSynthesis)window.speechSynthesis.cancel();
   }catch(error){}
   if(audioUrl){player=new Audio(audioUrl);player.play().catch(function(){});return}
   var utterance=new SpeechSynthesisUtterance(questionText.replace(/_{3,}/g,' blank '));
   utterance.lang='en-US';
   utterance.rate=.85;
   window.speechSynthesis.speak(utterance);
 });
})();
</script>
