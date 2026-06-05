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
$savedTexts = [];
$savedTitle = 'Reading Comprehension';

if ($activityId !== '') {
    $stmt = $pdo->prepare("SELECT data, title FROM activities WHERE id = ? AND type = 'reading_comprehension' LIMIT 1");
    $stmt->execute([$activityId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $savedData = json_decode((string) ($row['data'] ?? ''), true) ?? [];
        $savedTitle = (string) ($row['title'] ?? $savedTitle);
        $savedTexts = is_array($savedData['texts'] ?? null) ? $savedData['texts'] : [];
    }
}

$isEditor = ($mode === 'edit') && (isset($_SESSION['academic_id']) || isset($_SESSION['admin_id']));
$allowEditor = $isEditor ? 'true' : 'false';

ob_start();
?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Fredoka:wght@400;600&family=Nunito:wght@400;600;700;900&display=swap" rel="stylesheet">
<script src="https://unpkg.com/react@18/umd/react.production.min.js"></script>
<script src="https://unpkg.com/react-dom@18/umd/react-dom.production.min.js"></script>
<script src="https://unpkg.com/@babel/standalone/babel.min.js"></script>

<div id="rc-root"></div>

<script>
window.RC_ACTIVITY_ID   = <?= json_encode($activityId) ?>;
window.RC_UNIT_ID       = <?= json_encode($unitId) ?>;
window.RC_RETURN_TO     = <?= json_encode($returnTo) ?>;
window.RC_ALLOW_EDITOR  = <?= $allowEditor ?>;
window.RC_SAVED_TITLE   = <?= json_encode($savedTitle) ?>;
window.RC_SAVED_TEXTS   = <?= json_encode($savedTexts) ?>;
</script>

<script type="text/babel">
const { useMemo, useState, useEffect } = React;

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

function id(prefix) {
  return `${prefix}_${Date.now()}_${Math.random().toString(36).slice(2, 8)}`;
}

function countWords(text) {
  const cleaned = String(text || '').trim();
  if (!cleaned) return 0;
  return cleaned.split(/\s+/).filter(Boolean).length;
}

function normalizeQuestion(q = {}) {
  const optionsSource = Array.isArray(q.options) ? q.options : [];
  const options = LETTERS.map((letter, idx) => {
    const raw = String(optionsSource[idx] ?? '').trim();
    if (!raw) return `${letter}) `;
    return raw;
  });
  const correct = LETTERS.includes(String(q.correct || '').trim().toUpperCase())
    ? String(q.correct || '').trim().toUpperCase()
    : 'A';
  return {
    id: String(q.id || id('q')),
    stem: String(q.stem || ''),
    options,
    correct,
    feedback: String(q.feedback || ''),
  };
}

function normalizeText(t = {}, idx = 0) {
  const body = String(t.body || '');
  const vocab = (Array.isArray(t.vocab) ? t.vocab : [])
    .slice(0, 5)
    .map((v) => ({ word: String(v.word || ''), def: String(v.def || '') }));
  const questions = (Array.isArray(t.questions) ? t.questions : []).map(normalizeQuestion);
  const wordCountRaw = Number(t.wordCount);
  const computedWords = countWords(body);
  return {
    id: String(t.id || id('text')),
    title: String(t.title || `Text ${idx + 1}`),
    genre: String(t.genre || 'Informative text'),
    wordCount: Number.isFinite(wordCountRaw) && wordCountRaw > 0 ? wordCountRaw : computedWords,
    body,
    vocab,
    questions,
  };
}

function defaultText(idx = 0) {
  return normalizeText({
    title: `Text ${idx + 1}`,
    genre: 'Informative text',
    body: '',
    vocab: [],
    questions: [
      {
        stem: '',
        options: ['A) ', 'B) ', 'C) ', 'D) '],
        correct: 'A',
        feedback: '',
      },
    ],
  }, idx);
}

function normalizeTexts(input) {
  const arr = Array.isArray(input) ? input : [];
  if (!arr.length) return [defaultText(0)];
  return arr.map((t, idx) => normalizeText(t, idx));
}

function splitParagraphs(body) {
  return String(body || '')
    .split(/\n\s*\n/)
    .map((p) => p.trim())
    .filter(Boolean);
}

function getProgress(texts, answers) {
  let total = 0;
  let done = 0;
  let correct = 0;
  texts.forEach((t) => {
    const rows = answers[t.id] || [];
    total += t.questions.length;
    rows.forEach((r) => {
      if (r.checked) done += 1;
      if (r.checked && r.isCorrect) correct += 1;
    });
  });
  return { total, done, correct, wrong: Math.max(0, done - correct), percent: total ? Math.round((correct / total) * 100) : 0 };
}

function initAnswers(texts) {
  const out = {};
  texts.forEach((t) => {
    out[t.id] = t.questions.map(() => ({ selected: '', checked: false, isCorrect: false }));
  });
  return out;
}

async function saveCompletionScore(activityId, unitId, score) {
  const payload = { activity_id: activityId, unit_id: unitId, score, completed: true };
  try {
    await fetch('../../core/save_activity.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      credentials: 'same-origin',
      body: JSON.stringify(payload),
    });
  } catch (err) {}
}

