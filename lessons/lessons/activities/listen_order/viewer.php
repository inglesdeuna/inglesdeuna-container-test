<?php
require_once __DIR__."/../../config/db.php";

$unit = $_GET["unit"] ?? "";
$activity_type = "listen_order";

$stmt = $pdo->prepare("
SELECT content_json 
FROM activities 
WHERE unit_id=? AND activity_type=? 
ORDER BY id DESC LIMIT 1
");

$stmt->execute([$unit,$activity_type]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

$data = json_decode($row["content_json"] ?? "[]", true);
?>

<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Listen & Order</title>

<style>
.word{
display:inline-block;
padding:10px;
margin:5px;
background:#eee;
cursor:pointer;
}
</style>

</head>
<body>

<h2>Listen & Order</h2>

<div id="words"></div>

<button onclick="check()">Check</button>

<script>

let data = <?=json_encode($data)?>;
let words = [];
let correct = [];

data.forEach(item=>{
let sentence = item.text || "";
let parts = sentence.split(" ");
correct.push(parts);
words = words.concat(parts);
});

words.sort(()=>Math.random()-0.5);

let container = document.getElementById("words");

words.forEach(w=>{
let d = document.createElement("div");
d.className="word";
d.innerText=w;
d.onclick=()=>container.appendChild(d);
container.appendChild(d);
});

function check(){
alert("Check manual logic placeholder");
}

</script>

</body>
</html>
