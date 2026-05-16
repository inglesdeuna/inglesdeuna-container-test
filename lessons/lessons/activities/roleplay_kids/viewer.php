<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../core/_activity_viewer_template.php';

$activityId  = isset($_GET['id'])   ? trim((string) $_GET['id'])   : '';
$mode        = isset($_GET['mode']) ? trim((string) $_GET['mode']) : '';
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
window.RK_SAVED_SCENE  = <?= json_encode($savedScene) ?>;
window.RK_SAVED_TURNS  = <?= json_encode($savedTurns) ?>;
window.RK_ALLOW_EDITOR = <?= json_encode($allowEditor) ?>;
window.RK_START_VIEW   = <?= json_encode($startView) ?>;
</script>

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
  { id: "alex",  label: "Alex",  emoji: "🧑‍🚀" },
  { id: "sofia", label: "Sofia", emoji: "👸" },
  { id: "dino",  label: "Dino",  emoji: "🦕" },
  { id: "jack",  label: "Jack",  emoji: "🏴‍☠️" },
  { id: "hero",  label: "Hero",  emoji: "🦸" },
];
function avatarSrc(id) { return `assets/avatars/${id}.svg`; }

// ── DEFAULT DATA ───────────────────────────────────────────────
const DEFAULT_TURNS = [{ cue: "", hint: "" }];
const DEFAULT_SCENE = {
  title: "Kids Roleplay", desc: "Practice speaking English!",
  sceneImage: "", agentName: "Teacher", studentRole: "",
};

// ── TTS ────────────────────────────────────────────────────────
const ttsCache = {};
let currentAudio = null;
function playUrl(url) {
  if (currentAudio) { currentAudio.pause(); currentAudio = null; }
  currentAudio = new Audio(url);
  currentAudio.play().catch(() => {});
}
function playElevenLabs(text, voiceId, onDone, onError) {
  if (!text) return;
  const key = (voiceId || "") + "|" + text;
  if (ttsCache[key]) { playUrl(ttsCache[key]); if (onDone) onDone(); return; }
  const fd = new FormData();
  fd.append("text", text);
  if (voiceId) fd.append("voice_id", voiceId);
  fetch("tts.php", { method: "POST", body: fd, credentials: "same-origin" })
    .then(r => r.json())
    .then(data => {
      if (data.url) { ttsCache[key] = data.url; playUrl(data.url); if (onDone) onDone(); }
      else { if (onError) onError(); }
    })
    .catch(() => { if (onError) onError(); });
}

// ── SAVE ───────────────────────────────────────────────────────
function saveActivity(activityId, scene, turns) {
  if (!activityId) return Promise.resolve({ ok: false });
  const body = new FormData();
  body.append("id", activityId);
  body.append("data", JSON.stringify({ scene, turns }));
  return fetch("../../core/save_activity.php", { method: "POST", body, credentials: "same-origin" })
    .then(r => r.json());
}

// ── TEACHER ICON (inline SVG) ──────────────────────────────────
function TeacherIcon({ size = 68 }) {
  return (
    <svg width={size} height={size} viewBox="0 0 68 68" fill="none" xmlns="http://www.w3.org/2000/svg">
      <circle cx="34" cy="34" r="34" fill="#FFF0E6"/>
      <circle cx="34" cy="26" r="13" fill="#FCDDBF"/>
      <ellipse cx="34" cy="52" rx="18" ry="12" fill="#F97316"/>
      <circle cx="29" cy="24" r="2" fill="#C2580A"/>
      <circle cx="39" cy="24" r="2" fill="#C2580A"/>
      <path d="M29 30 Q34 34 39 30" stroke="#C2580A" strokeWidth="1.5" strokeLinecap="round" fill="none"/>
    </svg>
  );
}

