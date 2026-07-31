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
$unitName='Unit Quiz';
try{$s=$pdo->prepare('SELECT name FROM units WHERE id::text=:u LIMIT 1');$s->execute(['u'=>$unitId]);$n=trim((string)$s->fetchColumn());if($n!=='')$unitName=$n;}catch(Throwable $e){}
$s=$pdo->prepare('SELECT id,type,unit_id,data FROM activities WHERE unit_id::text=:u ORDER BY id ASC');
$s->execute(['u'=>$unitId]);
$all=[];
foreach(($s->fetchAll(PDO::FETCH_ASSOC)?:[]) as $activity){foreach(qz_normalize_activity($activity) as $q){if(($q['type']??'')!=='pronunciation')$all[]=$q;}}
$quiz=qz_build($all,$unitId,$assignmentId,1);
if(!$quiz)exit('No printable quiz questions were found.');
function tp_body(array $q): string{
    $type=(string)($q['type']??'');
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
        $o='<div class="pairs">';
        foreach((array)($q['pairs']??[]) as $i=>$p){
            $left=$p['left']??'';
            $o.='<div class="pair-item"><b>'.($i+1).'.</b><div class="pair-content">'.tp_media($left,'Match item '.($i+1)).'</div><span></span></div>';
        }
        return $o.'</div>';
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
    if($type==='dictation') return '<div class="dictation-line"><span></span></div>';
    return '<div class="lines"><span></span><span></span><span></span></div>';
}
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?=tp_h($unitName)?> - Quiz</title><style>
@page{size:letter;margin:13mm}*{box-sizing:border-box}body{margin:0;background:#eef2ff;color:#17133f;font-family:Verdana,Arial,sans-serif}.bar{position:sticky;top:0;display:flex;justify-content:center;gap:10px;padding:12px;background:#17133f}.bar a,.bar button{border:0;border-radius:9px;padding:10px 16px;color:#fff;font-weight:700;text-decoration:none;cursor:pointer}.bar a{background:#7F77DD}.bar button{background:#F97316}.sheet{width:216mm;min-height:279mm;margin:18px auto;background:#fff;padding:15mm;box-shadow:0 12px 35px rgba(0,0,0,.14)}header{border-bottom:4px solid #7F77DD;padding-bottom:12px}.eyebrow{font-size:10px;color:#F97316;font-weight:700;letter-spacing:1px}.title{font-size:28px;color:#7F77DD;margin:6px 0}.meta{font-size:11px;color:#746ca4}.fields{display:grid;grid-template-columns:2fr 1fr 1fr;gap:14px;margin:18px 0}.field{border-bottom:1px solid #555;padding:7px 2px;font-size:10px;color:#777}.inst{background:#FFF0E6;border:1px solid #FCDDBF;border-radius:10px;padding:11px;font-size:11px;margin-bottom:16px}.q{break-inside:avoid;border:1px solid #EDE9FA;border-radius:13px;padding:14px;margin-bottom:12px}.qh{display:flex;gap:10px;font-size:13px;font-weight:700;line-height:1.45}.num{display:inline-flex;align-items:center;justify-content:center;min-width:27px;height:27px;border-radius:50%;background:#7F77DD;color:#fff}.question-image{margin:10px 0 8px 37px}.type{margin:9px 0 10px 37px;color:#D85D00;font-size:9px;font-weight:800;text-transform:uppercase;letter-spacing:.4px}.opts{margin-left:37px;display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px;font-size:12.5px}.opt{position:relative;display:flex;align-items:center;justify-content:center;min-width:0;min-height:96px;border:1.5px solid #CDC7F3;border-bottom-width:4px;border-radius:10px;padding:12px 10px;background:#fff;color:#4338CA;text-align:center;line-height:1.35;box-shadow:0 2px 0 rgba(127,119,221,.18)}.opt-label{position:absolute;top:6px;left:6px;display:inline-flex;align-items:center;justify-content:center;width:23px;height:23px;border-radius:50%;background:#7F77DD;color:#fff;font-size:10px}.opt-content{min-width:0;width:100%;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:6px;font-weight:800;color:#4338CA}.opt-text{display:block}.quiz-img{display:block;max-width:100%;width:auto;height:auto;max-height:110px;object-fit:contain;margin:0 auto}.question-image .quiz-img{max-height:170px}.pairs{margin-left:37px;display:grid;grid-template-columns:1fr 1fr;gap:10px 22px;font-size:11px}.pair-item{display:flex;align-items:center;gap:7px}.pair-content{flex:1;min-width:0}.pair-item span{display:inline-block;width:60px;border-bottom:1px solid #777}.words{margin:10px 0 0 37px;display:flex;gap:10px;flex-wrap:wrap}.words span{width:80px;height:25px;border-bottom:1px solid #777}.unscramble-bank{margin:7px 0 8px 37px;display:flex;flex-wrap:wrap;gap:7px}.unscramble-chip{display:inline-flex;align-items:center;justify-content:center;padding:6px 11px;border:1.5px solid #AFA9EC;border-radius:9px;background:#EEEDFE;color:#534AB7;font-size:11px;font-weight:700;line-height:1.2}.unscramble-lines{margin-top:2px}.usk-print-emoji{margin:4px 0 8px 37px;font-size:54px;line-height:1}.usk-print-bank,.usk-print-slots{margin:8px 0 8px 37px;display:flex;flex-wrap:wrap;gap:7px}.usk-print-chip,.usk-print-slots span{display:inline-flex;align-items:center;justify-content:center;width:34px;height:40px;border:1.5px solid #AFA9EC;border-bottom-width:4px;border-radius:9px;background:#fff;color:#534AB7;font-size:18px;font-weight:800}.usk-print-slots span{border-style:dashed;background:#FAFAFE}.dictation-line{margin:8px 0 0 37px}.dictation-line span{display:block;height:27px;border-bottom:1px solid #bbb}.lines{margin-left:37px}.lines span{display:block;height:27px;border-bottom:1px solid #bbb}.foot{text-align:center;font-size:9px;color:#9990bd;margin-top:16px}@media print{body{background:#fff}.bar{display:none}.sheet{width:auto;min-height:auto;margin:0;padding:0;box-shadow:none}.quiz-img{print-color-adjust:exact;-webkit-print-color-adjust:exact}.opt,.opt-label,.unscramble-chip,.usk-print-chip,.usk-print-slots span{print-color-adjust:exact;-webkit-print-color-adjust:exact}}
</style></head><body><div class="bar"><a href="<?=tp_h($returnTo!==''?$returnTo:'teacher_viewer.php?'.http_build_query(['assignment'=>$assignmentId,'unit'=>$unitId]))?>">← Back to Quiz</a><button onclick="window.print()">🖨 Print / Export PDF</button></div><main class="sheet"><header><div class="eyebrow">LET'S INSTITUTE · ONES · PRINTABLE QUIZ</div><h1 class="title"><?=tp_h($unitName)?></h1><div class="meta"><?=count($quiz)?> questions</div></header><div class="fields"><div class="field">Student name</div><div class="field">Date</div><div class="field">Score</div></div><div class="inst"><b>Instructions:</b> Complete every question. This document contains only the questions selected for the unit Quiz.</div><?php foreach($quiz as $i=>$q):?><section class="q"><div class="qh"><span class="num"><?=$i+1?></span><span><?=tp_h($q['question']??'Answer the question.')?></span></div><?php if(!empty($q['image'])):?><div class="question-image"><?=tp_media($q['image'],'Question image')?></div><?php endif;?><div class="type"><?=tp_h(str_replace('_',' ',(string)($q['type']??'question')))?></div><?=tp_body($q)?></section><?php endforeach;?><div class="foot">LET'S Institute · ONES · Unit Quiz</div></main></body></html>

