<?php
// Quiz-only pronunciation view. It mirrors the unit activity while submitting
// one recognized phrase to the quiz's normal scoring flow.
$prefix='qzpron_'.preg_replace('/[^a-zA-Z0-9_-]/','',(string)($GLOBALS['unitId']??'0')).'_'.$qIndexNow;
$expected=trim((string)($qNow['correct']??$qNow['question']??''));
$image=trim((string)($qNow['image']??''));
$audio=trim((string)($qNow['audio']??''));
$phonetic=trim((string)($qNow['ph']??''));
$voiceId=trim((string)($qNow['voice_id']??'nzFihrBIvB34imQBuxub'))?:'nzFihrBIvB34imQBuxub';
$quizCount=max(1,count($quizNow));
$quizPosition=min($quizCount,$qIndexNow+1);
?>
<style>
.qzpron-holder{width:min(760px,100%);margin:36px auto 0;font-family:Nunito,'Segoe UI',sans-serif}.qzpron-board{background:#fff;border:1px solid #F0EEF8;border-radius:34px;padding:clamp(16px,2.6vw,26px);box-shadow:0 8px 40px rgba(127,119,221,.13)}.qzpron-progress{display:flex;align-items:center;gap:10px;margin-bottom:16px}.qzpron-track{flex:1;height:12px;background:#F4F2FD;border:1px solid #E4E1F8;border-radius:999px;overflow:hidden}.qzpron-fill{height:100%;background:linear-gradient(90deg,#F97316,#7F77DD);border-radius:999px}.qzpron-count{min-width:74px;text-align:center;padding:7px 11px;border-radius:999px;background:#7F77DD;color:#fff;font-size:12px;font-weight:900}.qzpron-card{min-height:430px;border:1px solid #EDE9FA;border-radius:30px;background:#fff;padding:18px;display:flex;flex-direction:column;align-items:center;justify-content:flex-start;text-align:center;box-shadow:0 8px 24px rgba(127,119,221,.09)}.qzpron-cue{display:inline-flex;margin-bottom:12px;padding:6px 13px;border-radius:999px;background:#EEEDFE;color:#534AB7;font-size:12px;font-weight:900}.qzpron-image{width:100%;max-width:680px;height:300px;margin-bottom:14px;border-radius:24px;background:#fff;border:1px solid #EDE9FA;display:flex;align-items:center;justify-content:center;overflow:hidden;padding:10px}.qzpron-image img{width:100%;height:100%;object-fit:contain;border-radius:18px}.qzpron-word{max-width:620px;font-size:clamp(30px,5.4vw,52px);font-weight:900;line-height:1.18;color:#534AB7;overflow-wrap:anywhere}.qzpron-ph{margin-top:8px;color:#4B5563;font-size:17px;font-weight:800}.qzpron-box{width:100%;max-width:620px;margin-top:10px;border-radius:12px;padding:9px 12px;font-size:13px;font-weight:800;text-align:center}.qzpron-captured{border:1px solid #EDE9FA;background:#fff;color:#534AB7}.qzpron-captured.ok{border-color:#bbf7d0;background:#f0fdf4;color:#166534}.qzpron-captured.bad{border-color:#fecaca;background:#fef2f2;color:#991b1b}.qzpron-feedback{color:#9B94BE}.qzpron-feedback.bad{color:#991b1b}.qzpron-actions{display:flex;justify-content:center;gap:10px;flex-wrap:wrap;margin-top:16px;padding-top:16px;border-top:1px solid #F0EEF8}.qzpron-btn{border:0;border-radius:10px;min-width:120px;padding:13px 20px;color:#fff;font:900 13px Nunito,sans-serif;cursor:pointer}.qzpron-purple{background:#7F77DD}.qzpron-orange{background:#F97316}.qzpron-light{background:#fff;color:#7F77DD;border:1px solid #EDE9FA}@media(max-width:640px){.qzpron-holder{margin-top:18px}.qzpron-board{padding:14px;border-radius:26px}.qzpron-card{min-height:390px;padding:14px;border-radius:24px}.qzpron-image{height:270px}.qzpron-actions{display:grid;grid-template-columns:1fr}.qzpron-btn{width:100%}}
</style>
<div id="<?=$prefix?>_holder" class="qzpron-holder" hidden>
 <section class="qzpron-board" data-az-zoom>
  <div class="qzpron-progress"><div style="color:#7F77DD;font-size:12px;font-weight:900"><?=$quizPosition?> / <?=$quizCount?></div><div class="qzpron-track"><div class="qzpron-fill" style="width:<?=max(1,(int)round(($quizPosition/$quizCount)*100))?>%"></div></div><div class="qzpron-count">Q <?=$quizPosition?> of <?=$quizCount?></div></div>
  <div class="qzpron-card">
   <div class="qzpron-cue">Listen first</div>
   <?php if($image!==''):?><div class="qzpron-image"><img src="<?=qz_h($image)?>" alt="<?=qz_h($expected)?>"></div><?php endif;?>
   <div class="qzpron-word"><?=qz_h($expected)?></div>
   <?php if($phonetic!==''):?><div class="qzpron-ph"><?=qz_h($phonetic)?></div><?php endif;?>
   <div class="qzpron-box qzpron-captured" id="<?=$prefix?>_captured"></div>
   <div class="qzpron-box qzpron-feedback" id="<?=$prefix?>_feedback"></div>
  </div>
  <div class="qzpron-actions">
   <button type="button" class="qzpron-btn qzpron-purple" id="<?=$prefix?>_listen">Listen</button>
   <button type="button" class="qzpron-btn qzpron-purple" id="<?=$prefix?>_speak">Speaker</button>
   <button type="button" class="qzpron-btn qzpron-orange" id="<?=$prefix?>_next"><?=$quizPosition>=$quizCount?'See result →':'Next →'?></button>
   <button type="button" class="qzpron-btn qzpron-light" id="<?=$prefix?>_skip">Skip</button>
  </div>
 </section>
 <form method="post" id="<?=$prefix?>_submit" hidden><input type="hidden" name="answer" id="<?=$prefix?>_answer"><input type="hidden" name="skip" id="<?=$prefix?>_skip_value" value="1" disabled></form>
</div>
<script>
(function(){
var p=<?=json_encode($prefix)?>,expected=<?=json_encode($expected,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?>,audioUrl=<?=json_encode($audio,JSON_UNESCAPED_SLASHES)?>,voiceId=<?=json_encode($voiceId)?>;
var holder=document.getElementById(p+'_holder'),captured=document.getElementById(p+'_captured'),feedback=document.getElementById(p+'_feedback'),listen=document.getElementById(p+'_listen'),speak=document.getElementById(p+'_speak'),next=document.getElementById(p+'_next'),skip=document.getElementById(p+'_skip'),answer=document.getElementById(p+'_answer'),skipValue=document.getElementById(p+'_skip_value'),form=document.getElementById(p+'_submit'),recorded='',activeAudio=null,ttsUrl='',busy=false;
var old=null,title=[].slice.call(document.querySelectorAll('.screen-title')).find(function(e){return /pregunta|question/i.test(e.textContent||'');});if(title&&title.nextElementSibling&&title.nextElementSibling.classList.contains('card'))old=title.nextElementSibling;if(!old)old=[].slice.call(document.querySelectorAll('.card')).find(function(c){return c.querySelector('form');})||null;if(!old||!holder)return;old.parentNode.insertBefore(holder,old);old.style.display='none';holder.hidden=false;
function setListenLabel(v){listen.textContent=v||'Listen';}
function browserSpeak(){if(!('speechSynthesis'in window))return;window.speechSynthesis.cancel();var u=new SpeechSynthesisUtterance(expected);u.lang='en-US';u.rate=.82;u.onstart=function(){setListenLabel('Pause');};u.onend=function(){setListenLabel();};u.onerror=function(){setListenLabel();};window.speechSynthesis.speak(u);}
function playUrl(url){activeAudio=new Audio(url);activeAudio.onended=function(){setListenLabel();};activeAudio.onerror=browserSpeak;setListenLabel('Pause');activeAudio.play().catch(browserSpeak);}
listen.addEventListener('click',function(){if(activeAudio&&!activeAudio.paused){activeAudio.pause();setListenLabel('Resume');return;}if(activeAudio&&activeAudio.paused&&activeAudio.currentTime>0){activeAudio.play();setListenLabel('Pause');return;}if(audioUrl||ttsUrl){playUrl(audioUrl||ttsUrl);return;}var fd=new FormData();fd.append('text',expected);fd.append('voice_id',voiceId);fetch('../pronunciation/tts.php',{method:'POST',body:fd,credentials:'same-origin'}).then(function(r){return r.json();}).then(function(j){if(!j||!j.url)throw Error('tts');ttsUrl=j.url;playUrl(ttsUrl);}).catch(browserSpeak);});
var Ctor=window.SpeechRecognition||window.webkitSpeechRecognition,recognition=Ctor?new Ctor():null;if(recognition){recognition.lang='en-US';recognition.interimResults=false;recognition.maxAlternatives=1;recognition.continuous=false;}
speak.addEventListener('click',function(){if(!recognition||busy){feedback.textContent=recognition?'Please wait.':'Speech recognition is not available in this browser.';feedback.className='qzpron-box qzpron-feedback bad';return;}busy=true;speak.disabled=true;feedback.textContent='Listening...';feedback.className='qzpron-box qzpron-feedback';recognition.onresult=function(e){recorded=String(e.results&&e.results[0]&&e.results[0][0]&&e.results[0][0].transcript||'').trim();captured.textContent=recorded?'You said: '+recorded:'Could not capture voice. Try again.';captured.className='qzpron-box qzpron-captured'+(recorded?'':' bad');feedback.textContent=recorded?'Recorded. Press Next to continue.':'Try again.';};recognition.onerror=function(){captured.textContent='Could not capture voice. Try again.';captured.className='qzpron-box qzpron-captured bad';feedback.textContent='Try again.';feedback.className='qzpron-box qzpron-feedback bad';};recognition.onend=function(){busy=false;speak.disabled=false;};try{recognition.start();}catch(e){busy=false;speak.disabled=false;}});
function submit(skipped){if(form.dataset.submitted==='1')return;if(!skipped&&!recorded){feedback.textContent='Use Speaker and record your pronunciation before continuing.';feedback.className='qzpron-box qzpron-feedback bad';return;}form.dataset.submitted='1';answer.value=recorded;skipValue.disabled=!skipped;form.submit();}
next.addEventListener('click',function(){submit(false);});skip.addEventListener('click',function(){submit(true);});
})();
</script>
