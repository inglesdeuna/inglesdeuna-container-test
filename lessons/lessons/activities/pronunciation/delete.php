<?php
require_once __DIR__."/../../config/db.php";

$unit=$_GET["unit"] ?? null;
$i=$_GET["i"] ?? null;

$stmt=$pdo->prepare("
SELECT data FROM activities
WHERE unit_id=:u AND type='pronunciation'
");
$stmt->execute(["u"=>$unit]);

$row=$stmt->fetch(PDO::FETCH_ASSOC);
$data=json_decode($row["data"] ?? "[]",true);

if(isset($data[$i])){
unset($data[$i]);
$data=array_values($data);
}

$json=json_encode($data,JSON_UNESCAPED_UNICODE);

$stmt=$pdo->prepare("
UPDATE activities SET data=:d
WHERE unit_id=:u AND type='pronunciation'
");

$stmt->execute([
"u"=>$unit,
"d"=>$json
]);

header("Location: editor.php?unit=".$unit);
