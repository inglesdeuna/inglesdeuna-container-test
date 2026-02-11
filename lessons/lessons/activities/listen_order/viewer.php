<?php
require_once __DIR__."/../../config/db.php";

$unit = $_GET["unit"] ?? "";
$type = "listen_order";

$stmt = $pdo->prepare("
SELECT data FROM activities
WHERE unit_id=? AND type=?
ORDER BY id DESC LIMIT 1
");
$stmt->execute([$unit,$type]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

$data = json_decode($row["data"] ?? "[]", true);
$item = $data[0] ?? ["audio"=>"","images"=>[],"correct"=>[]];
?>

<h2>Listen & Order</h2>

<audio controls src="../../<?=$item["audio"]?>"></audio>

<br><br>

<div id="images" style="display:flex; gap:10px;"></div>

<br>

<button onclick="check()">Check</button>

<script>

let images = <?=json_encode($item["images"])?>;
let correct = <?=json_encode($item["correct"])?>;

images.sort(()=>Math.random()-0.5);

let container = document.getElementById("images");

images.forEach((src,i)=>{
let img = document.createElement("img");
img.src="../../"+src;
img.width=120;
img.draggable=true;

img.ondragstart = e=>{
e.dataTransfer.setData("text", i);
};

img.ondragover = e=> e.preventDefault();

img.ondrop = e=>{
e.preventDefault();
let from = e.dataTransfer.getData("text");
container.insertBefore(container.children[from], img);
};

container.appendChild(img);
});

function check(){
let order=[];
[...container.children].forEach(img=>{
let file = img.src.split("/").pop();
order.push(images.indexOf(file));
});

alert(JSON.stringify(order)==JSON.stringify(correct) ? "Correct!" : "Try again");
}

</script>
