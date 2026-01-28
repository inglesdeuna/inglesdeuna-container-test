<?php
$file = __DIR__ . "/external_links.json";
$data = file_exists($file) ? json_decode(file_get_contents($file), true) : [];

/* ======================
   EDIT MODE
====================== */
$editIndex = isset($_GET["edit"]) ? (int)$_GET["edit"] : null;
$editing = $editIndex !== null && isset($data[$editIndex]);

/* ======================
   DELETE
====================== */
if (isset($_GET["delete"])) {
    $i = (int)$_GET["delete"];
    if (isset($data[$i])) {
        array_splice($data, $i, 1);
        file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT));
    }
    header("Location: external_links.php");
    exit;
}

/* ======================
   SAVE
====================== */
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $item = [
        "title" => trim($_POST["title"]),
        "url"   => trim($_POST["url"]),
        "type"  => $_POST["type"]
    ];

    if (isset($_POST["edit"])) {
        $data[(int)$_POST["edit"]] = $item;
    } else {
        $data[] = $item;
    }

    file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT));
    header("Location: external_links.php");
    exit;
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Actividad Externa (Docente)</title>
<style>
body{font-family:Arial;background:#f5f7fb}
.card{background:#fff;padding:20px;border-radius:10px;max-width:600px;margin:40px auto}
input,select,button{width:100%;padding:10px;margin-top:10px}
button{background:#2563eb;color:#fff;border:none;border-radius:6px}
.list{max-width:800px;margin:20px auto}
.item{background:#fff;padding:15px;border-radius:10px;margin-bottom:10px}
.actions a{margin-right:10px}
</style>
</head>

<body>

<div class="card">
<h2><?= $editing ? "✏️ Editar actividad" : "➕ Nueva actividad" ?></h2>

<form method="post">
<input type="text" name="title" placeholder="Título"
       value="<?= $editing ? htmlspecialchars($data[$editIndex]["title"]) : "" ?>" required>

<input type="url" name="url" placeholder="URL o Embed"
       value="<?= $editing ? htmlspecialchars($data[$editIndex]["url"]) : "" ?>" required>

<select name="type">
  <option value="link" <?= $editing && $data[$editIndex]["type"]==="link"?"selected":"" ?>>
    🔗 Enlace externo
  </option>
  <option value="embed" <?= $editing && $data[$editIndex]["type"]==="embed"?"selected":"" ?>>
    ▶️ Integrado (iframe)
  </option>
</select>

<?php if ($editing): ?>
<input type="hidden" name="edit" value="<?= $editIndex ?>">
<?php endif; ?>

<button>Guardar actividad</button>
</form>
</div>

<div class="list">
<h3>📚 Actividades guardadas</h3>

<?php foreach ($data as $i => $a): ?>
<div class="item" draggable="true" data-index="<?= $i ?>">
<strong><?= htmlspecialchars($a["title"]) ?></strong><br>
<small><?= htmlspecialchars($a["url"]) ?></small>

<div class="actions">
<a href="external_links.php?edit=<?= $i ?>">✏️ Editar</a>
<a href="external_links.php?delete=<?= $i ?>" onclick="return confirm('¿Eliminar actividad?')">🗑 Eliminar</a>
</div>
</div>
<?php endforeach; ?>
</div>
   
<button id="saveOrder" style="margin:20px auto;display:block;">
💾 Guardar orden
</button>
<script>
let dragged = null;

document.querySelectorAll(".item").forEach(item => {

  item.addEventListener("dragstart", () => {
    dragged = item;
    item.style.opacity = "0.5";
  });

  item.addEventListener("dragend", () => {
    item.style.opacity = "";
  });

  item.addEventListener("dragover", e => {
    e.preventDefault();
  });

  item.addEventListener("drop", e => {
    e.preventDefault();
    if (dragged && dragged !== item) {
      item.parentNode.insertBefore(dragged, item);
    }
  });

});

document.getElementById("saveOrder").addEventListener("click", () => {
  const order = [];
  document.querySelectorAll(".item").forEach(el => {
    order.push(el.dataset.index);
  });

  fetch("save_order.php", {
    method: "POST",
    headers: {"Content-Type":"application/json"},
    body: JSON.stringify(order)
  }).then(() => location.reload());
});
</script>

</body>
</html>
