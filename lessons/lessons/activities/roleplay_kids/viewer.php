<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../core/_activity_viewer_template.php';

$activityId  = isset($_GET['id'])        ? trim((string) $_GET['id'])        : '';
$mode        = isset($_GET['mode'])      ? trim((string) $_GET['mode'])      : '';
$returnTo    = isset($_GET['return_to']) ? trim((string) $_GET['return_to']) : '';
$allowEditor = ($mode === 'edit');
$startView   = $allowEditor ? 'editor' : 'player';

$savedScene = null;
$savedTurns = null;

if ($activityId !== '') {
    $stmt = $pdo->prepare("SELECT data FROM activities WHERE id = :id LIMIT 1");
    $stmt->execute(['id' => $activityId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row && !empty($row['data'])) {
        $parsed = json_decode($row['data'], true);
        if (is_array($parsed)) {
            $savedScene = $parsed['scene'] ?? null;
            $savedTurns = $parsed['turns'] ?? null;
        }
    }
}

ob_start();
?>
<link href="https://fonts.googleapis.com/css2?family=Fredoka:wght@500;600;700&family=Nunito:wght@600;700;800;900&display=swap" rel="stylesheet">

<style>
#roleplay-kids-root * { box-sizing: border-box; margin: 0; padding: 0; }
#roleplay-kids-root { font-family: 'Nunito', sans-serif; flex: 1; min-height: 0; overflow-y: auto; background: #fff; }
@keyframes rk-spin  { to { transform: rotate(360deg); } }
@keyframes rk-pulse { 0%,100%{opacity:1} 50%{opacity:.3} }
@keyframes rk-bounce { 0%,100%{transform:scale(1)} 50%{transform:scale(1.12)} }
@keyframes rk-shake  { 0%,100%{transform:translateX(0)} 25%{transform:translateX(-6px)} 75%{transform:translateX(6px)} }
</style>

<div id="roleplay-kids-root" style="flex:1;min-height:0;overflow-y:auto;"></div>

<script crossorigin src="https://unpkg.com/react@18/umd/react.production.min.js"></script>
<script crossorigin src="https://unpkg.com/react-dom@18/umd/react-dom.production.min.js"></script>
<script src="https://unpkg.com/@babel/standalone/babel.min.js"></script>

<script>
window.RK_ACTIVITY_ID  = <?= json_encode($activityId) ?>;
window.RK_RETURN_TO    = <?= json_encode($returnTo) ?>;
window.RK_SAVED_SCENE  = <?= json_encode($savedScene) ?>;
window.RK_SAVED_TURNS  = <?= json_encode($savedTurns) ?>;
window.RK_ALLOW_EDITOR = <?= json_encode($allowEditor) ?>;
window.RK_START_VIEW   = <?= json_encode($startView) ?>;
</script>

<script src="../../core/_activity_feedback.js"></script>
<script type="text/babel">
const { useState, useRef, useEffect, useCallback } = React;

// ── DESIGN TOKENS ─────────────────────────────────────────────
const C = {
  orange:       "#F97316",
  orangeDark:   "#C2580A",
  orangeSoft:   "#FFF0E6",
  orangeBorder: "#FCDDBF",
  purple:       "#7F77DD",
  purpleDark:   "#534AB7",
  purpleSoft:   "#EEEDFE",
  purpleBorder: "#EDE9FA",
  muted:        "#9B94BE",
  ink:          "#271B5D",
  white:        "#ffffff",
  green:        "#F0FDF4",
  greenText:    "#166534",
  red:          "#FEF2F2",
  redText:      "#991B1B",
};

// ── AVATARS ────────────────────────────────────────────────────
const AVATARS = [
  { id: "ANGIE",    label: "Angie"    },
  { id: "ANY",      label: "Any"      },
  { id: "BENNY",    label: "Benny"    },
  { id: "JAY JAY",  label: "Jay Jay"  },
  { id: "JESUS",    label: "Jesus"    },
  { id: "JOHN",     label: "John"     },
  { id: "LeeAnn",   label: "LeeAnn"  },
  { id: "MARY JAY", label: "Mary Jay" },
  { id: "NELLA",    label: "Nella"    },
  { id: "VICTOR",   label: "Victor"   },
  { id: "VIOLET",   label: "Violet"   },
];
const TEACHER_IMG = "assets/avatars/TEACHER.png";
function avatarSrc(id) { return `assets/avatars/${encodeURIComponent(id)}.png`; }

// ── VOICES (same IDs as flashcards/pronunciation editors) ──────
const VOICES = [
  { id: "nzFihrBIvB34imQBuxub", label: "Adult Male (Josh)"   },
  { id: "NoOVOzCQFLOvtsMoNcdT", label: "Adult Female (Lily)" },
  { id: "Nggzl2QAXh3OijoXD116", label: "Child (Candy)"       },
];

// ── DEFAULT DATA ───────────────────────────────────────────────
const DEFAULT_TURNS = [{ teacherLine: "", studentLine: "" }];
const DEFAULT_SCENE = {
  title: "Kids Roleplay", desc: "Practice speaking English!",
  sceneImage: "", agentName: "Teacher",
  voiceId: "nzFihrBIvB34imQBuxub",
};

// ── TTS — mirrors roleplay/viewer.php speakAgentLine exactly ──
// tts.php returns raw audio/mpeg blob (no Cloudinary dependency)
let currentAudioRef = null;
function playElevenLabs(text, voiceId, onDone, onError) {
  if (!text) return;
  if (currentAudioRef) { currentAudioRef.pause(); currentAudioRef = null; }
  const fd = new FormData();
  fd.append("text", text);
  fd.append("voice_id", voiceId || "nzFihrBIvB34imQBuxub");
  fetch("tts.php", { method: "POST", body: fd, credentials: "same-origin" })
    .then(r => {
      if (!r.ok) throw new Error("TTS " + r.status);
      return r.blob();
    })
    .then(blob => {
      const url = URL.createObjectURL(blob);
      const audio = new Audio(url);
      currentAudioRef = audio;
      audio.onended = () => { URL.revokeObjectURL(url); currentAudioRef = null; if (onDone) onDone(); };
      audio.onerror = () => { URL.revokeObjectURL(url); currentAudioRef = null; if (onError) onError(); };
      audio.play().catch(() => {});
    })
    .catch(e => { console.warn("TTS error:", e); if (onError) onError(); });
}

// ── SAVE — mirrors roleplay/save.php + roleplay saveActivity exactly ──
async function saveActivity(scene, turns) {
  const id = window.RK_ACTIVITY_ID;
  if (!id) return { ok: false, error: "No activity ID" };
  try {
    const r = await fetch("save.php", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      credentials: "same-origin",
      body: JSON.stringify({ id, scene, turns }),
    });
    return await r.json();
  } catch (e) {
    return { ok: false, error: String(e) };
  }
}

