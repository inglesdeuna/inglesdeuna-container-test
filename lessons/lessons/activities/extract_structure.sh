#!/bin/bash

for activity in crossword dictation dot_to_dot fillblank flashcards free_conversation hangman lets_classify listen_order matching_lines memory_cards order_sentences pronunciation question_answer reading_comprehension review_match roleplay roleplay_kids story_kids tracing unscramble_kids unscramble video_comprehension writing_practice; do
  file="$activity/viewer.php"
  if [ ! -f "$file" ]; then
    continue
  fi
  
  echo "Activity: $activity"
  
  # Get main wrapper div ID/class
  main_wrapper=$(grep -o '<div[[:space:]]*class="\|<div[[:space:]]*id="' "$file" | head -5)
  if [ ! -z "$main_wrapper" ]; then
    echo "  Main wrapper:"
    grep -o '<div[[:space:]]*\(class\|id\)="[^"]*"' "$file" | head -3
  fi
  
  # Get button IDs
  echo "  Buttons:"
  grep -o 'id="[^"]*"[[:space:]]*class="[^"]*btn' "$file" | head -8
  grep -o 'class="[^"]*btn[^"]*"[[:space:]]*id="[^"]*"' "$file" | head -8
  
  # Get CSS files
  echo "  CSS:"
  grep -o 'href="[^"]*\.css[^"]*"' "$file"
  
  echo ""
done
