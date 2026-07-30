<?php
// Multiple Choice quiz questions reuse the canonical unit activity view.
$questions=[[
    'question_type'=>(string)($qNow['question_type']??'text')==='listen'?'listen':'text',
    'question'=>trim((string)($qNow['question']??'')),
    'audio'=>trim((string)($qNow['audio']??'')),
    'voice_id'=>trim((string)($qNow['voice_id']??'josh'))?:'josh',
    'image'=>trim((string)($qNow['image']??'')),
    'option_type'=>(string)($qNow['option_type']??'text')==='image'?'image':'text',
    'options'=>is_array($qNow['options']??null)?array_values($qNow['options']):[],
    'option_images'=>is_array($qNow['option_images']??null)?array_values($qNow['option_images']):[],
    'correct'=>(int)($qNow['correct']??0),
]];
$viewerTitle='Multiple Choice';
$activityMode=in_array((string)($qNow['activity_mode']??''),['text','listening'],true)?(string)$qNow['activity_mode']:'standard';
$passage=(string)($qNow['passage']??'');
$passageVoiceId=trim((string)($qNow['passage_voice_id']??'josh'))?:'josh';
$showPassageText=!array_key_exists('show_passage_text',$qNow)||(bool)$qNow['show_passage_text'];
$activityId=(string)($qNow['source_activity_id']??preg_replace('/_[0-9]+$/','',(string)($qNow['id']??'')));
$returnTo='';
$mcQuizMode=true;
$prefix='qzmc_'.preg_replace('/[^a-zA-Z0-9_-]/','',(string)($GLOBALS['unitId']??'0')).'_'.$qIndexNow;
$quizCount=max(1,count($quizNow));
$quizPosition=min($quizCount,$qIndexNow+1);
?>
<div id="<?=$prefix?>_holder" class="qzmc-holder" hidden>
<?php require __DIR__.'/../multiple_choice/_multiple_choice_view.php';?>
<form method="post" id="<?=$prefix?>_submit" style="display:none">
    <input type="hidden" name="answer" id="<?=$prefix?>_answer">
    <input type="hidden" name="skip" id="<?=$prefix?>_skip" value="1" disabled>
</form>
</div>
<script>
window.MULTIPLE_CHOICE_DATA=<?=json_encode($questions,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?>;
window.MULTIPLE_CHOICE_TITLE=<?=json_encode($viewerTitle,JSON_UNESCAPED_UNICODE)?>;
window.MULTIPLE_CHOICE_RETURN_TO='';window.MULTIPLE_CHOICE_ACTIVITY_ID=<?=json_encode($activityId,JSON_UNESCAPED_UNICODE)?>;
window.MULTIPLE_CHOICE_MODE=<?=json_encode($activityMode)?>;
window.MULTIPLE_CHOICE_PASSAGE=<?=json_encode($passage,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?>;
window.MULTIPLE_CHOICE_PASSAGE_VOICE_ID=<?=json_encode($passageVoiceId)?>;
window.MULTIPLE_CHOICE_SHOW_PASSAGE_TEXT=<?=$showPassageText?'true':'false'?>;
window.MULTIPLE_CHOICE_QUIZ_MODE=true;
window.MULTIPLE_CHOICE_PROGRESS_CURRENT=<?=$quizPosition?>;window.MULTIPLE_CHOICE_PROGRESS_TOTAL=<?=$quizCount?>;
window.MULTIPLE_CHOICE_TTS_URL='../multiple_choice/tts.php';
window.MULTIPLE_CHOICE_QUIZ_SUBMIT=function(answer,skipped){
    var form=document.getElementById('<?=$prefix?>_submit'),a=document.getElementById('<?=$prefix?>_answer'),s=document.getElementById('<?=$prefix?>_skip');
    if(!form||!a||!s||form.dataset.submitted==='1')return;
    form.dataset.submitted='1';a.value=String(answer);s.disabled=!skipped;form.submit();
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
<script src="../multiple_choice/multiple_choice.js?v=<?=filemtime(__DIR__.'/../multiple_choice/multiple_choice.js')?>"></script>
