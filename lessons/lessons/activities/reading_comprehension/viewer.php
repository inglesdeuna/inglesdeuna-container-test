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

function activities_columns(PDO $pdo): array
{
    static $cache = null;
    if (is_array($cache)) {
        return $cache;
    }
    try {
        $stmt = $pdo->prepare("
            SELECT column_name
            FROM information_schema.columns
            WHERE table_schema = 'public'
              AND table_name = 'activities'
        ");
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
        $cache = array_map(static fn($col) => strtolower((string) $col), $rows);
        return $cache;
    } catch (Throwable $e) {
        $cache = [];
        return $cache;
    }
}

function activities_has_column(PDO $pdo, string $columnName): bool
{
    return in_array(strtolower($columnName), activities_columns($pdo), true);
}

function save_reading_activity(PDO $pdo, string $unit, string $activityId, string $contentJson): string
{
    $columns = activities_columns($pdo);
    $hasUnitId = in_array('unit_id', $columns, true);
    $hasUnit = in_array('unit', $columns, true);
    $hasData = in_array('data', $columns, true);
    $hasContentJson = in_array('content_json', $columns, true);
    $hasTitle = in_array('title', $columns, true);
    $hasName = in_array('name', $columns, true);
    $hasId = in_array('id', $columns, true);

    $decoded = json_decode($contentJson, true);
    $payloadTitle = '';
    if (is_array($decoded) && isset($decoded['title'])) {
        $payloadTitle = trim((string) $decoded['title']);
    }
    if ($payloadTitle === '') {
        $payloadTitle = 'Reading Comprehension';
    }

    $targetId = trim($activityId);
    if ($targetId === '' && $unit !== '') {
        if ($hasUnitId) {
            $stmt = $pdo->prepare("SELECT id FROM activities WHERE unit_id = :unit AND type = 'reading_comprehension' ORDER BY id ASC LIMIT 1");
            $stmt->execute(['unit' => $unit]);
            $targetId = trim((string) $stmt->fetchColumn());
        }
        if ($targetId === '' && $hasUnit) {
            $stmt = $pdo->prepare("SELECT id FROM activities WHERE unit = :unit AND type = 'reading_comprehension' ORDER BY id ASC LIMIT 1");
            $stmt->execute(['unit' => $unit]);
            $targetId = trim((string) $stmt->fetchColumn());
        }
    }

    if ($targetId !== '') {
        $setParts = [];
        $params = ['id' => $targetId];
        if ($hasData) {
            $setParts[] = 'data = :data';
            $params['data'] = $contentJson;
        }
        if ($hasContentJson) {
            $setParts[] = 'content_json = :content_json';
            $params['content_json'] = $contentJson;
        }
        if ($hasTitle) {
            $setParts[] = 'title = :title';
            $params['title'] = $payloadTitle;
        }
        if ($hasName) {
            $setParts[] = 'name = :name';
            $params['name'] = $payloadTitle;
        }
        if (!empty($setParts)) {
            $stmt = $pdo->prepare("UPDATE activities SET " . implode(', ', $setParts) . " WHERE id = :id AND type = 'reading_comprehension'");
            $stmt->execute($params);
        }
        return $targetId;
    }

    if ((!$hasUnitId && !$hasUnit) || ($unit === '')) {
        throw new RuntimeException('Missing unit to create activity');
    }
    if (!$hasData && !$hasContentJson) {
        throw new RuntimeException('Activities table does not support data storage columns');
    }

    $insertCols = [];
    $insertVals = [];
    $params = [];
    $newId = '';

    if ($hasId) {
        $newId = md5(random_bytes(16));
        $insertCols[] = 'id';
        $insertVals[] = ':id';
        $params['id'] = $newId;
    }
    if ($hasUnitId) {
        $insertCols[] = 'unit_id';
        $insertVals[] = ':unit';
        $params['unit'] = $unit;
    } elseif ($hasUnit) {
        $insertCols[] = 'unit';
        $insertVals[] = ':unit';
        $params['unit'] = $unit;
    }
    $insertCols[] = 'type';
    $insertVals[] = "'reading_comprehension'";
    if ($hasData) {
        $insertCols[] = 'data';
        $insertVals[] = ':data';
        $params['data'] = $contentJson;
    }
    if ($hasContentJson) {
        $insertCols[] = 'content_json';
        $insertVals[] = ':content_json';
        $params['content_json'] = $contentJson;
    }
    if ($hasTitle) {
        $insertCols[] = 'title';
        $insertVals[] = ':title';
        $params['title'] = $payloadTitle;
    }
    if ($hasName) {
        $insertCols[] = 'name';
        $insertVals[] = ':name';
        $params['name'] = $payloadTitle;
    }

    $stmt = $pdo->prepare("INSERT INTO activities (" . implode(', ', $insertCols) . ") VALUES (" . implode(', ', $insertVals) . ")");
    $stmt->execute($params);
    return $newId;
}

$action = isset($_GET['action']) ? trim((string) $_GET['action']) : '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'save') {
    header('Content-Type: application/json');
    if (!isset($_SESSION['academic_id']) && !isset($_SESSION['admin_id'])) {
        http_response_code(403);
        echo json_encode(['status' => 'error', 'message' => 'Access denied']);
        exit;
    }
    try {
        $postedUnit = isset($_POST['unit']) ? trim((string) $_POST['unit']) : '';
        $postedActivityId = isset($_POST['id']) ? trim((string) $_POST['id']) : '';
        $postedContent = isset($_POST['content_json']) ? (string) $_POST['content_json'] : '{}';
        if ($postedUnit === '' && $postedActivityId === '') {
            throw new RuntimeException('Missing unit or activity id');
        }
        $savedId = save_reading_activity($pdo, $postedUnit, $postedActivityId, $postedContent);
        echo json_encode(['status' => 'success', 'activity_id' => $savedId]);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}

$columns = activities_columns($pdo);
$selectFields = [];
if (in_array('data', $columns, true)) {
    $selectFields[] = 'data';
}
if (in_array('content_json', $columns, true)) {
    $selectFields[] = 'content_json';
}
if (in_array('title', $columns, true)) {
    $selectFields[] = 'title';
} elseif (in_array('name', $columns, true)) {
    $selectFields[] = 'name AS title';
} else {
    $selectFields[] = "'' AS title";
}

if (empty($selectFields)) {
    $selectFields[] = "'' AS title";
}

if ($activityId !== '') {
    $stmt = $pdo->prepare("SELECT " . implode(', ', $selectFields) . " FROM activities WHERE id = ? AND type = 'reading_comprehension' LIMIT 1");
    $stmt->execute([$activityId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $rawData = isset($row['data']) ? $row['data'] : ($row['content_json'] ?? '');
        $savedData = json_decode((string) $rawData, true) ?? [];
        $savedTitle = trim((string) ($row['title'] ?? '')) !== '' ? (string) $row['title'] : $savedTitle;
    }
}
if (empty($savedData) && $unitId !== '') {
    if (in_array('unit_id', $columns, true)) {
        $stmt = $pdo->prepare("SELECT " . implode(', ', $selectFields) . " FROM activities WHERE unit_id = ? AND type = 'reading_comprehension' ORDER BY id ASC LIMIT 1");
        $stmt->execute([$unitId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $rawData = isset($row['data']) ? $row['data'] : ($row['content_json'] ?? '');
            $savedData = json_decode((string) $rawData, true) ?? [];
            $savedTitle = trim((string) ($row['title'] ?? '')) !== '' ? (string) $row['title'] : $savedTitle;
        }
    }
    if (empty($savedData) && in_array('unit', $columns, true)) {
        $stmt = $pdo->prepare("SELECT " . implode(', ', $selectFields) . " FROM activities WHERE unit = ? AND type = 'reading_comprehension' ORDER BY id ASC LIMIT 1");
        $stmt->execute([$unitId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $rawData = isset($row['data']) ? $row['data'] : ($row['content_json'] ?? '');
            $savedData = json_decode((string) $rawData, true) ?? [];
            $savedTitle = trim((string) ($row['title'] ?? '')) !== '' ? (string) $row['title'] : $savedTitle;
        }
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

function rcBuildSaveUrl(percent, errors, total) {
  const returnTo = String(window.RC_RETURN_TO || '').trim();
  const activityId = String(window.RC_ACTIVITY_ID || '').trim();
  if (!returnTo || !activityId) return '';
  const joiner = returnTo.indexOf('?') !== -1 ? '&' : '?';
  return returnTo
    + joiner + 'activity_percent=' + encodeURIComponent(String(percent))
    + '&activity_errors=' + encodeURIComponent(String(errors))
    + '&activity_total=' + encodeURIComponent(String(total))
    + '&activity_id=' + encodeURIComponent(String(activityId))
    + '&activity_type=reading_comprehension';
}

function rcPersistScore(url) {
  if (!url) return Promise.resolve(false);
  return fetch(url, { method: 'GET', credentials: 'same-origin', cache: 'no-store' })
    .then(function(r) { return !!(r && r.ok); })
    .catch(function() { return false; });
}
</script>

<script type="text/babel">
const { useMemo, useState } = React;

const C = {
  orange: '#F97316',
  orangeSoft: '#FFF0E6',
  orangeBorder: '#FCDDBF',
  orangeDark: '#C2580A',
  purple: '#7F77DD',
  purpleDark: '#5A50C8',
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

const baseButtonStyle = {
  display: 'inline-flex',
  alignItems: 'center',
  justifyContent: 'center',
  gap: 6,
  border: 'none',
  borderRadius: 999,
  padding: '11px 22px',
  minWidth: 120,
  fontFamily: 'Nunito, sans-serif',
  fontSize: 14,
  fontWeight: 900,
  lineHeight: 1,
  textDecoration: 'none',
  transition: 'filter .15s ease, transform .15s ease',
};

function buttonStyle(variant = 'secondary', disabled = false) {
  const variants = {
    primary: {
      background: C.orange,
      color: C.white,
      boxShadow: '0 8px 20px rgba(249,115,22,.22)',
    },
    accent: {
      background: C.purple,
      color: C.white,
      boxShadow: '0 8px 20px rgba(127,119,221,.18)',
    },
    secondary: {
      background: C.white,
      color: C.purpleDark,
      border: `1.5px solid ${C.purpleBorder}`,
      boxShadow: '0 6px 18px rgba(127,119,221,.08)',
    },
    subtle: {
      background: '#FBFAFF',
      color: C.ink,
      border: `1.5px solid ${C.purpleBorder}`,
      boxShadow: 'none',
    },
    danger: {
      background: C.white,
      color: C.red,
      border: `1px solid ${C.red}`,
      boxShadow: 'none',
    },
  };

  return {
    ...baseButtonStyle,
    ...(variants[variant] || variants.secondary),
    opacity: disabled ? 0.45 : 1,
    cursor: disabled ? 'not-allowed' : 'pointer',
  };
}

function optionButtonStyle({ border, bg, color, disabled = false }) {
  return {
    ...baseButtonStyle,
    width: '100%',
    minWidth: 0,
    justifyContent: 'flex-start',
    textAlign: 'left',
    whiteSpace: 'normal',
    padding: '12px 16px',
    borderRadius: 16,
    border: `1.5px solid ${border}`,
    background: bg,
    color,
    boxShadow: 'none',
    cursor: disabled ? 'default' : 'pointer',
  };
}

const editorInputStyle = {
  width: '100%',
  padding: '11px 13px',
  borderRadius: 12,
  border: `1px solid ${C.purpleBorder}`,
  fontFamily: 'Nunito, sans-serif',
  fontSize: 14,
  fontWeight: 700,
  color: C.ink,
  background: C.white,
};

const editorSectionStyle = {
  background: C.white,
  border: `1.5px solid ${C.purpleBorder}`,
  borderRadius: 24,
  overflow: 'hidden',
  marginBottom: 18,
  boxShadow: '0 10px 28px rgba(127,119,221,.08)',
};

const editorSectionHeaderStyle = {
  padding: '16px 20px',
  borderBottom: `1px solid ${C.border}`,
  display: 'flex',
  alignItems: 'center',
  gap: 12,
};

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
    <div className="rc-topbar">
      <button
        onClick={() => {
          const backTo = String(window.RC_RETURN_TO || '').trim();
          if (backTo) window.location.href = backTo;
          else window.history.back();
        }}
        style={{ ...buttonStyle('secondary'), width: 42, height: 42, minWidth: 42, padding: 0, boxShadow: 'none' }}
      >←</button>

      <div style={{ textAlign: 'center' }}>
        <div style={{ color: C.purpleMid, fontSize: 11, fontWeight: 900, letterSpacing: '.08em', textTransform: 'uppercase' }}>Activity</div>
        <div style={{ color: C.orange, fontFamily: 'Fredoka, sans-serif', fontSize: 'clamp(24px, 3vw, 32px)', lineHeight: 1.05 }}>Reading Comprehension</div>
      </div>

      <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
        <div style={{ width: 110, height: 8, borderRadius: 999, background: C.purpleBorder, overflow: 'hidden' }}>
          <div style={{ width: `${total ? (done / total) * 100 : 0}%`, height: '100%', borderRadius: 999, background: 'linear-gradient(90deg,#F97316,#7F77DD)' }} />
        </div>
        <div style={{ minWidth: 58, textAlign: 'center', background: C.purpleSoft, color: C.purpleDark, borderRadius: 999, padding: '6px 10px', fontFamily: 'Nunito, sans-serif', fontWeight: 900, fontSize: 12 }}>{done} / {total}</div>
      </div>
    </div>
  );
}

function PassagePane({ text }) {
  const paragraphs = String(text.body || '').split(/\n\s*\n/).map((p) => p.trim()).filter(Boolean);
  return (
    <div className="rc-passage-pane">
      <div style={{ background: C.orangeSoft, border: `1px solid ${C.orangeBorder}`, borderRadius: 999, color: C.orangeDark, fontSize: 11, fontWeight: 900, textTransform: 'uppercase', letterSpacing: '.08em', padding: '4px 14px', width: 'fit-content' }}>Reading passage</div>
      <h2 style={{ margin: '14px 0 8px', fontFamily: 'Fredoka, sans-serif', fontSize: 'clamp(26px, 3vw, 34px)', color: C.orange, lineHeight: 1.05 }}>{text.title || 'Untitled passage'}</h2>
      <div style={{ fontFamily: 'Nunito, sans-serif', fontSize: 12, color: C.purpleMid, fontWeight: 800 }}>{text.genre || 'Informative text'} · {text.wordCount || countWords(text.body)} words · Read carefully</div>
      <div style={{ height: 1.5, background: C.border, margin: '14px 0' }} />
      <div style={{ color: C.ink, fontFamily: 'Nunito, sans-serif', fontSize: 15, lineHeight: 1.75 }}>
        {(paragraphs.length ? paragraphs : ['No passage text available.']).map((p, idx) => (
          <p key={`p_${idx}`} style={{ margin: '0 0 14px' }} dangerouslySetInnerHTML={{ __html: paragraphToHtml(p, text.words) }} />
        ))}
      </div>
    </div>
  );
}

function VocabQuestions({ text, onDone }) {
  const deck = useMemo(() => buildVocabDeck(text.words), [text.words]);
  const [index, setIndex] = useState(0);
  const [answers, setAnswers] = useState(() => deck.map(() => ({ selected: -1, checked: false, correct: false })));

  const row = answers[index] || { selected: -1, checked: false, correct: false };
  const item = deck[index] || null;
  const done = answers.filter((a) => a.checked).length;

  if (!item) {
    return <div style={{ padding: 16, color: C.purpleMid, fontWeight: 700 }}>Add highlighted words with meanings to generate questions.</div>;
  }

  const isLast = index >= deck.length - 1;

  return (
    <div className="rc-stage-shell">
      <TopBar done={done} total={deck.length} />
      <div className="rc-stage-layout">
        <div style={{ minWidth: 0 }}><PassagePane text={text} /></div>
        <div className="rc-question-pane">
          <div className="rc-question-card">
            <div style={{ color: C.purpleMid, fontSize: 11, fontWeight: 900, textTransform: 'uppercase', marginBottom: 8 }}>Question {index + 1} of {deck.length}</div>
            <h3 style={{ margin: 0, color: C.ink, fontFamily: 'Fredoka, sans-serif', fontSize: 'clamp(22px, 2.2vw, 28px)', lineHeight: 1.15 }}>What does <span style={{ color: C.orange }}>{item.word}</span> mean?</h3>

            <div style={{ marginTop: 16, display: 'flex', flexDirection: 'column', gap: 10 }}>
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
                  }} style={optionButtonStyle({ border, bg, color, disabled: row.checked })}>
                    {opt.text}
                  </button>
                );
              })}
            </div>

            <div className="rc-actions">
              <button onClick={() => setIndex((p) => Math.max(0, p - 1))} disabled={index === 0} style={buttonStyle('secondary', index === 0)}>← Previous</button>
              {!row.checked ? (
                <button
                  onClick={() => {
                    if (row.selected < 0) return;
                    const isCorrect = !!item.options[row.selected]?.correct;
                    setAnswers((prev) => prev.map((a, i) => i === index ? { ...a, checked: true, correct: isCorrect } : a));
                  }}
                  disabled={row.selected < 0}
                  style={buttonStyle('accent', row.selected < 0)}
                >Check answer</button>
              ) : (
                <button
                  onClick={() => {
                    if (isLast) {
                      if (typeof onDone === 'function') {
                        const correct = answers.filter((a) => a.correct).length;
                        const wrong = answers.filter((a) => a.checked && !a.correct).length;
                        const total = deck.length;
                        const scorable = correct + wrong;
                        onDone({ correct, wrong, total, percent: scorable > 0 ? Math.round((correct / scorable) * 100) : 0 });
                      }
                    } else {
                      setIndex((p) => p + 1);
                    }
                  }}
                  style={buttonStyle('primary')}
                >{isLast ? 'Finish ✓' : 'Next →'}</button>
              )}
            </div>

            {row.checked && (
              <div style={{ marginTop: 14, background: row.correct ? C.greenSoft : C.redSoft, color: row.correct ? C.greenDark : C.redDark, borderLeft: `4px solid ${row.correct ? C.green : C.red}`, borderRadius: 14, padding: '12px 14px', fontWeight: 700 }}>
                {row.correct ? 'Correct!' : 'Try again on the next round.'}
              </div>
            )}
          </div>
        </div>
      </div>
    </div>
  );
}

