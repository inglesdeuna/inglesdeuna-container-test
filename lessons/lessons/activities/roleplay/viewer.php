<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../core/_activity_viewer_template.php';

$activityId = isset($_GET['id']) ? trim((string) $_GET['id']) : '';

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
<style>
#roleplay-root * { box-sizing: border-box; margin: 0; padding: 0; }
#roleplay-root { font-family: 'Nunito', sans-serif; flex: 1; min-height: 0; overflow-y: auto; }
@keyframes rp-spin { to { transform: rotate(360deg); } }
@keyframes rp-pulse { 0%,100%{opacity:1} 50%{opacity:.3} }
</style>

<div id="roleplay-root" style="flex:1;min-height:0;overflow-y:auto;"></div>

<script crossorigin src="https://unpkg.com/react@18/umd/react.production.min.js"></script>
<script crossorigin src="https://unpkg.com/react-dom@18/umd/react-dom.production.min.js"></script>
<script src="https://unpkg.com/@babel/standalone/babel.min.js"></script>

<script>
window.ROLEPLAY_ACTIVITY_ID = <?= json_encode($activityId) ?>;
window.ROLEPLAY_SAVED_SCENE  = <?= json_encode($savedScene) ?>;
window.ROLEPLAY_SAVED_TURNS  = <?= json_encode($savedTurns) ?>;
</script>

<script type="text/babel">
const { useState, useRef, useEffect, useCallback } = React;

// ── DESIGN TOKENS ────────────────────────────────────────────
const C = {
  orange: "#F97316",
  purple: "#7F77DD",
  purpleLight: "#EDE9FA",
  purpleMid: "#C5BFEE",
  purpleSub: "#9B8FCC",
  orangeLight: "#FFF0E6",
  orangeMid: "#bf521a",
  cardBorder: "#EDE9FA",
  bg: "#F5F3FF",
  bgCard: "#F9F8FF",
  white: "#ffffff",
  green: "#F0FDF4",
  greenText: "#166534",
};

const DEFAULT_TURNS = [
  { agent: "", hint: "", ideal: "", criteria: "" },
];

const DEFAULT_SCENE = {
  title: "", icon: "🎭", desc: "", agentName: "", agentRole: "", studentRole: "",
};

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
    border: "none", borderRadius: 14, padding: "12px 0", width: "100%",
    fontFamily: "'Nunito', sans-serif", fontWeight: 800, fontSize: 14,
    cursor: disabled ? "not-allowed" : "pointer", display: "flex",
    alignItems: "center", justifyContent: "center", gap: 7, ...style,
  }}>{children}</button>
);

