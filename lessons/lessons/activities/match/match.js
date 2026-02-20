document.addEventListener("DOMContentLoaded", () => {

  const shuffle = arr => arr.sort(() => Math.random() - 0.5);

  const imagesDiv = document.getElementById("match-images");
  const wordsDiv  = document.getElementById("match-words");

  imagesDiv.innerHTML = "";
  wordsDiv.innerHTML = "";

  // IMAGES
  shuffle([...MATCH_DATA]).forEach(item=>{
    imagesDiv.innerHTML += `
      <div class="card">
        <img src="${item.image}"
             draggable="true"
             data-id="${item.id}"
             class="image">
      </div>
    `;
  });

  // WORDS
  shuffle([...MATCH_DATA]).forEach(item=>{
    wordsDiv.innerHTML += `
      <div class="word"
           data-id="${item.id}">
        ${item.text}
      </div>
    `;
  });

  document.addEventListener("dragstart", e=>{
    if(e.target.classList.contains("image")){
      e.dataTransfer.setData("id", e.target.dataset.id);
    }
  });

  document.addEventListener("dragover", e=>{
    if(e.target.classList.contains("word")){
      e.preventDefault();
    }
  });

  document.addEventListener("drop", e=>{
    if(e.target.classList.contains("word")){
      e.preventDefault();
      const draggedId = e.dataTransfer.getData("id");
      const targetId = e.target.dataset.id;

      if(draggedId === targetId){
        e.target.classList.add("correct");
      }else{
        e.target.classList.add("wrong");
        setTimeout(()=>e.target.classList.remove("wrong"),800);
      }
    }
  });

});
