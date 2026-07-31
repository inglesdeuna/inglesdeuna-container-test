<?php
// Dictation quiz questions reuse the unit Dictation design while quiz mode
// keeps only Listen, Next and Skip.
$dictText=trim((string)($qNow['correct']??''));
$dictAudio=trim((string)($qNow['audio']??''));
$dictImage=trim((string)($qNow['image']??''));
$dictVoiceId=trim((string)($qNow['voice_id']??'nzFihrBIvB34imQBuxub'))?:'nzFihrBIvB34imQBuxub';
$prefix='qzdict_'.preg_replace('/[^a-zA-Z0-9_-]/','',(string)($GLOBALS['unitId']??'0')).'_'.$qIndexNow;
$quizCount=max(1,count($quizNow));
$quizPosition=min($quizCount,$qIndexNow+1);
$dictProgress=(int)round(($quizPosition/$quizCount)*100);
?>
<div id="<?=$prefix?>_holder" class="qzdict-holder" hidden>
  <div class="dict-page">
    <div class="dict-app">
      <div class="dict-board" data-az-zoom>
        <div class="dict-progress">
          <div class="dict-progress-track"><div class="dict-progress-fill" style="width:<?=$dictProgress?>%"></div></div>
          <div class="dict-status"><?=$quizPosition?> / <?=$quizCount?></div>
        </div>
        <div class="dict-card">
          <div class="dict-listen-row">
            <button type="button" class="dict-btn dict-btn-listen" id="<?=$prefix?>_listen">Listen</button>
          </div>
          <?php if($dictImage!==''):?><img class="dict-image" src="<?=htmlspecialchars($dictImage,ENT_QUOTES,'UTF-8')?>" alt="Dictation image"><?php endif;?>
          <textarea class="dict-answer-box" id="<?=$prefix?>_input" placeholder="Write what you hear..." autocomplete="off"></textarea>
        </div>
        <div class="dict-controls">
          <button type="button" class="dict-btn dict-btn-next" id="<?=$prefix?>_next"><?=$quizPosition>=$quizCount?'See result →':'Next →'?></button>
          <button type="button" class="dict-btn dict-btn-skip" id="<?=$prefix?>_skip_button">Skip</button>
        </div>
      </div>
    </div>
  </div>
  <form method="post" id="<?=$prefix?>_submit" style="display:none">
    <input type="hidden" name="answer" id="<?=$prefix?>_answer">
    <input type="hidden" name="skip" id="<?=$prefix?>_skip" value="1" disabled>
  </form>
