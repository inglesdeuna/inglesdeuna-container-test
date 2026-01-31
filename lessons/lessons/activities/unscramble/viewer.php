<?php
$file = __DIR__ . "/unscramble.json";
$data = file_exists($file) ? json_decode(file_get_contents($file), true) : [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Unscramble</title>

<style>
body{
  font-family:Arial, sans-serif;
  background:#eef6ff;
  padding:20px;
}
h1{text-align:center;color:#2563eb;}

.container{
  max-width:800px;
  margin:0 auto;
}

video,audio{
  width:100%;
  border-radius:14px;
  margin-bottom:15px;
}

.list{
  display:flex;
  flex-direction:column;
  gap:10px;
}

.item{
  background:#fff;
  padding:12px;
  border-radius:10px;
  box-shadow:0 4px 8px rgba(0,0,0,.1);
  cursor:grab;
  font-weight:bold;
}

button{
  margin-top:20px;
  padding:12px 18px;
  border:none;
  border-radius:10px;
  background:#2563eb;
  color:white;
  font-weight:bold;
  cursor:pointer;
}

.result{
  margin-top:15px;
  font-size:18px;
  font-weight:bold;
}
.good{color:green;}
.try{color:orange;}
</style>
</head>

<body>

<h1>🧩 Unscramble</h1>

<?php if (empty($data)): ?>
  <p style="text-align:center">No hay actividades disponibles.</p>
<?php else: ?>

<?php $a = $data[0]; ?>

<div class="container">

  <?php if (!empty($a["video"])): ?>
    <video controls>
      <source src="<?= htmlspecialchars($a["video"]) ?>" type="video/mp4">
    </video>
  <?php endif; ?>

  <?php if (!empty($a["audio"])): ?>
    <audio controls src="<?= htmlspecialchars($a["audio"]) ?>"></audio>
  <?php endif; ?>

  <div class="list" id="list"></div>

  <button onclick="check()">✅ Check Order</button>
  <div id="result" class="result"></div>

</div>

<script>
const correct = <?= json_encode($a["items"]) ?>;
let shuffled = [...correct].sort(()=>Math.random()-0.5);

const list = document.getElementById("list");
shuffled.forEach(t=>{
  const div = document.createElement("div");
  div.className="item";
  div.textContent=t;
  div.draggable=true;
  list.appendChild(div);
});

let drag;
list.addEventListener("dragstart",e=>{
  if(e.target.classList.contains("item")){
    drag=e.target;
  }
});
list.addEventListener("dragover",e=>e.preventDefault());
list.addEventListener("drop",e=>{
  if(e.target.classList.contains("item")){
    list.insertBefore(drag, e.target.nextSibling);
  }
});

function check(){
  const user = [...document.querySelectorAll(".item")].map(i=>i.textContent);
  const ok = user.join("|") === correct.join("|");
  const r = document.getElementById("result");
  if(ok){
    r.textContent="🌟 Correct!";
    r.className="result good";
  }else{
    r.textContent="🔁 Try again!";
    r.className="result try";
  }
}
</script>

<?php endif; ?>

</body>
</html>
