<?php
// Shared renderer used by Unit Quiz and Admin Eval public exams.
// Normalized question types are produced by _quiz_lib.php.

if (!function_exists('qzr_h')) {
    function qzr_h($value): string {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('qzr_is_image')) {
    function qzr_is_image($value): bool {
        $value = trim((string)$value);
        if ($value === '') return false;
        if (str_starts_with($value, 'data:image/')) return true;
        $path = (string)(parse_url($value, PHP_URL_PATH) ?? '');
        return (bool)preg_match('/\.(png|jpe?g|gif|webp|svg)$/i', $path);
    }
}

if (!function_exists('qzr_styles')) {
    function qzr_styles(): string {
        return <<<'CSS'
<style>
.qzr-wrap{width:100%}.qzr-activity-instruction{margin:0 0 16px;padding:13px 16px;border:1px solid #EDE9FA;border-radius:14px;background:#F8F7FF;color:#534AB7;font:800 15px/1.55 Nunito,Arial,sans-serif;text-align:center}.qzr-passage{font-family:Lora,Georgia,serif;font-size:17px;line-height:1.75;background:#fff;border:1px solid #EDE9FA;border-radius:18px;padding:18px;margin:0 0 18px;color:#292452}.qzr-passage .qz-rc-hl{background:#FFF0E6;color:#C2580A;border-radius:5px;padding:0 3px;font-weight:700}.qzr-question{font-family:Nunito,Arial,sans-serif;font-size:23px;font-weight:900;line-height:1.4;color:#14113A;margin:10px 0 16px}.qzr-image{display:flex;justify-content:center;margin:10px 0 18px}.qzr-image img{display:block;max-width:100%;max-height:260px;object-fit:contain;border-radius:16px}.qzr-listen{display:flex;align-items:center;gap:12px;background:#F8F7FF;border:1px solid #EDE9FA;border-radius:16px;padding:14px;margin:12px 0}.qzr-listen button,.qzr-btn{border:0;border-radius:12px;padding:12px 18px;font:900 14px Nunito,Arial,sans-serif;cursor:pointer}.qzr-listen button,.qzr-btn-primary{background:#7F77DD;color:#fff}.qzr-btn-orange{background:#F97316;color:#fff}.qzr-btn-light{background:#fff;color:#7F77DD;border:1px solid #EDE9FA}.qzr-actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:18px}.qzr-actions>*{flex:1;min-width:150px}.qzr-option{display:flex;align-items:center;gap:12px;padding:14px;border:1px solid #EDE9FA;border-radius:14px;margin:10px 0;font:800 16px Nunito,Arial,sans-serif;cursor:pointer;background:#fff}.qzr-option:has(input:checked){border-color:#7F77DD;background:#F8F7FF}.qzr-letter{display:inline-flex;align-items:center;justify-content:center;width:34px;height:34px;border-radius:50%;background:#EEEDFE;color:#534AB7;font-weight:900;flex:0 0 auto}.qzr-option-content{flex:1;min-width:0}.qzr-option-image{display:block;max-width:100%;max-height:170px;object-fit:contain;margin:auto;border-radius:12px}.qzr-input,.qzr-select,.qzr-textarea{width:100%;padding:14px;border:1px solid #EDE9FA;border-radius:12px;font:700 16px Nunito,Arial,sans-serif;margin:8px 0;background:#fff;color:#14113A}.qzr-textarea{min-height:150px;resize:vertical}.qzr-match-row{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:12px;align-items:center;margin:10px 0;padding:12px;border:1px solid #EDE9FA;border-radius:14px;background:#fff}.qzr-match-left{font:900 16px Nunito,Arial,sans-serif}.qzr-match-left img{display:block;max-width:150px;max-height:110px;object-fit:contain;border-radius:10px}.qzr-dd-instruction{font:800 18px/2.15 Nunito,Arial,sans-serif;text-align:center;color:#534AB7;background:#EEEDFE;border-radius:16px;padding:18px;margin-bottom:16px}.qzr-dd-slot{display:inline-flex;align-items:center;justify-content:center;min-width:96px;height:40px;padding:0 10px;margin:0 3px;border:2px dashed #CFC8F5;border-radius:10px;background:#fff;color:#8178B6;font-weight:900;vertical-align:middle}.qzr-dd-slot.filled{border-style:solid;background:#fff;color:#534AB7}.qzr-bank,.qzr-build{display:flex;flex-wrap:wrap;justify-content:center;gap:12px;min-height:62px;padding:14px;border:1px solid #EDE9FA;border-radius:16px;background:#fff;margin:12px 0}.qzr-chip{display:inline-flex;align-items:center;justify-content:center;padding:12px 20px;border-radius:14px;background:#EEEDFE;border:2px solid #AFA9EC;color:#534AB7;font:900 17px Nunito,Arial,sans-serif;cursor:grab;user-select:none}.qzr-chip.selected{background:#7F77DD;color:#fff}.qzr-chip.used{display:none}.qzr-hidden{display:none}.qzr-pron-card{text-align:center;background:#fff;border:1px solid #EDE9FA;border-radius:20px;padding:18px}.qzr-pron-word{font:900 34px Fredoka,Nunito,sans-serif;color:#F97316}.qzr-status{min-height:42px;padding:10px;border-radius:12px;background:#F8F7FF;color:#8178B6;font-weight:800;margin-top:12px}.qzr-help{text-align:center;color:#8178B6;font:800 12px Nunito,Arial,sans-serif;margin-top:8px}@media(max-width:650px){.qzr-match-row{grid-template-columns:1fr}.qzr-actions{flex-direction:column}.qzr-actions>*{width:100%}.qzr-question{font-size:20px}.qzr-dd-instruction{font-size:16px}.qzr-chip{font-size:15px;padding:10px 14px}}
</style>
CSS;
    }
}

if (!function_exists('qzr_render')) {
    function qzr_render(array $q, array $config = []): string {
        $prefix = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)($config['prefix'] ?? ('qzr_' . uniqid())));
        $hidden = is_array($config['hidden'] ?? null) ? $config['hidden'] : [];
        $submitLabel = (string)($config['submit_label'] ?? 'Next');
        $skipLabel = (string)($config['skip_label'] ?? 'Skip');
        $formId = $prefix . '_form';
        $type = (string)($q['type'] ?? '');
        $activityInstruction = trim((string)($q['activity_instruction'] ?? ''));
        ob_start();
        echo qzr_styles();
        ?>
        <div class="qzr-wrap" data-qzr-root="<?=qzr_h($prefix)?>">
          <form method="post" id="<?=qzr_h($formId)?>">
            <?php foreach ($hidden as $name => $value): ?>
              <input type="hidden" name="<?=qzr_h($name)?>" value="<?=qzr_h($value)?>">
            <?php endforeach; ?>

            <?php if ($activityInstruction !== ''): ?><div class="qzr-activity-instruction"><?=nl2br(qzr_h($activityInstruction))?></div><?php endif; ?>

            <?php if (!empty($q['passage'])): ?>
              <div class="qzr-passage"><?=$q['passage']?></div>
            <?php endif; ?>

            <?php if ($type === 'pronunciation'): ?>
              <div class="qzr-pron-card">
                <?php if (!empty($q['image'])): ?><div class="qzr-image"><img src="<?=qzr_h($q['image'])?>" alt="<?=qzr_h($q['correct'] ?? '')?>"></div><?php endif; ?>
                <div class="qzr-pron-word"><?=qzr_h($q['correct'] ?? $q['question'] ?? '')?></div>
                <?php if (!empty($q['ph'])): ?><div class="qzr-help"><?=qzr_h($q['ph'])?></div><?php endif; ?>
                <div class="qzr-status" id="<?=qzr_h($prefix)?>_status">Listen, then speak.</div>
              </div>
              <input type="hidden" name="answer" id="<?=qzr_h($prefix)?>_answer">
              <div class="qzr-actions">
                <button class="qzr-btn qzr-btn-primary" type="button" id="<?=qzr_h($prefix)?>_listen">Listen</button>
                <button class="qzr-btn qzr-btn-orange" type="button" id="<?=qzr_h($prefix)?>_speak">Speak</button>
                <button class="qzr-btn qzr-btn-light" type="submit" name="skip" value="1" formnovalidate><?=qzr_h($skipLabel)?></button>
              </div>

            <?php elseif ($type === 'dictation'): ?>
              <div class="qzr-listen"><button type="button" id="<?=qzr_h($prefix)?>_listen">🔊 Listen</button><span>Listen</span></div>
              <?php if (!empty($q['image'])): ?><div class="qzr-image"><img src="<?=qzr_h($q['image'])?>" alt="Dictation image"></div><?php endif; ?>
              <input class="qzr-input" name="answer" autocomplete="off" required placeholder="Type what you hear">
              <div class="qzr-actions"><button class="qzr-btn qzr-btn-orange" type="submit"><?=qzr_h($submitLabel)?></button><button class="qzr-btn qzr-btn-light" type="submit" name="skip" value="1" formnovalidate><?=qzr_h($skipLabel)?></button></div>

            <?php elseif ($type === 'multiple_choice'): ?>
              <?php $listen = (($q['question_type'] ?? 'text') === 'listen'); ?>
              <?php if ($listen): ?><div class="qzr-listen"><button type="button" id="<?=qzr_h($prefix)?>_listen">🔊 Listen</button><span>Listen and choose the correct answer.</span></div><?php else: ?><div class="qzr-question"><?=qzr_h($q['question'] ?? '')?></div><?php endif; ?>
              <?php if (!empty($q['image'])): ?><div class="qzr-image"><img src="<?=qzr_h($q['image'])?>" alt="Question image"></div><?php endif; ?>
              <?php $imageOptions = (($q['option_type'] ?? 'text') === 'image'); ?>
              <?php foreach ((array)($q['options'] ?? []) as $i => $option): $isImage = $imageOptions || qzr_is_image($option); ?>
                <label class="qzr-option"><input type="radio" name="answer" value="<?=(int)$i?>" required><span class="qzr-letter"><?=chr(65 + (int)$i)?></span><span class="qzr-option-content"><?php if ($isImage): ?><img class="qzr-option-image" src="<?=qzr_h($option)?>" alt="Option <?=chr(65 + (int)$i)?>"><?php else: ?><?=qzr_h($option)?><?php endif; ?></span></label>
              <?php endforeach; ?>
              <div class="qzr-actions"><button class="qzr-btn qzr-btn-orange" type="submit"><?=qzr_h($submitLabel)?></button><button class="qzr-btn qzr-btn-light" type="submit" name="skip" value="1" formnovalidate><?=qzr_h($skipLabel)?></button></div>

            <?php elseif ($type === 'match'): ?>
              <div class="qzr-question"><?=qzr_h($q['question'] ?? 'Match each item with its correct pair.')?></div>
              <?php $rights = array_column((array)($q['pairs'] ?? []), 'right'); ?>
              <?php foreach ((array)($q['pairs'] ?? []) as $i => $pair): ?>
                <div class="qzr-match-row"><div class="qzr-match-left"><?php if (qzr_is_image($pair['left'] ?? '')): ?><img src="<?=qzr_h($pair['left'])?>" alt="Match item"><?php else: ?><?=qzr_h($pair['left'] ?? '')?><?php endif; ?></div><select class="qzr-select" name="answer[<?=(int)$i?>]" required><option value="">Choose</option><?php foreach ($rights as $right): ?><option value="<?=qzr_h($right)?>"><?=qzr_h($right)?></option><?php endforeach; ?></select></div>
              <?php endforeach; ?>
              <div class="qzr-actions"><button class="qzr-btn qzr-btn-orange" type="submit"><?=qzr_h($submitLabel)?></button><button class="qzr-btn qzr-btn-light" type="submit" name="skip" value="1" formnovalidate><?=qzr_h($skipLabel)?></button></div>

            <?php elseif ($type === 'drag_drop'): ?>
              <?php $words = array_values((array)($q['correct_words'] ?? $q['options'] ?? [])); $instruction = (string)($q['instruction'] ?? implode(' ', array_fill(0, count($words), '___'))); $parts = preg_split('/(___+)/', $instruction, -1, PREG_SPLIT_DELIM_CAPTURE); $shuffled = $words; shuffle($shuffled); ?>
              <?php if (!empty($q['listen_enabled'])): ?><div class="qzr-listen"><button type="button" id="<?=qzr_h($prefix)?>_listen">🔊 Listen</button><span>Listen, then complete the sentence.</span></div><?php endif; ?>
              <?php if (!empty($q['image'])): ?><div class="qzr-image"><img src="<?=qzr_h($q['image'])?>" alt="Drag and drop image"></div><?php endif; ?>
              <div class="qzr-question"><?=qzr_h($q['question'] ?? 'Drag the words into the correct blanks.')?></div>
              <div class="qzr-dd-instruction"><?php $slot = 0; foreach ($parts as $part): if (preg_match('/^___+$/', $part)): ?><span class="qzr-dd-slot" data-slot="<?=$slot++?>">Drop here</span><?php else: ?><?=qzr_h($part)?><?php endif; endforeach; ?></div>
              <div class="qzr-bank" data-bank><?php foreach ($shuffled as $i => $word): ?><button type="button" class="qzr-chip" draggable="true" data-chip="<?=$i?>" data-word="<?=qzr_h($word)?>"><?=qzr_h($word)?></button><?php endforeach; ?></div>
              <?php foreach ($words as $i => $word): ?><input class="qzr-hidden" name="answer[<?=$i?>]" data-answer="<?=$i?>" required><?php endforeach; ?>
              <div class="qzr-help">Drag a word into a blank, or tap a word and then tap a blank.</div>
              <div class="qzr-actions"><button class="qzr-btn qzr-btn-orange" type="submit"><?=qzr_h($submitLabel)?></button><button class="qzr-btn qzr-btn-light" type="submit" name="skip" value="1" formnovalidate><?=qzr_h($skipLabel)?></button></div>

            <?php elseif ($type === 'unscramble'): ?>
              <?php $tokens = array_values((array)($q['options'] ?? [])); $shuffled = $tokens; shuffle($shuffled); ?>
              <?php if (!empty($q['listen_enabled'])): ?><div class="qzr-listen"><button type="button" id="<?=qzr_h($prefix)?>_listen">🔊 Listen</button><span>Listen and build the sentence.</span></div><?php endif; ?>
              <?php if (!empty($q['image'])): ?><div class="qzr-image"><img src="<?=qzr_h($q['image'])?>" alt="Unscramble image"></div><?php endif; ?>
              <div class="qzr-question"><?=qzr_h($q['question'] ?? 'Put the words in the correct order.')?></div>
              <div class="qzr-build" data-build><span class="qzr-help">Tap words below to build the sentence.</span></div>
              <div class="qzr-bank" data-bank><?php foreach ($shuffled as $i => $word): ?><button type="button" class="qzr-chip" data-chip="<?=$i?>" data-word="<?=qzr_h($word)?>"><?=qzr_h($word)?></button><?php endforeach; ?></div>
              <input type="hidden" name="answer" data-unscramble-answer>
              <div class="qzr-actions"><button class="qzr-btn qzr-btn-orange" type="submit"><?=qzr_h($submitLabel)?></button><button class="qzr-btn qzr-btn-light" type="submit" name="skip" value="1" formnovalidate><?=qzr_h($skipLabel)?></button></div>

            <?php elseif ($type === 'writing_practice'): ?>
              <div class="qzr-question"><?=qzr_h($q['question'] ?? '')?></div>
              <?php if (!empty($q['image'])): ?><div class="qzr-image"><img src="<?=qzr_h($q['image'])?>" alt="Writing image"></div><?php endif; ?>
              <textarea class="qzr-textarea" name="answer" required placeholder="Write your answer"></textarea>
              <div class="qzr-actions"><button class="qzr-btn qzr-btn-orange" type="submit"><?=qzr_h($submitLabel)?></button><button class="qzr-btn qzr-btn-light" type="submit" name="skip" value="1" formnovalidate><?=qzr_h($skipLabel)?></button></div>

            <?php else: ?>
              <div class="qzr-question"><?=qzr_h($q['question'] ?? '')?></div>
              <?php if (!empty($q['image'])): ?><div class="qzr-image"><img src="<?=qzr_h($q['image'])?>" alt="Question image"></div><?php endif; ?>
              <?php if (!empty($q['audio'])): ?><div class="qzr-listen"><button type="button" id="<?=qzr_h($prefix)?>_listen">🔊 Listen</button><span>Listen before answering.</span></div><?php endif; ?>
              <input class="qzr-input" name="answer" required placeholder="Type the missing word or phrase">
              <div class="qzr-actions"><button class="qzr-btn qzr-btn-orange" type="submit"><?=qzr_h($submitLabel)?></button><button class="qzr-btn qzr-btn-light" type="submit" name="skip" value="1" formnovalidate><?=qzr_h($skipLabel)?></button></div>
            <?php endif; ?>
          </form>
        </div>
        <script>
        (function(){
          var root=document.querySelector('[data-qzr-root="<?=qzr_h($prefix)?>"]'); if(!root)return;
          var expected=<?=json_encode((string)($q['correct'] ?? ''))?>;
          var audioUrl=<?=json_encode((string)($q['audio'] ?? ''))?>;
          var listenText=<?=json_encode((string)($q['listen_text'] ?? $q['question'] ?? $q['correct'] ?? ''))?>;
          var listenBtn=document.getElementById('<?=qzr_h($prefix)?>_listen');
          var currentAudio=null;
          function stopAudio(){if(currentAudio){currentAudio.pause();currentAudio.currentTime=0;currentAudio=null;}try{if(window.speechSynthesis)window.speechSynthesis.cancel();}catch(e){}}
          function speak(){stopAudio();if(audioUrl){currentAudio=new Audio(audioUrl);currentAudio.play().catch(function(){});return;}if(!listenText||!window.speechSynthesis)return;var u=new SpeechSynthesisUtterance(listenText);u.lang='en-US';u.rate=.85;window.speechSynthesis.speak(u);}
          if(listenBtn){listenBtn.addEventListener('click',speak);}

          var chips=Array.prototype.slice.call(root.querySelectorAll('.qzr-chip'));
          var slots=Array.prototype.slice.call(root.querySelectorAll('.qzr-dd-slot'));
          var selected=null,dragged=null;
          function syncSlots(){slots.forEach(function(slot,i){var input=root.querySelector('[data-answer="'+i+'"]');if(input)input.value=slot.dataset.value||'';});}
          function restoreChip(word,id){var chip=root.querySelector('.qzr-chip[data-chip="'+id+'"]');if(chip)chip.classList.remove('used');}
          function clearSlot(slot){if(slot.dataset.chip)restoreChip(slot.dataset.value,slot.dataset.chip);slot.dataset.value='';slot.dataset.chip='';slot.textContent='Drop here';slot.classList.remove('filled');syncSlots();}
          function placeChip(chip,slot){if(slot.dataset.value)clearSlot(slot);slot.dataset.value=chip.dataset.word||chip.textContent.trim();slot.dataset.chip=chip.dataset.chip||'';slot.textContent=slot.dataset.value;slot.classList.add('filled');chip.classList.add('used');chips.forEach(function(c){c.classList.remove('selected');});selected=null;syncSlots();}
          chips.forEach(function(chip){chip.addEventListener('dragstart',function(){dragged=chip;});chip.addEventListener('click',function(){var build=root.querySelector('[data-build]');if(build){var clone=document.createElement('button');clone.type='button';clone.className='qzr-chip';clone.textContent=chip.dataset.word||chip.textContent.trim();clone.dataset.word=clone.textContent;clone.addEventListener('click',function(){clone.remove();chip.classList.remove('used');syncBuild();});build.appendChild(clone);chip.classList.add('used');syncBuild();return;}chips.forEach(function(c){c.classList.remove('selected');});selected=chip;chip.classList.add('selected');});});
          slots.forEach(function(slot){slot.addEventListener('dragover',function(e){e.preventDefault();});slot.addEventListener('drop',function(e){e.preventDefault();if(dragged)placeChip(dragged,slot);dragged=null;});slot.addEventListener('click',function(){if(selected)placeChip(selected,slot);else if(slot.dataset.value)clearSlot(slot);});});
          function syncBuild(){var build=root.querySelector('[data-build]');var answer=root.querySelector('[data-unscramble-answer]');if(!build||!answer)return;var words=Array.prototype.slice.call(build.querySelectorAll('.qzr-chip')).map(function(c){return c.dataset.word||c.textContent.trim();});answer.value=words.join(' ');var help=build.querySelector('.qzr-help');if(help)help.style.display=words.length?'none':'';}

          var speakBtn=document.getElementById('<?=qzr_h($prefix)?>_speak');
          if(speakBtn){speakBtn.addEventListener('click',function(){var C=window.SpeechRecognition||window.webkitSpeechRecognition;var answer=document.getElementById('<?=qzr_h($prefix)?>_answer');var status=document.getElementById('<?=qzr_h($prefix)?>_status');if(!C){if(status)status.textContent='Speech recognition is not available.';return;}var r=new C();r.lang='en-US';r.interimResults=false;r.maxAlternatives=1;r.onresult=function(e){var said=String(e.results&&e.results[0]&&e.results[0][0]?e.results[0][0].transcript:'');if(answer)answer.value=said;if(status)status.textContent=said;};r.onerror=function(){if(status)status.textContent='Try again.';};r.start();});}
          window.addEventListener('beforeunload',stopAudio);
        })();
        </script>
        <?php
        return (string)ob_get_clean();
    }
}


