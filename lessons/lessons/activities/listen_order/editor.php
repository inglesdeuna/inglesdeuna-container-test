<?php
$unit = $_GET["unit"] ?? null;
if(!$unit) die("Unidad missing");

$jsonFile = __DIR__."/listen_order.json";
$data = file_exists($jsonFile)
  ? json_decode(file_get_contents($jsonFile), true)
  : [];

$uploadDir = __DIR__."/uploads/".$unit;
if(!is_dir($uploadDir)) mkdir($uploadDir,0777,true);

if($_SERVER["REQUEST_METHOD"]==="POST"){

  if(!isset($data[$unit])) $data[$unit]=[];

  /* AUDIO */
  $audioPath=null;
  if(!empty($_FILES["audio"]["name"])){
    $aName=uniqid()."_".basename($_FILES["audio"]["name"]);
    move_uploaded_file(
      $_FILES["audio"]["tmp_name"],
      $uploadDir."/".$aName
    );
    $audioPath="activities/listen_order/uploads/".$unit."/".$aName;
  }

  /* IMAGES */
  $imgs=[];
  if(isset($_FILES["images"]["name"])){
    foreach($_FILES["images"]["name"] as $i=>$n){
      if(!$n) continue;

      $new=uniqid()."_".basename($n);
      move_uploaded_file(
        $_FILES["images"]["tmp_name"][$i],
        $uploadDir."/".$new
      );

      $imgs[]="activities/listen_order/uploads/".$unit."/".$new;
    }
  }

  if($audioPath && count($imgs)){
    $data[$unit][]=[
      "audio"=>$audioPath,
      "images"=>$imgs
    ];
  }

  file_put_contents($jsonFile,json_encode($data,JSON_PRETTY_PRINT));
  header("Location:?unit=".$unit);
  exit;
}

/* DELETE */
if(isset($_GET["delete"])){
  unset($data[$unit][$_GET["delete"]]);
  $data[$unit]=array_values($data[$unit]);
  file_put_contents($jsonFile,json_encode($data,JSON_PRETTY_PRINT));
  header("Location:?unit=".$unit);
  exit;
}

$list=$data[$unit] ?? [];
?>

<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Listen Order Editor</title>
</head>

<body style="font-family:Arial;background:#eef6ff;padding:30px">

<div style="background:white;padding:20px;border-radius:15px;max-width:700px;margin:auto">

<h2>🎧 Listen & Order Editor</h2>

<form method="POST" enctype="multipart/form-data">

Audio MP3:<br>
<input type="file" name="audio" accept="audio/mp3" required><br><br>

Imágenes:<br>
<input type="file" name="images[]" multiple accept="image/*" required><br><br>

<button>Guardar</button>

</form>

<hr>

<h3>Bloques</h3>

<?php foreach($list as $i=>$b): ?>
<div style="margin:10px 0;padding:10px;background:#f3f4f6;border-radius:10px">
Audio ✔<br>

<?php foreach($b["images"] as $img): ?>
<img src="../../<?= $img ?>" height="60">
<?php endforeach; ?>

<a href="?unit=<?=$unit?>&delete=<?=$i?>" style="color:red">✖</a>
</div>
<?php endforeach; ?>

<br>
<a href="../hub/index.php?unit=<?=$unit?>">
<button>Volver Hub</button>
</a>

</div>
</body>
</html>