function CompQuestions({ text, onDone }) {
  const questions = useMemo(() => (text.questions || []).filter((q) => q.options.some((o) => String(o || '').trim())), [text.questions]);
  const [index, setIndex] = useState(0);
  const [answers, setAnswers] = useState(() => questions.map(() => ({ selected: -1, checked: false, correct: false })));
  const done = answers.filter((a) => a.checked).length;
  const q = questions[index] || null;
  const row = answers[index] || { selected: -1, checked: false, correct: false };

  if (!q) {
    return <div style={{ padding: 16, color: C.purpleMid, fontWeight: 700 }}>Add comprehension questions to preview this mode.</div>;
  }

  const isLast = index >= questions.length - 1;

  return (
    <div className="rc-stage-shell">
      <TopBar done={done} total={questions.length} />
      <div className="rc-stage-layout">
        <div style={{ minWidth: 0 }}><PassagePane text={text} /></div>
        <div className="rc-question-pane">
          <div className="rc-question-card">
            <div style={{ color: C.purpleMid, fontSize: 11, fontWeight: 900, textTransform: 'uppercase', marginBottom: 8 }}>Question {index + 1} of {questions.length}</div>
            <h3 style={{ margin: 0, color: C.ink, fontFamily: 'Fredoka, sans-serif', fontSize: 'clamp(22px, 2.2vw, 28px)', lineHeight: 1.15 }}>{q.stem || `Question ${index + 1}`}</h3>

            <div style={{ marginTop: 16, display: 'flex', flexDirection: 'column', gap: 10 }}>
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
                  }} style={{ ...optionButtonStyle({ border, bg, color, disabled: row.checked }), gap: 10 }}>
                    <span style={{ width: 20, height: 20, borderRadius: 6, background: selected ? C.purple : C.purpleBorder, color: selected ? C.white : C.purple, fontSize: 10, fontWeight: 900, display: 'grid', placeItems: 'center' }}>{LETTERS[optIdx]}</span>
                    <span>{opt}</span>
                  </button>
                );
              })}
            </div>

            <div className="rc-actions">
              <button onClick={() => setIndex((p) => Math.max(0, p - 1))} disabled={index === 0} style={buttonStyle('secondary', index === 0)}>← Previous</button>
              {!row.checked ? (
                <button onClick={() => {
                  if (row.selected < 0) return;
                  setAnswers((prev) => prev.map((a, i) => i === index ? { ...a, checked: true, correct: row.selected === q.correct } : a));
                }} disabled={row.selected < 0} style={buttonStyle('accent', row.selected < 0)}>Check answer</button>
              ) : (
                <button
                  onClick={() => {
                    if (isLast) {
                      if (typeof onDone === 'function') {
                        const correct = answers.filter((a) => a.correct).length;
                        const wrong = answers.filter((a) => a.checked && !a.correct).length;
                        const total = questions.length;
                        const scorable = correct + wrong;
                        onDone({ correct, wrong, total, percent: scorable > 0 ? Math.round((correct / scorable) * 100) : 0 });
                      }
                    } else {
                      setIndex((p) => p + 1);
                    }
                  }}
                  style={buttonStyle('primary')}
                >{isLast ? 'Finish ✓' : 'Next →'}</button>
              )}
            </div>

            {row.checked && (
              <div style={{ marginTop: 14, background: row.correct ? C.greenSoft : C.orangeSoft, color: row.correct ? C.greenDark : C.orangeDark, borderLeft: `4px solid ${row.correct ? C.green : C.orange}`, borderRadius: 14, padding: '12px 14px', fontWeight: 700 }}>
                {q.feedback || (row.correct ? 'Correct answer!' : `Correct answer: ${LETTERS[q.correct]}`)}
              </div>
            )}
          </div>
        </div>
      </div>
    </div>
  );
}