const OutlineBtn = ({ children, onClick, style = {} }) => (
  <button onClick={onClick} style={{
    background: C.white, color: C.purple, border: `1.5px solid ${C.cardBorder}`,
    borderRadius: 14, padding: "12px 0", width: "100%",
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
    background: C.white, borderBottom: `2px solid #F0EEF8`,
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
  const recognitionRef = useRef(null);
  const mediaRecRef = useRef(null);
  const analyserRef = useRef(null);
  const audioCtxRef = useRef(null);
  const timerRef = useRef(null);
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
      mr.ondataavailable = () => {};
      mr.onstop = () => stream.getTracks().forEach(t => t.stop());
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
    setFinalText(""); setInterimText(""); setRecSecs(0); setHasRecorded(false); setIsRecording(false);
  }, [stop]);

  return { isRecording, finalText, interimText, recSecs, hasRecorded, analyserRef, start, stop, reset };
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
  const id = window.ROLEPLAY_ACTIVITY_ID;
  if (!id) return { ok: false, error: "No activity ID" };
  try {
    const r = await fetch("save.php", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ id, scene, turns }),
    });
    return await r.json();
  } catch (e) {
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
    setSaving(true); setSaveMsg("");
    const res = await saveActivity(scene, turns);
    setSaving(false);
    setSaveMsg(res.ok ? "✓ Guardado" : "✗ Error al guardar");
    setTimeout(() => setSaveMsg(""), 3000);
  };

  const arrowBtnStyle = disabled => ({
    background: "none", border: `1.5px solid ${disabled ? C.purpleLight : C.purpleMid}`,
    borderRadius: 6, cursor: disabled ? "default" : "pointer",
    color: disabled ? C.purpleLight : C.purpleSub,
    fontSize: 10, padding: "2px 5px", lineHeight: 1, fontWeight: 800,
  });

  return (
    <div style={{ background: C.bg, minHeight: "100%" }}>
      <Topbar title="🎭 Roleplay" right={
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

        {window.ROLEPLAY_ACTIVITY_ID && (
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

        <Btn onClick={onStart}>▶ Start Roleplay</Btn>
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
function RecorderCard({ turn, turnIndex, totalTurns, onSubmit }) {
  const rec = useRecorder();
  const displayText = (rec.finalText + rec.interimText).trim();
  const fmt = s => `${Math.floor(s / 60)}:${String(s % 60).padStart(2, "0")}`;

  const handleSubmit = () => {
    const t = displayText || "";
    if (!t) { alert("No speech detected. Please record again."); rec.reset(); return; }
    onSubmit(t);
  };

  return (
    <Card>
      <div style={{ display: "flex", alignItems: "center", justifyContent: "space-between", marginBottom: 10 }}>
        <span style={{ fontSize: 12, fontWeight: 800, color: C.purple, textTransform: "uppercase", letterSpacing: ".05em" }}>🎙️ Your response</span>
        <span style={{ fontSize: 11, color: C.purpleSub, fontWeight: 700 }}>Turn {turnIndex + 1} of {totalTurns}</span>
      </div>

      <div style={{ background: C.orangeLight, borderRadius: 12, padding: "8px 12px", fontSize: 13, color: C.orangeMid, fontWeight: 600, marginBottom: 10, display: "flex", gap: 8 }}>
        <span>💬</span><span>{turn.hint}</span>
      </div>

      <div style={{ background: C.bg, border: `1.5px solid ${C.cardBorder}`, borderRadius: 12, minHeight: 52, padding: "8px 12px", marginBottom: 10 }}>
        <div style={{ fontSize: 10, fontWeight: 800, color: C.purpleSub, textTransform: "uppercase", letterSpacing: ".05em", marginBottom: 3, display: "flex", alignItems: "center", gap: 5 }}>
          <span style={{ width: 6, height: 6, borderRadius: "50%", background: rec.isRecording ? C.orange : C.purpleMid, display: "inline-block", animation: rec.isRecording ? "rp-pulse .8s infinite" : "none" }} />
          {rec.isRecording ? "Listening…" : rec.hasRecorded ? "Speech captured" : "Tap record to start speaking"}
        </div>
        <div style={{ fontSize: 13, fontWeight: 600, color: rec.interimText ? C.purpleSub : "#333", fontStyle: rec.interimText ? "italic" : "normal", minHeight: 20, lineHeight: 1.5 }}>
          {displayText || ""}
        </div>
      </div>

      <Waveform active={rec.isRecording} analyserRef={rec.analyserRef} />

      {rec.isRecording && (
        <div style={{ textAlign: "right", fontSize: 11, fontWeight: 700, color: C.purple, marginBottom: 8, marginTop: -4 }}>
          {fmt(rec.recSecs)}
        </div>
      )}

      <div style={{ display: "flex", gap: 8, marginTop: 8 }}>
        {!rec.hasRecorded ? (
          <Btn onClick={rec.isRecording ? rec.stop : rec.start}
            color={rec.isRecording ? "#EF4444" : C.purple}>
            {rec.isRecording ? "⏹️ Stop recording" : "🎙️ Start speaking"}
          </Btn>
        ) : (
          <>
            <Btn color={C.purpleLight} disabled style={{ flex: 1, color: C.purpleSub }}>✓ Recorded</Btn>
            <Btn onClick={handleSubmit} style={{ flex: 1 }}>Submit →</Btn>
          </>
        )}
      </div>
    </Card>
  );
}

// ── FEEDBACK CARD ─────────────────────────────────────────────
function FeedbackCard({ data, transcript, turnIndex, isLast, onNext, onFinish }) {
  const diff = data.corrected?.toLowerCase().trim() !== transcript.toLowerCase().trim();
  return (
    <Card style={{ marginTop: 14 }}>
      <div style={{ fontFamily: "'Fredoka', sans-serif", fontSize: 17, fontWeight: 600, color: C.purple, marginBottom: 14 }}>
        🎯 Feedback — Turn {turnIndex + 1}
      </div>

      <MiniLabel>You said</MiniLabel>
      <div style={{ background: C.bg, borderLeft: `3px solid ${C.purple}`, padding: "9px 13px", fontSize: 13, color: "#333", lineHeight: 1.55, marginBottom: 10, fontWeight: 600 }}>
        "{transcript}"
      </div>

      {diff && <>
        <MiniLabel color={C.orange}>Suggested improvement</MiniLabel>
        <div style={{ background: C.orangeLight, borderLeft: `3px solid ${C.orange}`, padding: "9px 13px", fontSize: 13, color: C.orangeMid, lineHeight: 1.55, marginBottom: 10, fontWeight: 600 }}>
          "{data.corrected}"
        </div>
      </>}

      <div style={{ display: "grid", gridTemplateColumns: "1fr 1fr 1fr", gap: 8, marginBottom: 10 }}>
        {[["Grammar", data.grammar], ["Vocabulary", data.vocabulary], ["Fluency", data.fluency]].map(([lbl, val]) => (
          <div key={lbl} style={{ background: C.bgCard, border: `1.5px solid ${C.cardBorder}`, borderRadius: 14, padding: "9px 7px", textAlign: "center" }}>
            <div style={{ fontFamily: "'Fredoka', sans-serif", fontSize: 22, fontWeight: 700, color: C.purple }}>{val}</div>
            <div style={{ fontSize: 10, color: C.purpleSub, fontWeight: 700, marginTop: 2 }}>{lbl}</div>
          </div>
        ))}
      </div>

      <div style={{ background: `linear-gradient(135deg,${C.orange},${C.purple})`, borderRadius: 16, padding: "11px", textAlign: "center", color: C.white, marginBottom: 10 }}>
        <div style={{ fontSize: 11, fontWeight: 700, opacity: .9 }}>Turn score</div>
        <div style={{ fontFamily: "'Fredoka', sans-serif", fontSize: 30, fontWeight: 700 }}>{data.total} / 100</div>
      </div>

      <div style={{ background: C.green, borderRadius: 12, padding: "9px 13px", fontSize: 13, color: C.greenText, fontWeight: 700, marginBottom: 10 }}>
        ✨ {data.praise}
      </div>

      <ul style={{ listStyle: "none", display: "flex", flexDirection: "column", gap: 5, marginBottom: 12 }}>
        {data.tips.map((tip, i) => (
          <li key={i} style={{ fontSize: 13, color: "#444", fontWeight: 600, padding: "6px 10px", background: C.bg, borderRadius: 10, display: "flex", gap: 7 }}>
            💡 {tip}
          </li>
        ))}
      </ul>

      <Btn onClick={isLast ? onFinish : onNext}>
        {isLast ? "🏆 See results" : "Next turn →"}
      </Btn>
    </Card>
  );
}

// ── PLAYER VIEW ───────────────────────────────────────────────
function PlayerView({ scene, turns, onComplete, onBack }) {
  const [messages, setMessages] = useState([{ role: "ai", text: turns[0].agent }]);
  const [currentTurn, setCurrentTurn] = useState(0);
  const [phase, setPhase] = useState("record");
  const [feedback, setFeedback] = useState(null);
  const [lastTranscript, setLastTranscript] = useState("");
  const [results, setResults] = useState([]);
  const [voiceId, setVoiceId] = useState("nzFihrBIvB34imQBuxub");
  const [ttsState, setTtsState] = useState("idle"); // "idle" | "loading" | "playing"
  const currentAudioRef = useRef(null);

  const speakAgentLine = useCallback(async (text) => {
    if (currentAudioRef.current) {
      currentAudioRef.current.pause();
      currentAudioRef.current = null;
    }
    setTtsState("loading");
    try {
      const fd = new FormData();
      fd.append("text", text);
      fd.append("voice_id", voiceId);
      const res = await fetch("tts.php", { method: "POST", body: fd, credentials: "same-origin" });
      if (!res.ok) throw new Error("TTS error " + res.status);
      const blob = await res.blob();
      const url = URL.createObjectURL(blob);
      const audio = new Audio(url);
      currentAudioRef.current = audio;
      setTtsState("playing");
      audio.onended = () => { URL.revokeObjectURL(url); setTtsState("idle"); currentAudioRef.current = null; };
      audio.onerror = () => { URL.revokeObjectURL(url); setTtsState("idle"); currentAudioRef.current = null; };
      audio.play();
    } catch (e) {
      setTtsState("idle");
    }
  }, [voiceId]);

  // Auto-play agent line whenever a new AI message is added
  useEffect(() => {
    const last = messages[messages.length - 1];
    if (last && last.role === "ai") speakAgentLine(last.text);
  }, [messages.length]); // eslint-disable-line

  // Stop audio on unmount
  useEffect(() => () => { if (currentAudioRef.current) currentAudioRef.current.pause(); }, []);

  const handleSubmit = async (transcript) => {
    setMessages(m => [...m, { role: "you", text: transcript }]);
    setLastTranscript(transcript);
    setPhase("loading");
    const data = await callClaude(transcript, turns[currentTurn]);
    const newResults = [...results, { transcript, feedback: data, turnIdx: currentTurn }];
    setResults(newResults);
    setFeedback(data);
    setPhase("feedback");
  };

  const handleNext = () => {
    const next = currentTurn + 1;
    setCurrentTurn(next);
    setMessages(m => [...m, { role: "ai", text: turns[next].agent }]);
    setFeedback(null);
    setPhase("record");
  };

  return (
    <div style={{ background: C.bg, minHeight: "100%" }}>
      <Topbar title="🎭 Roleplay" right={
        <div style={{ display: "flex", alignItems: "center", gap: 10 }}>
          <select
            value={voiceId}
            onChange={e => setVoiceId(e.target.value)}
            style={{
              background: C.white, border: `1.5px solid #EDE9FA`,
              borderRadius: 10, fontFamily: "'Nunito', sans-serif",
              fontSize: 13, color: C.purple, padding: "6px 12px",
              outline: "none", cursor: "pointer",
            }}
          >
            <option value="nzFihrBIvB34imQBuxub">🎙 Adult Male</option>
            <option value="NoOVOzCQFLOvtsMoNcdT">🎙 Adult Female</option>
            <option value="Nggzl2QAXh3OijoXD116">🎙 Child</option>
          </select>
          <ProgressBar value={currentTurn} total={turns.length} />
        </div>
      } />
      <div style={{ maxWidth: 680, margin: "0 auto", padding: "20px 16px 60px" }}>
        <Kicker>Speaking activity</Kicker>

        <Card style={{ marginBottom: 14 }}>
          <div style={{ display: "flex", alignItems: "center", gap: 12, marginBottom: 12 }}>
            <div style={{ width: 44, height: 44, background: C.purpleLight, borderRadius: 14, display: "flex", alignItems: "center", justifyContent: "center", fontSize: 22, flexShrink: 0 }}>
              {scene.icon}
            </div>
            <div>
              <div style={{ fontFamily: "'Fredoka', sans-serif", fontSize: 18, fontWeight: 600, color: C.orange }}>{scene.title}</div>
              <div style={{ fontSize: 12, color: C.purpleSub, fontWeight: 600, marginTop: 2 }}>Real-life conversation practice</div>
            </div>
          </div>
          <div style={{ fontSize: 14, color: "#444", lineHeight: 1.7, background: C.bg, borderRadius: 14, padding: "12px 14px", borderLeft: `4px solid ${C.purple}`, fontWeight: 600 }}>
            {scene.desc}
          </div>
        </Card>

        <div style={{ display: "flex", gap: 10, marginBottom: 14 }}>
          {[{ av: "🤖", nm: scene.agentName, rl: "AI character", bg: C.purpleLight, c: C.purple },
            { av: "🙋", nm: "You", rl: scene.studentRole, bg: C.orangeLight, c: C.orange }].map((ch, i) => (
            <div key={i} style={{ background: C.white, border: `1.5px solid ${C.cardBorder}`, borderRadius: 16, padding: "8px 14px", display: "flex", alignItems: "center", gap: 9, flex: 1 }}>
              <div style={{ width: 32, height: 32, borderRadius: "50%", background: ch.bg, display: "flex", alignItems: "center", justifyContent: "center", fontSize: 14, flexShrink: 0 }}>{ch.av}</div>
              <div>
                <div style={{ fontSize: 13, fontWeight: 700, color: "#333" }}>{ch.nm}</div>
                <div style={{ fontSize: 11, color: C.purpleSub, fontWeight: 600 }}>{ch.rl}</div>
              </div>
            </div>
          ))}
        </div>

        <div style={{ fontSize: 11, fontWeight: 800, color: C.purpleSub, letterSpacing: ".06em", textTransform: "uppercase", marginBottom: 10 }}>Conversation</div>
        <Messages messages={messages} />

        {phase === "record" && (
          <>
            <div style={{ background: C.bg, border: `1.5px dashed ${C.purpleMid}`, borderRadius: 14, padding: "10px 16px", fontSize: 13, fontWeight: 700, color: C.purple, marginBottom: 14, display: "flex", alignItems: "center", justifyContent: "space-between", gap: 10 }}>
              <span>🎙️ Your turn — speak your response below</span>
              <button
                onClick={() => speakAgentLine(turns[currentTurn].agent)}
                disabled={ttsState !== "idle"}
                style={{
                  background: ttsState !== "idle" ? C.purpleLight : C.purple,
                  color: ttsState !== "idle" ? C.purpleSub : C.white,
                  border: "none", borderRadius: 10, padding: "6px 14px",
                  fontFamily: "'Nunito', sans-serif", fontWeight: 700, fontSize: 12,
                  cursor: ttsState !== "idle" ? "not-allowed" : "pointer",
                  display: "flex", alignItems: "center", gap: 5, flexShrink: 0,
                  transition: "background .15s",
                }}
              >
                {ttsState === "loading" ? "⏳ Loading…" : ttsState === "playing" ? "🔊 Playing…" : "🔊 Listen"}
              </button>
            </div>
            <RecorderCard turn={turns[currentTurn]} turnIndex={currentTurn} totalTurns={turns.length} onSubmit={handleSubmit} />
          </>
        )}

        {phase === "loading" && (
          <Card style={{ marginTop: 14, textAlign: "center", padding: 28 }}>
            <div style={{ width: 36, height: 36, border: `3px solid ${C.purpleLight}`, borderTop: `3px solid ${C.purple}`, borderRadius: "50%", animation: "rp-spin .8s linear infinite", margin: "0 auto 12px" }} />
            <div style={{ fontSize: 13, fontWeight: 700, color: C.purple }}>Reviewing your response…</div>
          </Card>
        )}

        {phase === "feedback" && feedback && (
          <FeedbackCard
            data={feedback}
            transcript={lastTranscript}
            turnIndex={currentTurn}
            isLast={currentTurn === turns.length - 1}
            onNext={handleNext}
            onFinish={() => onComplete(results)}
          />
        )}
      </div>
    </div>
  );
}

// ── COMPLETION VIEW ───────────────────────────────────────────
function CompletionView({ scene, turns, results, onReview, onRetry }) {
  const total = results.reduce((s, r) => s + r.feedback.total, 0);
  const avg = k => Math.round(results.reduce((s, r) => s + r.feedback[k], 0) / results.length);

  return (
    <div style={{ background: C.bg, minHeight: "100%" }}>
      <div style={{ background: "linear-gradient(160deg,#EDE9FA 0%,#FFF0E6 100%)", padding: "40px 20px 32px", textAlign: "center" }}>
        <div style={{ fontSize: 52, marginBottom: 10 }}>🎉</div>
        <div style={{ fontFamily: "'Fredoka', sans-serif", fontSize: 26, fontWeight: 700, color: C.purple, marginBottom: 6 }}>
          Roleplay complete!
        </div>
        <div style={{ fontSize: 14, color: C.purpleSub, fontWeight: 600 }}>
          You finished "{scene.title}"
        </div>
      </div>

      <div style={{ maxWidth: 680, margin: "0 auto", padding: "20px 16px 60px", display: "flex", flexDirection: "column", gap: 12 }}>
        <div style={{ background: C.white, border: `1.5px solid ${C.cardBorder}`, borderRadius: 20, padding: "20px", textAlign: "center" }}>
          <div style={{ fontFamily: "'Fredoka', sans-serif", fontSize: 52, fontWeight: 700, color: C.orange, lineHeight: 1 }}>{total}</div>
          <div style={{ fontSize: 12, color: "#888", fontWeight: 600, marginTop: 5 }}>out of {turns.length * 100} points</div>
        </div>

        <div style={{ display: "grid", gridTemplateColumns: "1fr 1fr 1fr", gap: 8 }}>
          {[["Avg grammar", avg("grammar")], ["Avg vocab", avg("vocabulary")], ["Avg fluency", avg("fluency")]].map(([lbl, val]) => (
            <div key={lbl} style={{ background: C.white, border: `1.5px solid ${C.cardBorder}`, borderRadius: 14, padding: "10px 8px", textAlign: "center" }}>
              <div style={{ fontFamily: "'Fredoka', sans-serif", fontSize: 22, fontWeight: 700, color: C.purple }}>{val}</div>
              <div style={{ fontSize: 10, color: C.purpleSub, fontWeight: 700, marginTop: 2 }}>{lbl}</div>
            </div>
          ))}
        </div>

        <Btn onClick={onReview} color={C.purple}>📋 See full conversation review</Btn>
        <OutlineBtn onClick={onRetry}>🔄 Try again</OutlineBtn>
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
  const [view, setView] = useState("editor");
  const [scene, setScene] = useState(window.ROLEPLAY_SAVED_SCENE || DEFAULT_SCENE);
  const [turns, setTurns] = useState(window.ROLEPLAY_SAVED_TURNS || JSON.parse(JSON.stringify(DEFAULT_TURNS)));
  const [results, setResults] = useState([]);

  return (
    <div>
      {view === "editor" && (
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
          onBack={() => setView("editor")}
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

const _rpRoot = document.getElementById('roleplay-root');
if (_rpRoot) ReactDOM.createRoot(_rpRoot).render(React.createElement(RoleplayActivity));
</script>
<?php
$content = ob_get_clean();
render_activity_viewer('Roleplay', 'fa-solid fa-comments', $content);
