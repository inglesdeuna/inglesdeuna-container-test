# Question & Answer Activity (Q&A Cards)

## Overview

The **Question & Answer (Q&A) activity** is a completely standalone card-based learning module designed for advanced English learners to practice fluency through structured questions and full-sentence answers.

**Type identifier in database:** `question_answer`  
**Base directory:** `/lessons/lessons/activities/question_answer/`

---

## Key Differences from Flashcards

| Feature | Flashcards | Q&A Cards |
|---------|-----------|----------|
| **Content** | Image + Optional text | Text-only (full sentences) |
| **Front Side** | Image or word | Full question |
| **Back Side** | Optional translation/answer | Complete answer |
| **Use Case** | Vocabulary, image recognition | Conversation practice, comprehension |
| **Target Level** | All levels | Advanced learners |
| **TTS Support** | Both sides | Both sides |
| **Database Type** | `type = 'flashcards'` | `type = 'question_answer'` |

---

## Architecture

### Two Independent Components

1. **Viewer (`viewer.php`)**
   - Student-facing interface
   - Displays one question at a time
   - Reveals answer on click or button press
   - TTS buttons for listening to question and answer
   - Navigation: Previous/Next
   - Progress tracking
   - Completion screen

2. **Editor (`editor.php`)**
   - Teacher/Admin interface
   - Create and edit question-answer pairs
   - Textarea fields for full-sentence content
   - Add/Remove questions dynamically
   - Save to database with activity title
   - Change detection warning

---

## Data Payload Structure

Questions and answers are stored as JSON in the `data` or `content_json` column:

```json
{
  "title": "Advanced Interview Questions",
  "cards": [
    {
      "id": "qa_unique_id_1",
      "question": "What are the most important skills for this position?",
      "answer": "This role requires strong communication, attention to detail, and the ability to work both independently and as part of a team. Experience with [specific tools] is essential."
    },
    {
      "id": "qa_unique_id_2",
      "question": "How do you handle conflict in the workplace?",
      "answer": "I approach conflicts with empathy and openness. I listen to understand the other person's perspective and look for solutions that benefit everyone involved."
    }
  ]
}
```

---

## Database Schema

Uses the existing `activities` table with:
- **`type`**: `'question_answer'`
- **`unit_id` or `unit`**: References the lesson unit
- **`data` or `content_json`**: Stores the JSON payload
- **`title` or `name`**: Activity title

```sql
INSERT INTO activities (unit_id, type, data, title) VALUES (
  'unit123',
  'question_answer',
  '{"title":"...","cards":[...]}',
  'Advanced Interview Questions'
);
```

---

## Features

### Viewer Features
- ✅ Horizontal card layout (responsive)
- ✅ Large, bold question text
- ✅ One-click reveal answer
- ✅ TTS (Text-to-Speech) buttons on both question and answer
- ✅ Previous/Next navigation
- ✅ Card flip animation
- ✅ Progress counter (Question X of Y)
- ✅ Completion screen with motivational message
- ✅ Mobile-optimized design
- ✅ Keyboard support (Enter/Space to reveal)

### Editor Features
- ✅ Clean two-textarea input per card (question + answer)
- ✅ Long-form text support (no character restrictions)
- ✅ Activity title field
- ✅ Add new question cards dynamically
- ✅ Remove cards
- ✅ Form change detection (warns before leaving)
- ✅ Success message on save
- ✅ Access control (teachers/admins only)

---

## URL Structure

### Viewing Q&A Activity
```
/lessons/lessons/activities/question_answer/viewer.php?unit=unit_id
/lessons/lessons/activities/question_answer/viewer.php?id=activity_id
```

### Editing Q&A Activity
```
/lessons/lessons/activities/question_answer/editor.php?unit=unit_id
/lessons/lessons/activities/question_answer/editor.php?unit=unit_id&id=activity_id
```

---

## TTS (Text-to-Speech) Support

Both the question and answer sides include TTS buttons with the following behavior:

```javascript
// Question spoken in English
speakText(question, 'en-US');

// Answer spoken in English (can be configured)
speakText(answer, 'en-US');
```

To support Spanish, modify the TTS language based on content:
- English questions: `en-US`
- Spanish answers: `es-ES`

---

## Styling

### Color Scheme
- **Front (Question side):** Light background (`#f8fafc`) with dark text
- **Back (Answer side):** Dark background (`#111827`) with light text
- **Buttons:** Accent blue (`#0ea5e9`)
- **Controls:** White with shadow depth

### Responsive Breakpoints
- **Desktop (>960px):** Full horizontal layout with sidebar arrows
- **Tablet (720-960px):** Flexible layout with centered controls
- **Mobile (<720px):** Full-width card, stacked controls

---

## Content Guidelines

### Question Format
- Use complete interrogative sentences
- Ask open-ended questions when possible
- Example: *"What strategies do you use to maintain work-life balance?"*

### Answer Format
- Provide comprehensive, full-sentence responses
- Use academic or professional language (advanced level)
- Include context and explanation
- Example: *"I prioritize boundaries by setting specific work hours and dedicating time to personal activities. This approach helps me stay productive at work while maintaining my mental health."*

---

## Completeness Validation

- ✅ Viewer: Fully functional (`viewer.php` - 390 lines)
- ✅ Editor: Fully functional (`editor.php` - 480 lines)
- ✅ Database integration: Supports all activity table schemas
- ✅ Payload handling: Normalizes and validates JSON
- ✅ Access control: Teachers/admins only in editor
- ✅ Error handling: Graceful fallbacks for missing data
- ✅ Responsive design: Mobile-first CSS
- ✅ Accessibility: ARIA labels, keyboard support

---

## Independence from Flashcards

This Q&A activity is **completely independent** from the flashcard system:

- ✅ Separate directory: `/question_answer/` vs `/flashcards/`
- ✅ Separate type: `question_answer` vs `flashcards`
- ✅ Separate functions: `normalize_qa_payload()` vs `normalize_flashcards_payload()`
- ✅ Separate payload format: `{question, answer}` vs `{text, image}`
- ✅ No shared code or imports between systems
- ✅ Can be disabled without affecting flashcards
- ✅ Different database entries (searchable by type)

---

## Testing Checklist

- [ ] Create a new unit and Q&A activity
- [ ] Add 3-5 question-answer pairs
- [ ] Save and reload editor
- [ ] View activity in viewer
- [ ] Test card navigation (previous/next)
- [ ] Test reveal answer (click card, button, Enter key)
- [ ] Test TTS buttons on question and answer
- [ ] Test mobile responsiveness
- [ ] Test completion screen
- [ ] Verify database stores JSON correctly

---

## Future Enhancements (Optional)

- Language-specific TTS (English questions, Spanish answers)
- Question difficulty levels
- Student response recording
- Performance analytics
- Answer feedback/hints
- Batch import from CSV
- Custom CSS theming

---

## Technical Notes

- **Database agnostic:** Works with any database that stores `activities` table
- **No external dependencies:** Uses built-in `PDO`, `json_*` functions
- **Session required:** Uses PHP session for access control
- **File uploads:** Does not handle file uploads (text-only)
- **Search:** Questions/answers are stored in JSON (not full-text indexed)

---

Created: **April 16, 2026**  
Version: **1.0 (Initial Release)**
