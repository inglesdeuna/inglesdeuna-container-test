<?php
require_once __DIR__."/../../config/db.php";

$unit=$_GET["unit"] ?? null;
if(!$unit) die("Unit missing");

$uploadDir=__DIR__."/uploads/".$unit;
if(!is_dir($uploadDir)) mkdir($uploadDir,0777,true);

/* LOAD EXISTING */
$stmt=$pdo->prepare("SELECT data FROM activities WHERE unit_id=? AND type='listen_order'");
$stmt->execute([$unit]);
$row=$stmt->fetch(PDO::FETCH_ASSOC);
$data=json_decode($row["data"] ?? "[]",true);

/* SAVE */
if($_SERVER["REQUEST_METHOD"]==="POST"){

$data=[];

if(isset($_POST["sentence"])){

foreach($_POST["sentence"] as $q=>$sentence){

if(trim($sentence)=="") continue;

$parts=[];

foreach($_POST["parts_text"][$q] as $i=>$text){

if(trim($text)=="") continue;

$img="";

if(!empty($_FILES["parts_img"]["name"][$q][$i])){
$name=uniqid()."_".basename($_FILES["parts_img"]["name"][$q][$i]);
move_uploaded_file(
$_FILES["parts_img"]["tmp_name"][$q][$i],
$uploadDir."/".$name
);
$img="activities/listen_order/uploads/".$unit."/".$name;
}

$parts[]=["text"=>$text,"img"=>$img];

}

$data[]=[
"id"=>uniqid(),
"sentence"=>$sentence,
"parts"=>$parts
];

}
}

/* UPSERT */
$stmt=$pdo->prepare("
INSERT INTO activities(id,unit_id,type,data)
VALUES(gen_random_uuid(),?,?,?)
ON CONFLICT(unit_id,type)
DO UPDATE SET data=EXCLUDED.data
");

$stmt->execute([$unit,"listen_order",json_encode($data)]);

header("Location: editor.php?unit=".$unit."&saved=1");
exit;

}
?>
