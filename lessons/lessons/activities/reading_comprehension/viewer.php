<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../core/_activity_viewer_template.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$activityId = isset($_GET['id']) ? trim((string) $_GET['id']) : '';
$unitId = isset($_GET['unit']) ? trim((string) $_GET['unit']) : '';
$returnTo = isset($_GET['return_to']) ? trim((string) $_GET['return_to']) : '';
$mode = isset($_GET['mode']) ? trim((string) $_GET['mode']) : 'view';

$savedData = [];
$savedTitle = 'Reading Comprehension';

if ($activityId !== '') {
    $stmt = $pdo->prepare("SELECT data, title FROM activities WHERE id = ? AND type = 'reading_comprehension' LIMIT 1");
    $stmt->execute([$activityId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $savedData = json_decode((string) ($row['data'] ?? ''), true) ?? [];
        $savedTitle = trim((string) ($row['title'] ?? '')) !== '' ? (string) $row['title'] : $savedTitle;
    }
} elseif ($unitId !== '') {
    $stmt = $pdo->prepare("SELECT data, title FROM activities WHERE unit_id = ? AND type = 'reading_comprehension' ORDER BY id ASC LIMIT 1");
    $stmt->execute([$unitId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $savedData = json_decode((string) ($row['data'] ?? ''), true) ?? [];
        $savedTitle = trim((string) ($row['title'] ?? '')) !== '' ? (string) $row['title'] : $savedTitle;
    }
}

$isEditor = ($mode === 'edit') && (isset($_SESSION['academic_id']) || isset($_SESSION['admin_id']));
$allowEditor = $isEditor ? 'true' : 'false';

ob_start();
?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Fredoka:wght@400;600&family=Nunito:wght@400;600;700;800;900&display=swap" rel="stylesheet">
<script src="https://unpkg.com/react@18/umd/react.production.min.js"></script>
<script src="https://unpkg.com/react-dom@18/umd/react-dom.production.min.js"></script>
<script src="https://unpkg.com/@babel/standalone/babel.min.js"></script>
<div id="rc-root"></div>

<script>
window.RC_ACTIVITY_ID  = <?= json_encode($activityId) ?>;
window.RC_UNIT_ID      = <?= json_encode($unitId) ?>;
window.RC_RETURN_TO    = <?= json_encode($returnTo) ?>;
window.RC_ALLOW_EDITOR = <?= $allowEditor ?>;
window.RC_SAVED_TITLE  = <?= json_encode($savedTitle) ?>;
window.RC_SAVED_DATA   = <?= json_encode($savedData) ?>;
</script>

<script type="text/babel">
const { useMemo, useState } = React;

const C = {
  orange: '#F97316',
  orangeSoft: '#FFF0E6',
  orangeBorder: '#FCDDBF',
  orangeDark: '#C2580A',
  purple: '#7F77DD',
  purpleSoft: '#F5F3FF',
  purpleBorder: '#EDE9FA',
  purpleMid: '#9B8FCC',
  green: '#1D9E75',
  greenSoft: '#E1F5EE',
  greenDark: '#085041',
  red: '#D85A30',
  redSoft: '#FAECE7',
  redDark: '#4A1B0C',
  ink: '#3D3560',
  bg: '#F8F7FF',
  white: '#ffffff',
  border: '#F0EEF8',
};

const LETTERS = ['A', 'B', 'C', 'D'];

const uid = (prefix) => `${prefix}_${Math.random().toString(36).slice(2, 9)}_${Date.now()}`;

const countWords = (text) => String(text || '').trim().split(/\s+/).filter(Boolean).length;

const escapeHtml = (value) => String(value || '')
  .replace(/&/g, '&amp;')
  .replace(/</g, '&lt;')
  .replace(/>/g, '&gt;')
  .replace(/"/g, '&quot;')
  .replace(/'/g, '&#39;');

const escapeRegExp = (value) => String(value || '').replace(/[.*+?^${}()|[\]\\]/g, '\\$&');

function normalizeWord(item = {}) {
  const distractors = Array.isArray(item.distractors) ? item.distractors : [];
  return {
    id: String(item.id || uid('w')),
    word: String(item.word || ''),
    correct: String(item.correct || ''),
    distractors: [
      String(distractors[0] || ''),
      String(distractors[1] || ''),
    ],
  };
}

function normalizeQuestion(item = {}) {
  const options = Array.isArray(item.options) ? item.options : [];
  const rawCorrect = Number(item.correct);
  return {
    id: String(item.id || uid('q')),
    stem: String(item.stem || ''),
    options: [
      String(options[0] || ''),
      String(options[1] || ''),
      String(options[2] || ''),
      String(options[3] || ''),
    ],
    correct: Number.isInteger(rawCorrect) ? Math.max(0, Math.min(3, rawCorrect)) : 0,
    feedback: String(item.feedback || ''),
  };
}

function normalizeText(input = {}) {
  const mode = String(input.mode || 'vocab').toLowerCase() === 'comp' ? 'comp' : 'vocab';
  const words = (Array.isArray(input.words) ? input.words : []).map(normalizeWord);
  const questions = (Array.isArray(input.questions) ? input.questions : []).map(normalizeQuestion);
  const body = String(input.body || '');
  const wc = Number(input.wordCount);
  return {
    id: String(input.id || uid('text')),
    mode,
    title: String(input.title || 'The Mystery of Migration'),
    genre: String(input.genre || 'Informative text'),
    wordCount: Number.isFinite(wc) && wc > 0 ? wc : countWords(body),
    body,
    words,
    questions,
  };
}

function normalizeDataset(raw) {
  if (raw && Array.isArray(raw.texts) && raw.texts.length) {
    return {
      title: String(raw.title || window.RC_SAVED_TITLE || 'Reading Comprehension'),
      texts: raw.texts.map(normalizeText),
    };
  }

  const base = normalizeText(raw || {});
  if ((!raw || !raw.title) && window.RC_SAVED_TITLE && !base.title) {
    base.title = String(window.RC_SAVED_TITLE || 'Reading Comprehension');
  }

  return {
    title: String(window.RC_SAVED_TITLE || 'Reading Comprehension'),
    texts: [base],
  };
}

function decorateTextSegment(html, terms) {
  return terms.reduce((acc, term) => {
    const escaped = escapeRegExp(term);
    const pattern = /\s/.test(term) ? `(${escaped})` : `\\b(${escaped})\\b`;
    return acc.replace(new RegExp(pattern, 'gi'), '<span class="rc-hl">$1</span>');
  }, html);
}

function paragraphToHtml(paragraph, words) {
  const formatted = escapeHtml(paragraph)
    .replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>')
    .replace(/__(.+?)__/g, '<u>$1</u>');

  const terms = (words || []).map((w) => String(w.word || '').trim()).filter(Boolean).sort((a, b) => b.length - a.length);
  if (!terms.length) return formatted;

  const chunks = formatted.split(/(<[^>]+>)/g);
  return chunks.map((chunk) => (chunk.startsWith('<') ? chunk : decorateTextSegment(chunk, terms))).join('');
}

function buildVocabDeck(words) {
  return (words || []).map((word, idx) => {
    const options = [
      { text: word.correct || '', correct: true, key: `c_${word.id}` },
      { text: word.distractors?.[0] || '', correct: false, key: `d1_${word.id}` },
      { text: word.distractors?.[1] || '', correct: false, key: `d2_${word.id}` },
    ].filter((x) => x.text.trim() !== '');

    const seed = Array.from(`${word.id}_${idx}`).reduce((a, c) => a + c.charCodeAt(0), 0);
    const shuffled = options
      .map((opt, i) => ({ ...opt, sortKey: ((seed + 11) * (i + 3)) % 97 }))
      .sort((a, b) => a.sortKey - b.sortKey)
      .map(({ sortKey, ...rest }) => rest);

    return {
      id: word.id,
      word: word.word,
      options: shuffled,
    };
  }).filter((x) => x.word.trim() && x.options.length >= 2);
}

function TopBar({ done, total }) {
  return (
    <div style={{ background: C.white, borderBottom: `1.5px solid ${C.border}`, height: 52, padding: '0 16px', position: 'relative', display: 'flex', alignItems: 'center', justifyContent: 'space-between' }}>
      <button
        onClick={() => {
          const backTo = String(window.RC_RETURN_TO || '').trim();
          if (backTo) window.location.href = backTo;
          else window.history.back();
        }}
        style={{ width: 32, height: 32, borderRadius: 999, border: `1.5px solid ${C.purpleBorder}`, background: C.white, color: C.ink, cursor: 'pointer' }}
      >←</button>

      <div style={{ position: 'absolute', left: '50%', transform: 'translateX(-50%)', color: C.orange, fontFamily: 'Fredoka, sans-serif', fontSize: 18 }}>Reading Comprehension</div>

      <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
        <div style={{ width: 90, height: 7, borderRadius: 999, background: C.purpleBorder, overflow: 'hidden' }}>
          <div style={{ width: `${total ? (done / total) * 100 : 0}%`, height: '100%', borderRadius: 999, background: 'linear-gradient(90deg,#F97316,#7F77DD)' }} />
        </div>
        <div style={{ fontFamily: 'Nunito, sans-serif', fontWeight: 900, fontSize: 12, color: C.purple }}>{done} / {total}</div>
      </div>
    </div>
  );
}

function PassagePane({ text }) {
  const paragraphs = String(text.body || '').split(/\n\s*\n/).map((p) => p.trim()).filter(Boolean);
  return (
    <div style={{ borderRight: `1px solid ${C.border}`, padding: 16, overflowY: 'auto' }}>
      <div style={{ background: C.orangeSoft, border: `1px solid ${C.orangeBorder}`, borderRadius: 999, color: C.orangeDark, fontSize: 11, fontWeight: 900, textTransform: 'uppercase', letterSpacing: '.08em', padding: '3px 14px', width: 'fit-content' }}>Reading passage</div>
      <h2 style={{ margin: '12px 0 6px', fontFamily: 'Fredoka, sans-serif', fontSize: 20, color: C.orange }}>{text.title || 'Untitled passage'}</h2>
      <div style={{ fontFamily: 'Nunito, sans-serif', fontSize: 11, color: C.purpleMid, fontWeight: 700 }}>{text.genre || 'Informative text'} · {text.wordCount || countWords(text.body)} words · Read carefully</div>
      <div style={{ height: 1.5, background: C.border, margin: '14px 0' }} />
      <div style={{ color: C.ink, fontFamily: 'Nunito, sans-serif', fontSize: 14, lineHeight: 1.7 }}>
        {(paragraphs.length ? paragraphs : ['No passage text available.']).map((p, idx) => (
          <p key={`p_${idx}`} style={{ margin: '0 0 14px' }} dangerouslySetInnerHTML={{ __html: paragraphToHtml(p, text.words) }} />
        ))}
      </div>
    </div>
  );
}

function VocabQuestions({ text }) {
  const deck = useMemo(() => buildVocabDeck(text.words), [text.words]);
  const [index, setIndex] = useState(0);
  const [answers, setAnswers] = useState(() => deck.map(() => ({ selected: -1, checked: false, correct: false })));

  const row = answers[index] || { selected: -1, checked: false, correct: false };
  const item = deck[index] || null;
  const done = answers.filter((a) => a.checked).length;

  if (!item) {
    return <div style={{ padding: 16, color: C.purpleMid, fontWeight: 700 }}>Add highlighted words with meanings to generate questions.</div>;
  }

  return (
    <>
      <TopBar done={done} total={deck.length} />
      <div style={{ flex: 1, minHeight: 0, display: 'flex' }}>
        <div style={{ width: '48%', minWidth: 0 }}><PassagePane text={text} /></div>
        <div style={{ width: '52%', minWidth: 0, padding: 16, overflowY: 'auto' }}>
          <div style={{ border: `1.5px solid ${C.purpleBorder}`, borderRadius: 18, background: C.white, padding: 18 }}>
            <div style={{ color: C.purpleMid, fontSize: 11, fontWeight: 900, textTransform: 'uppercase', marginBottom: 8 }}>Question {index + 1} of {deck.length}</div>
            <h3 style={{ margin: 0, color: C.ink, fontFamily: 'Fredoka, sans-serif', fontSize: 22 }}>What does <span style={{ color: C.orange }}>{item.word}</span> mean?</h3>

            <div style={{ marginTop: 14, display: 'flex', flexDirection: 'column', gap: 8 }}>
              {item.options.map((opt, optIdx) => {
                const selected = row.selected === optIdx;
                const isCorrect = row.checked && opt.correct;
                const isWrong = row.checked && selected && !opt.correct;
                const border = isCorrect ? C.green : (isWrong ? C.red : (selected ? C.purple : C.purpleBorder));
                const bg = isCorrect ? C.greenSoft : (isWrong ? C.redSoft : (selected ? C.purpleSoft : '#FBFAFF'));
                const color = isCorrect ? C.greenDark : (isWrong ? C.redDark : C.ink);
                return (
                  <button key={opt.key} onClick={() => {
                    if (row.checked) return;
                    setAnswers((prev) => prev.map((a, i) => i === index ? { ...a, selected: optIdx } : a));
                  }} style={{ textAlign: 'left', border: `1.5px solid ${border}`, borderRadius: 12, background: bg, color, padding: '10px 12px', fontSize: 14, fontWeight: 700, cursor: row.checked ? 'default' : 'pointer' }}>
                    {opt.text}
                  </button>
                );
              })}
            </div>

            <div style={{ marginTop: 12, display: 'flex', justifyContent: 'space-between', gap: 8 }}>
              <button onClick={() => setIndex((p) => Math.max(0, p - 1))} disabled={index === 0} style={{ border: `1.5px solid ${C.purpleBorder}`, borderRadius: 10, background: C.white, color: C.purple, padding: '8px 12px', fontWeight: 900, opacity: index === 0 ? 0.45 : 1 }}>← Previous</button>
              {!row.checked ? (
                <button
                  onClick={() => {
                    if (row.selected < 0) return;
                    const isCorrect = !!item.options[row.selected]?.correct;
                    setAnswers((prev) => prev.map((a, i) => i === index ? { ...a, checked: true, correct: isCorrect } : a));
                  }}
                  disabled={row.selected < 0}
                  style={{ border: 'none', borderRadius: 10, background: C.purple, color: C.white, padding: '8px 14px', fontWeight: 900, opacity: row.selected < 0 ? 0.45 : 1 }}
                >Check answer</button>
              ) : (
                <button onClick={() => setIndex((p) => Math.min(deck.length - 1, p + 1))} disabled={index >= deck.length - 1} style={{ border: 'none', borderRadius: 10, background: C.orange, color: C.white, padding: '8px 14px', fontWeight: 900, opacity: index >= deck.length - 1 ? 0.45 : 1 }}>{index >= deck.length - 1 ? 'Completed' : 'Next →'}</button>
              )}
            </div>

            {row.checked && (
              <div style={{ marginTop: 10, background: row.correct ? C.greenSoft : C.redSoft, color: row.correct ? C.greenDark : C.redDark, borderLeft: `3px solid ${row.correct ? C.green : C.red}`, padding: '10px 12px', fontWeight: 700 }}>
                {row.correct ? 'Correct!' : 'Try again on the next round.'}
              </div>
            )}
          </div>
        </div>
      </div>
    </>
  );
}

function CompQuestions({ text }) {
  const questions = useMemo(() => (text.questions || []).filter((q) => q.options.some((o) => String(o || '').trim())), [text.questions]);
  const [index, setIndex] = useState(0);
  const [answers, setAnswers] = useState(() => questions.map(() => ({ selected: -1, checked: false, correct: false })));
  const done = answers.filter((a) => a.checked).length;
  const q = questions[index] || null;
  const row = answers[index] || { selected: -1, checked: false, correct: false };

  if (!q) {
    return <div style={{ padding: 16, color: C.purpleMid, fontWeight: 700 }}>Add comprehension questions to preview this mode.</div>;
  }

  return (
    <>
      <TopBar done={done} total={questions.length} />
      <div style={{ flex: 1, minHeight: 0, display: 'flex' }}>
        <div style={{ width: '48%', minWidth: 0 }}><PassagePane text={text} /></div>
        <div style={{ width: '52%', minWidth: 0, padding: 16, overflowY: 'auto' }}>
          <div style={{ border: `1.5px solid ${C.purpleBorder}`, borderRadius: 18, background: C.white, padding: 18 }}>
            <div style={{ color: C.purpleMid, fontSize: 11, fontWeight: 900, textTransform: 'uppercase', marginBottom: 8 }}>Question {index + 1} of {questions.length}</div>
            <h3 style={{ margin: 0, color: C.ink, fontFamily: 'Fredoka, sans-serif', fontSize: 20 }}>{q.stem || `Question ${index + 1}`}</h3>

            <div style={{ marginTop: 14, display: 'flex', flexDirection: 'column', gap: 8 }}>
              {q.options.map((opt, optIdx) => {
                const selected = row.selected === optIdx;
                const isCorrect = row.checked && q.correct === optIdx;
                const isWrong = row.checked && selected && q.correct !== optIdx;
                const border = isCorrect ? C.green : (isWrong ? C.red : (selected ? C.purple : C.purpleBorder));
                const bg = isCorrect ? C.greenSoft : (isWrong ? C.redSoft : (selected ? C.purpleSoft : '#FBFAFF'));
                const color = isCorrect ? C.greenDark : (isWrong ? C.redDark : C.ink);
                return (
                  <button key={`${q.id}_${optIdx}`} onClick={() => {
                    if (row.checked) return;
                    setAnswers((prev) => prev.map((a, i) => i === index ? { ...a, selected: optIdx } : a));
                  }} style={{ display: 'flex', alignItems: 'center', gap: 10, textAlign: 'left', border: `1.5px solid ${border}`, borderRadius: 12, background: bg, color, padding: '10px 12px', fontSize: 14, fontWeight: 700, cursor: row.checked ? 'default' : 'pointer' }}>
                    <span style={{ width: 20, height: 20, borderRadius: 6, background: selected ? C.purple : C.purpleBorder, color: selected ? C.white : C.purple, fontSize: 10, fontWeight: 900, display: 'grid', placeItems: 'center' }}>{LETTERS[optIdx]}</span>
                    <span>{opt}</span>
                  </button>
                );
              })}
            </div>

            <div style={{ marginTop: 12, display: 'flex', justifyContent: 'space-between', gap: 8 }}>
              <button onClick={() => setIndex((p) => Math.max(0, p - 1))} disabled={index === 0} style={{ border: `1.5px solid ${C.purpleBorder}`, borderRadius: 10, background: C.white, color: C.purple, padding: '8px 12px', fontWeight: 900, opacity: index === 0 ? 0.45 : 1 }}>← Previous</button>
              {!row.checked ? (
                <button onClick={() => {
                  if (row.selected < 0) return;
                  setAnswers((prev) => prev.map((a, i) => i === index ? { ...a, checked: true, correct: row.selected === q.correct } : a));
                }} disabled={row.selected < 0} style={{ border: 'none', borderRadius: 10, background: C.purple, color: C.white, padding: '8px 14px', fontWeight: 900, opacity: row.selected < 0 ? 0.45 : 1 }}>Check answer</button>
              ) : (
                <button onClick={() => setIndex((p) => Math.min(questions.length - 1, p + 1))} disabled={index >= questions.length - 1} style={{ border: 'none', borderRadius: 10, background: C.orange, color: C.white, padding: '8px 14px', fontWeight: 900, opacity: index >= questions.length - 1 ? 0.45 : 1 }}>{index >= questions.length - 1 ? 'Completed' : 'Next →'}</button>
              )}
            </div>

            {row.checked && (
              <div style={{ marginTop: 10, background: row.correct ? C.greenSoft : C.orangeSoft, color: row.correct ? C.greenDark : C.orangeDark, borderLeft: `3px solid ${row.correct ? C.green : C.orange}`, padding: '10px 12px', fontWeight: 700 }}>
                {q.feedback || (row.correct ? 'Correct answer!' : `Correct answer: ${LETTERS[q.correct]}`)}
              </div>
            )}
          </div>
        </div>
      </div>
    </>
  );
}

function PlayerView({ data }) {
  const texts = data.texts || [];
  const [textIdx, setTextIdx] = useState(0);
  const current = texts[textIdx] || texts[0] || normalizeText();

  return (
    <div style={{ display: 'flex', flexDirection: 'column', height: 'calc(100vh - 40px)', background: C.bg }}>
      {texts.length > 1 && (
        <div style={{ display: 'flex', alignItems: 'center', gap: 18, padding: '8px 14px 0', borderBottom: `1px solid ${C.border}`, background: C.white }}>
          {texts.map((_, idx) => (
            <button key={`tab_${idx}`} onClick={() => setTextIdx(idx)} style={{ border: 'none', background: 'transparent', cursor: 'pointer', color: idx === textIdx ? C.orange : C.purpleMid, fontFamily: 'Nunito, sans-serif', fontWeight: 900, padding: '8px 2px 10px', borderBottom: idx === textIdx ? `2.5px solid ${C.orange}` : '2.5px solid transparent', display: 'inline-flex', alignItems: 'center', gap: 8 }}>
              <span>Text {idx + 1}</span>
              {idx === textIdx && <span style={{ background: C.purpleBorder, color: C.purple, borderRadius: 6, fontSize: 10, fontWeight: 900, padding: '2px 8px' }}>In progress</span>}
            </button>
          ))}
        </div>
      )}

      {current.mode === 'comp' ? <CompQuestions text={current} /> : <VocabQuestions text={current} />}
    </div>
  );
}

async function saveActivity(data) {
  const formPayload = new URLSearchParams();
  formPayload.set('unit', window.RC_UNIT_ID || '');
  formPayload.set('type', 'reading_comprehension');
  formPayload.set('content_json', JSON.stringify(data));

  const res = await fetch('../../core/save_activity.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
    credentials: 'same-origin',
    body: formPayload.toString(),
  });

  if (!res.ok) {
    throw new Error(`Save failed (${res.status})`);
  }
}

function EditorView({ data, setData }) {
  const [status, setStatus] = useState('');
  const [saving, setSaving] = useState(false);
  const [previewing, setPreviewing] = useState(false);

  const text = data.texts[0] || normalizeText();
  const patchText = (patcher) => {
    setData((prev) => ({ ...prev, texts: [patcher(prev.texts[0] || normalizeText())] }));
  };

  const addWord = () => patchText((t) => ({ ...t, words: [...t.words, normalizeWord()] }));
  const addQuestion = () => patchText((t) => ({ ...t, questions: [...t.questions, normalizeQuestion()] }));

  const save = async () => {
    setSaving(true);
    setStatus('Saving...');
    try {
      const payload = {
        mode: text.mode,
        title: text.title,
        genre: text.genre,
        wordCount: text.wordCount || countWords(text.body),
        body: text.body,
        words: text.words,
        questions: text.questions,
      };
      await saveActivity(payload);
      setStatus('Saved successfully.');
    } catch (err) {
      setStatus('Could not save activity.');
    } finally {
      setSaving(false);
    }
  };

  if (previewing) {
    return (
      <div style={{ height: 'calc(100vh - 40px)', display: 'flex', flexDirection: 'column' }}>
        <div style={{ background: C.white, borderBottom: `1px solid ${C.border}`, padding: 10 }}>
          <button onClick={() => setPreviewing(false)} style={{ border: `1px solid ${C.purpleBorder}`, borderRadius: 10, background: C.white, color: C.purple, padding: '8px 12px', fontWeight: 900 }}>← Back to editor</button>
        </div>
        <div style={{ flex: 1, minHeight: 0 }}><PlayerView data={data} /></div>
      </div>
    );
  }

  return (
    <div style={{ height: 'calc(100vh - 40px)', overflowY: 'auto', background: C.bg, padding: 20 }}>
      <div style={{ maxWidth: 1120, margin: '0 auto' }}>
        <div style={{ background: C.white, border: `1.5px solid ${C.purpleBorder}`, borderRadius: 20, overflow: 'hidden', marginBottom: 16 }}>
          <div style={{ padding: '14px 18px', borderBottom: `1px solid ${C.border}`, display: 'flex', alignItems: 'center', gap: 10 }}>
            <div style={{ width: 34, height: 34, borderRadius: 12, background: C.orangeSoft, border: `1px solid ${C.orangeBorder}`, display: 'grid', placeItems: 'center', color: C.orangeDark, fontWeight: 900 }}>⚙</div>
            <div style={{ fontFamily: 'Fredoka, sans-serif', color: C.orange, fontSize: 28 }}>Reading Comprehension</div>
            <div style={{ marginLeft: 'auto', color: C.purple, fontWeight: 900, fontSize: 14 }}>Edit mode</div>
          </div>

          <div style={{ padding: 18 }}>
            <div style={{ display: 'grid', gridTemplateColumns: '2fr 1fr', gap: 12, marginBottom: 14 }}>
              <div>
                <label style={{ display: 'block', marginBottom: 6, fontWeight: 900, color: C.purple }}>Activity title</label>
                <input value={text.title} onChange={(e) => patchText((t) => ({ ...t, title: e.target.value }))} style={{ width: '100%', padding: '10px 12px', borderRadius: 10, border: `1px solid ${C.purpleBorder}`, fontSize: 18, fontWeight: 700 }} />
              </div>
              <div>
                <label style={{ display: 'block', marginBottom: 6, fontWeight: 900, color: C.purple }}>Level</label>
                <input value={text.genre} onChange={(e) => patchText((t) => ({ ...t, genre: e.target.value }))} style={{ width: '100%', padding: '10px 12px', borderRadius: 10, border: `1px solid ${C.purpleBorder}`, fontSize: 18, fontWeight: 700 }} />
              </div>
            </div>

            <div style={{ marginBottom: 12 }}>
              <label style={{ display: 'block', marginBottom: 6, fontWeight: 900, color: C.purple }}>Activity mode — choose one</label>
              <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 12 }}>
                <button onClick={() => patchText((t) => ({ ...t, mode: 'vocab' }))} style={{ textAlign: 'left', border: `2px solid ${text.mode === 'vocab' ? C.orange : C.purpleBorder}`, borderRadius: 16, background: text.mode === 'vocab' ? '#F8ECE2' : '#F6F4FD', padding: 16 }}>
                  <div style={{ fontFamily: 'Fredoka, sans-serif', color: C.orange, fontSize: 26 }}>Vocabulary meaning</div>
                  <div style={{ color: C.purpleMid, fontWeight: 700 }}>Students read the passage and choose the correct meaning for each highlighted word.</div>
                </button>
                <button onClick={() => patchText((t) => ({ ...t, mode: 'comp' }))} style={{ textAlign: 'left', border: `2px solid ${text.mode === 'comp' ? C.orange : C.purpleBorder}`, borderRadius: 16, background: text.mode === 'comp' ? '#F8ECE2' : '#F6F4FD', padding: 16 }}>
                  <div style={{ fontFamily: 'Fredoka, sans-serif', color: C.purple, fontSize: 26 }}>Reading comprehension</div>
                  <div style={{ color: C.purpleMid, fontWeight: 700 }}>Students answer questions about the passage to demonstrate understanding.</div>
                </button>
              </div>
            </div>
          </div>
        </div>

        <div style={{ background: C.white, border: `1.5px solid ${C.purpleBorder}`, borderRadius: 20, overflow: 'hidden', marginBottom: 16 }}>
          <div style={{ padding: '14px 18px', borderBottom: `1px solid ${C.border}`, display: 'flex', alignItems: 'center', gap: 10 }}>
            <div style={{ width: 34, height: 34, borderRadius: 12, background: C.orangeSoft, border: `1px solid ${C.orangeBorder}`, display: 'grid', placeItems: 'center', color: C.orangeDark, fontWeight: 900 }}>📘</div>
            <div style={{ fontFamily: 'Fredoka, sans-serif', color: C.orange, fontSize: 28 }}>Passage</div>
          </div>

          <div style={{ padding: 18 }}>
            <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr 220px', gap: 10, marginBottom: 10 }}>
              <div>
                <label style={{ display: 'block', marginBottom: 6, fontWeight: 900, color: C.purple }}>Title</label>
                <input value={text.title} onChange={(e) => patchText((t) => ({ ...t, title: e.target.value }))} style={{ width: '100%', padding: '9px 10px', borderRadius: 10, border: `1px solid ${C.purpleBorder}`, fontWeight: 700, fontSize: 18 }} />
              </div>
              <div>
                <label style={{ display: 'block', marginBottom: 6, fontWeight: 900, color: C.purple }}>Genre</label>
                <input value={text.genre} onChange={(e) => patchText((t) => ({ ...t, genre: e.target.value }))} style={{ width: '100%', padding: '9px 10px', borderRadius: 10, border: `1px solid ${C.purpleBorder}`, fontWeight: 700, fontSize: 18 }} />
              </div>
              <div>
                <label style={{ display: 'block', marginBottom: 6, fontWeight: 900, color: C.purple }}>Word count</label>
                <input type="number" value={text.wordCount || ''} onChange={(e) => patchText((t) => ({ ...t, wordCount: Number(e.target.value || 0) }))} style={{ width: '100%', padding: '9px 10px', borderRadius: 10, border: `1px solid ${C.purpleBorder}`, fontWeight: 700, fontSize: 18 }} />
              </div>
            </div>
            <label style={{ display: 'block', marginBottom: 6, fontWeight: 900, color: C.purple }}>Passage body</label>
            <textarea rows={8} value={text.body} onChange={(e) => patchText((t) => ({ ...t, body: e.target.value }))} style={{ width: '100%', padding: '10px 12px', borderRadius: 12, border: `1px solid ${C.purpleBorder}`, fontFamily: 'Nunito, sans-serif', fontSize: 18, fontWeight: 700 }} />
          </div>
        </div>

        <div style={{ background: C.white, border: `1.5px solid ${C.purpleBorder}`, borderRadius: 20, overflow: 'hidden', marginBottom: 16 }}>
          <div style={{ padding: '14px 18px', borderBottom: `1px solid ${C.border}`, display: 'flex', alignItems: 'center', gap: 10 }}>
            <div style={{ width: 34, height: 34, borderRadius: 12, background: C.orangeSoft, border: `1px solid ${C.orangeBorder}`, display: 'grid', placeItems: 'center', color: C.orangeDark, fontWeight: 900 }}>🟧</div>
            <div style={{ fontFamily: 'Fredoka, sans-serif', color: C.orange, fontSize: 28 }}>Highlighted vocabulary words</div>
            <div style={{ marginLeft: 'auto', background: C.purpleSoft, color: C.purple, borderRadius: 8, padding: '2px 10px', fontWeight: 900 }}>{text.words.length} words</div>
          </div>

          <div style={{ padding: 18 }}>
            <div style={{ marginBottom: 10, background: C.purpleSoft, border: `1px solid ${C.purpleBorder}`, borderRadius: 12, padding: '10px 12px', color: C.purple, fontWeight: 800 }}>
              Add each word that appears in the passage. It will be highlighted in orange for students.
            </div>

            <div style={{ border: `1px solid ${C.orangeBorder}`, background: '#FFF9F4', borderRadius: 16, padding: 12, marginBottom: 12 }}>
              <div style={{ color: C.purpleMid, fontSize: 11, fontWeight: 900, marginBottom: 8 }}>Live preview — highlighted words</div>
              <div style={{ color: C.ink, lineHeight: 1.7 }} dangerouslySetInnerHTML={{ __html: paragraphToHtml(text.body || 'Type passage text to preview highlights.', text.words) }} />
            </div>

            {text.words.map((word, idx) => (
              <div key={word.id} style={{ border: `1px solid ${C.purpleBorder}`, borderRadius: 16, padding: 12, marginBottom: 10 }}>
                <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 8 }}>
                  <div style={{ color: C.orange, fontFamily: 'Fredoka, sans-serif', fontSize: 24 }}>Word card {idx + 1}</div>
                  <button onClick={() => patchText((t) => ({ ...t, words: t.words.filter((_, i) => i !== idx) }))} style={{ border: `1px solid ${C.red}`, borderRadius: 8, background: C.white, color: C.red, padding: '4px 10px', fontWeight: 900 }}>Delete</button>
                </div>
                <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 10, marginBottom: 8 }}>
                  <div>
                    <label style={{ display: 'block', marginBottom: 4, fontWeight: 900, color: C.purple }}>Word (as it appears in text)</label>
                    <input value={word.word} onChange={(e) => patchText((t) => ({ ...t, words: t.words.map((w, i) => i === idx ? { ...w, word: e.target.value } : w) }))} style={{ width: '100%', padding: '9px 10px', borderRadius: 10, border: `1px solid ${C.purpleBorder}`, fontWeight: 700, fontSize: 18 }} />
                  </div>
                  <div>
                    <label style={{ display: 'block', marginBottom: 4, fontWeight: 900, color: C.purple }}>Correct meaning</label>
                    <input value={word.correct} onChange={(e) => patchText((t) => ({ ...t, words: t.words.map((w, i) => i === idx ? { ...w, correct: e.target.value } : w) }))} style={{ width: '100%', padding: '9px 10px', borderRadius: 10, border: `1px solid ${C.purpleBorder}`, fontWeight: 700, fontSize: 18 }} />
                  </div>
                </div>
                <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 10 }}>
                  <div>
                    <label style={{ display: 'block', marginBottom: 4, fontWeight: 900, color: C.purple }}>Wrong option 1</label>
                    <input value={word.distractors[0] || ''} onChange={(e) => patchText((t) => ({ ...t, words: t.words.map((w, i) => i === idx ? { ...w, distractors: [e.target.value, w.distractors?.[1] || ''] } : w) }))} style={{ width: '100%', padding: '9px 10px', borderRadius: 10, border: `1px solid ${C.purpleBorder}`, fontWeight: 700, fontSize: 18 }} />
                  </div>
                  <div>
                    <label style={{ display: 'block', marginBottom: 4, fontWeight: 900, color: C.purple }}>Wrong option 2</label>
                    <input value={word.distractors[1] || ''} onChange={(e) => patchText((t) => ({ ...t, words: t.words.map((w, i) => i === idx ? { ...w, distractors: [w.distractors?.[0] || '', e.target.value] } : w) }))} style={{ width: '100%', padding: '9px 10px', borderRadius: 10, border: `1px solid ${C.purpleBorder}`, fontWeight: 700, fontSize: 18 }} />
                  </div>
                </div>
              </div>
            ))}

            <button onClick={addWord} style={{ width: '100%', border: `1.5px solid ${C.purpleBorder}`, background: C.white, color: C.ink, borderRadius: 12, padding: '10px 12px', fontSize: 32, fontWeight: 900 }}>+ Add vocabulary word</button>
          </div>
        </div>

        <div style={{ background: C.white, border: `1.5px solid ${C.purpleBorder}`, borderRadius: 20, overflow: 'hidden', marginBottom: 16 }}>
          <div style={{ padding: '14px 18px', borderBottom: `1px solid ${C.border}`, display: 'flex', alignItems: 'center', gap: 10 }}>
            <div style={{ width: 34, height: 34, borderRadius: 12, background: C.orangeSoft, border: `1px solid ${C.orangeBorder}`, display: 'grid', placeItems: 'center', color: C.orangeDark, fontWeight: 900 }}>❓</div>
            <div style={{ fontFamily: 'Fredoka, sans-serif', color: C.orange, fontSize: 28 }}>Reading comprehension questions</div>
            <div style={{ marginLeft: 'auto', background: C.purpleSoft, color: C.purple, borderRadius: 8, padding: '2px 10px', fontWeight: 900 }}>{text.questions.length} questions</div>
          </div>
          <div style={{ padding: 18 }}>
            {text.questions.map((q, idx) => (
              <div key={q.id} style={{ border: `1px solid ${C.purpleBorder}`, borderRadius: 16, padding: 12, marginBottom: 10 }}>
                <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 8 }}>
                  <div style={{ color: C.orange, fontFamily: 'Fredoka, sans-serif', fontSize: 24 }}>Question {idx + 1}</div>
                  <button onClick={() => patchText((t) => ({ ...t, questions: t.questions.filter((_, i) => i !== idx) }))} style={{ border: `1px solid ${C.red}`, borderRadius: 8, background: C.white, color: C.red, padding: '4px 10px', fontWeight: 900 }}>Delete</button>
                </div>
                <input value={q.stem} onChange={(e) => patchText((t) => ({ ...t, questions: t.questions.map((x, i) => i === idx ? { ...x, stem: e.target.value } : x) }))} placeholder="Question stem" style={{ width: '100%', marginBottom: 8, padding: '9px 10px', borderRadius: 10, border: `1px solid ${C.purpleBorder}`, fontWeight: 700, fontSize: 18 }} />
                {q.options.map((opt, optIdx) => (
                  <input key={`${q.id}_${optIdx}`} value={opt} onChange={(e) => patchText((t) => ({ ...t, questions: t.questions.map((x, i) => i === idx ? { ...x, options: x.options.map((o, j) => j === optIdx ? e.target.value : o) } : x) }))} placeholder={`Option ${LETTERS[optIdx]}`} style={{ width: '100%', marginBottom: 6, padding: '9px 10px', borderRadius: 10, border: `1px solid ${C.purpleBorder}`, fontWeight: 700, fontSize: 18 }} />
                ))}
                <div style={{ display: 'flex', alignItems: 'center', gap: 10, marginBottom: 8 }}>
                  <span style={{ fontWeight: 900, color: C.ink }}>Correct:</span>
                  {LETTERS.map((label, optIdx) => (
                    <label key={`${q.id}_c_${optIdx}`} style={{ display: 'inline-flex', alignItems: 'center', gap: 4, fontWeight: 800 }}>
                      <input type="radio" checked={q.correct === optIdx} onChange={() => patchText((t) => ({ ...t, questions: t.questions.map((x, i) => i === idx ? { ...x, correct: optIdx } : x) }))} /> {label}
                    </label>
                  ))}
                </div>
                <input value={q.feedback} onChange={(e) => patchText((t) => ({ ...t, questions: t.questions.map((x, i) => i === idx ? { ...x, feedback: e.target.value } : x) }))} placeholder="Feedback" style={{ width: '100%', padding: '9px 10px', borderRadius: 10, border: `1px solid ${C.purpleBorder}`, fontWeight: 700, fontSize: 18 }} />
              </div>
            ))}
            <button onClick={addQuestion} style={{ width: '100%', border: `1.5px solid ${C.purpleBorder}`, background: C.white, color: C.ink, borderRadius: 12, padding: '10px 12px', fontSize: 32, fontWeight: 900 }}>+ Add comprehension question</button>
          </div>
        </div>

        <div style={{ display: 'grid', gridTemplateColumns: '1fr auto auto', gap: 12, alignItems: 'center', position: 'sticky', bottom: 0, background: C.bg, paddingBottom: 12 }}>
          <button onClick={() => setPreviewing(true)} style={{ border: `1.5px solid ${C.purpleBorder}`, borderRadius: 12, background: C.white, color: C.ink, padding: '12px 14px', fontWeight: 900, fontSize: 28 }}>Preview as student</button>
          <span style={{ color: C.purple, fontWeight: 900, fontSize: 14 }}>{status}</span>
          <button onClick={save} disabled={saving} style={{ border: 'none', borderRadius: 12, background: C.white, color: C.ink, padding: '12px 18px', fontWeight: 900, fontSize: 28, opacity: saving ? 0.45 : 1 }}>Save activity</button>
        </div>
      </div>
    </div>
  );
}

function App() {
  const [data, setData] = useState(() => normalizeDataset(window.RC_SAVED_DATA || {}));
  const allowEditor = !!window.RC_ALLOW_EDITOR;

  if (allowEditor) {
    return <EditorView data={data} setData={setData} />;
  }

  return <PlayerView data={data} />;
}

ReactDOM.createRoot(document.getElementById('rc-root')).render(<App />);
</script>

<style>
.rc-hl{
  color:#C2580A;
  font-weight:900;
  border-bottom:2px solid #F97316;
  background:#FFF0E6;
  border-radius:4px;
  padding:0 2px;
}
</style>

<?php
$content = ob_get_clean();
render_activity_viewer('Reading Comprehension', 'fa-solid fa-book-open', $content);