async function saveReadingActivity({ activityId, unitId, title, texts }) {
  const data = {
    title: String(title || 'Reading Comprehension').trim() || 'Reading Comprehension',
    texts: texts.map((t, idx) => {
      const normalized = normalizeText(t, idx);
      return {
        id: normalized.id,
        title: normalized.title,
        genre: normalized.genre,
        wordCount: normalized.wordCount || countWords(normalized.body),
        body: normalized.body,
        vocab: (normalized.vocab || []).filter((v) => String(v.word || '').trim() || String(v.def || '').trim()).slice(0, 5),
        questions: (normalized.questions || []).map(normalizeQuestion),
      };
    }),
  };

  const payload = {
    id: activityId,
    unit_id: unitId,
    unit: unitId,
    type: 'reading_comprehension',
    title: data.title,
    data,
  };

  try {
    const res = await fetch('../../core/save_activity.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      credentials: 'same-origin',
      body: JSON.stringify(payload),
    });
    if (res.ok) return { ok: true };
  } catch (e) {}

  const formPayload = new URLSearchParams();
  formPayload.set('unit', unitId || '');
  formPayload.set('type', 'reading_comprehension');
  formPayload.set('content_json', JSON.stringify(data));

  const fallback = await fetch('../../core/save_activity.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
    credentials: 'same-origin',
    body: formPayload.toString(),
  });

  if (!fallback.ok) throw new Error(`Save failed (${fallback.status})`);
  return { ok: true };
}

async function uploadReadingImage(file) {
  const form = new FormData();
  form.append('image', file);
  const response = await fetch('../../core/upload_image.php', {
    method: 'POST',
    credentials: 'same-origin',
    body: form,
  });
  if (!response.ok) throw new Error('Upload failed');
  return response.json();
}

