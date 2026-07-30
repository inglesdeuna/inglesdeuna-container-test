<?php
// Unscramble quiz questions reuse the canonical unit activity view.
$sentence=trim((string)($qNow['correct']??''));
$words=is_array($qNow['options']??null)?array_values(array_filter(array_map('trim',$qNow['options']),static fn($value)=>$value!=='')):[];
if(!$words&&$sentence!=='')$words=array_values(array_filter(preg_split('/\s+/u',$sentence),static fn($value)=>$value!==''));
$sentences=[[
    'sentence'=>$sentence,
    'words'=>$words,
    'listen_enabled'=>!array_key_exists('listen_enabled',$qNow)||(bool)$qNow['listen_enabled'],
]];
$viewerTitle='Unscramble the Sentence';
$activityVoiceId=trim((string)($qNow['voice_id']??'nzFihrBIvB34imQBuxub'))?:'nzFihrBIvB34imQBuxub';
$activityId=(string)($qNow['source_activity_id']??preg_replace('/_[0-9]+$/','',(string)($qNow['id']??'')));
$returnTo='';
$usQuizMode=true;
$prefix='qzus_'.preg_replace('/[^a-zA-Z0-9_-]/','',(string)($GLOBALS['unitId']??'0')).'_'.$qIndexNow;
?>
<div id="<?=$prefix?>_holder" class="qzus-holder" hidden>
<?php require __DIR__.'/../unscramble/_unscramble_view.php';?>
<form method="post" id="<?=$prefix?>_submit" style="display:none">
    <input type="hidden" name="answer" id="<?=$prefix?>_answer">
    <input type="hidden" name="skip" id="<?=$prefix?>_skip" value="1" disabled>
</form>
</div>
<script>
window.UNSCRAMBLE_QUIZ_MODE=true;
window.UNSCRAMBLE_TTS_URL='../unscramble/tts.php';
window.UNSCRAMBLE_QUIZ_SUBMIT=function(answer,skipped){
    var form=document.getElementById('<?=$prefix?>_submit'),a=document.getElementById('<?=$prefix?>_answer'),s=document.getElementById('<?=$prefix?>_skip');
    if(!form||!a||!s||form.dataset.submitted==='1')return;
    form.dataset.submitted='1';a.value=String(answer||'');s.disabled=!skipped;form.submit();
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