function CompletedScreen({ score, title }) {
  const pct = score.percent;
  const emoji = pct >= 80 ? '🎉' : pct >= 50 ? '👍' : '💪';
  const msg = pct >= 80 ? 'Excellent work!' : pct >= 50 ? 'Good effort!' : 'Keep practicing!';
  return (
    <div className="rc-page">
      <div className="rc-app">
        <div style={{ maxWidth: 560, margin: '0 auto', textAlign: 'center', padding: 'clamp(24px,4vw,48px) 16px' }}>
          <div style={{ fontSize: 56, lineHeight: 1, marginBottom: 16 }}>{emoji}</div>
          <div style={{ fontFamily: 'Fredoka, sans-serif', fontSize: 'clamp(28px,4vw,40px)', color: C.orange, lineHeight: 1.05, marginBottom: 8 }}>{title || 'Reading Comprehension'}</div>
          <div style={{ color: C.purpleMid, fontWeight: 800, fontSize: 14, marginBottom: 28 }}>Activity completed · {msg}</div>

          <div style={{ display: 'grid', gridTemplateColumns: 'repeat(3,1fr)', gap: 12, marginBottom: 28 }}>
            <div style={{ background: C.greenSoft, border: `1px solid ${C.green}`, borderRadius: 16, padding: '14px 8px', textAlign: 'center' }}>
              <div style={{ fontFamily: 'Fredoka, sans-serif', fontSize: 28, color: C.green, lineHeight: 1 }}>{score.correct}</div>
              <div style={{ fontSize: 10, fontWeight: 900, color: C.greenDark, textTransform: 'uppercase', letterSpacing: '.06em', marginTop: 4 }}>Correct</div>
            </div>
            <div style={{ background: C.redSoft, border: `1px solid ${C.red}`, borderRadius: 16, padding: '14px 8px', textAlign: 'center' }}>
              <div style={{ fontFamily: 'Fredoka, sans-serif', fontSize: 28, color: C.red, lineHeight: 1 }}>{score.wrong}</div>
              <div style={{ fontSize: 10, fontWeight: 900, color: C.redDark, textTransform: 'uppercase', letterSpacing: '.06em', marginTop: 4 }}>Wrong</div>
            </div>
            <div style={{ background: C.purpleSoft, border: `1px solid ${C.purpleBorder}`, borderRadius: 16, padding: '14px 8px', textAlign: 'center' }}>
              <div style={{ fontFamily: 'Fredoka, sans-serif', fontSize: 28, color: C.purple, lineHeight: 1 }}>{pct}%</div>
              <div style={{ fontSize: 10, fontWeight: 900, color: C.purpleDark, textTransform: 'uppercase', letterSpacing: '.06em', marginTop: 4 }}>Score</div>
            </div>
          </div>

          <button
            onClick={() => {
              const back = String(window.RC_RETURN_TO || '').trim();
              if (back) { try { if (window.top && window.top !== window.self) { window.top.location.href = back; return; } } catch(e) {} window.location.href = back; }
              else window.history.back();
            }}
            style={buttonStyle('primary')}
          >← Back</button>
        </div>
      </div>
    </div>
  );
}

