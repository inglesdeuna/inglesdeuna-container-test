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
  // subPhase: "teacher" | "writing" | "recording" | "feedback"
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
      playElevenLabs(turn.teacherLine, voiceId, null, null);
    }
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
    if (turnIndex < total - 1) {
      setTurnIndex(i => i + 1);
      setSubPhase("teacher");
    } else {
      setPhase("done");
    }
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
  // PLAYING SCREEN
  // ════════════════════════════════════════════════════════════
  const btn = (label, onClick, bg = C.orange, disabled = false, extra = {}) => (
    <button onClick={onClick} disabled={disabled} style={{
      flex: 1, minWidth: 110, padding: "11px 10px", border: "none",
      borderRadius: 999, background: disabled ? C.purpleSoft : bg, color: disabled ? C.muted : C.white,
      fontFamily: "'Nunito',sans-serif", fontWeight: 900, fontSize: 13,
      cursor: disabled ? "default" : "pointer",
      boxShadow: disabled ? "none" : `0 4px 14px rgba(0,0,0,.12)`,
      transition: "all .15s", ...extra,
    }}>{label}</button>
  );
  const ghostBtn = (label, onClick) => (
    <button onClick={onClick} style={{
      flex: 1, minWidth: 110, padding: "11px 10px",
      border: `1.5px solid ${C.purpleBorder}`, borderRadius: 999,
      background: C.white, color: C.purple,
      fontFamily: "'Nunito',sans-serif", fontWeight: 900, fontSize: 13, cursor: "pointer",
    }}>{label}</button>
  );

  return (
    <div style={{ maxWidth: 820, margin: "0 auto", padding: "16px" }}>
      <div style={{
        background: C.white, border: `1px solid #F0EEF8`, borderRadius: 34,
        boxShadow: "0 8px 40px rgba(127,119,221,.13)", padding: "20px 22px 22px",
      }}>

        {/* Header */}
        <div style={{ display: "flex", justifyContent: "space-between", alignItems: "center", marginBottom: 12 }}>
          <div style={{ background: C.purpleSoft, border: `1px solid ${C.purpleBorder}`, borderRadius: 999, padding: "5px 14px", fontSize: 12, fontWeight: 800, color: C.purpleDark }}>
            Turn {turnIndex + 1} / {total}
          </div>
          <div style={{ background: C.orangeSoft, border: `1.5px solid ${C.orangeBorder}`, borderRadius: 999, padding: "5px 14px", fontFamily: "'Fredoka',sans-serif", fontSize: 16, fontWeight: 700, color: C.orange }}>
            {pts} pts
          </div>
        </div>

        {/* Progress bar */}
        <div style={{ height: 12, background: "#F4F2FD", border: "1px solid #E4E1F8", borderRadius: 999, overflow: "hidden", marginBottom: 14 }}>
          <div style={{ height: "100%", width: `${pct}%`, background: `linear-gradient(90deg,${C.orange},${C.purple})`, borderRadius: 999, transition: "width .4s" }} />
        </div>

        {/* Scene card */}
        <div style={{ position: "relative", borderRadius: 20, height: 220, overflow: "hidden", marginBottom: 16, background: scene.sceneImage ? "transparent" : "linear-gradient(160deg,#EDE9FA,#FFF0E6)" }}>
          {scene.sceneImage && <img src={scene.sceneImage} alt="" style={{ width: "100%", height: "100%", objectFit: "cover" }} />}

          {/* Teacher speech bubble */}
          <div style={{ position: "absolute", top: 10, right: 76, background: C.white, borderRadius: "14px 14px 4px 14px", padding: "8px 12px", maxWidth: 180, boxShadow: "0 4px 14px rgba(0,0,0,.12)", fontSize: 12, fontFamily: "'Fredoka',sans-serif", fontWeight: 600, color: C.purpleDark, lineHeight: 1.4 }}>
            {turn.teacherLine || "…"}
          </div>

          {/* Student avatar (bottom-left) */}
          <div style={{ position: "absolute", bottom: 8, left: 10, display: "flex", flexDirection: "column", alignItems: "center", gap: 3 }}>
            <div style={{ border: "3px solid white", borderRadius: "50%", boxShadow: "0 4px 12px rgba(0,0,0,.18)" }}>
              <AvatarImg id={avatarId} size={52} />
            </div>
            <div style={{ background: "rgba(83,74,183,.8)", color: C.white, borderRadius: 999, padding: "2px 8px", fontSize: 10, fontWeight: 800 }}>{avatarLabel}</div>
          </div>

          {/* Teacher avatar (bottom-right) */}
          <div style={{ position: "absolute", bottom: 8, right: 10, display: "flex", flexDirection: "column", alignItems: "center", gap: 3 }}>
            <div style={{ border: "3px solid white", borderRadius: "50%", boxShadow: "0 4px 12px rgba(0,0,0,.18)" }}>
              <TeacherImg size={52} />
            </div>
            <div style={{ background: "rgba(194,88,10,.8)", color: C.white, borderRadius: 999, padding: "2px 8px", fontSize: 10, fontWeight: 800 }}>{scene.agentName || "Teacher"}</div>
          </div>
        </div>

        {/* ── STEP 1: Teacher just spoke — student listens ── */}
        {subPhase === "teacher" && (
          <div style={{ textAlign: "center", padding: "8px 0 16px" }}>
            <div style={{ fontSize: 13, fontWeight: 800, color: C.muted, marginBottom: 16 }}>
              🔊 Teacher is speaking… listen carefully!
            </div>
            <div style={{ display: "flex", gap: 8, justifyContent: "center" }}>
              {ghostBtn("🔊 Play again", () => playElevenLabs(turn.teacherLine, voiceId, null, null))}
              {btn("Write my answer →", () => setSubPhase("writing"), C.orange)}
            </div>
          </div>
        )}

        {/* ── STEP 2: Student writes their answer ── */}
        {subPhase === "writing" && (
          <div style={{ marginBottom: 0 }}>
            <div style={{ fontSize: 11, fontWeight: 900, color: C.purple, textTransform: "uppercase", letterSpacing: ".06em", marginBottom: 8 }}>
              ✏️ Write your answer:
            </div>
            <textarea
              value={written}
              onChange={e => setWritten(e.target.value)}
              placeholder="Type what you would say…"
              rows={3}
              style={{
                width: "100%", padding: "12px 14px", border: `2px solid ${C.purpleBorder}`,
                borderRadius: 14, fontSize: 15, fontFamily: "'Nunito',sans-serif",
                fontWeight: 700, resize: "none", outline: "none", color: C.ink,
                background: C.purpleSoft, marginBottom: 12,
              }}
            />
            <div style={{ display: "flex", gap: 8 }}>
              {ghostBtn("← Listen again", () => { setSubPhase("teacher"); playElevenLabs(turn.teacherLine, voiceId, null, null); })}
              {btn("Now say it 🎤", () => setSubPhase("recording"), C.purple, written.trim() === "")}
            </div>
          </div>
        )}

        {/* ── STEP 3: Record pronunciation ── */}
        {subPhase === "recording" && (
          <div>
            {/* Show expected sentence */}
            <div style={{ background: C.purpleSoft, border: `1.5px solid ${C.purpleBorder}`, borderRadius: 16, padding: "12px 16px", marginBottom: 14, textAlign: "center" }}>
              <div style={{ fontSize: 10, fontWeight: 900, color: C.purple, textTransform: "uppercase", letterSpacing: ".06em", marginBottom: 6 }}>🗣 Say this:</div>
              <div style={{ fontFamily: "'Fredoka',sans-serif", fontSize: "clamp(16px,3vw,21px)", fontWeight: 600, color: C.purpleDark, lineHeight: 1.35 }}>{turn.studentLine || "—"}</div>
            </div>

            {/* Mic button */}
            <div style={{ textAlign: "center", marginBottom: 14 }}>
              <button
                onClick={() => micState === "recording" ? stopRecording() : startRecording()}
                disabled={micState === "processing"}
                style={{
                  width: 72, height: 72, borderRadius: "50%", border: "none",
                  background: micState === "recording" ? "#E24B4A" : C.orange,
                  color: C.white, fontSize: 28, cursor: micState === "processing" ? "default" : "pointer",
                  boxShadow: micState === "recording" ? "0 0 0 8px rgba(226,75,74,.2), 0 6px 18px rgba(226,75,74,.4)" : "0 6px 18px rgba(249,115,22,.32)",
                  animation: micState === "recording" ? "rk-pulse 1s ease-in-out infinite" : "none",
                }}>
                {micState === "processing" ? "⏳" : micState === "recording" ? "⏹" : "🎤"}
              </button>
              <div style={{ marginTop: 8, fontSize: 12, fontWeight: 800, color: micState === "recording" ? "#E24B4A" : C.muted }}>
                {micState === "processing" ? "Processing…" : micState === "recording" ? "Recording… tap to stop" : "Tap the mic and speak!"}
              </div>
            </div>

            {ghostBtn("← Back to writing", () => setSubPhase("writing"))}
          </div>
        )}

        {/* ── STEP 4: Feedback ── */}
        {subPhase === "feedback" && (
          <div>
            {written.trim() && (
              <div style={{ background: C.purpleSoft, borderLeft: `3px solid ${C.purple}`, borderRadius: 12, padding: "10px 14px", marginBottom: 8 }}>
                <div style={{ fontSize: 10, fontWeight: 900, color: C.purple, textTransform: "uppercase", marginBottom: 4 }}>You wrote</div>
                <div style={{ fontFamily: "'Fredoka',sans-serif", fontSize: 14, fontWeight: 600, color: C.ink }}>"{written}"</div>
              </div>
            )}
            {transcript && (
              <div style={{ background: "#F0F9FF", borderLeft: `3px solid #38BDF8`, borderRadius: 12, padding: "10px 14px", marginBottom: 8 }}>
                <div style={{ fontSize: 10, fontWeight: 900, color: "#0369A1", textTransform: "uppercase", marginBottom: 4 }}>You said</div>
                <div style={{ fontFamily: "'Fredoka',sans-serif", fontSize: 14, fontWeight: 600, color: C.ink }}>"{transcript}"</div>
              </div>
            )}
            <div style={{ background: C.orangeSoft, borderLeft: `3px solid ${C.orange}`, borderRadius: 12, padding: "10px 14px", marginBottom: 8 }}>
              <div style={{ fontSize: 10, fontWeight: 900, color: C.orange, textTransform: "uppercase", marginBottom: 4 }}>Correct answer</div>
              <div style={{ fontFamily: "'Fredoka',sans-serif", fontSize: 14, fontWeight: 600, color: C.orangeDark }}>{turn.studentLine}</div>
            </div>
            {pronScore !== null && (
              <div style={{ background: C.purpleSoft, borderRadius: 12, padding: "10px 14px", marginBottom: 12 }}>
                <div style={{ display: "flex", justifyContent: "space-between", alignItems: "center", marginBottom: 6 }}>
                  <div style={{ fontSize: 11, fontWeight: 900, color: C.muted, textTransform: "uppercase" }}>Pronunciation score</div>
                  <div style={{ fontFamily: "'Fredoka',sans-serif", fontSize: 18, fontWeight: 700, color: C.orange }}>{pronScore} pts</div>
                </div>
                <div style={{ height: 8, background: "#F4F2FD", borderRadius: 999, overflow: "hidden" }}>
                  <div style={{ height: "100%", width: `${pronScore}%`, background: `linear-gradient(90deg,${C.orange},${C.purple})`, borderRadius: 999, transition: "width .5s" }} />
                </div>
              </div>
            )}
            <div style={{ display: "flex", gap: 8 }}>
              {ghostBtn("Try Again ↺", () => { setSubPhase("recording"); setTranscript(""); setPronScore(null); })}
              {btn(turnIndex < total - 1 ? "Next →" : "Finish 🎉", goToNextTurn, C.orange)}
            </div>
          </div>
        )}

      </div>
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
