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
/* ── Reset ──────────────────────────────────────────────── */
#roleplay-kids-root *{box-sizing:border-box;margin:0;padding:0;}
#roleplay-kids-root{font-family:'Nunito','Segoe UI',system-ui,sans-serif;flex:1;min-height:0;overflow-y:auto;background:#fff;}

/* ── Keyframes ──────────────────────────────────────────── */
@keyframes rk-spin   {to{transform:rotate(360deg)}}
@keyframes rk-pulse  {0%,100%{opacity:1}50%{opacity:.3}}
@keyframes rk-bounce {0%,100%{transform:scale(1)}50%{transform:scale(1.12)}}
@keyframes rk-shake  {0%,100%{transform:translateX(0)}25%{transform:translateX(-6px)}75%{transform:translateX(6px)}}

/* ── Shell & layout ─────────────────────────────────────── */
.rk-shell{width:100%;flex:1;min-height:0;overflow-y:auto;padding:clamp(14px,2.5vw,34px);display:flex;align-items:flex-start;justify-content:center;background:#fff;}
.rk-app{width:min(680px,100%);display:grid;grid-template-columns:minmax(0,1fr);gap:clamp(10px,2vw,18px);}

/* ── Hero ───────────────────────────────────────────────── */
.rk-hero{text-align:center;}
.rk-kicker{display:inline-flex;align-items:center;gap:7px;margin-bottom:8px;padding:7px 14px;border-radius:999px;background:#FFF0E6;border:1px solid #FCDDBF;color:#C2580A;font-size:12px;font-weight:900;letter-spacing:.08em;text-transform:uppercase;}
.rk-title{margin:0;font-family:'Fredoka',sans-serif;font-size:clamp(28px,5.5vw,52px);line-height:1.05;color:#F97316;font-weight:700;}
.rk-subtitle{margin:8px 0 0;color:#9B94BE;font-size:clamp(13px,1.8vw,16px);font-weight:800;}

/* ── Board ──────────────────────────────────────────────── */
.rk-board{background:#fff;border:1px solid #EDE9FA;border-radius:28px;overflow:hidden;box-shadow:0 8px 40px rgba(127,119,221,.13);}
.rk-board-padded{padding:clamp(16px,2.6vw,26px);}

/* ── Top bar ────────────────────────────────────────────── */
.rk-topbar{display:flex;align-items:center;gap:8px;padding:12px 18px;border-bottom:1px solid #F0EEF8;flex-wrap:wrap;}
.rk-back-btn{display:inline-flex;align-items:center;gap:5px;padding:7px 14px;border:1.5px solid #EDE9FA;border-radius:999px;background:#fff;color:#271B5D;font-family:'Nunito',sans-serif;font-weight:800;font-size:13px;cursor:pointer;flex-shrink:0;transition:background .15s;}
.rk-back-btn:hover{background:#FAFAFE;}
.rk-scene-title{font-weight:800;font-size:16px;color:#F97316;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.rk-topbar-right{margin-left:auto;display:flex;align-items:center;gap:8px;flex-shrink:0;}
.rk-voice-chip{display:inline-flex;align-items:center;gap:4px;padding:6px 12px;border:1.5px solid #EDE9FA;border-radius:999px;background:#fff;font-size:12px;font-weight:800;color:#9B94BE;}
.rk-turn-badge{padding:6px 14px;border-radius:999px;background:#7F77DD;color:#fff;font-size:12px;font-weight:900;white-space:nowrap;}

/* ── Scene bar ──────────────────────────────────────────── */
.rk-scene-bar{display:flex;justify-content:space-between;align-items:center;padding:8px 18px;background:#FFF0E6;border-bottom:1px solid #FCDDBF;flex-wrap:wrap;gap:4px;}
.rk-scene-bar-left{display:flex;align-items:center;gap:6px;font-weight:800;font-size:13px;color:#C2580A;}
.rk-scene-bar-right{font-size:12px;font-weight:700;color:#C2580A;}

/* ── Progress ───────────────────────────────────────────── */
.rk-progress-row{display:flex;align-items:center;gap:10px;padding:10px 18px;border-bottom:1px solid #F0EEF8;}
.rk-progress-counter{font-size:12px;font-weight:900;color:#9B94BE;min-width:28px;flex-shrink:0;}
.rk-progress-track{flex:1;height:10px;background:#F4F2FD;border-radius:999px;overflow:hidden;border:1px solid #E4E1F8;}
.rk-progress-fill{height:100%;width:0%;background:linear-gradient(90deg,#F97316,#7F77DD);border-radius:999px;transition:width .45s cubic-bezier(.2,.9,.2,1);}
.rk-progress-badge{min-width:90px;text-align:center;padding:6px 12px;border-radius:999px;background:#7F77DD;color:#fff;font-size:12px;font-weight:900;white-space:nowrap;flex-shrink:0;}

/* ── Content wrapper ────────────────────────────────────── */
.rk-content{padding:14px 18px 0;}

/* ── Shared card header ─────────────────────────────────── */
.rk-card-header{display:flex;align-items:center;gap:10px;margin-bottom:10px;}
.rk-avatar{width:42px;height:42px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:900;font-size:18px;flex-shrink:0;}
.rk-avatar-purple{background:#EEEDFE;border:2px solid #7F77DD;color:#534AB7;}
.rk-avatar-orange{background:#FFF0E6;border:2px solid #F97316;color:#C2580A;}
.rk-speaker-name{font-weight:800;font-size:14px;color:#7F77DD;}
.rk-speaker-name-you{color:#7F77DD;}
.rk-speaker-role{font-size:10px;font-weight:900;color:#9B94BE;text-transform:uppercase;letter-spacing:.06em;}

/* ── Teacher card ───────────────────────────────────────── */
.rk-teacher-card{background:#F5F3FF;border:1px solid #EDE9FA;border-radius:18px;padding:14px 16px;margin-bottom:10px;}
.rk-tts-btn{margin-left:auto;display:inline-flex;align-items:center;gap:5px;padding:6px 14px;border:1.5px solid #EDE9FA;border-radius:999px;background:#fff;font-family:'Nunito',sans-serif;font-weight:800;font-size:12px;cursor:pointer;color:#9B94BE;flex-shrink:0;transition:background .15s,color .15s,border-color .15s;}
.rk-tts-btn:hover{background:#FAFAFE;}
.rk-tts-btn.is-playing{color:#534AB7;border-color:#7F77DD;}
.rk-dialog-text{font-weight:800;font-size:15px;color:#271B5D;line-height:1.65;}

/* ── Hint card ──────────────────────────────────────────── */
.rk-hint-card{background:#FFF7ED;border:1px solid #FCDDBF;border-radius:14px;padding:11px 16px;margin-bottom:10px;}
.rk-hint-label{font-size:10px;font-weight:900;color:#F97316;text-transform:uppercase;letter-spacing:.08em;margin-bottom:4px;display:flex;align-items:center;gap:5px;}
.rk-hint-text{font-weight:800;font-size:14px;color:#C2580A;line-height:1.55;}

/* ── Your turn card ─────────────────────────────────────── */
.rk-your-turn-card{background:#fff;border:2px solid #7F77DD;border-radius:18px;padding:14px 16px;box-shadow:0 4px 16px rgba(127,119,221,.12);}

/* ── Mic area ───────────────────────────────────────────── */
.rk-mic-area{text-align:center;padding:6px 0 8px;}
.rk-mic-btn{width:96px;height:96px;border-radius:10px;background:#fff;border:2px solid #F97316;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;margin-bottom:10px;font-size:36px;box-shadow:0 6px 20px rgba(249,115,22,.35);transition:border-color .15s,box-shadow .15s;}
.rk-mic-btn:hover:not(:disabled){border-color:#F97316;box-shadow:0 8px 24px rgba(249,115,22,.50);}
.rk-mic-btn:disabled{cursor:default;opacity:.6;}
.rk-mic-btn.rk-recording{border-color:#E24B4A;background:rgba(226,75,74,.05);box-shadow:0 4px 18px rgba(226,75,74,.16);}
.rk-mic-hint{font-size:13px;font-weight:700;color:#9B94BE;margin-bottom:12px;}
.rk-mic-hint.rk-recording{color:#E24B4A;}
.rk-or-divider{font-size:12px;font-weight:700;color:#9B94BE;margin:0 0 10px;}

/* ── Buttons ────────────────────────────────────────────── */
.rk-btn{border:0;border-radius:10px;padding:11px 20px;color:#F97316;font-family:'Nunito',sans-serif;font-size:13px;font-weight:900;cursor:pointer;transition:transform .18s,box-shadow .18s;display:inline-flex;align-items:center;justify-content:center;gap:6px;}
.rk-btn:hover:not(:disabled){transform:translateY(-1px);filter:brightness(1.05);}
.rk-btn:disabled{opacity:.45;cursor:default;transform:none;filter:none;}
.rk-btn-orange{background:#fff;color:#F97316;border:2px solid #F97316;box-shadow:0 6px 20px rgba(249,115,22,.45);}
.rk-btn-purple{background:#fff;color:#F97316;border:2px solid #F97316;box-shadow:0 6px 20px rgba(249,115,22,.45);}
.rk-btn-outline{background:#fff;color:#534AB7;border:1.5px solid #EDE9FA;box-shadow:0 4px 12px rgba(127,119,221,.10);}
.rk-btn-outline:hover:not(:disabled){background:#EEEDFE;}
.rk-btn-ghost{background:#fff;color:#9B94BE;border:1.5px solid #EDE9FA;font-family:'Nunito',sans-serif;font-weight:800;font-size:13px;cursor:pointer;border-radius:999px;padding:9px 20px;transition:background .15s;}
.rk-btn-ghost:hover{background:#FAFAFE;}

/* ── Textarea ───────────────────────────────────────────── */
.rk-textarea{width:100%;min-height:96px;border:1.5px solid #EDE9FA;border-radius:14px;padding:12px 14px;font-family:'Nunito',sans-serif;font-size:15px;font-weight:700;color:#271B5D;resize:none;outline:none;background:#FAFAFE;transition:border-color .18s,box-shadow .18s;margin-bottom:10px;}
.rk-textarea:focus{border-color:#F97316;box-shadow:0 0 0 3px rgba(249,115,22,.12);}

/* ── Chat play layout (single-page conversation) ───────────── */
.rk-chat-wrap{display:flex;flex-direction:column;min-height:72vh;}
.rk-chat-scroll{flex:1;overflow-y:auto;padding:14px 18px 8px;background:#F8F7FD;}
.rk-chat-turn{background:#fff;border:1px solid #EDE9FA;border-radius:16px;padding:12px;margin-bottom:12px;box-shadow:0 2px 10px rgba(127,119,221,.08);}
.rk-chat-teacher-row{display:flex;align-items:flex-start;gap:10px;}
.rk-chat-student-row{display:flex;align-items:flex-start;justify-content:flex-end;gap:10px;margin-top:10px;}
.rk-bubble-teacher{max-width:min(78%,540px);background:#EEEDFE;border:1px solid #DAD5FB;border-radius:14px 14px 14px 6px;padding:10px 12px;color:#3E3792;font-weight:800;line-height:1.5;}
.rk-bubble-student{max-width:min(78%,540px);background:#FFF0E6;border:1px solid #FCDDBF;border-radius:14px 14px 6px 14px;padding:10px 12px;color:#C2580A;font-weight:800;line-height:1.5;}
.rk-bubble-placeholder{max-width:min(78%,540px);border:2px dashed #DDD8F8;background:#FBFAFF;color:#9B94BE;font-style:italic;border-radius:14px 14px 6px 14px;padding:10px 12px;font-weight:800;}
.rk-turn-chip{display:inline-flex;align-items:center;gap:6px;padding:4px 10px;border-radius:999px;background:#EEF9F0;border:1px solid #BEE7C3;color:#166534;font-size:11px;font-weight:900;white-space:nowrap;}
.rk-turn-head{display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;gap:8px;flex-wrap:wrap;}
.rk-turn-label{font-size:12px;font-weight:900;color:#6B64B3;}
.rk-progress-dots{display:flex;align-items:center;gap:6px;}
.rk-dot{width:10px;height:10px;border-radius:50%;background:#E2DEFA;border:1px solid #D4CFF5;}
.rk-dot.is-done{background:#7F77DD;border-color:#7F77DD;}
.rk-dot.is-current{background:#F97316;border-color:#F97316;animation:rk-bounce .8s ease-in-out infinite;}
.rk-chat-hint{margin-top:6px;font-size:12px;color:#7F77DD;font-weight:800;}
.rk-input-sticky{position:sticky;bottom:0;z-index:5;background:#fff;border-top:1px solid #EDE9FA;padding:12px 18px;box-shadow:0 -8px 20px rgba(127,119,221,.08);}
.rk-input-card{background:#F7F6FD;border:1px solid #DDD8F8;border-radius:14px;padding:10px;}
.rk-input-row{display:flex;align-items:stretch;gap:8px;}
.rk-input-text{flex:1;min-height:58px;border:1.5px solid #D9D4F8;background:#fff;border-radius:12px;padding:10px 12px;font-family:'Nunito',sans-serif;font-size:16px;font-weight:800;color:#271B5D;resize:none;outline:none;}
.rk-send-btn{width:44px;min-width:44px;border-radius:12px;border:1.5px solid #D9D4F8;background:#fff;color:#7F77DD;font-size:20px;font-weight:900;cursor:pointer;}
.rk-send-btn:hover:not(:disabled){background:#EEEDFE;}
.rk-input-actions{display:flex;align-items:center;justify-content:center;gap:10px;margin-top:8px;flex-wrap:wrap;}
.rk-input-divider{font-size:12px;font-weight:900;color:#9B94BE;}
.rk-mic-inline{padding:10px 18px;border-radius:12px;border:1.5px solid #EDE9FA;background:#fff;color:#271B5D;font-size:14px;font-weight:900;cursor:pointer;}
.rk-mic-inline.is-recording{border-color:#E24B4A;background:#FEF2F2;color:#991B1B;animation:rk-pulse 1s ease-in-out infinite;}
.rk-top-score{display:inline-flex;align-items:center;gap:6px;padding:6px 12px;border-radius:999px;background:#F0FDF4;border:1px solid #BEE7C3;color:#166534;font-size:12px;font-weight:900;}

/* ── Bottom bar ─────────────────────────────────────────── */
.rk-bottombar{display:flex;justify-content:space-between;align-items:center;padding:12px 18px;margin-top:14px;border-top:1px solid #F0EEF8;}
.rk-status-text{font-size:13px;font-weight:700;color:#9B94BE;}

/* ── Feedback ───────────────────────────────────────────── */
.rk-feedback-wrap{margin-bottom:10px;}
.rk-feedback-block{border-radius:14px;padding:10px 14px;margin-bottom:8px;}
.rk-feedback-block.rk-fb-purple{background:#EEEDFE;border-left:3px solid #7F77DD;}
.rk-feedback-block.rk-fb-orange{background:#FFF0E6;border-left:3px solid #F97316;}
.rk-feedback-label{font-size:10px;font-weight:900;text-transform:uppercase;letter-spacing:.08em;margin-bottom:5px;}
.rk-feedback-label.purple{color:#534AB7;}
.rk-feedback-label.orange{color:#F97316;}
.rk-feedback-text{font-family:'Fredoka',sans-serif;font-size:15px;font-weight:600;color:#271B5D;line-height:1.45;}
.rk-feedback-text.orange{color:#C2580A;}
.rk-score-wrap{background:#EEEDFE;border-radius:14px;padding:10px 14px;margin-bottom:4px;}
.rk-score-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:7px;}
.rk-score-lbl{font-size:11px;font-weight:900;color:#9B94BE;text-transform:uppercase;}
.rk-score-pts{font-family:'Fredoka',sans-serif;font-size:20px;font-weight:700;color:#F97316;}
.rk-score-track{height:8px;background:#F4F2FD;border-radius:999px;overflow:hidden;}
.rk-score-fill{height:100%;background:linear-gradient(90deg,#F97316,#7F77DD);border-radius:999px;transition:width .5s;}

/* ── Avatar picker grid ─────────────────────────────────── */
.rk-pick-label{font-size:11px;font-weight:900;letter-spacing:.08em;text-transform:uppercase;color:#9B94BE;margin-bottom:14px;text-align:center;}
.rk-avatar-grid{display:grid;grid-template-columns:repeat(5,1fr);gap:10px;margin-bottom:22px;}
.rk-avatar-card{background:#fff;border:2px solid #EDE9FA;border-radius:18px;padding:12px 6px 10px;cursor:pointer;transition:all .18s cubic-bezier(.34,1.56,.64,1);text-align:center;position:relative;outline:none;font-family:'Nunito',sans-serif;}
.rk-avatar-card:hover{border-color:#7F77DD;transform:translateY(-3px);box-shadow:0 6px 18px rgba(127,119,221,.16);}
.rk-avatar-card.rk-selected{background:#FFF0E6;border-color:#F97316;box-shadow:0 0 0 3px rgba(249,115,22,.14);transform:scale(1.06) translateY(-2px);}
.rk-avatar-check{position:absolute;top:7px;right:7px;width:18px;height:18px;border-radius:50%;background:#F97316;display:flex;align-items:center;justify-content:center;opacity:0;transform:scale(0.4);transition:all .18s cubic-bezier(.34,1.56,.64,1);}
.rk-avatar-card.rk-selected .rk-avatar-check{opacity:1;transform:scale(1);}
.rk-avatar-img-wrap{width:54px;height:54px;border-radius:50%;margin:0 auto 8px;background:#EDE9FA;border:2.5px solid #EDE9FA;display:flex;align-items:center;justify-content:center;overflow:hidden;transition:border-color .18s;}
.rk-avatar-card.rk-selected .rk-avatar-img-wrap{border-color:#F97316;}
.rk-avatar-img-wrap img{width:100%;height:100%;object-fit:cover;border-radius:50%;}
.rk-avatar-lbl{font-size:11px;font-weight:800;color:#9B94BE;letter-spacing:.02em;transition:color .18s;}
.rk-avatar-card.rk-selected .rk-avatar-lbl{color:#C2580A;}
.rk-avatar-start{text-align:center;display:flex;flex-direction:column;align-items:center;gap:10px;}
.rk-avatar-preview{display:flex;align-items:center;gap:8px;background:#F5F3FF;border-radius:999px;padding:7px 16px;font-size:13px;font-weight:800;color:#534AB7;min-height:36px;transition:all .2s;}
.rk-avatar-preview.empty{color:#9B94BE;background:#F9F8FF;}
.rk-avatar-preview-dot{width:10px;height:10px;border-radius:50%;background:#7F77DD;flex-shrink:0;}
.rk-kicker-badge{display:inline-flex;align-items:center;gap:6px;background:#fff;border:1.5px solid #EDE9FA;border-radius:999px;padding:5px 14px;font-size:12px;font-weight:800;color:#7F77DD;margin-top:10px;}


/* ── Responsive ─────────────────────────────────────────── */
@media(max-width:640px){
  .rk-shell{padding:10px;}
  .rk-board{border-radius:22px;}
  .rk-topbar,.rk-scene-bar,.rk-progress-row,.rk-content,.rk-bottombar{padding-left:14px;padding-right:14px;}
  .rk-scene-bar-right{display:none;}
  .rk-title{font-size:clamp(26px,8vw,36px);}
}

/* ── Embedded / presentation modes ─────────────────────── */
body.embedded-mode .rk-shell,body.fullscreen-embedded .rk-shell,body.presentation-mode .rk-shell{
  position:absolute!important;inset:0!important;max-width:none!important;margin:0!important;
  padding:8px 12px!important;flex-direction:column!important;align-items:center!important;overflow-y:auto!important;
}
body.embedded-mode .rk-app,body.fullscreen-embedded .rk-app,body.presentation-mode .rk-app{
  width:min(680px,100%)!important;margin:0 auto!important;
}
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

  const [phase, setPhase] = useState("avatar");
  const [avatarId, setAvatarId] = useState(null);
  const [turnIndex, setTurnIndex] = useState(0);
  const [written, setWritten] = useState("");
  const [micState, setMicState] = useState("idle");
  const [turnResults, setTurnResults] = useState([]);
  const [totalPts, setTotalPts] = useState(0);
  const [turnScores, setTurnScores] = useState([]);
  const [reviewItems, setReviewItems] = useState([]);
  const [ttsPlaying, setTtsPlaying] = useState(false);

  const completedRef = useRef(null);
  const chatScrollRef = useRef(null);

  const total = safeT.length;
  const maxPts = total * 10;
  const avatarLabel = AVATARS.find(a => a.id === avatarId)?.label || "You";
  const currentTurn = safeT[turnIndex] || { teacherLine: "", studentLine: "" };
  const voiceId = scene.voiceId || VOICES[0].id;
  const turnsVisible = Math.min(turnIndex + 1, total);
  const completedTurns = turnResults.length;
  const progressPct = total > 0 ? Math.round((completedTurns / total) * 100) : 0;

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

  function stopTTS() {
    if (currentAudioRef) { currentAudioRef.pause(); currentAudioRef = null; }
    setTtsPlaying(false);
  }

  function replayTeacher(turnObj) {
    if (!turnObj || !turnObj.teacherLine) return;
    setTtsPlaying(true);
    playElevenLabs(turnObj.teacherLine, voiceId,
      () => setTtsPlaying(false),
      () => setTtsPlaying(false));
  }

  function pushAnswer(answerText, source) {
    const turn = safeT[turnIndex] || { teacherLine: "", studentLine: "" };
    const said = normalizeStr(answerText);
    const exp = normalizeStr(turn.studentLine);
    const overlap = wordOverlapScore(said, exp);
    const score10 = Math.max(0, Math.min(10, Math.round(overlap * 10)));
    const pass = score10 >= 5 ? 1 : 0;

    setTurnResults(prev => [...prev, {
      turn: turnIndex,
      answer: answerText,
      expected: turn.studentLine || "",
      score10,
      source,
    }]);
    setTurnScores(prev => [...prev, pass]);
    setReviewItems(prev => [...prev, {
      question: turn.teacherLine || ("Turn " + (turnIndex + 1)),
      yourAnswer: answerText || "(empty)",
      correctAnswer: turn.studentLine || "",
      score: pass,
    }]);
    setTotalPts(prev => prev + score10);
    setWritten("");
    setMicState("idle");

    if (turnIndex < total - 1) {
      setTurnIndex(prev => prev + 1);
    } else {
      setPhase("done");
    }
  }

  function submitTyped() {
    if (!written.trim() || phase !== "playing") return;
    pushAnswer(written.trim(), "typed");
  }

  function toggleMicSimulation() {
    if (phase !== "playing") return;
    if (micState === "recording") {
      const simulated = (safeT[turnIndex]?.studentLine || "I am okay, thank you.").trim();
      pushAnswer(simulated, "mic");
      return;
    }
    setMicState("recording");
  }

  function handleRestart() {
    stopTTS();
    setPhase("avatar");
    setAvatarId(null);
    setTurnIndex(0);
    setWritten("");
    setMicState("idle");
    setTurnResults([]);
    setTotalPts(0);
    setTurnScores([]);
    setReviewItems([]);
  }

  function startPlaying() {
    setPhase("playing");
    setTurnIndex(0);
  }

  useEffect(() => {
    if (phase !== "playing") return;
    replayTeacher(currentTurn);
  }, [phase, turnIndex]);

  useEffect(() => {
    if (!chatScrollRef.current || phase !== "playing") return;
    chatScrollRef.current.scrollTop = chatScrollRef.current.scrollHeight;
  }, [phase, turnIndex, turnResults.length]);

  useEffect(() => {
    if (phase !== "done" || !completedRef.current) return;
    const AF = window.ActivityFeedback;
    if (!AF) return;
    const winAudio = new Audio("../../hangman/assets/win.mp3");
    const returnTo = window.RK_RETURN_TO || "";
    const actId = window.RK_ACTIVITY_ID || "";
    const snapScores = turnScores.slice();
    AF.showCompleted({
      target: completedRef.current,
      scores: snapScores,
      title: scene.title || "Roleplay Kids",
      activityType: "Roleplay (Kids)",
      questionCount: total,
      winAudio: winAudio,
      onRetry: handleRestart,
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

  if (phase === "avatar") return (
    <div className="rk-shell">
      <div className="rk-app">
        <div className="rk-hero">
          <div className="rk-kicker">Activity</div>
          <h1 className="rk-title">{scene.title || "Roleplay"}</h1>
          <p className="rk-subtitle">Choose your character to get started!</p>
          <div className="rk-kicker-badge">
            <span style={{width:8,height:8,borderRadius:"50%",background:"linear-gradient(135deg,#F97316,#7F77DD)",display:"inline-block",flexShrink:0}}></span>
            {(scene.turns||[]).length} turns
          </div>
        </div>
        <div className="rk-board rk-board-padded">
          <div className="rk-pick-label">Who are you today?</div>
          <div className="rk-avatar-grid">
            {AVATARS.map(av => (
              <button
                key={av.id}
                className={`rk-avatar-card${avatarId === av.id ? " rk-selected" : ""}`}
                onClick={() => setAvatarId(av.id)}
              >
                <div className="rk-avatar-check">
                  <svg width="10" height="8" viewBox="0 0 10 8" fill="none" stroke="#fff" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round"><polyline points="1,4 4,7 9,1"/></svg>
                </div>
                <div className="rk-avatar-img-wrap">
                  <AvatarImg id={av.id} size={54} />
                </div>
                <div className="rk-avatar-lbl">{av.label}</div>
              </button>
            ))}
          </div>
          <div style={{height:1,background:"#F0EEF8",margin:"0 0 20px"}}></div>
          <div className="rk-avatar-start">
            <div className={`rk-avatar-preview${avatarId ? "" : " empty"}`}>
              {avatarId
                ? <><span className="rk-avatar-preview-dot"></span><span>Playing as <strong>{AVATARS.find(a=>a.id===avatarId)?.label}</strong></span></>
                : <span>No character selected</span>
              }
            </div>
            <button
              className="rk-btn rk-btn-orange"
              onClick={startPlaying}
              disabled={!avatarId}
              style={{ minWidth: 180, padding: "13px 32px", fontSize: 15 }}
            >Start Roleplay ✦</button>
            <span style={{fontSize:11,fontWeight:700,color:"#9B94BE",letterSpacing:".04em"}}>Tap a character above to begin</span>
          </div>
        </div>
      </div>
    </div>
  );

  // DONE — AF.showCompleted populates this div via useEffect
  // ════════════════════════════════════════════════════════════
  if (phase === "done") return (
    <div className="rk-shell">
      <div className="rk-app">
        <div ref={completedRef} />
      </div>
    </div>
  );

  const voiceLabelShort = (VOICES.find(v => v.id === voiceId)?.label || "Teacher")
    .split("(")[0].trim();

  return (
    <div className="rk-shell">
      <div className="rk-app">
        <div className="rk-board rk-chat-wrap">
          <div className="rk-topbar">
            <button className="rk-back-btn" onClick={handleRestart}>◁ Back</button>
            <span className="rk-scene-title">{scene.title || "Roleplay"}</span>
            <div className="rk-topbar-right">
              <div className="rk-voice-chip">{voiceLabelShort}</div>
              <div className="rk-top-score">{totalPts} / {maxPts} pts</div>
            </div>
          </div>

          <div className="rk-scene-bar">
            <span className="rk-scene-bar-left">▣ {scene.desc || "Practice speaking English!"}</span>
            <span className="rk-scene-bar-right">{scene.agentName || "Teacher"} vs {avatarLabel}</span>
          </div>

          <div className="rk-progress-row">
            <span className="rk-progress-counter">{Math.min(completedTurns + 1, total)} / {total}</span>
            <div className="rk-progress-track">
              <div className="rk-progress-fill" style={{ width: `${progressPct}%` }} />
            </div>
            <div className="rk-progress-dots">
              {safeT.map((_, idx) => (
                <span
                  key={idx}
                  className={`rk-dot${idx < completedTurns ? " is-done" : ""}${idx === turnIndex ? " is-current" : ""}`}
                />
              ))}
            </div>
          </div>

          <div className="rk-chat-scroll" ref={chatScrollRef}>
            {safeT.slice(0, turnsVisible).map((t, idx) => {
              const result = turnResults[idx] || null;
              const teacherInitial = (scene.agentName || "T")[0].toUpperCase();
              const studentInitial = (avatarLabel || "Y")[0].toUpperCase();
              return (
                <div key={idx} className="rk-chat-turn">
                  <div className="rk-turn-head">
                    <div className="rk-turn-label">Turn {idx + 1} of {total}</div>
                    {result && <div className="rk-turn-chip">+{result.score10} pts</div>}
                  </div>

                  <div className="rk-chat-teacher-row">
                    <div className="rk-avatar rk-avatar-purple" style={{ width: 36, height: 36, fontSize: 16 }}>{teacherInitial}</div>
                    <div className="rk-bubble-teacher">{t.teacherLine || "..."}</div>
                    {idx === turnIndex && (
                      <button
                        className={`rk-tts-btn${ttsPlaying ? " is-playing" : ""}`}
                        onClick={ttsPlaying ? stopTTS : () => replayTeacher(t)}
                        style={{ alignSelf: "center" }}
                      >
                        {ttsPlaying ? "■" : "▶"}
                      </button>
                    )}
                  </div>

                  <div className="rk-chat-hint">Hint: {t.studentLine || "Try answering naturally."}</div>

                  <div className="rk-chat-student-row">
                    {result ? (
                      <div className="rk-bubble-student">{result.answer}</div>
                    ) : (
                      <div className="rk-bubble-placeholder">Your answer goes here...</div>
                    )}
                    <div className="rk-avatar rk-avatar-orange" style={{ width: 36, height: 36, fontSize: 16 }}>{studentInitial}</div>
                  </div>
                </div>
              );
            })}
          </div>

          <div className="rk-input-sticky">
            <div className="rk-input-card">
              <div style={{ fontSize: 13, fontWeight: 900, color: C.orange, marginBottom: 8 }}>
                Your turn: {avatarLabel} - Turn {turnIndex + 1}
              </div>

              <div className="rk-input-row">
                <textarea
                  className="rk-input-text"
                  value={written}
                  onChange={e => setWritten(e.target.value)}
                  placeholder="Write your answer first, then say it..."
                  rows={2}
                  onKeyDown={e => {
                    if (e.key === "Enter" && !e.shiftKey) {
                      e.preventDefault();
                      submitTyped();
                    }
                  }}
                />
                <button className="rk-send-btn" onClick={submitTyped} disabled={!written.trim()}>↑</button>
              </div>

              <div className="rk-input-actions">
                <span className="rk-input-divider">- or speak directly -</span>
                <button
                  className={`rk-mic-inline${micState === "recording" ? " is-recording" : ""}`}
                  onClick={toggleMicSimulation}
                >
                  {micState === "recording" ? "Stop recording" : "Tap to speak your answer"}
                </button>
              </div>
            </div>

            <div style={{ display: "flex", justifyContent: "space-between", alignItems: "center", marginTop: 8 }}>
              <span className="rk-status-text">Score: {totalPts} / {maxPts} pts</span>
              <span className="rk-turn-chip">{completedTurns} completed</span>
            </div>
          </div>
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
