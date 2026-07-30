<?php
// Loaded explicitly by both unit-quiz viewers. Fill questions reuse the
// canonical Fill in the Blank markup and JavaScript from activities/fillblank.
$script=basename((string)($_SERVER['SCRIPT_NAME']??''));
$modeNow=(string)($GLOBALS['mode']??($_GET['mode']??''));
if(!in_array($script,['viewer.php','teacher_viewer.php'],true)||$modeNow!=='quiz')return;
$quizNow=$GLOBALS['quiz']??null;
$qIndexNow=(int)($GLOBALS['qIndex']??($_GET['q']??0));
if(!is_array($quizNow)||!isset($quizNow[$qIndexNow])||!is_array($quizNow[$qIndexNow]))return;
$qNow=$quizNow[$qIndexNow];
if((string)($qNow['type']??'')!=='fill')return;
$question=trim((string)($qNow['question']??''));
$correctRaw=trim((string)($qNow['correct']??''));
$answers=array_values(array_filter(array_map('trim',preg_split('/\s*[|,]\s*/',$correctRaw)),static fn($value)=>$value!==''));
if(!$answers&&$correctRaw!=='')$answers=[$correctRaw];
$viewerTitle='Fill in the Blank';
$instructions=trim((string)($qNow['instructions']??'Write the missing words in the blanks.'));
$activity=['instructions'=>$instructions];
$jsQuestions=[['instruction'=>$instructions,'text'=>$question,'answers'=>$answers,'image_url'=>trim((string)($qNow['image']??'')),'options'=>is_array($qNow['options']??null)?array_values($qNow['options']):[]]];
$activityId=(string)($qNow['source_activity_id']??preg_replace('/_[0-9]+$/','',(string)($qNow['id']??'')));
$returnTo='';
$fbQuizMode=true;
$fbMediaUrl=trim((string)($qNow['audio']??''));
$fbMediaType=$fbMediaUrl!==''?'audio':'none';
$fbTtsAudioUrl=$fbMediaUrl;
$fbTtsText=$question;
$fbVoiceId=trim((string)($qNow['voice_id']??'nzFihrBIvB34imQBuxub'))?:'nzFihrBIvB34imQBuxub';
$prefix='qzfb_'.preg_replace('/[^a-zA-Z0-9_-]/','',(string)($GLOBALS['unitId']??'0')).'_'.$qIndexNow;
$quizCount=max(1,count($quizNow));
$quizPosition=min($quizCount,$qIndexNow+1);
?>
<div id="<?=$prefix?>_holder" class="qzfb-holder" hidden>
<?php require __DIR__.'/../fillblank/_fillblank_view.php';?>
<form method="post" id="<?=$prefix?>_submit" style="display:none"><input type="hidden" name="answer" id="<?=$prefix?>_answer"><input type="hidden" name="skip" id="<?=$prefix?>_skip" value="1" disabled></form>
</div>
<script>
window.FILLBLANK_DATA=<?=json_encode($jsQuestions,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?>;
window.FILLBLANK_TITLE=<?=json_encode($viewerTitle,JSON_UNESCAPED_UNICODE)?>;
window.FILLBLANK_RETURN_TO='';window.FILLBLANK_ACTIVITY_ID=<?=json_encode($activityId,JSON_UNESCAPED_UNICODE)?>;
window.FILLBLANK_MEDIA_TYPE=<?=json_encode($fbMediaType)?>;window.FILLBLANK_MEDIA_URL=<?=json_encode($fbMediaUrl,JSON_UNESCAPED_SLASHES)?>;
window.FILLBLANK_TTS_AUDIO_URL=<?=json_encode($fbTtsAudioUrl,JSON_UNESCAPED_SLASHES)?>;window.FILLBLANK_TTS_TEXT=<?=json_encode($fbTtsText,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?>;
window.FILLBLANK_VOICE_ID=<?=json_encode($fbVoiceId)?>;window.FILLBLANK_TTS_URL='../fillblank/tts.php';
window.FILLBLANK_QUIZ_MODE=true;window.FILLBLANK_PROGRESS_CURRENT=<?=$quizPosition?>;window.FILLBLANK_PROGRESS_TOTAL=<?=$quizCount?>;
window.FILLBLANK_QUIZ_SUBMIT=function(answer,skipped){var form=document.getElementById('<?=$prefix?>_submit'),a=document.getElementById('<?=$prefix?>_answer'),s=document.getElementById('<?=$prefix?>_skip');if(!form||!a||!s||form.dataset.submitted==='1')return;form.dataset.submitted='1';a.value=String(answer||'');s.disabled=!skipped;form.submit();};
(function(){var h=document.getElementById('<?=$prefix?>_holder');if(!h)return;var old=null,t=Array.prototype.slice.call(document.querySelectorAll('.screen-title')).find(function(e){return /pregunta|question/i.test(e.textContent||'');});if(t&&t.nextElementSibling&&t.nextElementSibling.classList.contains('card'))old=t.nextElementSibling;if(!old)old=Array.prototype.slice.call(document.querySelectorAll('.card')).find(function(c){return c.querySelector('form');})||null;if(!old)return;old.parentNode.insertBefore(h,old);old.style.display='none';h.hidden=false;})();
</script>
<script src="../fillblank/fillblank.js?v=<?=filemtime(__DIR__.'/../fillblank/fillblank.js')?>"></script>