function PlayerView({ data }) {
  const texts = data.texts || [];
  const [textIdx, setTextIdx] = useState(0);
  const [completed, setCompleted] = useState(false);
  const [score, setScore] = useState(null);
  const current = texts[textIdx] || texts[0] || normalizeText();

  async function handleDone(textScore) {
    // aggregate across all texts (single-text case just uses textScore directly)
    const finalScore = textScore;
    setScore(finalScore);
    setCompleted(true);

    const saveUrl = rcBuildSaveUrl(finalScore.percent, finalScore.wrong, finalScore.total);
    if (saveUrl) {
      await rcPersistScore(saveUrl);
    }
  }

  if (completed && score) {
    return <CompletedScreen score={score} title={data.title || String(window.RC_SAVED_TITLE || 'Reading Comprehension')} />;
  }

  return (
    <div className="rc-page">
      <div className="rc-app">
      {texts.length > 1 && (
        <div className="rc-tabs">
          {texts.map((_, idx) => (
            <button key={`tab_${idx}`} onClick={() => setTextIdx(idx)} style={{ border: 'none', background: 'transparent', cursor: 'pointer', color: idx === textIdx ? C.orange : C.purpleMid, fontFamily: 'Nunito, sans-serif', fontWeight: 900, padding: '8px 2px 10px', borderBottom: idx === textIdx ? `2.5px solid ${C.orange}` : '2.5px solid transparent', display: 'inline-flex', alignItems: 'center', gap: 8 }}>
              <span>Text {idx + 1}</span>
              {idx === textIdx && <span style={{ background: C.purpleBorder, color: C.purple, borderRadius: 6, fontSize: 10, fontWeight: 900, padding: '2px 8px' }}>In progress</span>}
            </button>
          ))}
        </div>
      )}

      {current.mode === 'comp' ? <CompQuestions text={current} onDone={handleDone} /> : <VocabQuestions text={current} onDone={handleDone} />}
      </div>
    </div>
  );
}

