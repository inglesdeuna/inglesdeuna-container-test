<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../../../lessons/core/db.php';
if (!function_exists('get_pdo')) die('Error: get_pdo() was not found.');
function qz_h($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
function qz_json($v){if($v===null||$v==='')return[];if(is_array($v))return$v;$d=json_decode((string)$v,true);return is_array($d)?$d:[];}
function qz_norm($v){return strtolower(trim(preg_replace('/\s+/',' ',(string)$v)));}
function qz_dictation_norm($v){return qz_norm(preg_replace('/[.,!?;:]+/u','',(string)$v));}
function qz_pick($r,$keys,$def=''){foreach($keys as $k){if(isset($r[$k])&&trim((string)$r[$k])!=='')return$r[$k];}return$def;}
function qz_redirect($m,$u,$a,$q=null,$x=[]){$url='?mode='.urlencode($m).'&unit='.intval($u).'&assignment='.urlencode(trim((string)$a));if($q!==null)$url.='&q='.intval($q);foreach($x as$k=>$v)$url.='&'.urlencode($k).'='.urlencode((string)$v);header('Location: '.$url);exit;}
function qz_resolve_assignment_id($rawAssignment,$returnTo=''){ $assignment=trim((string)$rawAssignment);if($assignment!==''&&$assignment!=='0')return$assignment;$returnTo=trim((string)$returnTo);if($returnTo!==''){ $query=(string)(parse_url($returnTo,PHP_URL_QUERY)??'');if($query!==''){parse_str($query,$params);$fallback=trim((string)($params['assignment']??''));if($fallback!==''&&$fallback!=='0')return$fallback;}}return$assignment!==''?$assignment:'0';}
function qz_rows_from_data($data){$p=qz_json($data);$rows=[];if(!$p)return$rows;if(isset($p[0])&&is_array($p[0]))$rows=$p;foreach(['questions','items','data','blocks','words','sentences','pairs','lines']as$k){if(isset($p[$k])&&is_array($p[$k])){$rows=$p[$k];break;}}if(empty($rows)&&is_array($p))$rows=[$p];return$rows;}
function qz_is_scoreable_type($type){return in_array($type,['quiz','multiple_choice','video_comprehension','fillblank','fill_blank','fill_in_blank','writing_practice','question_answer','matching_lines','match','dictation','pronunciation','unscramble','listen_order','drag_drop','drag_drop_kids','reading_comprehension','lets_classify'],true);}
function qz_normalize_rc_activity($act){$rawType=strtolower(trim((string)($act['type']??'')));if($rawType!=='reading_comprehension')return[];$payload=qz_json($act['data']??null);if(!is_array($payload))return[];$base=(string)($act['id']??uniqid('qz_',true));$out=[];$texts=isset($payload['texts'])&&is_array($payload['texts'])?$payload['texts']:[$payload];foreach($texts as$ti=>$text){if(!is_array($text))continue;$mode=strtolower(trim((string)($text['mode']??'vocab')));$pfx=$base.'_t'.$ti;if($mode==='comp'){$questions=isset($text['questions'])&&is_array($text['questions'])?$text['questions']:[];foreach($questions as$i=>$q){if(!is_array($q))continue;$stem=trim((string)($q['stem']??''));$opts=isset($q['options'])&&is_array($q['options'])?$q['options']:[];$opts=array_values(array_map(fn($o)=>trim((string)$o),$opts));$correct=max(0,(int)($q['correct']??0));if($stem!==''&&count(array_filter($opts,fn($o)=>$o!==''))>=2)$out[]=['id'=>$pfx.'_q'.$i,'type'=>'multiple_choice','question'=>$stem,'options'=>$opts,'correct'=>$correct,'audio'=>'','image'=>'','question_type'=>'text','voice_id'=>'josh','option_type'=>'text','pairs'=>[]];}}else{$words=isset($text['words'])&&is_array($text['words'])?$text['words']:[];foreach($words as$i=>$w){if(!is_array($w))continue;$word=trim((string)($w['word']??''));$correct=trim((string)($w['correct']??''));$d0=trim((string)(isset($w['distractors'])&&is_array($w['distractors'])?($w['distractors'][0]??''):''));$d1=trim((string)(isset($w['distractors'])&&is_array($w['distractors'])?($w['distractors'][1]??''):''));$opts=array_values(array_filter([$correct,$d0,$d1],fn($o)=>$o!==''));if($word!==''&&$correct!==''&&count($opts)>=2)$out[]=['id'=>$pfx.'_w'.$i,'type'=>'multiple_choice','question'=>'What does "'.$word.'" mean?','options'=>$opts,'correct'=>0,'audio'=>'','image'=>'','question_type'=>'text','voice_id'=>'josh','option_type'=>'text','pairs'=>[]];}}}return$out;}
function qz_add_mc(&$out,$id,$question,$options,$correct,$image='',$questionType='text',$voiceId='josh',$audio='',$optionType='text'){$options=array_values(array_filter($options,fn($v)=>trim((string)$v)!==''));if(trim((string)$question)!==''&&count($options)>=2)$out[]=['id'=>$id,'type'=>'multiple_choice','question'=>$question,'options'=>$options,'correct'=>$correct,'audio'=>$audio,'image'=>$image,'question_type'=>$questionType==='listen'?'listen':'text','voice_id'=>in_array($voiceId,['josh','lily','candy'],true)?$voiceId:'josh','option_type'=>$optionType==='image'?'image':'text','pairs'=>[]];}
function qz_add_fill(&$out,$id,$question,$answer,$image='',$audio='',$inputMode='text',$type='fill'){if(trim((string)$question)!==''&&trim((string)$answer)!=='')$out[]=['id'=>$id,'type'=>in_array($type,['fill','writing_practice'],true)?$type:'fill','question'=>$question,'options'=>[],'correct'=>$answer,'audio'=>$audio,'image'=>$image,'input_mode'=>$inputMode==='textarea'?'textarea':'text','pairs'=>[]];}
function qz_fill_answers($row){if(!is_array($row))return[];$answers=[];if(isset($row['answers'])&&is_array($row['answers']))$answers=$row['answers'];elseif(isset($row['answers'])&&is_string($row['answers']))$answers=preg_split('/\s*[|,]\s*/',$row['answers']);elseif(isset($row['answerkey'])&&is_string($row['answerkey']))$answers=preg_split('/\s*[|,]\s*/',$row['answerkey']);$answers=array_values(array_filter(array_map(fn($a)=>trim((string)$a),$answers),fn($a)=>$a!==''));return$answers;}
function qz_add_match(&$out,$id,$pairs,$question='Match each item with the correct option.'){$clean=[];foreach($pairs as$p){if(!is_array($p))continue;$l=qz_pick($p,['left','term','word','en','front','question','a','from']);$r=qz_pick($p,['right','match','translation','es','back','answer','b','to']);if($l!==''&&$r!=='')$clean[]=['left'=>$l,'right'=>$r];}if(count($clean)>=2)$out[]=['id'=>$id,'type'=>'match','question'=>$question,'options'=>[],'correct'=>'','audio'=>'','image'=>'','pairs'=>$clean];}
function qz_build_drag_drop_question($row,$voiceId='josh'){if(!is_array($row))return null;$text=qz_pick($row,['text','sentence','instruction','prompt']);if($text==='')return null;$missing=[];if(isset($row['missing_words'])&&is_array($row['missing_words'])){foreach($row['missing_words'] as$word){$word=trim((string)$word);if($word!=='')$missing[]=$word;}}$listen=isset($row['listen_enabled'])?filter_var($row['listen_enabled'],FILTER_VALIDATE_BOOLEAN,FILTER_NULL_ON_FAILURE):true;if(empty($missing)){$parts=preg_split('/\s+/',trim($text));$missing=array_values(array_filter(array_map(fn($part)=>trim((string)$part),$parts),fn($part)=>$part!==''));if(empty($missing))return null;$instruction=implode(' ',array_fill(0,count($missing),'___'));}else{$instruction=$text;$ordered=[];foreach($missing as$word){$pattern='/\b'.preg_quote($word,'/').'\b/i';if(preg_match($pattern,$instruction,$match,PREG_OFFSET_CAPTURE)){$ordered[]=['word'=>$word,'position'=>(int)($match[0][1]??PHP_INT_MAX)];$instruction=preg_replace($pattern,'___',$instruction,1);}else{$ordered[]=['word'=>$word,'position'=>PHP_INT_MAX];}}usort($ordered,fn($a,$b)=>$a['position']<=>$b['position']);$missing=array_map(fn($slot)=>(string)($slot['word']??''),$ordered);}$missing=array_values(array_filter($missing,fn($word)=>$word!==''));if(empty($missing))return null;return['type'=>'drag_drop','question'=>'Drag the words into the correct blanks.','instruction'=>$instruction,'options'=>$missing,'correct'=>implode(' ',$missing),'correct_words'=>$missing,'audio'=>qz_pick($row,['audio','audio_url']), 'image'=>qz_pick($row,['img','image','image_url']),'listen_enabled'=>$listen===null?true:(bool)$listen,'listen_text'=>$text,'voice_id'=>in_array($voiceId,['josh','lily','candy'],true)?$voiceId:'josh','pairs'=>[]];}
function qz_add_pron(&$out,$id,$row){$word=qz_pick($row,['en','word','text','phrase','prompt','question']);$img=qz_pick($row,['img','image','image_url']);$audio=qz_pick($row,['audio','audio_url']);$ph=qz_pick($row,['ph','phonetic']);if($word!=='')$out[]=['id'=>$id,'type'=>'pronunciation','question'=>$word,'options'=>[],'correct'=>$word,'audio'=>$audio,'image'=>$img,'ph'=>$ph,'pairs'=>[]];}
function qz_add_dict(&$out,$id,$row){$word=qz_pick($row,['en','word','text','phrase','sentence','prompt','question']);$audio=qz_pick($row,['audio','audio_url']);$img=qz_pick($row,['img','image','image_url']);$ph=qz_pick($row,['ph','phonetic']);if($word!=='')$out[]=['id'=>$id,'type'=>'dictation','question'=>'Listen and type what you hear.','options'=>[],'correct'=>$word,'audio'=>$audio,'image'=>$img,'ph'=>$ph,'pairs'=>[]];}
function qz_normalize_activity($act){$rawType=strtolower(trim((string)($act['type']??'')));if(!qz_is_scoreable_type($rawType))return[];if($rawType==='reading_comprehension')return qz_normalize_rc_activity($act);$payload=qz_json($act['data']??null);$rows=qz_rows_from_data($act['data']??null);$out=[];$base=(string)($act['id']??uniqid('qz_',true)); if($rawType==='quiz'||$rawType==='multiple_choice'||$rawType==='video_comprehension'){foreach($rows as$i=>$r){if(!is_array($r))continue;$opts=isset($r['options'])&&is_array($r['options'])?$r['options']:(isset($r['choices'])&&is_array($r['choices'])?$r['choices']:[$r['option_a']??'',$r['option_b']??'',$r['option_c']??'',$r['option_d']??'']);qz_add_mc($out,$base.'_'.$i,qz_pick($r,['question','prompt','text','title']),$opts,qz_pick($r,['correct','correct_answer','answer','expected'],0),qz_pick($r,['img','image','image_url']),qz_pick($r,['question_type'],'text'),qz_pick($r,['voice_id'],'josh'),qz_pick($r,['audio','audio_url']),qz_pick($r,['option_type'],'text'));}} elseif($rawType==='fillblank'||$rawType==='fill_blank'||$rawType==='fill_in_blank'||$rawType==='writing_practice'||$rawType==='question_answer'){foreach($rows as$i=>$r){if(!is_array($r))continue;$question=qz_pick($r,['question','prompt','sentence','text','en','prompt_text','instruction']);$answer=qz_pick($r,['answer','correct','correct_answer','expected','es','expected_answer','model_answer']);if($answer===''){$fillAnswers=qz_fill_answers($r);if(!empty($fillAnswers))$answer=implode(' | ',$fillAnswers);}$image=qz_pick($r,['img','image','image_url']);$audio=qz_pick($r,['audio','audio_url']);if($audio===''&&in_array($rawType,['fillblank','fill_blank','fill_in_blank'],true))$audio=qz_pick($payload,['tts_audio_url','media_url','audio','audio_url']);$inputMode=$rawType==='writing_practice'?'textarea':'text';$fillType=$rawType==='writing_practice'?'writing_practice':'fill';qz_add_fill($out,$base.'_'.$i,$question,$answer,$image,$audio,$inputMode,$fillType);}} elseif($rawType==='matching_lines'||$rawType==='match'||$rawType==='listen_order'||$rawType==='drag_drop_kids'){qz_add_match($out,$base.'_m',$rows,'Match each item with its correct pair.');} elseif($rawType==='drag_drop'){$voice=qz_pick($payload,['voice_id'],'josh');foreach($rows as$i=>$r){$question=qz_build_drag_drop_question($r,$voice);if($question===null)continue;$question['id']=$base.'_'.$i;$out[]=$question;}} elseif($rawType==='pronunciation'){foreach($rows as$i=>$r)if(is_array($r))qz_add_pron($out,$base.'_'.$i,$r);} elseif($rawType==='dictation'){foreach($rows as$i=>$r)if(is_array($r))qz_add_dict($out,$base.'_'.$i,$r);} elseif($rawType==='unscramble'){$voice=qz_pick($payload,['voice_id'],'josh');foreach($rows as$i=>$r){if(!is_array($r))continue;$sentence=qz_pick($r,['sentence','text','en','answer','correct']);if($sentence!==''){$tokens=array_values(array_filter(array_map(function($t){$clean=preg_replace('/^\d+[\.\)\-:]*/u','',trim((string)$t));return trim((string)$clean);},preg_split('/\s+/',trim($sentence))),fn($t)=>$t!==''));if(empty($tokens))continue;$listen=isset($r['listen_enabled'])?filter_var($r['listen_enabled'],FILTER_VALIDATE_BOOLEAN,FILTER_NULL_ON_FAILURE):true;$out[]=['id'=>$base.'_'.$i,'type'=>'unscramble','question'=>'Drag the words to make the sentence.','options'=>$tokens,'correct'=>implode(' ',$tokens),'audio'=>qz_pick($r,['audio','audio_url']),'image'=>qz_pick($r,['img','image','image_url']),'listen_enabled'=>$listen===null?true:(bool)$listen,'voice_id'=>$voice,'pairs'=>[]];}}} return$out;}
function qz_seed($u,$a,$att){$k='qz_seed_'.md5($u.'_'.$a.'_'.$att);if(!isset($_SESSION[$k]))$_SESSION[$k]=random_int(100000,999999);return(int)$_SESSION[$k];}
function qz_shuffle(&$arr,$seed){mt_srand($seed);for($i=count($arr)-1;$i>0;$i--){$j=mt_rand(0,$i);[$arr[$i],$arr[$j]]=[$arr[$j],$arr[$i]];}mt_srand();}
function qz_build($all,$u,$a,$att){$order=['dictation','pronunciation','multiple_choice','drag_drop','match','unscramble','fill','writing_practice'];$by=[];foreach($all as$q)$by[$q['type']][]=$q;$seed=qz_seed($u,$a,$att);foreach($by as$t=>&$list){qz_shuffle($list,$seed+crc32($t));$limit=6;if($t==='unscramble')$limit=max(1,min(6,(int)ceil(count($list)*0.75)));$list=array_slice($list,0,$limit);}unset($list);$final=[];foreach($order as$t){if(!empty($by[$t])){foreach($by[$t] as$q){$q['block_type']=$t;$final[]=$q;}unset($by[$t]);}}foreach($by as$t=>$list)foreach($list as$q){$q['block_type']=$t;$final[]=$q;}return$final;}
function qz_fill_parts_expected($v){$raw=preg_split('/\s*[|,]\s*/',(string)$v);$parts=[];foreach($raw as$p){$p=trim((string)$p);if($p!=='')$parts[]=$p;}return$parts;}
function qz_fill_parts_actual($v){$raw=preg_split('/\s*[|,]\s*/',(string)$v);return array_map(fn($p)=>trim((string)$p),$raw);}
function qz_words($v){$parts=preg_split('/\s+/u',trim((string)$v));$out=[];foreach((array)$parts as$p){$p=trim((string)$p);if($p!=='')$out[]=$p;}return$out;}
function qz_words_score($actual,$expected){$a=qz_words($actual);$e=qz_words($expected);$possible=max(1,count($e));$earned=0.0;for($i=0;$i<$possible;$i++)if(qz_norm((string)($a[$i]??''))===qz_norm((string)($e[$i]??'')))$earned++;$full=count($a)===$possible&&$earned===$possible;return['earned'=>$earned,'possible'=>$possible,'correct'=>$full];}
function qz_question_possible($q){$type=(string)($q['type']??'');if(in_array($type,['fill','writing_practice'],true)){if(strpos((string)($q['correct']??''),'|')!==false){$expected=qz_fill_parts_expected($q['correct']??'');$possible=0;foreach($expected as$part)$possible+=max(1,count(qz_words($part)));return max(1,$possible);}return max(1,count(qz_words((string)($q['correct']??''))));}return 1;}
function qz_answer_score($q,$a){$possible=qz_question_possible($q);if($a===null||$a==='')return['earned'=>0.0,'possible'=>$possible,'correct'=>false];$type=(string)($q['type']??'');if(in_array($type,['fill','writing_practice'],true)){if(strpos((string)($q['correct']??''),'|')!==false){$actual=qz_fill_parts_actual($a);$expected=qz_fill_parts_expected($q['correct']??'');$count=max(count($actual),count($expected),1);$earned=0.0;$totalPossible=0;$allCorrect=true;for($i=0;$i<$count;$i++){$ws=qz_words_score((string)($actual[$i]??''),(string)($expected[$i]??''));$earned+=$ws['earned'];$totalPossible+=$ws['possible'];if(!$ws['correct'])$allCorrect=false;}return['earned'=>$earned,'possible'=>max(1,$totalPossible),'correct'=>$allCorrect&&$earned>0];}$ws=qz_words_score((string)$a,(string)($q['correct']??''));return['earned'=>$ws['earned'],'possible'=>$ws['possible'],'correct'=>$ws['correct']&&$ws['earned']>0];}$correct=qz_correct($q,$a);return['earned'=>$correct?1.0:0.0,'possible'=>1,'correct'=>$correct];}
function qz_answers_totals(array $quiz,array $answers):array{$earned=0.0;$possible=0;$correctQuestions=0;$skipped=0;foreach($answers as$i=>$a){if(!is_array($a))continue;$q=is_array($quiz[$i]??null)?$quiz[$i]:null;$fallbackPossible=$q!==null?qz_question_possible($q):1;$entryPossible=max(1,(int)($a['possible']??$fallbackPossible));$entryEarned=(float)($a['earned']??(!empty($a['correct'])?1:0));$entryEarned=max(0.0,min((float)$entryPossible,$entryEarned));$possible+=$entryPossible;$earned+=$entryEarned;if(!empty($a['correct']))$correctQuestions++;if(!empty($a['skipped']))$skipped++;}return['earned'=>$earned,'possible'=>$possible,'correct_questions'=>$correctQuestions,'skipped_questions'=>$skipped];}
function qz_correct($q,$a){if($a===null||$a==='')return false;if($q['type']==='pronunciation')return !empty($a);if($q['type']==='dictation')return qz_dictation_norm($a)===qz_dictation_norm($q['correct']);if($q['type']==='unscramble')return qz_norm($a)===qz_norm($q['correct']);if($q['type']==='drag_drop'){if(!is_array($a))return false;$expected=array_values($q['correct_words']??[]);if(count($a)!==count($expected))return false;foreach($expected as$i=>$word)if(!isset($a[$i])||qz_norm((string)$a[$i])!==qz_norm($word))return false;return true;}if($q['type']==='match'){if(!is_array($a))return false;foreach($q['pairs']as$i=>$p)if(!isset($a[$i])||(string)$a[$i]!== (string)$p['right'])return false;return true;}if($q['type']==='multiple_choice'){if(is_numeric($q['correct']))return(string)$a===(string)$q['correct'];$opts=$q['options'];return isset($opts[(int)$a])&&qz_norm($opts[(int)$a])===qz_norm($q['correct']);}if($q['type']==='fill'&&strpos((string)$q['correct'],'|')!==false){$actual=array_values(array_filter(array_map('trim',preg_split('/\s*[|,]\s*/',(string)$a)),fn($v)=>$v!==''));$expected=array_values(array_filter(array_map('trim',preg_split('/\s*[|,]\s*/',(string)$q['correct'])),fn($v)=>$v!==''));if(count($actual)!==count($expected))return false;foreach($expected as$i=>$word)if(!isset($actual[$i])||qz_norm((string)$actual[$i])!==qz_norm((string)$word))return false;return true;}return qz_norm($a)===qz_norm($q['correct']);}
function qz_review_format_value($value):string{if($value===null)return'—';if(is_array($value)){$flat=[];array_walk_recursive($value,function($v)use(&$flat){$txt=trim((string)$v);if($txt!=='')$flat[]=$txt;});return empty($flat)?'—':implode(' · ',$flat);}if(is_bool($value))return$value?'Yes':'No';$txt=trim((string)$value);return$txt!==''?$txt:'—';}
function qz_review_answer_text(array $q,$answer):string{if($answer===null||$answer==='')return'—';$type=(string)($q['type']??'');if($type==='multiple_choice'&&is_numeric($answer)){$_opts=$q['options']??[];$idx=(int)$answer;if(isset($_opts[$idx]))return qz_review_format_value($_opts[$idx]);}return qz_review_format_value($answer);}
function qz_review_correct_text(array $q):string{$type=(string)($q['type']??'');if($type==='match'){$_pairs=$q['pairs']??[];$_vals=[];foreach((array)$_pairs as$_pair){$_left=trim((string)($_pair['left']??''));$_right=trim((string)($_pair['right']??''));if($_left!==''||$_right!=='')$_vals[]=$_left!==''&&$_right!==''?($_left.' → '.$_right):($_left.$_right);}return empty($_vals)?'—':implode(' · ',$_vals);}if($type==='drag_drop'){$_words=$q['correct_words']??[];if(is_array($_words)&&!empty($_words))return qz_review_format_value($_words);}return qz_review_format_value($q['correct']??'—');}
function qz_bool($v):bool{if(is_bool($v))return$v;if(is_int($v)||is_float($v))return((int)$v)===1;$s=strtolower(trim((string)$v));if($s==='')return false;return in_array($s,['1','t','true','y','yes','on'],true);}
function qz_ensure_quiz_state_table(PDO $pdo):void{try{$pdo->exec("CREATE TABLE IF NOT EXISTS student_quiz_state(student_id TEXT NOT NULL,assignment_id TEXT NOT NULL,unit_id TEXT NOT NULL,attempt_number INTEGER NOT NULL DEFAULT 1,quiz_set_json TEXT NOT NULL DEFAULT '[]',answers_json TEXT NOT NULL DEFAULT '{}',is_completed BOOLEAN NOT NULL DEFAULT FALSE,score_percent INTEGER NOT NULL DEFAULT 0,correct_count INTEGER NOT NULL DEFAULT 0,wrong_count INTEGER NOT NULL DEFAULT 0,skip_count INTEGER NOT NULL DEFAULT 0,total_count INTEGER NOT NULL DEFAULT 0,started_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),completed_at TIMESTAMPTZ,PRIMARY KEY(student_id,assignment_id,unit_id,attempt_number))");$pdo->exec("ALTER TABLE student_unit_results ADD COLUMN IF NOT EXISTS quiz_score_percent INTEGER");}catch(Throwable $e){}}
function qz_count_completed(PDO $pdo,string $sid,int $unit,string $asgn):int{if($sid==='')return 0;try{$st=$pdo->prepare("SELECT COUNT(*) FROM student_quiz_state WHERE student_id=:s AND unit_id=:u AND assignment_id=:a AND is_completed=TRUE");$st->execute(['s'=>$sid,'u'=>(string)$unit,'a'=>(string)$asgn]);return(int)$st->fetchColumn();}catch(Throwable $e){return 0;}}
function qz_load_db_state(PDO $pdo,string $sid,int $unit,string $asgn):?array{if($sid==='')return null;try{$st=$pdo->prepare("SELECT attempt_number,quiz_set_json,answers_json,is_completed,score_percent,correct_count,total_count,completed_at FROM student_quiz_state WHERE student_id=:s AND unit_id=:u AND assignment_id=:a ORDER BY attempt_number DESC LIMIT 1");$st->execute(['s'=>$sid,'u'=>(string)$unit,'a'=>(string)$asgn]);$row=$st->fetch(PDO::FETCH_ASSOC);return is_array($row)?$row:null;}catch(Throwable $e){return null;}}
function qz_save_db_state(PDO $pdo,string $sid,int $unit,string $asgn,int $att,array $quiz,array $answers,bool $completed,int $pct,int $cor,int $wr,int $sk,int $tot):void{if($sid==='')return;try{$cat=$completed?date('Y-m-d H:i:s'):null;$st=$pdo->prepare("INSERT INTO student_quiz_state(student_id,assignment_id,unit_id,attempt_number,quiz_set_json,answers_json,is_completed,score_percent,correct_count,wrong_count,skip_count,total_count,started_at,completed_at)VALUES(:s,:a,:u,:att,:qj,:aj,:comp,:pct,:cor,:wr,:sk,:tot,NOW(),:cat)ON CONFLICT(student_id,assignment_id,unit_id,attempt_number)DO UPDATE SET quiz_set_json=CASE WHEN student_quiz_state.is_completed THEN student_quiz_state.quiz_set_json ELSE EXCLUDED.quiz_set_json END,answers_json=CASE WHEN student_quiz_state.is_completed THEN student_quiz_state.answers_json ELSE EXCLUDED.answers_json END,is_completed=CASE WHEN student_quiz_state.is_completed THEN TRUE ELSE EXCLUDED.is_completed END,score_percent=CASE WHEN student_quiz_state.is_completed THEN student_quiz_state.score_percent ELSE EXCLUDED.score_percent END,correct_count=CASE WHEN student_quiz_state.is_completed THEN student_quiz_state.correct_count ELSE EXCLUDED.correct_count END,wrong_count=CASE WHEN student_quiz_state.is_completed THEN student_quiz_state.wrong_count ELSE EXCLUDED.wrong_count END,skip_count=CASE WHEN student_quiz_state.is_completed THEN student_quiz_state.skip_count ELSE EXCLUDED.skip_count END,total_count=CASE WHEN student_quiz_state.is_completed THEN student_quiz_state.total_count ELSE EXCLUDED.total_count END,completed_at=CASE WHEN student_quiz_state.is_completed THEN student_quiz_state.completed_at WHEN EXCLUDED.is_completed THEN COALESCE(student_quiz_state.completed_at,NOW()) ELSE NULL END");$st->execute(['s'=>$sid,'a'=>(string)$asgn,'u'=>(string)$unit,'att'=>$att,'qj'=>json_encode($quiz),'aj'=>json_encode($answers),'comp'=>$completed?1:0,'pct'=>$pct,'cor'=>$cor,'wr'=>$wr,'sk'=>$sk,'tot'=>$tot,'cat'=>$cat]);}catch(Throwable $e){}}
function qz_check_teacher_unlock(PDO $pdo,string $sid,int $unit,string $asgn):bool{if($sid==='')return false;try{$st=$pdo->prepare("SELECT 1 FROM teacher_quiz_unlocks WHERE student_id=:s AND unit_id=:u AND assignment_id=:a LIMIT 1");$st->execute(['s'=>$sid,'u'=>(string)$unit,'a'=>(string)$asgn]);return(bool)$st->fetchColumn();}catch(Throwable $e){return false;}}
function qz_clear_teacher_unlock(PDO $pdo,string $sid,int $unit,string $asgn):void{if($sid==='')return;try{$st=$pdo->prepare("DELETE FROM teacher_quiz_unlocks WHERE student_id=:s AND unit_id=:u AND assignment_id=:a");$st->execute(['s'=>$sid,'u'=>(string)$unit,'a'=>(string)$asgn]);}catch(Throwable $e){}}
function qz_save_quiz_unit_score(PDO $pdo,string $sid,int $unit,string $asgn,int $pct):void{if($sid==='')return;try{$st=$pdo->prepare("INSERT INTO student_unit_results(student_id,assignment_id,unit_id,quiz_score_percent,completion_percent)VALUES(:s,:a,:u,:pct,0)ON CONFLICT(student_id,assignment_id,unit_id)DO UPDATE SET quiz_score_percent=GREATEST(COALESCE(student_unit_results.quiz_score_percent,0),EXCLUDED.quiz_score_percent)");$st->execute(['pct'=>$pct,'s'=>$sid,'a'=>(string)$asgn,'u'=>(string)$unit]);}catch(Throwable $e){}}
function qz_load_unit_metrics(PDO $pdo,string $sid,int $unit,string $asgn):?array{if($sid==='')return null;try{$st=$pdo->prepare("SELECT completion_percent,COALESCE(quiz_score_percent,0) AS quiz_score_percent FROM student_unit_results WHERE student_id=:s AND assignment_id=:a AND unit_id=:u LIMIT 1");$st->execute(['s'=>$sid,'a'=>(string)$asgn,'u'=>(string)$unit]);$row=$st->fetch(PDO::FETCH_ASSOC);return is_array($row)?$row:null;}catch(Throwable $e){return null;}}
function qz_load_all_completed_attempts(PDO $pdo,string $sid,int $unit,string $asgn):array{if($sid==='')return[];try{$st=$pdo->prepare("SELECT attempt_number,score_percent,correct_count,total_count,completed_at FROM student_quiz_state WHERE student_id=:s AND unit_id=:u AND assignment_id=:a AND is_completed=TRUE ORDER BY attempt_number ASC");$st->execute(['s'=>$sid,'u'=>(string)$unit,'a'=>(string)$asgn]);$rows=$st->fetchAll(PDO::FETCH_ASSOC);return is_array($rows)?$rows:[];}catch(Throwable $e){return[];}}
function qz_load_assignment_context(PDO $pdo,int $unit,string $asgn):array{try{$st=$pdo->prepare("SELECT COALESCE(sa.program,'') AS program,COALESCE(sa.course_id::text,'') AS course_id,COALESCE(sa.level_id::text,'') AS assigned_phase_id,COALESCE(u.module_id::text,'') AS module_id,COALESCE(t.name,'Teacher') AS teacher_name,COALESCE(u.name,'Unit ' || (:u)) AS unit_title,CASE WHEN COALESCE(sa.program,'')='english' THEN COALESCE(NULLIF(ep_assign.name,''),NULLIF(ep_unit.name,''),NULLIF(sa.period,''),'Phase') ELSE COALESCE(NULLIF(tm.name,''),NULLIF(c.name,''),NULLIF(sa.period,''),'Phase') END AS phase_name,CASE WHEN COALESCE(sa.program,'')='english' THEN COALESCE(NULLIF(el.name,''),'Level') ELSE COALESCE(NULLIF(c.name,''),'Level') END AS level_name FROM student_assignments sa LEFT JOIN teachers t ON t.id=sa.teacher_id LEFT JOIN units u ON u.id::text=:u LEFT JOIN technical_modules tm ON tm.id=u.module_id LEFT JOIN courses c ON c.id::text=sa.course_id LEFT JOIN english_phases ep_unit ON ep_unit.id=u.phase_id LEFT JOIN english_phases ep_assign ON ep_assign.id::text=sa.level_id LEFT JOIN english_levels el ON el.id=COALESCE(ep_assign.level_id,ep_unit.level_id) WHERE sa.id=:a LIMIT 1");$st->execute(['u'=>(string)$unit,'a'=>(string)$asgn]);$row=$st->fetch(PDO::FETCH_ASSOC);return is_array($row)?$row:[];}catch(Throwable $e){return[];}}
function qz_table_has_column(PDO $pdo,string $table,string $column):bool{try{$st=$pdo->prepare("SELECT 1 FROM information_schema.columns WHERE table_name=:t AND column_name=:c LIMIT 1");$st->execute(['t'=>$table,'c'=>$column]);return(bool)$st->fetchColumn();}catch(Throwable $e){return false;}}
function qz_load_phase_progress(PDO $pdo,string $sid,string $asgn,int $currentUnit,array $ctx=[]):array{if($sid==='')return['phase_avg'=>null,'phase_units'=>[]];try{$progressRows=[];$program=trim((string)($ctx['program']??''));$phaseId=trim((string)($ctx['assigned_phase_id']??''));$moduleId=trim((string)($ctx['module_id']??''));$courseId=trim((string)($ctx['course_id']??''));if($program==='english'&&$phaseId!==''&&qz_table_has_column($pdo,'units','phase_id')){$stUnits=$pdo->prepare("SELECT u.id::text AS unit_id,COALESCE(u.name,'Unit '||u.id::text) AS unit_name FROM units u WHERE u.phase_id::text=:phase_id ORDER BY u.name ASC NULLS LAST,u.id ASC");$stUnits->execute(['phase_id'=>$phaseId]);$progressRows=$stUnits->fetchAll(PDO::FETCH_ASSOC)?:[];}elseif($program==='technical'&&$moduleId!==''&&qz_table_has_column($pdo,'units','module_id')){$stUnits=$pdo->prepare("SELECT u.id::text AS unit_id,COALESCE(u.name,'Unit '||u.id::text) AS unit_name FROM units u WHERE u.module_id::text=:module_id ORDER BY u.name ASC NULLS LAST,u.id ASC");$stUnits->execute(['module_id'=>$moduleId]);$progressRows=$stUnits->fetchAll(PDO::FETCH_ASSOC)?:[];}elseif($courseId!==''&&qz_table_has_column($pdo,'units','course_id')){$stUnits=$pdo->prepare("SELECT u.id::text AS unit_id,COALESCE(u.name,'Unit '||u.id::text) AS unit_name FROM units u WHERE u.course_id::text=:course_id ORDER BY u.name ASC NULLS LAST,u.id ASC");$stUnits->execute(['course_id'=>$courseId]);$progressRows=$stUnits->fetchAll(PDO::FETCH_ASSOC)?:[];}if(empty($progressRows)){$stUnit=$pdo->prepare("SELECT u.id::text AS unit_id,COALESCE(u.name,'Unit '||u.id::text) AS unit_name FROM units u WHERE u.id::text=:unit_id LIMIT 1");$stUnit->execute(['unit_id'=>(string)$currentUnit]);$row=$stUnit->fetch(PDO::FETCH_ASSOC);if(is_array($row))$progressRows[]=$row;}$stScores=$pdo->prepare("SELECT unit_id,completion_percent,COALESCE(quiz_score_percent,0) AS quiz_score_percent FROM student_unit_results WHERE student_id=:s AND assignment_id=:a");$stScores->execute(['s'=>$sid,'a'=>(string)$asgn]);$scoreRows=$stScores->fetchAll(PDO::FETCH_ASSOC)?:[];$scoreMap=[];foreach($scoreRows as$r)$scoreMap[(string)($r['unit_id']??'')]=$r;$units=[];$sum=0.0;$n=0;foreach($progressRows as$r){$uid=(string)($r['unit_id']??'');if($uid==='')continue;$scoreRow=$scoreMap[$uid]??null;$score=null;if(is_array($scoreRow)){$act=max(0,min(100,(int)($scoreRow['completion_percent']??0)));$quiz=max(0,min(100,(int)($scoreRow['quiz_score_percent']??0)));$score=round(($act*0.6)+($quiz*0.4),1);$sum+=$score;$n++;}$units[]=['label'=>(string)($r['unit_name']??('Unit '.$uid)),'score'=>$score,'is_current'=>$uid===(string)$currentUnit];}return['phase_avg'=>$n>0?($sum/$n):null,'phase_units'=>$units];}catch(Throwable $e){return['phase_avg'=>null,'phase_units'=>[]];}}
function qz_log_result_score_flow(array $payload):void{error_log('[quiz_result_score_flow] '.json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));}
$unitId=isset($_GET['unit'])?intval($_GET['unit']):0;$returnTo=isset($_GET['return_to'])?trim((string)$_GET['return_to']):'';$assignment=qz_resolve_assignment_id($_GET['assignment']??0,$returnTo);$mode=$_GET['mode']??'intro';$qIndex=isset($_GET['q'])?intval($_GET['q']):0;if(!$unitId)die('Missing unit id.');$pdo=get_pdo();$st=$pdo->prepare('SELECT * FROM activities WHERE unit_id=:u ORDER BY id ASC');$st->execute(['u'=>$unitId]);$activities=$st->fetchAll(PDO::FETCH_ASSOC);$all=[];foreach($activities as$act)foreach(qz_normalize_activity($act)as$q)$all[]=$q;if(!$all)die('<div style="font-family:Arial;padding:30px;color:#7c3aed"><h2>No scoreable quiz activities found for this unit.</h2></div>');
$qzStudentId=trim((string)($_SESSION['student_id']??''));$qzHasDb=$qzStudentId!=='';if($qzHasDb)qz_ensure_quiz_state_table($pdo);$qzDbState=$qzHasDb?qz_load_db_state($pdo,$qzStudentId,$unitId,$assignment):null;error_log("UNIT: ".$unitId);error_log("ASSIGNMENT: ".$assignment);error_log("STUDENT: ".$qzStudentId);error_log("ATTEMPT FOUND: ".json_encode($qzDbState,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
$attKey='qz_attempt_'.$unitId.'_'.$assignment;if(!isset($_SESSION[$attKey])){if($qzDbState!==null)$_SESSION[$attKey]=qz_bool($qzDbState['is_completed'])?(int)$qzDbState['attempt_number']+1:max(1,(int)$qzDbState['attempt_number']);else $_SESSION[$attKey]=1;}$att=(int)$_SESSION[$attKey];$setKey='qz_set_'.$unitId.'_'.$assignment.'_'.$att;$ansKey='qz_answers_'.$unitId.'_'.$assignment.'_'.$att;
if(isset($_GET['reset'])){$qzCanReset=true;if($qzHasDb){$qzResetAttempts=qz_load_all_completed_attempts($pdo,$qzStudentId,$unitId,$assignment);$qzHasAttempt2=false;foreach($qzResetAttempts as$qzResetAttempt){if((int)($qzResetAttempt['attempt_number']??0)>=2){$qzHasAttempt2=true;break;}}$qzResetMetrics=qz_load_unit_metrics($pdo,$qzStudentId,$unitId,$assignment);$qzResetQuiz=max(0,min(100,(float)($qzResetMetrics['quiz_score_percent']??0)));$qzResetUnit=max(0,min(100,(float)($qzResetMetrics['completion_percent']??0)));$qzResetCombined=($qzResetUnit*0.6)+($qzResetQuiz*0.4);$qzCanReset=($qzResetQuiz<=64||$qzResetCombined<=64)&&!$qzHasAttempt2;}if($qzCanReset){$_SESSION[$attKey]=$att+1;unset($_SESSION[$setKey],$_SESSION[$ansKey]);if($qzHasDb)qz_clear_teacher_unlock($pdo,$qzStudentId,$unitId,$assignment);qz_redirect('intro',$unitId,$assignment,null,['return_to'=>$returnTo]);}qz_redirect('result',$unitId,$assignment,null,['return_to'=>$returnTo]);}
$qzLocked=false;$qzCompletedCount=0;$qzHasTeacherUnlock=false;$qzCanRetry=true;$qzHasCompletedAttempt=false;$qzHasFirstAttemptCompleted=false;$qzLatestCompletedAttempt=0;
$qzDbStateForAtt=($qzDbState!==null&&(int)$qzDbState['attempt_number']===$att&&!qz_bool($qzDbState['is_completed']))?$qzDbState:null;
if(!isset($_SESSION[$setKey])){if($qzDbStateForAtt!==null){$rs=json_decode((string)($qzDbStateForAtt['quiz_set_json']??'[]'),true);if(is_array($rs)&&!empty($rs))$_SESSION[$setKey]=$rs;}if(!isset($_SESSION[$setKey]))$_SESSION[$setKey]=qz_build($all,$unitId,$assignment,$att);}
if(!isset($_SESSION[$ansKey])){if($qzDbStateForAtt!==null){$ra=json_decode((string)($qzDbStateForAtt['answers_json']??'{}'),true);if(is_array($ra))$_SESSION[$ansKey]=$ra;}if(!isset($_SESSION[$ansKey]))$_SESSION[$ansKey]=[];}
$quiz=$_SESSION[$setKey];$answers=&$_SESSION[$ansKey];$total=count($quiz);$returnHref=$returnTo!==''?$returnTo:'../../academic/student_dashboard.php';
if($_SERVER['REQUEST_METHOD']==='POST'&&$mode==='quiz'){$qIndex=max(0,min($qIndex,$total-1));if(isset($answers[$qIndex])){$la=count($answers)>0?max(array_keys($answers)):-1;$nu=max(0,min($total-1,$la+1));if(count($answers)>=$total)qz_redirect('result',$unitId,$assignment,null,['return_to'=>$returnTo]);else qz_redirect('quiz',$unitId,$assignment,$nu,['return_to'=>$returnTo]);}$q=$quiz[$qIndex];if(isset($_POST['skip'])){$score=qz_answer_score($q,null);$answers[$qIndex]=['answer'=>null,'skipped'=>true,'correct'=>false,'earned'=>0.0,'possible'=>$score['possible']];}else{$ans=in_array($q['type'],['match','drag_drop'],true)?(isset($_POST['answer'])&&is_array($_POST['answer'])?$_POST['answer']:[]):($_POST['answer']??'');$score=qz_answer_score($q,$ans);$answers[$qIndex]=['answer'=>$ans,'skipped'=>false,'correct'=>$score['correct'],'earned'=>$score['earned'],'possible'=>$score['possible']];}$stats=qz_answers_totals($quiz,$answers);$qzC=(int)round($stats['earned']);$qzS=(int)$stats['skipped_questions'];$qzTotal=(int)$stats['possible'];$qzW=max(0,$qzTotal-$qzC);$qzP=$qzTotal?round($stats['earned']/$qzTotal*100):0;$qzDone=count($answers)>=$total;if($qzHasDb){qz_save_db_state($pdo,$qzStudentId,$unitId,$assignment,$att,$quiz,$answers,$qzDone,(int)$qzP,$qzC,$qzW,$qzS,$qzTotal);if($qzDone)qz_save_quiz_unit_score($pdo,$qzStudentId,$unitId,$assignment,(int)$qzP);}$n=$qIndex+1;qz_redirect($n>=$total?'result':'quiz',$unitId,$assignment,$n>=$total?null:$n,['return_to'=>$returnTo]);}
$labels=['dictation'=>['Dictation','Listen and type what you hear','ti-keyboard'],'pronunciation'=>['Pronunciation','Say the phrase','ti-microphone'],'multiple_choice'=>['Multiple choice','Pick the correct answer','ti-checks'],'fill'=>['Fill in the blank','Complete the sentence','ti-pencil'],'writing_practice'=>['Writing practice','Write your answer','ti-writing'],'match'=>['Match','Connect each word to its pair','ti-arrows-shuffle'],'drag_drop'=>['Drag and drop','Arrange or match items','ti-hand-move'],'unscramble'=>['Unscramble','Put the words in order','ti-sort-ascending']];$counts=[];foreach($quiz as$q){$bt=$q['block_type']??$q['type'];$counts[$bt]=($counts[$bt]??0)+1;}$score_stats=qz_answers_totals($quiz,$answers);$correct=(int)round($score_stats['earned']);$skip=(int)$score_stats['skipped_questions'];$score_total=(int)$score_stats['possible'];$wrong=max(0,$score_total-$correct);$percent=$score_total?round($score_stats['earned']/$score_total*100):0;
$quiz_score=(float)$percent;$unit_score=0.0;$phase_avg=0.0;$phase_name='Phase';$teacher_name='Teacher';$unit_title='Unit '.$unitId;$level_name='Level';$phase_units=[['label'=>'Unit '.$unitId,'score'=>null,'is_current'=>true]];$ctx=[];
if($qzHasDb){$metrics=qz_load_unit_metrics($pdo,$qzStudentId,$unitId,$assignment);if(is_array($metrics)){$unit_score=max(0,min(100,(float)($metrics['completion_percent']??$unit_score)));$quiz_score=max(0,min(100,(float)($metrics['quiz_score_percent']??$quiz_score)));}$ctx=qz_load_assignment_context($pdo,$unitId,$assignment);if(is_array($ctx)&&!empty($ctx)){$phase_name=trim((string)($ctx['phase_name']??''))!==''?(string)$ctx['phase_name']:$phase_name;$teacher_name=trim((string)($ctx['teacher_name']??''))!==''?(string)$ctx['teacher_name']:$teacher_name;$unit_title=trim((string)($ctx['unit_title']??''))!==''?(string)$ctx['unit_title']:$unit_title;$level_name=trim((string)($ctx['level_name']??''))!==''?(string)$ctx['level_name']:$level_name;}$progress=qz_load_phase_progress($pdo,$qzStudentId,$assignment,$unitId,is_array($ctx)?$ctx:[]);if(is_array($progress)){if(isset($progress['phase_avg'])&&$progress['phase_avg']!==null)$phase_avg=max(0,min(100,(float)$progress['phase_avg']));if(isset($progress['phase_units'])&&is_array($progress['phase_units'])&&!empty($progress['phase_units']))$phase_units=$progress['phase_units'];}}

// In result mode, if current attempt answers are incomplete (e.g. after refresh/logout),
// fall back to the last completed DB attempt's stats so correct/total display correctly.
if($mode==='result'&&count($answers)<$total&&$qzDbState!==null&&qz_bool($qzDbState['is_completed'])){$dbCor=(int)($qzDbState['correct_count']??0);$dbTot=(int)($qzDbState['total_count']??1);$correct=$dbCor;$score_total=$dbTot;$wrong=max(0,$dbTot-$dbCor);$skip=0;}
$qzAllAttempts=$qzHasDb?qz_load_all_completed_attempts($pdo,$qzStudentId,$unitId,$assignment):[];
if(!empty($qzAllAttempts)){$qzCompletedCount=count($qzAllAttempts);$qzHasCompletedAttempt=$qzCompletedCount>0;$qzLatestCompletedAttempt=(int)($qzAllAttempts[$qzCompletedCount-1]['attempt_number']??0);foreach($qzAllAttempts as$qzAttemptRow){if((int)($qzAttemptRow['attempt_number']??0)===1){$qzHasFirstAttemptCompleted=true;break;}}}
$attempt1_score=null;$attempt2_score=null;$attempt_number=max(1,min(2,$att));$max_attempts=2;
foreach($qzAllAttempts as$qzAttemptRow){$qzAttemptNo=(int)($qzAttemptRow['attempt_number']??0);$qzAttemptPct=(int)($qzAttemptRow['score_percent']??0);if($qzAttemptNo===1)$attempt1_score=$qzAttemptPct;if($qzAttemptNo===2)$attempt2_score=$qzAttemptPct;}
if($qzLatestCompletedAttempt>0)$attempt_number=max(1,min(2,$qzLatestCompletedAttempt));
$qzCurrentAttemptCompleted=count($answers)>=$total&&$total>0;
$qzHasFirstAttemptCompleted=$qzHasFirstAttemptCompleted||$qzHasCompletedAttempt||$qzCurrentAttemptCompleted;
$qzCombinedScore=round(($unit_score*0.6)+($quiz_score*0.4),1);
$qzCanRetryByScore=$quiz_score<=64||$qzCombinedScore<=64;
$qzCanRetry=($attempt_number<$max_attempts)&&$attempt2_score===null&&$qzCanRetryByScore;
$lastAnswered=count($answers)>0?max(array_keys($answers)):-1;$currentQuizIndex=max(0,min($total-1,$lastAnswered+1));$rtParam='&return_to='.urlencode($returnHref);$resultHref='?mode=result&unit='.$unitId.'&assignment='.$assignment.$rtParam;$reviewHref='?mode=review&unit='.$unitId.'&assignment='.$assignment.$rtParam;$quizHref='?mode=quiz&q='.$currentQuizIndex.'&unit='.$unitId.'&assignment='.$assignment.$rtParam;
$quizStartHref='?mode=quiz&q=0&unit='.$unitId.'&assignment='.$assignment.$rtParam;$retakeHref='?mode=intro&reset=1&unit='.$unitId.'&assignment='.$assignment.$rtParam;$qzShowTakeQuizState=in_array($mode,['result','review'],true)&&!$qzHasFirstAttemptCompleted;$qzTabsLocked=!$qzHasFirstAttemptCompleted;$resultTabHref=$qzTabsLocked?$quizStartHref:$resultHref;$reviewTabHref=$qzTabsLocked?$quizStartHref:$reviewHref;
if($mode==='quiz'&&$qzLocked)$mode='intro';
if($mode==='quiz'&&$qzHasFirstAttemptCompleted&&!$qzCanRetry)qz_redirect('result',$unitId,$assignment,null,['return_to'=>$returnTo]);
if($mode==='quiz'&&count($answers)>=$total)qz_redirect('result',$unitId,$assignment,null,['return_to'=>$returnTo]);
if($mode==='quiz'&&isset($answers[$qIndex])&&count($answers)<$total)qz_redirect('quiz',$unitId,$assignment,$currentQuizIndex,['return_to'=>$returnTo]);
if($mode==='result'&&count($answers)>=$total&&$total>0&&$qzHasDb){$rStats=qz_answers_totals($quiz,$answers);$rTotal=(int)$rStats['possible'];$rC=(int)round($rStats['earned']);$rS=(int)$rStats['skipped_questions'];$rW=max(0,$rTotal-$rC);$rP=$rTotal?round($rStats['earned']/$rTotal*100):0;qz_save_db_state($pdo,$qzStudentId,$unitId,$assignment,$att,$quiz,$answers,true,(int)$rP,$rC,$rW,$rS,$rTotal);qz_save_quiz_unit_score($pdo,$qzStudentId,$unitId,$assignment,(int)$rP);}
?><!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Unit Quiz</title><link href="https://fonts.googleapis.com/css2?family=Fredoka:wght@500;600;700&family=Nunito:wght@500;600;700;800;900&display=swap" rel="stylesheet"><link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@latest/tabler-icons.min.css"><style>:root{--pu:#8070dd;--or:#ff7315;--ink:#14113a;--mut:#8f86c5;--line:#e9e3fb;--bg:#f8f7ff}*{box-sizing:border-box}body{margin:0;background:var(--bg);font-family:Nunito,sans-serif;color:var(--ink)}.page{max-width:1020px;margin:auto;padding:34px 24px 56px}.top,.card{background:white;border:1px solid var(--line);border-radius:22px}.top{padding:18px 28px;display:flex;justify-content:space-between;max-width:820px;margin:0 auto 26px}.brand{font-weight:900;color:var(--pu);font-size:20px}.sub{font-size:15px;color:var(--mut)}.tabs{display:flex;gap:12px;justify-content:center;flex-wrap:wrap;margin-bottom:20px}.tab{display:inline-flex;align-items:center;justify-content:center;text-decoration:none;border:1px solid var(--line);background:white;color:var(--pu);border-radius:12px;padding:11px 22px;font-size:13px;font-weight:900;box-shadow:0 7px 18px rgba(127,112,221,.13);min-width:112px}.tab:hover{transform:translateY(-1px);box-shadow:0 10px 22px rgba(127,112,221,.18)}.tab.on{background:var(--pu);color:white;border-color:var(--pu)}.screen-title{text-align:center;color:#c0b8e8;font-size:13px;font-weight:900;letter-spacing:.08em;text-transform:uppercase;margin-bottom:14px}.card{max-width:720px;margin:auto;padding:36px}.kicker{display:inline-block;background:#fff0e6;color:var(--or);border:1px solid #ffd1ad;border-radius:999px;padding:7px 16px;font-weight:900;font-size:13px}.title{font:700 40px Fredoka,sans-serif;color:var(--or);margin:14px 0 6px}.lead{color:var(--pu);font-size:18px;line-height:1.45}.chips{display:flex;gap:10px;flex-wrap:wrap;margin:22px 0}.chip{border:1px solid var(--line);border-radius:999px;color:var(--pu);padding:9px 14px;font-size:14px;font-weight:900;background:#fbfaff}.hr{height:1px;background:var(--line);margin:24px 0}.included{color:#a99ee0;font-size:13px;font-weight:900}.type-row{display:flex;gap:14px;align-items:center;background:#f6f3ff;border-radius:15px;padding:16px;margin:10px 0;text-decoration:none;color:inherit}.type-row:hover{outline:2px solid var(--pu)}.type-row i{color:var(--pu);font-size:24px}.type-row b{font-size:16px}.type-row span:last-child{margin-left:auto;color:var(--pu);font-weight:900;font-size:18px}.btn{border:0;border-radius:13px;padding:16px 20px;font-weight:900;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;justify-content:center;gap:8px;font-size:16px}.btn-primary{background:var(--or);color:white}.btn-purple{background:var(--pu);color:white}.btn-light{background:white;color:var(--pu);border:1px solid var(--line)}.w100{width:100%}.progress-head{display:flex;justify-content:space-between;color:var(--pu);font-size:14px;font-weight:900}.track{height:9px;background:#eeeafa;border-radius:999px;overflow:hidden;margin:10px 0 24px}.bar{height:100%;background:linear-gradient(90deg,var(--or),var(--pu))}.tag{display:inline-flex;gap:8px;align-items:center;background:#f0ecff;color:var(--pu);border-radius:999px;padding:8px 13px;font-size:13px;font-weight:900;text-transform:uppercase;margin-bottom:16px}.question{font-weight:900;line-height:1.4;margin-bottom:22px;font-size:24px}.option{border:1px solid var(--line);border-radius:14px;padding:16px;margin-bottom:12px;display:flex;gap:14px;font-weight:800;font-size:17px;cursor:pointer}.option input{display:none}.letter{background:#eeeafa;color:var(--pu);border-radius:999px;width:30px;height:30px;display:inline-flex;align-items:center;justify-content:center}.option:hover,.option:has(input:checked){border-color:var(--pu);background:#f8f6ff}.input,.select{width:100%;border:1px solid var(--line);border-radius:13px;padding:16px;font:800 17px Nunito;margin-bottom:12px}.match-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}.match-left{padding:15px;border:1px solid var(--pu);border-radius:12px;text-align:center;font-weight:900;background:#fbfaff;font-size:16px}.actions{display:flex;gap:12px;margin-top:18px}.result-hero{background:linear-gradient(135deg,#eee9ff,#fff0e6);border-radius:18px;text-align:center;padding:36px 22px;margin-bottom:22px}.pct{font:700 58px Fredoka,sans-serif;color:var(--or)}.pill{display:inline-flex;border-radius:999px;padding:7px 12px;font-size:14px;font-weight:900;margin:5px}.review{border:1px solid var(--line);border-radius:15px;padding:16px;margin-bottom:12px}.pron-card{min-height:480px;border:1px solid #EDE9FA;border-radius:30px;background:#fff;padding:18px;text-align:center;box-shadow:0 8px 24px rgba(127,119,221,.09)}.pron-listen-cue{display:inline-flex;margin-bottom:12px;padding:6px 13px;border-radius:999px;background:#EEEDFE;color:#534AB7;font-size:12px;font-weight:900}.pron-image{width:100%;height:340px;margin-bottom:14px;border-radius:24px;background:#fff;border:1px solid #EDE9FA;display:flex;align-items:center;justify-content:center;overflow:hidden;padding:10px}.pron-image img{width:100%;height:100%;object-fit:contain;border-radius:18px}.pron-word{font-size:28px;font-weight:900;color:#534AB7}.pron-box{width:100%;margin-top:8px;border-radius:12px;padding:9px 12px;font-size:13px;font-weight:800;text-align:center}.pron-captured{border:1px solid #EDE9FA;background:#fff;color:#534AB7}.pron-actions{display:flex;justify-content:center;gap:10px;flex-wrap:wrap;margin-top:16px;padding-top:16px;border-top:1px solid #F0EEF8}.pron-btn{border:0;border-radius:10px;min-width:130px;padding:13px 20px;color:#fff;font-size:13px;font-weight:900;cursor:pointer}.pron-purple{background:#7F77DD}.pron-orange{background:#F97316}.us-list{display:flex;flex-wrap:wrap;gap:10px;min-height:92px;border:1px solid var(--line);border-radius:18px;padding:14px;background:#fbfaff;margin-bottom:12px}.us-chip{padding:12px 16px;border-radius:16px;background:white;border:1px solid #EDE9FA;box-shadow:0 4px 14px rgba(127,119,221,.13);font-weight:900;color:#534AB7;cursor:grab;user-select:none}.us-chip.dragging{opacity:.35}.us-chip:hover{border-color:#7F77DD+transform:translateY(-1px)}.btn-back{display:inline-flex;align-items:center;gap:6px;padding:9px 16px;border-radius:10px;background:#f0ecff;color:var(--pu);font-size:13px;font-weight:900;text-decoration:none;border:1px solid var(--line);transition:background .15s,transform .12s;white-space:nowrap;}
.btn-back:hover{background:#e5dff8;transform:translateY(-1px);}
.qz-lock-modal{position:fixed;inset:0;z-index:999;display:flex;align-items:center;justify-content:center;padding:20px;background:rgba(20,17,58,.48)}
.qz-lock-modal[hidden]{display:none}
.qz-lock-card{width:min(100%,380px);background:#fff;border:1px solid #EDE9FA;border-radius:24px;box-shadow:0 24px 60px rgba(20,17,58,.22);padding:26px;text-align:center}
.qz-lock-badge{display:inline-flex;align-items:center;justify-content:center;width:56px;height:56px;border-radius:18px;background:#FFF0E6;color:#F97316;font-size:28px;margin-bottom:12px}
.qz-lock-title{font-family:'Fredoka',sans-serif;font-size:28px;font-weight:700;color:#F97316;margin:0 0 8px}
.qz-lock-copy{margin:0;color:#7F77DD;font-size:14px;font-weight:700;line-height:1.5}
.qz-lock-actions{display:flex;gap:10px;margin-top:18px}
.qz-lock-actions .btn{flex:1}
@media(max-width:760px){.page{padding:22px 14px 42px}.top{max-width:100%;padding:14px 18px}.card{max-width:100%;padding:24px}.title{font-size:34px}.question{font-size:20px}.match-grid{grid-template-columns:1fr}.actions{flex-direction:column}.pron-image{height:280px}.pron-actions{display:grid;grid-template-columns:1fr}.pron-btn{width:100%}.tab{min-width:0;flex:1;padding:10px 12px}}/* ── Unscramble (word-bank + build-area) ── */
.qz-build-area{display:flex;flex-wrap:wrap;align-items:center;gap:10px;min-height:80px;padding:14px 16px;border-radius:18px;border:2px dashed #EDE9FA;background:#FAFAFE;margin-bottom:14px;transition:border-color .15s,background .15s;}
.qz-build-area.qz-drag-over{border-color:#7F77DD;background:#F5F3FF;}
.qz-build-placeholder{color:#9B94BE;font-size:14px;font-weight:800;pointer-events:none;}
.qz-word-bank{display:flex;flex-wrap:wrap;gap:10px;min-height:56px;padding:14px 16px;border-radius:18px;border:1px solid #EDE9FA;background:#fff;margin-bottom:14px;}
.qz-chip{display:inline-flex;align-items:center;justify-content:center;padding:11px 16px;min-height:42px;min-width:60px;border-radius:10px;font-family:'Nunito',sans-serif;font-size:16px;font-weight:900;cursor:grab;user-select:none;transition:transform .12s,box-shadow .12s,border-color .12s;}
.qz-chip:active{cursor:grabbing;transform:translateY(1px);}
.qz-bank-chip{background:#fff;color:#4A3FC2;border:1.5px solid #BDB5EE;border-bottom:3px solid #7F77DD;box-shadow:0 2px 0 rgba(127,119,221,.18);}
.qz-bank-chip:hover{transform:translateY(-1px);border-color:#AFA6EA;box-shadow:0 6px 14px rgba(127,119,221,.18);}
.qz-built-chip{background:#F8F7FF;color:#4338CA;border:1.5px solid #BDB5EE;border-bottom:3px solid #7F77DD;box-shadow:0 2px 0 rgba(127,119,221,.18);}
.qz-built-chip:hover{transform:translateY(-1px);box-shadow:0 6px 14px rgba(127,119,221,.16);}
.qz-correct-chip{background:#f0fdf4;color:#166534;border:1.5px solid #22c55e;border-bottom:3px solid #16a34a;box-shadow:none;cursor:default;}
.qz-wrong-chip{background:#fef2f2;color:#991b1b;border:1.5px solid #ef4444;border-bottom:3px solid #dc2626;box-shadow:none;cursor:default;}
.qz-us-feedback{text-align:center;font-size:13px;font-weight:900;min-height:18px;margin-bottom:12px;color:#534AB7;}
.qz-us-feedback.good{color:#166534;}
.qz-us-feedback.bad{color:#b91c1c;}
.qz-dd-instruction{display:flex;flex-wrap:wrap;gap:10px;align-items:center;padding:16px 18px;border:1px solid var(--line);border-radius:18px;background:#fbfaff;margin-bottom:14px;}
.qz-dd-text{font-size:18px;font-weight:800;color:#4338CA;}
.qz-dd-slot{display:inline-flex;align-items:center;justify-content:flex-start;min-width:110px;min-height:52px;padding:8px 10px;border-radius:14px;border:2px dashed #BDB5EE;background:#fff;transition:border-color .15s,background .15s;}
.qz-dd-slot.qz-drag-over{border-color:#7F77DD;background:#F5F3FF;}
.qz-dd-slot.qz-dd-filled{border-style:solid;}
.qz-dd-placeholder{font-size:13px;font-weight:900;color:#9B94BE;pointer-events:none;}
.qz-fill-inline{display:flex;flex-wrap:wrap;align-items:baseline;gap:8px;font-size:24px;font-weight:900;line-height:1.65;color:#14113A;margin-bottom:10px;}
.qz-fill-inline .qz-fill-text{display:inline;}
.qz-fill-blank{border:none;background:transparent;outline:none;text-align:center;font:800 24px/1.2 Nunito,sans-serif;color:#4338CA;padding:0 3px;min-width:56px;max-width:220px;width:5ch;vertical-align:baseline;border-radius:6px;transition:box-shadow .15s,background .15s;}
.qz-fill-blank:focus{background:#F8F7FF;box-shadow:inset 0 0 0 1px #534AB7;}
.qz-fill-input-lite{width:100%;border:1.5px solid #DCD5FA;border-radius:12px;background:#fff;color:#4338CA;font:800 24px/1.3 Nunito,sans-serif;padding:10px 14px;margin-bottom:8px;outline:none;transition:border-color .15s,box-shadow .15s;}
.qz-fill-input-lite:focus{border-color:#7F77DD;box-shadow:0 0 0 3px rgba(127,119,221,.14);}
input.qz-dd-hidden{display:none;}
@media(max-width:760px){.qz-chip{padding:10px 13px;font-size:15px;}.qz-build-area,.qz-word-bank{padding:12px;}.qz-fill-inline{font-size:20px;gap:6px;}.qz-fill-blank,.qz-fill-input-lite{font-size:20px;}}
</style></head><body><div class="page"><div class="top"><div style="display:flex;align-items:center;gap:10px;"><svg width="42" height="42" viewBox="0 0 36 36" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><rect width="36" height="36" rx="9" fill="#FFF0E6"/><circle cx="17" cy="15" r="8.5" fill="#F97316"/><polygon points="12,22 7,30 21,26" fill="#F97316"/><circle cx="17" cy="15" r="4.5" fill="#FFF0E6"/><circle cx="24" cy="9" r="3.5" fill="#7B6EE6"/><circle cx="24" cy="9" r="1.75" fill="#ffffff"/></svg><div><div class="brand">ONES</div><div class="sub">ONLINE ENGLISH SOLUTION · <?php echo$total;?> preguntas en bloques</div></div></div><a class="btn-back" href="<?php echo qz_h($returnHref);?>">&#8592; Back</a></div><div class="tabs"><a class="tab <?php echo$mode==='intro'?'on':'';?>" href="?mode=intro&unit=<?php echo$unitId;?>&assignment=<?php echo$assignment;?>&return_to=<?php echo urlencode($returnHref);?>">Intro</a><a class="tab <?php echo$mode==='quiz'?'on':'';?>" href="<?php echo qz_h($quizHref);?>">Quiz</a><a class="tab <?php echo$mode==='result'?'on':'';?>" href="<?php echo qz_h($resultTabHref);?>"<?php if($qzTabsLocked):?> data-quiz-locked="1" aria-disabled="true"<?php endif; ?>>Resultado</a><a class="tab <?php echo$mode==='review'?'on':'';?>" href="<?php echo qz_h($reviewTabHref);?>"<?php if($qzTabsLocked):?> data-quiz-locked="1" aria-disabled="true"<?php endif; ?>>Review</a></div><div class="qz-lock-modal" id="qzTakeQuizModal" hidden><div class="qz-lock-card" role="dialog" aria-modal="true" aria-labelledby="qzTakeQuizTitle"><div class="qz-lock-badge">📝</div><h2 class="qz-lock-title" id="qzTakeQuizTitle">Take Quiz</h2><p class="qz-lock-copy">Complete your first attempt before opening Result or Review.</p><div class="qz-lock-actions"><button type="button" class="btn btn-light" id="qzTakeQuizClose">Close</button><a class="btn btn-primary" href="<?php echo qz_h($quizStartHref);?>">Take Quiz</a></div></div></div><script>(function(){var modal=document.getElementById('qzTakeQuizModal');if(!modal)return;var closeBtn=document.getElementById('qzTakeQuizClose');function hideModal(){modal.hidden=true;document.body.style.overflow='';}function showModal(){modal.hidden=false;document.body.style.overflow='hidden';}document.querySelectorAll('[data-quiz-locked="1"]').forEach(function(link){link.addEventListener('click',function(e){e.preventDefault();showModal();});});if(closeBtn)closeBtn.addEventListener('click',hideModal);modal.addEventListener('click',function(e){if(e.target===modal)hideModal();});document.addEventListener('keydown',function(e){if(e.key==='Escape'&&!modal.hidden)hideModal();});})();</script>
<?php if($mode==='intro'):?><div class="screen-title">Pantalla 1 — Portada del quiz</div><div class="card"><span class="kicker">UNIT <?php echo$unitId;?> · QUIZ</span><div class="title">Unit Quiz</div><div class="lead">Choose a block to start there, or press Start quiz to begin from pronunciation.</div><div class="chips"><span class="chip"><?php echo$total;?> questions</span><span class="chip">Max 6 per activity type</span><span class="chip">Skipped = 0</span></div><div class="hr"></div><div class="included">WHAT'S INCLUDED</div><?php foreach($counts as$t=>$c):$inf=$labels[$t]??[$t,'','ti-circle'];$first=0;foreach($quiz as$i=>$qq){if(($qq['block_type']??$qq['type'])===$t){$first=$i;break;}}?><a class="type-row" href="?mode=quiz&q=<?php echo$first;?>&unit=<?php echo$unitId;?>&assignment=<?php echo$assignment;?>&return_to=<?php echo urlencode($returnHref);?>"><i class="ti <?php echo qz_h($inf[2]);?>"></i><div><b><?php echo qz_h($inf[0]);?></b><small style="display:block;color:var(--mut)"><?php echo qz_h($inf[1]);?></small></div><span><?php echo$c;?></span></a><?php endforeach;?><div class="hr"></div><a class="btn btn-purple w100" href="?mode=quiz&q=0&unit=<?php echo$unitId;?>&assignment=<?php echo$assignment;?>&return_to=<?php echo urlencode($returnHref);?>">Start quiz</a></div>
<?php elseif($mode==='quiz'):$qIndex=max(0,min($qIndex,$total-1));$q=$quiz[$qIndex];$bt=$q['block_type']??$q['type'];$inf=$labels[$bt]??[$bt,'','ti-circle'];?><div class="screen-title">Pregunta <?php echo$qIndex+1;?> de <?php echo$total;?> — <?php echo qz_h($inf[0]);?></div><div class="card"><div class="progress-head"><span>PROGRESS</span><span><?php echo$qIndex+1;?> / <?php echo$total;?></span></div><div class="track"><div class="bar" style="width:<?php echo round((($qIndex+1)/$total)*100);?>%"></div></div><div class="tag"><i class="ti <?php echo qz_h($inf[2]);?>"></i><?php echo qz_h($inf[0]);?></div><?php if($q['type']==='pronunciation'):?><div class="pron-card"><div class="pron-listen-cue">Listen first</div><div class="pron-image"><?php if(!empty($q['image'])):?><img src="<?php echo qz_h($q['image']);?>" alt="<?php echo qz_h($q['correct']);?>"><?php else:?><div class="pron-word"><?php echo qz_h($q['correct']);?></div><?php endif;?></div><div class="pron-box pron-captured" id="pron-captured"></div></div><form method="post" id="pron-form"><input type="hidden" name="answer" id="pron-answer" value=""><div class="pron-actions"><button type="button" class="pron-btn pron-purple" id="pron-listen">Listen</button><button type="button" class="pron-btn pron-purple" id="pron-speak">Speaker</button><button type="submit" class="pron-btn pron-purple" name="skip" value="1" formnovalidate>Skip</button></div></form><script>(function(){var expected=<?php echo json_encode((string)$q['correct']);?>;var audio=<?php echo json_encode((string)$q['audio']);?>;var cap=document.getElementById('pron-captured'),answer=document.getElementById('pron-answer'),form=document.getElementById('pron-form'),submitted=false;var currentAudio=null,isSpeaking=false,isPaused=false,utter=null;function norm(t){return String(t||'').toLowerCase().trim().replace(/[.,!?;:'"-]/g,'').replace(/\s+/g,' ')}function overlap(a,b){var wa=a.split(' ').filter(Boolean),wb=b.split(' ').filter(Boolean);if(!wa.length||!wb.length)return 0;var m=wa.filter(function(w){return wb.indexOf(w)!==-1}).length;return m/Math.max(wa.length,wb.length)}function isMatch(a,b){return a===b||overlap(a,b)>=.8}function submit(ok){if(submitted)return;submitted=true;answer.value=ok?'1':'';setTimeout(function(){form.submit()},250)}function label(){var b=document.getElementById('pron-listen');if(b)b.textContent=isPaused?'Resume':(isSpeaking?'Pause':'Listen')}function voice(){var vs=[];try{vs=speechSynthesis.getVoices()||[]}catch(e){};for(var i=0;i<vs.length;i++)if(String(vs[i].lang||'').toLowerCase()==='en-us')return vs[i];for(var j=0;j<vs.length;j++)if(String(vs[j].lang||'').toLowerCase().indexOf('en')===0)return vs[j];return null}function listen(){if(audio){if(!currentAudio){currentAudio=new Audio(audio);currentAudio.onended=function(){isSpeaking=false;isPaused=false;label()}}if(!currentAudio.paused){currentAudio.pause();isSpeaking=true;isPaused=true}else{currentAudio.play();isSpeaking=true;isPaused=false}label();return}if(!window.speechSynthesis)return;if(speechSynthesis.speaking&&!speechSynthesis.paused){speechSynthesis.pause();isSpeaking=true;isPaused=true;label();return}if(speechSynthesis.paused||isPaused){speechSynthesis.resume();isSpeaking=true;isPaused=false;label();return}speechSynthesis.cancel();utter=new SpeechSynthesisUtterance(expected);utter.lang='en-US';utter.rate=.82;utter.pitch=1;var v=voice();if(v)utter.voice=v;utter.onstart=function(){isSpeaking=true;isPaused=false;label()};utter.onend=function(){isSpeaking=false;isPaused=false;label()};speechSynthesis.speak(utter)}function speak(){var C=window.SpeechRecognition||window.webkitSpeechRecognition;if(!C){submit(false);return}var r=new C();r.lang='en-US';r.interimResults=false;r.maxAlternatives=1;r.continuous=false;var heard=false;cap.textContent='';r.onresult=function(e){heard=true;var said=String(e.results&&e.results[0]&&e.results[0][0]?e.results[0][0].transcript:'');var ok=isMatch(norm(said),norm(expected));submit(ok)};r.onerror=function(){heard=true;submit(false)};r.onend=function(){if(!heard)submit(false)};r.start()}document.getElementById('pron-listen').onclick=listen;document.getElementById('pron-speak').onclick=speak;})();</script><?php elseif($q['type']==='dictation'):?><div class="pron-card"><div class="pron-listen-cue">Listen and type what you hear</div><div class="pron-image" style="min-height:140px;"><?php if(!empty($q['image'])):?><img src="<?php echo qz_h($q['image']);?>" alt="<?php echo qz_h($q['correct']);?>"><?php else:?><span style="font-size:64px;line-height:1;">🎧</span><?php endif;?></div><div class="pron-box pron-captured" id="dict-status"></div></div><form method="post" id="dict-form"><input type="hidden" name="answer" id="dict-answer" value=""><input class="input" id="dict-input" autocomplete="off" placeholder="Type what you heard…"><div class="pron-actions"><button type="button" class="pron-btn pron-purple" id="dict-listen">Listen</button><button type="button" class="pron-btn pron-orange" id="dict-next">Next</button><button type="submit" class="pron-btn pron-purple" name="skip" value="1" formnovalidate>Skip</button></div></form><script>(function(){var audio=<?php echo json_encode((string)$q['audio']);?>;var expected=<?php echo json_encode((string)$q['correct']);?>;var status=document.getElementById('dict-status');var input=document.getElementById('dict-input');var answer=document.getElementById('dict-answer');var form=document.getElementById('dict-form');var submitted=false;var currentAudio=null,isSpeaking=false,isPaused=false,utter=null;function labelBtn(){var b=document.getElementById('dict-listen');if(b)b.textContent=isPaused?'Resume':(isSpeaking?'Pause':'Listen');}function getVoice(){var vs=[];try{vs=speechSynthesis.getVoices()||[];}catch(e){}for(var i=0;i<vs.length;i++)if(String(vs[i].lang||'').toLowerCase()==='en-us')return vs[i];for(var j=0;j<vs.length;j++)if(String(vs[j].lang||'').toLowerCase().indexOf('en')===0)return vs[j];return null;}function listen(){if(audio){if(!currentAudio){currentAudio=new Audio(audio);currentAudio.onended=function(){isSpeaking=false;isPaused=false;labelBtn();};}if(!currentAudio.paused){currentAudio.pause();isSpeaking=true;isPaused=true;}else{currentAudio.play();isSpeaking=true;isPaused=false;}labelBtn();return;}if(!window.speechSynthesis)return;if(speechSynthesis.speaking&&!speechSynthesis.paused){speechSynthesis.pause();isSpeaking=true;isPaused=true;labelBtn();return;}if(speechSynthesis.paused||isPaused){speechSynthesis.resume();isSpeaking=true;isPaused=false;labelBtn();return;}speechSynthesis.cancel();utter=new SpeechSynthesisUtterance(expected);utter.lang='en-US';utter.rate=.82;utter.pitch=1;var v=getVoice();if(v)utter.voice=v;utter.onstart=function(){isSpeaking=true;isPaused=false;labelBtn();};utter.onend=function(){isSpeaking=false;isPaused=false;labelBtn();};speechSynthesis.speak(utter);}document.getElementById('dict-listen').onclick=listen;document.getElementById('dict-next').onclick=function(){if(submitted)return;submitted=true;answer.value=input.value.trim();form.submit();};input.addEventListener('keydown',function(e){if(e.key==='Enter'){e.preventDefault();if(!submitted){submitted=true;answer.value=input.value.trim();form.submit();}}});setTimeout(listen,150);})();</script><?php elseif($q['type']==='multiple_choice'):?><?php $qzMcListen=($q['question_type']??'text')==='listen';$qzMcImageOptions=($q['option_type']??'text')==='image';?><form method="post" id="mc-form"><?php if($qzMcListen):?><div style="margin-bottom:12px;"><button type="button" class="btn btn-light" id="qz-mc-listen">🔊 Listen</button></div><?php else:?><div class="question"><?php echo qz_h($q['question']);?></div><?php endif;?><?php if(!empty($q['image'])):?><div style="margin-bottom:14px;"><img src="<?php echo qz_h($q['image']);?>" alt="Question image" style="width:100%;max-height:220px;object-fit:contain;border-radius:14px;border:1px solid var(--line);background:#fff;"></div><?php endif;?><?php foreach($q['options']as$i=>$op):?><label class="option"><input type="radio" name="answer" value="<?php echo$i;?>" onchange="setTimeout(function(){document.getElementById('mc-form').submit()},180)"><span class="letter"><?php echo chr(65+$i);?></span><?php if($qzMcImageOptions):?><img src="<?php echo qz_h($op);?>" alt="Option <?php echo chr(65+$i);?>" style="max-width:100%;max-height:120px;object-fit:contain;border-radius:10px;"><?php else:?><?php echo qz_h($op);?><?php endif;?></label><?php endforeach;?><div class="actions"><button class="btn btn-light" type="submit" name="skip" value="1" formnovalidate>Skip</button></div></form><?php if($qzMcListen):?><script>(function(){var b=document.getElementById('qz-mc-listen');if(!b)return;var text=<?php echo json_encode((string)$q['question']);?>;var audioUrl=<?php echo json_encode((string)($q['audio']??''));?>;var u=null,a=null;function stop(){if(a){a.pause();a.currentTime=0;a=null;}try{if(window.speechSynthesis){window.speechSynthesis.cancel();}}catch(e){}}function speak(){if(!text)return;stop();if(audioUrl){a=new Audio(audioUrl);a.play().catch(function(){});return;}if(!window.speechSynthesis)return;u=new SpeechSynthesisUtterance(text);u.lang='en-US';u.rate=.85;window.speechSynthesis.speak(u);}b.addEventListener('click',speak);setTimeout(speak,150);window.addEventListener('beforeunload',stop);})();</script><?php endif;?><script>(function(){var form=document.getElementById('mc-form');if(!form)return;var radios=Array.from(form.querySelectorAll('input[type="radio"]'));var submitted=false;function submitNow(){if(submitted)return;submitted=true;form.submit();}form.addEventListener('keydown',function(evt){if(evt.key==='Enter'){evt.preventDefault();submitNow();return;}var idx='ABCDE'.indexOf(evt.key.toUpperCase());if(idx>=0&&idx<radios.length){evt.preventDefault();radios[idx].checked=true;submitNow();}});setTimeout(function(){try{if(radios[0])radios[0].focus();}catch(e){}},80);})();</script><?php elseif($q['type']==='drag_drop'):?><?php $qzDdWords=$q['options'];$qzDdShuffled=$qzDdWords;qz_shuffle($qzDdShuffled,qz_seed($unitId,$assignment,$att)+$qIndex+577);$qzDdParts=preg_split('/(___)/',$q['instruction']??'',-1,PREG_SPLIT_DELIM_CAPTURE);$qzDdSlotCount=count($q['correct_words']??[]);?><form method="post" id="qz-dd-form"><?php if(!empty($q['listen_enabled'])):?><div style="margin-bottom:12px;"><button type="button" class="btn btn-light" id="qz-dd-listen">🔊 Listen</button></div><?php endif;?><?php if(!empty($q['image'])):?><div style="margin-bottom:14px;"><img src="<?php echo qz_h($q['image']);?>" alt="Drag and drop image" style="width:100%;max-height:220px;object-fit:contain;border-radius:14px;border:1px solid var(--line);background:#fff;"></div><?php endif;?><div class="question"><?php echo qz_h($q['question']);?></div><div class="qz-dd-instruction"><?php $qzDdRenderedSlots=0;foreach($qzDdParts as$qzDdPart){if($qzDdPart==='___'&&$qzDdRenderedSlots<$qzDdSlotCount){?><div class="qz-dd-slot" data-slot-index="<?php echo$qzDdRenderedSlots;?>"><span class="qz-dd-placeholder">Drop here</span></div><?php $qzDdRenderedSlots++;}elseif($qzDdPart!==''){?><span class="qz-dd-text"><?php echo qz_h($qzDdPart);?></span><?php }}?></div><div id="qz-dd-bank" class="qz-word-bank"><?php foreach($qzDdShuffled as$qzDdWord):?><span class="qz-chip qz-bank-chip" draggable="true" data-word="<?php echo qz_h($qzDdWord);?>"><?php echo qz_h($qzDdWord);?></span><?php endforeach;?></div><div id="qz-dd-inputs"></div><div class="actions"><button type="button" class="btn btn-purple" id="qz-dd-next">Next</button><button class="btn btn-light" type="submit" name="skip" value="1" formnovalidate>Skip</button></div></form><script>(function(){var form=document.getElementById('qz-dd-form');var bank=document.getElementById('qz-dd-bank');var inputs=document.getElementById('qz-dd-inputs');var slots=Array.from(document.querySelectorAll('.qz-dd-slot'));var nextBtn=document.getElementById('qz-dd-next');var listenBtn=document.getElementById('qz-dd-listen');var dragged=null;var submitted=false;var listenText=<?php echo json_encode((string)($q['listen_text']??$q['correct']??''));?>;var listenAudio=<?php echo json_encode((string)($q['audio']??''));?>;var listenPlayer=null;function clearSlot(slot){var chip=slot.querySelector('.qz-built-chip');if(chip){bank.appendChild(createBankChip(chip.dataset.word||chip.textContent.trim()));chip.remove();}slot.classList.remove('qz-dd-filled');slot.innerHTML='<span class="qz-dd-placeholder">Drop here</span>';}function fillSlot(slot,word){clearSlot(slot);var chip=createBuiltChip(word);slot.innerHTML='';slot.appendChild(chip);slot.classList.add('qz-dd-filled');}function createBankChip(word){var chip=document.createElement('span');chip.className='qz-chip qz-bank-chip';chip.draggable=true;chip.dataset.word=word;chip.textContent=word;chip.addEventListener('dragstart',function(e){dragged=chip;chip.dataset.src='bank';if(e.dataTransfer)e.dataTransfer.effectAllowed='move';});chip.addEventListener('click',function(){var empty=slots.find(function(slot){return !slot.classList.contains('qz-dd-filled');});if(!empty)return;fillSlot(empty,word);chip.remove();syncInputs();});return chip;}function createBuiltChip(word){var chip=document.createElement('span');chip.className='qz-chip qz-built-chip';chip.draggable=true;chip.dataset.word=word;chip.textContent=word;chip.addEventListener('dragstart',function(e){dragged=chip;chip.dataset.src='slot';chip.dataset.from=chip.parentElement&&chip.parentElement.dataset.slotIndex?chip.parentElement.dataset.slotIndex:'';if(e.dataTransfer)e.dataTransfer.effectAllowed='move';});chip.addEventListener('click',function(){var slot=chip.parentElement;if(slot)clearSlot(slot);syncInputs();});return chip;}function syncInputs(){inputs.innerHTML='';slots.forEach(function(slot,index){var chip=slot.querySelector('.qz-built-chip');if(!chip)return;var input=document.createElement('input');input.type='hidden';input.name='answer['+index+']';input.value=chip.dataset.word||chip.textContent.trim();input.className='qz-dd-hidden';inputs.appendChild(input);});}slots.forEach(function(slot){slot.addEventListener('dragover',function(e){e.preventDefault();slot.classList.add('qz-drag-over');});slot.addEventListener('dragleave',function(){slot.classList.remove('qz-drag-over');});slot.addEventListener('drop',function(e){e.preventDefault();slot.classList.remove('qz-drag-over');if(!dragged)return;var word=dragged.dataset.word||dragged.textContent.trim();if(dragged.dataset.src==='slot'&&dragged.parentElement===slot){dragged=null;return;}if(dragged.dataset.src==='slot'&&dragged.parentElement)clearSlot(dragged.parentElement);fillSlot(slot,word);if(dragged.dataset.src==='bank')dragged.remove();dragged=null;syncInputs();});slot.addEventListener('click',function(){if(!slot.classList.contains('qz-dd-filled'))return;clearSlot(slot);syncInputs();});});bank.addEventListener('dragover',function(e){e.preventDefault();});bank.addEventListener('drop',function(e){e.preventDefault();if(!dragged||dragged.dataset.src!=='slot')return;if(dragged.parentElement)clearSlot(dragged.parentElement);dragged=null;syncInputs();});Array.from(bank.querySelectorAll('.qz-bank-chip')).forEach(function(chip){chip.addEventListener('dragstart',function(e){dragged=chip;chip.dataset.src='bank';if(e.dataTransfer)e.dataTransfer.effectAllowed='move';});chip.addEventListener('click',function(){var empty=slots.find(function(slot){return !slot.classList.contains('qz-dd-filled');});if(!empty)return;fillSlot(empty,chip.dataset.word||chip.textContent.trim());chip.remove();syncInputs();});});if(listenBtn){listenBtn.addEventListener('click',function(){if(listenPlayer){listenPlayer.pause();listenPlayer.currentTime=0;listenPlayer=null;}try{if(window.speechSynthesis)window.speechSynthesis.cancel();}catch(e){}if(listenAudio){listenPlayer=new Audio(listenAudio);listenPlayer.play().catch(function(){});return;}if(!listenText||!window.speechSynthesis)return;var utter=new SpeechSynthesisUtterance(listenText);utter.lang='en-US';utter.rate=.85;window.speechSynthesis.speak(utter);});setTimeout(function(){listenBtn.click();},150);}nextBtn.addEventListener('click',function(){if(submitted)return;submitted=true;syncInputs();form.submit();});})();</script><?php elseif($q['type']==='unscramble'):?><?php
$qzTokens = $q['options'];
$qzShuffled = $qzTokens;
qz_shuffle($qzShuffled, qz_seed($unitId,$assignment,$att)+$qIndex+991);
?>
<form method="post" id="us-form">
  <input type="hidden" name="answer" id="us-answer" value="">
  <?php if(!empty($q['listen_enabled'])):?><div style="margin-bottom:12px;"><button type="button" class="btn btn-light" id="qz-us-listen">🔊 Listen</button></div><?php endif;?>
  <?php if(!empty($q['image'])):?><div style="margin-bottom:14px;"><img src="<?php echo qz_h($q['image']);?>" alt="Unscramble image" style="width:100%;max-height:220px;object-fit:contain;border-radius:14px;border:1px solid var(--line);background:#fff;"></div><?php endif;?>
  <div class="question"><?php echo qz_h($q['question']);?></div>

  <!-- Build area -->
  <div id="qz-build-area" class="qz-build-area">
    <span class="qz-build-placeholder" id="qz-build-ph">Drag words here to build the sentence…</span>
  </div>

  <!-- Word bank -->
  <div id="qz-word-bank" class="qz-word-bank">
    <?php foreach($qzShuffled as $tok):?>
    <span class="qz-chip qz-bank-chip" draggable="true" data-word="<?php echo qz_h($tok);?>"><?php echo qz_h($tok);?></span>
    <?php endforeach;?>
  </div>

  <div id="qz-us-feedback" class="qz-us-feedback"></div>

  <div class="actions">
    <button type="button" class="btn btn-purple" id="qz-us-next">Next</button>
    <button class="btn btn-light" type="submit" name="skip" value="1" formnovalidate>Skip</button>
  </div>
</form>
<script>(function(){
var correct=<?php echo json_encode(qz_norm($q['correct']));?>;
var form=document.getElementById('us-form');
var ans=document.getElementById('us-answer');
var buildArea=document.getElementById('qz-build-area');
var wordBank=document.getElementById('qz-word-bank');
var feedback=document.getElementById('qz-us-feedback');
var nextBtn=document.getElementById('qz-us-next');
var listenBtn=document.getElementById('qz-us-listen');
var placeholder=document.getElementById('qz-build-ph');
var correctWords=correct.split(' ').filter(Boolean);
var attempts=0;var locked=false;var submitted=false;var dragged=null;
var listenText=<?php echo json_encode((string)$q['correct']);?>;
var listenAudio=<?php echo json_encode((string)($q['audio']??''));?>;
var listenPlayer=null;

function norm(t){return String(t||'').toLowerCase().trim().replace(/\s+/g,' ');}
function getBuilt(){return Array.from(buildArea.querySelectorAll('.qz-built-chip,.qz-correct-chip,.qz-wrong-chip')).map(function(c){return norm(c.dataset.word||c.textContent.trim());});}
function updatePlaceholder(){placeholder.style.display=buildArea.querySelectorAll('.qz-built-chip,.qz-correct-chip,.qz-wrong-chip').length?'none':'inline';}
function speakSentence(){if(!listenText)return;try{if(listenPlayer){listenPlayer.pause();listenPlayer.currentTime=0;listenPlayer=null;}if(window.speechSynthesis)window.speechSynthesis.cancel();}catch(e){} if(listenAudio){listenPlayer=new Audio(listenAudio);listenPlayer.play().catch(function(){});return;} if(!window.speechSynthesis)return;var u=new SpeechSynthesisUtterance(listenText);u.lang='en-US';u.rate=.85;window.speechSynthesis.speak(u);}

function createBankChip(word){
  var c=document.createElement('span');c.className='qz-chip qz-bank-chip';c.draggable=true;c.dataset.word=word;c.textContent=word;
  c.addEventListener('dragstart',function(e){dragged=c;c.dataset.src='bank';if(e.dataTransfer)e.dataTransfer.effectAllowed='move';});
  c.addEventListener('click',function(){if(locked)return;var b=createBuiltChip(word);buildArea.appendChild(b);c.remove();updatePlaceholder();setTimeout(autoCheck,40);});
  return c;
}
function createBuiltChip(word){
  var c=document.createElement('span');c.className='qz-chip qz-built-chip';c.draggable=true;c.dataset.word=word;c.textContent=word;
  c.addEventListener('dragstart',function(e){dragged=c;c.dataset.src='build';if(e.dataTransfer)e.dataTransfer.effectAllowed='move';});
  c.addEventListener('click',function(){if(locked)return;var b=createBankChip(word);wordBank.appendChild(b);c.remove();updatePlaceholder();feedback.textContent='';feedback.className='qz-us-feedback';});
  return c;
}

buildArea.addEventListener('dragover',function(e){e.preventDefault();buildArea.classList.add('qz-drag-over');});
buildArea.addEventListener('dragleave',function(){buildArea.classList.remove('qz-drag-over');});
buildArea.addEventListener('drop',function(e){
  e.preventDefault();buildArea.classList.remove('qz-drag-over');
  if(!dragged||locked)return;
  if(dragged.dataset.src==='bank'){buildArea.appendChild(createBuiltChip(dragged.dataset.word));dragged.remove();}
  else{buildArea.appendChild(dragged);}
  dragged=null;updatePlaceholder();setTimeout(autoCheck,40);
});
wordBank.addEventListener('dragover',function(e){e.preventDefault();});
wordBank.addEventListener('drop',function(e){
  e.preventDefault();
  if(!dragged||locked)return;
  if(dragged.dataset.src==='build'){wordBank.appendChild(createBankChip(dragged.dataset.word));dragged.remove();updatePlaceholder();feedback.textContent='';feedback.className='qz-us-feedback';}
  dragged=null;
});

function checkNow(){
  // feedback removed – results shown in review only
}

function autoCheck(){
  if(locked)return;
  var built=getBuilt();
  if(built.length<correctWords.length)return;
  checkNow();
}

nextBtn.addEventListener('click',function(){
  if(submitted)return;submitted=true;
  var built=getBuilt();ans.value=built.join(' ');
  form.submit();
});

// Init bank chips with events
Array.from(wordBank.querySelectorAll('.qz-bank-chip')).forEach(function(c){
  c.addEventListener('dragstart',function(e){dragged=c;c.dataset.src='bank';if(e.dataTransfer)e.dataTransfer.effectAllowed='move';});
  c.addEventListener('click',function(){if(locked)return;var b=createBuiltChip(c.dataset.word);buildArea.appendChild(b);c.remove();updatePlaceholder();setTimeout(autoCheck,40);});
});
if(listenBtn){listenBtn.addEventListener('click',speakSentence);setTimeout(speakSentence,150);}
})();</script><?php elseif($q['type']==='match'):?><form method="post"><div class="question"><?php echo qz_h($q['question']);?></div><div class="match-grid"><?php $rights=array_column($q['pairs'],'right');foreach($q['pairs']as$i=>$p):?><div class="match-left"><?php echo qz_h($p['left']);?></div><select class="select" name="answer[<?php echo$i;?>]" required><option value="">Choose</option><?php foreach($rights as$r):?><option value="<?php echo qz_h($r);?>"><?php echo qz_h($r);?></option><?php endforeach;?></select><?php endforeach;?></div><div class="actions"><button class="btn btn-purple" type="submit">Next</button><button class="btn btn-light" type="submit" name="skip" value="1" formnovalidate>Skip</button></div></form><?php elseif($q['type']==='fill'||$q['type']==='writing_practice'):?><?php $qzIsFill=$q['type']==='fill';$qzQuestionText=(string)($q['question']??'');$qzHasBlanks=$qzIsFill&&preg_match('/_{3,}/',$qzQuestionText)===1;$qzExpectedAnswers=array_values(array_filter(array_map('trim',preg_split('/\s*[|,]\s*/',(string)($q['correct']??''))),fn($v)=>$v!==''));if(empty($qzExpectedAnswers)&&trim((string)($q['correct']??''))!=='')$qzExpectedAnswers=[trim((string)$q['correct'])];?><form method="post" id="fill-form" data-fill-expected='<?php echo qz_h(json_encode($qzExpectedAnswers,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));?>'><?php if(!empty($q['audio'])):?><div style="margin-bottom:12px;"><button type="button" class="btn btn-light" id="qz-fill-listen">🔊 Listen</button></div><?php endif;?><?php if(!empty($q['image'])):?><div style="margin-bottom:14px;"><img src="<?php echo qz_h($q['image']);?>" alt="Fill question image" style="width:100%;max-height:220px;object-fit:contain;border-radius:14px;border:1px solid var(--line);background:#fff;"></div><?php endif;?><?php if($qzHasBlanks):$qzFbParts=preg_split('/_{3,}/',$qzQuestionText);?><div class="question qz-fill-inline"><?php foreach($qzFbParts as$qzPi=>$qzPart):?><span class="qz-fill-text"><?php echo qz_h($qzPart);?></span><?php if($qzPi<count($qzFbParts)-1):?><input class="qz-fill-blank" data-blank="<?php echo$qzPi;?>" type="text" autocomplete="off" placeholder="..."><?php endif;?><?php endforeach;?></div><input type="hidden" name="answer" id="qz-fill-combined"><?php else:?><div class="question"><?php echo qz_h($q['question']);?></div><?php if($qzIsFill):?><input class="qz-fill-input-lite" name="answer" autocomplete="off" placeholder="Type your answer"><?php elseif(($q['input_mode']??'text')==='textarea'):?><textarea class="input" name="answer" required autocomplete="off" placeholder="Write your answer"></textarea><?php else:?><input class="input" name="answer" required autocomplete="off" placeholder="Type your answer"><?php endif;?><?php endif;?><div class="actions"><?php if(!$qzIsFill):?><button class="btn btn-purple" type="submit">Next</button><?php endif;?><button class="btn btn-light" type="submit" name="skip" value="1" formnovalidate>Skip</button></div></form><?php if(!empty($q['audio'])):?><script>(function(){var b=document.getElementById('qz-fill-listen');if(!b)return;var audioUrl=<?php echo json_encode((string)($q['audio']??''));?>;var fallbackText=<?php echo json_encode((string)$q['question']);?>;var player=null;function stop(){if(player){player.pause();player.currentTime=0;player=null;}try{if(window.speechSynthesis)window.speechSynthesis.cancel();}catch(e){}}function listen(){stop();if(audioUrl){player=new Audio(audioUrl);player.play().catch(function(){});return;}if(!fallbackText||!window.speechSynthesis)return;var utter=new SpeechSynthesisUtterance(fallbackText);utter.lang='en-US';utter.rate=.85;window.speechSynthesis.speak(utter);}b.addEventListener('click',listen);window.addEventListener('beforeunload',stop);})();</script><?php endif;?><?php if($qzIsFill):?><script>(function(){var form=document.getElementById('fill-form');if(!form)return;var expected=<?php echo json_encode($qzExpectedAnswers,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);?>||[];var combined=document.getElementById('qz-fill-combined');var blanks=Array.from(form.querySelectorAll('.qz-fill-blank'));var singleInput=form.querySelector('input[name="answer"]');var submitted=false;function norm(v){return String(v||'').toLowerCase().trim().replace(/\s+/g,' ');}function resize(inp){if(!inp)return;var n=Math.min(24,Math.max(4,(inp.value||'').trim().length+1));inp.style.width=n+'ch';}function values(){if(blanks.length)return blanks.map(function(inp){return String(inp.value||'').trim();});return [singleInput?String(singleInput.value||'').trim():''];}function syncPayload(){if(combined)combined.value=values().join(' | ');}function isCorrect(){var current=values();var count=Math.max(current.length,expected.length);if(!count)return false;for(var i=0;i<count;i++){if(norm(current[i]||'')!==norm(expected[i]||''))return false;}return true;}var spaceToAdvance=expected.length?expected.every(function(v){return norm(v).indexOf(' ')===-1;}):true;function submitNow(){if(submitted)return;submitted=true;syncPayload();form.submit();}function onInput(evt){if(submitted)return;resize(evt&&evt.target?evt.target:null);syncPayload();if(isCorrect())submitNow();}function onKeydown(evt){if(submitted)return;if(evt.key==='Enter'){evt.preventDefault();submitNow();return;}if(evt.key===' '&&spaceToAdvance){evt.preventDefault();submitNow();}}if(blanks.length){blanks.forEach(function(inp,bi){resize(inp);inp.addEventListener('input',onInput);inp.addEventListener('keydown',function(evt){if(submitted)return;var isLast=bi===blanks.length-1;if(evt.key==='Enter'){evt.preventDefault();if(!isLast){blanks[bi+1].focus();blanks[bi+1].select();}else{submitNow();}return;}if(evt.key==='ArrowRight'){var atEnd=inp.selectionStart===inp.value.length&&inp.selectionEnd===inp.value.length;if(atEnd&&!isLast){evt.preventDefault();blanks[bi+1].focus();blanks[bi+1].setSelectionRange(0,0);}return;}if(evt.key===' '&&spaceToAdvance){evt.preventDefault();submitNow();}});});setTimeout(function(){try{blanks[0].focus();}catch(e){}},80);}else if(singleInput){singleInput.addEventListener('input',onInput);singleInput.addEventListener('keydown',onKeydown);setTimeout(function(){try{singleInput.focus();singleInput.select();}catch(e){}},80);}form.addEventListener('submit',syncPayload);})();</script><?php endif;?><?php else:?><form method="post"><div class="question"><?php echo qz_h($q['question']);?></div><input class="input" name="answer" required autocomplete="off" placeholder="Type your answer"><div class="actions"><button class="btn btn-purple" type="submit">Next</button><button class="btn btn-light" type="submit" name="skip" value="1" formnovalidate>Skip</button></div></form><?php endif;?></div>
<?php elseif($mode==='result'):
$quiz_score=isset($quiz_score)?max(0,min(100,(float)$quiz_score)):(float)$percent;
$unit_score=isset($unit_score)?max(0,min(100,(float)$unit_score)):0.0;
$phase_avg=isset($phase_avg)?max(0,min(100,(float)$phase_avg)):0.0;
$phase_name=isset($phase_name)&&$phase_name!==''?(string)$phase_name:'Phase';
$teacher_name=isset($teacher_name)&&$teacher_name!==''?(string)$teacher_name:'Teacher';
$unit_title=isset($unit_title)&&$unit_title!==''?(string)$unit_title:('Unit '.$unitId);
$level_name=isset($level_name)&&$level_name!==''?(string)$level_name:'Level';
$correct_count=isset($correct_count)?(int)$correct_count:(int)$correct;
$total_count=isset($total_count)?(int)$total_count:(int)($score_total??$total);
$elapsed_time=isset($elapsed_time)&&$elapsed_time!==''?(string)$elapsed_time:'--';
$skill_speaking=$skill_speaking??null;
$skill_listening=$skill_listening??null;
$skill_writing=$skill_writing??null;
$skill_reading=$skill_reading??null;
$skill_speaking_acts=is_array($skill_speaking_acts??null)?$skill_speaking_acts:[];
$skill_listening_acts=is_array($skill_listening_acts??null)?$skill_listening_acts:[];
$skill_writing_acts=is_array($skill_writing_acts??null)?$skill_writing_acts:[];
$skill_reading_acts=is_array($skill_reading_acts??null)?$skill_reading_acts:[];
$phase_units=is_array($phase_units??null)?$phase_units:[['label'=>'Unit '.$unitId,'score'=>round($unit_score),'is_current'=>true]];
$unit_final=round(($unit_score*0.6)+($quiz_score*0.4),1);
$render_phase_avg=round($phase_avg);$render_unit_score=round($unit_score);$render_quiz_score=round($quiz_score);$render_unit_final=round($unit_final,1);
$_fu=round($unit_score,1);$_fq=round($quiz_score,1);$_fu_part=round($_fu*0.6,1);$_fq_part=round($_fq*0.4,1);
if($mode==='result'){qz_log_result_score_flow(['student_id'=>$qzStudentId,'assignment_id'=>$assignment,'unit_id'=>$unitId,'db_enabled'=>$qzHasDb,'source'=>['quiz_correct'=>$correct,'quiz_total'=>$score_total??$total,'quiz_percent_calculated'=>(float)$percent,'unit_score_stored'=>(float)$unit_score,'quiz_score_stored'=>(float)$quiz_score,'phase_avg_stored'=>(float)$phase_avg,'phase_units_count'=>is_array($phase_units)?count($phase_units):0],'rendered'=>['phase_teacher_percent'=>(float)$render_phase_avg,'unit_score_percent'=>(float)$render_unit_score,'activities_avg_percent'=>(float)$render_unit_score,'quiz_score_percent'=>(float)$render_quiz_score,'final_score_percent'=>(float)$render_unit_final]]);}
$pass=$unit_final>=60;
if(!function_exists('result_ring')){function result_ring($pct,$color,$label_top,$label_bottom=''){ $pct=max(0,min(100,(float)$pct));$dash=226;$offset=$dash-($dash*$pct/100);$val=round($pct).'%';ob_start(); ?>
  <div style="display:flex;flex-direction:column;align-items:center;gap:6px;">
    <div style="position:relative;width:88px;height:88px;">
      <svg width="88" height="88" viewBox="0 0 88 88" style="transform:rotate(-90deg)">
        <circle cx="44" cy="44" r="36" fill="none" stroke="#F0EEF8" stroke-width="9"/>
        <circle cx="44" cy="44" r="36" fill="none" stroke="<?= $color ?>" stroke-width="9"
          stroke-linecap="round" stroke-dasharray="<?= $dash ?>" stroke-dashoffset="<?= $offset ?>"/>
      </svg>
      <div style="position:absolute;inset:0;display:flex;align-items:center;justify-content:center;font-family:'Fredoka',sans-serif;font-size:22px;font-weight:700;color:<?= $color ?>;">
        <?= $val ?>
      </div>
    </div>
    <div style="font-family:'Nunito',sans-serif;font-weight:800;font-size:12px;color:#9B8FCC;text-transform:uppercase;letter-spacing:.5px;text-align:center;max-width:90px;line-height:1.3;">
      <?= qz_h($label_top) ?><?= $label_bottom ? '<br>'.qz_h($label_bottom) : '' ?>
    </div>
  </div>
  <?php return ob_get_clean(); }}
if(!function_exists('skill_bar')){function skill_bar($label,$icon_color,$bar_color,$subtitle,$score,$acts){if($score===null)return ''; $score=max(0,min(100,(float)$score));$chip_threshold=80;ob_start(); ?>
  <div style="display:flex;flex-direction:column;gap:4px;">
    <div style="display:flex;justify-content:space-between;align-items:flex-start;">
      <div>
        <div style="font-weight:800;font-size:14px;color:#271B5D;"><span style="color:<?= $icon_color ?>">●</span> <?= qz_h($label) ?></div>
        <div style="font-size:11px;color:#9B8FCC;font-weight:700;margin-top:1px;"><?= qz_h($subtitle) ?></div>
      </div>
      <span style="font-family:'Fredoka',sans-serif;font-size:16px;font-weight:600;color:<?= $icon_color ?>"><?= round($score) ?>%</span>
    </div>
    <div style="background:#F0EEF8;border-radius:999px;height:8px;overflow:hidden;"><div style="height:100%;border-radius:999px;background:<?= $bar_color ?>;width:<?= round($score) ?>%;"></div></div>
    <div style="display:flex;flex-wrap:wrap;gap:6px;margin-top:4px;">
      <?php foreach($acts as $a): $name=(string)($a['name']??'');$raw=$a['score']??0;$sc=max(0,min(100,(float)$raw));$done=$sc>=$chip_threshold;$chip_bg=$done?'#F0FDF4':'#FAECE7';$chip_cl=$done?'#166534':'#D85A30';$chip_bd=$done?'#9FE1CB':'#F5C4B3';$icon=$done?'✓':'✗'; ?>
      <span style="background:<?= $chip_bg ?>;border:1px solid <?= $chip_bd ?>;color:<?= $chip_cl ?>;font-size:12px;font-weight:700;border-radius:999px;padding:3px 10px;"><?= $icon ?> <?= qz_h($name) ?> · <?= round($sc) ?>%</span>
      <?php endforeach; ?>
    </div>
  </div>
  <?php return ob_get_clean(); }}
?>
<?php if($qzShowTakeQuizState): ?>
<div style="background:#F8F7FF;padding:24px 16px 40px;font-family:'Nunito',sans-serif;">
  <div style="max-width:760px;margin:0 auto;">
    <div style="background:#fff;border-radius:24px;border:1px solid #EDE9FA;padding:28px;box-shadow:0 4px 32px rgba(127,119,221,.10);text-align:center;">
      <div style="font-family:'Fredoka',sans-serif;color:#F97316;font-size:26px;font-weight:700;margin:0 0 8px;">Take quiz</div>
      <div style="font-size:14px;font-weight:700;color:#7F77DD;margin-bottom:16px;">Complete your first attempt to unlock results.</div>
      <button onclick="window.location.href='<?= qz_h($quizStartHref) ?>'" style="background:#F97316;color:#fff;border:none;font-family:'Nunito',sans-serif;font-weight:900;font-size:14px;padding:11px 22px;border-radius:8px;cursor:pointer;">Take quiz</button>
    </div>
  </div>
</div>
<?php else: ?>
<div style="background:#F8F7FF;padding:24px 16px 40px;font-family:'Nunito',sans-serif;">
  <div style="max-width:760px;margin:0 auto;display:flex;flex-direction:column;gap:16px;">
    <div style="background:#fff;border-radius:24px;border:1px solid #EDE9FA;padding:24px;box-shadow:0 4px 32px rgba(127,119,221,.10);text-align:center;">
      <span style="display:inline-block;background:#FFF0E6;border:1px solid #FCDDBF;color:#C2580A;font-family:'Nunito',sans-serif;font-weight:900;font-size:11px;letter-spacing:1px;text-transform:uppercase;border-radius:999px;padding:4px 14px;margin-bottom:10px;">Quiz result</span>
      <div style="font-family:'Fredoka',sans-serif;color:#F97316;font-size:28px;font-weight:700;margin:0 0 4px;"><?= qz_h($unit_title) ?></div>
      <div style="color:#9B8FCC;font-size:14px;font-weight:700;margin:0 0 20px;"><?= qz_h($phase_name) ?> — <?= qz_h($teacher_name) ?> · <?= qz_h($level_name) ?></div>
      <div style="display:flex;justify-content:center;gap:32px;align-items:center;margin-bottom:16px;flex-wrap:wrap;">
        <?= result_ring($quiz_score,'#F97316','Quiz score') ?>
        <?= result_ring($unit_score,'#7F77DD','Unit score') ?>
        <?= result_ring($phase_avg,'#1D9E75','Total score') ?>
      </div>
      <div style="display:flex;justify-content:center;gap:10px;flex-wrap:wrap;">
        <?php if($pass): ?><span style="background:#DCFCE7;color:#166534;font-family:'Nunito',sans-serif;font-weight:900;font-size:12px;padding:5px 16px;border-radius:999px;">✓ Passed</span><?php else: ?><span style="background:#FEE2E2;color:#991B1B;font-family:'Nunito',sans-serif;font-weight:900;font-size:12px;padding:5px 16px;border-radius:999px;">✗ Not passed</span><?php endif; ?>
        <span style="background:#EDE9FA;color:#7F77DD;font-family:'Nunito',sans-serif;font-weight:900;font-size:12px;padding:5px 16px;border-radius:999px;"><?= $correct_count ?> / <?= $total_count ?> correct</span>
        <span style="background:#EDE9FA;color:#7F77DD;font-family:'Nunito',sans-serif;font-weight:900;font-size:12px;padding:5px 16px;border-radius:999px;">⏱ <?= qz_h($elapsed_time) ?></span>
      </div>
    </div>
    <div style="background:#fff;border-radius:24px;border:1px solid #EDE9FA;padding:24px;box-shadow:0 4px 32px rgba(127,119,221,.10);">
      <div style="font-family:'Fredoka',sans-serif;color:#7F77DD;font-size:18px;font-weight:600;margin:0 0 14px;">◈ Skill breakdown</div>
      <div style="display:flex;flex-direction:column;gap:12px;">
        <?= skill_bar('Speaking','#F97316','#F97316','Pronunciation · Roleplay',$skill_speaking,$skill_speaking_acts) ?>
        <?php if($skill_speaking!==null&&$skill_listening!==null): ?><hr style="border:none;border-top:1px solid #F0EEF8;margin:4px 0"><?php endif; ?>
        <?= skill_bar('Listening','#7F77DD','#7F77DD','Order the sentences · Listen & order · Dictation',$skill_listening,$skill_listening_acts) ?>
        <?php if($skill_listening!==null&&$skill_writing!==null): ?><hr style="border:none;border-top:1px solid #F0EEF8;margin:4px 0"><?php endif; ?>
        <?= skill_bar('Writing','#1D9E75','#1D9E75','Fill in blank · Writing practice',$skill_writing,$skill_writing_acts) ?>
        <?php if($skill_writing!==null&&$skill_reading!==null): ?><hr style="border:none;border-top:1px solid #F0EEF8;margin:4px 0"><?php endif; ?>
        <?= skill_bar('Reading','#378ADD','#378ADD','Match · Matching lines · Multiple choice',$skill_reading,$skill_reading_acts) ?>
      </div>
    </div>
    <div style="background:#fff;border-radius:24px;border:1px solid #EDE9FA;padding:24px;box-shadow:0 4px 32px rgba(127,119,221,.10);">
      <div style="font-family:'Fredoka',sans-serif;color:#7F77DD;font-size:18px;font-weight:600;margin:0 0 14px;">🏆 Unit final score</div>
      <div style="display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;margin-bottom:14px;">
        <div style="background:#F9F8FF;border-radius:16px;border:1px solid #EDE9FA;padding:14px;text-align:center;"><div style="font-family:'Fredoka',sans-serif;font-size:28px;font-weight:700;color:#F97316;line-height:1;"><?= round($unit_final,1) ?>%</div><div style="font-size:11px;font-weight:800;color:#9B8FCC;text-transform:uppercase;letter-spacing:.5px;margin-top:4px;">Total</div></div>
        <div style="background:#F9F8FF;border-radius:16px;border:1px solid #EDE9FA;padding:14px;text-align:center;"><div style="font-family:'Fredoka',sans-serif;font-size:28px;font-weight:700;color:#7F77DD;line-height:1;"><?= round($unit_score) ?>%</div><div style="font-size:11px;font-weight:800;color:#9B8FCC;text-transform:uppercase;letter-spacing:.5px;margin-top:4px;">Activities avg</div></div>
        <div style="background:#F9F8FF;border-radius:16px;border:1px solid #EDE9FA;padding:14px;text-align:center;"><div style="font-family:'Fredoka',sans-serif;font-size:28px;font-weight:700;color:#1D9E75;line-height:1;"><?= round($quiz_score) ?>%</div><div style="font-size:11px;font-weight:800;color:#9B8FCC;text-transform:uppercase;letter-spacing:.5px;margin-top:4px;">Quiz score</div></div>
      </div>
      <div style="background:#F9F8FF;border:1px solid #EDE9FA;border-radius:14px;padding:14px;">
        <div style="font-weight:800;font-size:13px;color:#271B5D;margin-bottom:8px;">Score formula</div>
        <div style="display:flex;flex-direction:column;gap:6px;">
          <div style="display:flex;justify-content:space-between;font-size:13px;font-weight:700;color:#9B8FCC;gap:10px;"><span>Activities avg <span style="background:#EDE9FA;color:#7F77DD;border-radius:999px;padding:1px 8px;font-size:11px;">60%</span></span><span><?= $_fu ?>% × 0.6 = <b style="color:#271B5D"><?= $_fu_part ?></b></span></div>
          <div style="display:flex;justify-content:space-between;font-size:13px;font-weight:700;color:#9B8FCC;gap:10px;"><span>Quiz score <span style="background:#FFF0E6;color:#C2580A;border-radius:999px;padding:1px 8px;font-size:11px;">40%</span></span><span><?= $_fq ?>% × 0.4 = <b style="color:#271B5D"><?= $_fq_part ?></b></span></div>
          <hr style="border:none;border-top:1px solid #F0EEF8;margin:4px 0">
          <div style="display:flex;justify-content:space-between;font-size:15px;font-weight:900;"><span style="color:#271B5D;">Unit final</span><span style="font-family:'Fredoka',sans-serif;color:#F97316;font-size:22px;"><?= round($unit_final,1) ?>%</span></div>
        </div>
      </div>
    </div>
<?php if(!empty($qzAllAttempts)): $qzBestPct=max(array_column($qzAllAttempts,'score_percent')); ?>
    <div style="background:#fff;border-radius:24px;border:1px solid #EDE9FA;padding:24px;box-shadow:0 4px 32px rgba(127,119,221,.10);">
      <div style="font-family:'Fredoka',sans-serif;color:#7F77DD;font-size:18px;font-weight:600;margin:0 0 14px;">📋 Attempt history</div>
      <div style="display:flex;flex-direction:column;gap:8px;">
        <?php foreach($qzAllAttempts as$qzAtt):$qzAttPct=(int)($qzAtt['score_percent']??0);$qzAttCor=(int)($qzAtt['correct_count']??0);$qzAttTot=(int)($qzAtt['total_count']??0);$qzAttDate=isset($qzAtt['completed_at'])&&$qzAtt['completed_at']?date('M j, Y',strtotime((string)$qzAtt['completed_at'])):'—';$qzIsBest=$qzAttPct===$qzBestPct; ?>
        <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;background:#F9F8FF;border-radius:14px;padding:12px 16px;border:1px solid <?= $qzIsBest?'#FCDDBF':'#EDE9FA' ?>;">
          <div style="display:flex;align-items:center;gap:10px;">
            <span style="background:#EDE9FA;color:#7F77DD;font-family:'Nunito',sans-serif;font-weight:900;font-size:12px;padding:4px 11px;border-radius:999px;">Attempt <?= (int)$qzAtt['attempt_number'] ?></span>
            <span style="font-size:12px;color:#9B8FCC;font-weight:700;"><?= qz_h($qzAttDate) ?></span>
          </div>
          <div style="display:flex;align-items:center;gap:8px;">
            <?php if($qzIsBest): ?><span style="background:#FFF0E6;color:#C2580A;font-size:11px;font-weight:900;padding:3px 10px;border-radius:999px;">⭐ Best</span><?php endif; ?>
            <span style="font-size:12px;color:#9B8FCC;font-weight:700;"><?= $qzAttCor ?>/<?= $qzAttTot ?> correct</span>
            <span style="font-family:'Fredoka',sans-serif;font-size:20px;font-weight:700;color:<?= $qzAttPct>=60?'#1D9E75':'#E03A3A' ?>;"><?= $qzAttPct ?>%</span>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
<?php endif; ?>
    <div style="background:#fff;border-radius:24px;border:1px solid #EDE9FA;padding:24px;box-shadow:0 4px 32px rgba(127,119,221,.10);">
      <div style="font-family:'Fredoka',sans-serif;color:#7F77DD;font-size:18px;font-weight:600;margin:0 0 14px;">◧ Phase progress — <?= qz_h($level_name) ?></div>
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;gap:10px;"><span style="font-weight:800;font-size:13px;color:#271B5D;"><?= qz_h($phase_name) ?> — <?= qz_h($teacher_name) ?> · <?= count($phase_units) ?> units</span><span style="font-family:'Fredoka',sans-serif;font-size:16px;color:#7F77DD;font-weight:600;"><?= round($phase_avg) ?>% avg</span></div>
      <div style="background:#F0EEF8;border-radius:999px;height:12px;overflow:hidden;"><div style="height:100%;border-radius:999px;background:linear-gradient(90deg,#F97316,#7F77DD);width:<?= round($phase_avg) ?>%;"></div></div>
      <div style="display:flex;gap:8px;margin-top:16px;flex-wrap:wrap;"><?php foreach($phase_units as$u):$isCurrent=!empty($u['is_current']);$scoreVal=array_key_exists('score',$u)?$u['score']:null;if($isCurrent){$dc='background:#FFF0E6;border-color:#FCDDBF;color:#C2580A;';$val=($scoreVal!==null?round((float)$scoreVal).'%' :'—').' ★';}elseif($scoreVal!==null){$dc='background:#DCFCE7;border-color:#9FE1CB;color:#166534;';$val=round((float)$scoreVal).'%';}else{$dc='background:#F0EEF8;border-color:#EDE9FA;color:#9B8FCC;';$val='—';}?><div style="display:flex;flex-direction:column;align-items:center;gap:3px;"><div style="width:36px;height:36px;border-radius:50%;border:2px solid;<?= $dc ?>display:flex;align-items:center;justify-content:center;font-size:10px;font-weight:800;"><?= qz_h($val) ?></div><div style="font-size:10px;color:#9B8FCC;font-weight:700;"><?= qz_h((string)($u['label']??'')) ?></div></div><?php endforeach; ?></div>
      <?php $completed=count(array_filter($phase_units,fn($u)=>array_key_exists('score',$u)&&$u['score']!==null));$total_u=count($phase_units); ?>
      <div style="margin-top:14px;background:#F0FDF4;border:1px solid #9FE1CB;border-radius:12px;padding:12px;font-size:13px;font-weight:700;color:#166534;">✓ <?= $completed ?> / <?= $total_u ?> units completed</div>
    </div>
    <div style="display:flex;gap:10px;">
      <button onclick="window.location.href='<?= qz_h($reviewHref) ?>'" style="flex:1;background:transparent;color:#7F77DD;border:1.5px solid #EDE9FA;font-family:'Nunito',sans-serif;font-weight:900;font-size:14px;padding:11px 22px;border-radius:8px;cursor:pointer;">Review answers</button>
      <button onclick="window.location.href='../../academic/student_dashboard.php'" style="flex:1;background:#F97316;color:#fff;border:none;font-family:'Nunito',sans-serif;font-weight:900;font-size:14px;padding:11px 22px;border-radius:8px;cursor:pointer;">Back to dashboard →</button>
    </div>
  </div>
</div>
<?php endif; ?>
<?php elseif($mode==='review'):
// TODO: confirm variable name in this file
$attempt1_score = $attempt1_score ?? null;
$attempt2_score = $attempt2_score ?? null;
$attempt_number = isset($attempt_number) ? (int)$attempt_number : (int)($att ?? 1);
$max_attempts   = $max_attempts ?? 2;
$correct_count  = isset($correct_count) ? (int)$correct_count : (int)$correct;
$total_count    = isset($total_count) ? (int)$total_count : (int)($score_total ?? $total);
if($qzShowTakeQuizState): ?>
<div style="background:#F8F7FF;padding:24px 16px 40px;font-family:'Nunito',sans-serif;">
  <div style="max-width:760px;margin:0 auto;">
    <div style="background:#fff;border-radius:24px;border:1px solid #EDE9FA;padding:28px;box-shadow:0 4px 32px rgba(127,119,221,.10);text-align:center;">
      <div style="font-family:'Fredoka',sans-serif;color:#F97316;font-size:26px;font-weight:700;margin:0 0 8px;">Take quiz</div>
      <div style="font-size:14px;font-weight:700;color:#7F77DD;margin-bottom:16px;">Take quiz to unlock review.</div>
      <button onclick="window.location.href='<?= qz_h($quizStartHref) ?>'" style="background:#F97316;color:#fff;border:none;font-family:'Nunito',sans-serif;font-weight:900;font-size:14px;padding:11px 22px;border-radius:8px;cursor:pointer;">Take quiz</button>
    </div>
  </div>
</div>
<script>setTimeout(function(){window.location.href=<?php echo json_encode($quizStartHref); ?>;},1600);</script>
<?php else:
// Build display-only $questions array from existing $quiz and $answers — NOT score logic
$_qz_skill_map = [
  'pronunciation'    => 'speaking',
  'dictation'        => 'listening',
  'listen_order'     => 'listening',
  'fill'             => 'writing',
  'writing_practice' => 'writing',
  'question_answer'  => 'writing',
  'multiple_choice'  => 'reading',
  'match'            => 'reading',
  'matching_lines'   => 'reading',
  'drag_drop'        => 'reading',
  'unscramble'       => 'reading',
];
$questions = [];
foreach ($quiz as $_qi => $_qq) {
  $_qtype = (string)($_qq['type'] ?? '');
  $_answerRaw = $answers[$_qi]['answer'] ?? null;
  $questions[] = [
    'id'      => $_qq['id'] ?? $_qi,
    'number'  => $_qi + 1,
    'text'    => (string)($_qq['question'] ?? ''),
    'skill'   => $_qz_skill_map[$_qtype] ?? 'reading',
    'correct' => !empty($answers[$_qi]['correct']),
    'answer_text' => qz_review_answer_text($_qq,$_answerRaw),
    'correct_text' => qz_review_correct_text($_qq),
  ];
}
unset($_qi, $_qq, $_qtype, $_qz_skill_map, $_answerRaw);
if (!function_exists('rw_skill_meta')) {
  function rw_skill_meta($skill) {
    $map = [
      'speaking'  => ['label'=>'Speaking',  'bg'=>'#FFF0E6', 'color'=>'#C2580A'],
      'listening' => ['label'=>'Listening', 'bg'=>'#EDE9FA', 'color'=>'#534AB7'],
      'writing'   => ['label'=>'Writing',   'bg'=>'#EAFAF3', 'color'=>'#0F6E56'],
      'reading'   => ['label'=>'Reading',   'bg'=>'#E6F1FB', 'color'=>'#185FA5'],
    ];
    return $map[$skill] ?? ['label'=>ucfirst($skill), 'bg'=>'#F0EEF8', 'color'=>'#7F77DD'];
  }
}
if (!function_exists('rw_skill_tip')) {
  function rw_skill_tip($skill, $error_count) {
    $tips = [
      'speaking'  => 'Practice the <b>Pronunciation</b> and <b>Roleplay</b> activities again. Record yourself and compare with the model voice.',
      'listening' => 'Review the <b>Listen &amp; Order</b> and <b>Order the Sentences</b> activities. Listen carefully before answering.',
      'writing'   => 'Revisit the <b>Fill in Blank</b> and <b>Dictation</b> activities. Focus on spelling and word order.',
      'reading'   => 'Go back to the <b>Match</b>, <b>Matching Lines</b>, and <b>Multiple Choice</b> activities in this unit.',
    ];
    $icons = [
      'speaking'  => '🎤',
      'listening' => '🎧',
      'writing'   => '✏️',
      'reading'   => '📖',
    ];
    $label_map = [
      'speaking' => 'Speaking', 'listening' => 'Listening',
      'writing'  => 'Writing',  'reading'   => 'Reading',
    ];
    $tip_colors = [
      'speaking'  => ['bg'=>'#FFF7F0', 'border'=>'#FCDDBF', 'icon_bg'=>'#FFF0E6', 'label'=>'#C2580A', 'text'=>'#6B6B8D'],
      'listening' => ['bg'=>'#F5F3FF', 'border'=>'#EDE9FA', 'icon_bg'=>'#EDE9FA', 'label'=>'#534AB7', 'text'=>'#6B6B8D'],
      'writing'   => ['bg'=>'#F0FDF4', 'border'=>'#9FE1CB', 'icon_bg'=>'#DCFCE7', 'label'=>'#0F6E56', 'text'=>'#6B6B8D'],
      'reading'   => ['bg'=>'#E6F1FB', 'border'=>'#B5D4F4', 'icon_bg'=>'#DBEAFE', 'label'=>'#185FA5', 'text'=>'#6B6B8D'],
    ];
    $c = $tip_colors[$skill] ?? $tip_colors['reading'];
    $icon = $icons[$skill] ?? '💡';
    $label = ($label_map[$skill] ?? ucfirst($skill)) . ' — ' . $error_count . ' error' . ($error_count !== 1 ? 's' : '');
    $text = $tips[$skill] ?? 'Review the activities for this skill.';
    ob_start(); ?>
  <div style="display:flex;align-items:flex-start;gap:12px;padding:14px;border-radius:14px;
    background:<?= $c['bg'] ?>;border:1px solid <?= $c['border'] ?>;">
    <div style="width:34px;height:34px;border-radius:10px;background:<?= $c['icon_bg'] ?>;
      display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:18px;">
      <?= $icon ?>
    </div>
    <div>
      <div style="font-weight:900;font-size:13px;color:<?= $c['label'] ?>;margin:0 0 3px;"><?= $label ?></div>
      <div style="font-size:12px;font-weight:700;color:<?= $c['text'] ?>;margin:0;line-height:1.5;"><?= $text ?></div>
    </div>
  </div>
    <?php return ob_get_clean();
  }
}
// Count errors per skill — READ $questions array only, no score logic
$errors_by_skill = [];
$error_total     = 0;
foreach ($questions as $q) {
  if (!$q['correct']) {
    $error_total++;
    $sk = $q['skill'] ?? 'reading';
    $errors_by_skill[$sk] = ($errors_by_skill[$sk] ?? 0) + 1;
  }
}
arsort($errors_by_skill);
$show_only_errors = $attempt_number >= 2 && $attempt2_score !== null;
$review_questions = $show_only_errors ? array_values(array_filter($questions, fn($q)=>empty($q['correct']))) : $questions;
?>
<div style="background:#F8F7FF;padding:24px 16px 40px;font-family:'Nunito',sans-serif;">
<div style="max-width:760px;margin:0 auto;display:flex;flex-direction:column;gap:16px;">

  <!-- CARD 1: Header + attempt tabs + score summary -->
  <div style="background:#fff;border-radius:24px;border:1px solid #EDE9FA;padding:24px;box-shadow:0 4px 32px rgba(127,119,221,.10);">
    <span style="display:inline-block;background:#FFF0E6;border:1px solid #FCDDBF;color:#C2580A;
      font-family:'Nunito',sans-serif;font-weight:900;font-size:11px;letter-spacing:1px;
      text-transform:uppercase;border-radius:999px;padding:4px 14px;margin-bottom:10px;">
      Review
    </span>
    <div style="font-family:'Fredoka',sans-serif;color:#F97316;font-size:24px;font-weight:700;margin:0 0 4px;">
      <?= htmlspecialchars($unit_title) ?>
    </div>
    <div style="color:#9B8FCC;font-size:13px;font-weight:700;margin:0 0 16px;">
      <?= htmlspecialchars($phase_name) ?> — <?= htmlspecialchars($teacher_name) ?> · <?= htmlspecialchars($level_name) ?>
    </div>
    <!-- Attempt tabs -->
    <div style="display:flex;gap:8px;margin-bottom:16px;">
      <?php for ($i = 1; $i <= $max_attempts; $i++):
        $a_score = ($i === 1) ? $attempt1_score : $attempt2_score;
        if ($i === 2 && !$qzCanRetry && $attempt2_score === null) {
          $tab_bg = '#F5F3FF'; $tab_bd = '#EDE9FA'; $tab_cl = '#9B8FCC';
          $tab_label = 'Attempt 2 &nbsp;·&nbsp; Locked';
        } elseif ($i === $attempt_number && $a_score === null) {
          $tab_bg = '#FFF0E6'; $tab_bd = '#FCDDBF'; $tab_cl = '#C2580A';
          $tab_label = 'Attempt ' . $i . ' &nbsp;·&nbsp; In progress';
        } elseif ($i === $attempt_number && $a_score !== null) {
          $tab_bg = '#FFF0E6'; $tab_bd = '#FCDDBF'; $tab_cl = '#C2580A';
          $tab_label = 'Attempt ' . $i . ' &nbsp;·&nbsp; ' . round($a_score) . '%';
        } elseif ($a_score !== null) {
          $tab_bg = '#F0FDF4'; $tab_bd = '#9FE1CB'; $tab_cl = '#166534';
          $tab_label = 'Attempt ' . $i . ' &nbsp;·&nbsp; ' . round($a_score) . '%';
        } else {
          $tab_bg = '#F9F8FF'; $tab_bd = '#EDE9FA'; $tab_cl = '#9B8FCC';
          $tab_label = 'Attempt ' . $i . ' &nbsp;·&nbsp; Pending';
        }
      ?>
      <div style="flex:1;padding:9px;border-radius:10px;border:1.5px solid <?= $tab_bd ?>;
        font-family:'Nunito',sans-serif;font-weight:800;font-size:13px;text-align:center;
        color:<?= $tab_cl ?>;background:<?= $tab_bg ?>;">
        <?= $tab_label ?>
      </div>
      <?php endfor; ?>
    </div>
    <!-- Score summary chips -->
    <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:10px;">
      <div style="background:#F9F8FF;border-radius:14px;border:1px solid #EDE9FA;padding:12px;text-align:center;">
        <div style="font-family:'Fredoka',sans-serif;font-size:24px;font-weight:700;color:#F97316;line-height:1;">
          <?= round($quiz_score) ?>%
        </div>
        <div style="font-size:11px;font-weight:800;color:#9B8FCC;text-transform:uppercase;letter-spacing:.5px;margin-top:3px;">Score</div>
      </div>
      <div style="background:#F9F8FF;border-radius:14px;border:1px solid #EDE9FA;padding:12px;text-align:center;">
        <div style="font-family:'Fredoka',sans-serif;font-size:24px;font-weight:700;color:#1D9E75;line-height:1;">
          <?= $correct_count ?>
        </div>
        <div style="font-size:11px;font-weight:800;color:#9B8FCC;text-transform:uppercase;letter-spacing:.5px;margin-top:3px;">Correct</div>
      </div>
      <div style="background:#F9F8FF;border-radius:14px;border:1px solid #EDE9FA;padding:12px;text-align:center;">
        <div style="font-family:'Fredoka',sans-serif;font-size:24px;font-weight:700;color:#D85A30;line-height:1;">
          <?= $error_total ?>
        </div>
        <div style="font-size:11px;font-weight:800;color:#9B8FCC;text-transform:uppercase;letter-spacing:.5px;margin-top:3px;">Errors</div>
      </div>
      <div style="background:#F9F8FF;border-radius:14px;border:1px solid #EDE9FA;padding:12px;text-align:center;">
        <div style="font-family:'Fredoka',sans-serif;font-size:24px;font-weight:700;color:#9B8FCC;line-height:1;">
          <?= $total_count ?>
        </div>
        <div style="font-size:11px;font-weight:800;color:#9B8FCC;text-transform:uppercase;letter-spacing:.5px;margin-top:3px;">Total</div>
      </div>
    </div>
  </div>

  <!-- Retry banner — only when attempt 1 done and attempt 2 not yet taken -->
  <?php if ($attempt_number === 1 && $attempt2_score === null && $qzCanRetry): ?>
  <div style="background:linear-gradient(135deg,#FFF0E6,#EDE9FA);border-radius:16px;border:1px solid #FCDDBF;
    padding:16px 18px;display:flex;align-items:center;gap:14px;flex-wrap:wrap;">
    <div style="width:44px;height:44px;background:#F97316;border-radius:12px;
      display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:22px;">🔁</div>
    <div style="flex:1;min-width:160px;">
      <div style="font-family:'Fredoka',sans-serif;color:#F97316;font-size:17px;font-weight:700;margin:0 0 2px;">
        You have 1 more attempt
      </div>
      <div style="font-size:12px;font-weight:700;color:#9B8FCC;margin:0;">
        Review your errors below, then retake to improve your score.
      </div>
    </div>
    <button onclick="window.location.href='<?= qz_h($retakeHref) ?>'"
      style="background:#F97316;color:#fff;border:none;font-family:'Nunito',sans-serif;
      font-weight:900;font-size:13px;padding:10px 20px;border-radius:8px;cursor:pointer;white-space:nowrap;">
      ✎ Retake quiz
    </button>
  </div>
  <?php endif; ?>
  <?php if ($attempt_number === 1 && $attempt2_score === null && !$qzCanRetry): ?>
  <?php $qzCorrectionRows=array_values(array_filter($questions,fn($q)=>empty($q['correct']))); ?>
  <div style="background:#fff;border-radius:24px;border:1px solid #EDE9FA;padding:24px;box-shadow:0 4px 32px rgba(127,119,221,.10);">
    <div style="font-family:'Fredoka',sans-serif;color:#7F77DD;font-size:17px;font-weight:600;margin:0 0 4px;">✅ Results unlocked</div>
    <div style="font-size:12px;font-weight:700;color:#9B8FCC;margin:0 0 14px;">Your score reached 65%+; review your errors and correct answers below.</div>
    <?php if(empty($qzCorrectionRows)): ?>
      <div style="background:#F0FDF4;border:1px solid #9FE1CB;border-radius:12px;padding:10px 14px;font-size:13px;font-weight:800;color:#166534;">✓ Perfect score — no errors to review.</div>
    <?php else: ?>
      <div style="display:flex;flex-direction:column;gap:10px;">
        <?php foreach($qzCorrectionRows as $qzRow): ?>
          <div style="border:1px solid #EDE9FA;border-radius:12px;padding:12px;background:#F9F8FF;">
            <div style="font-size:13px;font-weight:900;color:#271B5D;margin-bottom:6px;">Q<?= (int)($qzRow['number']??0) ?>. <?= htmlspecialchars((string)($qzRow['text']??'')) ?></div>
            <div style="font-size:12px;font-weight:700;color:#9B8FCC;">Your answer: <span style="color:#D85A30;"><?= htmlspecialchars((string)($qzRow['answer_text']??'—')) ?></span></div>
            <div style="font-size:12px;font-weight:700;color:#9B8FCC;">Correct answer: <span style="color:#166534;"><?= htmlspecialchars((string)($qzRow['correct_text']??'—')) ?></span></div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <?php if (!function_exists('rw_skill_config')) {
    function rw_skill_config($skill) {
      $cfg = [
        'speaking'  => [
          'label'    => 'Speaking',
          'icon'     => '🎤',
          'acts'     => 'Pronunciation · Roleplay',
          'hdr_bg'   => '#FFF7F0',
          'border'   => '#FCDDBF',
          'badge_bg' => '#FFF0E6',
          'badge_cl' => '#C2580A',
          'name_cl'  => '#C2580A',
          'icon_bg'  => '#FFF0E6',
        ],
        'listening' => [
          'label'    => 'Listening',
          'icon'     => '🎧',
          'acts'     => 'Listen &amp; Order · Order the Sentences · Dictation',
          'hdr_bg'   => '#F5F3FF',
          'border'   => '#C4BDFF',
          'badge_bg' => '#EDE9FA',
          'badge_cl' => '#534AB7',
          'name_cl'  => '#534AB7',
          'icon_bg'  => '#EDE9FA',
        ],
        'writing'   => [
          'label'    => 'Writing',
          'icon'     => '✏️',
          'acts'     => 'Fill in Blank · Writing Practice',
          'hdr_bg'   => '#F0FDF4',
          'border'   => '#9FE1CB',
          'badge_bg' => '#EAFAF3',
          'badge_cl' => '#0F6E56',
          'name_cl'  => '#0F6E56',
          'icon_bg'  => '#DCFCE7',
        ],
        'reading'   => [
          'label'    => 'Reading',
          'icon'     => '📖',
          'acts'     => 'Match · Matching Lines · Multiple Choice',
          'hdr_bg'   => '#F9F8FF',
          'border'   => '#EDE9FA',
          'badge_bg' => '#F0EEF8',
          'badge_cl' => '#9B8FCC',
          'name_cl'  => '#9B8FCC',
          'icon_bg'  => '#F0EEF8',
        ],
      ];
      return $cfg[$skill] ?? $cfg['reading'];
    }
  } ?>

  <?php
  // Display-only counters — NOT score logic
  $rw_prev_errors_by_skill = $errors_by_skill;
  $rw_prev_error_total = $error_total;
  $errors_by_skill = ['speaking'=>[], 'listening'=>[], 'writing'=>[], 'reading'=>[]];
  $error_total = 0;
  foreach ($questions as $idx => $q) {
    if (!$q['correct']) {
      $error_total++;
      $sk = $q['skill'] ?? 'reading';
      if (!isset($errors_by_skill[$sk])) $errors_by_skill[$sk] = [];
      $errors_by_skill[$sk][] = [
        'number' => $q['number'] ?? ($idx + 1),
        'text'   => $q['text'],
      ];
    }
  }
  $skill_order = ['listening', 'speaking', 'writing', 'reading'];
  ?>

  <div style="background:#fff;border-radius:24px;border:1px solid #EDE9FA;padding:24px;
    box-shadow:0 4px 32px rgba(127,119,221,.10);">

    <div style="font-family:'Fredoka',sans-serif;color:#7F77DD;font-size:17px;font-weight:600;
      margin:0 0 4px;">✗ Errors by skill</div>

    <?php if ($qzCanRetry): ?>
      <div style="font-size:12px;font-weight:700;color:#9B8FCC;margin:0 0 14px;">
        Click a skill to see which questions to review
      </div>
    <?php else: ?>
      <div style="font-size:12px;font-weight:700;color:#9B8FCC;margin:0 0 14px;">
        Review your errors before your final attempt
      </div>
    <?php endif; ?>

    <!-- Error summary -->
    <?php if ($error_total > 0): ?>
    <div style="background:#FAECE7;border:1px solid #F5C4B3;border-radius:12px;padding:10px 14px;
      margin-bottom:14px;font-size:13px;font-weight:800;color:#D85A30;display:flex;align-items:center;gap:8px;">
      ✗ <?= $error_total ?> error<?= $error_total !== 1 ? 's' : '' ?> out of <?= $total_count ?> questions
    </div>
    <?php else: ?>
    <div style="background:#F0FDF4;border:1px solid #9FE1CB;border-radius:12px;padding:10px 14px;
      margin-bottom:14px;font-size:13px;font-weight:800;color:#166534;display:flex;align-items:center;gap:8px;">
      ✓ Perfect score — no errors!
    </div>
    <?php endif; ?>

    <?php if ($attempt_number >= 2 || !$qzCanRetry): ?>
    <!-- ATTEMPT 2: Accordion unlocked -->
    <div style="display:flex;flex-direction:column;gap:8px;" id="rw-acc-list">
      <?php foreach ($skill_order as $skill):
        $cfg    = rw_skill_config($skill);
        $errs   = $errors_by_skill[$skill] ?? [];
        $ecount = count($errs);
        $disabled = $ecount === 0;
        $acc_id = 'rw-acc-' . $skill;
      ?>
      <div style="border-radius:16px;border:2px solid <?= $cfg['border'] ?>;overflow:hidden;
        <?= $disabled ? 'opacity:.45;pointer-events:none;' : '' ?>"
        id="<?= $acc_id ?>">

        <!-- Header -->
        <div onclick="rwToggle('<?= $skill ?>')"
          style="display:flex;align-items:center;gap:12px;padding:13px 16px;
          background:<?= $cfg['hdr_bg'] ?>;cursor:<?= $disabled ? 'default' : 'pointer' ?>;">
          <div style="width:36px;height:36px;border-radius:10px;background:<?= $cfg['icon_bg'] ?>;
            display:flex;align-items:center;justify-content:center;font-size:18px;flex-shrink:0;">
            <?= $cfg['icon'] ?>
          </div>
          <div style="flex:1;">
            <div style="font-family:'Fredoka',sans-serif;font-size:16px;font-weight:600;color:<?= $cfg['name_cl'] ?>;">
              <?= $cfg['label'] ?>
            </div>
            <div style="font-size:11px;font-weight:700;color:#9B8FCC;margin-top:1px;"><?= $cfg['acts'] ?></div>
          </div>
          <span style="font-family:'Nunito',sans-serif;font-weight:900;font-size:12px;border-radius:999px;
            padding:3px 11px;background:<?= $cfg['badge_bg'] ?>;color:<?= $cfg['badge_cl'] ?>;
            border:1px solid <?= $cfg['border'] ?>;">
            <?= $ecount ?> error<?= $ecount !== 1 ? 's' : '' ?>
          </span>
          <?php if (!$disabled): ?>
          <span id="rw-chev-<?= $skill ?>"
            style="font-size:16px;color:#9B8FCC;transition:transform .25s;flex-shrink:0;">▾</span>
          <?php endif; ?>
        </div>

        <!-- Body -->
        <?php if (!$disabled): ?>
        <div id="rw-body-<?= $skill ?>"
          style="max-height:0;overflow:hidden;transition:max-height .3s ease;">
          <div style="display:flex;flex-direction:column;gap:7px;padding:0 14px 14px;">
            <?php foreach ($errs as $e): ?>
            <div style="display:flex;align-items:center;gap:11px;padding:10px 13px;border-radius:12px;
              background:#FAECE7;border:1px solid #F5C4B3;">
              <div style="width:26px;height:26px;border-radius:50%;background:#FEE2E2;color:#991B1B;
                display:flex;align-items:center;justify-content:center;font-family:'Fredoka',sans-serif;
                font-size:13px;font-weight:700;flex-shrink:0;">
                <?= $e['number'] ?>
              </div>
              <div style="flex:1;font-size:13px;font-weight:700;color:#271B5D;">
                <?= htmlspecialchars($e['text']) ?>
              </div>
              <span style="font-size:15px;color:#D85A30;font-weight:900;">✗</span>
            </div>
            <?php endforeach; ?>
          </div>
        </div>
        <?php endif; ?>

      </div>
      <?php endforeach; ?>
    </div>

    <?php else: ?>
    <!-- ATTEMPT 1: Locked state -->
    <div style="display:flex;flex-direction:column;gap:8px;">
      <?php foreach ($skill_order as $skill):
        $cfg = rw_skill_config($skill);
      ?>
      <div style="border-radius:16px;border:2px solid <?= $cfg['border'] ?>;overflow:hidden;opacity:.45;">
        <div style="display:flex;align-items:center;gap:12px;padding:13px 16px;background:<?= $cfg['hdr_bg'] ?>;">
          <div style="width:36px;height:36px;border-radius:10px;background:<?= $cfg['icon_bg'] ?>;
            display:flex;align-items:center;justify-content:center;font-size:18px;flex-shrink:0;">
            <?= $cfg['icon'] ?>
          </div>
          <div style="flex:1;">
            <div style="font-family:'Fredoka',sans-serif;font-size:16px;font-weight:600;color:<?= $cfg['name_cl'] ?>;">
              <?= $cfg['label'] ?>
            </div>
            <div style="font-size:11px;font-weight:700;color:#9B8FCC;margin-top:1px;">—</div>
          </div>
          <span style="font-family:'Nunito',sans-serif;font-weight:900;font-size:12px;border-radius:999px;
            padding:3px 11px;background:<?= $cfg['badge_bg'] ?>;color:<?= $cfg['badge_cl'] ?>;
            border:1px solid <?= $cfg['border'] ?>;">
            ? errors
          </span>
        </div>
      </div>
      <?php endforeach; ?>

      <div style="background:#F5F3FF;border:1px solid #EDE9FA;border-radius:14px;padding:20px;
        text-align:center;margin-top:4px;">
        <div style="font-size:26px;margin-bottom:6px;">🔒</div>
        <?php if($qzCanRetry): ?>
          <div style="font-family:'Fredoka',sans-serif;font-size:14px;color:#7F77DD;font-weight:600;">
            Skill breakdown unlocks on Attempt 2
          </div>
          <div style="font-size:12px;font-weight:700;color:#9B8FCC;margin-top:4px;">
            Retake the quiz to see your errors by skill
          </div>
        <?php else: ?>
          <div style="font-family:'Fredoka',sans-serif;font-size:14px;color:#166534;font-weight:600;">
            Results unlocked
          </div>
          <div style="font-size:12px;font-weight:700;color:#166534;margin-top:4px;">
            Retake disabled because your score reached 65% or higher
          </div>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>

  </div>
  <?php
  $errors_by_skill = $rw_prev_errors_by_skill;
  $error_total = $rw_prev_error_total;
  unset($rw_prev_errors_by_skill, $rw_prev_error_total, $skill_order);
  ?>

  <script>
  // Accordion toggle — add once, check if rwToggle already defined
  if (typeof rwToggle === 'undefined') {
    function rwToggle(skill) {
      var body  = document.getElementById('rw-body-' + skill);
      var chev  = document.getElementById('rw-chev-' + skill);
      if (!body) return;
      var isOpen = body.style.maxHeight && body.style.maxHeight !== '0px';
      if (isOpen) {
        body.style.maxHeight = '0px';
        if (chev) chev.style.transform = 'rotate(0deg)';
      } else {
        body.style.maxHeight = body.scrollHeight + 'px';
        if (chev) chev.style.transform = 'rotate(180deg)';
      }
    }
  }
  </script>

  <!-- CARD 3: Study tips by skill (only skills with errors) -->
  <?php if (!empty($errors_by_skill)): ?>
  <div style="background:#fff;border-radius:24px;border:1px solid #EDE9FA;padding:24px;box-shadow:0 4px 32px rgba(127,119,221,.10);">
    <div style="font-family:'Fredoka',sans-serif;color:#7F77DD;font-size:17px;font-weight:600;margin:0 0 4px;display:flex;align-items:center;gap:8px;">
      💡 Study tips — focus on these skills
    </div>
    <div style="font-size:12px;font-weight:700;color:#9B8FCC;margin:0 0 14px;">
      Based on your errors in this attempt
    </div>
    <div style="display:flex;flex-direction:column;gap:10px;">
      <?php foreach ($errors_by_skill as $skill => $ecount): ?>
        <?= rw_skill_tip($skill, $ecount) ?>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <!-- Final attempt notice -->
  <?php if ($attempt_number >= $max_attempts): ?>
  <div style="background:#F5F3FF;border:1px solid #EDE9FA;border-radius:12px;padding:11px 14px;
    font-size:12px;font-weight:800;color:#7F77DD;display:flex;align-items:center;gap:8px;">
    ℹ This was your 2nd and final attempt. Correct answers are not shown to encourage independent practice.
  </div>
  <?php elseif(!$qzCanRetry): ?>
  <div style="background:#F0FDF4;border:1px solid #9FE1CB;border-radius:12px;padding:11px 14px;
    font-size:12px;font-weight:800;color:#166534;display:flex;align-items:center;gap:8px;">
    ✓ Second attempt locked because your score is 65% or higher.
  </div>
  <?php endif; ?>

  <!-- CTA row -->
  <div style="display:flex;gap:10px;">
    <button onclick="window.location.href='<?= qz_h($resultHref) ?>'"
      style="flex:1;background:transparent;color:#7F77DD;border:1.5px solid #EDE9FA;
      font-family:'Nunito',sans-serif;font-weight:900;font-size:14px;padding:11px 22px;border-radius:8px;cursor:pointer;">
      ← Back to results
    </button>
    <?php if ($qzCanRetry): ?>
    <button onclick="window.location.href='<?= qz_h($retakeHref) ?>'"
      style="flex:1;background:#F97316;color:#fff;border:none;
      font-family:'Nunito',sans-serif;font-weight:900;font-size:14px;padding:11px 22px;border-radius:8px;cursor:pointer;">
      🔁 Retake quiz
    </button>
    <?php endif; ?>
  </div>

</div>
</div>
<?php endif; ?>
<?php endif;?></div></body></html>
