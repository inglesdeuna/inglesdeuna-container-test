<?php
// Drag & Drop quiz questions reuse the canonical unit activity view.
$slotWords=is_array($qNow['correct_words']??null)?array_values(array_filter(array_map('trim',$qNow['correct_words']),static fn($value)=>$value!=='')):[];
if(!$slotWords){
    $correctRaw=trim((string)($qNow['correct']??''));
    if($correctRaw!=='')$slotWords=array_values(array_filter(array_map('trim',preg_split('/\s*[|,]\s*/',$correctRaw)),static fn($value)=>$value!==''));
}
$wordBank=is_array($qNow['options']??null)?array_values(array_filter(array_map('trim',$qNow['options']),static fn($value)=>$value!=='')):[];
if(!$wordBank)$wordBank=$slotWords;
$instruction=trim((string)($qNow['instruction']??$qNow['question']??''));
if($instruction==='')$instruction=implode(' ',array_fill(0,max(1,count($slotWords)),'___'));
$viewerTitle='Drag & Drop';
$questions=[[
    'instruction'=>$instruction,
    'slots'=>array_map(static fn($word)=>['answer'=>$word],$slotWords),
    'words'=>$wordBank,
    'image'=>trim((string)($qNow['image']??'')),
    'tts_text'=>trim((string)($qNow['listen_text']??$qNow['correct']??'')),
    'listen_enabled'=>!empty($qNow['listen_enabled']),
    'voice_id'=>trim((string)($qNow['voice_id']??'nzFihrBIvB34imQBuxub'))?:'nzFihrBIvB34imQBuxub',
]];
$activityId=(string)($qNow['source_activity_id']??preg_replace('/_[0-9]+$/','',(string)($qNow['id']??'')));
$returnTo='';
$ddQuizMode=true;
$prefix='qzdd_'.preg_replace('/[^a-zA-Z0-9_-]/','',(string)($GLOBALS['unitId']??'0')).'_'.$qIndexNow;
$quizCount=max(1,count($quizNow));
$quizPosition=min($quizCount,$qIndexNow+1);
?>
<div id="<?=$prefix?>_holder" class="qzdd-holder" hidden>
<?php require __DIR__.'/../drag_drop/_drag_drop_view.php';?>
<form method="post" id="<?=$prefix?>_submit" style="display:none"><input type="hidden" name="skip" id="<?=$prefix?>_skip" value="1" disabled></form>
</div>
<script src="../../core/_activity_feedback.js"></script>
<script>
window.DRAGDROP_DATA=<?=json_encode($questions,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?>;
window.DRAGDROP_TITLE=<?=json_encode($viewerTitle,JSON_UNESCAPED_UNICODE)?>;
window.DRAGDROP_RETURN_TO='';window.DRAGDROP_ACTIVITY_ID=<?=json_encode($activityId,JSON_UNESCAPED_UNICODE)?>;
window.DRAGDROP_QUIZ_MODE=true;window.DRAGDROP_PROGRESS_CURRENT=<?=$quizPosition?>;window.DRAGDROP_PROGRESS_TOTAL=<?=$quizCount?>;
window.DRAGDROP_TTS_URL='/lessons/lessons/activities/drag_drop/tts.php';
window.DRAGDROP_QUIZ_SUBMIT=function(values,skipped){
    var form=document.getElementById('<?=$prefix?>_submit'),s=document.getElementById('<?=$prefix?>_skip');
    if(!form||!s||form.dataset.submitted==='1')return;
    Array.prototype.slice.call(form.querySelectorAll('input[data-dd-answer]')).forEach(function(input){input.remove();});
    (Array.isArray(values)?values:[]).forEach(function(value,index){
        var input=document.createElement('input');
        input.type='hidden';input.name='answer['+index+']';input.value=String(value||'');input.setAttribute('data-dd-answer','1');
        form.appendChild(input);
    });
    form.dataset.submitted='1';s.disabled=!skipped;form.submit();
};
(function(){
    var h=document.getElementById('<?=$prefix?>_holder');if(!h)return;
    var old=null,t=Array.prototype.slice.call(document.querySelectorAll('.screen-title')).find(function(e){return /pregunta|question/i.test(e.textContent||'');});
    if(t&&t.nextElementSibling&&t.nextElementSibling.classList.contains('card'))old=t.nextElementSibling;
    if(!old)old=Array.prototype.slice.call(document.querySelectorAll('.card')).find(function(c){return c.querySelector('form');})||null;
    if(!old)return;
    old.parentNode.insertBefore(h,old);old.style.display='none';h.hidden=false;
})();
</script>
<script src="../drag_drop/drag_drop.js?v=<?=filemtime(__DIR__.'/../drag_drop/drag_drop.js')?>"></script>
