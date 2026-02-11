<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__."/../../config/db.php";

$unit = $_GET["unit"] ?? "";
$activity_type = "listen_order";

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
        $_SESSION[$key][] = [
            "text"=>$_POST["text"] ?? ""
        ];
    }

    if($action=="save"){
        if(!empty($_SESSION[$key])){
            $stmt = $pdo->prepare("
            INSERT INTO activities(unit_id, activity_type, content_json)
            VALUES(?,?,?)
            ");
            $stmt->execute([
                $unit,
                $activity_type,
                json_encode($_SESSION[$key], JSON_UNESCAPED_UNICODE)
            ]);
            $_SESSION[$key] = [];
        }
    }
}

$draft = $_SESSION[$key];

$stmt = $pdo->prepare("
SELECT content_json 
FROM activities 
WHERE unit_id=? AND activity_type=? 
ORDER BY id DESC
");
$stmt->execute([$unit,$activity_type]);
$saved = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Listen Order Editor</title>
</head>
<body>

<h2>Listen & Order Editor</h2>

<form method="POST">
<input type="hidden" name="action" value="add">
<input name="text" placeholder="Write sentence">
<button>+ Add</button>
</form>

<br>

<h3>Draft</h3>

<?php foreach($draft as $i=>$row): ?>
<div>
<b><?=htmlspecialchars($row["text"] ?? "")?></b>
<a href="?unit=<?=$unit?>&delete=<?=$i?>">❌</a>
</div>
<?php endforeach; ?>

<br>

<form method="POST">
<input type="hidden" name="action" value="save">
<button>💾 Guardar Actividad</button>
</form>

<br>

<h3>Saved</h3>

<?php foreach($saved as $row): 
$data = json_decode($row["content_json"],true);
?>

<div style="border:1px solid #ccc; padding:10px; margin:10px 0;">
<?php foreach(($data ?? []) as $item): ?>
<div><?=htmlspecialchars($item["text"] ?? "")?></div>
<?php endforeach; ?>
</div>

<?php endforeach; ?>

</body>
</html>
