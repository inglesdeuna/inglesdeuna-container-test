<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__."/../../config/db.php";

$unit = $_GET["unit"] ?? "";
$type = "listen_order";

session_start();

$key = "draft_listen_order_".$unit;
if(!isset($_SESSION[$key])) $_SESSION[$key] = [];

if(isset($_GET["delete"])){
    $i = intval($_GET["delete"]);
    if(isset($_SESSION[$key][$i])){
        array_splice($_SESSION[$key], $i, 1);
    }
}

if($_SERVER["REQUEST_METHOD"]=="POST"){

    $action = $_POST["action"] ?? "";

    if($action=="add"){

        $audio = $_POST["audio"] ?? "";

        $images = [];
        if(!empty($_POST["images"])){
            $images = array_map("trim", explode(",", $_POST["images"]));
        }

        $correct = [];
        if(!empty($_POST["correct"])){
            $correct = array_map("intval", explode(",", $_POST["correct"]));
        }

        $_SESSION[$key][] = [
            "audio"=>$audio,
            "images"=>$images,
            "correct"=>$correct
        ];
    }

    if($action=="save"){
        if(!empty($_SESSION[$key])){
            $stmt = $pdo->prepare("
            INSERT INTO activities(unit_id, type, data)
            VALUES(?,?,?)
            ");
            $stmt->execute([
                $unit,
                $type,
                json_encode($_SESSION[$key], JSON_UNESCAPED_UNICODE)
            ]);
            $_SESSION[$key] = [];
        }
    }
}

$draft = $_SESSION[$key];

$stmt = $pdo->prepare("
SELECT data FROM activities
WHERE unit_id=? AND type=?
ORDER BY id DESC
");
$stmt->execute([$unit,$type]);
$saved = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<h2>Listen & Order Editor</h2>

<form method="POST">
<input type="hidden" name="action" value="add">

Audio URL:
<input name="audio" placeholder="uploads/audio.mp3">

<br><br>

Images (coma separadas):
<input name="images" placeholder="img1.png,img2.png,img3.png">

<br><br>

Correct order (ej: 0,2,1):
<input name="correct" placeholder="0,1,2">

<br><br>

<button>+ Add</button>
</form>

<h3>Draft</h3>

<?php foreach($draft as $i=>$row): ?>
<div>
🎧 <?=$row["audio"]?><br>
🖼 <?=implode(", ",$row["images"])?><br>
✔ <?=implode(", ",$row["correct"])?>

<a href="?unit=<?=$unit?>&delete=<?=$i?>">❌</a>
</div>
<hr>
<?php endforeach; ?>

<form method="POST">
<input type="hidden" name="action" value="save">
<button>💾 Guardar Actividad</button>
</form>

<h3>Saved</h3>

<?php foreach($saved as $row):
$data = json_decode($row["data"],true);
?>
<pre><?php print_r($data); ?></pre>
<?php endforeach; ?>
