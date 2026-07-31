<?php
// Quiz-only Matching Lines view. Reuses the unit activity's cards, lane and line interaction
// while intentionally omitting practice controls and activity headings.
$qNow=$GLOBALS['quiz'][$qIndexNow]??[];
$pairs=is_array($qNow['pairs']??null)?array_values($qNow['pairs']):[];
if(count($pairs)<2)return;
if(!function_exists('qzml_media')){
    function qzml_media($value,$text,$alt):string{
        $value=trim((string)$value);$text=trim((string)$text);
        $path=(string)(parse_url($value,PHP_URL_PATH)??'');$isImage=str_starts_with($value,'data:image/')||(bool)preg_match('/\\.(png|jpe?g|gif|webp|svg)$/i',$path);if($value!==''&&$isImage)return '<img class="qzml-media" src="'.qz_h($value).'" alt="'.qz_h($alt).'">';
        return '<span class="qzml-text">'.qz_h($text!==''?$text:$value).'</span>';
    }
}
$rightItems=[];
foreach($pairs as$i=>$pair)$rightItems[]=['index'=>$i,'value'=>(string)($pair['right']??''),'text'=>(string)($pair['right_text']??''),'image'=>(string)($pair['right_image']??'')];
qz_shuffle($rightItems,(int)hexdec(substr(md5('quiz_matching_lines_'.(string)($qNow['id']??$qIndexNow)),0,7)));
$prefix='qzml_'.preg_replace('/[^a-zA-Z0-9_-]/','',(string)($GLOBALS['unitId']??'0')).'_'.$qIndexNow;
?>
<style>
.<?=$prefix?>{width:100%;font-family:Nunito,Arial,sans-serif}.<?=$prefix?> .qzml-stage{--tile:92px;position:relative;display:grid;grid-template-columns:minmax(0,1fr) 180px minmax(0,1fr);gap:0;min-height:420px;padding:16px;border-radius:24px;background:linear-gradient(180deg,rgba(255,255,255,.94),rgba(243,244,246,.9)),repeating-linear-gradient(135deg,rgba(255,255,255,.38) 0 14px,rgba(229,231,235,.32) 14px 28px),#e5e7eb;box-shadow:0 20px 34px rgba(8,47,73,.18);touch-action:none;overflow:hidden}.<?=$prefix?> .qzml-col{position:relative;z-index:2;display:grid;gap:11px;align-content:start}.<?=$prefix?> .qzml-lines{position:absolute;inset:0;width:100%;height:100%;pointer-events:none;z-index:1}.<?=$prefix?> .qzml-card{position:relative;width:calc(100% - 4px);height:var(--tile);min-height:var(--tile);margin:auto;padding:10px 12px;border:2px solid transparent;border-radius:12px;background:#fff;box-shadow:0 5px 0 rgba(15,23,42,.22),0 9px 18px rgba(15,23,42,.13);display:flex;align-items:center;justify-content:center;text-align:center;font:800 18px/1.16 Fredoka,Nunito,sans-serif;color:#c2410c;cursor:pointer;user-select:none;-webkit-user-drag:none;touch-action:none}.<?=$prefix?> .qzml-card.selected{border-color:#8b5cf6;box-shadow:0 0 0 4px rgba(139,92,246,.18)}.<?=$prefix?> .qzml-card.connected{border-color:#10b981;background:linear-gradient(180deg,#ecfdf5,#fff)}.<?=$prefix?> .qzml-media{display:block;max-width:100%;max-height:100%;object-fit:contain;border-radius:8px;pointer-events:none;-webkit-user-drag:none}.<?=$prefix?> .qzml-text{overflow-wrap:anywhere}.<?=$prefix?> .qzml-anchor{position:absolute;top:50%;width:18px;height:18px;border:2px solid #fff;border-radius:50%;background:#0f172a;box-shadow:0 1px 4px rgba(0,0,0,.2);transform:translateY(-50%)}.<?=$prefix?> [data-side="left"] .qzml-anchor{right:-8px}.<?=$prefix?> [data-side="right"] .qzml-anchor{left:-8px}.<?=$prefix?> .qzml-actions{display:flex;justify-content:center;gap:10px;margin-top:18px}.<?=$prefix?> .qzml-btn{min-width:135px;padding:12px 22px;border:0;border-radius:10px;font:900 14px Nunito,Arial,sans-serif;cursor:pointer}.<?=$prefix?> .qzml-next{background:#F97316;color:#fff}.<?=$prefix?> .qzml-skip{background:#7F77DD;color:#fff}@media(max-width:640px){.<?=$prefix?> .qzml-stage{--tile:78px;grid-template-columns:minmax(0,1fr) 34px minmax(0,1fr);padding:10px}.<?=$prefix?> .qzml-card{padding:7px;font-size:13px}.<?=$prefix?> .qzml-anchor{width:14px;height:14px}.<?=$prefix?> .qzml-actions{flex-direction:column}.<?=$prefix?> .qzml-btn{width:100%}}
</style>
<div id="<?=$prefix?>_holder" class="<?=$prefix?>" hidden>
<form method="post" id="<?=$prefix?>_form">
<div class="qzml-stage" id="<?=$prefix?>_stage">
<svg class="qzml-lines" id="<?=$prefix?>_lines" aria-hidden="true"></svg>
<div class="qzml-col">
<?php foreach($pairs as$i=>$pair):?><button type="button" class="qzml-card" data-side="left" data-left="<?=$i?>"><?=qzml_media($pair['left']??'',$pair['left_text']??'','Left item '.($i+1))?><span class="qzml-anchor"></span></button><?php endforeach;?>
</div><div></div><div class="qzml-col">
<?php foreach($rightItems as$item):?><button type="button" class="qzml-card" data-side="right" data-right="<?=$item['index']?>"><?=qzml_media($item['image']?:$item['value'],$item['text']?:$item['value'],'Right item')?><span class="qzml-anchor"></span></button><?php endforeach;?>
</div>
</div>
<?php foreach($pairs as$i=>$pair):?><input type="hidden" name="answer[<?=$i?>]" data-qzml-answer="<?=$i?>" value=""><?php endforeach;?>
<div class="qzml-actions"><button class="qzml-btn qzml-next" type="submit">Next</button><button class="qzml-btn qzml-skip" type="submit" name="skip" value="1" formnovalidate>Skip</button></div>
</form></div>
<script>
(function(){
var holder=document.getElementById('<?=$prefix?>_holder'),stage=document.getElementById('<?=$prefix?>_stage'),svg=document.getElementById('<?=$prefix?>_lines');if(!holder||!stage||!svg)return;
var pairs=<?=json_encode(array_map(fn($p)=>(string)($p['right']??''),$pairs),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?>,selected=null,tracing=null,ignoreClick=false,links={};
var lefts=Array.from(holder.querySelectorAll('[data-left]')),rights=Array.from(holder.querySelectorAll('[data-right]'));
function input(i){return holder.querySelector('[data-qzml-answer="'+i+'"]')}
function connect(li,ri){Object.keys(links).forEach(function(k){if(String(links[k])===String(ri)&&String(k)!==String(li)){delete links[k];var old=input(k);if(old)old.value='';}});links[li]=ri;var a=input(li);if(a)a.value=pairs[ri]||'';selected=null;paint();draw();}
function paint(){lefts.forEach(function(el){el.classList.toggle('selected',String(el.dataset.left)===String(selected));el.classList.toggle('connected',Object.prototype.hasOwnProperty.call(links,el.dataset.left));});rights.forEach(function(el){el.classList.toggle('connected',Object.values(links).map(String).indexOf(String(el.dataset.right))!==-1);});}
function draw(){var box=stage.getBoundingClientRect();svg.setAttribute('viewBox','0 0 '+box.width+' '+box.height);svg.innerHTML='';Object.keys(links).forEach(function(li){var l=holder.querySelector('[data-left="'+li+'"] .qzml-anchor'),r=holder.querySelector('[data-right="'+links[li]+'"] .qzml-anchor');if(!l||!r)return;var lb=l.getBoundingClientRect(),rb=r.getBoundingClientRect(),x1=lb.left+lb.width/2-box.left,y1=lb.top+lb.height/2-box.top,x2=rb.left+rb.width/2-box.left,y2=rb.top+rb.height/2-box.top;var p=document.createElementNS('http://www.w3.org/2000/svg','path');p.setAttribute('d','M '+x1+' '+y1+' C '+(x1+box.width*.18)+' '+y1+', '+(x2-box.width*.18)+' '+y2+', '+x2+' '+y2);p.setAttribute('fill','none');p.setAttribute('stroke','#2563eb');p.setAttribute('stroke-width','5');p.setAttribute('stroke-linecap','round');svg.appendChild(p);});}
function preview(clientX,clientY){draw();if(tracing===null)return;var box=stage.getBoundingClientRect(),anchor=holder.querySelector('[data-left="'+tracing+'"] .qzml-anchor');if(!anchor)return;var ab=anchor.getBoundingClientRect(),x1=ab.left+ab.width/2-box.left,y1=ab.top+ab.height/2-box.top,x2=clientX-box.left,y2=clientY-box.top,p=document.createElementNS('http://www.w3.org/2000/svg','path');p.setAttribute('d','M '+x1+' '+y1+' C '+(x1+box.width*.18)+' '+y1+', '+(x2-box.width*.18)+' '+y2+', '+x2+' '+y2);p.setAttribute('fill','none');p.setAttribute('stroke','#2563eb');p.setAttribute('stroke-width','5');p.setAttribute('stroke-linecap','round');p.setAttribute('stroke-dasharray','8 6');svg.appendChild(p);}
lefts.forEach(function(el){el.setAttribute('draggable','false');el.addEventListener('dragstart',function(e){e.preventDefault();});el.addEventListener('click',function(){if(ignoreClick)return;selected=el.dataset.left;paint();});var anchor=el.querySelector('.qzml-anchor');if(anchor)anchor.addEventListener('pointerdown',function(e){e.preventDefault();e.stopPropagation();selected=el.dataset.left;tracing=el.dataset.left;paint();preview(e.clientX,e.clientY);});});
rights.forEach(function(el){el.setAttribute('draggable','false');el.addEventListener('dragstart',function(e){e.preventDefault();});el.addEventListener('click',function(){if(ignoreClick)return;if(selected!==null)connect(selected,el.dataset.right);});});
document.addEventListener('pointermove',function(e){if(tracing!==null){e.preventDefault();preview(e.clientX,e.clientY);}},{passive:false});
document.addEventListener('pointerup',function(e){if(tracing===null)return;var target=document.elementFromPoint(e.clientX,e.clientY),right=target&&target.closest?target.closest('[data-right]'):null,from=tracing;tracing=null;if(right&&holder.contains(right)){ignoreClick=true;connect(from,right.dataset.right);setTimeout(function(){ignoreClick=false;},0);}else draw();});
window.addEventListener('resize',draw);if(window.ResizeObserver)new ResizeObserver(draw).observe(stage);
var old=null,t=Array.from(document.querySelectorAll('.screen-title')).find(function(e){return /pregunta|question/i.test(e.textContent||'');});if(t&&t.nextElementSibling&&t.nextElementSibling.classList.contains('card'))old=t.nextElementSibling;if(!old)old=Array.from(document.querySelectorAll('.card')).find(function(c){return c.querySelector('form');})||null;if(!old)return;old.parentNode.insertBefore(holder,old);old.style.display='none';holder.hidden=false;setTimeout(draw,40);
})();
</script>
