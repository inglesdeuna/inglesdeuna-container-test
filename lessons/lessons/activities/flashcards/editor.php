<?php
require_once __DIR__."/../../config/init_db.php";

$type = "flashcards";
require_once __DIR__."/../../core/_activity_editor_template.php";

$data = $data ?? [];
?>

<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Flashcards Editor</title>

<style>
body{
    font-family:Arial;
    background:#e9f2fb;
    padding:30px;
}

.box{
    max-width:900px;
    margin:auto;
    background:white;
    padding:25px;
    border-radius:20px;
    box-shadow:0 6px 20px rgba(0,0,0,.08);
}

h2{
    color:#0b5ed7;
    margin-bottom:20px;
}

input[type="text"],
input[type="file"]{
    padding:10px;
    border-radius:10px;
    border:1px solid #ccc;
    width:100%;
}

.row{
    display:grid;
    grid-template-columns:2fr 2fr auto;
    gap:10px;
    margin-bottom:10px;
}

button{
    padding:10px 18px;
    border:none;
    border-radius:10px;
    cursor:pointer;
    font-weight:bold;
}

.save{
    background:#2f6fed;
    color:white;
}

.hub{
    background:#28a745;
    color:white;
}

.delete{
    color:red;
    text-decoration:none;
    font-size:20px;
    font-weight:bold;
}

.savedCard{
    display:flex;
    align-items:center;
    gap:15px;
    background:#f8f9fa;
    padding:12px;
    border-radius:14px;
    margin-bottom:10px;
}

.mini{
    width:60px;
    height:60px;
    object-fit:contain;
}
</style>
</head>

<body>

<div class="box">

<h2>🧸 Flashcards Editor</h2>

<form method="POST" enctype="multipart/form-data">

<div class="row">
    <input type="text" name="text" placeholder="Word">
    <input type="file" name="image">
    <button class="save" name="add">💾 Guardar</button>
</div>

</form>

<a href="../hub/index.php?unit=<?=$unit?>">
    <button class="hub">← Volver al Hub</button>
</a>

<hr>

<h3>📦 Guardados</h3>

<?php foreach($data as $i=>$item): ?>

<div class="savedCard">

    <?php if(!empty($item["image"])): ?>
        <img src="/lessons/lessons/<?=$item["image"]?>" class="mini">
    <?php endif; ?>

    <div>
        <b><?=htmlspecialchars($item["text"] ?? "")?></b>
    </div>

    <a class="delete" href="?unit=<?=$unit?>&delete=<?=$i?>">❌</a>

</div>

<?php endforeach; ?>

</div>

</body>
</html>