// ── TEACHER IMG ────────────────────────────────────────────────
function TeacherImg({ size = 68, style = {} }) {
  return (
    <img src={TEACHER_IMG} alt="Teacher" style={{
      width: size, height: size, borderRadius: "50%", objectFit: "cover",
      objectPosition: "top", ...style,
    }} />
  );
}

// ── AVATAR IMG ─────────────────────────────────────────────────
function AvatarImg({ id, size = 68, style = {} }) {
  const av = AVATARS.find(a => a.id === id) || AVATARS[0];
  return (
    <img
      src={avatarSrc(id)}
      alt={av.label}
      style={{ width: size, height: size, borderRadius: "50%", objectFit: "cover",
        objectPosition: "top", ...style }}
    />
  );
}

// ── SCENE IMAGE UPLOAD ────────────────────────────────────────
function SceneImageUpload({ value, onChange }) {
  const [uploading, setUploading] = useState(false);
  const [error, setError]         = useState(null);
  const inputRef                  = useRef(null);

  function handleFile(file) {
    if (!file) return;
    setError(null);
    setUploading(true);
    const fd = new FormData();
    fd.append("file", file);
    fetch("upload.php", { method: "POST", body: fd, credentials: "same-origin" })
      .then(r => r.json())
      .then(data => {
        if (data.url) { onChange(data.url); }
        else { setError(data.error || "Upload failed"); }
        setUploading(false);
      })
      .catch(() => { setError("Upload failed"); setUploading(false); });
  }

  function handleDrop(e) {
    e.preventDefault();
    const file = e.dataTransfer.files[0];
    if (file) handleFile(file);
  }

  return (
    <div>
      {/* Drop zone — 0.2cm margin on all sides */}
      <div
        onClick={() => !uploading && inputRef.current && inputRef.current.click()}
        onDragOver={e => e.preventDefault()}
        onDrop={handleDrop}
        style={{
          border: `2px dashed ${value ? C.orange : C.purpleBorder}`,
          borderRadius: 14, padding: "14px 16px", cursor: uploading ? "default" : "pointer",
          background: value ? C.orangeSoft : "#FAFAFE",
          display: "flex", alignItems: "center", gap: 14, transition: "all .15s",
          margin: "0.2cm",
        }}
      >
        {value ? (
          <img src={value} alt="Scene" style={{
            width: 80, height: 52, objectFit: "cover", borderRadius: 8,
            border: `1.5px solid ${C.orangeBorder}`, flexShrink: 0,
          }} />
        ) : (
          <div style={{
            width: 80, height: 52, borderRadius: 8, background: C.purpleSoft,
            display: "flex", alignItems: "center", justifyContent: "center",
            fontSize: 24, flexShrink: 0,
          }}>🖼️</div>
        )}
        <div>
          <div style={{ fontWeight: 800, fontSize: 13, color: value ? C.orangeDark : C.purpleDark }}>
            {uploading ? "Uploading…" : value ? "Change image" : "Click or drag image here"}
          </div>
          <div style={{ fontSize: 11, fontWeight: 700, color: C.muted, marginTop: 2 }}>
            JPG, PNG, GIF, WebP · uploads to Cloudinary
          </div>
        </div>
        {value && !uploading && (
          <button
            onClick={e => { e.stopPropagation(); onChange(""); }}
            style={{
              marginLeft: "auto", background: "#FEF2F2", color: "#B91C1C",
              border: "1px solid #FECACA", borderRadius: 999, padding: "4px 10px",
              fontSize: 11, fontWeight: 800, fontFamily: "'Nunito',sans-serif",
              cursor: "pointer", flexShrink: 0,
            }}>✕ Remove</button>
        )}
      </div>
      <input
        ref={inputRef} type="file" accept="image/*"
        style={{ display: "none" }}
        onChange={e => handleFile(e.target.files[0])}
      />
      {error && (
        <div style={{ marginTop: 6, fontSize: 12, fontWeight: 700, color: "#B91C1C" }}>⚠ {error}</div>
      )}
    </div>
  );
}

