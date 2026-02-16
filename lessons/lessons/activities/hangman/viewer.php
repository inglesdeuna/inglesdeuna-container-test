<?php
session_start();
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Hangman</title>

<style>

body{
  font-family: Arial, sans-serif;
  background:#eef6ff;
  text-align:center;
  padding:20px;
}

h1{
  color:#0b5ed7;
}

.game-box{
  margin:20px auto;
  padding:20px;
  background:white;
  border-radius:15px;
  max-width:700px;
}

#word{
  font-size:28px;
  letter-spacing:8px;
  margin:20px 0;
}

#letters button{
  padding:8px 14px;
  margin:4px;
  border:none;
  border-radius:10px;
  background:#2563eb;
  color:white;
  font-weight:bold;
  cursor:pointer;
}

#letters button:hover{
  background:#1d4ed8;
}

#letters button:disabled{
  background:#9ca3af;
  cursor:not-allowed;
}

#feedback{
  font-size:18px;
  font-weight:bold;
  margin-top:15px;
}

.controls{
  margin-top:15px;
}

button.control-btn{
  padding:10px 18px;
  border:none;
  border-radius:12px;
  background:#0b5ed7;
  color:white;
  cursor:pointer;
  margin:6px;
}

button.control-btn:hover{
  background:#084298;
}

a.back{
  display:inline-block;
  margin-top:20px;
  background:#16a34a;
  color:#fff;
  padding:10px 18px;
  border-radius:12px;
  text-decoration:none;
  font-weight:bold;
}

</style>
</head>

<body>

<h1>🎯 Hangman</h1>
<p>Listen and guess the correct word.</p>

<div class="game-box">

  <button class="control-btn" onclick="playSound()">🔊 Listen</button>

  <div id="word"></div>

  <div id="letters"></div>

  <div id="feedback"></div>

  <div class="controls">
      <button class="control-btn" onclick="nextWord()">➡️ Next</button>
  </div>

</div>

<a class="back" href="../hub/index.php">
  ↩ Back
</a>


<script>

const words = ["APPLE","HOUSE","TRAIN","SCHOOL"]; // ← aquí puedes luego conectar DB
let index = 0;
let selectedWord = "";
let guessed = [];
let wrong = 0;
const maxWrong = 6;

const wordDiv = document.getElementById("word");
const lettersDiv = document.getElementById("letters");
const feedback = document.getElementById("feedback");

/* LOAD WORD */
function loadWord(){

  guessed = [];
  wrong = 0;
  feedback.textContent="";
  lettersDiv.innerHTML="";

  selectedWord = words[index];

  displayWord();

  for(let i=65;i<=90;i++){
    const letter = String.fromCharCode(i);
    const btn = document.createElement("button");
    btn.textContent = letter;

    btn.onclick = ()=>{
      btn.disabled=true;

      if(selectedWord.includes(letter)){
        guessed.push(letter);
        displayWord();
        checkWin();
      }else{
        wrong++;
        checkLose();
      }
    };

    lettersDiv.appendChild(btn);
  }
}

/* DISPLAY */
function displayWord(){
  wordDiv.textContent = selectedWord
    .split("")
    .map(l=> guessed.includes(l) ? l : "_")
    .join(" ");
}

/* CHECK WIN */
function checkWin(){
  const won = selectedWord
    .split("")
    .every(l=> guessed.includes(l));

  if(won){
    feedback.textContent="🌟 Excellent!";
  }
}

/* CHECK LOSE */
function checkLose(){
  if(wrong >= maxWrong){
    feedback.textContent="❌ You lost! Word was: " + selectedWord;
  }
}

/* NEXT */
function nextWord(){
  index++;
  if(index >= words.length){
    feedback.textContent="🏆 Finished!";
    return;
  }
  loadWord();
}

/* AUDIO PLACEHOLDER */
function playSound(){
  const msg = new SpeechSynthesisUtterance(selectedWord);
  msg.lang="en-US";
  speechSynthesis.speak(msg);
}

/* START */
loadWord();

</script>

</body>
</html>