function PlayerView({ title, texts }) {
  const [textIdx, setTextIdx] = useState(0);
  const [answers, setAnswers] = useState(() => initAnswers(texts));
  const [showCompletion, setShowCompletion] = useState(false);

  useEffect(() => {
    setAnswers(initAnswers(texts));
    setTextIdx(0);
    setShowCompletion(false);
  }, [texts]);

  const current = texts[textIdx] || texts[0];
  const currentAnswers = answers[current.id] || [];
  const progress = useMemo(() => getProgress(texts, answers), [texts, answers]);

  const activeQuestionIndex = current.questions.findIndex((_, idx) => !(currentAnswers[idx] && currentAnswers[idx].checked));
  const currentAnswered = currentAnswers.filter((r) => r && r.checked).length;
  const allAnswered = progress.total > 0 && progress.done === progress.total;

  useEffect(() => {
    if (!showCompletion || !allAnswered) return;
    saveCompletionScore(window.RC_ACTIVITY_ID || '', window.RC_UNIT_ID || '', progress.percent);
  }, [showCompletion, allAnswered, progress.percent]);

  const onSelectOption = (qIdx, letter) => {
    setAnswers((prev) => {
      const rows = (prev[current.id] || []).slice();
      const row = { ...(rows[qIdx] || { selected: '', checked: false, isCorrect: false }) };
      if (row.checked) return prev;
      row.selected = letter;
      rows[qIdx] = row;
      return { ...prev, [current.id]: rows };
    });
  };

  const checkAnswer = (qIdx) => {
    const q = current.questions[qIdx];
    if (!q) return;
    setAnswers((prev) => {
      const rows = (prev[current.id] || []).slice();
      const row = { ...(rows[qIdx] || { selected: '', checked: false, isCorrect: false }) };
      if (!row.selected || row.checked) return prev;
      row.checked = true;
      row.isCorrect = row.selected === q.correct;
      rows[qIdx] = row;
      return { ...prev, [current.id]: rows };
    });
  };

  if (showCompletion && allAnswered) {
    return (
      <div style={{ minHeight: 'calc(100vh - 40px)', background: C.bg, padding: '22px', display: 'grid', placeItems: 'center' }}>
        <div style={{ width: '100%', maxWidth: 760, border: `1px solid ${C.purpleBorder}`, borderRadius: 22, padding: '24px 22px', background: 'linear-gradient(160deg,#EDE9FA,#FFF0E6)' }}>
          <div style={{ width: 72, height: 72, margin: '0 auto 14px', borderRadius: 999, background: C.white, display: 'grid', placeItems: 'center' }}>
            <svg width="44" height="44" viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle cx="12" cy="12" r="10" stroke={C.purple} strokeWidth="2"/><path d="M7 12.5l3.1 3L17 9" stroke={C.purple} strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"/></svg>
          </div>
          <h2 style={{ margin: 0, textAlign: 'center', fontFamily: 'Fredoka, sans-serif', fontSize: 28, color: C.purple }}>Reading complete!</h2>
          <div style={{ marginTop: 10, textAlign: 'center', fontFamily: 'Fredoka, sans-serif', fontSize: 52, color: C.orange }}>{progress.percent}%</div>
          <div style={{ marginTop: 8, display: 'flex', justifyContent: 'center', gap: 10, flexWrap: 'wrap' }}>
            <span style={{ background: C.white, border: `1px solid ${C.purpleBorder}`, borderRadius: 999, padding: '4px 14px', fontWeight: 700, color: C.greenDark, fontSize: 13 }}>Correct: {progress.correct}</span>
            <span style={{ background: C.white, border: `1px solid ${C.purpleBorder}`, borderRadius: 999, padding: '4px 14px', fontWeight: 700, color: C.redDark, fontSize: 13 }}>Incorrect: {progress.wrong}</span>
          </div>
          <div style={{ marginTop: 18, display: 'flex', justifyContent: 'center' }}>
            <button onClick={() => { setAnswers(initAnswers(texts)); setTextIdx(0); setShowCompletion(false); }} style={{ border: `1.5px solid ${C.purpleBorder}`, background: C.white, color: C.purple, borderRadius: 10, padding: '10px 16px', fontFamily: 'Nunito, sans-serif', fontWeight: 900, cursor: 'pointer' }}>Try again</button>
          </div>
        </div>
      </div>
    );
  }

  return (
    <div style={{ display: 'flex', flexDirection: 'column', height: 'calc(100vh - 40px)', background: C.bg }}>
      <div style={{ background: C.white, borderBottom: `1.5px solid ${C.border}`, height: 52, display: 'flex', alignItems: 'center', justifyContent: 'space-between', padding: '0 16px', position: 'relative', flexShrink: 0 }}>
        <button onClick={() => {
          const backTo = String(window.RC_RETURN_TO || '').trim();
          if (backTo) window.location.href = backTo;
          else window.history.back();
        }} style={{ width: 32, height: 32, borderRadius: 999, border: `1px solid ${C.purpleBorder}`, background: C.white, color: C.ink, cursor: 'pointer', fontSize: 16 }}>←</button>
        <div style={{ position: 'absolute', left: '50%', transform: 'translateX(-50%)', color: C.orange, fontFamily: 'Fredoka, sans-serif', fontSize: 18 }}>Reading Comprehension</div>
        <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
          <div style={{ width: 90, height: 7, borderRadius: 999, background: C.purpleBorder, overflow: 'hidden' }}>
            <div style={{ width: `${progress.total ? (progress.done / progress.total) * 100 : 0}%`, height: '100%', borderRadius: 999, background: 'linear-gradient(90deg,#F97316,#7F77DD)' }} />
          </div>
          <div style={{ fontFamily: 'Nunito, sans-serif', fontSize: 12, fontWeight: 900, color: C.purple }}>{progress.done} / {progress.total || 0}</div>
        </div>
      </div>

      {texts.length > 1 && (
        <div style={{ display: 'flex', alignItems: 'center', gap: 18, padding: '8px 14px 0', borderBottom: `1px solid ${C.border}`, background: C.white, flexShrink: 0 }}>
          {texts.map((_, idx) => (
            <button key={`tab_${idx}`} onClick={() => setTextIdx(idx)} style={{ border: 'none', background: 'transparent', cursor: 'pointer', color: idx === textIdx ? C.orange : C.purpleMid, fontFamily: 'Nunito, sans-serif', fontWeight: 900, padding: '8px 2px 10px', borderBottom: idx === textIdx ? `2.5px solid ${C.orange}` : '2.5px solid transparent', display: 'inline-flex', alignItems: 'center', gap: 8 }}>
              <span>Text {idx + 1}</span>
              {idx === textIdx && <span style={{ background: C.purpleBorder, color: C.purple, borderRadius: 6, fontSize: 10, fontWeight: 900, padding: '2px 8px' }}>In progress</span>}
            </button>
          ))}
        </div>
      )}

      <div style={{ flex: 1, minHeight: 0, display: 'grid', gridTemplateColumns: '48% 52%', gap: 0 }}>
        <div style={{ borderRight: `1px solid ${C.border}`, padding: '16px', overflowY: 'auto' }}>
          <div style={{ background: C.orangeSoft, border: `1px solid ${C.orangeBorder}`, borderRadius: 999, color: C.orangeDark, fontSize: 11, fontWeight: 900, letterSpacing: '.08em', textTransform: 'uppercase', padding: '3px 14px', width: 'fit-content' }}>Reading passage</div>
          <h2 style={{ margin: '12px 0 6px', fontFamily: 'Fredoka, sans-serif', fontSize: 30, color: C.orange }}>{current.title || 'Untitled text'}</h2>
          <div style={{ fontSize: 11, color: C.purpleMid, fontFamily: 'Nunito, sans-serif', fontWeight: 700 }}>{current.genre || 'Informative text'} · {current.wordCount || countWords(current.body)} words · Read carefully</div>
          <div style={{ height: 1.5, background: C.border, margin: '14px 0' }} />
          <div style={{ color: C.ink, fontFamily: 'Nunito, sans-serif', fontSize: 13.5, lineHeight: 1.75, fontWeight: 500 }}>
            {(splitParagraphs(current.body).length ? splitParagraphs(current.body) : ['No passage text yet.']).map((p, idx) => (
              <p key={`p_${idx}`} style={{ margin: '0 0 14px' }}>{p}</p>
            ))}
          </div>
          {!!current.vocab.length && (
            <div style={{ background: '#F9F8FF', border: `1px solid ${C.purpleBorder}`, borderRadius: 12, padding: '12px 14px', marginTop: 16 }}>
              <div style={{ fontFamily: 'Nunito, sans-serif', fontSize: 11, fontWeight: 900, color: C.purple, textTransform: 'uppercase', marginBottom: 8 }}>Vocabulary help</div>
              {current.vocab.map((entry, idx) => (
                <div key={`v_${idx}`} style={{ display: 'grid', gridTemplateColumns: '16px 1fr', gap: 10, alignItems: 'start', marginBottom: 8 }}>
                  <div style={{ width: 16, height: 16, borderRadius: 999, background: C.purple, color: C.white, fontSize: 10, fontWeight: 900, display: 'grid', placeItems: 'center' }}>{idx + 1}</div>
                  <div style={{ fontSize: 12.5, lineHeight: 1.4 }}><strong style={{ color: C.ink }}>{entry.word}</strong> <span style={{ color: C.purpleMid }}>{entry.def}</span></div>
                </div>
              ))}
            </div>
          )}
        </div>

        <div style={{ padding: '16px', overflowY: 'auto' }}>
          <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: 10 }}>
            <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
              <div style={{ width: 28, height: 28, borderRadius: 10, background: C.purpleBorder, color: C.purple, display: 'grid', placeItems: 'center', fontWeight: 900 }}>?</div>
              <div style={{ fontFamily: 'Fredoka, sans-serif', fontSize: 15, color: C.purple }}>Questions</div>
            </div>
            <div style={{ textAlign: 'right', fontSize: 11, color: C.purpleMid, fontWeight: 700 }}>{current.questions.length} questions · {current.questions.length ? Math.round(100 / current.questions.length) : 0} pts each</div>
          </div>

          {current.questions.map((q, qIdx) => {
            const row = currentAnswers[qIdx] || { selected: '', checked: false, isCorrect: false };
            const unlocked = qIdx === 0 || (currentAnswers[qIdx - 1] && currentAnswers[qIdx - 1].checked);
            const active = qIdx === activeQuestionIndex && unlocked;
            const done = !!row.checked;
            return (
              <div key={q.id} style={{ background: C.white, border: `1.5px solid ${active ? C.purple : C.purpleBorder}`, borderRadius: 20, padding: '16px 18px', marginBottom: 12, boxShadow: active ? '0 4px 20px rgba(127,119,221,.13)' : 'none', opacity: unlocked ? (done ? 0.85 : 1) : 0.4, pointerEvents: unlocked ? 'auto' : 'none' }}>
                <div style={{ display: 'flex', alignItems: 'center', gap: 10, marginBottom: 10 }}>
                  <div style={{ width: 22, height: 22, borderRadius: 999, background: C.orangeSoft, border: `1px solid ${C.orangeBorder}`, color: C.orangeDark, fontSize: 10, fontWeight: 900, display: 'grid', placeItems: 'center' }}>{qIdx + 1}</div>
                  <div style={{ fontSize: 13, fontWeight: 700, color: C.ink, lineHeight: 1.5 }}>{q.stem || `Question ${qIdx + 1}`}</div>
                </div>

                {!unlocked && <div style={{ marginBottom: 8, fontSize: 11, color: C.purpleMid, fontWeight: 800 }}>Answer Q{qIdx} to unlock</div>}

                <div style={{ display: 'flex', flexDirection: 'column', gap: 6 }}>
                  {q.options.map((opt, optIdx) => {
                    const letter = LETTERS[optIdx];
                    const selected = row.selected === letter;
                    const isCorrect = row.checked && q.correct === letter;
                    const isWrong = row.checked && selected && q.correct !== letter;
                    const borderColor = isCorrect ? C.green : (isWrong ? C.red : (selected ? C.purple : C.purpleBorder));
                    const bg = isCorrect ? C.greenSoft : (isWrong ? C.redSoft : (selected ? C.purpleSoft : '#F9F8FF'));
                    const color = isCorrect ? C.greenDark : (isWrong ? C.redDark : (selected ? C.purple : C.ink));
                    const badgeBg = isCorrect ? C.green : (isWrong ? C.red : (selected ? C.purple : C.purpleBorder));
                    const badgeColor = selected || isCorrect || isWrong ? C.white : C.purple;
                    return (
                      <button key={`${q.id}_${letter}`} onClick={() => onSelectOption(qIdx, letter)} style={{ display: 'flex', alignItems: 'center', gap: 10, textAlign: 'left', padding: '9px 12px', border: `1.5px solid ${borderColor}`, borderRadius: 12, fontSize: 12.5, fontWeight: 600, color, background: bg, cursor: row.checked ? 'default' : 'pointer' }}>
                        <span style={{ width: 20, height: 20, borderRadius: 6, background: badgeBg, color: badgeColor, display: 'grid', placeItems: 'center', fontSize: 10, fontWeight: 900 }}>{letter}</span>
                        <span>{opt}</span>
                      </button>
                    );
                  })}
                </div>

                {active && !row.checked && (
                  <div style={{ marginTop: 10, display: 'flex', justifyContent: 'flex-end' }}>
                    <button onClick={() => checkAnswer(qIdx)} disabled={!row.selected} style={{ background: C.purple, color: C.white, border: 'none', borderRadius: 10, padding: '8px 20px', fontFamily: 'Nunito, sans-serif', fontWeight: 900, fontSize: 13, cursor: row.selected ? 'pointer' : 'not-allowed', opacity: row.selected ? 1 : 0.45 }}>Check answer</button>
                  </div>
                )}

                {row.checked && (
                  <div style={{ marginTop: 10, background: row.isCorrect ? C.greenSoft : C.orangeSoft, borderLeft: `3px solid ${row.isCorrect ? C.green : C.orange}`, color: row.isCorrect ? C.greenDark : C.orangeDark, padding: '9px 12px', fontSize: 12, fontWeight: 700 }}>
                    <span style={{ marginRight: 8 }}>{row.isCorrect ? '✓' : '✗'}</span>
                    <span>{q.feedback || (row.isCorrect ? 'Correct answer!' : `Correct answer: ${q.correct}`)}</span>
                  </div>
                )}
              </div>
            );
          })}
        </div>
      </div>

      <div style={{ flexShrink: 0, background: C.white, borderTop: `1.5px solid ${C.border}`, height: 64, display: 'grid', gridTemplateColumns: '1fr auto 1fr', alignItems: 'center', gap: 10, padding: '0 16px' }}>
        <div>
          <button onClick={() => setTextIdx((prev) => Math.max(0, prev - 1))} disabled={textIdx === 0} style={{ border: `1.5px solid ${C.purpleBorder}`, borderRadius: 10, color: C.purple, background: C.white, padding: '8px 14px', fontWeight: 900, fontFamily: 'Nunito, sans-serif', opacity: textIdx === 0 ? 0.5 : 1, cursor: textIdx === 0 ? 'not-allowed' : 'pointer' }}>← Previous</button>
        </div>
        <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'center', gap: 8 }}>
          {texts.map((t, idx) => {
            const rows = answers[t.id] || [];
            const done = rows.length > 0 && rows.every((r) => r.checked);
            const active = idx === textIdx;
            return <span key={`dot_${t.id}`} style={{ width: active ? 14 : 10, height: active ? 14 : 10, borderRadius: 999, background: active ? C.orange : (done ? C.purple : C.purpleBorder), display: 'inline-block' }} />;
          })}
        </div>
        <div style={{ textAlign: 'right' }}>
          {textIdx < texts.length - 1 && (
            <button onClick={() => setTextIdx((prev) => Math.min(texts.length - 1, prev + 1))} style={{ border: 'none', borderRadius: 10, color: C.white, background: C.orange, padding: '8px 14px', fontWeight: 900, fontFamily: 'Nunito, sans-serif', cursor: 'pointer' }}>Next text →</button>
          )}
          {textIdx === texts.length - 1 && (
            <button onClick={() => allAnswered && setShowCompletion(true)} disabled={!allAnswered} style={{ border: 'none', borderRadius: 10, color: C.white, background: C.orange, padding: '8px 14px', fontWeight: 900, fontFamily: 'Nunito, sans-serif', cursor: allAnswered ? 'pointer' : 'not-allowed', opacity: allAnswered ? 1 : 0.45 }}>{allAnswered ? 'Finish' : 'Next text →'}</button>
          )}
        </div>
      </div>
    </div>
  );
}