// ══════════════════════════════════════════════════════════════
// EDITOR VIEW
// ══════════════════════════════════════════════════════════════
function EditorView({ scene: initScene, turns: initTurns, onSave }) {
  const [scene, setScene]   = useState(initScene || DEFAULT_SCENE);
  const [turns, setTurns]   = useState(initTurns && initTurns.length ? initTurns : DEFAULT_TURNS);
  const [toast, setToast]   = useState(null);
  const [saving, setSaving] = useState(false);

  function sc(key, val) { setScene(s => ({ ...s, [key]: val })); }

  function addTurn() { setTurns(t => [...t, { teacherLine: "", studentLine: "" }]); }
  function removeTurn(i) { setTurns(t => t.filter((_, idx) => idx !== i)); }
  function updateTurn(i, key, val) { setTurns(t => t.map((r, idx) => idx === i ? { ...r, [key]: val } : r)); }

  async function handleSave() {
    setSaving(true);
    const res = await saveActivity(scene, turns);
    setSaving(false);
    setToast(res.ok ? "Saved successfully." : "Error saving. Please try again.");
    if (res.ok) onSave(scene, turns);
    setTimeout(() => setToast(null), 3000);
  }

  const inp = (val, onChange, placeholder = "") => (
    <input value={val} onChange={e => onChange(e.target.value)} placeholder={placeholder} style={{
      width: "100%", padding: "9px 12px", border: `1.5px solid ${C.purpleBorder}`,
      borderRadius: 10, fontSize: 14, fontFamily: "'Nunito',sans-serif",
      fontWeight: 700, outline: "none", color: C.ink,
    }} />
  );

  const label = (text) => (
    <div style={{ fontSize: 11, fontWeight: 900, color: C.purpleDark, textTransform: "uppercase",
      letterSpacing: ".05em", marginBottom: 5 }}>{text}</div>
  );

  const card = (children, mb = 14) => (
    <div style={{ background: C.white, border: `1px solid ${C.purpleBorder}`, borderRadius: 20,
      padding: 18, marginBottom: mb, boxShadow: "0 2px 12px rgba(127,119,221,.08)" }}>
      {children}
    </div>
  );

  return (
    <div style={{ maxWidth: 680, margin: "0 auto", padding: "20px 16px" }}>
      {/* Header — no preview button */}
      <div style={{ marginBottom: 18 }}>
        <div style={{ fontFamily: "'Fredoka',sans-serif", fontSize: 24, fontWeight: 700, color: C.orange }}>
          🎭 Roleplay Kids — Editor
        </div>
      </div>

      {/* Scene */}
      {card(<>
        <div style={{ fontWeight: 900, fontSize: 15, color: C.purpleDark, marginBottom: 14 }}>Scene Settings</div>
        <div style={{ display: "grid", gridTemplateColumns: "1fr 1fr", gap: 12 }}>
          <div>{label("Activity Title")}{inp(scene.title, v => sc("title", v), "e.g. At the Café")}</div>
          <div>{label("Scene Description")}{inp(scene.desc, v => sc("desc", v), "Short subtitle")}</div>
          <div>{label("Teacher / Agent Name")}{inp(scene.agentName, v => sc("agentName", v), "Teacher")}</div>
          <div>
            {label("Teacher Voice")}
            <select
              value={scene.voiceId || VOICES[0].id}
              onChange={e => sc("voiceId", e.target.value)}
              style={{
                width: "100%", padding: "9px 12px", border: `1.5px solid ${C.purpleBorder}`,
                borderRadius: 10, fontSize: 14, fontFamily: "'Nunito',sans-serif",
                fontWeight: 700, outline: "none", color: C.ink, background: C.white, cursor: "pointer",
              }}
            >
              {VOICES.map(v => <option key={v.id} value={v.id}>{v.label}</option>)}
            </select>
          </div>
          <div style={{ gridColumn: "1/-1" }}>
            {label("Scene Background Image")}
            <SceneImageUpload value={scene.sceneImage} onChange={v => sc("sceneImage", v)} />
          </div>
        </div>
      </>)}

      {/* Turns */}
      <div style={{ fontWeight: 900, fontSize: 15, color: C.purpleDark, marginBottom: 10 }}>
        Turns <span style={{ color: C.muted, fontWeight: 700 }}>({turns.length})</span>
      </div>
      {turns.map((turn, i) => card(<>
        <div style={{ display: "flex", justifyContent: "space-between", alignItems: "center", marginBottom: 12 }}>
          <div style={{ display: "flex", alignItems: "center", gap: 8 }}>
            <span style={{ fontWeight: 800, color: C.purpleDark, fontSize: 13 }}>Turn {i + 1}</span>
          </div>
          {turns.length > 1 && (
            <button onClick={() => removeTurn(i)} style={{
              background: "#FEF2F2", color: "#B91C1C", border: "1px solid #FECACA",
              borderRadius: 999, padding: "4px 12px", fontSize: 12, fontWeight: 800,
              fontFamily: "'Nunito',sans-serif", cursor: "pointer",
            }}>Remove</button>
          )}
        </div>
        <div style={{ display: "flex", flexDirection: "column", gap: 10 }}>
          {/* Teacher line */}
          <div style={{ background: C.orangeSoft, border: `1px solid ${C.orangeBorder}`, borderRadius: 12, padding: "10px 12px" }}>
            {label("🧑‍🏫 Teacher says")}
            <textarea value={turn.teacherLine} onChange={e => updateTurn(i, "teacherLine", e.target.value)}
              placeholder="e.g. Hello! What would you like to order?" rows={2} style={{
                width: "100%", padding: "8px 10px", border: `1.5px solid ${C.orangeBorder}`,
                borderRadius: 8, fontSize: 14, fontFamily: "'Nunito',sans-serif",
                fontWeight: 700, resize: "vertical", outline: "none", color: C.ink, background: C.white,
              }} />
          </div>
          {/* Student line */}
          <div style={{ background: C.purpleSoft, border: `1px solid ${C.purpleBorder}`, borderRadius: 12, padding: "10px 12px" }}>
            {label("🎤 Student should say")}
            <textarea value={turn.studentLine} onChange={e => updateTurn(i, "studentLine", e.target.value)}
              placeholder="e.g. I would like a coffee, please." rows={2} style={{
                width: "100%", padding: "8px 10px", border: `1.5px solid ${C.purpleBorder}`,
                borderRadius: 8, fontSize: 14, fontFamily: "'Nunito',sans-serif",
                fontWeight: 700, resize: "vertical", outline: "none", color: C.ink, background: C.white,
              }} />
            <div style={{ fontSize: 11, color: C.muted, fontWeight: 700, marginTop: 6 }}>
              The system will compare what the student says against this sentence and give a score.
            </div>
          </div>
        </div>
      </>, 10))}

      <button onClick={addTurn} style={{
        width: "100%", padding: "10px", background: C.purpleSoft, color: C.purpleDark,
        border: `1.5px dashed ${C.purpleBorder}`, borderRadius: 14, fontWeight: 800,
        fontSize: 14, fontFamily: "'Nunito',sans-serif", cursor: "pointer", marginBottom: 16,
      }}>+ Add Turn</button>

      <button onClick={handleSave} disabled={saving} style={{
        width: "100%", padding: "13px", background: saving ? C.muted : C.orange,
        color: C.white, border: "none", borderRadius: 14, fontWeight: 900,
        fontSize: 15, fontFamily: "'Nunito',sans-serif", cursor: saving ? "default" : "pointer",
      }}>{saving ? "Saving…" : "Save Activity"}</button>

      {toast && (
        <div style={{
          marginTop: 12, padding: "10px 16px", borderRadius: 12, textAlign: "center",
          fontWeight: 800, fontSize: 14,
          background: toast.includes("fail") ? C.red : C.green,
          color: toast.includes("fail") ? C.redText : C.greenText,
        }}>{toast}</div>
      )}
    </div>
  );
}