// ── AVATAR IMG with emoji fallback ─────────────────────────────
function AvatarImg({ id, size = 68, style = {} }) {
  const av = AVATARS.find(a => a.id === id) || AVATARS[0];
  const [useFallback, setUseFallback] = useState(false);
  return useFallback ? (
    <div style={{
      width: size, height: size, borderRadius: "50%",
      background: C.purpleSoft, display: "flex", alignItems: "center",
      justifyContent: "center", fontSize: size * 0.5, ...style,
    }}>{av.emoji}</div>
  ) : (
    <img
      src={avatarSrc(id)}
      alt={av.label}
      onError={() => setUseFallback(true)}
      style={{ width: size, height: size, borderRadius: "50%", objectFit: "cover", ...style }}
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
      {/* Drop zone */}
      <div
        onClick={() => !uploading && inputRef.current && inputRef.current.click()}
        onDragOver={e => e.preventDefault()}
        onDrop={handleDrop}
        style={{
          border: `2px dashed ${value ? C.orange : C.purpleBorder}`,
          borderRadius: 14, padding: "14px 16px", cursor: uploading ? "default" : "pointer",
          background: value ? C.orangeSoft : "#FAFAFE",
          display: "flex", alignItems: "center", gap: 14, transition: "all .15s",
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
function EditorView({ scene: initScene, turns: initTurns, activityId, onSave, onPreview }) {
  const [scene, setScene]   = useState(initScene || DEFAULT_SCENE);
  const [turns, setTurns]   = useState(initTurns && initTurns.length ? initTurns : DEFAULT_TURNS);
  const [toast, setToast]   = useState(null);
  const [saving, setSaving] = useState(false);

  function sc(key, val) { setScene(s => ({ ...s, [key]: val })); }

  function addTurn() { setTurns(t => [...t, { cue: "", hint: "" }]); }
  function removeTurn(i) { setTurns(t => t.filter((_, idx) => idx !== i)); }
  function updateTurn(i, key, val) { setTurns(t => t.map((r, idx) => idx === i ? { ...r, [key]: val } : r)); }

  async function handleSave() {
    setSaving(true);
    try {
      const res = await saveActivity(activityId, scene, turns);
      setToast(res && res.ok !== false ? "Saved ✓" : "Save failed");
      if (res && res.ok !== false) onSave(scene, turns);
    } catch { setToast("Save failed"); }
    setSaving(false);
    setTimeout(() => setToast(null), 2500);
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
      {/* Topbar */}
      <div style={{ display: "flex", justifyContent: "space-between", alignItems: "center", marginBottom: 18 }}>
        <div style={{ fontFamily: "'Fredoka',sans-serif", fontSize: 24, fontWeight: 700, color: C.orange }}>
          🎭 Roleplay Kids — Editor
        </div>
        <button onClick={onPreview} style={{
          background: C.purpleSoft, color: C.purpleDark, border: `1px solid ${C.purpleBorder}`,
          borderRadius: 999, padding: "8px 16px", fontWeight: 800, fontSize: 13,
          fontFamily: "'Nunito',sans-serif", cursor: "pointer",
        }}>Preview ▶</button>
      </div>

      {/* Scene */}
      {card(<>
        <div style={{ fontWeight: 900, fontSize: 15, color: C.purpleDark, marginBottom: 14 }}>Scene Settings</div>
        <div style={{ display: "grid", gridTemplateColumns: "1fr 1fr", gap: 12 }}>
          <div>{label("Activity Title")}{inp(scene.title, v => sc("title", v), "e.g. At the Café")}</div>
          <div>{label("Scene Description")}{inp(scene.desc, v => sc("desc", v), "Short subtitle")}</div>
          <div>{label("Teacher / Agent Name")}{inp(scene.agentName, v => sc("agentName", v), "Teacher")}</div>
          <div>{label("Student Role Label")}{inp(scene.studentRole, v => sc("studentRole", v), "e.g. Customer")}</div>
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
        <div style={{ display: "flex", justifyContent: "space-between", alignItems: "center", marginBottom: 10 }}>
          <div style={{ fontWeight: 800, color: C.purpleDark, fontSize: 13 }}>Turn {i + 1}</div>
          {turns.length > 1 && (
            <button onClick={() => removeTurn(i)} style={{
              background: "#FEF2F2", color: "#B91C1C", border: "1px solid #FECACA",
              borderRadius: 999, padding: "4px 12px", fontSize: 12, fontWeight: 800,
              fontFamily: "'Nunito',sans-serif", cursor: "pointer",
            }}>Remove</button>
          )}
        </div>
        <div style={{ display: "flex", flexDirection: "column", gap: 10 }}>
          <div>
            {label("Cue — sentence the student repeats")}
            <textarea value={turn.cue} onChange={e => updateTurn(i, "cue", e.target.value)}
              placeholder="e.g. Can I have a coffee, please?" rows={2} style={{
                width: "100%", padding: "9px 12px", border: `1.5px solid ${C.purpleBorder}`,
                borderRadius: 10, fontSize: 14, fontFamily: "'Nunito',sans-serif",
                fontWeight: 700, resize: "vertical", outline: "none", color: C.ink,
              }} />
          </div>
          <div>
            {label("Hint (optional)")}
            {inp(turn.hint, v => updateTurn(i, "hint", v), "e.g. Use polite form")}
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

  const [phase,      setPhase]      = useState("avatar"); // avatar | playing | feedback | done
  const [avatarId,   setAvatarId]   = useState(null);
  const [turnIndex,  setTurnIndex]  = useState(0);
  const [pts,        setPts]        = useState(0);
  const [ttsLoading, setTtsLoading] = useState(false);
  const [micState,   setMicState]   = useState("idle"); // idle | recording | processing
  const [transcript, setTranscript] = useState("");
  const [pronScore,  setPronScore]  = useState(null);
  const recRef = useRef(null);

  const turn  = safeT[turnIndex] || { cue: "", hint: "" };
  const total = safeT.length;

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
      scorePronunciation(text, turn.cue);
    };
    rec.onerror = () => { setMicState("idle"); };
    rec.onend   = () => { if (micState === "recording") setMicState("idle"); };
    rec.start();
  }

  function stopRecording() {
    if (recRef.current) { try { recRef.current.stop(); } catch {} }
    setMicState("idle");
  }

  function scorePronunciation(text, expected) {
    const fd = new FormData();
    fd.append("transcript", text);
    fd.append("expected",   expected);
    fetch("../../core/pronunciation_score.php", { method: "POST", body: fd, credentials: "same-origin" })
      .then(r => r.json())
      .then(data => {
        const score = Number(data.score ?? 0);
        setPts(prev => prev + Math.round(score * 10));
        setPronScore(score);
        setMicState("idle");
        setPhase("feedback");
      })
      .catch(() => {
        // fallback: basic similarity score
        const sim = simpleSim(text, expected);
        setPts(prev => prev + Math.round(sim * 10));
        setPronScore(sim);
        setMicState("idle");
        setPhase("feedback");
      });
  }

  function simpleSim(a, b) {
    const wa = a.toLowerCase().split(/\s+/);
    const wb = b.toLowerCase().split(/\s+/);
    const matches = wa.filter(w => wb.includes(w)).length;
    return Math.round((matches / Math.max(wb.length, 1)) * 100);
  }

  function handleMicClick() {
    if (micState === "recording") stopRecording();
    else if (micState === "idle") startRecording();
  }

  function handleListen() {
    setTtsLoading(true);
    playElevenLabs(turn.cue, null, () => setTtsLoading(false), () => setTtsLoading(false));
  }

  function handleTryAgain() {
    setTranscript(""); setPronScore(null); setMicState("idle"); setPhase("playing");
  }

  function handleNext() {
    setTranscript(""); setPronScore(null); setMicState("idle");
    if (turnIndex < total - 1) { setTurnIndex(i => i + 1); setPhase("playing"); }
    else { setPhase("done"); }
  }

  function handleRestart() {
    setPhase("avatar"); setAvatarId(null); setTurnIndex(0);
    setPts(0); setTranscript(""); setPronScore(null); setMicState("idle");
  }

  // ── Progress pct ─────────────────────────────────────────────
  const pct = total > 0 ? Math.round(((turnIndex + (phase === "done" ? 1 : 0)) / total) * 100) : 0;

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

      <button onClick={() => setPhase("playing")} disabled={!avatarId} style={{
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
  // DONE SCREEN
  // ════════════════════════════════════════════════════════════
  if (phase === "done") return (
    <div style={{ maxWidth: 500, margin: "0 auto", padding: "28px 16px", textAlign: "center" }}>
      <div style={{
        background: "linear-gradient(160deg,#EEEDFE,#FFF0E6)", borderRadius: 24,
        padding: "40px 28px", marginBottom: 20,
        boxShadow: "0 8px 32px rgba(127,119,221,.14)",
      }}>
        <div style={{ fontSize: 64, marginBottom: 12 }}>🎉</div>
        <h2 style={{
          fontFamily: "'Fredoka',sans-serif", fontSize: "clamp(26px,5vw,38px)",
          fontWeight: 700, color: C.purple, marginBottom: 12,
        }}>Great job!</h2>
        <div style={{ marginBottom: 20 }}>
          <div style={{ fontSize: 13, fontWeight: 800, color: C.muted, textTransform: "uppercase",
            letterSpacing: ".06em", marginBottom: 4 }}>Total Score</div>
          <div style={{
            display: "inline-block", background: C.orangeSoft, border: `1.5px solid ${C.orangeBorder}`,
            borderRadius: 999, padding: "8px 24px",
            fontFamily: "'Fredoka',sans-serif", fontSize: 32, fontWeight: 700, color: C.orange,
          }}>{pts} pts</div>
        </div>
        <p style={{ fontSize: 14, fontWeight: 700, color: C.muted }}>
          You completed {total} {total === 1 ? "turn" : "turns"}. Keep practicing!
        </p>
      </div>
      <button onClick={handleRestart} style={{
        background: C.orange, color: C.white, border: "none", borderRadius: 999,
        padding: "13px 36px", fontFamily: "'Nunito',sans-serif",
        fontWeight: 900, fontSize: 15, cursor: "pointer",
        boxShadow: "0 6px 18px rgba(249,115,22,.28)",
      }}>Try Again ↺</button>
    </div>
  );

  // ════════════════════════════════════════════════════════════
  // PLAYING + FEEDBACK
  // ════════════════════════════════════════════════════════════
  return (
    <div style={{ maxWidth: 640, margin: "0 auto", padding: "16px" }}>

      {/* ── Board card ─────────────────────────────────────── */}
      <div style={{
        background: C.white, border: `1px solid #F0EEF8`, borderRadius: 34,
        boxShadow: "0 8px 40px rgba(127,119,221,.13)", padding: "18px 18px 20px",
      }}>

        {/* Header row */}
        <div style={{ display: "flex", justifyContent: "space-between", alignItems: "center", marginBottom: 12 }}>
          <div style={{
            background: C.purpleSoft, border: `1px solid ${C.purpleBorder}`,
            borderRadius: 999, padding: "5px 14px", fontSize: 12, fontWeight: 800, color: C.purpleDark,
          }}>Turn {turnIndex + 1} / {total}</div>
          <div style={{
            background: C.orangeSoft, border: `1.5px solid ${C.orangeBorder}`,
            borderRadius: 999, padding: "5px 14px",
            fontFamily: "'Fredoka',sans-serif", fontSize: 16, fontWeight: 700, color: C.orange,
          }}>{pts} pts</div>
        </div>

        {/* Progress bar */}
        <div style={{
          height: 12, background: "#F4F2FD", border: "1px solid #E4E1F8",
          borderRadius: 999, overflow: "hidden", marginBottom: 14,
        }}>
          <div style={{
            height: "100%", width: `${pct}%`,
            background: `linear-gradient(90deg,${C.orange},${C.purple})`,
            borderRadius: 999, transition: "width .4s",
          }} />
        </div>

        {/* Scene card */}
        <div style={{
          position: "relative", borderRadius: 20, height: 180,
          overflow: "hidden", marginBottom: 16,
          background: scene.sceneImage
            ? "transparent"
            : "linear-gradient(160deg,#EDE9FA,#FFF0E6)",
        }}>
          {scene.sceneImage && (
            <img src={scene.sceneImage} alt="" style={{
              width: "100%", height: "100%", objectFit: "cover",
            }} />
          )}

          {/* Teacher speech bubble (top-right) */}
          <div style={{
            position: "absolute", top: 10, right: 10,
            background: C.white, borderRadius: "16px 16px 4px 16px",
            padding: "8px 12px", maxWidth: 180,
            boxShadow: "0 4px 14px rgba(0,0,0,.12)",
            fontSize: 12, fontFamily: "'Fredoka',sans-serif",
            fontWeight: 600, color: C.purpleDark, lineHeight: 1.4,
          }}>
            {turn.cue || "…"}
          </div>

          {/* Student avatar (bottom-left) */}
          <div style={{ position: "absolute", bottom: 10, left: 12, display: "flex", flexDirection: "column", alignItems: "center", gap: 4 }}>
            <div style={{ border: "3px solid white", borderRadius: "50%", boxShadow: "0 4px 12px rgba(0,0,0,.18)" }}>
              <AvatarImg id={avatarId} size={56} />
            </div>
            <div style={{
              background: "rgba(83,74,183,.75)", color: C.white,
              borderRadius: 999, padding: "2px 10px", fontSize: 10, fontWeight: 800,
            }}>{scene.studentRole || "You"}</div>
          </div>

          {/* Teacher avatar (bottom-right) */}
          <div style={{ position: "absolute", bottom: 10, right: 12, display: "flex", flexDirection: "column", alignItems: "center", gap: 4 }}>
            <div style={{ border: "3px solid white", borderRadius: "50%", boxShadow: "0 4px 12px rgba(0,0,0,.18)" }}>
              <TeacherIcon size={56} />
            </div>
            <div style={{
              background: "rgba(194,88,10,.75)", color: C.white,
              borderRadius: 999, padding: "2px 10px", fontSize: 10, fontWeight: 800,
            }}>{scene.agentName || "Teacher"}</div>
          </div>
        </div>

        {/* CUE box */}
        <div style={{
          position: "relative", background: C.purpleSoft,
          border: `1.5px solid ${C.purpleBorder}`, borderRadius: 20,
          padding: "16px 56px 16px 18px", marginBottom: 16,
        }}>
          <div style={{
            fontSize: 10, fontWeight: 900, color: C.purple, textTransform: "uppercase",
            letterSpacing: ".07em", marginBottom: 6,
          }}>🗣 Say this:</div>
          <div style={{
            fontFamily: "'Fredoka',sans-serif", fontSize: "clamp(17px,3.5vw,22px)",
            fontWeight: 600, color: C.purpleDark, textAlign: "center", lineHeight: 1.35,
          }}>{turn.cue || "—"}</div>
          {turn.hint && (
            <div style={{ fontSize: 12, fontWeight: 700, color: C.muted, textAlign: "center", marginTop: 6 }}>
              💡 {turn.hint}
            </div>
          )}
          {/* Listen button */}
          <button onClick={handleListen} disabled={ttsLoading} style={{
            position: "absolute", top: 12, right: 12,
            background: ttsLoading ? C.muted : C.purple, color: C.white, border: "none",
            borderRadius: 999, padding: "6px 10px", fontSize: 13, fontWeight: 900,
            fontFamily: "'Nunito',sans-serif", cursor: ttsLoading ? "default" : "pointer",
            boxShadow: "0 4px 12px rgba(127,119,221,.28)",
          }}>{ttsLoading ? "…" : "🔊"}</button>
        </div>

        {/* FEEDBACK bubble (phase === "feedback") */}
        {phase === "feedback" && pronScore !== null && (
          <div style={{ marginBottom: 16, display: "flex", flexDirection: "column", gap: 8 }}>
            {transcript && (
              <div style={{
                background: C.purpleSoft, borderLeft: `3px solid ${C.purple}`,
                borderRadius: 12, padding: "10px 14px",
              }}>
                <div style={{ fontSize: 10, fontWeight: 900, color: C.purple, textTransform: "uppercase", marginBottom: 4 }}>You said</div>
                <div style={{ fontFamily: "'Fredoka',sans-serif", fontSize: 15, fontWeight: 600, color: C.ink }}>"{transcript}"</div>
              </div>
            )}
            <div style={{
              background: C.orangeSoft, borderLeft: `3px solid ${C.orange}`,
              borderRadius: 12, padding: "10px 14px",
            }}>
              <div style={{ fontSize: 10, fontWeight: 900, color: C.orange, textTransform: "uppercase", marginBottom: 4 }}>Try saying</div>
              <div style={{ fontFamily: "'Fredoka',sans-serif", fontSize: 15, fontWeight: 600, color: C.orangeDark }}>{turn.cue}</div>
            </div>
            {/* Score bar */}
            <div style={{ background: C.purpleSoft, borderRadius: 12, padding: "10px 14px" }}>
              <div style={{ display: "flex", justifyContent: "space-between", alignItems: "center", marginBottom: 6 }}>
                <div style={{ fontSize: 11, fontWeight: 900, color: C.muted, textTransform: "uppercase" }}>Pronunciation score</div>
                <div style={{ fontFamily: "'Fredoka',sans-serif", fontSize: 18, fontWeight: 700, color: C.orange }}>{pronScore} pts</div>
              </div>
              <div style={{ height: 8, background: "#F4F2FD", borderRadius: 999, overflow: "hidden" }}>
                <div style={{
                  height: "100%", width: `${pronScore}%`,
                  background: `linear-gradient(90deg,${C.orange},${C.purple})`,
                  borderRadius: 999, transition: "width .5s",
                }} />
              </div>
            </div>
          </div>
        )}

        {/* Mic button + label (playing phase only) */}
        {phase === "playing" && (
          <div style={{ textAlign: "center", marginBottom: 16 }}>
            <button onClick={handleMicClick} disabled={micState === "processing"} style={{
              width: 72, height: 72, borderRadius: "50%", border: "none",
              background: micState === "recording" ? "#E24B4A" : C.orange,
              color: C.white, fontSize: 28, cursor: micState === "processing" ? "default" : "pointer",
              boxShadow: micState === "recording"
                ? "0 0 0 8px rgba(226,75,74,.2), 0 6px 18px rgba(226,75,74,.4)"
                : "0 6px 18px rgba(249,115,22,.32)",
              transition: "all .2s",
              animation: micState === "recording" ? "rk-pulse 1s ease-in-out infinite" : "none",
            }}>
              {micState === "processing" ? "⏳" : micState === "recording" ? "⏹" : "🎤"}
            </button>
            <div style={{
              marginTop: 8, fontSize: 12, fontWeight: 800,
              color: micState === "recording" ? "#E24B4A" : C.muted,
            }}>
              {micState === "processing" ? "Processing…" :
               micState === "recording" ? "Recording… tap to stop" :
               "Tap the mic and speak!"}
            </div>
          </div>
        )}

        {/* Action buttons */}
        <div style={{ display: "flex", gap: 8, justifyContent: "center", flexWrap: "wrap" }}>
          {phase === "feedback" ? (
            <>
              <button onClick={handleTryAgain} style={{
                flex: 1, minWidth: 100, padding: "11px 8px", border: `1.5px solid ${C.purpleBorder}`,
                borderRadius: 999, background: C.white, color: C.purple,
                fontFamily: "'Nunito',sans-serif", fontWeight: 900, fontSize: 13, cursor: "pointer",
              }}>Try Again</button>
              <button onClick={() => { playElevenLabs(turn.cue, null); }} style={{
                flex: 1, minWidth: 100, padding: "11px 8px", border: "none",
                borderRadius: 999, background: C.purple, color: C.white,
                fontFamily: "'Nunito',sans-serif", fontWeight: 900, fontSize: 13, cursor: "pointer",
              }}>Show Answer</button>
              <button onClick={handleNext} style={{
                flex: 1, minWidth: 100, padding: "11px 8px", border: "none",
                borderRadius: 999, background: C.orange, color: C.white,
                fontFamily: "'Nunito',sans-serif", fontWeight: 900, fontSize: 13, cursor: "pointer",
                boxShadow: "0 4px 14px rgba(249,115,22,.28)",
              }}>{turnIndex < total - 1 ? "Next →" : "Finish 🎉"}</button>
            </>
          ) : (
            <button onClick={() => setPhase("feedback")} style={{
              padding: "11px 24px", border: `1.5px solid ${C.purpleBorder}`,
              borderRadius: 999, background: C.white, color: C.muted,
              fontFamily: "'Nunito',sans-serif", fontWeight: 800, fontSize: 13, cursor: "pointer",
            }}>Skip this turn</button>
          )}
        </div>

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
        activityId={window.RK_ACTIVITY_ID}
        onSave={(s, t) => { setScene(s); setTurns(t); }}
        onPreview={() => setView("player")}
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