function EditorView({ title, setTitle, texts, setTexts }) {
  const [collapsed, setCollapsed] = useState({});
  const [saving, setSaving] = useState(false);
  const [status, setStatus] = useState('');

  const patchText = (textId, mutator) => {
    setTexts((prev) => prev.map((t) => (t.id === textId ? mutator(t) : t)));
  };

  const addText = () => {
    setTexts((prev) => [...prev, defaultText(prev.length)]);
  };

  const deleteText = (textId) => {
    setTexts((prev) => {
      if (prev.length <= 1) return prev;
      return prev.filter((t) => t.id !== textId);
    });
  };

  const moveText = (textId, dir) => {
    setTexts((prev) => {
      const idx = prev.findIndex((t) => t.id === textId);
      if (idx < 0) return prev;
      const next = idx + dir;
      if (next < 0 || next >= prev.length) return prev;
      const copy = prev.slice();
      const tmp = copy[idx];
      copy[idx] = copy[next];
      copy[next] = tmp;
      return copy;
    });
  };

  const addVocab = (textId) => {
    patchText(textId, (t) => {
      if ((t.vocab || []).length >= 5) return t;
      return { ...t, vocab: [...(t.vocab || []), { word: '', def: '' }] };
    });
  };

  const patchVocab = (textId, idx, key, value) => {
    patchText(textId, (t) => {
      const vocab = (t.vocab || []).slice();
      vocab[idx] = { ...(vocab[idx] || { word: '', def: '' }), [key]: value };
      return { ...t, vocab };
    });
  };

  const deleteVocab = (textId, idx) => {
    patchText(textId, (t) => ({ ...t, vocab: (t.vocab || []).filter((_, i) => i !== idx) }));
  };

  const addQuestion = (textId) => {
    patchText(textId, (t) => ({
      ...t,
      questions: [...(t.questions || []), normalizeQuestion({ options: ['A) ', 'B) ', 'C) ', 'D) ' })],
    }));
  };

  const patchQuestion = (textId, qIdx, updater) => {
    patchText(textId, (t) => {
      const questions = (t.questions || []).slice();
      questions[qIdx] = updater(questions[qIdx] || normalizeQuestion());
      return { ...t, questions };
    });
  };

  const deleteQuestion = (textId, qIdx) => {
    patchText(textId, (t) => ({ ...t, questions: (t.questions || []).filter((_, i) => i !== qIdx) }));
  };

  const save = async () => {
    setSaving(true);
    setStatus('Saving...');
    try {
      await saveReadingActivity({
        activityId: window.RC_ACTIVITY_ID || '',
        unitId: window.RC_UNIT_ID || '',
        title,
        texts,
      });
      setStatus('Saved successfully.');
    } catch (e) {
      setStatus('Could not save activity.');
    } finally {
      setSaving(false);
    }
  };

  return (
    <div style={{ minHeight: 'calc(100vh - 40px)', background: C.bg, padding: '16px 18px 30px', overflowY: 'auto' }}>
      <div style={{ maxWidth: 980, margin: '0 auto' }}>
        <h2 style={{ margin: '0 0 10px', fontFamily: 'Fredoka, sans-serif', color: C.orange }}>Reading Comprehension Editor</h2>

        <div style={{ background: C.white, border: `1.5px solid ${C.purpleBorder}`, borderRadius: 16, padding: 14, marginBottom: 12 }}>
          <label style={{ display: 'block', fontWeight: 900, color: C.purple, marginBottom: 6 }}>Activity title</label>
          <input type="text" value={title} onChange={(e) => setTitle(e.target.value)} style={{ width: '100%', padding: '10px 12px', borderRadius: 10, border: `1px solid ${C.purpleBorder}`, fontFamily: 'Nunito, sans-serif' }} />
        </div>

        <div style={{ marginBottom: 12 }}>
          <button onClick={addText} style={{ border: 'none', background: C.purple, color: C.white, borderRadius: 10, padding: '10px 14px', fontWeight: 900, fontFamily: 'Nunito, sans-serif', cursor: 'pointer' }}>+ Add text</button>
        </div>

        {texts.map((t, textIdx) => {
          const isCollapsed = !!collapsed[t.id];
          return (
            <div key={t.id} style={{ background: C.white, border: `1.5px solid ${C.purpleBorder}`, borderRadius: 20, padding: 20, marginBottom: 12 }}>
              <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', gap: 8, marginBottom: 12 }}>
                <button onClick={() => setCollapsed((prev) => ({ ...prev, [t.id]: !prev[t.id] }))} style={{ border: 'none', background: 'transparent', cursor: 'pointer', fontFamily: 'Fredoka, sans-serif', color: C.purple, fontSize: 18 }}>{isCollapsed ? '▶' : '▼'} Text {textIdx + 1}: {t.title || 'Untitled'}</button>
                <div style={{ display: 'flex', gap: 6 }}>
                  <button onClick={() => moveText(t.id, -1)} style={{ border: `1px solid ${C.purpleBorder}`, background: C.white, borderRadius: 8, cursor: 'pointer' }}>↑</button>
                  <button onClick={() => moveText(t.id, 1)} style={{ border: `1px solid ${C.purpleBorder}`, background: C.white, borderRadius: 8, cursor: 'pointer' }}>↓</button>
                  <button onClick={() => deleteText(t.id)} style={{ border: `1px solid ${C.red}`, color: C.red, background: C.white, borderRadius: 8, cursor: 'pointer' }}>🗑</button>
                </div>
              </div>

              {!isCollapsed && (
                <div>
                  <div style={{ display: 'grid', gap: 10, gridTemplateColumns: '1fr 1fr 180px', marginBottom: 12 }}>
                    <div>
                      <label style={{ display: 'block', marginBottom: 4, fontWeight: 800, color: C.ink }}>Title</label>
                      <input type="text" value={t.title} onChange={(e) => patchText(t.id, (prev) => ({ ...prev, title: e.target.value }))} style={{ width: '100%', padding: '9px 10px', borderRadius: 10, border: `1px solid ${C.purpleBorder}` }} />
                    </div>
                    <div>
                      <label style={{ display: 'block', marginBottom: 4, fontWeight: 800, color: C.ink }}>Genre</label>
                      <input type="text" value={t.genre} onChange={(e) => patchText(t.id, (prev) => ({ ...prev, genre: e.target.value }))} style={{ width: '100%', padding: '9px 10px', borderRadius: 10, border: `1px solid ${C.purpleBorder}` }} />
                    </div>
                    <div>
                      <label style={{ display: 'block', marginBottom: 4, fontWeight: 800, color: C.ink }}>Word count</label>
                      <input type="number" value={t.wordCount || ''} onChange={(e) => patchText(t.id, (prev) => ({ ...prev, wordCount: Number(e.target.value || 0) }))} placeholder={`Auto: ${countWords(t.body)}`} style={{ width: '100%', padding: '9px 10px', borderRadius: 10, border: `1px solid ${C.purpleBorder}` }} />
                    </div>
                  </div>

                  <div style={{ marginBottom: 12 }}>
                    <label style={{ display: 'block', marginBottom: 4, fontWeight: 800, color: C.ink }}>Body text</label>
                    <textarea rows={10} value={t.body} onChange={(e) => patchText(t.id, (prev) => ({ ...prev, body: e.target.value }))} placeholder="Paste or type the reading passage here. Separate paragraphs with a blank line." style={{ width: '100%', padding: '10px 12px', borderRadius: 12, border: `1px solid ${C.purpleBorder}`, resize: 'vertical', fontFamily: 'Nunito, sans-serif' }} />
                  </div>

                  <div style={{ marginBottom: 12 }}>
                    <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 6 }}>
                      <strong style={{ color: C.purple }}>Vocabulary help</strong>
                      <button onClick={() => addVocab(t.id)} disabled={(t.vocab || []).length >= 5} style={{ border: 'none', background: 'transparent', color: C.purple, fontWeight: 900, cursor: 'pointer', opacity: (t.vocab || []).length >= 5 ? 0.4 : 1 }}>+ Add word</button>
                    </div>
                    {(t.vocab || []).map((v, vIdx) => (
                      <div key={`${t.id}_v_${vIdx}`} style={{ display: 'grid', gridTemplateColumns: '1fr 2fr auto', gap: 8, marginBottom: 6 }}>
                        <input type="text" value={v.word} placeholder="Word" onChange={(e) => patchVocab(t.id, vIdx, 'word', e.target.value)} style={{ padding: '9px 10px', borderRadius: 10, border: `1px solid ${C.purpleBorder}` }} />
                        <input type="text" value={v.def} placeholder="Definition" onChange={(e) => patchVocab(t.id, vIdx, 'def', e.target.value)} style={{ padding: '9px 10px', borderRadius: 10, border: `1px solid ${C.purpleBorder}` }} />
                        <button onClick={() => deleteVocab(t.id, vIdx)} style={{ border: `1px solid ${C.red}`, background: C.white, color: C.red, borderRadius: 8, cursor: 'pointer' }}>Delete</button>
                      </div>
                    ))}
                  </div>

                  <div>
                    <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 6 }}>
                      <strong style={{ color: C.purple }}>Questions</strong>
                      <button onClick={() => addQuestion(t.id)} style={{ border: 'none', background: 'transparent', color: C.purple, fontWeight: 900, cursor: 'pointer' }}>+ Add question</button>
                    </div>
                    {(t.questions || []).map((q, qIdx) => (
                      <div key={q.id} style={{ border: `1px solid ${C.purpleBorder}`, borderRadius: 12, padding: 12, marginBottom: 8, background: '#FCFBFF' }}>
                        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 6 }}>
                          <strong style={{ color: C.ink }}>Question {qIdx + 1}</strong>
                          <button onClick={() => deleteQuestion(t.id, qIdx)} style={{ border: `1px solid ${C.red}`, background: C.white, color: C.red, borderRadius: 8, cursor: 'pointer' }}>Delete question</button>
                        </div>
                        <input type="text" value={q.stem} placeholder="Stem" onChange={(e) => patchQuestion(t.id, qIdx, (prev) => ({ ...prev, stem: e.target.value }))} style={{ width: '100%', marginBottom: 8, padding: '9px 10px', borderRadius: 10, border: `1px solid ${C.purpleBorder}` }} />
                        {LETTERS.map((letter, optIdx) => (
                          <input key={`${q.id}_o_${letter}`} type="text" value={q.options[optIdx] || `${letter}) `} placeholder={`Option ${letter}`} onChange={(e) => patchQuestion(t.id, qIdx, (prev) => {
                            const options = (prev.options || []).slice();
                            options[optIdx] = e.target.value;
                            return { ...prev, options };
                          })} style={{ width: '100%', marginBottom: 6, padding: '8px 10px', borderRadius: 10, border: `1px solid ${C.purpleBorder}` }} />
                        ))}
                        <div style={{ display: 'flex', gap: 10, alignItems: 'center', marginBottom: 8 }}>
                          <span style={{ fontWeight: 800, color: C.ink }}>Correct answer:</span>
                          {LETTERS.map((letter) => (
                            <label key={`${q.id}_c_${letter}`} style={{ display: 'inline-flex', alignItems: 'center', gap: 4 }}>
                              <input type="radio" name={`${q.id}_correct`} checked={q.correct === letter} onChange={() => patchQuestion(t.id, qIdx, (prev) => ({ ...prev, correct: letter }))} /> {letter}
                            </label>
                          ))}
                        </div>
                        <input type="text" value={q.feedback} placeholder="Feedback" onChange={(e) => patchQuestion(t.id, qIdx, (prev) => ({ ...prev, feedback: e.target.value }))} style={{ width: '100%', padding: '9px 10px', borderRadius: 10, border: `1px solid ${C.purpleBorder}` }} />
                      </div>
                    ))}
                  </div>
                </div>
              )}
            </div>
          );
        })}

        <div style={{ display: 'flex', alignItems: 'center', gap: 12 }}>
          <button onClick={save} disabled={saving} style={{ border: 'none', background: C.orange, color: C.white, borderRadius: 10, padding: '10px 28px', fontFamily: 'Nunito, sans-serif', fontWeight: 900, cursor: saving ? 'not-allowed' : 'pointer', opacity: saving ? 0.6 : 1 }}>Save</button>
          <span style={{ color: C.purple, fontWeight: 800 }}>{status}</span>
        </div>
      </div>
    </div>
  );
}

function App() {
  const [title, setTitle] = useState(String(window.RC_SAVED_TITLE || 'Reading Comprehension'));
  const [texts, setTexts] = useState(() => normalizeTexts(window.RC_SAVED_TEXTS));
  const allowEditor = !!window.RC_ALLOW_EDITOR;

  if (allowEditor) {
    return <EditorView title={title} setTitle={setTitle} texts={texts} setTexts={setTexts} />;
  }
  return <PlayerView title={title} texts={texts} />;
}

ReactDOM.createRoot(document.getElementById('rc-root')).render(<App />);
</script>
<?php
$content = ob_get_clean();
render_activity_viewer('Reading Comprehension', 'fa-solid fa-book-open', $content);
