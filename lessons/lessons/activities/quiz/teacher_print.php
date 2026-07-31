<?php
declare(strict_types=1);
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/_quiz_lib.php';
if (empty($_SESSION['academic_logged'])) { header('Location: /lessons/lessons/academic/login.php'); exit; }
function tp_h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function tp_is_image($v): bool {
    $v=trim((string)$v);
    if($v==='') return false;
    if(str_starts_with($v,'data:image/')) return true;
    $path=(string)(parse_url($v,PHP_URL_PATH)??'');
    return (bool)preg_match('/\.(png|jpe?g|gif|webp|svg)$/i',$path);
}
function tp_media($v,string $alt=''): string {
    $v=trim((string)$v);
    if($v==='') return '';
    if(tp_is_image($v)) return '<img class="quiz-img" src="'.tp_h($v).'" alt="'.tp_h($alt).'" loading="eager">';
    return tp_h($v);
}
$assignmentId=trim((string)($_GET['assignment']??''));
$unitId=trim((string)($_GET['unit']??''));
$teacherId=trim((string)($_SESSION['teacher_id']??''));
$returnTo=trim((string)($_GET['return_to']??''));
if($assignmentId===''||$unitId===''||$teacherId===''){http_response_code(400);exit('Missing assignment or unit.');}
$permission=$pdo->prepare('SELECT id FROM teacher_assignments WHERE id=:a AND teacher_id=:t LIMIT 1');
$permission->execute(['a'=>$assignmentId,'t'=>$teacherId]);
if(!$permission->fetchColumn()){http_response_code(403);exit('Access denied.');}
$unitName='Unit Quiz';$unitLevel='';$unitModule='';$gradeName='';
try{$s=$pdo->prepare('SELECT * FROM units WHERE id::text=:u LIMIT 1');$s->execute(['u'=>$unitId]);$unitRow=$s->fetch(PDO::FETCH_ASSOC);if(is_array($unitRow)){$n=trim((string)($unitRow['name']??''));if($n!=='')$unitName=$n;$unitLevel=trim((string)($unitRow['level']??''));$unitModule=trim((string)($unitRow['module_name']??''));}}catch(Throwable $e){}
try{$s=$pdo->prepare('SELECT course_name,program_type FROM teacher_assignments WHERE id=:a LIMIT 1');$s->execute(['a'=>$assignmentId]);$assignmentRow=$s->fetch(PDO::FETCH_ASSOC);if(is_array($assignmentRow)){$courseName=trim((string)($assignmentRow['course_name']??''));$programType=trim((string)($assignmentRow['program_type']??''));$gradeName=$courseName!==''?$courseName:($programType!==''?ucfirst($programType).' Program':'English Program');}}catch(Throwable $e){}
if($gradeName==='')$gradeName='English Program';
$today=date('F j, Y');
$s=$pdo->prepare('SELECT id,type,unit_id,data FROM activities WHERE unit_id::text=:u ORDER BY id ASC');
$s->execute(['u'=>$unitId]);
$all=[];
foreach(($s->fetchAll(PDO::FETCH_ASSOC)?:[]) as $activity){foreach(qz_normalize_activity($activity) as $q){if(($q['type']??'')!=='pronunciation')$all[]=$q;}}
$quiz=qz_build($all,$unitId,$assignmentId,1);
if(!$quiz)exit('No printable quiz questions were found.');
function tp_body(array $q): string{
    $type=(string)($q['type']??'');
    if($type==='drag_drop_kids'){
        $pairs=is_array($q['pairs']??null)?array_values($q['pairs']):[];
        $background=trim((string)($q['background_image']??''));
        if(!$pairs||$background==='')return '';
        $o='<div class="ddk-print-bank">';
        foreach($pairs as $pair)$o.='<span>'.tp_h($pair['label']??'').'</span>';
        $o.='</div><div class="ddk-print-canvas"><img src="'.tp_h($background).'" alt="Labeling activity">';
        foreach($pairs as $pair){
            $left=max(0,min(92,(float)($pair['x']??10)));
            $top=max(0,min(92,(float)($pair['y']??10)));
            $width=max(12,min(36,(float)($pair['w']??12)));
            $height=max(8,min(22,(float)($pair['h']??8)));
            $o.='<span class="ddk-print-zone" style="left:'.$left.'%;top:'.$top.'%;width:'.$width.'%;height:'.$height.'%"></span>';
        }
        return $o.'</div>';
    }
    if($type==='multiple_choice'){
        $isImage=((string)($q['option_type']??''))==='image';
        $o='<div class="opts'.($isImage?' image-options':' text-options').'">';
        $optionImages=is_array($q['option_images']??null)?array_values($q['option_images']):[];
        foreach((array)($q['options']??[]) as $i=>$v){
            $label=chr(65+$i);
            if($isImage||tp_is_image($v)){
                $content=tp_media($v,'Option '.$label);
            }else{
                $content='';
                $thumb=trim((string)($optionImages[$i]??''));
                if($thumb!=='')$content.=tp_media($thumb,'Option '.$label);
                $content.='<span class="opt-text">'.tp_h($v).'</span>';
            }
            $o.='<div class="opt"><b class="opt-label">'.$label.'</b><div class="opt-content">'.$content.'</div></div>';
        }
        return $o.'</div>';
    }
    if($type==='match'){
        $pairs=array_values((array)($q['pairs']??[]));$rights=[];
        foreach($pairs as$i=>$p)$rights[]=['letter'=>chr(65+$i),'value'=>$p['right']??''];
        qz_shuffle($rights,(int)hexdec(substr(md5('print_match_'.(string)($q['id']??'')),0,7)));
        $o='<div class="ml-print-stage" style="--ml-count:'.max(2,count($pairs)).'"><div class="ml-print-col">';
        foreach($pairs as$i=>$p)$o.='<div class="ml-print-card"><b>'.($i+1).'</b><div>'.tp_media($p['left']??'','Left match '.($i+1)).'</div><span class="ml-print-dot"></span></div>';
        $o.='</div><div class="ml-print-lane"></div><div class="ml-print-col">';
        foreach($rights as$i=>$item)$o.='<div class="ml-print-card right"><span class="ml-print-dot"></span><b>'.chr(65+$i).'</b><div>'.tp_media($item['value']??'','Right match '.chr(65+$i)).'</div></div>';
        return $o.'</div></div>';
    }
    if($type==='drag_drop'){
        $count=max(1,count((array)($q['correct_words']??[])));
        return '<div class="words">'.str_repeat('<span></span>',$count).'</div>';
    }
    if($type==='unscramble_kids'){
        $letters=array_values(array_filter((array)preg_split('//u',trim((string)($q['correct']??'')),-1,PREG_SPLIT_NO_EMPTY),static fn($letter)=>trim((string)$letter)!==''));
        if(count($letters)>1){
            $ordered=$letters;
            $seed=(int)hexdec(substr(md5('print_unscramble_kids_'.(string)($q['id']??implode('',$letters))),0,7));
            qz_shuffle($letters,$seed);
            if($letters===$ordered)$letters=array_merge(array_slice($letters,1),[$letters[0]]);
        }
        $o='';
        $emoji=trim((string)($q['emoji']??''));
        if(empty($q['image'])&&$emoji!=='')$o.='<div class="usk-print-emoji">'.tp_h($emoji).'</div>';
        $o.='<div class="usk-print-bank">';
        foreach($letters as $letter)$o.='<span class="usk-print-chip">'.tp_h($letter).'</span>';
        $o.='</div><div class="usk-print-slots">';
        foreach($letters as $_)$o.='<span></span>';
        return $o.'</div>';
    }
    if($type==='unscramble'){
        $words=array_values(array_filter(array_map('trim',(array)($q['options']??[])),static fn($word)=>$word!==''));
        if(!$words){
            $words=array_values(array_filter(preg_split('/\s+/u',trim((string)($q['correct']??''))),static fn($word)=>$word!==''));
        }
        $ordered=$words;
        if(count($words)>1){
            $seed=(int)hexdec(substr(md5('print_unscramble_'.(string)($q['id']??implode('|',$words))),0,7));
            qz_shuffle($words,$seed);
            if($words===$ordered)$words=array_merge(array_slice($words,1),[$words[0]]);
        }
        $o='<div class="unscramble-bank">';
        foreach($words as $word)$o.='<span class="unscramble-chip">'.tp_h($word).'</span>';
        return $o.'</div><div class="lines unscramble-lines"><span></span><span></span></div>';
    }
    if($type==='fill') return '';
    if($type==='dictation'){
        $image=trim((string)($q['image']??''));
        $o='<div class="dictation-print-row">';
        if($image!=='')$o.='<div class="dictation-print-image">'.tp_media($image,'Dictation image').'</div>';
        $o.='<div class="dictation-print-lines"><span></span><span></span><span></span></div>';
        return $o.'</div>';
    }
    return '<div class="lines"><span></span><span></span><span></span></div>';
}
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?=tp_h($unitName)?> - Quiz</title><link href="https://fonts.googleapis.com/css2?family=Fredoka:wght@500;600;700&family=Nunito:wght@400;600;700;800&display=swap" rel="stylesheet"><style>
@page{size:letter;margin:13mm}*{box-sizing:border-box}body{margin:0;background:#eef2ff;color:#17133f;font-family:Verdana,Arial,sans-serif}.bar{position:sticky;top:0;display:flex;justify-content:center;gap:10px;padding:12px;background:#17133f}.bar a,.bar button{border:0;border-radius:9px;padding:10px 16px;color:#fff;font-weight:700;text-decoration:none;cursor:pointer}.bar a{background:#7F77DD}.bar button{background:#F97316}.sheet{width:216mm;min-height:279mm;margin:18px auto;background:#fff;padding:15mm;box-shadow:0 12px 35px rgba(0,0,0,.14)}header{border-bottom:4px solid #7F77DD;padding-bottom:12px}.eyebrow{font-size:10px;color:#F97316;font-weight:700;letter-spacing:1px}.title{font-size:28px;color:#7F77DD;margin:6px 0}.meta{font-size:11px;color:#746ca4}.fields{display:grid;grid-template-columns:2fr 1fr 1fr;gap:14px;margin:18px 0}.field{border-bottom:1px solid #555;padding:7px 2px;font-size:10px;color:#777}.inst{background:#FFF0E6;border:1px solid #FCDDBF;border-radius:10px;padding:11px;font-size:11px;margin-bottom:16px}.q{break-inside:avoid;border:1px solid #EDE9FA;border-radius:13px;padding:14px;margin-bottom:12px}.qh{display:flex;gap:10px;font-size:13px;font-weight:700;line-height:1.45}.num{display:inline-flex;align-items:center;justify-content:center;min-width:27px;height:27px;border-radius:50%;background:#7F77DD;color:#fff}.question-image{margin:10px 0 8px 37px}.type{margin:9px 0 10px 37px;color:#D85D00;font-size:9px;font-weight:800;text-transform:uppercase;letter-spacing:.4px}.opts{margin-left:37px;display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px;font-size:12.5px}.opt{position:relative;display:flex;align-items:center;justify-content:center;min-width:0;min-height:96px;border:1.5px solid #CDC7F3;border-bottom-width:4px;border-radius:10px;padding:12px 10px;background:#fff;color:#4338CA;text-align:center;line-height:1.35;box-shadow:0 2px 0 rgba(127,119,221,.18)}.opt-label{position:absolute;top:6px;left:6px;display:inline-flex;align-items:center;justify-content:center;width:23px;height:23px;border-radius:50%;background:#7F77DD;color:#fff;font-size:10px}.opt-content{min-width:0;width:100%;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:6px;font-weight:800;color:#4338CA}.opt-text{display:block}.quiz-img{display:block;max-width:100%;width:auto;height:auto;max-height:110px;object-fit:contain;margin:0 auto}.question-image .quiz-img{max-height:170px}.pairs{margin-left:37px;display:grid;grid-template-columns:1fr 1fr;gap:10px 22px;font-size:11px}.pair-item{display:flex;align-items:center;gap:7px}.pair-content{flex:1;min-width:0}.pair-item span{display:inline-block;width:60px;border-bottom:1px solid #777}.match-print-question{min-height:170mm;display:flex;flex-direction:column}.match-print-question .ml-print-stage{flex:1}.ml-print-stage{width:100%;min-height:145mm;margin:10px 0 4px;display:grid;grid-template-columns:minmax(0,1fr) 110px minmax(0,1fr);gap:0;padding:16px;border-radius:18px;background:#f4f4f6}.ml-print-col{display:grid;grid-template-rows:repeat(var(--ml-count),minmax(0,1fr));gap:12px}.ml-print-lane{min-width:90px}.ml-print-card{position:relative;min-height:96px;padding:12px 15px;display:flex;align-items:center;gap:10px;border:2px solid #fff;border-radius:12px;background:#fff;box-shadow:0 4px 0 rgba(15,23,42,.18);color:#c2410c;font-size:13px;font-weight:800}.ml-print-card>div{flex:1;min-width:0;text-align:center}.ml-print-card .quiz-img{max-height:92px}.ml-print-dot{position:absolute;right:-7px;top:50%;width:14px;height:14px;border:2px solid #fff;border-radius:50%;background:#0f172a;transform:translateY(-50%)}.ml-print-card.right .ml-print-dot{right:auto;left:-7px}.words{margin:10px 0 0 37px;display:flex;gap:10px;flex-wrap:wrap}.words span{width:80px;height:25px;border-bottom:1px solid #777}.unscramble-bank{margin:7px 0 8px 37px;display:flex;flex-wrap:wrap;gap:7px}.unscramble-chip{display:inline-flex;align-items:center;justify-content:center;padding:6px 11px;border:1.5px solid #AFA9EC;border-radius:9px;background:#EEEDFE;color:#534AB7;font-size:11px;font-weight:700;line-height:1.2}.unscramble-lines{margin-top:2px}.usk-print-emoji{margin:4px 0 8px 37px;font-size:54px;line-height:1}.usk-print-bank,.usk-print-slots{margin:8px 0 8px 37px;display:flex;flex-wrap:wrap;gap:7px}.usk-print-chip,.usk-print-slots span{display:inline-flex;align-items:center;justify-content:center;width:34px;height:40px;border:1.5px solid #AFA9EC;border-bottom-width:4px;border-radius:9px;background:#fff;color:#534AB7;font-size:18px;font-weight:800}.usk-print-slots span{border-style:dashed;background:#FAFAFE}.ddk-print-question{min-height:115mm;display:flex;flex-direction:column}.ddk-print-bank{margin:7px 0 9px 37px;display:flex;flex-wrap:wrap;gap:7px}.ddk-print-bank span{padding:6px 11px;border:1.5px solid #AFA9EC;border-radius:9px;background:#EEEDFE;color:#534AB7;font-size:11px;font-weight:700}.ddk-print-canvas{position:relative;display:block;align-self:center;width:calc(100% - 37px);max-width:calc(100% - 37px);line-height:0}.ddk-print-canvas img{display:block;width:100%;height:auto;max-width:none;max-height:none;object-fit:contain}.ddk-print-zone{position:absolute;display:block;min-width:104px;min-height:32px;background:#fff;border:2px solid #7F77DD;border-radius:7px;box-shadow:0 1px 3px rgba(0,0,0,.12)}.dictation-print-question{padding:16px 20px}.dictation-print-question>.type{display:none}.dictation-print-row{display:flex;align-items:center;gap:14px;margin:8px 0 2px 37px;min-height:138px}.dictation-print-image{width:150px;height:138px;min-width:150px;display:flex;align-items:center;justify-content:center;overflow:hidden}.dictation-print-image .quiz-img{width:100%;height:100%;max-height:none;object-fit:contain}.dictation-print-lines{flex:1;min-width:0;display:grid;gap:14px}.dictation-print-lines span{display:block;height:12px;border-bottom:2px solid #8E8E8E}.lines{margin-left:37px}.lines span{display:block;height:27px;border-bottom:1px solid #bbb}.foot{text-align:center;font-size:9px;color:#9990bd;margin-top:16px}@media print{body{background:#fff}.bar{display:none}.sheet{width:auto;min-height:auto;margin:0;padding:0;box-shadow:none}.quiz-img{print-color-adjust:exact;-webkit-print-color-adjust:exact}.opt,.opt-label,.unscramble-chip,.usk-print-chip,.usk-print-slots span,.ddk-print-bank span,.ddk-print-zone{print-color-adjust:exact;-webkit-print-color-adjust:exact}}
.sheet{width:min(880px,calc(100% - 32px));min-height:279mm;margin:18px auto;background:#fff;padding:0;box-shadow:0 12px 40px rgba(0,0,0,.10);overflow:hidden}.doc-header{padding:18px 30px 14px;border-bottom:2px solid #F97316;display:flex;align-items:center;justify-content:space-between;background:#fff}.lockup{display:flex;align-items:center;gap:14px}.ones-text{font-family:Fredoka,Arial,sans-serif;font-weight:700;font-size:30px;color:#F97316;line-height:1;letter-spacing:-.5px}.tagline{font-size:9px;font-weight:800;color:#7F77DD;letter-spacing:2.5px}.byline-row{display:flex;align-items:center;gap:5px;margin-top:3px}.byline-line{width:16px;height:1.5px;background:#EDE9FA;border-radius:2px}.byline{font-size:9.5px;font-weight:600;color:#9B8FCC;letter-spacing:.3px}.header-right{display:flex;flex-direction:column;align-items:flex-end;gap:5px}.ws-badge{background:#fff;border:2px solid #F97316;border-radius:20px;padding:3px 13px;font-size:9.5px;font-weight:800;color:#F97316;letter-spacing:.07em;text-transform:uppercase}.ws-date{font-size:9.5px;color:#9B8FCC;font-weight:700}.course-bar{background:#7F77DD;padding:7px 30px;display:flex;align-items:center;justify-content:space-between}.cb-pill{background:rgba(255,255,255,.2);border:1px solid rgba(255,255,255,.38);border-radius:20px;padding:3px 12px;font-size:9.5px;font-weight:800;color:#fff;letter-spacing:.04em}.cb-sep{font-size:10px;color:rgba(255,255,255,.38);margin:0 4px}.cb-course{font-size:10.5px;font-weight:700;color:rgba(255,255,255,.72)}.cb-unit{font-size:10.5px;font-weight:800;color:#fff}.cb-count{font-size:9px;font-weight:700;color:rgba(255,255,255,.6)}.quiz-body{padding:22px 30px 30px}.unit-hero{border:1.5px solid #EDE9FA;border-radius:14px;padding:14px 18px;margin-bottom:18px;display:flex;align-items:flex-start;justify-content:space-between;gap:14px}.unit-eyebrow{font-size:8.5px;font-weight:800;text-transform:uppercase;letter-spacing:.15em;color:#9B8FCC;margin-bottom:4px}.unit-title{font-family:Fredoka,Arial,sans-serif;font-weight:700;font-size:18px;color:#1a1a2e;line-height:1.2;margin-bottom:3px}.unit-sub{font-size:10px;color:#9B8FCC;font-weight:600}.unit-chips{display:flex;gap:6px;margin-top:8px;flex-wrap:wrap}.uchip{border-radius:20px;padding:2px 10px;font-size:9px;font-weight:700;border:1.5px solid #F97316;color:#F97316}.uchip.lila{border-color:#7F77DD;color:#7F77DD}.grade-badge{background:#7F77DD;border-radius:10px;padding:7px 14px;text-align:center;flex-shrink:0}.gb-lbl{font-size:7.5px;font-weight:800;text-transform:uppercase;letter-spacing:.12em;color:rgba(255,255,255,.7);display:block;margin-bottom:2px}.gb-val{font-family:Fredoka,Arial,sans-serif;font-weight:700;font-size:15px;color:#fff}.student-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:16px}.sf{border:1.5px solid #EDE9FA;border-radius:10px;padding:11px 12px;display:flex;align-items:center;gap:8px}.sf-lbl{font-size:9px;font-weight:800;text-transform:uppercase;letter-spacing:.08em;color:#9B8FCC;white-space:nowrap;margin:0}.sf-line{flex:1;border-bottom:2px solid #EDE9FA;height:12px}.instr-row{border:1.5px solid #EDE9FA;border-radius:10px;padding:9px 14px;margin-bottom:22px;font-size:10.5px;color:#7F77DD;font-weight:600;display:flex;align-items:flex-start;gap:9px;line-height:1.5}.ins-dot{flex-shrink:0;width:20px;height:20px;background:#7F77DD;border-radius:50%;display:flex;align-items:center;justify-content:center;margin-top:1px}@media print{.sheet{width:auto;min-height:auto;margin:0;box-shadow:none}}</style></head><body><div class="bar"><a href="<?=tp_h($returnTo!==''?$returnTo:'teacher_viewer.php?'.http_build_query(['assignment'=>$assignmentId,'unit'=>$unitId]))?>">← Back to Quiz</a><button onclick="window.print()">🖨 Print / Export PDF</button></div><main class="sheet"><div class="doc-header"><div class="lockup"><svg width="54" height="54" viewBox="0 0 64 64" fill="none" xmlns="http://www.w3.org/2000/svg"><rect width="64" height="64" rx="17" fill="#FFF0E6"/><circle cx="30" cy="28" r="15" fill="#F97316"/><polygon points="23,41 15,53 37,46" fill="#F97316"/><circle cx="30" cy="28" r="8" fill="#FFF0E6"/><circle cx="42" cy="18" r="6" fill="#7F77DD"/><circle cx="42" cy="18" r="3" fill="#ffffff"/></svg><div><div class="ones-text">ONES</div><div class="tagline">ONLINE ENGLISH SOLUTION</div><div class="byline-row"><div class="byline-line"></div><span class="byline">by Let&rsquo;s Institute</span></div></div></div><div class="header-right"><div class="ws-badge">Quiz</div><div class="ws-date"><?=tp_h($today)?></div></div></div><div class="course-bar"><div style="display:flex;align-items:center"><div class="cb-pill"><?=tp_h($gradeName)?></div><span class="cb-sep">·</span><?php if($unitLevel!==''):?><div class="cb-course"><?=tp_h($unitLevel)?></div><span class="cb-sep">·</span><?php endif;?><div class="cb-unit"><?=tp_h($unitName)?></div></div><div class="cb-count"><?=count($quiz)?> questions</div></div><div class="quiz-body"><div class="unit-hero"><div><div class="unit-eyebrow">Online English Solution &middot; Let&rsquo;s Institute</div><div class="unit-title"><?=tp_h($unitName)?></div><?php $subParts=array_values(array_filter([$unitLevel,$unitModule,$gradeName],static fn($v)=>trim((string)$v)!==''));if($subParts):?><div class="unit-sub"><?=tp_h(implode(' · ',$subParts))?></div><?php endif;?><div class="unit-chips"><span class="uchip"><?=count($quiz)?> questions</span><span class="uchip lila">Print &amp; complete</span></div></div><div class="grade-badge"><span class="gb-lbl">Document</span><span class="gb-val">QUIZ</span></div></div><div class="student-grid"><div class="sf"><span class="sf-lbl">Name:</span><div class="sf-line"></div></div><div class="sf"><span class="sf-lbl">Date:</span><div class="sf-line"></div></div></div><div class="instr-row"><div class="ins-dot"><svg width="10" height="10" viewBox="0 0 12 12" fill="none"><circle cx="6" cy="6" r="5" stroke="#fff" stroke-width="1.2"/><path d="M6 5v4M6 3v1" stroke="#fff" stroke-width="1.3" stroke-linecap="round"/></svg></div>Complete every question. This document contains only the questions selected for the unit Quiz.</div><?php foreach($quiz as $i=>$q):?><section class="q<?=$_qType=(string)($q['type']??'');echo $_qType==='drag_drop_kids'?' ddk-print-question':($_qType==='dictation'?' dictation-print-question':($_qType==='match'?' match-print-question':''))?>"><div class="qh"><span class="num"><?=$i+1?></span><span><?=tp_h($q['question']??'Answer the question.')?></span></div><?php if(!empty($q['image'])&&($q['type']??'')!=='dictation'):?><div class="question-image"><?=tp_media($q['image'],'Question image')?></div><?php endif;?><div class="type"><?=tp_h(str_replace('_',' ',(string)($q['type']??'question')))?></div><?=tp_body($q)?></section><?php endforeach;?><div class="foot">LET'S Institute · ONES · Unit Quiz</div></div></main></body></html>