</div>
<style>
.qzdict-holder{width:100%;font-family:'Nunito','Segoe UI',sans-serif}
.qzdict-holder .dict-page{width:100%;padding:0;display:flex;align-items:flex-start;justify-content:center;background:transparent;box-sizing:border-box}
.qzdict-holder .dict-app{width:min(860px,100%);margin:0 auto}
.qzdict-holder .dict-board{background:#fff;border:1px solid #F0EEF8;border-radius:34px;padding:clamp(16px,2.6vw,26px);box-shadow:0 8px 40px rgba(127,119,221,.13);width:min(760px,100%);margin:0 auto;box-sizing:border-box;position:relative}
.qzdict-holder .dict-progress{display:flex;align-items:center;gap:10px;margin-bottom:18px}
.qzdict-holder .dict-progress-track{flex:1;height:12px;background:#F4F2FD;border:1px solid #E4E1F8;border-radius:999px;overflow:hidden}
.qzdict-holder .dict-progress-fill{height:100%;background:linear-gradient(90deg,#F97316,#7F77DD);border-radius:999px}
.qzdict-holder .dict-status{background:#7F77DD;color:#fff;font-family:'Nunito',sans-serif;font-size:12px;font-weight:900;border-radius:999px;padding:7px 11px;white-space:nowrap}
.qzdict-holder .dict-card{background:#fff;border:1px solid #EDE9FA;border-radius:28px;box-shadow:0 12px 36px rgba(127,119,221,.13);padding:clamp(18px,3vw,28px);min-height:clamp(260px,35vh,390px);box-sizing:border-box;display:flex;flex-direction:column;align-items:center;justify-content:center}
.qzdict-holder .dict-listen-row{display:flex;align-items:center;justify-content:center;margin-bottom:16px}
.qzdict-holder .dict-image{display:block;width:min(100%,340px);max-width:100%;max-height:280px;object-fit:contain;border-radius:22px;margin:0 auto 18px;background:#fff;border:1px solid #EDE9FA;box-shadow:0 8px 24px rgba(127,119,221,.10)}
.qzdict-holder .dict-answer-box{width:100%;max-width:620px;min-height:108px;padding:16px;border:1.5px solid #EDE9FA;background:#fff;border-radius:22px;font-family:'Nunito','Segoe UI',sans-serif;font-size:clamp(16px,2vw,19px);line-height:1.45;font-weight:800;color:#534AB7;resize:vertical;box-sizing:border-box;margin:0 auto;display:block;outline:none;box-shadow:0 4px 14px rgba(127,119,221,.08)}
.qzdict-holder .dict-answer-box::placeholder{color:#9B94BE;font-weight:800}
.qzdict-holder .dict-answer-box:focus{border-color:#7F77DD;box-shadow:0 0 0 3px rgba(127,119,221,.18)}
.qzdict-holder .dict-controls{border-top:1px solid #F0EEF8;margin-top:16px;padding-top:16px;display:flex;align-items:center;gap:10px;flex-wrap:wrap;justify-content:center;background:#fff}
.qzdict-holder .dict-btn{display:inline-flex;align-items:center;justify-content:center;padding:13px 20px;min-width:clamp(112px,16vw,154px);border:0;border-radius:10px;font-family:'Nunito',sans-serif;font-size:13px;font-weight:900;color:#fff;cursor:pointer;white-space:nowrap;transition:transform .12s,filter .12s,box-shadow .12s}
.qzdict-holder .dict-btn:hover{filter:brightness(1.07);transform:translateY(-1px)}
.qzdict-holder .dict-btn:disabled{opacity:.45;cursor:default;transform:none;filter:none;box-shadow:none}
.qzdict-holder .dict-btn-listen{background:#7F77DD;box-shadow:0 6px 18px rgba(127,119,221,.18)}
.qzdict-holder .dict-btn-next{background:#F97316;box-shadow:0 6px 18px rgba(249,115,22,.22)}
.qzdict-holder .dict-btn-skip{background:#7F77DD;box-shadow:0 6px 18px rgba(127,119,221,.18)}
@media(max-width:640px){.qzdict-holder .dict-board{border-radius:26px;padding:14px;width:100%}.qzdict-holder .dict-card{border-radius:22px;padding:16px;min-height:250px}.qzdict-holder .dict-image{max-height:220px}.qzdict-holder .dict-answer-box{min-height:100px;border-radius:18px}.qzdict-holder .dict-controls{display:grid;grid-template-columns:1fr;gap:9px}.qzdict-holder .dict-btn{width:100%}}
</style>
<script>
(function(){
  var holder=document.getElementById('<?=$prefix?>_holder');if(!holder)return;
  var old=null,title=Array.prototype.slice.call(document.querySelectorAll('.screen-title')).find(function(el){return /pregunta|question/i.test(el.textContent||'');});
  if(title&&title.nextElementSibling&&title.nextElementSibling.classList.contains('card'))old=title.nextElementSibling;
  if(!old)old=Array.prototype.slice.call(document.querySelectorAll('.card')).find(function(card){return card.querySelector('form');})||null;
  if(!old)return;
  old.parentNode.insertBefore(holder,old);old.style.display='none';holder.hidden=false;

  var input=document.getElementById('<?=$prefix?>_input');
  var answer=document.getElementById('<?=$prefix?>_answer');
  var skip=document.getElementById('<?=$prefix?>_skip');
  var form=document.getElementById('<?=$prefix?>_submit');
  var listen=document.getElementById('<?=$prefix?>_listen');
  var next=document.getElementById('<?=$prefix?>_next');
  var skipButton=document.getElementById('<?=$prefix?>_skip_button');
  var audioUrl=<?=json_encode($dictAudio,JSON_UNESCAPED_SLASHES)?>;
  var text=<?=json_encode($dictText,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?>;
  var voiceId=<?=json_encode($dictVoiceId)?>;
  var player=null,isPaused=false;

  function setListenLabel(){if(!listen)return;listen.textContent=isPaused?'Resume':(player&&!player.paused?'Pause':'Listen');}
  function stopAudio(){try{if(player){player.pause();player.currentTime=0;player=null;}if(window.speechSynthesis)window.speechSynthesis.cancel();}catch(error){}isPaused=false;setListenLabel();}
  function playAudio(src,isObjectUrl){player=new Audio(src);player.onended=function(){if(isObjectUrl)URL.revokeObjectURL(src);player=null;isPaused=false;setListenLabel();};player.play().then(setListenLabel).catch(function(){player=null;setListenLabel();});}
  function speak(){
    if(player){if(player.paused){player.play().then(function(){isPaused=false;setListenLabel();}).catch(function(){});}else{player.pause();isPaused=true;setListenLabel();}return;}
    if(audioUrl){playAudio(audioUrl,false);return;}
    listen.disabled=true;listen.textContent='...';
    var data=new FormData();data.append('text',text);data.append('voice_id',voiceId);
    fetch('../dictation/tts.php',{method:'POST',body:data,credentials:'same-origin'}).then(function(response){if(!response.ok)throw new Error('TTS '+response.status);return response.blob();}).then(function(blob){listen.disabled=false;playAudio(URL.createObjectURL(blob),true);}).catch(function(){listen.disabled=false;setListenLabel();if(window.speechSynthesis&&text){var utterance=new SpeechSynthesisUtterance(text);utterance.lang='en-US';utterance.rate=.85;window.speechSynthesis.speak(utterance);}});
  }
  function submit(value,skipped){
    if(!form||form.dataset.submitted==='1')return;
    form.dataset.submitted='1';answer.value=String(value||'');skip.disabled=!skipped;next.disabled=true;skipButton.disabled=true;
    var fallback=function(){try{form.submit();}catch(error){form.dataset.submitted='0';next.disabled=false;skipButton.disabled=false;}};
    if(!window.fetch||!window.FormData){fallback();return;}
    fetch(window.location.href,{method:'POST',body:new FormData(form),credentials:'same-origin',cache:'no-store',redirect:'follow'}).then(function(response){if(!response.ok)throw new Error('Quiz submit failed: '+response.status);var target=response.url||window.location.href;if(<?=empty($GLOBALS['qzEvalInjectorContext'])?'true':'false'?>&&<?=$quizPosition?>>=<?=$quizCount?>&&!/[?&]mode=result(?:&|$)/.test(target)){var resultUrl=new URL(window.location.href);resultUrl.searchParams.set('mode','result');resultUrl.searchParams.delete('q');target=resultUrl.toString();}window.location.assign(target);}).catch(function(){form.dataset.submitted='0';fallback();});
  }
  listen.addEventListener('click',speak);
  next.addEventListener('click',function(){var value=String(input.value||'').trim();if(!value){input.focus();return;}stopAudio();submit(value,false);});
  skipButton.addEventListener('click',function(){stopAudio();submit('',true);});
  input.addEventListener('keydown',function(event){if(event.key==='Enter'&&!event.shiftKey){event.preventDefault();next.click();}});
  window.addEventListener('beforeunload',stopAudio);
  setTimeout(function(){try{input.focus();}catch(error){}},80);
})();
</script>
