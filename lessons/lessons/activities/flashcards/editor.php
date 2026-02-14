<?php
require_once __DIR__."/../../config/init_db.php";

$type = "flashcards";

require_once __DIR__."/../../core/_activity_editor_template.php";
?>

<h2>Flashcards Editor</h2>

<a href="viewer.php?unit=<?php echo $unit; ?>">👉 Ir al Viewer</a>

<form method="POST" enctype="multipart/form-data" style="margin-top:20px;">

    <label>Texto:</label><br>
    <input type="text" name="text"><br><br>

    <label>Imagen:</label><br>
    <input type="file" name="image"><br><br>

    <button type="submit" name="add">Guardar Flashcard</button>

</form>

<hr>

<h3>Guardados</h3>

<?php foreach($data as $i=>$item): ?>

<div style="border:1px solid #ccc; padding:10px; margin:10px 0">

    <strong><?php echo htmlspecialchars($item["text"] ?? ""); ?></strong><br>

    <?php if(!empty($item["image"])): ?>
        <img src="/lessons/lessons/<?php echo $item["image"]; ?>" width="150">
    <?php endif; ?>

    <br>
    <a href="?unit=<?php echo $unit ?>&delete=<?php echo $i ?>">Eliminar</a>

</div>

<?php endforeach; ?>