// ══════════════════════════════════════════════════════════════
// PLAYER VIEW
// ══════════════════════════════════════════════════════════════
function PlayerView({ scene: sc, turns, activityId }) {
  const scene = sc || DEFAULT_SCENE;
  const safeT = (turns && turns.length) ? turns : DEFAULT_TURNS;

  // phase: "avatar" | "playing" | "done"
  // subPhase: "teacher" | "feedback"
  const [phase,       setPhase]      = useState("avatar");
  const [subPhase,    setSubPhase]   = useState("teacher");
  const [avatarId,    setAvatarId]   = useState(null);
  const [turnIndex,   setTurnIndex]  = useState(0);
  const [pts,         setPts]        = useState(0);
  const [written,     setWritten]    = useState("");
  const [micState,    setMicState]   = useState("idle");
  const [transcript,  setTranscript] = useState("");
  const [pronScore,   setPronScore]  = useState(null);
  const [turnScores,  setTurnScores] = useState([]);
  const [reviewItems, setReviewItems]= useState([]);
  const [ttsPlaying,  setTtsPlaying] = useState(false);
  const [showTyping,  setShowTyping] = useState(false);
  const completedRef = useRef(null);
  const recRef       = useRef(null);

  const turn        = safeT[turnIndex] || { teacherLine: "", studentLine: "" };
  const avatarLabel = AVATARS.find(a => a.id === avatarId)?.label || "You";
  const total       = safeT.length;
  const pct         = total > 0 ? Math.round(((turnIndex + (phase === "done" ? 1 : 0)) / total) * 100) : 0;

  const voiceId = scene.voiceId || VOICES[0].id;

  // ── Auto-play teacher when turn starts ───────────────────────
  useEffect(() => {
    if (phase === "playing" && subPhase === "teacher" && turn.teacherLine) {
      setTtsPlaying(true);
      playElevenLabs(turn.teacherLine, voiceId,
        () => setTtsPlaying(false),
        () => setTtsPlaying(false));
    }
    if (subPhase !== "teacher") setTtsPlaying(false);
  }, [turnIndex, phase, subPhase]);

  // ── Mic ──────────────────────────────────────────────────────
  const SpeechRec = window.SpeechRecognition || window.webkitSpeechRecognition;

  function startRecording() {
    if (!SpeechRec) { alert("Speech recognition not supported in this browser."); return; }
    const rec = new SpeechRec();
    rec.lang = "en-US";
    rec.interimResults = false;
    rec.maxAlternatives = 1;
    recRef.current = rec;
    setMicState("recording");
    setTranscript("");
    setPronScore(null);
    rec.onresult = (e) => {
      const text = e.results[0][0].transcript;
      setTranscript(text);
      setMicState("processing");
      scorePronunciation(text, turn.studentLine);
    };
    rec.onerror = () => setMicState("idle");
    rec.onend   = () => { if (micState === "recording") setMicState("idle"); };
    rec.start();
  }

  function stopRecording() {
    if (recRef.current) { try { recRef.current.stop(); } catch {} }
    setMicState("idle");
  }

  // ── Client-side pronunciation scoring ────────────────────────
  function normalizeStr(s) {
    return String(s || "").toLowerCase().trim()
      .replace(/[.,!?;:'"]/g, "").replace(/\s+/g, " ");
  }
  function wordOverlapScore(a, b) {
    const wa = a.split(" ").filter(Boolean);
    const wb = b.split(" ").filter(Boolean);
    if (!wa.length || !wb.length) return 0;
    const matches = wa.filter(w => wb.includes(w)).length;
    return matches / Math.max(wa.length, wb.length);
  }
  function scorePronunciation(text, expected) {
    const said    = normalizeStr(text);
    const exp     = normalizeStr(expected);
    const overlap = wordOverlapScore(said, exp);
    const score   = Math.round(overlap * 100);
    const pass    = score >= 50 ? 1 : 0;
    setPts(prev => prev + score);
    setPronScore(score);
    setMicState("idle");
    setSubPhase("feedback");
    setTurnScores(prev => [...prev, pass]);
    setReviewItems(prev => [...prev, {
      question:      turn.teacherLine || ("Turn " + (turnIndex + 1)),
      yourAnswer:    text || "(no recording)",
      correctAnswer: expected,
      score:         pass,
    }]);
  }

  function goToNextTurn() {
    setWritten(""); setTranscript(""); setPronScore(null); setMicState("idle");
    setShowTyping(false);
    if (turnIndex < total - 1) {
      setTurnIndex(i => i + 1);
      setSubPhase("teacher");
    } else {
      setPhase("done");
    }
  }

  function scoreWritten(text) {
    if (!text.trim()) return;
    const said    = normalizeStr(text);
    const exp     = normalizeStr(turn.studentLine);
    const overlap = wordOverlapScore(said, exp);
    const score   = Math.round(overlap * 100);
    const pass    = score >= 50 ? 1 : 0;
    setPts(prev => prev + score);
    setPronScore(score);
    setTranscript("");
    setMicState("idle");
    setSubPhase("feedback");
    setTurnScores(prev => [...prev, pass]);
    setReviewItems(prev => [...prev, {
      question:      turn.teacherLine || ("Turn " + (turnIndex + 1)),
      yourAnswer:    text,
      correctAnswer: turn.studentLine,
      score:         pass,
    }]);
  }

  function handleNext() {
    if (subPhase === "feedback") { goToNextTurn(); return; }
    if (showTyping && written.trim()) { scoreWritten(written); return; }
    setTurnScores(prev => [...prev, 0]);
    setReviewItems(prev => [...prev, {
      question: turn.teacherLine || ("Turn " + (turnIndex + 1)),
      yourAnswer: "(skipped)", correctAnswer: turn.studentLine, score: 0,
    }]);
    goToNextTurn();
  }

  function replayTTS() {
    setTtsPlaying(true);
    playElevenLabs(turn.teacherLine, voiceId,
      () => setTtsPlaying(false),
      () => setTtsPlaying(false));
  }

  function stopTTS() {
    if (currentAudioRef) { currentAudioRef.pause(); currentAudioRef = null; }
    setTtsPlaying(false);
  }

  // ── AF.showCompleted when done ────────────────────────────────
  useEffect(() => {
    if (phase !== "done" || !completedRef.current) return;
    const AF = window.ActivityFeedback;
    if (!AF) return;
    const winAudio  = new Audio("../../hangman/assets/win.mp3");
    const returnTo  = window.RK_RETURN_TO  || "";
    const actId     = window.RK_ACTIVITY_ID || "";
    const snapScores = turnScores.slice();
    AF.showCompleted({
      target:        completedRef.current,
      scores:        snapScores,
      title:         scene.title || "Roleplay Kids",
      activityType:  "Roleplay (Kids)",
      questionCount: total,
      winAudio:      winAudio,
      onRetry:       handleRestart,
      onReview: function () {
        AF.showReview({ target: completedRef.current, items: reviewItems, onRetry: handleRestart });
      },
    });
    const result = AF.computeScore(snapScores);
    if (returnTo && actId) {
      const sep = returnTo.includes("?") ? "&" : "?";
      fetch(returnTo + sep + "activity_percent=" + result.percent + "&activity_errors=" + result.wrong + "&activity_total=" + result.total + "&activity_id=" + encodeURIComponent(actId) + "&activity_type=roleplay_kids",
        { method: "GET", credentials: "same-origin", cache: "no-store" }).catch(() => {});
    }
  }, [phase]);

  function handleRestart() {
    setPhase("avatar"); setAvatarId(null); setTurnIndex(0); setPts(0);
    setSubPhase("teacher"); setWritten(""); setTranscript(""); setPronScore(null);
    setMicState("idle"); setTurnScores([]); setReviewItems([]);
  }

  function startPlaying() { setPhase("playing"); setSubPhase("teacher"); }

  // ════════════════════════════════════════════════════════════
  // AVATAR PICKER
  // ════════════════════════════════════════════════════════════
  if (phase === "avatar") return (
    <div style={{ maxWidth: 600, margin: "0 auto", padding: "28px 16px", textAlign: "center" }}>
      {/* Kicker */}
      <div style={{
        display: "inline-flex", alignItems: "center", padding: "6px 14px",
        borderRadius: 999, background: C.orangeSoft, border: `1px solid ${C.orangeBorder}`,
        color: C.orangeDark, fontSize: 11, fontWeight: 900, letterSpacing: ".08em",
        textTransform: "uppercase", marginBottom: 12,
      }}>🎭 Roleplay Activity</div>

      <h1 style={{
        fontFamily: "'Fredoka',sans-serif", fontSize: "clamp(28px,6vw,44px)",
        fontWeight: 700, color: C.orange, marginBottom: 6, lineHeight: 1.1,
      }}>{scene.title || "Kids Roleplay"}</h1>

      <p style={{ fontSize: 15, fontWeight: 700, color: C.muted, marginBottom: 28 }}>
        Choose your character!
      </p>

      {/* Avatar grid */}
      <div style={{ display: "flex", gap: 14, justifyContent: "center", flexWrap: "wrap", marginBottom: 32 }}>
        {AVATARS.map(av => {
          const selected = avatarId === av.id;
          return (
            <button key={av.id} onClick={() => setAvatarId(av.id)} style={{
              background: selected ? C.orangeSoft : C.white,
              border: selected ? `3px solid ${C.orange}` : `2px solid ${C.purpleBorder}`,
              borderRadius: 20, padding: "14px 12px", cursor: "pointer",
              boxShadow: selected ? `0 0 0 3px rgba(249,115,22,.22)` : "0 2px 8px rgba(127,119,221,.08)",
              transition: "all .15s", minWidth: 80,
              transform: selected ? "scale(1.06)" : "scale(1)",
            }}>
              <AvatarImg id={av.id} size={62} />
              <div style={{
                marginTop: 8, fontWeight: 800, fontSize: 13,
                color: selected ? C.orangeDark : C.muted,
              }}>{av.label}</div>
            </button>
          );
        })}
      </div>

      <button onClick={startPlaying} disabled={!avatarId} style={{
        background: avatarId ? C.orange : C.purpleSoft,
        color: avatarId ? C.white : C.muted,
        border: "none", borderRadius: 999, padding: "14px 40px",
        fontFamily: "'Nunito',sans-serif", fontWeight: 900, fontSize: 16,
        cursor: avatarId ? "pointer" : "not-allowed",
        boxShadow: avatarId ? "0 6px 20px rgba(249,115,22,.3)" : "none",
        transition: "all .15s",
      }}>Let's go! →</button>
    </div>
  );

  // ════════════════════════════════════════════════════════════
  // DONE — AF.showCompleted populates this div via useEffect
  // ════════════════════════════════════════════════════════════
  if (phase === "done") return (
    <div ref={completedRef} style={{ maxWidth: 760, margin: "0 auto", padding: "20px 16px" }}></div>
  );

  // ════════════════════════════════════════════════════════════
  // PLAYING SCREEN — redesigned
  // ════════════════════════════════════════════════════════════
  const voiceLabelShort = (VOICES.find(v => v.id === voiceId)?.label || "Adult Male")
    .split("(")[0].trim();
  const agentInitial  = (scene.agentName || "T")[0].toUpperCase();
  const studentInitial = (avatarLabel || "Y")[0].toUpperCase();

  const outlinedBtn = (label, onClick, disabled = false) => (
    <button onClick={onClick} disabled={disabled} style={{
      padding: "9px 18px", border: `1.5px solid ${disabled ? C.purpleBorder : C.purpleBorder}`,
      borderRadius: 999, background: disabled ? "#fafafe" : C.white,
      color: disabled ? C.muted : C.purpleDark,
      fontFamily: "'Nunito',sans-serif", fontWeight: 800, fontSize: 13,
      cursor: disabled ? "default" : "pointer", transition: "all .15s",
    }}>{label}</button>
  );

  return (
    <div style={{ maxWidth: 640, margin: "0 auto", padding: "20px 16px 24px" }}>

      {/* ── Hero ── */}
      <div style={{ textAlign: "center", marginBottom: 20 }}>
        <div style={{
          display: "inline-flex", alignItems: "center", padding: "5px 14px",
          borderRadius: 999, background: C.orangeSoft, border: `1px solid ${C.orangeBorder}`,
          color: C.orangeDark, fontSize: 11, fontWeight: 900,
          letterSpacing: ".08em", textTransform: "uppercase", marginBottom: 10,
        }}>ACTIVITY</div>
        <h1 style={{
          fontFamily: "'Fredoka',sans-serif", fontSize: "clamp(32px,6vw,44px)",
          fontWeight: 700, color: C.orange, margin: "0 0 6px", lineHeight: 1.05,
        }}>{scene.title || "Roleplay"}</h1>
        <p style={{ fontSize: 15, fontWeight: 700, color: C.muted, margin: 0 }}>
          {scene.desc || "Practice real conversations in English."}
        </p>
      </div>

      {/* ── Main card ── */}
      <div style={{
        background: C.white, border: `1px solid #EDE9FA`,
        borderRadius: 24, boxShadow: "0 8px 40px rgba(127,119,221,.12)",
        overflow: "hidden",
      }}>

        {/* Top bar */}
        <div style={{
          display: "flex", alignItems: "center", gap: 8,
          padding: "12px 16px", borderBottom: `1px solid #F0EEF8`,
        }}>
          <button onClick={handleRestart} style={{
            display: "flex", alignItems: "center", gap: 6,
            padding: "7px 14px", border: `1.5px solid #E4E0F8`,
            borderRadius: 999, background: C.white, color: C.ink,
            fontFamily: "'Nunito',sans-serif", fontWeight: 800, fontSize: 13,
            cursor: "pointer",
          }}>◁ Back</button>
          <span style={{ fontWeight: 800, fontSize: 16, color: C.orange }}>
            {scene.title || "Roleplay"}
          </span>
          <div style={{ marginLeft: "auto", display: "flex", gap: 8, alignItems: "center" }}>
            <div style={{
              display: "flex", alignItems: "center", gap: 4,
              padding: "6px 12px", border: `1.5px solid #E4E0F8`,
              borderRadius: 999, fontSize: 12, fontWeight: 800, color: C.ink,
            }}>
              <span style={{ fontSize: 10, color: C.muted }}>□</span> {voiceLabelShort} <span style={{ fontSize: 10, color: C.muted }}>□</span>
            </div>
            <div style={{
              background: C.purple, color: C.white,
              borderRadius: 999, padding: "6px 14px",
              fontSize: 12, fontWeight: 900,
            }}>Turn {turnIndex + 1} / {total}</div>
          </div>
        </div>

        {/* Scene bar */}
        <div style={{
          display: "flex", justifyContent: "space-between", alignItems: "center",
          padding: "8px 16px", background: C.orangeSoft,
          borderBottom: `1px solid ${C.orangeBorder}`,
        }}>
          <span style={{ display: "flex", alignItems: "center", gap: 6, fontWeight: 800, fontSize: 13, color: C.orangeDark }}>
            <span style={{ fontSize: 10 }}>□</span>
            {scene.title || "Scene"}{scene.desc ? ` — ${scene.desc}` : ""}
          </span>
          <span style={{ fontSize: 12, fontWeight: 700, color: C.orangeDark }}>
            {scene.agentName || "Teacher"} · Teacher | {avatarLabel} · You
          </span>
        </div>

        {/* Progress row */}
        <div style={{
          display: "flex", alignItems: "center", gap: 10,
          padding: "10px 16px", borderBottom: `1px solid #F0EEF8`,
        }}>
          <span style={{ fontSize: 12, fontWeight: 800, color: C.muted, minWidth: 30 }}>
            {turnIndex + 1} / {total}
          </span>
          <div style={{ flex: 1, height: 10, background: "#F4F2FD", borderRadius: 999, overflow: "hidden" }}>
            <div style={{
              height: "100%", width: `${pct}%`,
              background: `linear-gradient(90deg,${C.orange},${C.purple})`,
              borderRadius: 999, transition: "width .4s",
            }} />
          </div>
          <div style={{
            background: C.purple, color: C.white, borderRadius: 999,
            padding: "5px 12px", fontSize: 12, fontWeight: 900,
          }}>Turn {turnIndex + 1} of {total}</div>
        </div>

        {/* Content */}
        <div style={{ padding: "14px 16px 0" }}>

          {/* Teacher dialog card */}
          <div style={{
            background: "#F5F3FF", border: `1px solid ${C.purpleBorder}`,
            borderRadius: 18, padding: "14px 16px", marginBottom: 10,
          }}>
            <div style={{ display: "flex", alignItems: "center", gap: 10, marginBottom: 10 }}>
              <div style={{
                width: 42, height: 42, borderRadius: "50%",
                background: C.purpleSoft, border: `2px solid ${C.purple}`,
                display: "flex", alignItems: "center", justifyContent: "center",
                fontWeight: 900, fontSize: 18, color: C.purpleDark, flexShrink: 0,
              }}>{agentInitial}</div>
              <div>
                <div style={{ fontWeight: 800, fontSize: 14, color: C.purpleDark }}>
                  {scene.agentName || "Teacher"}
                </div>
                <div style={{ fontSize: 10, fontWeight: 900, color: C.muted, textTransform: "uppercase", letterSpacing: ".06em" }}>
                  TEACHER
                </div>
              </div>
              <div style={{ marginLeft: "auto" }}>
                {ttsPlaying ? (
                  <button onClick={stopTTS} style={{
                    display: "flex", alignItems: "center", gap: 5,
                    padding: "6px 12px", border: `1.5px solid #E4E0F8`,
                    borderRadius: 999, background: C.white, color: C.ink,
                    fontFamily: "'Nunito',sans-serif", fontWeight: 800, fontSize: 12,
                    cursor: "pointer",
                  }}>■ Playing…</button>
                ) : (
                  <button onClick={replayTTS} style={{
                    display: "flex", alignItems: "center", gap: 5,
                    padding: "6px 12px", border: `1.5px solid #E4E0F8`,
                    borderRadius: 999, background: C.white, color: C.muted,
                    fontFamily: "'Nunito',sans-serif", fontWeight: 800, fontSize: 12,
                    cursor: "pointer",
                  }}>▶ Play</button>
                )}
              </div>
            </div>
            <div style={{ fontWeight: 800, fontSize: 15, color: C.ink, lineHeight: 1.55 }}>
              {turn.teacherLine || "…"}
            </div>
          </div>

          {/* Hint card — shown when not yet in feedback */}
          {turn.studentLine && subPhase !== "feedback" && (
            <div style={{
              background: "#FFF7ED", border: `1px solid ${C.orangeBorder}`,
              borderRadius: 14, padding: "11px 16px", marginBottom: 10,
            }}>
              <div style={{ fontSize: 10, fontWeight: 900, color: C.orange, textTransform: "uppercase", letterSpacing: ".06em", marginBottom: 4 }}>
                HINT
              </div>
              <div style={{ fontWeight: 800, fontSize: 14, color: C.orangeDark, lineHeight: 1.5 }}>
                {turn.studentLine}
              </div>
            </div>
          )}

          {/* Feedback section */}
          {subPhase === "feedback" && (
            <div style={{ marginBottom: 10 }}>
              {(written.trim() || transcript) && (
                <div style={{ background: C.purpleSoft, borderLeft: `3px solid ${C.purple}`, borderRadius: 12, padding: "10px 14px", marginBottom: 8 }}>
                  <div style={{ fontSize: 10, fontWeight: 900, color: C.purple, textTransform: "uppercase", marginBottom: 4 }}>
                    {transcript ? "You said" : "You wrote"}
                  </div>
                  <div style={{ fontFamily: "'Fredoka',sans-serif", fontSize: 14, fontWeight: 600, color: C.ink }}>
                    "{transcript || written}"
                  </div>
                </div>
              )}
              <div style={{ background: C.orangeSoft, borderLeft: `3px solid ${C.orange}`, borderRadius: 12, padding: "10px 14px", marginBottom: 8 }}>
                <div style={{ fontSize: 10, fontWeight: 900, color: C.orange, textTransform: "uppercase", marginBottom: 4 }}>Correct answer</div>
                <div style={{ fontFamily: "'Fredoka',sans-serif", fontSize: 14, fontWeight: 600, color: C.orangeDark }}>{turn.studentLine}</div>
              </div>
              {pronScore !== null && (
                <div style={{ background: C.purpleSoft, borderRadius: 12, padding: "10px 14px", marginBottom: 4 }}>
                  <div style={{ display: "flex", justifyContent: "space-between", alignItems: "center", marginBottom: 6 }}>
                    <div style={{ fontSize: 11, fontWeight: 900, color: C.muted, textTransform: "uppercase" }}>Score</div>
                    <div style={{ fontFamily: "'Fredoka',sans-serif", fontSize: 18, fontWeight: 700, color: C.orange }}>{pronScore} pts</div>
                  </div>
                  <div style={{ height: 8, background: "#F4F2FD", borderRadius: 999, overflow: "hidden" }}>
                    <div style={{ height: "100%", width: `${pronScore}%`, background: `linear-gradient(90deg,${C.orange},${C.purple})`, borderRadius: 999, transition: "width .5s" }} />
                  </div>
                </div>
              )}
            </div>
          )}

          {/* Your turn card */}
          {subPhase !== "feedback" && (
            <div style={{
              background: C.white, border: `1px solid ${C.purpleBorder}`,
              borderRadius: 18, padding: "14px 16px",
            }}>
              <div style={{ display: "flex", alignItems: "center", gap: 10, marginBottom: 14 }}>
                <div style={{
                  width: 42, height: 42, borderRadius: "50%",
                  background: C.orangeSoft, border: `2px solid ${C.orange}`,
                  display: "flex", alignItems: "center", justifyContent: "center",
                  fontWeight: 900, fontSize: 18, color: C.orangeDark, flexShrink: 0,
                }}>{studentInitial}</div>
                <div>
                  <div style={{ fontWeight: 800, fontSize: 14, color: C.orange }}>Your turn</div>
                  <div style={{ fontSize: 10, fontWeight: 900, color: C.muted, textTransform: "uppercase", letterSpacing: ".06em" }}>
                    {avatarLabel.toUpperCase()}
                  </div>
                </div>
              </div>

              {!showTyping ? (
                <div style={{ textAlign: "center" }}>
                  <button
                    onClick={() => micState === "recording" ? stopRecording() : startRecording()}
                    disabled={micState === "processing"}
                    style={{
                      width: 110, height: 110, borderRadius: 22,
                      background: micState === "recording" ? "rgba(226,75,74,.07)" : C.white,
                      border: micState === "recording"
                        ? "2px solid #E24B4A"
                        : `2px solid ${C.purpleBorder}`,
                      cursor: micState === "processing" ? "default" : "pointer",
                      display: "flex", alignItems: "center", justifyContent: "center",
                      margin: "0 auto 10px", fontSize: 38,
                      boxShadow: "0 2px 10px rgba(127,119,221,.08)",
                      animation: micState === "recording" ? "rk-pulse 1s ease-in-out infinite" : "none",
                      transition: "border-color .15s",
                    }}>
                    {micState === "processing" ? "⏳" : micState === "recording" ? "⏹" : "🎤"}
                  </button>
                  <div style={{ fontSize: 13, fontWeight: 700, color: C.muted, marginBottom: 12 }}>
                    {micState === "processing" ? "Processing…"
                      : micState === "recording" ? "Recording… tap to stop"
                      : "Tap to speak your response"}
                  </div>
                  <div style={{ fontSize: 12, fontWeight: 700, color: C.muted, margin: "0 0 10px" }}>— or —</div>
                  <button onClick={() => { stopTTS(); setShowTyping(true); }} style={{
                    padding: "9px 24px", border: `1.5px solid #E4E0F8`,
                    borderRadius: 999, background: C.white, color: C.ink,
                    fontFamily: "'Nunito',sans-serif", fontWeight: 800, fontSize: 13,
                    cursor: "pointer",
                  }}>Type instead</button>
                </div>
              ) : (
                <div>
                  <textarea
                    value={written}
                    onChange={e => setWritten(e.target.value)}
                    placeholder="Type your response here…"
                    rows={3}
                    style={{
                      width: "100%", padding: "12px 14px",
                      border: `1.5px solid ${C.purpleBorder}`,
                      borderRadius: 14, fontSize: 15,
                      fontFamily: "'Nunito',sans-serif", fontWeight: 700,
                      resize: "none", outline: "none", color: C.ink,
                      background: "#FAFAFE", marginBottom: 10,
                    }}
                  />
                  <button onClick={() => setShowTyping(false)} style={{
                    padding: "8px 18px", border: `1.5px solid #E4E0F8`,
                    borderRadius: 999, background: C.white, color: C.muted,
                    fontFamily: "'Nunito',sans-serif", fontWeight: 800, fontSize: 12,
                    cursor: "pointer",
                  }}>🎤 Speak instead</button>
                </div>
              )}
            </div>
          )}

        </div>{/* end content */}

        {/* Bottom bar */}
        <div style={{
          display: "flex", justifyContent: "space-between", alignItems: "center",
          padding: "12px 16px", marginTop: 14,
          borderTop: `1px solid #F0EEF8`,
        }}>
          <span style={{ fontSize: 13, fontWeight: 700, color: C.muted }}>
            {subPhase === "feedback" ? "Nice work!" : "Speak or type to continue"}
          </span>
          <button onClick={handleNext} style={{
            padding: "9px 22px", border: `1.5px solid #E4E0F8`,
            borderRadius: 999, background: C.white, color: C.ink,
            fontFamily: "'Nunito',sans-serif", fontWeight: 800, fontSize: 14,
            cursor: "pointer",
          }}>
            {subPhase === "feedback"
              ? (turnIndex < total - 1 ? "Next →" : "Finish 🎉")
              : "Next →"}
          </button>
        </div>

      </div>{/* end main card */}
    </div>
  );
}

// ══════════════════════════════════════════════════════════════
// ROOT APP
// ══════════════════════════════════════════════════════════════
function App() {
  const [view,  setView]  = useState(window.RK_START_VIEW || "player");
  const [scene, setScene] = useState(window.RK_SAVED_SCENE || DEFAULT_SCENE);
  const [turns, setTurns] = useState(window.RK_SAVED_TURNS && window.RK_SAVED_TURNS.length ? window.RK_SAVED_TURNS : DEFAULT_TURNS);

  if (view === "editor" && window.RK_ALLOW_EDITOR) {
    return (
      <EditorView
        scene={scene} turns={turns}
        onSave={(s, t) => { setScene(s); setTurns(t); }}
      />
    );
  }
  return <PlayerView scene={scene} turns={turns} activityId={window.RK_ACTIVITY_ID} />;
}

const _rkRoot = document.getElementById('roleplay-kids-root');
if (_rkRoot) ReactDOM.createRoot(_rkRoot).render(React.createElement(App));
</script>
<?php
$content = ob_get_clean();
render_activity_viewer('Roleplay Kids', 'fa-solid fa-children', $content);
