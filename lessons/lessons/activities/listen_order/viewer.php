<?php
$file = __DIR__ . "/listen_order.json";
$data = file_exists($file) ? json_decode(file_get_contents($file), true) : [];
$a = $data[0] ?? null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Listen & Order</title>

<style>
body{
  font-family: Arial, sans-serif;
  background:#eef6ff;
  text-align:center;
  padding:20px;
}

h1{color:#0b5ed7;}

#sentenceBox{
  margin:20px auto;
  padding:15px;
  background:white;
  border-radius:15px;
  min-height:60px;
  max-width:700px;
  box-shadow:0 3px 6px rgba(0,0,0,.1);
}

#words, #answer{
  display:flex;
  flex-wrap:wrap;
  justify-content:center;
  gap:10px;
  margin:15px 0;
}

.word{
  padding:8px 12px;
  border-radius:10px;
  color:white;
  font-weight:bold;
  cursor:grab;
  user-select:none;
}

.drop-zone{
  background:#fff;
  border:2px dashed #0b5ed7;
  border-radius:12px;
  padding:12px;
  min-height:50px;
}

button{
  padding:10px 18px;
  border:none;
  border-radius:12px;
  background:#0b5ed7;
  color:white;
  cursor:pointer;
  margin:6px;
  font-weight:bold;
}

#feedback{
  font-size:18px;
  font-weight:bold;
  margin-top:10px;
}
.good{color:green;}
.bad{color:crimson;}
</style>
</head>

<body>

<h1>🎧 Listen & Order</h1>
<p>Listen and build the sentence.</p>

<?php if (!$a): ?>
<p>No activity available.</p>
<?php else: ?>

<div id="sentenceBox">
  <button onclick="speak()">🔊 Listen</button>
</div>

<h3>Words</h3>
<div id="words"></div>

<h3>Your sentence</h3>
<div id="answer" class="drop-zone"></div>

<button onclick="checkSentence()">✅ Check</button>

<div id="feedback"></div>

<script>
const sentence = <?= json_encode($a["sentence"]) ?>;
const colors = ["#ff6b6b","#feca57","#48dbfb","#1dd1a1","#5f27cd","#ff9f43"];

const wordsDiv = document.getElementById("words");
const answerDiv = document.getElementById("answer");
const feedback = document.getElementById("feedback");

let dragged = null;

// LOAD
function load(){
  wordsDiv.innerHTML="";
  answerDiv.innerHTML="";
  feedback.textContent="";

  const words = sentence.split(" ");
  const shuffled = [...words].sort(()=>Math.random()-0.5);

  shuffled.forEach((w,i)=>{
    const span = document.createElement("span");
    span.textContent = w;
    span.className = "word";
    span.style.background = colors[i % colors.length];
    span.draggable = true;

    span.addEventListener("dragstart", ()=> dragged = span);

    wordsDiv.appendChild(span);
  });
}

// AUDIO AI
function speak(){
  const u = new SpeechSynthesisUtterance(sentence);
  u.lang = "en-US";
  u.rate = 0.9;
  speechSynthesis.cancel();
  speechSynthesis.speak(u);
}

// DROP
answerDiv.addEventListener("dragover", e=>e.preventDefault());
answerDiv.addEventListener("drop", e=>{
  e.preventDefault();
  if(dragged){
    answerDiv.appendChild(dragged);
  }
});

// CHECK
function checkSentence(){
  const built = [...answerDiv.children].map(w=>w.textContent).join(" ");
  if(built === sentence){
    feedback.textContent="🌟 Excellent!";
    feedback.className="good";
  }else{
    feedback.textContent="🔁 Try again!";
    feedback.className="bad";
  }
}

load();
</script>

<?php endif; ?>

</body>
</html>