async function saveActivity(data) {
  const formPayload = new URLSearchParams();
  formPayload.set('id', window.RC_ACTIVITY_ID || '');
  formPayload.set('unit', window.RC_UNIT_ID || '');
  formPayload.set('type', 'reading_comprehension');
  formPayload.set('content_json', JSON.stringify(data));

  const res = await fetch('viewer.php?action=save', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
    credentials: 'same-origin',
    body: formPayload.toString(),
  });

  const payload = await res.json().catch(() => ({}));
  if (!res.ok || payload.status !== 'success') {
    throw new Error(payload.message || `Save failed (${res.status})`);
  }
  if (payload.activity_id) {
    window.RC_ACTIVITY_ID = String(payload.activity_id);
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

  const sectionTitleStyle = { fontFamily: 'Fredoka, sans-serif', color: C.orange, fontSize: 22, lineHeight: 1.1 };
  const countBadgeStyle = { marginLeft: 'auto', background: C.purpleSoft, color: C.purpleDark, borderRadius: 999, padding: '5px 12px', fontWeight: 900, fontSize: 12 };
  const sectionCardStyle = { border: `1px solid ${C.purpleBorder}`, borderRadius: 18, padding: 14, marginBottom: 12, background: '#FBFAFF' };

  if (previewing) {
    return (
      <div style={{ minHeight: '100vh', background: C.bg }}>
        <div style={{ width: 'min(1120px, 100%)', margin: '0 auto', padding: '16px 18px 0' }}>
          <button onClick={() => setPreviewing(false)} style={buttonStyle('secondary')}>← Back to editor</button>
        </div>
        <PlayerView data={data} />
      </div>
    );
  }

  return (
    <div className="rc-editor-page">
      <div className="rc-editor-app">
        <div style={editorSectionStyle}>
          <div style={editorSectionHeaderStyle}>
            <div style={{ width: 38, height: 38, borderRadius: 14, background: C.orangeSoft, border: `1px solid ${C.orangeBorder}`, display: 'grid', placeItems: 'center', color: C.orangeDark, fontWeight: 900 }}>⚙</div>
            <div>
              <div style={{ fontFamily: 'Fredoka, sans-serif', color: C.orange, fontSize: 'clamp(24px, 2.6vw, 32px)', lineHeight: 1.05 }}>Reading Comprehension</div>
              <div style={{ color: C.purpleMid, fontSize: 13, fontWeight: 800 }}>Edit mode</div>
            </div>
            <div style={{ ...countBadgeStyle, background: C.orangeSoft, color: C.orangeDark }}>Activity setup</div>
          </div>

          <div style={{ padding: 20 }}>
            <div className="rc-editor-grid-main" style={{ marginBottom: 16 }}>
              <div>
                <label style={{ display: 'block', marginBottom: 6, fontWeight: 900, color: C.purple }}>Activity title</label>
                <input value={text.title} onChange={(e) => patchText((t) => ({ ...t, title: e.target.value }))} style={editorInputStyle} />
              </div>
              <div>
                <label style={{ display: 'block', marginBottom: 6, fontWeight: 900, color: C.purple }}>Level</label>
                <input value={text.genre} onChange={(e) => patchText((t) => ({ ...t, genre: e.target.value }))} style={editorInputStyle} />
              </div>
            </div>

            <div>
              <label style={{ display: 'block', marginBottom: 8, fontWeight: 900, color: C.purple }}>Activity mode — choose one</label>
              <div className="rc-editor-grid-half">
                <button onClick={() => patchText((t) => ({ ...t, mode: 'vocab' }))} style={{ textAlign: 'left', border: `2px solid ${text.mode === 'vocab' ? C.orange : C.purpleBorder}`, borderRadius: 18, background: text.mode === 'vocab' ? '#FFF7F0' : '#FBFAFF', padding: 18, cursor: 'pointer' }}>
                  <div style={{ fontFamily: 'Fredoka, sans-serif', color: C.orange, fontSize: 20, marginBottom: 6 }}>Vocabulary meaning</div>
                  <div style={{ color: C.purpleMid, fontWeight: 700, fontSize: 14, lineHeight: 1.5 }}>Students read the passage and choose the correct meaning for each highlighted word.</div>
                </button>
                <button onClick={() => patchText((t) => ({ ...t, mode: 'comp' }))} style={{ textAlign: 'left', border: `2px solid ${text.mode === 'comp' ? C.orange : C.purpleBorder}`, borderRadius: 18, background: text.mode === 'comp' ? '#FFF7F0' : '#FBFAFF', padding: 18, cursor: 'pointer' }}>
                  <div style={{ fontFamily: 'Fredoka, sans-serif', color: C.purpleDark, fontSize: 20, marginBottom: 6 }}>Reading comprehension</div>
                  <div style={{ color: C.purpleMid, fontWeight: 700, fontSize: 14, lineHeight: 1.5 }}>Students answer questions about the passage to demonstrate understanding.</div>
                </button>
              </div>
            </div>
          </div>
        </div>

        <div style={editorSectionStyle}>
          <div style={editorSectionHeaderStyle}>
            <div style={{ width: 38, height: 38, borderRadius: 14, background: C.orangeSoft, border: `1px solid ${C.orangeBorder}`, display: 'grid', placeItems: 'center', color: C.orangeDark, fontWeight: 900 }}>📘</div>
            <div style={sectionTitleStyle}>Passage</div>
          </div>

          <div style={{ padding: 20 }}>
            <div className="rc-editor-grid-passage" style={{ marginBottom: 12 }}>
              <div>
                <label style={{ display: 'block', marginBottom: 6, fontWeight: 900, color: C.purple }}>Title</label>
                <input value={text.title} onChange={(e) => patchText((t) => ({ ...t, title: e.target.value }))} style={editorInputStyle} />
              </div>
              <div>
                <label style={{ display: 'block', marginBottom: 6, fontWeight: 900, color: C.purple }}>Genre</label>
                <input value={text.genre} onChange={(e) => patchText((t) => ({ ...t, genre: e.target.value }))} style={editorInputStyle} />
              </div>
              <div>
                <label style={{ display: 'block', marginBottom: 6, fontWeight: 900, color: C.purple }}>Word count</label>
                <input type="number" value={text.wordCount || ''} onChange={(e) => patchText((t) => ({ ...t, wordCount: Number(e.target.value || 0) }))} style={editorInputStyle} />
              </div>
            </div>
            <label style={{ display: 'block', marginBottom: 6, fontWeight: 900, color: C.purple }}>Passage body</label>
            <textarea rows={8} value={text.body} onChange={(e) => patchText((t) => ({ ...t, body: e.target.value }))} style={{ ...editorInputStyle, minHeight: 180, lineHeight: 1.6, resize: 'vertical' }} />
          </div>
        </div>

        <div style={editorSectionStyle}>
          <div style={editorSectionHeaderStyle}>
            <div style={{ width: 38, height: 38, borderRadius: 14, background: C.orangeSoft, border: `1px solid ${C.orangeBorder}`, display: 'grid', placeItems: 'center', color: C.orangeDark, fontWeight: 900 }}>🟧</div>
            <div style={sectionTitleStyle}>Highlighted vocabulary words</div>
            <div style={countBadgeStyle}>{text.words.length} words</div>
          </div>

          <div style={{ padding: 20 }}>
            <div style={{ marginBottom: 12, background: C.purpleSoft, border: `1px solid ${C.purpleBorder}`, borderRadius: 16, padding: '12px 14px', color: C.purpleDark, fontWeight: 800, fontSize: 14 }}>
              Add each word that appears in the passage. It will be highlighted in orange for students.
            </div>

            <div style={{ border: `1px solid ${C.orangeBorder}`, background: '#FFF9F4', borderRadius: 18, padding: 14, marginBottom: 14 }}>
              <div style={{ color: C.purpleMid, fontSize: 11, fontWeight: 900, marginBottom: 8, textTransform: 'uppercase', letterSpacing: '.08em' }}>Live preview — highlighted words</div>
              <div style={{ color: C.ink, lineHeight: 1.7 }} dangerouslySetInnerHTML={{ __html: paragraphToHtml(text.body || 'Type passage text to preview highlights.', text.words) }} />
            </div>

            {text.words.map((word, idx) => (
              <div key={word.id} style={sectionCardStyle}>
                <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', gap: 12, marginBottom: 10, flexWrap: 'wrap' }}>
                  <div style={{ color: C.orange, fontFamily: 'Fredoka, sans-serif', fontSize: 20 }}>Word card {idx + 1}</div>
                  <button onClick={() => patchText((t) => ({ ...t, words: t.words.filter((_, i) => i !== idx) }))} style={buttonStyle('danger')}>Delete</button>
                </div>
                <div className="rc-editor-grid-half" style={{ marginBottom: 10 }}>
                  <div>
                    <label style={{ display: 'block', marginBottom: 4, fontWeight: 900, color: C.purple }}>Word (as it appears in text)</label>
                    <input value={word.word} onChange={(e) => patchText((t) => ({ ...t, words: t.words.map((w, i) => i === idx ? { ...w, word: e.target.value } : w) }))} style={editorInputStyle} />
                  </div>
                  <div>
                    <label style={{ display: 'block', marginBottom: 4, fontWeight: 900, color: C.purple }}>Correct meaning</label>
                    <input value={word.correct} onChange={(e) => patchText((t) => ({ ...t, words: t.words.map((w, i) => i === idx ? { ...w, correct: e.target.value } : w) }))} style={editorInputStyle} />
                  </div>
                </div>
                <div className="rc-editor-grid-half">
                  <div>
                    <label style={{ display: 'block', marginBottom: 4, fontWeight: 900, color: C.purple }}>Wrong option 1</label>
                    <input value={word.distractors[0] || ''} onChange={(e) => patchText((t) => ({ ...t, words: t.words.map((w, i) => i === idx ? { ...w, distractors: [e.target.value, w.distractors?.[1] || ''] } : w) }))} style={editorInputStyle} />
                  </div>
                  <div>
                    <label style={{ display: 'block', marginBottom: 4, fontWeight: 900, color: C.purple }}>Wrong option 2</label>
                    <input value={word.distractors[1] || ''} onChange={(e) => patchText((t) => ({ ...t, words: t.words.map((w, i) => i === idx ? { ...w, distractors: [w.distractors?.[0] || '', e.target.value] } : w) }))} style={editorInputStyle} />
                  </div>
                </div>
              </div>
            ))}

            <button onClick={addWord} style={{ ...buttonStyle('secondary'), width: '100%' }}>+ Add vocabulary word</button>
          </div>
        </div>

        <div style={editorSectionStyle}>
          <div style={editorSectionHeaderStyle}>
            <div style={{ width: 38, height: 38, borderRadius: 14, background: C.orangeSoft, border: `1px solid ${C.orangeBorder}`, display: 'grid', placeItems: 'center', color: C.orangeDark, fontWeight: 900 }}>❓</div>
            <div style={sectionTitleStyle}>Reading comprehension questions</div>
            <div style={countBadgeStyle}>{text.questions.length} questions</div>
          </div>

          <div style={{ padding: 20 }}>
            {text.questions.map((q, idx) => (
              <div key={q.id} style={sectionCardStyle}>
                <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', gap: 12, marginBottom: 10, flexWrap: 'wrap' }}>
                  <div style={{ color: C.orange, fontFamily: 'Fredoka, sans-serif', fontSize: 20 }}>Question {idx + 1}</div>
                  <button onClick={() => patchText((t) => ({ ...t, questions: t.questions.filter((_, i) => i !== idx) }))} style={buttonStyle('danger')}>Delete</button>
                </div>
                <input value={q.stem} onChange={(e) => patchText((t) => ({ ...t, questions: t.questions.map((x, i) => i === idx ? { ...x, stem: e.target.value } : x) }))} placeholder="Question stem" style={{ ...editorInputStyle, marginBottom: 10 }} />
                {q.options.map((opt, optIdx) => (
                  <input key={`${q.id}_${optIdx}`} value={opt} onChange={(e) => patchText((t) => ({ ...t, questions: t.questions.map((x, i) => i === idx ? { ...x, options: x.options.map((o, j) => j === optIdx ? e.target.value : o) } : x) }))} placeholder={`Option ${LETTERS[optIdx]}`} style={{ ...editorInputStyle, marginBottom: 8 }} />
                ))}
                <div style={{ display: 'flex', alignItems: 'center', gap: 12, marginBottom: 10, flexWrap: 'wrap' }}>
                  <span style={{ fontWeight: 900, color: C.ink }}>Correct:</span>
                  {LETTERS.map((label, optIdx) => (
                    <label key={`${q.id}_c_${optIdx}`} style={{ display: 'inline-flex', alignItems: 'center', gap: 6, fontWeight: 800, color: C.purpleDark }}>
                      <input type="radio" checked={q.correct === optIdx} onChange={() => patchText((t) => ({ ...t, questions: t.questions.map((x, i) => i === idx ? { ...x, correct: optIdx } : x) }))} /> {label}
                    </label>
                  ))}
                </div>
                <input value={q.feedback} onChange={(e) => patchText((t) => ({ ...t, questions: t.questions.map((x, i) => i === idx ? { ...x, feedback: e.target.value } : x) }))} placeholder="Feedback" style={editorInputStyle} />
              </div>
            ))}
            <button onClick={addQuestion} style={{ ...buttonStyle('secondary'), width: '100%' }}>+ Add comprehension question</button>
          </div>
        </div>

        <div className="rc-editor-actions">
          <div style={{ color: C.purple, fontWeight: 900, fontSize: 14, textAlign: 'right' }}>{status}</div>
          <button onClick={() => setPreviewing(true)} style={buttonStyle('secondary')}>Preview as student</button>
          <button onClick={save} disabled={saving} style={buttonStyle('primary', saving)}>Save activity</button>
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
html,body{
  width:100%;
  min-height:100%;
  margin:0;
  background:#F8F7FF;
  font-family:'Nunito','Segoe UI',sans-serif;
}
body{
  margin:0!important;
  padding:0!important;
  background:#F8F7FF!important;
}
.activity-wrapper{
  max-width:100%!important;
  margin:0!important;
  padding:0!important;
  min-height:100vh!important;
  display:flex!important;
  flex-direction:column!important;
  background:transparent!important;
}
.top-row,
.activity-header,
.viewer-header{
  display:none!important;
}
.viewer-content{
  flex:1!important;
  min-height:0!important;
  display:flex!important;
  flex-direction:column!important;
  padding:0!important;
  margin:0!important;
  background:transparent!important;
  border:none!important;
  box-shadow:none!important;
  border-radius:0!important;
  overflow:hidden!important;
}
.rc-page{
  width:100%;
  flex:1;
  min-height:0;
  overflow:auto;
  padding:clamp(14px,2.2vw,28px);
  display:flex;
  justify-content:center;
  align-items:flex-start;
  background:#F8F7FF;
}
.rc-app,
.rc-editor-app{
  width:min(1120px,100%);
  margin:0 auto;
}
.rc-tabs{
  margin-bottom:14px;
  padding:0 18px;
  min-height:54px;
  display:flex;
  align-items:flex-end;
  gap:18px;
  border:1px solid #EDE9FA;
  border-radius:22px;
  background:#fff;
  box-shadow:0 8px 28px rgba(127,119,221,.08);
}
.rc-stage-shell{
  display:grid;
  grid-template-rows:auto minmax(0,1fr);
  min-height:min(760px,calc(100vh - 72px));
  background:#fff;
  border:1px solid #EDE9FA;
  border-radius:28px;
  overflow:hidden;
  box-shadow:0 12px 36px rgba(127,119,221,.12);
}
.rc-topbar{
  display:grid;
  grid-template-columns:auto 1fr auto;
  align-items:center;
  gap:16px;
  padding:18px 22px;
  border-bottom:1px solid #F0EEF8;
  background:linear-gradient(180deg,#ffffff 0%,#fbfaff 100%);
}
.rc-stage-layout{
  display:grid;
  grid-template-columns:minmax(0,1.03fr) minmax(320px,.97fr);
  min-height:0;
}
.rc-passage-pane{
  height:100%;
  min-height:0;
  overflow:auto;
  padding:22px;
  border-right:1px solid #F0EEF8;
  background:#FCFBFF;
}
.rc-question-pane{
  min-height:0;
  overflow:auto;
  padding:22px;
  background:#fff;
}
.rc-question-card{
  border:1px solid #EDE9FA;
  border-radius:24px;
  background:#fff;
  padding:22px;
  box-shadow:0 8px 24px rgba(127,119,221,.08);
}
.rc-actions{
  margin-top:16px;
  display:flex;
  justify-content:space-between;
  align-items:center;
  gap:10px;
  flex-wrap:wrap;
}
.rc-editor-page{
  min-height:100vh;
  overflow:auto;
  background:#F8F7FF;
  padding:20px;
}
.rc-editor-grid-main{
  display:grid;
  grid-template-columns:2fr 1fr;
  gap:12px;
}
.rc-editor-grid-passage{
  display:grid;
  grid-template-columns:1fr 1fr 220px;
  gap:10px;
}
.rc-editor-grid-half{
  display:grid;
  grid-template-columns:1fr 1fr;
  gap:10px;
}
.rc-editor-actions{
  display:grid;
  grid-template-columns:1fr auto auto;
  gap:12px;
  align-items:center;
  position:sticky;
  bottom:0;
  padding:12px 0 2px;
  background:linear-gradient(180deg,rgba(248,247,255,0) 0%,#F8F7FF 34%);
}
.rc-hl{
  color:#C2580A;
  font-weight:900;
  border-bottom:2px solid #F97316;
  background:#FFF0E6;
  border-radius:4px;
  padding:0 2px;
}
@media (max-width: 980px){
  .rc-stage-shell{
    min-height:auto;
  }
  .rc-stage-layout,
  .rc-editor-grid-main,
  .rc-editor-grid-passage,
  .rc-editor-grid-half,
  .rc-editor-actions{
    grid-template-columns:1fr;
  }
  .rc-passage-pane{
    border-right:none;
    border-bottom:1px solid #F0EEF8;
  }
  .rc-editor-actions{
    position:static;
    background:transparent;
    padding-top:0;
  }
}
@media (max-width: 640px){
  .rc-page,
  .rc-editor-page{
    padding:12px;
  }
  .rc-topbar,
  .rc-question-pane,
  .rc-passage-pane{
    padding:16px;
  }
  .rc-tabs{
    padding:0 14px;
    gap:12px;
    overflow:auto;
  }
}
/* Editor mode: override template's overflow:hidden so the page can scroll */
body:has(.rc-editor-page){
  height:auto!important;
  overflow:auto!important;
}
.activity-wrapper:has(.rc-editor-page){
  height:auto!important;
  min-height:100vh!important;
}
.viewer-content:has(.rc-editor-page){
  overflow:visible!important;
  height:auto!important;
  min-height:0!important;
  flex:none!important;
}
</style>

<?php
$content = ob_get_clean();
render_activity_viewer($savedTitle, '📖', $content);
