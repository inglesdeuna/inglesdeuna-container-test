<?php
if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../core/_activity_viewer_template.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

$activityId = isset($_GET['id']) ? trim((string) $_GET['id']) : '';
$mode = isset($_GET['mode']) ? trim((string) $_GET['mode']) : '';
$returnTo = isset($_GET['return_to']) ? trim((string) $_GET['return_to']) : '';

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
$startView = $allowEditor ? 'editor' : 'player';

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

  error_log('[roleplay_kids/viewer] Viewer loaded data ' . json_encode([
    'activity_id' => $activityId,
    'viewer_path' => $_SERVER['REQUEST_URI'] ?? '',
    'has_scene' => is_array($savedScene),
    'turns_count' => is_array($savedTurns) ? count($savedTurns) : 0,
  ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

// Backward compatibility: map old roleplay_kids schema to roleplay schema.
if (is_array($savedScene)) {
  if (!isset($savedScene['icon']) || trim((string) $savedScene['icon']) === '') {
    $savedScene['icon'] = '🎭';
  }
  if (!isset($savedScene['agentRole']) || trim((string) $savedScene['agentRole']) === '') {
    $savedScene['agentRole'] = 'Teacher';
  }
  if (!isset($savedScene['studentRole']) || trim((string) $savedScene['studentRole']) === '') {
    $savedScene['studentRole'] = 'Student';
  }
  if (!isset($savedScene['sceneImage'])) {
    $savedScene['sceneImage'] = '';
  }
  if (!isset($savedScene['teacherAvatarId']) || trim((string) $savedScene['teacherAvatarId']) === '') {
    $savedScene['teacherAvatarId'] = 'TEACHER';
  }
  if (!isset($savedScene['studentAvatarId']) || trim((string) $savedScene['studentAvatarId']) === '') {
    $savedScene['studentAvatarId'] = 'ANGIE';
  }
  $legacyVoice = '';
  if (isset($savedScene['teacherVoice'])) {
    $legacyVoice = trim((string) $savedScene['teacherVoice']);
  } elseif (isset($savedScene['voice_id'])) {
    $legacyVoice = trim((string) $savedScene['voice_id']);
  } elseif (isset($savedScene['voiceId'])) {
    $legacyVoice = trim((string) $savedScene['voiceId']);
  }

  if (!isset($savedScene['teacherVoiceId']) || trim((string) $savedScene['teacherVoiceId']) === '') {
    $savedScene['teacherVoiceId'] = $legacyVoice !== '' ? $legacyVoice : 'nzFihrBIvB34imQBuxub';
  }
}

if (is_array($savedTurns)) {
  $normalizedTurns = [];
  foreach ($savedTurns as $turn) {
    if (!is_array($turn)) {
      continue;
    }

    if (array_key_exists('agent', $turn) || array_key_exists('ideal', $turn) || array_key_exists('hint', $turn) || array_key_exists('criteria', $turn)) {
      $normalizedTurns[] = [
        'agent' => (string) ($turn['agent'] ?? ''),
        'hint' => (string) ($turn['hint'] ?? ''),
        'ideal' => (string) ($turn['ideal'] ?? ''),
        'criteria' => (string) ($turn['criteria'] ?? ''),
      ];
      continue;
    }

    $teacherLine = (string) ($turn['teacherLine'] ?? '');
    $studentLine = (string) ($turn['studentLine'] ?? '');
    $normalizedTurns[] = [
      'agent' => $teacherLine,
      'hint' => $studentLine,
      'ideal' => $studentLine,
      'criteria' => '',
    ];
  }
  $savedTurns = $normalizedTurns;
}

ob_start();
?>
<link href="https://fonts.googleapis.com/css2?family=Fredoka:wght@400;600&family=Nunito:wght@400;600;700&display=swap" rel="stylesheet">
<style>
#roleplay-kids-root * { box-sizing: border-box; margin: 0; padding: 0; }
#roleplay-kids-root { font-family: 'Nunito', sans-serif; flex: 1; min-height: 0; overflow-y: auto; }
body { background: #ffffff; font-family: 'Nunito', sans-serif; }
@keyframes rp-spin { to { transform: rotate(360deg); } }
@keyframes rp-pulse { 0%,100%{opacity:1} 50%{opacity:.3} }
</style>

<div id="roleplay-kids-root" style="flex:1;min-height:0;overflow-y:auto;"></div>

<script crossorigin src="https://unpkg.com/react@18/umd/react.production.min.js"></script>
<script crossorigin src="https://unpkg.com/react-dom@18/umd/react-dom.production.min.js"></script>
<script src="https://unpkg.com/@babel/standalone/babel.min.js"></script>

<script>
window.RK_ACTIVITY_ID = <?= json_encode($activityId) ?>;
window.RK_RETURN_TO = <?= json_encode($returnTo) ?>;
window.RK_SAVED_SCENE  = <?= json_encode($savedScene) ?>;
window.RK_SAVED_TURNS  = <?= json_encode($savedTurns) ?>;
window.RK_ALLOW_EDITOR = <?= json_encode($allowEditor) ?>;
window.RK_START_VIEW = <?= json_encode($startView) ?>;
</script>

<script type="text/babel">
const { useState, useRef, useEffect, useCallback } = React;

// ── DESIGN TOKENS ────────────────────────────────────────────
const C = {
  orange: "#F97316",
  purple: "#7F77DD",
  purpleLight: "#EDE9FA",
  purpleMid: "#9B8FCC",
  purpleSub: "#9B8FCC",
  orangeLight: "#FFF0E6",
  orangeMid: "#bf521a",
  cardBorder: "#EDE9FA",
  bg: "#ffffff",
  bgCard: "#F9F8FF",
  white: "#ffffff",
  green: "#F0FDF4",
  greenText: "#166534",
};

const DEFAULT_TURNS = [
  { agent: "", hint: "", ideal: "", criteria: "" },
];

const BLOCK_PASS_SCORE = 80;

const DEFAULT_SCENE = {
  title: "Kids Roleplay", icon: "🎭", desc: "Practice speaking English!", agentName: "Teacher", agentRole: "Teacher", studentRole: "Student", sceneImage: "", teacherAvatarId: "TEACHER", studentAvatarId: "ANGIE", teacherVoiceId: "nzFihrBIvB34imQBuxub",
};

const RK_VOICES = [
  { id: "nzFihrBIvB34imQBuxub", label: "Adult Male (Josh)" },
  { id: "NoOVOzCQFLOvtsMoNcdT", label: "Adult Female (Lily)" },
  { id: "Nggzl2QAXh3OijoXD116", label: "Child (Candy)" },
];

const DEFAULT_TEACHER_VOICE_ID = "nzFihrBIvB34imQBuxub";
const ALLOWED_TEACHER_VOICE_IDS = new Set(RK_VOICES.map(v => v.id));
const TEACHER_VOICE_FIELD = "teacherVoiceId";

function resolveTeacherVoiceId(scene) {
  const direct = String(scene?.[TEACHER_VOICE_FIELD] || "").trim();
  if (ALLOWED_TEACHER_VOICE_IDS.has(direct)) return direct;

  const legacy = String(scene?.teacherVoice || scene?.voice_id || scene?.voiceId || "").trim();
  if (ALLOWED_TEACHER_VOICE_IDS.has(legacy)) return legacy;

  return DEFAULT_TEACHER_VOICE_ID;
}

function canonicalizeScene(scene) {
  const base = (scene && typeof scene === "object") ? scene : {};
  const canonical = { ...base, [TEACHER_VOICE_FIELD]: resolveTeacherVoiceId(base) };
  delete canonical.teacherVoice;
  delete canonical.voice_id;
  delete canonical.voiceId;
  return canonical;
}

function buildTtsError(message, code) {
  const err = new Error(message || "TTS error");
  err.code = code || "tts_error";
  return err;
}

async function fetchElevenLabsAudioBlob(text, voiceId) {
  const cleanText = String(text || "").trim();
  if (!cleanText) throw buildTtsError("Text is required for TTS", "missing_text");

  const cleanVoiceId = String(voiceId || "").trim();
  if (!cleanVoiceId) throw buildTtsError("Teacher voice is missing", "missing_voice_id");
  if (!ALLOWED_TEACHER_VOICE_IDS.has(cleanVoiceId)) {
    throw buildTtsError(`Invalid teacher voice ID: ${cleanVoiceId}`, "invalid_voice_id");
  }

  const fd = new FormData();
  fd.append("text", cleanText);
  fd.append("voice_id", cleanVoiceId);

  let res;
  try {
    res = await fetch("tts.php", {
      method: "POST",
      body: fd,
      credentials: "same-origin",
      cache: "no-store",
    });
  } catch (err) {
    throw buildTtsError("Could not contact ElevenLabs TTS endpoint", "elevenlabs_request_failed");
  }

  if (!res.ok) {
    let detail = `TTS error ${res.status}`;
    try {
      const j = await res.json();
      if (j && typeof j.error === "string" && j.error.trim() !== "") detail = j.error;
    } catch (e) {
      // Ignore non-JSON error responses.
    }
    throw buildTtsError(detail, "elevenlabs_request_failed");
  }

  return await res.blob();
}

async function playAudioBlob(blob, audioRef, hooks = {}) {
  const onPlaying = typeof hooks.onPlaying === "function" ? hooks.onPlaying : () => {};
  const onIdle = typeof hooks.onIdle === "function" ? hooks.onIdle : () => {};

  if (audioRef && audioRef.current) {
    audioRef.current.pause();
    audioRef.current = null;
  }

  const url = URL.createObjectURL(blob);
  await new Promise((resolve, reject) => {
    const audio = new Audio(url);
    if (audioRef) audioRef.current = audio;
    onPlaying();

    audio.onended = () => {
      URL.revokeObjectURL(url);
      if (audioRef) audioRef.current = null;
      onIdle();
      resolve();
    };
    audio.onerror = () => {
      URL.revokeObjectURL(url);
      if (audioRef) audioRef.current = null;
      onIdle();
      reject(buildTtsError("Audio playback failed", "playback_failed"));
    };

    audio.play().catch(() => {
      URL.revokeObjectURL(url);
      if (audioRef) audioRef.current = null;
      onIdle();
      reject(buildTtsError("Audio playback blocked", "playback_failed"));
    });
  });
}

const RK_AVATARS = [
  { id: "TEACHER", label: "Teacher" },
  { id: "ANGIE", label: "Angie" },
  { id: "ANY", label: "Any" },
  { id: "BENNY", label: "Benny" },
  { id: "JAY JAY", label: "Jay Jay" },
  { id: "JESUS", label: "Jesus" },
  { id: "JOHN", label: "John" },
  { id: "LeeAnn", label: "LeeAnn" },
  { id: "MARY JAY", label: "Mary Jay" },
  { id: "NELLA", label: "Nella" },
  { id: "VICTOR", label: "Victor" },
  { id: "VIOLET", label: "Violet" },
];

function avatarSrc(id) {
  return `assets/avatars/${encodeURIComponent(String(id || "ANGIE"))}.png`;
}

// ── SHARED COMPONENTS ─────────────────────────────────────────
const Kicker = ({ children }) => (
  <span style={{
    display: "inline-block", background: C.orangeLight, color: C.orange,
    fontSize: 11, fontWeight: 800, letterSpacing: ".07em", padding: "3px 12px",
    borderRadius: 20, textTransform: "uppercase", marginBottom: 12,
  }}>{children}</span>
);

const Card = ({ children, style = {} }) => (
  <div style={{
    background: C.white, border: `1.5px solid ${C.cardBorder}`,
    borderRadius: 24, padding: 20,
    boxShadow: "0 4px 24px rgba(127,119,221,.10)", ...style,
  }}>{children}</div>
);

const Btn = ({ children, onClick, color = C.orange, style = {}, disabled = false }) => (
  <button onClick={onClick} disabled={disabled} style={{
    background: disabled ? C.purpleLight : color, color: disabled ? C.purpleSub : C.white,
    border: "none", borderRadius: 999, padding: "12px 0", width: "100%",
    fontFamily: "'Nunito', sans-serif", fontWeight: 800, fontSize: 14,
    cursor: disabled ? "not-allowed" : "pointer", display: "flex",
    alignItems: "center", justifyContent: "center", gap: 7, ...style,
  }}>{children}</button>
);

const OutlineBtn = ({ children, onClick, style = {} }) => (
  <button onClick={onClick} style={{
    background: C.white, color: C.purple, border: `1.5px solid ${C.cardBorder}`,
    borderRadius: 999, padding: "12px 0", width: "100%",
    fontFamily: "'Nunito', sans-serif", fontWeight: 800, fontSize: 14,
    cursor: "pointer", display: "flex", alignItems: "center",
    justifyContent: "center", gap: 7, ...style,
  }}>{children}</button>
);

const MiniLabel = ({ children, color = C.purpleSub }) => (
  <div style={{ fontSize: 10, fontWeight: 800, color, textTransform: "uppercase", letterSpacing: ".05em", marginBottom: 4 }}>
    {children}
  </div>
);

const Topbar = ({ title, right }) => (
  <div style={{
    background: C.white, borderBottom: `1px solid #F0EEF8`,
    padding: "12px 20px", display: "flex", alignItems: "center",
    justifyContent: "space-between", position: "sticky", top: 0, zIndex: 100,
  }}>
    <span style={{ fontFamily: "'Fredoka', sans-serif", fontSize: 20, fontWeight: 600, color: C.orange }}>
      {title}
    </span>
    {right}
  </div>
);

const ProgressBar = ({ value, total }) => (
  <div style={{ display: "flex", alignItems: "center", gap: 8 }}>
    <div style={{ width: 110, height: 7, background: C.purpleLight, borderRadius: 8, overflow: "hidden" }}>
      <div style={{ height: "100%", width: `${(value / total) * 100}%`, borderRadius: 8, background: `linear-gradient(90deg,${C.orange},${C.purple})`, transition: "width .4s" }} />
    </div>
    <span style={{ background: C.purple, color: C.white, borderRadius: 20, fontSize: 11, fontWeight: 700, padding: "2px 10px" }}>
      {Math.min(value + 1, total)} / {total}
    </span>
  </div>
);

// ── WAVEFORM ──────────────────────────────────────────────────
const Waveform = ({ active, analyserRef }) => {
  const barsRef = useRef([]);
  const frameRef = useRef(null);
  const NUM = 26;

  useEffect(() => {
    if (active && analyserRef?.current) {
      const draw = () => {
        const data = new Uint8Array(analyserRef.current.frequencyBinCount);
        analyserRef.current.getByteFrequencyData(data);
        barsRef.current.forEach((b, i) => {
          if (b) b.style.height = (6 + (data[i % data.length] || 0) / 255 * 32) + "px";
        });
        frameRef.current = requestAnimationFrame(draw);
      };
      draw();
    } else {
      if (frameRef.current) cancelAnimationFrame(frameRef.current);
      barsRef.current.forEach((b) => {
        if (b) b.style.height = (7 + Math.random() * 14) + "px";
      });
    }
    return () => { if (frameRef.current) cancelAnimationFrame(frameRef.current); };
  }, [active]);

  return (
    <div style={{
      background: C.bg, borderRadius: 14, height: 52,
      display: "flex", alignItems: "center", justifyContent: "center",
      gap: 3, padding: "0 10px", overflow: "hidden", position: "relative",
    }}>
      {Array.from({ length: NUM }).map((_, i) => (
        <div key={i} ref={el => barsRef.current[i] = el} style={{
          width: 4, height: 10 + Math.random() * 18, borderRadius: 2,
          background: active ? C.purple : C.purpleMid, transition: "height .08s",
        }} />
      ))}
    </div>
  );
};

// ── RECORDER HOOK ─────────────────────────────────────────────
function useRecorder() {
  const [isRecording, setIsRecording] = useState(false);
  const [finalText, setFinalText] = useState("");
  const [interimText, setInterimText] = useState("");
  const [recSecs, setRecSecs] = useState(0);
  const [hasRecorded, setHasRecorded] = useState(false);
  const [recordedAudioUrl, setRecordedAudioUrl] = useState("");
  const recognitionRef = useRef(null);
  const mediaRecRef = useRef(null);
  const analyserRef = useRef(null);
  const audioCtxRef = useRef(null);
  const timerRef = useRef(null);
  const audioChunksRef = useRef([]);
  const playbackAudioRef = useRef(null);
  const SR = window.SpeechRecognition || window.webkitSpeechRecognition;

  const setupSR = useCallback(() => {
    if (!SR) return;
    const r = new SR();
    r.lang = "en-US"; r.continuous = true; r.interimResults = true;
    r.onresult = e => {
      let fin = "", inter = "";
      for (let i = e.resultIndex; i < e.results.length; i++) {
        if (e.results[i].isFinal) fin += e.results[i][0].transcript + " ";
        else inter = e.results[i][0].transcript;
      }
      if (fin) setFinalText(p => p + fin);
      setInterimText(inter);
    };
    r.onerror = e => { if (e.error === "not-allowed") alert("Microphone access denied."); };
    r.onend = () => { if (recognitionRef.current?._active) { try { r.start(); } catch (e) {} } };
    recognitionRef.current = r;
    recognitionRef.current._active = false;
  }, [SR]);

  const start = useCallback(async () => {
    try {
      const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
      const mr = new MediaRecorder(stream);
      audioChunksRef.current = [];
      if (recordedAudioUrl) {
        URL.revokeObjectURL(recordedAudioUrl);
        setRecordedAudioUrl("");
      }
      mr.ondataavailable = (event) => {
        if (event.data && event.data.size > 0) audioChunksRef.current.push(event.data);
      };
      mr.onstop = () => {
        stream.getTracks().forEach(t => t.stop());
        if (audioChunksRef.current.length > 0) {
          const blob = new Blob(audioChunksRef.current, { type: "audio/webm" });
          setRecordedAudioUrl(URL.createObjectURL(blob));
        }
      };
      mr.start();
      mediaRecRef.current = mr;

      try {
        audioCtxRef.current = new (window.AudioContext || window.webkitAudioContext)();
        analyserRef.current = audioCtxRef.current.createAnalyser();
        analyserRef.current.fftSize = 64;
        audioCtxRef.current.createMediaStreamSource(stream).connect(analyserRef.current);
      } catch (e) {}

      setupSR();
      if (recognitionRef.current) {
        recognitionRef.current._active = true;
        try { recognitionRef.current.start(); } catch (e) {}
      }
      setFinalText(""); setInterimText(""); setRecSecs(0); setIsRecording(true);
      timerRef.current = setInterval(() => setRecSecs(s => s + 1), 1000);
    } catch (e) { alert("Microphone access is needed. Please allow it and try again."); }
  }, [setupSR]);

  const stop = useCallback(() => {
    clearInterval(timerRef.current);
    setIsRecording(false);
    setHasRecorded(true);
    if (recognitionRef.current) {
      recognitionRef.current._active = false;
      try { recognitionRef.current.stop(); } catch (e) {}
    }
    if (mediaRecRef.current?.state !== "inactive") mediaRecRef.current?.stop();
    if (audioCtxRef.current) {
      try { audioCtxRef.current.close(); } catch (e) {}
      audioCtxRef.current = null; analyserRef.current = null;
    }
  }, []);

  const reset = useCallback(() => {
    stop();
    if (playbackAudioRef.current) {
      playbackAudioRef.current.pause();
      playbackAudioRef.current = null;
    }
    if (recordedAudioUrl) URL.revokeObjectURL(recordedAudioUrl);
    setFinalText(""); setInterimText(""); setRecSecs(0); setHasRecorded(false); setIsRecording(false);
    setRecordedAudioUrl("");
  }, [recordedAudioUrl, stop]);

  const playRecording = useCallback(() => {
    if (!recordedAudioUrl) return;
    if (playbackAudioRef.current) {
      playbackAudioRef.current.pause();
      playbackAudioRef.current = null;
    }
    const audio = new Audio(recordedAudioUrl);
    playbackAudioRef.current = audio;
    audio.play().catch(() => {});
  }, [recordedAudioUrl]);

  useEffect(() => {
    return () => {
      if (playbackAudioRef.current) playbackAudioRef.current.pause();
      if (recordedAudioUrl) URL.revokeObjectURL(recordedAudioUrl);
    };
  }, [recordedAudioUrl]);

  return {
    isRecording,
    finalText,
    interimText,
    recSecs,
    hasRecorded,
    recordedAudioUrl,
    analyserRef,
    start,
    stop,
    reset,
    playRecording,
  };
}

// ── CLAUDE API (via server-side proxy) ────────────────────────
async function callClaude(transcript, turn) {
  const prompt = `You are an English language coach evaluating a roleplay response.
AGENT SAID: "${turn.agent}"
STUDENT SAID: "${transcript}"
IDEAL: "${turn.ideal}"
CRITERIA: ${turn.criteria}
Return ONLY valid JSON, no markdown:
{"grammar":<0-100>,"vocabulary":<0-100>,"fluency":<0-100>,"total":<0-100>,"corrected":"<corrected or same if perfect>","praise":"<one specific encouraging sentence>","tips":["<tip1>","<tip2>","<tip3>"],"passed":<true/false>}`;
  try {
    const r = await fetch("claude_proxy.php", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({
        model: "claude-sonnet-4-6",
        max_tokens: 600,
        messages: [{ role: "user", content: prompt }],
      }),
    });
    const j = await r.json();
    const raw = j.content.map(i => i.text || "").join("").replace(/```json|```/g, "").trim();
    return JSON.parse(raw);
  } catch (e) {
    return {
      grammar: 78, vocabulary: 75, fluency: 77, total: 77,
      corrected: turn.ideal,
      praise: "Good effort! You communicated the main idea.",
      tips: ["Use complete sentences.", "Add 'please' for politeness.", "Include all key details."],
      passed: true,
    };
  }
}

// ── EDITOR VIEW ───────────────────────────────────────────────
const inputStyle = {
  width: "100%", padding: "8px 11px", borderRadius: 10, border: `1.5px solid ${C.cardBorder}`,
  fontFamily: "'Nunito', sans-serif", fontSize: 13, fontWeight: 600, color: "#333",
  outline: "none", background: C.bgCard, boxSizing: "border-box",
};

async function saveActivity(scene, turns) {
  const id = window.RK_ACTIVITY_ID;
  if (!id) return { ok: false, error: "No activity ID" };
  const saveUrl = new URL("save.php", window.location.href);
  saveUrl.searchParams.set("_ts", String(Date.now()));
  const normalizedScene = canonicalizeScene(scene);
  const payload = { id, scene: normalizedScene, turns };

  console.log("[roleplay_kids] saveActivity called", {
    activityId: id,
    saveDestination: "activities.data",
    saveUrl: saveUrl.toString(),
    savePayload: payload,
  });

  try {
    const r = await fetch(saveUrl.toString(), {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      credentials: "same-origin",
      cache: "no-store",
      body: JSON.stringify(payload),
    });
    const data = await r.json().catch(() => ({}));
    if (!r.ok) {
      const errorMsg = data?.error || `HTTP ${r.status}`;
      console.error("[roleplay_kids] saveActivity failed", {
        activityId: id,
        saveDestination: "activities.data",
        saveUrl: saveUrl.toString(),
        responseStatus: r.status,
        responseBody: data,
      });
      return { ok: false, error: errorMsg };
    }

    console.log("[roleplay_kids] saveActivity success", {
      activityId: id,
      saveDestination: "activities.data",
      responseBody: data,
    });
    return data;
  } catch (e) {
    console.error("[roleplay_kids] saveActivity exception", {
      activityId: id,
      saveDestination: "activities.data",
      error: String(e),
    });
    return { ok: false, error: String(e) };
  }
}

const TEMPLATES = [
  { icon: "🍽", label: "Restaurant",
    scene: { title: "At the Restaurant", icon: "🍽", desc: "You are a customer at a restaurant. The waiter will take your order. Respond politely and completely in English.", agentName: "", agentRole: "Waiter", studentRole: "Customer" },
    turns: [
      { agent: "Good evening! Are you ready to order?", hint: "Greet the waiter and say what you'd like to eat.", ideal: "Good evening! Yes, I'd like the grilled chicken, please.", criteria: "polite greeting, order stated, please used" },
      { agent: "Great choice! Would you like anything to drink?", hint: "Order a drink and optionally ask for water.", ideal: "I'll have an orange juice, please. And some water.", criteria: "drink ordered, polite language, complete sentence" },
    ],
  },
  { icon: "🏥", label: "Doctor",
    scene: { title: "At the Doctor", icon: "🏥", desc: "You are a patient visiting the doctor. Describe your symptoms clearly and answer the doctor's questions in English.", agentName: "", agentRole: "Doctor", studentRole: "Patient" },
    turns: [
      { agent: "Good morning! What brings you in today?", hint: "Describe your main symptom and how long you've had it.", ideal: "Good morning, Doctor. I've had a bad headache for two days.", criteria: "symptom described, duration mentioned, polite language" },
      { agent: "I see. Do you have any other symptoms — fever or nausea?", hint: "Mention any other symptoms you have, or say you don't.", ideal: "I've also had a slight fever, but no nausea.", criteria: "additional symptoms addressed, complete response" },
    ],
  },
  { icon: "🏨", label: "Hotel",
    scene: { title: "At the Hotel", icon: "🏨", desc: "You are a guest checking in to a hotel. Communicate clearly and politely in English.", agentName: "", agentRole: "Receptionist", studentRole: "Guest" },
    turns: [
      { agent: "Welcome! Do you have a reservation?", hint: "Confirm your reservation and give your name.", ideal: "Yes, I have a reservation under the name [Your Name].", criteria: "confirmation given, name provided, polite tone" },
      { agent: "Perfect. Could I see some ID, please?", hint: "Offer your ID and ask for the room number.", ideal: "Of course, here is my passport. What is my room number?", criteria: "ID offered, question asked, polite and complete" },
    ],
  },
  { icon: "✈️", label: "Airport",
    scene: { title: "At the Airport", icon: "✈️", desc: "You are a traveler checking in at an international airport. Respond naturally and completely in English.", agentName: "", agentRole: "Agent", studentRole: "Traveler" },
    turns: [
      { agent: "Good morning! May I have your name and destination, please?", hint: "Greet the agent, give your name, and mention your destination.", ideal: "Good morning! My name is [Name] and I'm flying to London.", criteria: "greeting used, name given, destination mentioned" },
      { agent: "Thank you! Do you have any bags to check in today?", hint: "Say how many bags you have and offer your passport.", ideal: "I have two bags. Here is my passport.", criteria: "bags stated, passport mentioned, complete response" },
    ],
  },
  { icon: "🛒", label: "Shopping",
    scene: { title: "Shopping", icon: "🛒", desc: "You are a customer in a shop. The assistant will help you find something. Use polite English throughout.", agentName: "", agentRole: "Shop assistant", studentRole: "Customer" },
    turns: [
      { agent: "Hello! Can I help you with anything today?", hint: "Say what you are looking for.", ideal: "Hi! Yes, I'm looking for a birthday gift for my friend.", criteria: "clear request made, polite greeting, complete sentence" },
      { agent: "Great! What's your budget?", hint: "State your budget clearly.", ideal: "I'd like to spend around twenty pounds, please.", criteria: "budget stated, please used, natural phrasing" },
    ],
  },
  { icon: "📞", label: "Phone call",
    scene: { title: "Phone Call", icon: "📞", desc: "You are calling a company's customer service line. Communicate clearly and politely in English.", agentName: "", agentRole: "Customer service", studentRole: "Caller" },
    turns: [
      { agent: "Thank you for calling. How can I help you today?", hint: "State why you are calling and give your name.", ideal: "Hello, my name is [Name]. I'm calling about my order.", criteria: "name given, reason stated, polite opening" },
      { agent: "Of course! Could I have your order number, please?", hint: "Provide your order number or say you don't have it.", ideal: "Yes, it's order number 45892.", criteria: "order number provided, polite response" },
    ],
  },
  { icon: "🏫", label: "Classroom",
    scene: { title: "In the Classroom", icon: "🏫", desc: "You are a student in an English class. Your teacher will ask you questions. Answer clearly and in complete sentences.", agentName: "", agentRole: "Teacher", studentRole: "Student" },
    turns: [
      { agent: "Good morning! Can you tell me about your weekend?", hint: "Describe one thing you did at the weekend.", ideal: "Good morning! I went to the park with my family on Saturday.", criteria: "past tense used, activity described, complete sentence" },
      { agent: "That sounds lovely! Did you enjoy it?", hint: "Give your opinion and add a detail or reason.", ideal: "Yes, I really enjoyed it. The weather was sunny and we had a picnic.", criteria: "opinion expressed, reason given, natural phrasing" },
    ],
  },
  { icon: "🏦", label: "Bank",
    scene: { title: "At the Bank", icon: "🏦", desc: "You are a client at a bank. The teller will assist you. Use polite and clear English.", agentName: "", agentRole: "Teller", studentRole: "Client" },
    turns: [
      { agent: "Good morning! How can I help you today?", hint: "Explain what you would like to do at the bank.", ideal: "Good morning! I'd like to open a savings account, please.", criteria: "polite greeting, clear request, please used" },
      { agent: "Of course! Could I see some identification, please?", hint: "Confirm you have your ID and say what you've brought.", ideal: "Yes, here is my passport.", criteria: "ID offered, polite and direct, complete sentence" },
    ],
  },
  { icon: "🏫", label: "School",
    scene: { title: "At School", icon: "🏫", desc: "You are a student at school. Your teacher asks you questions about the lesson.", agentName: "", agentRole: "Teacher", studentRole: "Student" },
    turns: [
      { agent: "", hint: "", ideal: "", criteria: "" },
      { agent: "", hint: "", ideal: "", criteria: "" },
    ],
  },
  { icon: "🏢", label: "Office",
    scene: { title: "At the Office", icon: "🏢", desc: "You are an employee. Your manager is giving you instructions for the day.", agentName: "", agentRole: "Manager", studentRole: "Employee" },
    turns: [
      { agent: "", hint: "", ideal: "", criteria: "" },
      { agent: "", hint: "", ideal: "", criteria: "" },
    ],
  },
  { icon: "🎉", label: "Party",
    scene: { title: "At a Party", icon: "🎉", desc: "You are a guest at a party. The host welcomes you and introduces you to others.", agentName: "", agentRole: "Host", studentRole: "Guest" },
    turns: [
      { agent: "", hint: "", ideal: "", criteria: "" },
      { agent: "", hint: "", ideal: "", criteria: "" },
    ],
  },
  { icon: "🚶", label: "In Line",
    scene: { title: "Waiting in Line", icon: "🚶", desc: "You are waiting in line. The person next to you starts a friendly conversation.", agentName: "", agentRole: "Stranger", studentRole: "Person in line" },
    turns: [
      { agent: "", hint: "", ideal: "", criteria: "" },
      { agent: "", hint: "", ideal: "", criteria: "" },
    ],
  },
  { icon: "🛍", label: "Mall",
    scene: { title: "At the Shopping Mall", icon: "🛍", desc: "You are shopping at the mall. A store clerk approaches to help you find something.", agentName: "", agentRole: "Store clerk", studentRole: "Shopper" },
    turns: [
      { agent: "", hint: "", ideal: "", criteria: "" },
      { agent: "", hint: "", ideal: "", criteria: "" },
    ],
  },
  { icon: "🏠", label: "Home",
    scene: { title: "At Home", icon: "🏠", desc: "You are at home. A family member asks about your day and makes plans with you.", agentName: "", agentRole: "Family member", studentRole: "You" },
    turns: [
      { agent: "", hint: "", ideal: "", criteria: "" },
      { agent: "", hint: "", ideal: "", criteria: "" },
    ],
  },
  { icon: "🌳", label: "Park",
    scene: { title: "At the Park", icon: "🌳", desc: "You are relaxing at the park. A friendly neighbor stops to chat with you.", agentName: "", agentRole: "Neighbor", studentRole: "Visitor" },
    turns: [
      { agent: "", hint: "", ideal: "", criteria: "" },
      { agent: "", hint: "", ideal: "", criteria: "" },
    ],
  },
  { icon: "🛝", label: "Playground",
    scene: { title: "At the Playground", icon: "🛝", desc: "You are at the playground. Another child invites you to play and asks your name.", agentName: "", agentRole: "Another kid", studentRole: "You" },
    turns: [
      { agent: "", hint: "", ideal: "", criteria: "" },
      { agent: "", hint: "", ideal: "", criteria: "" },
    ],
  },
  { icon: "🌤", label: "Outside",
    scene: { title: "Outside", icon: "🌤", desc: "You are outside for a walk. Someone stops to ask for directions or for help.", agentName: "", agentRole: "Passerby", studentRole: "You" },
    turns: [
      { agent: "", hint: "", ideal: "", criteria: "" },
      { agent: "", hint: "", ideal: "", criteria: "" },
    ],
  },
  { icon: "🛒", label: "Supermarket",
    scene: { title: "At the Supermarket", icon: "🛒", desc: "You are shopping at the supermarket. An employee offers to help you find a product.", agentName: "", agentRole: "Store employee", studentRole: "Customer" },
    turns: [
      { agent: "", hint: "", ideal: "", criteria: "" },
      { agent: "", hint: "", ideal: "", criteria: "" },
    ],
  },
];

function EditorView({ scene, turns, onSceneChange, onTurnsChange, onStart }) {
  const [saving, setSaving] = useState(false);
  const [saveMsg, setSaveMsg] = useState("");
  const [showTemplates, setShowTemplates] = useState(false);
  const [activeTemplate, setActiveTemplate] = useState(null);
  const [ttsPreviewState, setTtsPreviewState] = useState("idle");
  const previewAudioRef = useRef(null);

  const addTurn = () => onTurnsChange([...turns, { agent: "", hint: "", ideal: "", criteria: "" }]);
  const deleteTurn = i => {
    if (turns.length <= 1) return;
    const t = [...turns]; t.splice(i, 1); onTurnsChange(t);
  };
  const updateTurn = (i, key, val) => {
    const t = [...turns]; t[i] = { ...t[i], [key]: val }; onTurnsChange(t);
  };
  const moveTurnUp = i => {
    if (i === 0) return;
    const t = [...turns]; [t[i - 1], t[i]] = [t[i], t[i - 1]]; onTurnsChange(t);
  };
  const moveTurnDown = i => {
    if (i === turns.length - 1) return;
    const t = [...turns]; [t[i], t[i + 1]] = [t[i + 1], t[i]]; onTurnsChange(t);
  };
  const applyTemplate = tpl => {
    setActiveTemplate(tpl.label);
    onSceneChange({ ...tpl.scene });
    onTurnsChange(tpl.turns.map(t => ({ ...t })));
  };

  const handleSave = async () => {
    console.log("[roleplay_kids] handleSave triggered", {
      activityId: window.RK_ACTIVITY_ID || null,
      turnsCount: Array.isArray(turns) ? turns.length : 0,
    });
    setSaving(true); setSaveMsg("");
    const res = await saveActivity(scene, turns);
    setSaving(false);
    setSaveMsg(res.ok ? "✓ Guardado" : "✗ Error al guardar");
    if (!res.ok) {
      console.error("[roleplay_kids] Save failed", {
        activityId: window.RK_ACTIVITY_ID || null,
        error: res.error || "Unknown save error",
      });
      alert(`Save failed: ${res.error || "Unknown error"}`);
    }
    setTimeout(() => setSaveMsg(""), 3000);
    return res;
  };

  const handleStart = async () => {
    if (window.RK_ACTIVITY_ID) {
      const res = await handleSave();
      if (!res?.ok) return;
    }
    onStart();
  };

  const handlePreviewTeacherVoice = useCallback(async () => {
    const previewText = (turns.find(t => String(t.agent || "").trim() !== "")?.agent || "Hello! Let's practice English together.").trim();
    if (!previewText) return;
    if (previewAudioRef.current) {
      previewAudioRef.current.pause();
      previewAudioRef.current = null;
    }

    setTtsPreviewState("loading");
    try {
      const voiceId = resolveTeacherVoiceId(scene);
      const blob = await fetchElevenLabsAudioBlob(previewText, voiceId);
      await playAudioBlob(blob, previewAudioRef, {
        onPlaying: () => setTtsPreviewState("playing"),
        onIdle: () => setTtsPreviewState("idle"),
      });
    } catch (e) {
      setTtsPreviewState("idle");
      const detail = e && e.message ? e.message : "Unknown error";
      alert("Could not play teacher voice preview: " + detail);
    }
  }, [scene, turns]);

  useEffect(() => {
    return () => {
      if (previewAudioRef.current) {
        previewAudioRef.current.pause();
        previewAudioRef.current = null;
      }
    };
  }, []);

  const arrowBtnStyle = disabled => ({
    background: "none", border: `1.5px solid ${disabled ? C.purpleLight : C.purpleMid}`,
    borderRadius: 6, cursor: disabled ? "default" : "pointer",
    color: disabled ? C.purpleLight : C.purpleSub,
    fontSize: 10, padding: "2px 5px", lineHeight: 1, fontWeight: 800,
  });

  return (
    <div style={{ background: C.bg, minHeight: "100%" }}>
      <Topbar title="🎭 Roleplay Kids" right={
        <span style={{ fontSize: 12, color: C.purpleSub, fontWeight: 700 }}>Activity Editor</span>
      } />
      <div style={{ maxWidth: 680, margin: "0 auto", padding: "20px 16px 60px" }}>
        <Kicker>Activity Builder</Kicker>

        {/* Quick Templates */}
        <div style={{ marginBottom: 14 }}>
          <button onClick={() => setShowTemplates(v => !v)} style={{
            background: "none", border: "none", cursor: "pointer",
            fontFamily: "'Nunito', sans-serif", fontWeight: 800, fontSize: 13,
            color: C.purple, display: "flex", alignItems: "center", gap: 6, padding: "4px 0",
          }}>
            💡 Quick Templates {showTemplates ? "▲" : "▼"}
          </button>
          {showTemplates && (
            <div style={{ display: "flex", flexWrap: "wrap", gap: 7, marginTop: 10 }}>
              {TEMPLATES.map(tpl => {
                const active = activeTemplate === tpl.label;
                return (
                  <button key={tpl.label} onClick={() => applyTemplate(tpl)} style={{
                    background: active ? C.purple : C.purpleLight,
                    color: active ? C.white : C.purple,
                    border: `1.5px solid ${C.cardBorder}`,
                    borderRadius: 20, fontFamily: "'Nunito', sans-serif",
                    fontSize: 12, fontWeight: 800, padding: "6px 14px", cursor: "pointer",
                  }}>
                    {tpl.icon} {tpl.label}
                  </button>
                );
              })}
            </div>
          )}
        </div>

        {/* Scene Setup */}
        <Card style={{ marginBottom: 16, borderRadius: 20 }}>
          <div style={{ fontFamily: "'Fredoka', sans-serif", fontSize: 16, fontWeight: 600, color: C.orange, marginBottom: 14 }}>
            🎭 Scene Setup
          </div>

          <div style={{ display: "grid", gridTemplateColumns: "1fr 72px", gap: 10, marginBottom: 10 }}>
            <div>
              <MiniLabel>Activity title</MiniLabel>
              <input value={scene.title} onChange={e => onSceneChange({ ...scene, title: e.target.value })}
                style={inputStyle} placeholder="e.g. At the Restaurant" />
            </div>
            <div>
              <MiniLabel>Scene icon</MiniLabel>
              <input value={scene.icon} onChange={e => onSceneChange({ ...scene, icon: e.target.value })}
                maxLength={2} style={inputStyle} placeholder="🎭" />
            </div>
          </div>

          <div style={{ marginBottom: 10 }}>
            <MiniLabel>Situation description</MiniLabel>
            <textarea
              value={scene.desc}
              onChange={e => onSceneChange({ ...scene, desc: e.target.value })}
              rows={3}
              placeholder={"Describe the situation the student is in.\ne.g. You are a customer at a busy café ordering lunch..."}
              style={{ ...inputStyle, resize: "none", lineHeight: 1.55 }}
            />
          </div>

          <div style={{ display: "grid", gridTemplateColumns: "1fr 1fr", gap: 10, marginBottom: 10 }}>
            <div>
              <MiniLabel>Agent name</MiniLabel>
              <input value={scene.agentName} onChange={e => onSceneChange({ ...scene, agentName: e.target.value })}
                style={inputStyle} placeholder="e.g. Maria, Dr. Smith" />
            </div>
            <div>
              <MiniLabel>Agent role</MiniLabel>
              <input value={scene.agentRole} onChange={e => onSceneChange({ ...scene, agentRole: e.target.value })}
                style={inputStyle} placeholder="e.g. Waiter, Doctor, Receptionist" />
            </div>
          </div>

          <div>
            <MiniLabel>Student role</MiniLabel>
            <input value={scene.studentRole} onChange={e => onSceneChange({ ...scene, studentRole: e.target.value })}
              style={inputStyle} placeholder="e.g. Customer, Patient, Tourist" />
          </div>

          <div style={{ marginTop: 10 }}>
            <MiniLabel>Scene image URL</MiniLabel>
            <input value={scene.sceneImage || ""} onChange={e => onSceneChange({ ...scene, sceneImage: e.target.value })}
              style={inputStyle} placeholder="https://... (optional)" />
          </div>

          <div style={{ display: "grid", gridTemplateColumns: "1fr 1fr", gap: 10, marginTop: 10 }}>
            <div>
              <MiniLabel>Teacher avatar</MiniLabel>
              <select value={scene.teacherAvatarId || "TEACHER"} onChange={e => onSceneChange({ ...scene, teacherAvatarId: e.target.value })} style={inputStyle}>
                {RK_AVATARS.map(a => <option key={a.id} value={a.id}>{a.label}</option>)}
              </select>
            </div>
            <div>
              <MiniLabel>Student avatar</MiniLabel>
              <select value={scene.studentAvatarId || "ANGIE"} onChange={e => onSceneChange({ ...scene, studentAvatarId: e.target.value })} style={inputStyle}>
                {RK_AVATARS.filter(a => a.id !== "TEACHER").map(a => <option key={a.id} value={a.id}>{a.label}</option>)}
              </select>
            </div>
          </div>

          <div style={{ marginTop: 10 }}>
            <MiniLabel>Teacher voice</MiniLabel>
            <div style={{ display: "flex", gap: 8, alignItems: "center", flexWrap: "wrap" }}>
              <select value={resolveTeacherVoiceId(scene)} onChange={e => onSceneChange({ ...scene, teacherVoiceId: e.target.value })} style={{ ...inputStyle, margin: 0, flex: "1 1 260px", width: "auto", minWidth: 0 }}>
                {RK_VOICES.map(v => <option key={v.id} value={v.id}>{v.label}</option>)}
              </select>
              <button
                onClick={handlePreviewTeacherVoice}
                disabled={ttsPreviewState !== "idle"}
                style={{ background: ttsPreviewState !== "idle" ? "#C5C1ED" : C.purple, color: C.white, border: "none", borderRadius: 10, padding: "10px 14px", fontSize: 12, fontWeight: 800, cursor: ttsPreviewState !== "idle" ? "not-allowed" : "pointer", whiteSpace: "nowrap", flexShrink: 0 }}
              >
                {ttsPreviewState === "loading" ? "Loading..." : ttsPreviewState === "playing" ? "Playing..." : "Listen"}
              </button>
            </div>
          </div>
        </Card>

        {/* Turn Cards */}
        {turns.map((t, i) => (
          <Card key={i} style={{ marginBottom: 10, borderRadius: 20 }}>
            <div style={{ display: "flex", alignItems: "center", justifyContent: "space-between", marginBottom: 12 }}>
              <span style={{ background: C.purpleLight, color: C.purple, fontSize: 11, fontWeight: 800, padding: "3px 10px", borderRadius: 20 }}>
                Turn {i + 1}
              </span>
              <div style={{ display: "flex", alignItems: "center", gap: 4 }}>
                <button onClick={() => moveTurnUp(i)} disabled={i === 0} style={arrowBtnStyle(i === 0)}>▲</button>
                <button onClick={() => moveTurnDown(i)} disabled={i === turns.length - 1} style={arrowBtnStyle(i === turns.length - 1)}>▼</button>
                {turns.length > 1 && (
                  <button onClick={() => deleteTurn(i)} style={{ background: "none", border: "none", cursor: "pointer", color: "#ccc", fontSize: 15, padding: "2px 6px", borderRadius: 6, fontWeight: 800 }}>✕</button>
                )}
              </div>
            </div>
            <div style={{ display: "flex", flexDirection: "column", gap: 9 }}>
              <div>
                <MiniLabel>Agent says</MiniLabel>
                <textarea value={t.agent} onChange={e => updateTurn(i, "agent", e.target.value)}
                  rows={2} placeholder="What the agent/character says to the student"
                  style={{ ...inputStyle, resize: "none", lineHeight: 1.55 }} />
              </div>
              <div>
                <MiniLabel>Student hint</MiniLabel>
                <input value={t.hint} onChange={e => updateTurn(i, "hint", e.target.value)}
                  style={inputStyle} placeholder="Hint shown to help the student respond" />
              </div>
              <div>
                <MiniLabel>Ideal response</MiniLabel>
                <textarea value={t.ideal} onChange={e => updateTurn(i, "ideal", e.target.value)}
                  rows={2} placeholder="The ideal answer the student should give"
                  style={{ ...inputStyle, resize: "none", lineHeight: 1.55 }} />
              </div>
              <div>
                <MiniLabel>Grading criteria</MiniLabel>
                <input value={t.criteria} onChange={e => updateTurn(i, "criteria", e.target.value)}
                  style={inputStyle} placeholder="e.g. polite greeting used, verb tense correct, complete sentence" />
              </div>
            </div>
          </Card>
        ))}

        <button onClick={addTurn} style={{
          width: "100%", padding: 11, borderRadius: 16, border: `2px dashed ${C.purpleMid}`,
          background: "transparent", cursor: "pointer", fontFamily: "'Nunito', sans-serif",
          fontSize: 13, fontWeight: 800, color: C.purple, marginBottom: 14,
        }}>＋ Add Turn</button>

        {window.RK_ACTIVITY_ID && (
          <div style={{ display: "flex", gap: 8, marginBottom: 10, alignItems: "center" }}>
            <Btn onClick={handleSave} color={C.purple} disabled={saving} style={{ flex: 1 }}>
              {saving ? "Guardando…" : "💾 Guardar"}
            </Btn>
            {saveMsg && (
              <span style={{ fontSize: 13, fontWeight: 700, color: saveMsg.startsWith("✓") ? C.greenText : "#DC2626" }}>
                {saveMsg}
              </span>
            )}
          </div>
        )}

        <Btn onClick={handleStart}>▶ Start Roleplay Kids</Btn>
      </div>
    </div>
  );
}

// ── MESSAGES ──────────────────────────────────────────────────
function Messages({ messages }) {
  const ref = useRef(null);
  useEffect(() => { if (ref.current) ref.current.scrollTop = ref.current.scrollHeight; }, [messages]);
  return (
    <div ref={ref} style={{ display: "flex", flexDirection: "column", gap: 10, maxHeight: 260, overflowY: "auto", marginBottom: 12, paddingRight: 2 }}>
      {messages.map((m, i) => (
        <div key={i} style={{ display: "flex", gap: 8, alignItems: "flex-start", flexDirection: m.role === "you" ? "row-reverse" : "row" }}>
          <div style={{
            width: 32, height: 32, borderRadius: "50%", display: "flex", alignItems: "center",
            justifyContent: "center", fontSize: 13, flexShrink: 0,
            background: m.role === "ai" ? C.purpleLight : C.orangeLight,
          }}>{m.role === "ai" ? "🤖" : "🙋"}</div>
          <div style={{
            maxWidth: "76%", padding: "9px 13px", borderRadius: 14, fontSize: 13,
            lineHeight: 1.55, fontWeight: 600,
            background: m.role === "ai" ? C.bg : C.orangeLight,
            color: m.role === "ai" ? "#333" : C.orangeMid,
            borderBottomLeftRadius: m.role === "ai" ? 3 : 14,
            borderBottomRightRadius: m.role === "you" ? 3 : 14,
          }}>{m.text}</div>
        </div>
      ))}
    </div>
  );
}

// ── RECORDER CARD ─────────────────────────────────────────────
function RecorderCard({ turn, turnIndex, totalTurns, onSubmit, scene, ttsState, speakAgentLine, onBack }) {
  const rec = useRecorder();
  const displayText = (rec.finalText + rec.interimText).trim();
  const canSubmit = rec.hasRecorded;
  const canGoBack = typeof onBack === "function";
  const muted = "#9B8FCC";
  const ink = "#1e1b2e";

  const handleSubmit = () => {
    const t = displayText;
    if (!t) { alert("Please speak your response."); return; }
    onSubmit(t);
  };

  return (
    <div style={{ display: "flex", flexDirection: "column", gap: 14 }}>

      {/* 1. PROGRESS BAR */}
      <div>
        <div style={{ height: 6, background: "#EDE9FA", borderRadius: 999 }}>
          <div style={{ height: "100%", width: `${((turnIndex + 1) / totalTurns) * 100}%`, background: `linear-gradient(90deg,${C.orange},${C.purple})`, borderRadius: 999, transition: "width .4s" }} />
        </div>
        <div style={{ textAlign: "right", fontSize: 13, fontWeight: 700, color: "#9B8FCC", marginTop: 4 }}>
          {turnIndex + 1} of {totalTurns} turns
        </div>
      </div>

      {/* 2. AGENT CARD */}
      <div style={{ background: "#F9F8FF", borderRadius: "18px 18px 18px 4px", border: "1px solid #EDE9FA", padding: "14px 16px" }}>
        <div style={{ display: "flex", alignItems: "center", gap: 10, marginBottom: 10 }}>
          <div style={{ width: 32, height: 32, borderRadius: "50%", background: "#EDE9FA", color: "#5A51C0", display: "flex", alignItems: "center", justifyContent: "center", fontFamily: "'Nunito',sans-serif", fontSize: 15, fontWeight: 700, flexShrink: 0 }}>
            {(scene.agentName || "A")[0].toUpperCase()}
          </div>
          <div style={{ flex: 1, minWidth: 0 }}>
            <div style={{ fontFamily: "'Nunito',sans-serif", fontSize: 14, color: "#5A51C0", fontWeight: 800, lineHeight: 1.2 }}>{scene.agentName || "Agent"}</div>
            <div style={{ fontSize: 10, fontWeight: 800, color: muted, textTransform: "uppercase", letterSpacing: ".05em" }}>{scene.agentRole || "Character"}</div>
          </div>
          <button onClick={() => speakAgentLine(turn.agent)} disabled={ttsState !== "idle"} style={{ background: ttsState !== "idle" ? "#C5C1ED" : "#7F77DD", color: "#fff", border: "none", borderRadius: 999, padding: "8px 14px", fontSize: 12, fontWeight: 800, fontFamily: "'Nunito',sans-serif", cursor: ttsState !== "idle" ? "not-allowed" : "pointer", display: "flex", alignItems: "center", gap: 5, flexShrink: 0 }}>
            <svg width="12" height="12" viewBox="0 0 24 24" fill="currentColor"><polygon points="5 3 19 12 5 21 5 3"/></svg>
            {ttsState === "loading" ? "Loading..." : ttsState === "playing" ? "Playing..." : "Speaker"}
          </button>
        </div>
        <div style={{ background: "#fff", border: "1px solid #EDE9FA", borderRadius: "0 16px 16px 16px", padding: "12px 14px", fontSize: 14, fontWeight: 700, color: "#3C3489", lineHeight: 1.6 }}>
          {turn.agent}
        </div>
        {turn.ideal ? (
          <div style={{ marginTop: 8, background: "#FFF0E6", border: "1px solid #FDDCBE", borderRadius: 12, padding: "10px 12px" }}>
            <div style={{ fontSize: 10, fontWeight: 800, color: C.orange, textTransform: "uppercase", letterSpacing: ".05em", marginBottom: 3 }}>Correction</div>
            <div style={{ fontSize: 13, fontWeight: 700, color: "#9A3412", lineHeight: 1.55 }}>{turn.ideal}</div>
          </div>
        ) : null}
      </div>

      {/* 3. HINT BOX */}
      {turn.hint !== "" && (
        <div style={{ background: "#FFF0E6", border: "1px solid #FDDCBE", borderRadius: 12, padding: "10px 14px", display: "flex", gap: 10, alignItems: "flex-start" }}>
          <span style={{ fontSize: 18, flexShrink: 0, lineHeight: 1.2 }}>💡</span>
          <div>
            <div style={{ fontSize: 10, fontWeight: 800, color: C.orange, textTransform: "uppercase", letterSpacing: ".05em", marginBottom: 2 }}>HINT</div>
            <div style={{ fontSize: 13, fontWeight: 700, color: "#C1682B", lineHeight: 1.5 }}>{turn.hint}</div>
          </div>
        </div>
      )}

      {/* 4. STUDENT AREA */}
      <div style={{ background: "#fff", border: "1.5px solid #EDE9FA", borderRadius: 20, padding: "14px 16px" }}>
        <div style={{ display: "flex", alignItems: "center", gap: 10, marginBottom: 12 }}>
          <div style={{ width: 32, height: 32, borderRadius: "50%", background: "#FFF0E6", color: "#F97316", display: "flex", alignItems: "center", justifyContent: "center", fontSize: 16, flexShrink: 0 }}>🎓</div>
          <div style={{ flex: 1 }}>
            <div style={{ fontFamily: "'Nunito',sans-serif", fontSize: 14, color: "#F97316", fontWeight: 800 }}>Your turn</div>
            <div style={{ fontSize: 10, fontWeight: 800, color: muted, textTransform: "uppercase", letterSpacing: ".05em" }}>{scene.studentRole || "Student"}</div>
          </div>
        </div>
        <div style={{ background: "#F9F8FF", border: "2px dashed #EDE9FA", borderRadius: 16, padding: 20, display: "flex", flexDirection: "column", alignItems: "center", gap: 10 }}>
          <button onClick={rec.isRecording ? rec.stop : rec.start} style={{ width: 64, height: 64, borderRadius: "50%", border: "none", cursor: "pointer", background: rec.isRecording ? "#EF4444" : C.purple, boxShadow: rec.isRecording ? "0 4px 16px rgba(239,68,68,.3)" : "0 4px 16px rgba(127,119,221,.3)", display: "flex", alignItems: "center", justifyContent: "center", transition: "background .2s" }}>
            {rec.isRecording
              ? <svg width="22" height="22" viewBox="0 0 24 24" fill="white"><rect x="6" y="6" width="12" height="12" rx="2"/></svg>
              : <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="white" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><path d="M12 1a3 3 0 0 0-3 3v8a3 3 0 0 0 6 0V4a3 3 0 0 0-3-3z"/><path d="M19 10v2a7 7 0 0 1-14 0v-2"/><line x1="12" y1="19" x2="12" y2="23"/><line x1="8" y1="23" x2="16" y2="23"/></svg>
            }
          </button>
          <div style={{ fontSize: 12, fontWeight: 800, color: rec.isRecording ? C.orange : muted, textAlign: "center" }}>
            {rec.isRecording ? "🔴 Listening..." : rec.hasRecorded ? `✓ "${displayText.slice(0, 42)}${displayText.length > 42 ? "..." : ""}"` : "Press mic to record"}
          </div>
          <div style={{ display: "flex", alignItems: "center", justifyContent: "center", gap: 8, flexWrap: "wrap" }}>
            <button onClick={() => speakAgentLine(turn.agent)} disabled={ttsState !== "idle"} style={{ background: ttsState !== "idle" ? "#C5C1ED" : "#7F77DD", color: "#fff", border: "none", borderRadius: 999, padding: "8px 16px", fontSize: 12, fontWeight: 800, fontFamily: "'Nunito',sans-serif", cursor: ttsState !== "idle" ? "not-allowed" : "pointer" }}>
              Speaker
            </button>
            <button onClick={rec.playRecording} disabled={!rec.recordedAudioUrl} style={{ background: rec.recordedAudioUrl ? "#F97316" : "#FED7AA", color: "#fff", border: "none", borderRadius: 999, padding: "8px 16px", fontSize: 12, fontWeight: 800, fontFamily: "'Nunito',sans-serif", cursor: rec.recordedAudioUrl ? "pointer" : "not-allowed" }}>
              Listen
            </button>
          </div>

        </div>
      </div>

      {/* 5. NAV ROW */}
      <div style={{ borderTop: "1px solid #F0EEF8", padding: "12px 0 0", display: "flex", justifyContent: canGoBack ? "space-between" : "flex-end", alignItems: "center", background: "#fff", gap: 10 }}>
        {canGoBack && (
          <button onClick={onBack} style={{ background: "#fff", border: "1.5px solid #EDE9FA", color: "#7F77DD", borderRadius: 999, padding: "10px 20px", fontSize: 13, fontWeight: 800, fontFamily: "'Nunito',sans-serif", cursor: "pointer" }}>← Back</button>
        )}
        {!canSubmit && <span style={{ fontSize: 12, fontWeight: 700, color: "#C5C1ED", fontFamily: "'Nunito',sans-serif" }}>Speak to continue</span>}
        <button onClick={handleSubmit} disabled={!canSubmit} style={{ background: canSubmit ? "#F97316" : "#EDE9FA", color: canSubmit ? "#fff" : "#C5C1ED", cursor: canSubmit ? "pointer" : "not-allowed", border: "none", borderRadius: 999, padding: "10px 20px", fontSize: 13, fontWeight: 800, fontFamily: "'Nunito',sans-serif" }}>Next →</button>
      </div>

    </div>
  );
}

// ── FEEDBACK CARD ─────────────────────────────────────────────
function FeedbackCard({ data, transcript, turnIndex, isLast, onNext, onFinish }) {
  const diff = data.corrected?.toLowerCase().trim() !== transcript.toLowerCase().trim();
  const muted = "#9B8FCC";
  const ink = "#1e1b2e";
  return (
    <div style={{ display: "flex", flexDirection: "column", gap: 14, padding: 18, background: C.white, borderRadius: 18, border: `1.5px solid ${C.cardBorder}`, marginTop: 14 }}>

      {/* 1. YOU SAID */}
      <div style={{ background: "#F5F3FF", borderLeft: "3px solid #7F77DD", borderRadius: "0 14px 14px 0", padding: "12px 14px" }}>
        <div style={{ fontSize: 10, fontWeight: 800, textTransform: "uppercase", color: "#7F77DD", letterSpacing: ".05em", marginBottom: 4 }}>YOU SAID</div>
        <div style={{ fontSize: 13, fontWeight: 700, color: ink, lineHeight: 1.6 }}>"{transcript}"</div>
      </div>

      {/* 2. SUGGESTION */}
      {diff && (
        <div style={{ background: "#FFF0E6", borderLeft: "3px solid #F97316", borderRadius: "0 14px 14px 0", padding: "12px 14px" }}>
          <div style={{ fontSize: 10, fontWeight: 800, textTransform: "uppercase", color: C.orange, letterSpacing: ".05em", marginBottom: 4 }}>SUGGESTION</div>
          <div style={{ fontSize: 13, fontWeight: 700, color: "#9A3412", lineHeight: 1.6 }}>"{data.corrected}"</div>
        </div>
      )}

      {/* 3. SCORE CHIPS */}
      <div style={{ display: "flex", gap: 8, flexWrap: "wrap" }}>
        {[["Fluency", data.fluency], ["Vocabulary", data.vocabulary], ["Grammar", data.grammar], ["Response", data.total]].map(([lbl, val]) => (
          <div key={lbl} style={{ background: "#F9F8FF", border: `1.5px solid ${C.cardBorder}`, borderRadius: 12, padding: "8px 12px", textAlign: "center", flex: 1, minWidth: 70 }}>
            <div style={{ fontSize: 10, fontWeight: 800, color: muted, textTransform: "uppercase", letterSpacing: ".05em", marginBottom: 2 }}>{lbl}</div>
            <div style={{ fontFamily: "'Fredoka',sans-serif", fontSize: 20, color: C.purple }}>{val}</div>
          </div>
        ))}
      </div>

      {/* 4. TOTAL SCORE BAR */}
      <div style={{ background: "linear-gradient(90deg,#F97316,#7F77DD)", borderRadius: 14, padding: "12px 16px", display: "flex", justifyContent: "space-between", alignItems: "center" }}>
        <div>
          <div style={{ fontSize: 12, fontWeight: 800, color: "rgba(255,255,255,0.85)" }}>TURN SCORE</div>
          {data.praise && <div style={{ fontSize: 11, color: "rgba(255,255,255,0.7)", marginTop: 2 }}>{data.praise.slice(0, 52)}{data.praise.length > 52 ? "…" : ""}</div>}
        </div>
        <div style={{ fontFamily: "'Fredoka',sans-serif", fontSize: 26, color: C.white, fontWeight: 600 }}>{data.total} / 100</div>
      </div>

      {/* 5. PRAISE */}
      {data.praise && (
        <div style={{ background: "#F0FDF4", borderRadius: 14, padding: "10px 14px", fontSize: 12, fontWeight: 800, color: "#166534" }}>
          ⭐ {data.praise}
        </div>
      )}

      {/* 6. TIP */}
      {data.tips && data.tips[0] && (
        <div style={{ background: "#F5F3FF", borderRadius: 14, padding: "10px 14px", fontSize: 12, fontWeight: 700, color: "#534AB7", lineHeight: 1.55 }}>
          💬 Tip: {data.tips[0]}
        </div>
      )}

      {/* 7. NAV ROW */}
      <div style={{ borderTop: "1px solid #F0EEF8", paddingTop: 12, display: "flex", justifyContent: "space-between", alignItems: "center", gap: 10 }}>
        <button onClick={() => {}} style={{ background: "#fff", border: "1.5px solid #EDE9FA", color: "#7F77DD", borderRadius: 999, padding: "10px 20px", fontSize: 13, fontWeight: 800, fontFamily: "'Nunito',sans-serif", cursor: "pointer" }}>← Review</button>
        <button onClick={isLast ? onFinish : onNext} style={{ background: "#F97316", color: "#fff", border: "none", borderRadius: 999, padding: "10px 20px", fontSize: 13, fontWeight: 800, fontFamily: "'Nunito',sans-serif", cursor: "pointer" }}>
          {isLast ? "See Results →" : "Next Turn →"}
        </button>
      </div>

    </div>
  );
}

// ── PLAYER VIEW ───────────────────────────────────────────────
function PlayerView({ scene, turns, onComplete, onBack, onListenFull, persistedResults = [], onResultsChange = null }) {
  const safeTurns = (turns && turns.length) ? turns : DEFAULT_TURNS;
  const [currentTurn, setCurrentTurn] = useState(0);
  const [results, setResults] = useState(Array.isArray(persistedResults) ? persistedResults : []);
  const teacherVoiceId = resolveTeacherVoiceId(scene);
  const [ttsState, setTtsState] = useState("idle"); // "idle" | "loading" | "playing"
  const recorder = useRecorder();
  const currentAudioRef = useRef(null);
  const pendingAutoEvalTurnRef = useRef(null);
  const teacherAvatar = scene.teacherAvatarId || "TEACHER";
  const studentAvatar = scene.studentAvatarId || "ANGIE";

  useEffect(() => {
    if (!Array.isArray(persistedResults)) return;
    setResults(persistedResults);
  }, [persistedResults]);

  const stopAgentAudio = useCallback(() => {
    if (currentAudioRef.current) {
      currentAudioRef.current.pause();
      currentAudioRef.current = null;
    }
    if (window.speechSynthesis) {
      window.speechSynthesis.cancel();
    }
    setTtsState("idle");
  }, []);

  const speakWithVoice = useCallback(async (text, selectedVoiceId, opts = {}) => {
    const silent = !!opts.silent;
    if (recorder.isRecording) return;
    const useVoiceId = selectedVoiceId || teacherVoiceId;
    if (!text || !useVoiceId) return;
    setTtsState("loading");
    try {
      const blob = await fetchElevenLabsAudioBlob(text, useVoiceId);
      await playAudioBlob(blob, currentAudioRef, {
        onPlaying: () => setTtsState("playing"),
        onIdle: () => setTtsState("idle"),
      });
    } catch (e) {
      setTtsState("idle");
      if (e && e.code === "elevenlabs_request_failed" && window.speechSynthesis) {
        try {
          window.speechSynthesis.cancel();
          const u = new SpeechSynthesisUtterance(text);
          u.lang = "en-US";
          u.rate = 0.95;
          window.speechSynthesis.speak(u);
        } catch (err) {
          // Ignore fallback errors.
        }
      }
      if (!silent) {
        const detail = e && e.message ? e.message : String(e);
        console.warn("Roleplay Kids TTS error:", detail);
        if (!(e && e.code === "elevenlabs_request_failed")) {
          alert("Could not play teacher audio: " + detail);
        }
      }
    }
  }, [recorder.isRecording, teacherVoiceId]);

  const speakAgentLine = useCallback(async (text, opts = {}) => {
    return speakWithVoice(text, teacherVoiceId, opts);
  }, [speakWithVoice, teacherVoiceId]);

  function normalize(text) {
    return String(text || "")
      .toLowerCase()
      .trim()
      .replace(/[.,!?;:'"]/g, "")
      .replace(/\s+/g, " ");
  }

  function computeScore(transcript, target) {
    const a = normalize(transcript).split(" ").filter(Boolean);
    const b = normalize(target).split(" ").filter(Boolean);
    if (!a.length || !b.length) return 0;
    const matches = a.filter(w => b.includes(w)).length;
    return Math.max(0, Math.min(100, Math.round((matches / Math.max(a.length, b.length)) * 100)));
  }

  function targetLine(turn) {
    return (turn.ideal || turn.hint || "").trim();
  }

  function buildFeedback(transcript, target) {
    const total = computeScore(transcript, target);
    return {
      grammar: total,
      vocabulary: total,
      fluency: total,
      total,
      corrected: target || transcript,
      praise: total >= 80 ? "Great repetition!" : total >= 55 ? "Good effort, keep practicing!" : "Nice try, listen and repeat again.",
      tips: ["Listen carefully and repeat with clear pronunciation."],
      passed: total >= BLOCK_PASS_SCORE,
    };
  }

  const currentTurnData = safeTurns[currentTurn] || { agent: "", ideal: "", hint: "" };

  function applyTurnResult(updatedTurnResult) {
    const existingIdx = results.findIndex(r => r.turnIdx === updatedTurnResult.turnIdx);
    const newResults = existingIdx >= 0
      ? results.map((r, i) => (i === existingIdx ? updatedTurnResult : r))
      : [...results, updatedTurnResult];

    setResults(newResults);
    if (typeof onResultsChange === "function") {
      onResultsChange(newResults);
    }
    return newResults;
  }

  function evaluateBlock(turnIdx, transcript, options = {}) {
    const shouldAdvance = !!options.advance;
    const shouldResetRecorder = options.resetRecorder !== false;
    const safeTranscript = String(transcript || "").trim();
    if (!safeTranscript) return false;

    const turn = safeTurns[turnIdx] || { ideal: "", hint: "" };
    const target = targetLine(turn);
    const scorableTranscript = safeTranscript === "(Audio response)" ? "" : safeTranscript;
    const fb = buildFeedback(scorableTranscript, target);
    const updatedTurnResult = {
      blockId: turnIdx,
      turnIdx,
      transcript: safeTranscript,
      feedback: fb,
    };
    const newResults = applyTurnResult(updatedTurnResult);

    if (shouldResetRecorder) {
      recorder.reset();
    }

    if (!shouldAdvance) return true;

    const completedTurns = new Set(newResults.map(r => r.turnIdx));
    const isAllCompleted = safeTurns.every((_, idx) => completedTurns.has(idx));
    if (isAllCompleted) {
      onComplete(newResults);
      return true;
    }

    let nextIdx = safeTurns.findIndex((_, idx) => idx > turnIdx && !completedTurns.has(idx));
    if (nextIdx === -1) {
      nextIdx = safeTurns.findIndex((_, idx) => !completedTurns.has(idx));
    }
    if (nextIdx === -1) {
      nextIdx = Math.min(turnIdx + 1, safeTurns.length - 1);
    }
    setCurrentTurn(nextIdx);
    return true;
  }

  // Stop audio on unmount
  useEffect(() => () => {
    if (currentAudioRef.current) currentAudioRef.current.pause();
    if (window.speechSynthesis) window.speechSynthesis.cancel();
  }, []);

  function handleAdvanceCurrentTurn() {
    const spokenText = (recorder.finalText + recorder.interimText).trim();
    const transcript = spokenText || (recorder.hasRecorded ? "(Audio response)" : "");
    if (!transcript) {
      alert("Please record the student repetition first.");
      return;
    }
    evaluateBlock(currentTurn, transcript, { advance: true, resetRecorder: true });
  }

  const globalSpokenText = (recorder.finalText + recorder.interimText).trim();
  const globalTranscript = globalSpokenText || (recorder.hasRecorded ? "(Audio response)" : "");
  const canAdvanceTurn = globalTranscript !== "";

  function selectTurnForPractice(idx) {
    if (idx === currentTurn) return;
    recorder.reset();
    setCurrentTurn(idx);
  }

  function toggleRecordingForTurn(idx) {
    if (idx !== currentTurn) {
      stopAgentAudio();
      recorder.reset();
      setCurrentTurn(idx);
      setTimeout(() => {
        recorder.start();
      }, 0);
      return;
    }
    if (recorder.isRecording) {
      pendingAutoEvalTurnRef.current = idx;
      recorder.stop();
      return;
    }
    stopAgentAudio();
    pendingAutoEvalTurnRef.current = null;
    recorder.start();
  }

  useEffect(() => {
    if (recorder.isRecording) return;
    const turnToEval = pendingAutoEvalTurnRef.current;
    if (turnToEval === null || typeof turnToEval !== "number") return;

    const spokenText = (recorder.finalText + recorder.interimText).trim();
    const transcript = spokenText || (recorder.hasRecorded ? "(Audio response)" : "");
    if (!transcript) return;

    pendingAutoEvalTurnRef.current = null;
    evaluateBlock(turnToEval, transcript, { advance: false, resetRecorder: true });
  }, [recorder.isRecording, recorder.hasRecorded, recorder.finalText, recorder.interimText]);

  return (
    <div style={{ background: "#ffffff", minHeight: "100%" }}>
      <div style={{ maxWidth: 760, margin: "0 auto", padding: "12px 16px 60px", background: "#fff", border: "1px solid #EDE9FA", borderRadius: 24, boxShadow: "0 4px 24px rgba(127,119,221,.13)", overflow: "hidden" }}>
          <div style={{ textAlign: "center", padding: "8px 0 6px" }}>
            <span style={{ display: "inline-block", background: "#FFF0E6", color: "#F97316", fontFamily: "'Nunito', sans-serif", fontSize: 11, fontWeight: 800, letterSpacing: ".07em", textTransform: "uppercase", borderRadius: 99, padding: "3px 14px", marginBottom: 6 }}>Activity</span>
            <div style={{ fontFamily: "'Fredoka', sans-serif", fontSize: 28, fontWeight: 600, color: "#F97316", lineHeight: 1.2 }}>Roleplay Kids</div>
            <div style={{ fontFamily: "'Nunito', sans-serif", fontSize: 13, color: "#9B8FCC", fontWeight: 600 }}>Practice real conversations in English.</div>
          </div>

          <div style={{ background: "#fff", border: "1px solid #EDE9FA", borderRadius: 24, overflow: "hidden" }}>

            <div style={{ padding: "14px 14px 10px", background: "#fff", display: "flex", justifyContent: "center" }}>
              <div style={{ width: "100%", maxWidth: 460, aspectRatio: "1 / 1", borderRadius: 18, border: "1.5px solid #C9B5EB", overflow: "hidden", background: "#F5F4FA", display: "flex", alignItems: "center", justifyContent: "center" }}>
                {scene.sceneImage
                  ? <img src={scene.sceneImage} alt="Scene" style={{ width: "100%", height: "100%", objectFit: "cover" }} />
                  : <div style={{ fontFamily: "'Fredoka', sans-serif", fontSize: 42, color: "#3F3A4F" }}>Imagen</div>
                }
              </div>
            </div>

            <div style={{ padding: "14px 14px 110px", display: "flex", flexDirection: "column", gap: 12, background: "#F9F8FF" }}>
              {safeTurns.map((turn, idx) => {
                const turnResult = results.find(r => r.turnIdx === idx);
                const turnScore = Number(turnResult?.feedback?.total || 0);
                const hasScored = !!turnResult && Number.isFinite(turnScore);
                const isPassed = hasScored && turnScore >= BLOCK_PASS_SCORE;
                const progressLabel = hasScored ? (isPassed ? "100%" : "Try again") : "Pending";
                const progressStyle = isPassed
                  ? { background: "#F0FDF4", color: "#166534", border: "1px solid #86EFAC" }
                  : hasScored
                    ? { background: "#FFF0E6", color: "#C2580A", border: "1px solid #FCDDBF" }
                    : { background: "#EEEDFE", color: "#5A51C0", border: "1px solid #D9D5F2" };
                const isActive = idx === currentTurn;
                const studentLine = targetLine(turn);
                return (
                  <div key={idx} style={{ background: "#fff", border: isActive ? "2px solid #7F77DD" : "1.5px solid #EDE9FA", borderRadius: 18, padding: 12 }}>
                    <div style={{ display: "flex", alignItems: "center", justifyContent: "space-between", marginBottom: 8 }}>
                      <span style={{ background: "#EEEDFE", color: "#5A51C0", borderRadius: 999, padding: "4px 10px", fontSize: 11, fontWeight: 800 }}>Turn {idx + 1}</span>
                      <div style={{ display: "flex", alignItems: "center", gap: 6 }}>
                        <span style={{ ...progressStyle, borderRadius: 999, padding: "4px 10px", fontSize: 11, fontWeight: 800 }}>
                          {progressLabel}
                        </span>
                        {!isActive && (
                          <button
                            onClick={() => selectTurnForPractice(idx)}
                            style={{ background: "#fff", color: "#7F77DD", border: "1.5px solid #EDE9FA", borderRadius: 999, padding: "4px 10px", fontSize: 11, fontWeight: 800, cursor: "pointer" }}
                          >
                            {hasScored ? (isPassed ? "Review block" : "Try again") : "Start block"}
                          </button>
                        )}
                      </div>
                    </div>

                    <div style={{ background: "#F5F3FF", border: "1px solid #EDE9FA", borderRadius: 14, padding: "10px 12px", marginBottom: 8 }}>
                      <div style={{ display: "flex", alignItems: "center", justifyContent: "space-between", gap: 8, marginBottom: 6, minHeight: 44 }}>
                        <div style={{ display: "flex", alignItems: "center", gap: 8 }}>
                          <img src={avatarSrc(teacherAvatar)} alt="Teacher avatar" style={{ width: 74, height: 74, borderRadius: "50%", objectFit: "contain", border: "1px solid #D9D5F2", background: "#fff", padding: 3, flexShrink: 0 }} />
                          <div style={{ fontSize: 14, fontWeight: 800, color: "#5A51C0" }}>{scene.agentName || "Teacher"}</div>
                        </div>
                        <button onClick={() => speakAgentLine(turn.agent)} disabled={ttsState !== "idle"} style={{ background: ttsState !== "idle" ? "#C5C1ED" : "#7F77DD", color: "#fff", border: "none", borderRadius: 12, padding: "10px 14px", fontSize: 13, fontWeight: 800, cursor: ttsState !== "idle" ? "not-allowed" : "pointer", minWidth: 94 }}>
                          {ttsState === "loading" ? "Loading..." : ttsState === "playing" ? "Playing..." : "Listen"}
                        </button>
                      </div>
                      <div style={{ background: "#fff", border: "1px solid #EDE9FA", borderRadius: 12, padding: "8px 12px", fontSize: 13, fontWeight: 700, color: "#2E2A45", lineHeight: 1.5 }}>
                        {turn.agent || "..."}
                      </div>
                    </div>

                    <div style={{ background: "#FFF7ED", border: "1px solid #FCDDBF", borderRadius: 14, padding: "10px 12px" }}>
                      <div style={{ display: "flex", alignItems: "center", justifyContent: "space-between", gap: 8, marginBottom: 6, minHeight: 44 }}>
                        <div style={{ display: "flex", alignItems: "center", gap: 8 }}>
                          <img src={avatarSrc(studentAvatar)} alt="Student avatar" style={{ width: 74, height: 74, borderRadius: "50%", objectFit: "contain", border: "1px solid #F3CC9C", background: "#fff", padding: 3, flexShrink: 0 }} />
                          <div style={{ fontSize: 13, fontWeight: 800, color: "#C2580A" }}>Repeat exactly this line</div>
                        </div>
                        <span style={{ fontSize: 10, color: "#9B8FCC", fontWeight: 800 }}>STUDENT</span>
                      </div>
                      <div style={{ background: "#fff", border: "1px solid #FCDDBF", borderRadius: 12, padding: "8px 12px", fontSize: 13, fontWeight: 700, color: "#8C4A0E", lineHeight: 1.5 }}>
                        {studentLine || "(No student line configured)"}
                      </div>
                      {turnResult && turnResult.feedback && turnResult.feedback.corrected && (
                        <div style={{ marginTop: 6, background: "#FFF0E6", border: "1px solid #FCDDBF", borderRadius: 10, padding: "8px 10px" }}>
                          <div style={{ fontSize: 10, fontWeight: 800, color: "#C2580A", textTransform: "uppercase", letterSpacing: ".05em", marginBottom: 2 }}>Correction</div>
                          <div style={{ fontSize: 12, fontWeight: 700, color: "#9A3412", lineHeight: 1.5 }}>
                            {turnResult.feedback.corrected}
                          </div>
                        </div>
                      )}

                      {turnResult && (
                        <div style={{ marginTop: 8, fontSize: 12, fontWeight: 700, color: "#534AB7" }}>
                          Student said: "{turnResult.transcript}"
                        </div>
                      )}

                      <div style={{ marginTop: 10, display: "flex", flexDirection: "column", gap: 8 }}>
                          {(() => {
                            const spokenText = (recorder.finalText + recorder.interimText).trim();
                            const currentTranscript = spokenText || (recorder.hasRecorded ? "(Audio response)" : "");
                            const currentScore = currentTranscript ? computeScore(currentTranscript, studentLine) : 0;
                            const isCorrect = currentTranscript && currentScore >= BLOCK_PASS_SCORE;
                            return (
                              <>
                          <div style={{ display: "flex", gap: 8, flexWrap: "wrap" }}>
                            <button
                              onClick={() => toggleRecordingForTurn(idx)}
                              style={{ background: recorder.isRecording ? "#EF4444" : "#7F77DD", color: "#fff", border: "none", borderRadius: 12, padding: "10px 14px", fontSize: 13, fontWeight: 800, cursor: "pointer", minWidth: 94 }}
                            >
                              {isActive && recorder.isRecording ? "Stop" : "Record"}
                            </button>
                            <button
                              onClick={() => {
                                if (!isActive) {
                                  selectTurnForPractice(idx);
                                  return;
                                }
                                recorder.playRecording();
                              }}
                              disabled={!isActive || !recorder.recordedAudioUrl}
                              style={{ background: (isActive && recorder.recordedAudioUrl) ? "#F97316" : "#FCDDBF", color: "#fff", border: "none", borderRadius: 12, padding: "10px 14px", fontSize: 13, fontWeight: 800, cursor: (isActive && recorder.recordedAudioUrl) ? "pointer" : "not-allowed", minWidth: 94 }}
                            >
                              Listen
                            </button>
                          </div>

                          <div style={{ fontSize: 12, fontWeight: 800, color: isActive ? (currentTranscript ? (isCorrect ? "#166534" : "#B91C1C") : "#6A63B0") : "#6A63B0", minHeight: 18 }}>
                            {isActive
                              ? (currentTranscript
                                ? `${isCorrect ? "Correct" : "Incorrect"}: ${currentTranscript}`
                                : "Waiting for student response...")
                              : "Tap Record to practice this block."}
                          </div>

                          {isActive && currentTranscript && (
                            <div style={{ fontSize: 11, fontWeight: 700, color: isCorrect ? "#166534" : "#B91C1C" }}>
                              {isCorrect ? "Good repetition." : "Try again and repeat exactly the line."}
                            </div>
                          )}

                          <div style={{ fontSize: 11, fontWeight: 700, color: "#6A63B0" }}>
                            Use the fixed action bar below to continue.
                          </div>
                              </>
                            );
                          })()}
                      </div>
                    </div>
                  </div>
                );
              })}

              <div style={{ position: "fixed", left: "50%", transform: "translateX(-50%)", bottom: 12, width: "min(720px, calc(100vw - 24px))", zIndex: 999, background: "#FFFFFF", border: "1.5px solid #EDE9FA", borderRadius: 14, padding: "10px", display: "flex", gap: 8, justifyContent: "center", flexWrap: "wrap", boxShadow: "0 8px 20px rgba(127,119,221,.18)" }}>
                <button
                  onClick={onListenFull}
                  style={{ background: C.purple, color: "#fff", border: "none", borderRadius: 12, padding: "10px 14px", fontSize: 13, fontWeight: 800, cursor: "pointer", minWidth: 148 }}
                >
                  Listen to roleplay
                </button>
                <button
                  onClick={handleAdvanceCurrentTurn}
                  disabled={!canAdvanceTurn}
                  style={{ background: canAdvanceTurn ? "#F97316" : "#E5E1F8", color: canAdvanceTurn ? "#fff" : "#AAA2D8", border: "none", borderRadius: 12, padding: "10px 14px", fontSize: 13, fontWeight: 800, cursor: canAdvanceTurn ? "pointer" : "not-allowed", minWidth: 94 }}
                >
                  Next
                </button>
              </div>
            </div>
          </div>

          {onBack && (
            <div style={{ marginTop: 10, display: "flex", justifyContent: "flex-start" }}>
              <button onClick={onBack} style={{ background: "#fff", border: "1.5px solid #EDE9FA", color: "#7F77DD", borderRadius: 999, padding: "10px 20px", fontSize: 13, fontWeight: 800, cursor: "pointer" }}>← Back</button>
            </div>
          )}
        </div>
    </div>
  );
}

// ── COMPLETION VIEW ───────────────────────────────────────────
function CompletionView({ scene, turns, results, onReview, onRetry }) {
  const totalTurns = Array.isArray(results) ? results.length : 0;
  const correctTurns = (results || []).filter(r => !!(r && r.feedback && r.feedback.passed)).length;
  const wrongTurns = Math.max(0, totalTurns - correctTurns);
  const scorePct = totalTurns > 0 ? Math.round((correctTurns / totalTurns) * 100) : 0;

  const total = (results || []).reduce((s, r) => s + (r && r.feedback ? (r.feedback.total || 0) : 0), 0);
  const avg = k => totalTurns > 0
    ? Math.round((results || []).reduce((s, r) => s + (r && r.feedback ? (r.feedback[k] || 0) : 0), 0) / totalTurns)
    : 0;

  return (
    <div style={{ background: C.bg, minHeight: "100%" }}>
      <div style={{ maxWidth: 760, margin: "0 auto", padding: "18px 16px 60px", display: "flex", flexDirection: "column", gap: 12 }}>

        <div style={{ display: "grid", gridTemplateColumns: "repeat(3, minmax(0, 1fr))", gap: 10 }}>
          <div style={{ background: "#FAFAFE", border: `1px solid ${C.cardBorder}`, borderRadius: 14, padding: 12, textAlign: "center" }}>
            <div style={{ fontFamily: "'Fredoka',sans-serif", fontSize: 24, lineHeight: 1, fontWeight: 600, color: C.purple }}>{correctTurns}</div>
            <div style={{ marginTop: 3, fontSize: 10, fontWeight: 800, letterSpacing: ".08em", textTransform: "uppercase", color: "#9B94BE" }}>Correct</div>
          </div>
          <div style={{ background: "#FAFAFE", border: `1px solid ${C.cardBorder}`, borderRadius: 14, padding: 12, textAlign: "center" }}>
            <div style={{ fontFamily: "'Fredoka',sans-serif", fontSize: 24, lineHeight: 1, fontWeight: 600, color: C.purple }}>{wrongTurns}</div>
            <div style={{ marginTop: 3, fontSize: 10, fontWeight: 800, letterSpacing: ".08em", textTransform: "uppercase", color: "#9B94BE" }}>Wrong</div>
          </div>
          <div style={{ background: "#FAFAFE", border: `1px solid ${C.cardBorder}`, borderRadius: 14, padding: 12, textAlign: "center" }}>
            <div style={{ fontFamily: "'Fredoka',sans-serif", fontSize: 24, lineHeight: 1, fontWeight: 600, color: C.purple }}>{scorePct}%</div>
            <div style={{ marginTop: 3, fontSize: 10, fontWeight: 800, letterSpacing: ".08em", textTransform: "uppercase", color: "#9B94BE" }}>Score</div>
          </div>
        </div>

        <div style={{ background: C.white, border: `1px solid ${C.cardBorder}`, borderRadius: 28, boxShadow: "0 12px 36px rgba(127,119,221,.13)", minHeight: "clamp(300px,42vh,430px)", display: "flex", flexDirection: "column", alignItems: "center", justifyContent: "center", textAlign: "center", padding: "clamp(28px,5vw,48px) 24px", gap: 12 }}>
          <div style={{ fontSize: 30, lineHeight: 1 }}>✅</div>
          <h2 style={{ margin: 0, color: C.orange, fontFamily: "'Fredoka',sans-serif", fontSize: 32, fontWeight: 700 }}>{scene.title || "Roleplay Kids"}</h2>
          <p style={{ margin: 0, color: "#9B94BE", fontSize: 14, fontWeight: 800 }}>You've completed this activity. Great job practicing.</p>
          <p style={{ margin: 0, color: "#666", fontSize: 14, fontWeight: 800 }}>{correctTurns} correct · {wrongTurns} wrong · {scorePct}%</p>

          <div style={{ display: "flex", justifyContent: "center", gap: 10, marginTop: 4, flexWrap: "wrap" }}>
            <button onClick={onReview} style={{ background: "#fff", border: `1.5px solid ${C.cardBorder}`, color: C.purple, borderRadius: 999, padding: "11px 20px", fontSize: 13, fontWeight: 800, fontFamily: "'Nunito',sans-serif", cursor: "pointer" }}>See review</button>
            <button onClick={onRetry} style={{ background: C.purple, border: "none", color: "#fff", borderRadius: 999, padding: "11px 20px", fontSize: 13, fontWeight: 800, fontFamily: "'Nunito',sans-serif", cursor: "pointer" }}>Restart</button>
          </div>

          <div style={{ marginTop: 4, display: "flex", gap: 8, flexWrap: "wrap", justifyContent: "center" }}>
            {[
              `${totalTurns} turns`,
              `Avg Grammar: ${avg("grammar")}`,
              `Avg Vocab: ${avg("vocabulary")}`,
              `Avg Fluency: ${avg("fluency")}`,
              `${total} / ${Math.max(1, turns.length) * 100} points`,
            ].map(lbl => (
              <span key={lbl} style={{ background: "#FAFAFE", border: `1px solid ${C.cardBorder}`, borderRadius: 20, padding: "5px 12px", fontSize: 11, fontWeight: 800, color: C.purple }}>{lbl}</span>
            ))}
          </div>
        </div>
      </div>
    </div>
  );
}

// ── REPLAY VIEW ───────────────────────────────────────────────
function ReplayView({ scene, turns, results, onBack }) {
  const [playing, setPlaying] = useState(false);
  const [activeIdx, setActiveIdx] = useState(null);
  const [activePart, setActivePart] = useState(null);
  const [speed, setSpeed] = useState(0.9);
  const queueRef = useRef([]);
  const idxRef = useRef(0);
  const playingRef = useRef(false);

  const buildQueue = () => {
    const q = [];
    results.forEach((r, i) => {
      q.push({ text: turns[r.turnIdx].agent, role: "ai", idx: i });
      q.push({ text: r.transcript, role: "you", idx: i });
    });
    return q;
  };

  const playNext = useCallback(() => {
    if (!playingRef.current || idxRef.current >= queueRef.current.length) {
      setPlaying(false); setActiveIdx(null); setActivePart(null); playingRef.current = false; return;
    }
    const item = queueRef.current[idxRef.current];
    setActiveIdx(item.idx); setActivePart(item.role);
    const utt = new SpeechSynthesisUtterance(item.text);
    utt.lang = "en-US"; utt.rate = speed; utt.pitch = item.role === "ai" ? 0.9 : 1.1;
    utt.onend = () => { idxRef.current++; setTimeout(playNext, 350); };
    utt.onerror = () => { idxRef.current++; playNext(); };
    window.speechSynthesis.speak(utt);
  }, [speed]);

  const playAll = () => {
    if (!window.speechSynthesis) return;
    window.speechSynthesis.cancel();
    queueRef.current = buildQueue();
    idxRef.current = 0; playingRef.current = true;
    setPlaying(true);
    playNext();
  };

  const stopAll = () => {
    playingRef.current = false;
    window.speechSynthesis.cancel();
    setPlaying(false); setActiveIdx(null); setActivePart(null);
  };

  useEffect(() => () => window.speechSynthesis.cancel(), []);

  return (
    <div style={{ background: C.bg, minHeight: "100%" }}>
      <div style={{ background: C.white, borderBottom: `2px solid #F0EEF8`, padding: "12px 20px", display: "flex", alignItems: "center", gap: 12, position: "sticky", top: 0, zIndex: 100 }}>
        <button onClick={onBack} style={{ width: 32, height: 32, borderRadius: 10, background: C.bg, border: "none", display: "flex", alignItems: "center", justifyContent: "center", cursor: "pointer", fontSize: 16, color: C.purple }}>←</button>
        <span style={{ fontFamily: "'Fredoka', sans-serif", fontSize: 18, fontWeight: 600, color: C.purple }}>💬 Conversation replay</span>
      </div>

      <div style={{ maxWidth: 680, margin: "0 auto", padding: "20px 16px 60px" }}>
        <Card>
          <div style={{ fontFamily: "'Fredoka', sans-serif", fontSize: 15, color: C.purple, marginBottom: 12 }}>
            Full conversation — {scene.title}
          </div>

          <div style={{ background: C.bgCard, border: `1.5px solid ${C.cardBorder}`, borderRadius: 12, padding: "10px 14px", display: "flex", alignItems: "center", gap: 10, marginBottom: 14, flexWrap: "wrap" }}>
            {!playing ? (
              <button onClick={playAll} style={{ background: C.purple, color: C.white, border: "none", borderRadius: 9, padding: "6px 14px", fontFamily: "'Nunito', sans-serif", fontWeight: 700, fontSize: 12, cursor: "pointer", display: "flex", alignItems: "center", gap: 5 }}>
                ▶ Play all
              </button>
            ) : (
              <button onClick={stopAll} style={{ background: "#EF4444", color: C.white, border: "none", borderRadius: 9, padding: "6px 14px", fontFamily: "'Nunito', sans-serif", fontWeight: 700, fontSize: 12, cursor: "pointer" }}>
                ⏹ Stop
              </button>
            )}
            <div style={{ display: "flex", alignItems: "center", gap: 6, marginLeft: "auto", fontSize: 11, fontWeight: 700, color: C.purpleSub }}>
              Speed
              <input type="range" min={0.6} max={1.4} step={0.1} value={speed} onChange={e => setSpeed(parseFloat(e.target.value))} style={{ width: 70 }} />
              {speed.toFixed(1)}×
            </div>
            {playing && (
              <div style={{ width: "100%", fontSize: 11, color: C.purple, fontWeight: 700 }}>
                Playing: {activePart === "ai" ? scene.agentName : "You"} (turn {(activeIdx ?? 0) + 1})
              </div>
            )}
          </div>

          {results.map((r, i) => {
            const t = turns[r.turnIdx];
            const fb = r.feedback;
            const diff = fb.corrected?.toLowerCase().trim() !== r.transcript.toLowerCase().trim();
            const agentActive = playing && activeIdx === i && activePart === "ai";
            const youActive = playing && activeIdx === i && activePart === "you";

            return (
              <div key={i} style={{ borderBottom: i < results.length - 1 ? `0.5px solid ${C.cardBorder}` : "none", paddingBottom: 12, marginBottom: 12 }}>
                <div style={{ display: "flex", gap: 8, alignItems: "flex-start", marginBottom: 6 }}>
                  <div style={{ width: 26, height: 26, borderRadius: "50%", background: C.purpleLight, display: "flex", alignItems: "center", justifyContent: "center", fontSize: 12, flexShrink: 0 }}>🤖</div>
                  <div style={{ maxWidth: "78%", padding: "7px 11px", borderRadius: 12, fontSize: 12, fontWeight: 500, lineHeight: 1.5, background: C.bg, color: "#333", borderBottomLeftRadius: 3, outline: agentActive ? `2px solid ${C.purple}` : "none", outlineOffset: 2 }}>
                    {t.agent}
                  </div>
                </div>
                <div style={{ display: "flex", gap: 8, alignItems: "flex-start", justifyContent: "flex-end", marginBottom: 6 }}>
                  <div style={{ maxWidth: "78%", padding: "7px 11px", borderRadius: 12, fontSize: 12, fontWeight: 500, lineHeight: 1.5, background: C.orangeLight, color: C.orangeMid, borderBottomRightRadius: 3, outline: youActive ? `2px solid ${C.orange}` : "none", outlineOffset: 2 }}>
                    {r.transcript}
                  </div>
                  <div style={{ width: 26, height: 26, borderRadius: "50%", background: C.orangeLight, display: "flex", alignItems: "center", justifyContent: "center", fontSize: 12, flexShrink: 0 }}>🙋</div>
                </div>
                {diff && (
                  <div style={{ background: "#FFFBEB", padding: "5px 10px", fontSize: 11, color: "#92400E", fontWeight: 600, marginBottom: 6, borderLeft: `2px solid ${C.orange}` }}>
                    ✏️ Better: "{fb.corrected}"
                  </div>
                )}
                <div style={{ display: "flex", justifyContent: "flex-end", gap: 5 }}>
                  {[["G", fb.grammar], ["V", fb.vocabulary], ["F", fb.fluency]].map(([k, v]) => (
                    <span key={k} style={{ fontSize: 10, fontWeight: 700, padding: "2px 7px", borderRadius: 20, background: C.purpleLight, color: "#534AB7" }}>{k}: {v}</span>
                  ))}
                  <span style={{ fontSize: 10, fontWeight: 700, padding: "2px 7px", borderRadius: 20, background: C.orangeLight, color: "#993C1D" }}>{fb.total}/100</span>
                </div>
              </div>
            );
          })}
        </Card>
      </div>
    </div>
  );
}

// ── ROOT APP ──────────────────────────────────────────────────
function RoleplayActivity() {
  const allowEditor = !!window.RK_ALLOW_EDITOR;
  const initialView = window.RK_START_VIEW === "editor" && allowEditor ? "editor" : "player";
  const [view, setView] = useState(initialView);
  const [scene, setScene] = useState(canonicalizeScene(window.RK_SAVED_SCENE || DEFAULT_SCENE));
  const [turns, setTurns] = useState(window.RK_SAVED_TURNS || JSON.parse(JSON.stringify(DEFAULT_TURNS)));
  const [results, setResults] = useState([]);
  const resultsStorageKey = `rk_results_${String(window.RK_ACTIVITY_ID || "new")}`;
  const [isListeningFull, setIsListeningFull] = useState(false);
  const listenCancelRef = useRef(false);
  const replayStudentVoiceCandidates = ["Nggzl2QAXh3OijoXD116", "NoOVOzCQFLOvtsMoNcdT", "nzFihrBIvB34imQBuxub"];

  const playReplayLine = useCallback(async (text, voiceCandidates) => {
    if (!text) return;
    const candidateList = Array.isArray(voiceCandidates) && voiceCandidates.length ? voiceCandidates : ["nzFihrBIvB34imQBuxub"];
    for (const voiceId of candidateList) {
      try {
        const blob = await fetchElevenLabsAudioBlob(text, voiceId);
        await playAudioBlob(blob, null);
        return;
      } catch (err) {
        // Try next configured voice.
      }
    }

    if (window.speechSynthesis) {
      await new Promise((resolve) => {
        try {
          const utt = new SpeechSynthesisUtterance(text);
          utt.lang = "en-US";
          utt.rate = 1.0;
          utt.pitch = 1.08;
          utt.onend = () => resolve();
          utt.onerror = () => resolve();
          window.speechSynthesis.speak(utt);
        } catch (e) {
          resolve();
        }
      });
      return;
    }

    throw new Error("No replay voice available");
  }, []);

  const handleListenFull = useCallback(() => {
    if (isListeningFull) {
      listenCancelRef.current = true;
      if (window.speechSynthesis) window.speechSynthesis.cancel();
      setIsListeningFull(false);
      return;
    }

    const queue = [];
    turns.forEach((t) => {
      const teacher = String(t.agent || "").trim();
      const student = String(t.ideal || t.hint || "").trim();
      if (teacher) queue.push({ text: teacher, role: "teacher" });
      if (student) queue.push({ text: student, role: "student" });
    });

    if (!queue.length) return;
    listenCancelRef.current = false;
    if (window.speechSynthesis) window.speechSynthesis.cancel();
    setIsListeningFull(true);

    let i = 0;
    const speakNext = async () => {
      if (listenCancelRef.current || i >= queue.length) {
        setIsListeningFull(false);
        return;
      }
      const item = queue[i++];
      try {
        const teacherVoice = resolveTeacherVoiceId(scene);
        const voiceCandidates = item.role === "teacher"
          ? [teacherVoice, "NoOVOzCQFLOvtsMoNcdT", "Nggzl2QAXh3OijoXD116", "nzFihrBIvB34imQBuxub"]
          : replayStudentVoiceCandidates;
        await playReplayLine(item.text, voiceCandidates);
      } catch (e) {
        console.warn("Roleplay Kids full replay failed:", e && e.message ? e.message : e);
      }
      speakNext();
    };

    speakNext();
  }, [isListeningFull, playReplayLine, replayStudentVoiceCandidates, scene.teacherVoiceId, turns]);

  useEffect(() => {
    try {
      const raw = localStorage.getItem(resultsStorageKey);
      if (!raw) return;
      const parsed = JSON.parse(raw);
      if (!Array.isArray(parsed)) return;
      const hydrated = parsed.filter(r => r && typeof r.turnIdx === "number" && r.feedback && typeof r.feedback.total === "number");
      if (hydrated.length) setResults(hydrated);
    } catch (e) {
      // Ignore malformed persisted progress.
    }
  }, [resultsStorageKey]);

  useEffect(() => {
    try {
      localStorage.setItem(resultsStorageKey, JSON.stringify(results));
    } catch (e) {
      // Ignore storage errors (quota/private mode).
    }
  }, [results, resultsStorageKey]);

  useEffect(() => {
    return () => {
      listenCancelRef.current = true;
      if (window.speechSynthesis) window.speechSynthesis.cancel();
    };
  }, []);

  useEffect(() => {
    console.log("[roleplay_kids] viewer bootstrap", {
      activityId: window.RK_ACTIVITY_ID || null,
      viewerPath: window.location.pathname,
      allowEditor,
      initialView,
      savedScene: window.RK_SAVED_SCENE,
      savedTurns: window.RK_SAVED_TURNS,
      loadedSceneState: scene,
      loadedTurnsState: turns,
      loadedTurnsCount: Array.isArray(turns) ? turns.length : 0,
    });
  }, []);

  return (
    <div>
      {allowEditor && view === "editor" && (
        <EditorView
          scene={scene} turns={turns}
          onSceneChange={setScene} onTurnsChange={setTurns}
          onStart={() => setView("player")}
        />
      )}
      {view === "player" && (
        <PlayerView
          scene={scene} turns={turns}
          onComplete={r => { setResults(r); setView("completion"); }}
          persistedResults={results}
          onResultsChange={setResults}
          onBack={allowEditor ? () => setView("editor") : null}
          onListenFull={handleListenFull}
        />
      )}
      {view === "completion" && (
        <CompletionView
          scene={scene} turns={turns} results={results}
          onReview={() => setView("replay")}
          onRetry={() => setView("player")}
        />
      )}
      {view === "replay" && (
        <ReplayView
          scene={scene} turns={turns} results={results}
          onBack={() => setView("completion")}
        />
      )}
    </div>
  );
}

const _rpRoot = document.getElementById('roleplay-kids-root');
if (_rpRoot) ReactDOM.createRoot(_rpRoot).render(React.createElement(RoleplayActivity));
</script>
<?php
$content = ob_get_clean();
render_activity_viewer('Roleplay Kids', 'fa-solid fa-children', $content);
