<?php
// Unscramble Kids quiz questions reuse the canonical unit activity view.
$word=strtoupper(trim((string)($qNow['correct']??'')));
if($word==='')return;
$words=[[
    'word'=>$word,
    'emoji'=>trim((string)($qNow['emoji']??'')),
    'hint'=>trim((string)($qNow['hint']??'')),
    'image'=>trim((string)($qNow['image']??'')),
    'audio'=>trim((string)($qNow['audio']??'')),
    'voice_id'=>trim((string)($qNow['voice_id']??'')),
]];
$viewerTitle=trim((string)($qNow['activity_title']??'Spell the Word'))?:'Spell the Word';
$activityVoiceId=trim((string)($qNow['activity_voice_id']??$qNow['voice_id']??'Nggzl2QAXh3OijoXD116'))?:'Nggzl2QAXh3OijoXD116';
$activityId=(string)($qNow['source_activity_id']??preg_replace('/_[0-9]+$/','',(string)($qNow['id']??'')));
$returnTo='';
$uskQuizMode=true;
$uskTtsUrl='/lessons/lessons/activities/unscramble_kids/tts.php';
$prefix='qzusk_'.preg_replace('/[^a-zA-Z0-9_-]/','',(string)($GLOBALS['unitId']??'0')).'_'.$qIndexNow;
?>
<script>
window.USK_QUIZ_SUBMIT=function(answer,skipped){
    var form=document.getElementById('<?=$prefix?>_submit'),a=document.getElementById('<?=$prefix?>_answer'),s=document.getElementById('<?=$prefix?>_skip');
    if(!form||!a||!s||form.dataset.submitted==='1')return;
    form.dataset.submitted='1';a.value=String(answer||'');s.disabled=!skipped;form.submit();
};
</script>
<div id="<?=$prefix?>_holder" class="qzusk-holder" hidden>
<?php require __DIR__.'/../unscramble_kids/_unscramble_kids_view.php';?>
<form method="post" id="<?=$prefix?>_submit" style="display:none">
    <input type="hidden" name="answer" id="<?=$prefix?>_answer">
    <input type="hidden" name="skip" id="<?=$prefix?>_skip" value="1" disabled>
</form>
</div>
<script>
(function(){
    var h=document.getElementById('<?=$prefix?>_holder');if(!h)return;
    var old=null,t=Array.prototype.slice.call(document.querySelectorAll('.screen-title')).find(function(e){return /pregunta|question/i.test(e.textContent||'');});
    if(t&&t.nextElementSibling&&t.nextElementSibling.classList.contains('card'))old=t.nextElementSibling;
    if(!old)old=Array.prototype.slice.call(document.querySelectorAll('.card')).find(function(c){return c.querySelector('form');})||null;
    if(!old)return;
    old.parentNode.insertBefore(h,old);old.style.display='none';h.hidden=false;
})();
</script>

