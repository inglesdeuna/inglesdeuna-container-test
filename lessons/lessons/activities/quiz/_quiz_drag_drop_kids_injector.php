<?php
// Drag & Drop Kids quiz questions reuse the canonical unit activity view.
$pairs=is_array($qNow['pairs']??null)?array_values($qNow['pairs']):[];
$bgImage=trim((string)($qNow['background_image']??''));
if(!$pairs||$bgImage==='')return;
$title=trim((string)($qNow['activity_title']??'Drag & Drop'))?:'Drag & Drop';
$instructions=trim((string)($qNow['instructions']??'Drag each word to the correct place on the image.'));
$activityId=(string)($qNow['source_activity_id']??$qNow['id']??'');
$returnTo='';
$ddkQuizMode=true;
$prefix='qzddk_'.preg_replace('/[^a-zA-Z0-9_-]/','',(string)($GLOBALS['unitId']??'0')).'_'.$qIndexNow;
?>
<script>
window.DDK_QUIZ_SUBMIT=function(placements,skipped){
    var form=document.getElementById('<?=$prefix?>_submit'),a=document.getElementById('<?=$prefix?>_answer'),s=document.getElementById('<?=$prefix?>_skip');
    if(!form||!a||!s||form.dataset.submitted==='1')return;
    form.dataset.submitted='1';a.value=JSON.stringify(placements||{});s.disabled=!skipped;form.submit();
};
</script>
<div id="<?=$prefix?>_holder" class="qzddk-holder" hidden>
<?php require __DIR__.'/../drag_drop_kids/_drag_drop_kids_view.php';?>
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

