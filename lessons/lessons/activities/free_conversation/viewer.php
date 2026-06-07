<?php
/**
 * Free Conversation — viewer.php
 * Follows the roleplay_kids/viewer.php pattern exactly.
 * Provides a free multi-turn conversation activity with Claude AI,
 * per-turn feedback (grammar, vocabulary, fluency), session timer,
 * vocabulary tracker, and a completed screen with aggregate scores.
 */
if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../core/_activity_viewer_template.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

$activityId = isset($_GET['id'])        ? trim((string) $_GET['id'])        : '';
$mode       = isset($_GET['mode'])      ? trim((string) $_GET['mode'])      : '';
$returnTo   = isset($_GET['return_to']) ? trim((string) $_GET['return_to']) : '';

$isEditorAuth = !empty($_SESSION['admin_logged'])
    || !empty($_SESSION['academic_logged'])
    || !empty($_SESSION['teacher_logged'])
    || !empty($_SESSION['teacher_id'])
    || !empty($_SESSION['teacher_username']);

if ($mode === 'edit' && !$isEditorAuth) {
    header('Location: /lessons/lessons/academic/login.php');
    exit;
}

$allowEditor = ($mode === 'edit');
$savedData   = null;

if ($activityId !== '') {
    $stmt = $pdo->prepare("SELECT data FROM activities WHERE id = :id LIMIT 1");
    $stmt->execute(['id' => $activityId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row && !empty($row['data'])) {
        $parsed = json_decode($row['data'], true);
        if (is_array($parsed)) {
            $savedData = $parsed;
        }
    }
}

$defaults = [
    'title'             => 'Free Conversation',
    'topic'             => "Let's have a friendly conversation in English!",
    'conversation_mode' => 'chat_feedback',
    'difficulty'        => 'intermediate',
    'timeLimit'         => 5,
    'agentName'         => 'Alex',
    'teacherVoiceId'    => 'nzFihrBIvB34imQBuxub',
    'targetVocab'       => [],
    'hints'             => ['Tell me about your day', 'What are your hobbies?', 'Describe your family'],
    'targetLanguage'    => 'English',
];

$payload = array_merge($defaults, $savedData ?? []);

ob_start();
?>
<link href="https://fonts.googleapis.com/css2?family=Fredoka:wght@400;600&family=Nunito:wght@400;600;700&display=swap" rel="stylesheet">
<style>
#fc-root * { box-sizing: border-box; margin: 0; padding: 0; }
#fc-root { font-family: 'Nunito', sans-serif; flex: 1; min-height: 0; display: flex; flex-direction: column; }
@keyframes fc-bounce { 0%,100%{ transform: translateY(0); } 50%{ transform: translateY(-6px); } }
@keyframes fc-fade-in { from{ opacity:0; transform:translateY(8px); } to{ opacity:1; transform:translateY(0); } }
.fc-bubble { animation: fc-fade-in 0.25s ease; }
.fc-dot { animation: fc-bounce 1s infinite; border-radius: 50%; background: #9B8FCC; width: 8px; height: 8px; display: inline-block; }
.fc-dot:nth-child(2) { animation-delay: 0.15s; }
.fc-dot:nth-child(3) { animation-delay: 0.30s; }
.fc-input:focus { outline: none; box-shadow: 0 0 0 3px rgba(127,119,221,0.2); }
.fc-btn:hover { opacity: 0.88; }
.fc-btn:active { transform: scale(0.97); }
</style>

<div id="fc-root" style="flex:1;min-height:0;overflow-y:auto;"></div>

<script crossorigin src="https://unpkg.com/react@18/umd/react.production.min.js"></script>
<script crossorigin src="https://unpkg.com/react-dom@18/umd/react-dom.production.min.js"></script>
<script src="https://unpkg.com/@babel/standalone/babel.min.js"></script>

<script>
window.FC_ACTIVITY_ID  = <?= json_encode($activityId) ?>;
window.FC_RETURN_TO    = <?= json_encode($returnTo) ?>;
window.FC_PAYLOAD      = <?= json_encode($payload) ?>;
window.FC_ALLOW_EDITOR = <?= json_encode($allowEditor) ?>;
</script>

<script type="text/babel">
const { useState, useRef, useEffect, useMemo } = React;

// ── DESIGN TOKENS ─────────────────────────────────────────────
const C = {
  orange:      '#F97316', orangeLight: '#FFF0E6', orangeMid: '#bf521a',
  purple:      '#7F77DD', purpleLight: '#EDE9FA', purpleMid: '#9B8FCC',
  green:       '#F0FDF4', greenText:   '#166534', redText:   '#991B1B',
  bg:          '#ffffff', bgCard:      '#F9F8FF', cardBorder: '#EDE9FA',
  textMain:    '#1e1b4b', textSub:     '#6B7280', white:      '#ffffff',
};

const FC_VOICES = [
  { id: 'nzFihrBIvB34imQBuxub', label: 'Adult Male (Josh)' },
  { id: 'NoOVOzCQFLOvtsMoNcdT', label: 'Adult Female (Lily)' },
  { id: 'Nggzl2QAXh3OijoXD116', label: 'Child (Candy)' },
];

// ── UTILITIES ─────────────────────────────────────────────────
function formatTime(s) {
  const m = Math.floor(s / 60);
  return m + ':' + String(s % 60).padStart(2, '0');
}

function buildActivitySaveUrl(returnTo, activityId, activityType, percent, errors, total) {
  const safeReturn = String(returnTo || '').trim();
  const safeId     = String(activityId || '').trim();
  if (!safeReturn || !safeId) return '';
  const joiner = safeReturn.indexOf('?') !== -1 ? '&' : '?';
  return safeReturn
    + joiner + 'activity_percent=' + encodeURIComponent(String(percent))
    + '&activity_errors='  + encodeURIComponent(String(errors))
    + '&activity_total='   + encodeURIComponent(String(total))
    + '&activity_id='      + encodeURIComponent(safeId)
    + '&activity_type='    + encodeURIComponent(String(activityType || 'free_conversation'));
}

function persistScoreSilently(targetUrl) {
  if (!targetUrl) return Promise.resolve(false);
  return fetch(targetUrl, { method: 'GET', credentials: 'same-origin', cache: 'no-store' })
    .then(function(r) { return !!(r && r.ok); }).catch(function() { return false; });
}

function navigateToReturn(url) {
  if (!url) return;
  try { if (window.top && window.top !== window.self) { window.top.location.href = url; return; } } catch (e) {}
  window.location.href = url;
}

function extractJson(text) {
  try { return JSON.parse(text.trim()); } catch (e) {}
  const m = text.match(/\{[\s\S]*\}/);
  if (m) { try { return JSON.parse(m[0]); } catch (e2) {} }
  return null;
}

// ── LANGUAGE CODE MAP ─────────────────────────────────────────
const LANG_CODES = {
  'English':    'en-US',
  'Spanish':    'es-ES',
  'French':     'fr-FR',
  'Portuguese': 'pt-BR',
  'German':     'de-DE',
  'Italian':    'it-IT',
  'Chinese':    'zh-CN',
  'Japanese':   'ja-JP',
  'Korean':     'ko-KR',
  'Arabic':     'ar-SA',
};

// ── CLAUDE API ────────────────────────────────────────────────
async function callClaude(userMessage, history, payload) {
  const mode = payload.conversation_mode || 'chat_feedback';
  const vocab = (payload.targetVocab || []).filter(Boolean);
  const lang = payload.targetLanguage || 'English';

  const modeInstr = {
    chat_feedback: 'Have a natural, friendly conversation about the topic.',
    voice_only:    'Have a natural conversation. Keep responses brief (1-3 sentences).',
    debate:        "Take a respectful opposing position. Challenge the student's arguments to help them practice persuasion.",
    interview:     'Conduct a structured interview. Ask one focused follow-up question after each answer.',
  }[mode] || 'Have a natural conversation.';

  const diffInstr = {
    beginner:     'Use simple vocabulary and short sentences. Be very encouraging.',
    intermediate: 'Use natural language. Correct major errors that affect clarity.',
    advanced:     'Use rich vocabulary and complex grammar. Correct subtle errors too.',
  }[payload.difficulty || 'intermediate'] || '';

  const vocabLine = vocab.length > 0 ? '\nTarget vocabulary to encourage: ' + vocab.join(', ') : '';

  const system = 'You are ' + (payload.agentName || 'Alex') + ', a friendly ' + lang + ' conversation partner.\n\n'
    + 'IMPORTANT: Conduct the ENTIRE conversation in ' + lang + '. All your replies must be written in ' + lang + '.\n\n'
    + 'TOPIC: ' + (payload.topic || 'General conversation') + '\n'
    + 'DIFFICULTY: ' + (payload.difficulty || 'intermediate') + ' — ' + diffInstr
    + vocabLine + '\n\n'
    + modeInstr + '\n\n'
    + 'Respond ONLY with valid JSON (no markdown, no extra text):\n'
    + '{\n'
    + '  "response": "<your conversational reply in ' + lang + '>",\n'
    + '  "feedback": {\n'
    + '    "corrected": "<corrected student message in ' + lang + ', or same if already correct>",\n'
    + '    "grammar": <0-100>,\n'
    + '    "vocabulary": <0-100>,\n'
    + '    "fluency": <0-100>,\n'
    + '    "total": <0-100>,\n'
    + '    "corrections": ["<correction 1>"],\n'
    + '    "praise": "<one specific positive observation>",\n'
    + '    "tips": ["<tip 1>", "<tip 2>"]\n'
    + '  }\n'
    + '}';

  const messages = history.concat([{ role: 'user', content: userMessage }]);

  const resp = await fetch('claude_proxy.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ model: 'claude-sonnet-4-5', max_tokens: 1024, system: system, messages: messages }),
  });

  if (!resp.ok) {
    const errData = await resp.json().catch(function() { return {}; });
    const errMsg = typeof errData.error === 'string'
      ? errData.error
      : (errData.error && errData.error.message) || 'API error ' + resp.status;
    throw new Error(errMsg);
  }

  const data = await resp.json();
  const rawText = ((data && data.content && data.content[0] && data.content[0].text) || '').trim();
  const parsed  = extractJson(rawText);

  if (!parsed || typeof parsed.response !== 'string') {
    return {
      response: rawText || 'Could you try that again?',
      feedback: { corrected: userMessage, grammar: 70, vocabulary: 70, fluency: 70, total: 70, corrections: [], praise: 'Keep practicing!', tips: [] },
    };
  }

  return parsed;
}

// ── TTS ───────────────────────────────────────────────────────
async function playTTS(text, voiceId) {
  const form = new FormData();
  form.append('text', text.substring(0, 500));
  form.append('voice_id', voiceId || 'nzFihrBIvB34imQBuxub');
  const resp = await fetch('tts.php', { method: 'POST', body: form });
  if (!resp.ok) throw new Error('TTS error');
  const blob = await resp.blob();
  const url  = URL.createObjectURL(blob);
  const audio = new Audio(url);
  audio.onended = function() { URL.revokeObjectURL(url); };
  return audio.play();
}

// ── SCORE RING ────────────────────────────────────────────────
function ScoreRing({ score, size }) {
  size = size || 88;
  const r = 32, circ = 2 * Math.PI * r;
  const dash = (Math.min(100, Math.max(0, score || 0)) / 100) * circ;
  return (
    <svg width={size} height={size} viewBox="0 0 80 80" style={{ display: 'block', margin: '0 auto' }}>
      <circle cx="40" cy="40" r={r} fill="none" stroke={C.purpleLight} strokeWidth="8" />
      <circle cx="40" cy="40" r={r} fill="none" stroke={C.purple} strokeWidth="8"
        strokeDasharray={dash + ' ' + circ}
        strokeDashoffset={circ / 4}
        strokeLinecap="round"
        style={{ transition: 'stroke-dasharray 0.5s ease' }}
      />
      <text x="40" y="40" textAnchor="middle" dominantBaseline="middle"
        fontSize="15" fontWeight="700" fill={C.purple} fontFamily="Nunito, sans-serif">
        {score || 0}%
      </text>
    </svg>
  );
}

// ── FEEDBACK CARD (shown below each user message) ─────────────
function FeedbackCard({ feedback, userText }) {
  const [open, setOpen] = useState(true);
  if (!feedback) return null;

  const hasDiff = feedback.corrected &&
    feedback.corrected.toLowerCase().trim() !== (userText || '').toLowerCase().trim();

  function scoreColor(s) { return s >= 80 ? C.greenText : s >= 60 ? '#92400e' : C.redText; }
  function scoreBg(s)    { return s >= 80 ? C.green     : s >= 60 ? '#FEF3C7' : '#FEE2E2'; }

  return (
    <div style={{ background: C.purpleLight, borderRadius: 10, padding: '8px 12px', fontSize: 12.5, border: '1px solid ' + C.cardBorder }}>
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', cursor: 'pointer', userSelect: 'none' }}
        onClick={function() { setOpen(function(o) { return !o; }); }}>
        <div style={{ display: 'flex', gap: 5, flexWrap: 'wrap' }}>
          {['grammar','vocabulary','fluency'].map(function(k) {
            return (
              <span key={k} style={{ padding: '2px 7px', borderRadius: 20, background: scoreBg(feedback[k] || 0), color: scoreColor(feedback[k] || 0), fontWeight: 700, fontSize: 11 }}>
                {k.slice(0,3).toUpperCase()} {feedback[k] || 0}
              </span>
            );
          })}
        </div>
        <span style={{ color: C.purpleMid, fontSize: 11, marginLeft: 6 }}>{open ? '▲' : '▼'}</span>
      </div>
      {open && (
        <div style={{ marginTop: 8 }}>
          {hasDiff && (
            <div style={{ marginBottom: 6, padding: '5px 8px', background: C.white, borderRadius: 7, borderLeft: '3px solid ' + C.purple }}>
              <span style={{ color: C.purpleMid, fontWeight: 700, marginRight: 4 }}>✏️</span>
              <span style={{ color: C.textMain }}>{feedback.corrected}</span>
            </div>
          )}
          {feedback.corrections && feedback.corrections.length > 0 && (
            <ul style={{ paddingLeft: 16, marginBottom: 6, color: C.textMain }}>
              {feedback.corrections.map(function(c, i) { return <li key={i}>{c}</li>; })}
            </ul>
          )}
          {feedback.praise && (
            <div style={{ color: C.greenText, marginBottom: 4 }}>⭐ {feedback.praise}</div>
          )}
          {feedback.tips && feedback.tips.length > 0 && (
            <div style={{ background: C.orangeLight, borderRadius: 7, padding: '5px 8px', color: C.orangeMid }}>
              💡 {feedback.tips[0]}
            </div>
          )}
        </div>
      )}
    </div>
  );
}

// ── MESSAGE BUBBLE ────────────────────────────────────────────
function MessageBubble({ msg, agentName, voiceId, showFeedback }) {
  const isUser = msg.role === 'user';
  const [speaking, setSpeaking] = useState(false);

  async function handleSpeak() {
    if (speaking) return;
    setSpeaking(true);
    try { await playTTS(msg.content, voiceId); } catch (e) {}
    setSpeaking(false);
  }

  return (
    <div className="fc-bubble" style={{ display: 'flex', flexDirection: 'column', alignItems: isUser ? 'flex-end' : 'flex-start', marginBottom: 6 }}>
      <div style={{ fontSize: 11, color: C.textSub, marginBottom: 2, marginLeft: isUser ? 0 : 4, marginRight: isUser ? 4 : 0 }}>
        {isUser ? 'You' : (agentName || 'Alex')}
      </div>
      <div style={{ display: 'flex', gap: 4, alignItems: 'flex-end', flexDirection: isUser ? 'row-reverse' : 'row' }}>
        <div style={{
          maxWidth: '72%', padding: '9px 14px',
          borderRadius: isUser ? '18px 18px 4px 18px' : '18px 18px 18px 4px',
          background: isUser ? C.orange : C.bgCard,
          color: isUser ? C.white : C.textMain,
          border: isUser ? 'none' : '1px solid ' + C.cardBorder,
          fontSize: 14, lineHeight: 1.55,
        }}>
          {msg.content}
        </div>
        {!isUser && (
          <button onClick={handleSpeak} title="Listen" style={{ background: 'none', border: 'none', cursor: 'pointer', fontSize: 16, opacity: speaking ? 0.5 : 0.65, padding: 2 }}>
            {speaking ? '🔊' : '🔈'}
          </button>
        )}
      </div>
      {isUser && showFeedback && msg.feedback && (
        <div style={{ maxWidth: '72%', marginTop: 4 }}>
          <FeedbackCard feedback={msg.feedback} userText={msg.content} />
        </div>
      )}
    </div>
  );
}

// ── TYPING INDICATOR ──────────────────────────────────────────
function TypingDots({ agentName }) {
  return (
    <div className="fc-bubble" style={{ display: 'flex', alignItems: 'center', gap: 6, marginBottom: 6 }}>
      <span style={{ fontSize: 11, color: C.textSub }}>{agentName || 'Alex'}</span>
      <div style={{ background: C.bgCard, border: '1px solid ' + C.cardBorder, borderRadius: '18px 18px 18px 4px', padding: '10px 16px', display: 'flex', gap: 5, alignItems: 'center' }}>
        <span className="fc-dot" /><span className="fc-dot" /><span className="fc-dot" />
      </div>
    </div>
  );
}

// ── HINT PILLS ────────────────────────────────────────────────
function HintPills({ hints, onSelect }) {
  if (!hints || !hints.length) return null;
  return (
    <div style={{ padding: '8px 0 16px' }}>
      <div style={{ fontSize: 12, color: C.textSub, marginBottom: 8 }}>💬 Get started with a topic:</div>
      <div style={{ display: 'flex', flexWrap: 'wrap', gap: 8 }}>
        {hints.filter(Boolean).map(function(h, i) {
          return (
            <button key={i} onClick={function() { onSelect(h); }} style={{
              background: C.purpleLight, color: C.purple, border: '1px solid ' + C.cardBorder,
              borderRadius: 20, padding: '6px 16px', fontSize: 13, cursor: 'pointer', fontFamily: 'Nunito, sans-serif',
            }}>
              {h}
            </button>
          );
        })}
      </div>
    </div>
  );
}

// ── EDITOR VIEW ───────────────────────────────────────────────
function EditorView({ payload, activityId, onSaved, onPreview }) {
  const [d, setD] = useState(Object.assign({}, payload));
  const [saving, setSaving] = useState(false);
  const [statusMsg, setStatusMsg] = useState(null);
  const [vocabInput, setVocabInput] = useState((payload.targetVocab || []).join(', '));

  function set(key, val) { setD(function(prev) { return Object.assign({}, prev, { [key]: val }); }); }

  async function handleSave() {
    if (!activityId) { setStatusMsg({ type: 'err', text: 'No activity ID — cannot save.' }); return; }
    setSaving(true); setStatusMsg(null);
    try {
      const resp = await fetch('save.php', {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(Object.assign({ id: activityId }, d)),
      });
      const json = await resp.json();
      if (resp.ok && json.ok) {
        setStatusMsg({ type: 'ok', text: '✅ Saved successfully!' });
        if (onSaved) onSaved(d);
      } else {
        setStatusMsg({ type: 'err', text: json.error || 'Save failed.' });
      }
    } catch (e) {
      setStatusMsg({ type: 'err', text: 'Network error.' });
    } finally {
      setSaving(false);
    }
  }

  const fieldStyle = { width: '100%', padding: '8px 12px', border: '1.5px solid ' + C.cardBorder, borderRadius: 8, fontSize: 14, fontFamily: 'Nunito, sans-serif', outline: 'none', background: C.white };
  const labelStyle = { fontSize: 12, fontWeight: 700, color: C.textSub, marginBottom: 4, display: 'block', textTransform: 'uppercase', letterSpacing: 0.5 };
  const col2 = { display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 16, marginBottom: 18 };

  return (
    <div style={{ minHeight: '100vh', background: '#F8F7FF', fontFamily: 'Nunito, sans-serif' }}>
      {/* Top bar */}
      <div style={{ background: C.orange, padding: '12px 20px', display: 'flex', alignItems: 'center', gap: 10 }}>
        <span style={{ fontSize: 20 }}>💬</span>
        <span style={{ color: C.white, fontFamily: 'Fredoka, sans-serif', fontSize: 20, fontWeight: 600 }}>
          Free Conversation — Editor
        </span>
      </div>

      {/* Form */}
      <div style={{ maxWidth: 700, margin: '28px auto', padding: '0 16px 40px' }}>
        <div style={{ background: C.white, borderRadius: 16, padding: '28px 28px 24px', boxShadow: '0 2px 12px rgba(0,0,0,0.07)' }}>

          {/* Title */}
          <div style={{ marginBottom: 18 }}>
            <label style={labelStyle}>Activity Title</label>
            <input style={fieldStyle} value={d.title || ''} onChange={function(e) { set('title', e.target.value); }} placeholder="e.g. Daily Life Conversation" />
          </div>

          {/* Topic */}
          <div style={{ marginBottom: 18 }}>
            <label style={labelStyle}>Conversation Topic / Prompt</label>
            <textarea style={Object.assign({}, fieldStyle, { minHeight: 80, resize: 'vertical' })}
              value={d.topic || ''} onChange={function(e) { set('topic', e.target.value); }}
              placeholder="e.g. Let's talk about your daily routine and weekend plans" />
          </div>

          {/* Target Language */}
          <div style={{ marginBottom: 18 }}>
            <label style={labelStyle}>Target Language</label>
            <select style={fieldStyle} value={d.targetLanguage || 'English'} onChange={function(e) { set('targetLanguage', e.target.value); }}>
              <option value="English">English</option>
              <option value="Spanish">Spanish</option>
              <option value="French">French</option>
              <option value="Portuguese">Portuguese</option>
              <option value="German">German</option>
              <option value="Italian">Italian</option>
              <option value="Chinese">Chinese</option>
              <option value="Japanese">Japanese</option>
              <option value="Korean">Korean</option>
              <option value="Arabic">Arabic</option>
            </select>
          </div>

          {/* Mode + Difficulty */}
          <div style={col2}>
            <div>
              <label style={labelStyle}>Conversation Mode</label>
              <select style={fieldStyle} value={d.conversation_mode || 'chat_feedback'} onChange={function(e) { set('conversation_mode', e.target.value); }}>
                <option value="chat_feedback">Chat + Feedback</option>
                <option value="voice_only">Voice Only</option>
                <option value="debate">Debate</option>
                <option value="interview">Interview</option>
              </select>
            </div>
            <div>
              <label style={labelStyle}>Difficulty</label>
              <select style={fieldStyle} value={d.difficulty || 'intermediate'} onChange={function(e) { set('difficulty', e.target.value); }}>
                <option value="beginner">Beginner</option>
                <option value="intermediate">Intermediate</option>
                <option value="advanced">Advanced</option>
              </select>
            </div>
          </div>

          {/* Time + Agent Name */}
          <div style={col2}>
            <div>
              <label style={labelStyle}>Time Limit</label>
              <select style={fieldStyle} value={d.timeLimit || 5} onChange={function(e) { set('timeLimit', parseInt(e.target.value, 10)); }}>
                <option value={3}>3 minutes</option>
                <option value={5}>5 minutes</option>
                <option value={10}>10 minutes</option>
                <option value={15}>15 minutes</option>
              </select>
            </div>
            <div>
              <label style={labelStyle}>AI Agent Name</label>
              <input style={fieldStyle} value={d.agentName || ''} onChange={function(e) { set('agentName', e.target.value); }} placeholder="e.g. Alex" />
            </div>
          </div>

          {/* Voice */}
          <div style={{ marginBottom: 18 }}>
            <label style={labelStyle}>Agent Voice (ElevenLabs TTS)</label>
            <select style={fieldStyle} value={d.teacherVoiceId || 'nzFihrBIvB34imQBuxub'} onChange={function(e) { set('teacherVoiceId', e.target.value); }}>
              {FC_VOICES.map(function(v) { return <option key={v.id} value={v.id}>{v.label}</option>; })}
            </select>
          </div>

          {/* Target Vocab */}
          <div style={{ marginBottom: 18 }}>
            <label style={labelStyle}>Target Vocabulary (comma-separated)</label>
            <input style={fieldStyle}
              value={vocabInput}
              onChange={function(e) {
                var raw = e.target.value;
                setVocabInput(raw);
                set('targetVocab', raw.split(',').map(function(s) { return s.trim(); }).filter(Boolean));
              }}
              placeholder="e.g. routine, schedule, commute, hobby" />
          </div>

          {/* Hints */}
          <div style={{ marginBottom: 26 }}>
            <label style={labelStyle}>Conversation Starters (up to 3)</label>
            {[0, 1, 2].map(function(i) {
              return (
                <input key={i} style={Object.assign({}, fieldStyle, { marginBottom: 6 })}
                  value={((d.hints || [])[i]) || ''}
                  onChange={function(e) {
                    var hints = (d.hints || ['', '', '']).slice(0, 3);
                    while (hints.length < 3) hints.push('');
                    hints[i] = e.target.value;
                    set('hints', hints);
                  }}
                  placeholder={'Starter ' + (i + 1)}
                />
              );
            })}
          </div>

          {/* Buttons */}
          <div style={{ display: 'flex', gap: 10 }}>
            <button onClick={handleSave} disabled={saving} className="fc-btn" style={{
              background: C.purple, color: C.white, border: 'none', borderRadius: 10, padding: '10px 24px',
              fontSize: 14, fontWeight: 700, cursor: saving ? 'not-allowed' : 'pointer', fontFamily: 'Nunito, sans-serif',
            }}>
              {saving ? 'Saving…' : '💾 Save Activity'}
            </button>
            <button onClick={onPreview} className="fc-btn" style={{
              background: C.orange, color: C.white, border: 'none', borderRadius: 10, padding: '10px 24px',
              fontSize: 14, fontWeight: 700, cursor: 'pointer', fontFamily: 'Nunito, sans-serif',
            }}>
              ▶ Preview
            </button>
          </div>

          {statusMsg && (
            <div style={{ marginTop: 12, padding: '8px 14px', borderRadius: 8, background: statusMsg.type === 'ok' ? C.green : '#FEE2E2', color: statusMsg.type === 'ok' ? C.greenText : C.redText, fontWeight: 600 }}>
              {statusMsg.text}
            </div>
          )}
        </div>
      </div>
    </div>
  );
}

// ── PLAYER VIEW ───────────────────────────────────────────────
function PlayerView({ payload, activityId, returnTo, onComplete, onBack }) {
  const [messages, setMessages]         = useState([]);
  const [history, setHistory]           = useState([]);
  const [inputText, setInputText]       = useState('');
  const [isLoading, setIsLoading]       = useState(false);
  const [timeLeft, setTimeLeft]         = useState((payload.timeLimit || 5) * 60);
  const [sessionEnded, setSessionEnded] = useState(false);
  const [vocabUsed, setVocabUsed]       = useState(new Set());
  const [feedbacks, setFeedbacks]       = useState([]);
  const [latestTips, setLatestTips]     = useState([]);
  const [isListening, setIsListening]   = useState(false);

  const chatRef          = useRef(null);
  const inputRef         = useRef(null);
  const recognitionRef   = useRef(null);
  const sessionEndedRef  = useRef(false);
  const feedbacksRef     = useRef([]);
  const vocabUsedRef     = useRef(new Set());

  const showFeedback = payload.conversation_mode !== 'voice_only';

  const avgScore    = useMemo(function() { return feedbacks.length ? Math.round(feedbacks.reduce(function(s, f) { return s + (f.total || 0); }, 0) / feedbacks.length) : 0; }, [feedbacks]);
  const avgGrammar  = useMemo(function() { return feedbacks.length ? Math.round(feedbacks.reduce(function(s, f) { return s + (f.grammar || 0); }, 0) / feedbacks.length) : 0; }, [feedbacks]);
  const avgVocab    = useMemo(function() { return feedbacks.length ? Math.round(feedbacks.reduce(function(s, f) { return s + (f.vocabulary || 0); }, 0) / feedbacks.length) : 0; }, [feedbacks]);
  const avgFluency  = useMemo(function() { return feedbacks.length ? Math.round(feedbacks.reduce(function(s, f) { return s + (f.fluency || 0); }, 0) / feedbacks.length) : 0; }, [feedbacks]);

  // Auto-scroll on new messages
  useEffect(function() {
    if (chatRef.current) chatRef.current.scrollTop = chatRef.current.scrollHeight;
  }, [messages, isLoading]);

  // Timer countdown
  useEffect(function() {
    var interval = setInterval(function() {
      setTimeLeft(function(t) {
        if (t <= 1) {
          clearInterval(interval);
          if (!sessionEndedRef.current) { sessionEndedRef.current = true; setSessionEnded(true); }
          return 0;
        }
        return t - 1;
      });
    }, 1000);
    return function() { clearInterval(interval); };
  }, []);

  // AI greeting on mount
  useEffect(function() {
    var active = true;
    setIsLoading(true);
    var greetMsg = '[START CONVERSATION] Please greet the student warmly (1-2 sentences) and introduce the topic: "' + (payload.topic || 'general conversation') + '"';
    callClaude(greetMsg, [], payload).then(function(result) {
      if (!active) return;
      setMessages([{ role: 'assistant', content: result.response }]);
      setHistory([
        { role: 'user', content: '[START]' },
        { role: 'assistant', content: result.response },
      ]);
    }).catch(function() {
      if (!active) return;
      var greeting = 'Hi! I\'m ' + (payload.agentName || 'Alex') + '. Ready to practice ' + (payload.targetLanguage || 'English') + '? ' + (payload.topic || '');
      setMessages([{ role: 'assistant', content: greeting.trim() }]);
      setHistory([
        { role: 'user', content: '[START]' },
        { role: 'assistant', content: greeting.trim() },
      ]);
    }).finally(function() {
      if (active) setIsLoading(false);
    });
    return function() { active = false; };
  }, []);

  // Fire onComplete when session ends
  useEffect(function() {
    if (!sessionEnded) return;
    var fbs = feedbacksRef.current;
    var vu  = vocabUsedRef.current;
    var correct = fbs.filter(function(f) { return (f.total || 0) >= 70; }).length;
    var errors  = fbs.filter(function(f) { return (f.total || 0) < 70; }).length;
    var total   = fbs.length;
    var avg     = total > 0 ? Math.round(fbs.reduce(function(s, f) { return s + (f.total || 0); }, 0) / total) : 0;

    var saveUrl = buildActivitySaveUrl(returnTo, activityId, 'free_conversation', avg, errors, total);
    if (saveUrl) persistScoreSilently(saveUrl);

    onComplete({
      grammar:    total > 0 ? Math.round(fbs.reduce(function(s, f) { return s + (f.grammar || 0); }, 0) / total) : 0,
      vocabulary: total > 0 ? Math.round(fbs.reduce(function(s, f) { return s + (f.vocabulary || 0); }, 0) / total) : 0,
      fluency:    total > 0 ? Math.round(fbs.reduce(function(s, f) { return s + (f.fluency || 0); }, 0) / total) : 0,
      total: avg, correct: correct, errors: errors, turnCount: total,
      vocabUsed: Array.from(vu),
    });
  }, [sessionEnded]);

  function doEndSession() {
    if (sessionEndedRef.current) return;
    sessionEndedRef.current = true;
    setSessionEnded(true);
  }

  async function sendMessage(text) {
    var userText = (typeof text === 'string' ? text : inputText).trim();
    if (!userText || isLoading || sessionEnded) return;

    setInputText('');
    setIsLoading(true);

    // Track vocab usage
    var lower = userText.toLowerCase();
    var nextVocab = new Set(vocabUsedRef.current);
    (payload.targetVocab || []).forEach(function(w) {
      if (lower.indexOf(w.toLowerCase()) !== -1) nextVocab.add(w.toLowerCase());
    });
    vocabUsedRef.current = nextVocab;
    setVocabUsed(new Set(nextVocab));

    // Optimistic user bubble
    setMessages(function(prev) { return prev.concat([{ role: 'user', content: userText }]); });

    try {
      var result = await callClaude(userText, history, payload);

      if (showFeedback && result.feedback) {
        setMessages(function(prev) {
          var updated = prev.slice();
          updated[updated.length - 1] = Object.assign({}, updated[updated.length - 1], { feedback: result.feedback });
          return updated.concat([{ role: 'assistant', content: result.response }]);
        });
        var newFbs = feedbacksRef.current.concat([result.feedback]);
        feedbacksRef.current = newFbs;
        setFeedbacks(newFbs);
        if (result.feedback.tips && result.feedback.tips.length > 0) setLatestTips(result.feedback.tips);
      } else {
        setMessages(function(prev) { return prev.concat([{ role: 'assistant', content: result.response }]); });
        if (result.feedback) {
          var newFbs2 = feedbacksRef.current.concat([result.feedback]);
          feedbacksRef.current = newFbs2;
          setFeedbacks(newFbs2);
        }
      }

      setHistory(function(prev) {
        return prev.concat([
          { role: 'user', content: userText },
          { role: 'assistant', content: result.response },
        ]);
      });

      // Auto-play TTS in voice-only mode
      if (payload.conversation_mode === 'voice_only') {
        playTTS(result.response, payload.teacherVoiceId).catch(function() {});
      }
    } catch (e) {
      setMessages(function(prev) { return prev.concat([{ role: 'assistant', content: '⚠️ ' + (e.message || 'Connection error. Please try again.'), isError: true }]); });
    } finally {
      setIsLoading(false);
      if (inputRef.current) inputRef.current.focus();
    }
  }

  function toggleMic() {
    var SR = window.SpeechRecognition || window.webkitSpeechRecognition;
    if (!SR) { alert('Speech recognition is not supported in this browser. Try Chrome or Edge.'); return; }
    if (isListening) {
      if (recognitionRef.current) recognitionRef.current.stop();
      setIsListening(false);
      return;
    }
    var rec = new SR();
    rec.lang = LANG_CODES[payload.targetLanguage] || 'en-US';
    rec.continuous = false;
    rec.interimResults = false;
    rec.onstart = function() { setIsListening(true); };
    rec.onend   = function() { setIsListening(false); };
    rec.onerror = function() { setIsListening(false); };
    rec.onresult = function(e) { var t = e.results[0][0].transcript; sendMessage(t); };
    recognitionRef.current = rec;
    rec.start();
  }

  var timerColor = timeLeft <= 30 ? '#EF4444' : timeLeft <= 60 ? C.orangeLight : C.white;
  var targetVocab = (payload.targetVocab || []).filter(Boolean);

  return (
    <div style={{ display: 'flex', flexDirection: 'column', height: '100%', minHeight: 0, fontFamily: 'Nunito, sans-serif' }}>
      {/* Top bar */}
      <div style={{ background: C.orange, padding: '9px 14px', display: 'flex', alignItems: 'center', gap: 8, flexShrink: 0 }}>
        {onBack && (
          <button onClick={onBack} title="Back to Editor" style={{ background: 'none', border: 'none', color: C.white, cursor: 'pointer', fontSize: 18, padding: '0 4px', lineHeight: 1 }}>←</button>
        )}
        <span style={{ color: C.white, fontFamily: 'Fredoka, sans-serif', fontSize: 18, fontWeight: 600, flex: 1, whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis' }}>
          💬 {payload.title || 'Free Conversation'}
        </span>
        <span style={{ background: 'rgba(255,255,255,0.25)', color: C.white, borderRadius: 10, padding: '2px 9px', fontSize: 11, fontWeight: 700, textTransform: 'uppercase', letterSpacing: 0.4, flexShrink: 0 }}>
          { { chat_feedback: 'Chat+Feedback', voice_only: 'Voice', debate: 'Debate', interview: 'Interview' }[payload.conversation_mode || 'chat_feedback'] }
          {payload.targetLanguage && payload.targetLanguage !== 'English' ? ' · ' + payload.targetLanguage : ''}
        </span>
        <span style={{ color: timerColor, fontWeight: 700, fontSize: 15, fontFamily: 'monospace', flexShrink: 0 }}>⏱ {formatTime(timeLeft)}</span>
        <button onClick={doEndSession} disabled={sessionEnded} className="fc-btn" style={{
          background: C.white, color: C.orange, border: 'none', borderRadius: 8, padding: '5px 12px',
          fontSize: 12, fontWeight: 700, cursor: sessionEnded ? 'not-allowed' : 'pointer', fontFamily: 'Nunito, sans-serif', flexShrink: 0,
        }}>
          End Session
        </button>
      </div>

      {/* Main area */}
      <div style={{ display: 'flex', flex: 1, minHeight: 0, overflow: 'hidden' }}>
        {/* Chat panel */}
        <div ref={chatRef} style={{ flex: 1, overflowY: 'auto', padding: '14px 16px', display: 'flex', flexDirection: 'column' }}>
          {messages.length === 0 && !isLoading && (
            <HintPills hints={payload.hints} onSelect={sendMessage} />
          )}
          {messages.map(function(msg, i) {
            return <MessageBubble key={i} msg={msg} agentName={payload.agentName || 'Alex'} voiceId={payload.teacherVoiceId} showFeedback={showFeedback} />;
          })}
          {isLoading && <TypingDots agentName={payload.agentName || 'Alex'} />}
        </div>

        {/* Sidebar */}
        <div style={{ width: 210, background: C.bgCard, borderLeft: '1px solid ' + C.cardBorder, padding: 12, display: 'flex', flexDirection: 'column', gap: 12, overflowY: 'auto', flexShrink: 0 }}>
          {/* Score ring */}
          <div style={{ textAlign: 'center' }}>
            <ScoreRing score={avgScore} />
            <div style={{ fontSize: 10.5, color: C.textSub, marginTop: 3, fontWeight: 700, textTransform: 'uppercase', letterSpacing: 0.5 }}>Overall Score</div>
          </div>

          {/* Score breakdown */}
          {feedbacks.length > 0 && (
            <div style={{ background: C.white, borderRadius: 10, padding: '8px 10px', border: '1px solid ' + C.cardBorder }}>
              {[['Grammar', avgGrammar], ['Vocabulary', avgVocab], ['Fluency', avgFluency]].map(function(pair) {
                var label = pair[0], val = pair[1];
                return (
                  <div key={label} style={{ display: 'flex', justifyContent: 'space-between', fontSize: 12.5, marginBottom: 4 }}>
                    <span style={{ color: C.textSub }}>{label}</span>
                    <span style={{ fontWeight: 700, color: val >= 70 ? C.greenText : '#92400e' }}>{val}%</span>
                  </div>
                );
              })}
              <div style={{ display: 'flex', justifyContent: 'space-between', fontSize: 12, color: C.textSub, borderTop: '1px solid ' + C.cardBorder, paddingTop: 5, marginTop: 2 }}>
                <span>Turns</span><span style={{ fontWeight: 700 }}>{feedbacks.length}</span>
              </div>
            </div>
          )}

          {/* Vocab tracker */}
          {targetVocab.length > 0 && (
            <div>
              <div style={{ fontSize: 10.5, fontWeight: 700, color: C.textSub, textTransform: 'uppercase', letterSpacing: 0.5, marginBottom: 6 }}>📚 Vocabulary</div>
              <div style={{ display: 'flex', flexWrap: 'wrap', gap: 4 }}>
                {targetVocab.map(function(w) {
                  var used = vocabUsed.has(w.toLowerCase());
                  return (
                    <span key={w} style={{
                      padding: '3px 8px', borderRadius: 12, fontSize: 11, fontWeight: 600,
                      background: used ? C.green : C.cardBorder,
                      color: used ? C.greenText : C.textSub,
                    }}>
                      {used ? '✓ ' : ''}{w}
                    </span>
                  );
                })}
              </div>
            </div>
          )}

          {/* Coach tips */}
          {latestTips.length > 0 && (
            <div>
              <div style={{ fontSize: 10.5, fontWeight: 700, color: C.textSub, textTransform: 'uppercase', letterSpacing: 0.5, marginBottom: 5 }}>💡 Coach Tips</div>
              {latestTips.slice(0, 2).map(function(t, i) {
                return <div key={i} style={{ fontSize: 12, color: C.orangeMid, background: C.orangeLight, borderRadius: 7, padding: '5px 8px', marginBottom: 4 }}>{t}</div>;
              })}
            </div>
          )}

          {/* Finish button */}
          <div style={{ marginTop: 'auto' }}>
            <button onClick={doEndSession} disabled={sessionEnded || feedbacks.length === 0} className="fc-btn" style={{
              width: '100%', background: (sessionEnded || feedbacks.length === 0) ? '#E5E7EB' : C.purple,
              color: (sessionEnded || feedbacks.length === 0) ? '#9CA3AF' : C.white, border: 'none',
              borderRadius: 10, padding: '9px 0', fontSize: 13, fontWeight: 700,
              cursor: (sessionEnded || feedbacks.length === 0) ? 'not-allowed' : 'pointer', fontFamily: 'Nunito, sans-serif',
            }}>
              {sessionEnded ? '✅ Complete' : '🏁 Finish Session'}
            </button>
          </div>
        </div>
      </div>

      {/* Input area */}
      {!sessionEnded && (
        <div style={{ padding: '9px 12px', borderTop: '1px solid ' + C.cardBorder, background: C.white, display: 'flex', gap: 7, alignItems: 'center', flexShrink: 0 }}>
          <input ref={inputRef} className="fc-input" value={inputText}
            onChange={function(e) { setInputText(e.target.value); }}
            onKeyDown={function(e) { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); sendMessage(); } }}
            placeholder={isListening ? '🎤 Listening…' : 'Type your message or press the mic…'}
            style={{
              flex: 1, padding: '9px 14px', border: '1.5px solid ' + C.cardBorder, borderRadius: 22,
              fontSize: 14, fontFamily: 'Nunito, sans-serif', background: C.white,
              transition: 'box-shadow 0.2s',
            }}
          />
          <button onClick={toggleMic} title="Voice input" style={{
            width: 38, height: 38, borderRadius: '50%', border: 'none', flexShrink: 0,
            background: isListening ? '#EF4444' : C.purpleLight, color: isListening ? C.white : C.purple,
            fontSize: 16, cursor: 'pointer', display: 'flex', alignItems: 'center', justifyContent: 'center',
          }}>🎤</button>
          <button onClick={function() { sendMessage(); }} disabled={isLoading || !inputText.trim()} className="fc-btn" style={{
            background: (isLoading || !inputText.trim()) ? '#E5E7EB' : C.orange,
            color: (isLoading || !inputText.trim()) ? '#9CA3AF' : C.white,
            border: 'none', borderRadius: 20, padding: '9px 16px', fontSize: 14, fontWeight: 700,
            cursor: (isLoading || !inputText.trim()) ? 'not-allowed' : 'pointer', fontFamily: 'Nunito, sans-serif', flexShrink: 0,
          }}>
            Send ▶
          </button>
        </div>
      )}
    </div>
  );
}

// ── COMPLETED VIEW ────────────────────────────────────────────
function CompletedView({ stats, payload, returnTo, activityId, onRestart }) {
  var s = stats || {};

  function scoreColor(v) { return v >= 80 ? C.greenText : v >= 60 ? '#92400e' : C.redText; }
  function scoreBg(v)    { return v >= 80 ? C.green     : v >= 60 ? '#FEF3C7' : '#FEE2E2'; }

  var boxes = [
    { label: 'Grammar',    val: s.grammar    || 0 },
    { label: 'Vocabulary', val: s.vocabulary || 0 },
    { label: 'Fluency',    val: s.fluency    || 0 },
    { label: 'Total',      val: s.total      || 0 },
  ];

  return (
    <div style={{ minHeight: '100vh', background: 'linear-gradient(135deg, #EDE9FA 0%, #FFF0E6 100%)', display: 'flex', alignItems: 'center', justifyContent: 'center', fontFamily: 'Nunito, sans-serif', padding: 16 }}>
      <div style={{ background: C.white, borderRadius: 20, padding: '36px 28px', maxWidth: 440, width: '100%', boxShadow: '0 4px 24px rgba(127,119,221,0.15)', textAlign: 'center' }}>
        <div style={{ fontSize: 52, marginBottom: 10 }}>✅</div>
        <div style={{ fontFamily: 'Fredoka, sans-serif', fontSize: 26, fontWeight: 600, color: C.textMain, marginBottom: 6 }}>
          Conversation Complete!
        </div>
        <div style={{ color: C.textSub, fontSize: 14, marginBottom: 24 }}>
          {payload.title || 'Free Conversation'}
        </div>

        {/* Score boxes */}
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(4, 1fr)', gap: 8, marginBottom: 16 }}>
          {boxes.map(function(b) {
            return (
              <div key={b.label} style={{ background: scoreBg(b.val), borderRadius: 12, padding: '10px 4px' }}>
                <div style={{ fontSize: 22, fontWeight: 700, color: scoreColor(b.val) }}>{b.val}</div>
                <div style={{ fontSize: 10, color: C.textSub, fontWeight: 700, textTransform: 'uppercase', lineHeight: 1.3 }}>{b.label}</div>
              </div>
            );
          })}
        </div>

        {/* Stats line */}
        <div style={{ fontSize: 13.5, color: C.textSub, marginBottom: 26, lineHeight: 2 }}>
          <span style={{ fontWeight: 700, color: C.greenText }}>{s.correct || 0}</span> great turns ·{' '}
          <span style={{ fontWeight: 700, color: C.redText }}>{s.errors || 0}</span> to improve ·{' '}
          <span style={{ fontWeight: 700, color: C.purple }}>{(s.vocabUsed || []).length}</span> vocab words used
        </div>

        {/* Buttons */}
        <div style={{ display: 'flex', gap: 10, justifyContent: 'center' }}>
          <button onClick={onRestart} className="fc-btn" style={{
            background: C.orange, color: C.white, border: 'none', borderRadius: 10, padding: '11px 24px',
            fontSize: 14, fontWeight: 700, cursor: 'pointer', fontFamily: 'Nunito, sans-serif',
          }}>
            🔄 Try Again
          </button>
          {returnTo && (
            <button onClick={function() { navigateToReturn(returnTo); }} className="fc-btn" style={{
              background: C.purpleLight, color: C.purple, border: '1.5px solid ' + C.cardBorder,
              borderRadius: 10, padding: '11px 24px', fontSize: 14, fontWeight: 700, cursor: 'pointer', fontFamily: 'Nunito, sans-serif',
            }}>
              ← Back
            </button>
          )}
        </div>
      </div>
    </div>
  );
}

// ── ROOT APP ──────────────────────────────────────────────────
function FreeConversationApp() {
  var payload0    = window.FC_PAYLOAD      || {};
  var activityId  = window.FC_ACTIVITY_ID  || '';
  var returnTo    = window.FC_RETURN_TO    || '';
  var allowEditor = !!window.FC_ALLOW_EDITOR;

  var [view, setView]           = useState(allowEditor ? 'editor' : 'player');
  var [curPayload, setPayload]  = useState(payload0);
  var [stats, setStats]         = useState(null);

  return (
    <div style={{ display: 'flex', flexDirection: 'column', height: '100%' }}>
      {view === 'editor' && (
        <EditorView
          payload={curPayload} activityId={activityId}
          onSaved={function(p) { setPayload(p); }}
          onPreview={function() { setView('player'); }}
        />
      )}
      {view === 'player' && (
        <PlayerView
          payload={curPayload} activityId={activityId} returnTo={returnTo}
          onComplete={function(s) { setStats(s); setView('completed'); }}
          onBack={allowEditor ? function() { setView('editor'); } : null}
        />
      )}
      {view === 'completed' && (
        <CompletedView
          stats={stats} payload={curPayload} returnTo={returnTo} activityId={activityId}
          onRestart={function() { setStats(null); setView('player'); }}
        />
      )}
    </div>
  );
}

var _fcEl = document.getElementById('fc-root');
if (_fcEl) ReactDOM.createRoot(_fcEl).render(React.createElement(FreeConversationApp));
</script>
<?php
$content = ob_get_clean();
render_activity_viewer('Free Conversation', 'fa-solid fa-comments', $content);
