<?php
/**
 * reading_comprehension/viewer.php
 * Ruta: lessons/lessons/activities/reading_comprehension/viewer.php
 *
 * VIEWER  → estudiante ve la actividad (modo vocab o comp)
 * EDITOR  → docente edita la actividad  (?mode=edit, sesión academic_id/admin_id)
 *
 * Patron del repo: ob_start() arriba, render_activity_viewer($title, $icon, $content) abajo.
 */

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../core/_activity_viewer_template.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$activityId = isset($_GET['id'])       ? trim((string) $_GET['id'])       : '';
$unitId     = isset($_GET['unit'])     ? trim((string) $_GET['unit'])     : '';
$returnTo   = isset($_GET['return_to'])? trim((string) $_GET['return_to']): '';
$mode       = isset($_GET['mode'])     ? trim((string) $_GET['mode'])     : 'view';

$savedData  = [];
$savedTitle = 'Reading Comprehension';

/* ── helper: check column exists ── */
function rc_has_column(PDO $pdo, string $col): bool
{
    try {
        $st = $pdo->prepare("SELECT 1 FROM information_schema.columns WHERE table_schema='public' AND table_name='activities' AND column_name=:c LIMIT 1");
        $st->execute(['c' => $col]);
        return (bool) $st->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

$titleSel = "'' AS title";
if (rc_has_column($pdo, 'title')) {
    $titleSel = 'title AS title';
} elseif (rc_has_column($pdo, 'name')) {
    $titleSel = 'name AS title';
}

/* ── cargar datos guardados ── */
if ($activityId !== '') {
    $st = $pdo->prepare("SELECT data, {$titleSel} FROM activities WHERE id=? AND type='reading_comprehension' LIMIT 1");
    $st->execute([$activityId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $savedData  = json_decode((string)($row['data'] ?? ''), true) ?? [];
        $savedTitle = trim((string)($row['title'] ?? '')) !== '' ? (string)$row['title'] : $savedTitle;
    }
} elseif ($unitId !== '') {
    $st = $pdo->prepare("SELECT data, {$titleSel} FROM activities WHERE unit_id=? AND type='reading_comprehension' ORDER BY id ASC LIMIT 1");
    $st->execute([$unitId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $savedData  = json_decode((string)($row['data'] ?? ''), true) ?? [];
        $savedTitle = trim((string)($row['title'] ?? '')) !== '' ? (string)$row['title'] : $savedTitle;
    }
}

$isEditor    = ($mode === 'edit') && (isset($_SESSION['academic_id']) || isset($_SESSION['admin_id']));
$allowEditor = $isEditor ? 'true' : 'false';
$viewerTitle = $savedTitle !== '' ? $savedTitle : 'Reading Comprehension';

ob_start();
?>
<!– fonts –>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Fredoka:wght@400;600&family=Nunito:wght@400;600;700;800;900&display=swap" rel="stylesheet">

<!– react + babel –>
<script src="https://unpkg.com/react@18/umd/react.production.min.js"></script>
<script src="https://unpkg.com/react-dom@18/umd/react-dom.production.min.js"></script>
<script src="https://unpkg.com/@babel/standalone/babel.min.js"></script>

<div id="rc-root"></div>

<!– PHP → JS globals –>
<script>
window.RC_ACTIVITY_ID  = <?= json_encode($activityId) ?>;
window.RC_UNIT_ID      = <?= json_encode($unitId) ?>;
window.RC_RETURN_TO    = <?= json_encode($returnTo) ?>;
window.RC_ALLOW_EDITOR = <?= $allowEditor ?>;
window.RC_SAVED_TITLE  = <?= json_encode($savedTitle) ?>;
window.RC_SAVED_DATA   = <?= json_encode($savedData) ?>;
</script>

<script type="text/babel">
/* ================================================================
   READING COMPREHENSION — viewer.php React app
   Dos modos:
     "vocab" → el estudiante elige el significado de cada palabra highlighted
     "comp"  → el estudiante responde preguntas ABCD sobre el texto
   El docente configura TODO desde el EditorView.
   El PlayerView renderiza exactamente lo que el docente guardó.
   ================================================================ */

const { useState, useMemo } = React;

/* ── design tokens ── */
const C = {
  orange      : '#F97316',
  orangeSoft  : '#FFF0E6',
  orangeBorder: '#FCDDBF',
  orangeDark  : '#C2580A',
  purple      : '#7F77DD',
  purpleSoft  : '#F5F3FF',
  purpleBorder: '#EDE9FA',
  purpleMid   : '#9B8FCC',
  green       : '#1D9E75',
  greenSoft   : '#E1F5EE',
  greenDark   : '#085041',
  red         : '#D85A30',
  redSoft     : '#FAECE7',
  redDark     : '#4A1B0C',
  ink         : '#3D3560',
  bg          : '#F8F7FF',
  white       : '#ffffff',
  border      : '#F0EEF8',
};

const LETTERS = ['A', 'B', 'C', 'D'];

/* ── helpers ── */
const uid = (p) => `${p}_${Math.random().toString(36).slice(2,9)}_${Date.now()}`;

const countWords = (t) => String(t||'').trim().split(/\s+/).filter(Boolean).length;

const escHtml = (v) => String(v||'')
  .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')
  .replace(/"/g,'&quot;').replace(/'/g,'&#39;');

const escRe = (v) => String(v||'').replace(/[.*+?^${}()|[\]\\]/g,'\\$&');

/* ── normalizadores ── */
function normalizeWord(x = {}) {
  const d = Array.isArray(x.distractors) ? x.distractors : [];
  return {
    id         : String(x.id || uid('w')),
    word       : String(x.word || ''),
    correct    : String(x.correct || ''),
    distractors: [String(d[0]||''), String(d[1]||'')],
  };
}

function normalizeQuestion(x = {}) {
  const opts = Array.isArray(x.options) ? x.options : [];
  const c    = Number(x.correct);
  return {
    id      : String(x.id || uid('q')),
    stem    : String(x.stem || ''),
    options : [String(opts[0]||''),String(opts[1]||''),String(opts[2]||''),String(opts[3]||'')],
    correct : Number.isInteger(c) ? Math.max(0,Math.min(3,c)) : 0,
    feedback: String(x.feedback || ''),
  };
}

function normalizeText(x = {}) {
  const mode = String(x.mode||'vocab').toLowerCase()==='comp' ? 'comp' : 'vocab';
  const body = String(x.body||'');
  const wc   = Number(x.wordCount);
  return {
    id       : String(x.id || uid('t')),
    mode,
    title    : String(x.title || ''),
    genre    : String(x.genre || 'Informative text'),
    wordCount: Number.isFinite(wc) && wc > 0 ? wc : countWords(body),
    body,
    words    : (Array.isArray(x.words)     ? x.words     : []).map(normalizeWord),
    questions: (Array.isArray(x.questions) ? x.questions : []).map(normalizeQuestion),
  };
}

function normalizeDataset(raw) {
  if (raw && Array.isArray(raw.texts) && raw.texts.length) {
    return { title: String(raw.title||window.RC_SAVED_TITLE||'Reading Comprehension'), texts: raw.texts.map(normalizeText) };
  }
  return { title: String(window.RC_SAVED_TITLE||'Reading Comprehension'), texts: [normalizeText(raw||{})] };
}

/* ── highlight helpers ── */
function decorateSegment(html, terms) {
  return terms.reduce((acc, term) => {
    const pat = /\s/.test(term) ? `(${escRe(term)})` : `\\b(${escRe(term)})\\b`;
    return acc.replace(new RegExp(pat,'gi'), '<span class="rc-hl">$1</span>');
  }, html);
}

function paragraphToHtml(p, words) {
  const formatted = escHtml(p).replace(/\*\*(.+?)\*\*/g,'<strong>$1</strong>').replace(/__(.+?)__/g,'<u>$1</u>');
  const terms = (words||[]).map(w=>String(w.word||'').trim()).filter(Boolean).sort((a,b)=>b.length-a.length);
  if (!terms.length) return formatted;
  const chunks = formatted.split(/(<[^>]+>)/g);
  return chunks.map(ch => ch.startsWith('<') ? ch : decorateSegment(ch,terms)).join('');
}

/* ── vocab deck builder ── */
function buildVocabDeck(words) {
  return (words||[]).map((word, idx) => {
    const opts = [
      { text: word.correct||'', correct: true,  key:`c_${word.id}` },
      { text: word.distractors?.[0]||'', correct: false, key:`d1_${word.id}` },
      { text: word.distractors?.[1]||'', correct: false, key:`d2_${word.id}` },
    ].filter(o => o.text.trim());
    const seed = Array.from(`${word.id}_${idx}`).reduce((a,c)=>a+c.charCodeAt(0),0);
    const shuffled = opts.map((o,i)=>({...o,sk:((seed+11)*(i+3))%97})).sort((a,b)=>a.sk-b.sk).map(({sk,...r})=>r);
    return { id: word.id, word: word.word, options: shuffled };
  }).filter(x => x.word.trim() && x.options.length >= 2);
}

/* ════════════════════════════════════════════════
   TOPBAR — igual que todas las actividades del repo
   ════════════════════════════════════════════════ */
function TopBar({ done, total }) {
  const pct = total ? (done/total)*100 : 0;
  return (
    <div style={{ background:C.white, borderBottom:`1.5px solid ${C.border}`, height:52, padding:'0 16px', position:'relative', display:'flex', alignItems:'center', justifyContent:'space-between' }}>
      {/* botón back */}
      <button
        onClick={() => { const b=String(window.RC_RETURN_TO||'').trim(); if(b) window.location.href=b; else window.history.back(); }}
        aria-label="Back"
        style={{ width:32, height:32, borderRadius:999, border:`1.5px solid ${C.purpleBorder}`, background:C.white, cursor:'pointer', display:'flex', alignItems:'center', justifyContent:'center', color:C.purple, fontWeight:900, fontSize:16 }}
      >&#8592;</button>

      {/* título centrado */}
      <div style={{ position:'absolute', left:'50%', transform:'translateX(-50%)', color:C.orange, fontFamily:'Fredoka, sans-serif', fontSize:18, fontWeight:600, whiteSpace:'nowrap' }}>
        Reading Comprehension
      </div>

      {/* progreso */}
      <div style={{ display:'flex', alignItems:'center', gap:8 }}>
        <div style={{ width:90, height:7, borderRadius:999, background:C.purpleBorder, overflow:'hidden' }}>
          <div style={{ width:`${pct}%`, height:'100%', background:'linear-gradient(90deg,#F97316,#7F77DD)', borderRadius:999 }} />
        </div>
        <span style={{ fontFamily:'Nunito,sans-serif', fontWeight:900, fontSize:12, color:C.purple }}>{done} / {total}</span>
      </div>
    </div>
  );
}

/* ════════════════
   PASSAGE PANE
   ════════════════ */
function PassagePane({ text }) {
  const paragraphs = String(text.body||'').split(/\n\s*\n/).map(p=>p.trim()).filter(Boolean);
  return (
    <div style={{ borderRight:`1px solid ${C.border}`, padding:16, overflowY:'auto', height:'100%' }}>
      <div style={{ background:C.orangeSoft, border:`1px solid ${C.orangeBorder}`, borderRadius:999, color:C.orangeDark, fontSize:11, fontWeight:900, textTransform:'uppercase', letterSpacing:'.08em', padding:'3px 14px', width:'fit-content', marginBottom:10 }}>
        Reading passage
      </div>
      <h2 style={{ margin:'0 0 4px', fontFamily:'Fredoka,sans-serif', fontSize:20, color:C.orange }}>{text.title||'Untitled'}</h2>
      <div style={{ fontFamily:'Nunito,sans-serif', fontSize:11, color:C.purpleMid, fontWeight:700, marginBottom:14 }}>
        {text.genre} &middot; {text.wordCount||countWords(text.body)} words &middot; Read carefully
      </div>
      <div style={{ height:1.5, background:C.border, marginBottom:14 }} />
      <div style={{ color:C.ink, fontFamily:'Nunito,sans-serif', fontSize:14, lineHeight:1.75 }}>
        {(paragraphs.length ? paragraphs : ['No passage text yet.']).map((p,i) => (
          <p key={i} style={{ margin:'0 0 14px' }} dangerouslySetInnerHTML={{ __html: paragraphToHtml(p, text.words) }} />
        ))}
      </div>
    </div>
  );
}

/* ════════════════════════════════════
   PLAYER — MODO VOCAB
   Una pregunta por palabra highlighted.
   El docente escribió: word + correct + 2 distractors.
   El viewer arma: "What does [word] mean?" con 3 opciones shuffled.
   ════════════════════════════════════ */
function VocabPlayer({ text }) {
  const deck    = useMemo(() => buildVocabDeck(text.words), [text.words]);
  const [idx,   setIdx]   = useState(0);
  const [answers,setAnswers] = useState(() => deck.map(() => ({ sel:-1, checked:false, correct:false })));

  if (!deck.length) return <div style={{ padding:20, color:C.purpleMid, fontWeight:700 }}>No vocabulary words configured yet.</div>;

  const row  = answers[idx] || { sel:-1, checked:false, correct:false };
  const item = deck[idx];
  const done = answers.filter(a=>a.checked).length;

  const optStyle = (opt, oi) => {
    const sel  = row.sel === oi;
    const ok   = row.checked && opt.correct;
    const bad  = row.checked && sel && !opt.correct;
    return {
      textAlign:'left', display:'block', width:'100%',
      border:`1.5px solid ${ok?C.green:bad?C.red:sel?C.purple:C.purpleBorder}`,
      borderRadius:12,
      background: ok?C.greenSoft:bad?C.redSoft:sel?C.purpleSoft:'#FBFAFF',
      color: ok?C.greenDark:bad?C.redDark:C.ink,
      padding:'10px 12px', fontSize:14, fontWeight:700,
      cursor: row.checked?'default':'pointer', marginBottom:8,
      fontFamily:'Nunito,sans-serif',
    };
  };

  return (
    <>
      <TopBar done={done} total={deck.length} />
      <div style={{ flex:1, minHeight:0, display:'flex', overflow:'hidden' }}>
        {/* texto izquierda */}
        <div style={{ width:'48%', minWidth:0, height:'100%' }}><PassagePane text={text} /></div>
        {/* preguntas derecha */}
        <div style={{ width:'52%', minWidth:0, padding:16, overflowY:'auto', background:C.bg }}>
          <div style={{ background:C.white, border:`1.5px solid ${row.checked?(row.correct?C.green:C.red):C.purpleBorder}`, borderRadius:20, padding:18, boxShadow:'0 4px 20px rgba(127,119,221,.10)' }}>
            <div style={{ fontSize:11, fontWeight:900, color:C.purpleMid, textTransform:'uppercase', letterSpacing:'.06em', marginBottom:8 }}>
              Question {idx+1} of {deck.length}
            </div>
            <h3 style={{ margin:'0 0 14px', fontFamily:'Fredoka,sans-serif', fontSize:20, color:C.ink, lineHeight:1.3 }}>
              What does <span style={{ color:C.orange }}>"{item.word}"</span> mean in this context?
            </h3>
            {item.options.map((opt,oi) => (
              <button key={opt.key} style={optStyle(opt,oi)}
                onClick={() => { if (!row.checked) setAnswers(prev=>prev.map((a,i)=>i===idx?{...a,sel:oi}:a)); }}>
                {opt.text}
              </button>
            ))}
            {/* feedback */}
            {row.checked && (
              <div style={{ borderLeft:`3px solid ${row.correct?C.green:C.orange}`, background:row.correct?C.greenSoft:C.orangeSoft, color:row.correct?C.greenDark:C.orangeDark, padding:'10px 12px', fontWeight:700, fontSize:13, marginTop:4 }}>
                {row.correct ? '✓ Correct!' : '✗ Not quite — review the passage and try the next one.'}
              </div>
            )}
            {/* botones nav */}
            <div style={{ display:'flex', justifyContent:'space-between', gap:8, marginTop:14 }}>
              <button onClick={()=>setIdx(p=>Math.max(0,p-1))} disabled={idx===0}
                style={{ border:`1.5px solid ${C.purpleBorder}`, borderRadius:10, background:C.white, color:C.purple, padding:'8px 14px', fontWeight:900, fontFamily:'Nunito,sans-serif', opacity:idx===0?.45:1, cursor:idx===0?'default':'pointer' }}>
                ← Previous
              </button>
              {!row.checked
                ? <button onClick={()=>{ if(row.sel<0)return; const ok=!!item.options[row.sel]?.correct; setAnswers(prev=>prev.map((a,i)=>i===idx?{...a,checked:true,correct:ok}:a)); }}
                    disabled={row.sel<0}
                    style={{ border:'none', borderRadius:10, background:C.purple, color:C.white, padding:'8px 18px', fontWeight:900, fontFamily:'Nunito,sans-serif', opacity:row.sel<0?.45:1, cursor:row.sel<0?'default':'pointer' }}>
                    Check answer
                  </button>
                : <button onClick={()=>setIdx(p=>Math.min(deck.length-1,p+1))} disabled={idx>=deck.length-1}
                    style={{ border:'none', borderRadius:10, background:C.orange, color:C.white, padding:'8px 18px', fontWeight:900, fontFamily:'Nunito,sans-serif', opacity:idx>=deck.length-1?.45:1, cursor:idx>=deck.length-1?'default':'pointer' }}>
                    {idx>=deck.length-1 ? '✓ Completed' : 'Next →'}
                  </button>
              }
            </div>
          </div>
        </div>
      </div>
    </>
  );
}

/* ════════════════════════════════════
   PLAYER — MODO COMP
   Preguntas ABCD sobre el texto.
   El docente escribió: stem + 4 opciones + correct (índice 0-3) + feedback.
   ════════════════════════════════════ */
function CompPlayer({ text }) {
  const questions = useMemo(() => (text.questions||[]).filter(q=>q.options.some(o=>String(o||'').trim())), [text.questions]);
  const [idx, setIdx]     = useState(0);
  const [answers,setAnswers] = useState(() => questions.map(()=>({ sel:-1, checked:false, correct:false })));

  if (!questions.length) return <div style={{ padding:20, color:C.purpleMid, fontWeight:700 }}>No comprehension questions configured yet.</div>;

  const q   = questions[idx];
  const row = answers[idx] || { sel:-1, checked:false, correct:false };
  const done = answers.filter(a=>a.checked).length;

  const optStyle = (oi) => {
    const sel = row.sel===oi;
    const ok  = row.checked && q.correct===oi;
    const bad = row.checked && sel && q.correct!==oi;
    return {
      display:'flex', alignItems:'center', gap:10, textAlign:'left', width:'100%',
      border:`1.5px solid ${ok?C.green:bad?C.red:sel?C.purple:C.purpleBorder}`,
      borderRadius:12,
      background: ok?C.greenSoft:bad?C.redSoft:sel?C.purpleSoft:'#FBFAFF',
      color: ok?C.greenDark:bad?C.redDark:C.ink,
      padding:'10px 12px', fontSize:14, fontWeight:700,
      cursor: row.checked?'default':'pointer', marginBottom:8,
      fontFamily:'Nunito,sans-serif',
    };
  };

  const letterStyle = (oi) => {
    const sel = row.sel===oi;
    const ok  = row.checked && q.correct===oi;
    return {
      width:20, height:20, borderRadius:6, display:'grid', placeItems:'center',
      fontSize:10, fontWeight:900, flexShrink:0,
      background: ok?C.green:sel?C.purple:C.purpleBorder,
      color: (ok||sel)?C.white:C.purple,
    };
  };

  return (
    <>
      <TopBar done={done} total={questions.length} />
      <div style={{ flex:1, minHeight:0, display:'flex', overflow:'hidden' }}>
        {/* texto izquierda */}
        <div style={{ width:'48%', minWidth:0, height:'100%' }}><PassagePane text={text} /></div>
        {/* preguntas derecha */}
        <div style={{ width:'52%', minWidth:0, padding:16, overflowY:'auto', background:C.bg }}>
          <div style={{ background:C.white, border:`1.5px solid ${row.checked?(row.correct?C.green:C.red):C.purpleBorder}`, borderRadius:20, padding:18, boxShadow:'0 4px 20px rgba(127,119,221,.10)' }}>
            <div style={{ fontSize:11, fontWeight:900, color:C.purpleMid, textTransform:'uppercase', letterSpacing:'.06em', marginBottom:8 }}>
              Question {idx+1} of {questions.length}
            </div>
            <h3 style={{ margin:'0 0 14px', fontFamily:'Fredoka,sans-serif', fontSize:20, color:C.ink, lineHeight:1.3 }}>{q.stem||`Question ${idx+1}`}</h3>
            {q.options.map((opt,oi) => (
              <button key={`${q.id}_${oi}`} style={optStyle(oi)}
                onClick={()=>{ if(!row.checked) setAnswers(prev=>prev.map((a,i)=>i===idx?{...a,sel:oi}:a)); }}>
                <span style={letterStyle(oi)}>{LETTERS[oi]}</span>
                <span>{opt}</span>
              </button>
            ))}
            {/* feedback */}
            {row.checked && (
              <div style={{ borderLeft:`3px solid ${row.correct?C.green:C.orange}`, background:row.correct?C.greenSoft:C.orangeSoft, color:row.correct?C.greenDark:C.orangeDark, padding:'10px 12px', fontWeight:700, fontSize:13, marginTop:4 }}>
                {q.feedback || (row.correct ? '✓ Correct!' : `✗ Correct answer: ${LETTERS[q.correct]}`)}
              </div>
            )}
            {/* botones nav */}
            <div style={{ display:'flex', justifyContent:'space-between', gap:8, marginTop:14 }}>
              <button onClick={()=>setIdx(p=>Math.max(0,p-1))} disabled={idx===0}
                style={{ border:`1.5px solid ${C.purpleBorder}`, borderRadius:10, background:C.white, color:C.purple, padding:'8px 14px', fontWeight:900, fontFamily:'Nunito,sans-serif', opacity:idx===0?.45:1, cursor:idx===0?'default':'pointer' }}>
                ← Previous
              </button>
              {!row.checked
                ? <button onClick={()=>{ if(row.sel<0)return; setAnswers(prev=>prev.map((a,i)=>i===idx?{...a,checked:true,correct:row.sel===q.correct}:a)); }}
                    disabled={row.sel<0}
                    style={{ border:'none', borderRadius:10, background:C.purple, color:C.white, padding:'8px 18px', fontWeight:900, fontFamily:'Nunito,sans-serif', opacity:row.sel<0?.45:1, cursor:row.sel<0?'default':'pointer' }}>
                    Check answer
                  </button>
                : <button onClick={()=>setIdx(p=>Math.min(questions.length-1,p+1))} disabled={idx>=questions.length-1}
                    style={{ border:'none', borderRadius:10, background:C.orange, color:C.white, padding:'8px 18px', fontWeight:900, fontFamily:'Nunito,sans-serif', opacity:idx>=questions.length-1?.45:1, cursor:idx>=questions.length-1?'default':'pointer' }}>
                    {idx>=questions.length-1 ? '✓ Completed' : 'Next →'}
                  </button>
              }
            </div>
          </div>
        </div>
      </div>
    </>
  );
}

/* ════════════════
   PLAYER VIEW
   ════════════════ */
function PlayerView({ data }) {
  const texts   = data.texts||[];
  const [tIdx, setTIdx] = useState(0);
  const current = texts[tIdx] || texts[0] || normalizeText();
  return (
    <div style={{ display:'flex', flexDirection:'column', height:'100%', background:C.bg }}>
      {texts.length > 1 && (
        <div style={{ display:'flex', alignItems:'center', gap:18, padding:'8px 14px 0', borderBottom:`1px solid ${C.border}`, background:C.white, flexShrink:0 }}>
          {texts.map((_,i) => (
            <button key={i} onClick={()=>setTIdx(i)}
              style={{ border:'none', background:'transparent', cursor:'pointer', color:i===tIdx?C.orange:C.purpleMid, fontFamily:'Nunito,sans-serif', fontWeight:900, padding:'8px 2px 10px', borderBottom:i===tIdx?`2.5px solid ${C.orange}`:'2.5px solid transparent', display:'inline-flex', alignItems:'center', gap:8 }}>
              Text {i+1}
              {i===tIdx && <span style={{ background:C.purpleBorder, color:C.purple, borderRadius:6, fontSize:10, fontWeight:900, padding:'2px 8px' }}>In progress</span>}
            </button>
          ))}
        </div>
      )}
      {/* La actividad correcta según el modo que el docente eligió */}
      <div style={{ flex:1, minHeight:0, display:'flex', flexDirection:'column' }}>
        {current.mode === 'comp'
          ? <CompPlayer  text={current} />
          : <VocabPlayer text={current} />
        }
      </div>
    </div>
  );
}

/* ════════════════════════════════════
   EDITOR VIEW
   Solo visible cuando RC_ALLOW_EDITOR=true (sesión docente/admin).
   Todo lo que el docente escribe aquí → se guarda en DB → aparece en PlayerView.
   ════════════════════════════════════ */

/* ── estilos reutilizables del editor ── */
const eStyles = {
  card  : { background:C.white, border:`1.5px solid ${C.purpleBorder}`, borderRadius:20, overflow:'hidden', marginBottom:16 },
  head  : { padding:'14px 18px', borderBottom:`1px solid ${C.border}`, display:'flex', alignItems:'center', gap:10 },
  body  : { padding:18 },
  label : { display:'block', marginBottom:6, fontWeight:900, color:C.purple, fontFamily:'Nunito,sans-serif', fontSize:12, textTransform:'uppercase', letterSpacing:'.06em' },
  input : { width:'100%', padding:'9px 12px', borderRadius:10, border:`1.5px solid ${C.purpleBorder}`, fontFamily:'Nunito,sans-serif', fontWeight:700, fontSize:14, color:C.ink, background:'#FBFAFF', outline:'none' },
  textarea: { width:'100%', padding:'10px 12px', borderRadius:12, border:`1.5px solid ${C.purpleBorder}`, fontFamily:'Nunito,sans-serif', fontWeight:600, fontSize:14, color:C.ink, background:'#FBFAFF', outline:'none', resize:'vertical' },
  sectionIcon: (bg,color) => ({ width:34, height:34, borderRadius:12, background:bg, display:'grid', placeItems:'center', color, fontWeight:900, fontSize:18, flexShrink:0 }),
  sectionTitle: (color) => ({ fontFamily:'Fredoka,sans-serif', color, fontSize:22 }),
  badge : { background:C.purpleSoft, color:C.purple, borderRadius:8, padding:'2px 10px', fontWeight:900, fontSize:12 },
  infoBox: { marginBottom:12, background:C.purpleSoft, border:`1px solid ${C.purpleBorder}`, borderRadius:12, padding:'10px 14px', color:C.purple, fontWeight:800, fontSize:13 },
  addBtn: { width:'100%', border:`1.5px solid ${C.purpleBorder}`, background:C.white, color:C.purple, borderRadius:12, padding:'10px 14px', fontWeight:900, fontFamily:'Nunito,sans-serif', fontSize:14, cursor:'pointer', marginTop:4 },
  delBtn: { border:`1.5px solid ${C.red}`, borderRadius:8, background:C.white, color:C.red, padding:'4px 10px', fontWeight:900, fontFamily:'Nunito,sans-serif', fontSize:12, cursor:'pointer' },
};

function EditorView({ data, setData }) {
  const [status,   setStatus]   = useState('');
  const [saving,   setSaving]   = useState(false);
  const [previewing, setPreviewing] = useState(false);

  /* siempre editamos el primer (y único) text */
  const text    = data.texts[0] || normalizeText();
  const patchTx = (fn) => setData(prev => ({ ...prev, texts: [fn(prev.texts[0]||normalizeText())] }));

  /* ── save ── */
  const save = async () => {
    setSaving(true); setStatus('Saving…');
    try {
      const payload = new URLSearchParams();
      payload.set('unit', window.RC_UNIT_ID||'');
      payload.set('type', 'reading_comprehension');
      payload.set('content_json', JSON.stringify({
        mode     : text.mode,
        title    : text.title,
        genre    : text.genre,
        wordCount: text.wordCount || countWords(text.body),
        body     : text.body,
        words    : text.words,
        questions: text.questions,
      }));
      const res = await fetch('../../core/save_activity.php', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8'}, credentials:'same-origin', body:payload.toString() });
      if (!res.ok) throw new Error('HTTP '+res.status);
      setStatus('✓ Saved successfully');
    } catch(e) {
      setStatus('⚠ Could not save — ' + e.message);
    } finally {
      setSaving(false);
    }
  };

  /* ── preview inline ── */
  if (previewing) return (
    <div style={{ height:'100%', display:'flex', flexDirection:'column' }}>
      <div style={{ background:C.white, borderBottom:`1px solid ${C.border}`, padding:'10px 16px', flexShrink:0 }}>
        <button onClick={()=>setPreviewing(false)} style={{ ...eStyles.addBtn, width:'auto', padding:'8px 16px' }}>← Back to editor</button>
      </div>
      <div style={{ flex:1, minHeight:0 }}><PlayerView data={data} /></div>
    </div>
  );

  /* ── live preview html ── */
  const previewHtml = paragraphToHtml(text.body||'Type passage text above to see highlights.', text.words);

  return (
    <div style={{ height:'100%', overflowY:'auto', background:C.bg, padding:20 }}>
      <div style={{ maxWidth:1100, margin:'0 auto' }}>

        {/* ── 1. MODO ── */}
        <div style={eStyles.card}>
          <div style={eStyles.head}>
            <div style={eStyles.sectionIcon(C.orangeSoft, C.orangeDark)}>⚙</div>
            <span style={eStyles.sectionTitle(C.orange)}>Reading Comprehension</span>
            <span style={{ marginLeft:'auto', ...eStyles.badge }}>Edit mode</span>
          </div>
          <div style={eStyles.body}>
            <label style={eStyles.label}>Activity title</label>
            <input style={{ ...eStyles.input, marginBottom:16, fontSize:18 }} value={text.title}
              onChange={e=>patchTx(t=>({...t,title:e.target.value}))} placeholder="e.g. Unit 3 — Reading: Migration" />

            <label style={eStyles.label}>Activity mode — choose one</label>
            <div style={{ display:'grid', gridTemplateColumns:'1fr 1fr', gap:12 }}>
              {/* Vocab card */}
              <button onClick={()=>patchTx(t=>({...t,mode:'vocab'}))}
                style={{ textAlign:'left', border:`2px solid ${text.mode==='vocab'?C.orange:C.purpleBorder}`, borderRadius:16, background:text.mode==='vocab'?'#FFF5EE':'#F6F4FD', padding:16, cursor:'pointer' }}>
                <div style={{ fontFamily:'Fredoka,sans-serif', color:C.orange, fontSize:20, marginBottom:4 }}>🔤 Vocabulary meaning</div>
                <div style={{ color:C.purpleMid, fontWeight:700, fontSize:13 }}>Students choose the correct meaning of each highlighted word (1 correct + 2 distractors).</div>
                {text.mode==='vocab' && <div style={{ marginTop:8, color:C.orange, fontWeight:900, fontSize:12 }}>✓ Selected</div>}
              </button>
              {/* Comp card */}
              <button onClick={()=>patchTx(t=>({...t,mode:'comp'}))}
                style={{ textAlign:'left', border:`2px solid ${text.mode==='comp'?C.purple:C.purpleBorder}`, borderRadius:16, background:text.mode==='comp'?C.purpleSoft:'#F6F4FD', padding:16, cursor:'pointer' }}>
                <div style={{ fontFamily:'Fredoka,sans-serif', color:C.purple, fontSize:20, marginBottom:4 }}>📖 Reading comprehension</div>
                <div style={{ color:C.purpleMid, fontWeight:700, fontSize:13 }}>Students answer multiple-choice questions A B C D about the passage.</div>
                {text.mode==='comp' && <div style={{ marginTop:8, color:C.purple, fontWeight:900, fontSize:12 }}>✓ Selected</div>}
              </button>
            </div>
          </div>
        </div>

        {/* ── 2. PASSAGE ── */}
        <div style={eStyles.card}>
          <div style={eStyles.head}>
            <div style={eStyles.sectionIcon(C.orangeSoft, C.orangeDark)}>📄</div>
            <span style={eStyles.sectionTitle(C.orange)}>Passage</span>
          </div>
          <div style={eStyles.body}>
            <div style={{ display:'grid', gridTemplateColumns:'1fr 1fr 180px', gap:12, marginBottom:12 }}>
              <div>
                <label style={eStyles.label}>Passage title</label>
                <input style={eStyles.input} value={text.title}
                  onChange={e=>patchTx(t=>({...t,title:e.target.value}))} placeholder="e.g. The Mystery of Migration" />
              </div>
              <div>
                <label style={eStyles.label}>Genre / type</label>
                <input style={eStyles.input} value={text.genre}
                  onChange={e=>patchTx(t=>({...t,genre:e.target.value}))} placeholder="e.g. Informative text" />
              </div>
              <div>
                <label style={eStyles.label}>Word count</label>
                <input style={eStyles.input} type="number" value={text.wordCount||''}
                  onChange={e=>patchTx(t=>({...t,wordCount:Number(e.target.value||0)}))} placeholder="auto" />
              </div>
            </div>
            <label style={eStyles.label}>Passage body text</label>
            <textarea rows={8} style={eStyles.textarea} value={text.body}
              onChange={e=>patchTx(t=>({...t,body:e.target.value}))}
              placeholder="Paste or type the reading passage here. Separate paragraphs with a blank line." />
          </div>
        </div>

        {/* ── 3. PALABRAS HIGHLIGHTED ── */}
        <div style={eStyles.card}>
          <div style={eStyles.head}>
            <div style={eStyles.sectionIcon(C.orangeSoft, C.orangeDark)}>🖊</div>
            <span style={eStyles.sectionTitle(C.orange)}>Highlighted vocabulary words</span>
            <span style={{ marginLeft:'auto', ...eStyles.badge }}>{text.words.length} words</span>
          </div>
          <div style={eStyles.body}>

            {/* info */}
            <div style={eStyles.infoBox}>
              {text.mode==='vocab'
                ? '📌 Each word added here will appear highlighted in orange in the passage. Students must choose its correct meaning from 3 options.'
                : '📌 Words added here appear highlighted in orange in the passage — for reference. In Comprehension mode, questions are defined separately below.'}
            </div>

            {/* live preview */}
            <div style={{ border:`1.5px solid ${C.orangeBorder}`, background:'#FFF9F4', borderRadius:14, padding:'12px 14px', marginBottom:16 }}>
              <div style={{ fontSize:11, fontWeight:900, color:C.purpleMid, textTransform:'uppercase', letterSpacing:'.06em', marginBottom:8 }}>
                Live preview — highlighted words as students see them
              </div>
              <div style={{ color:C.ink, lineHeight:1.75, fontSize:14 }} dangerouslySetInnerHTML={{ __html: previewHtml }} />
            </div>

            {/* word cards */}
            {text.words.map((w, wi) => (
              <div key={w.id} style={{ border:`1.5px solid ${C.purpleBorder}`, borderRadius:16, padding:14, marginBottom:12 }}>
                <div style={{ display:'flex', justifyContent:'space-between', alignItems:'center', marginBottom:10 }}>
                  <span style={{ fontFamily:'Fredoka,sans-serif', color:C.orange, fontSize:18 }}>Word {wi+1}{w.word ? ` — ${w.word}` : ''}</span>
                  <button style={eStyles.delBtn} onClick={()=>patchTx(t=>({...t,words:t.words.filter((_,i)=>i!==wi)}))}>✕ Remove</button>
                </div>
                {/* fila 1: word + correct */}
                <div style={{ display:'grid', gridTemplateColumns:'1fr 2fr', gap:10, marginBottom:10 }}>
                  <div>
                    <label style={eStyles.label}>Word (exact as in text)</label>
                    <input style={eStyles.input} value={w.word}
                      onChange={e=>patchTx(t=>({...t,words:t.words.map((x,i)=>i===wi?{...x,word:e.target.value}:x)}))}
                      placeholder="e.g. migration" />
                  </div>
                  <div>
                    <label style={eStyles.label}>✓ Correct meaning</label>
                    <input style={{ ...eStyles.input, borderColor:C.green, background:C.greenSoft }} value={w.correct}
                      onChange={e=>patchTx(t=>({...t,words:t.words.map((x,i)=>i===wi?{...x,correct:e.target.value}:x)}))}
                      placeholder="Correct definition" />
                  </div>
                </div>
                {/* fila 2: distractors */}
                <div style={{ display:'grid', gridTemplateColumns:'1fr 1fr', gap:10 }}>
                  <div>
                    <label style={eStyles.label}>✗ Wrong option 1</label>
                    <input style={{ ...eStyles.input, borderColor:C.red, background:C.redSoft }} value={w.distractors[0]||''}
                      onChange={e=>patchTx(t=>({...t,words:t.words.map((x,i)=>i===wi?{...x,distractors:[e.target.value,x.distractors?.[1]||'']}:x)}))}
                      placeholder="Incorrect definition" />
                  </div>
                  <div>
                    <label style={eStyles.label}>✗ Wrong option 2</label>
                    <input style={{ ...eStyles.input, borderColor:C.red, background:C.redSoft }} value={w.distractors[1]||''}
                      onChange={e=>patchTx(t=>({...t,words:t.words.map((x,i)=>i===wi?{...x,distractors:[x.distractors?.[0]||'',e.target.value]}:x)}))}
                      placeholder="Incorrect definition" />
                  </div>
                </div>
              </div>
            ))}

            <button style={eStyles.addBtn} onClick={()=>patchTx(t=>({...t,words:[...t.words,normalizeWord()]}))}>
              + Add vocabulary word
            </button>
          </div>
        </div>

        {/* ── 4. PREGUNTAS COMP (solo visible en modo comp) ── */}
        {text.mode === 'comp' && (
          <div style={eStyles.card}>
            <div style={eStyles.head}>
              <div style={eStyles.sectionIcon(C.purpleSoft, C.purple)}>❓</div>
              <span style={eStyles.sectionTitle(C.purple)}>Comprehension questions</span>
              <span style={{ marginLeft:'auto', ...eStyles.badge }}>{text.questions.length} questions</span>
            </div>
            <div style={eStyles.body}>
              <div style={eStyles.infoBox}>
                📌 Add questions about the passage. Click the letter badge (A B C D) to mark the correct answer. The feedback text appears after the student answers.
              </div>

              {text.questions.map((q, qi) => (
                <div key={q.id} style={{ border:`1.5px solid ${C.purpleBorder}`, borderRadius:16, padding:14, marginBottom:12 }}>
                  <div style={{ display:'flex', justifyContent:'space-between', alignItems:'center', marginBottom:10 }}>
                    <span style={{ fontFamily:'Fredoka,sans-serif', color:C.purple, fontSize:18 }}>Question {qi+1}</span>
                    <button style={eStyles.delBtn} onClick={()=>patchTx(t=>({...t,questions:t.questions.filter((_,i)=>i!==qi)}))}>✕ Remove</button>
                  </div>

                  <label style={eStyles.label}>Question stem</label>
                  <input style={{ ...eStyles.input, marginBottom:12 }} value={q.stem}
                    onChange={e=>patchTx(t=>({...t,questions:t.questions.map((x,i)=>i===qi?{...x,stem:e.target.value}:x)}))}
                    placeholder="Type the question here…" />

                  <label style={eStyles.label}>Options — click the letter to mark the correct answer</label>
                  {q.options.map((opt, oi) => {
                    const isCorrect = q.correct===oi;
                    return (
                      <div key={oi} style={{ display:'grid', gridTemplateColumns:'36px 1fr', gap:8, marginBottom:8, alignItems:'center' }}>
                        {/* letra clicable */}
                        <button
                          onClick={()=>patchTx(t=>({...t,questions:t.questions.map((x,i)=>i===qi?{...x,correct:oi}:x)}))}
                          title="Click to mark as correct"
                          style={{ width:34, height:34, borderRadius:8, border:`2px solid ${isCorrect?C.green:C.purpleBorder}`, background:isCorrect?C.green:C.white, color:isCorrect?C.white:C.purple, fontWeight:900, fontSize:13, cursor:'pointer' }}>
                          {LETTERS[oi]}
                        </button>
                        <input style={{ ...eStyles.input, borderColor:isCorrect?C.green:C.purpleBorder, background:isCorrect?C.greenSoft:'#FBFAFF' }}
                          value={opt}
                          onChange={e=>patchTx(t=>({...t,questions:t.questions.map((x,i)=>i===qi?{...x,options:x.options.map((o,j)=>j===oi?e.target.value:o)}:x)}))}
                          placeholder={`Option ${LETTERS[oi]}`} />
                      </div>
                    );
                  })}

                  <div style={{ display:'flex', alignItems:'center', gap:8, margin:'6px 0 10px', fontSize:12, fontWeight:900 }}>
                    <span style={{ background:C.greenSoft, border:`1px solid ${C.green}`, borderRadius:999, padding:'3px 12px', color:C.greenDark }}>
                      ✓ Correct: {LETTERS[q.correct]}
                    </span>
                  </div>

                  <label style={eStyles.label}>Feedback shown after student answers</label>
                  <input style={eStyles.input} value={q.feedback}
                    onChange={e=>patchTx(t=>({...t,questions:t.questions.map((x,i)=>i===qi?{...x,feedback:e.target.value}:x)}))}
                    placeholder="Explain the correct answer…" />
                </div>
              ))}

              <button style={eStyles.addBtn} onClick={()=>patchTx(t=>({...t,questions:[...t.questions,normalizeQuestion()]}))}>
                + Add comprehension question
              </button>
            </div>
          </div>
        )}

        {/* ── 5. SAVE BAR ── */}
        <div style={{ display:'grid', gridTemplateColumns:'auto 1fr auto', gap:12, alignItems:'center', position:'sticky', bottom:0, background:C.bg, paddingBottom:16 }}>
          <button onClick={()=>setPreviewing(true)}
            style={{ border:`1.5px solid ${C.purpleBorder}`, borderRadius:12, background:C.white, color:C.purple, padding:'12px 18px', fontWeight:900, fontFamily:'Nunito,sans-serif', fontSize:14, cursor:'pointer' }}>
            👁 Preview as student
          </button>
          <span style={{ color:C.purple, fontWeight:900, fontSize:13, textAlign:'center' }}>{status}</span>
          <button onClick={save} disabled={saving}
            style={{ border:'none', borderRadius:12, background:C.orange, color:C.white, padding:'12px 22px', fontWeight:900, fontFamily:'Nunito,sans-serif', fontSize:14, cursor:saving?'default':'pointer', opacity:saving?.6:1 }}>
            {saving ? 'Saving…' : '💾 Save activity'}
          </button>
        </div>

      </div>
    </div>
  );
}

/* ════════════════
   ROOT APP
   ════════════════ */
function App() {
  const [data, setData] = useState(() => normalizeDataset(window.RC_SAVED_DATA || {}));
  return window.RC_ALLOW_EDITOR
    ? <EditorView data={data} setData={setData} />
    : <PlayerView data={data} />;
}

ReactDOM.createRoot(document.getElementById('rc-root')).render(<App />);
</script>

<style>
/* palabra highlighted en el texto */
.rc-hl {
  color: #C2580A;
  font-weight: 900;
  border-bottom: 2px solid #F97316;
  background: #FFF0E6;
  border-radius: 3px;
  padding: 0 2px;
}
</style>

<?php
$content = ob_get_clean();
render_activity_viewer($viewerTitle, '📖', $content);
