<?php
require_once __DIR__."/../../core/activity_editor_base.php";
require_once __DIR__."/../../config/db.php";

$activity_type = "listen_order";
$unit = $_GET["unit"] ?? "";

activity_init_draft($activity_type, $unit);

if(isset($_GET["delete_draft"])){
    activity_delete_item($activity_type, $unit, intval($_GET["delete_draft"]));
}

if($_SERVER["REQUEST_METHOD"]=="POST"){

    $action = $_POST["action"] ?? "";

    if($action=="add"){

        activity_add_item($activity_type, $unit, [
            "text"=>$_POST["text"] ?? "",
            "images"=>[]
        ]);

    }

    if($action=="save_db"){
        activity_save_to_db($pdo, $activity_type, $unit);
    }
}

$draft = activity_get_draft($activity_type, $unit);

$stmt = $pdo->prepare("SELECT * FROM activities WHERE unit_id=? AND activity_type=? ORDER BY id DESC");
$stmt->execute([$unit,$activity_type]);
$saved = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Listen & Order Editor</title>
</head>
<body>

<h2>Listen & Order — Editor</h2>

<form method="POST">
<input type="hidden" name="action" value="add">
<input name="text" placeholder="Write sentence">
<button>+ Add</button>
</form>

<br>

<h3>📝 Draft</h3>

<?php foreach($draft as $i=>$row): ?>

<div>
<b><?=htmlspecialchars($row["text"] ?? "")?></b>
<a href="?unit=<?=$unit?>&delete_draft=<?=$i?>">❌</a>
</div>

<?php endforeach; ?>

<br>

<form method="POST">
<input type="hidden" name="action" value="save_db">
<button>💾 Guardar Actividad</button>
</form>

<br>

<h3>📦 Saved</h3>

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

