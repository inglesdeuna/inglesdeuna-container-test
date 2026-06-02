<?php
/*
 * ONES – student_dashboard.php  (HTML/CSS shell replacement)
 *
 * ─── INSTRUCTIONS FOR COPILOT / DEVELOPER ───────────────────────────────────
 * This file contains ONLY the redesigned HTML/CSS structure.
 * All PHP logic (session checks, DB queries, variable assignments, loops)
 * from the ORIGINAL student_dashboard.php must be kept exactly as-is.
 *
 * Integration points are marked with:
 *   <!-- PHP: keep original code here -->
 *   <?php /* PHP_PLACEHOLDER: replace with your original PHP block * / ?>
 *
 * Steps:
 *  1. Copy your original PHP blocks (session_start, DB connection, queries,
 *     variable assignments, loops) into the marked placeholders.
 *  2. In the course cards loop, replace the static card HTML with
 *     your original foreach/while loop, mapping variables like:
 *       $course['unit_name']  → .course-unit
 *       $course['score']      → score bar width + .score-pct
 *       $course['errors']     → .errors-chip
 *       $course['period']     → .course-badge period
 *       $course['has_quiz']   → show/hide Quiz button
 *  3. In the Progress tab, map the same data to the unit table rows.
 *  4. The tab switching is handled by JS — no PHP needed for navigation.
 * ────────────────────────────────────────────────────────────────────────────
 */

/* PHP_PLACEHOLDER: paste original session_start(), DB connection,
   student data queries, and variable assignments here */
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>ONES – Student Dashboard</title>
  <link rel="icon" type="image/svg+xml" href="assets/ones-mark-32.svg"/>
  <link rel="preconnect" href="https://fonts.googleapis.com"/>
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin/>
  <link href="https://fonts.googleapis.com/css2?family=Fredoka+One&family=Nunito:wght@400;600;700;800&display=swap" rel="stylesheet"/>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@latest/tabler-icons.min.css"/>
  <link rel="stylesheet" href="student_dashboard.css"/>
</head>
<body>

<!-- ═══════════════════════════════════════════
     TOPBAR
     ═══════════════════════════════════════════ -->
<header class="topbar">
  <div class="topbar-brand">
    <svg width="30" height="30" viewBox="0 0 36 36" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
      <rect width="36" height="36" rx="9" fill="#FFF0E6"/>
      <circle cx="17" cy="15" r="8.5" fill="#F97316"/>
      <polygon points="12,22 7,30 21,26" fill="#F97316"/>
      <circle cx="17" cy="15" r="4.5" fill="#FFF0E6"/>
      <circle cx="24" cy="9" r="3.5" fill="#7F77DD"/>
      <circle cx="24" cy="9" r="1.75" fill="#ffffff"/>
    </svg>
    <div>
      <div class="topbar-name">ONES</div>
      <div class="topbar-sub">Online English Solution</div>
    </div>
  </div>

  <div class="topbar-right">
    <div>
      <!-- PHP_PLACEHOLDER: echo $student_name and $student_id -->
      <div class="topbar-student-name">Estudiante Prueba</div>
      <div class="topbar-student-role">Estudiante &middot; ID: 00142</div>
    </div>
    <div class="topbar-avatar">
      <!-- PHP_PLACEHOLDER: if student has photo, use <img>, else initials -->
      EP
    </div>
    <a href="logout.php" class="btn-logout">
      <i class="ti ti-logout" aria-hidden="true"></i>Salir
    </a>
  </div>
</header>

<!-- ═══════════════════════════════════════════
     PHASE NAVIGATION
     ═══════════════════════════════════════════ -->
<nav class="phase-bar" aria-label="Secciones del dashboard">
  <button class="phase-tab active" onclick="switchTab('english', this)" aria-controls="tab-english">
    <i class="ti ti-language" aria-hidden="true"></i>English
  </button>
  <?php /* PHP_PLACEHOLDER: if student has technical courses, render this tab */ ?>
  <button class="phase-tab" onclick="switchTab('technical', this)" aria-controls="tab-technical">
    <i class="ti ti-tool" aria-hidden="true"></i>Technical
  </button>
  <button class="phase-tab" onclick="switchTab('progress', this)" aria-controls="tab-progress">
    <i class="ti ti-chart-bar" aria-hidden="true"></i>Progress
  </button>
</nav>

<!-- ═══════════════════════════════════════════
     BODY
     ═══════════════════════════════════════════ -->
<div class="dash-body">

  <!-- ── SIDEBAR ── -->
  <aside class="sidebar">

    <div class="profile-card">
      <div class="profile-avatar">
        <!-- PHP_PLACEHOLDER: if photo exists: <img src="<?= $photo_url ?>" alt="Foto de perfil"/> else initials -->
        EP
      </div>
      <!-- PHP_PLACEHOLDER: echo student name and role -->
      <div class="profile-name">Estudiante Prueba</div>
      <div class="profile-role">Estudiante</div>
      <div class="profile-divider"></div>
      <div class="stat-row">
        <div class="stat-chip">
          <!-- PHP_PLACEHOLDER: echo count($courses) -->
          <div class="stat-value">6</div>
          <div class="stat-label">Cursos</div>
        </div>
        <div class="stat-chip">
          <!-- PHP_PLACEHOLDER: echo round($avg_score) . '%' -->
          <div class="stat-value">90%</div>
          <div class="stat-label">Prom.</div>
        </div>
      </div>
      <div class="photo-label">Foto de perfil</div>
      <form method="POST" enctype="multipart/form-data" style="width:100%;">
        <!-- PHP_PLACEHOLDER: keep original photo upload form fields here -->
        <input type="file" name="photo" accept="image/*" class="photo-input"/>
        <button type="submit" name="update_photo" class="sdbtn sdbtn-purple" style="margin-top:6px;width:100%;">
          <i class="ti ti-upload" aria-hidden="true"></i>Actualizar foto
        </button>
      </form>
    </div>

    <div class="sidebar-actions">
      <div class="sidebar-section-label">Acciones</div>
      <!-- PHP_PLACEHOLDER: keep original href values for these links -->
      <a href="quiz.php" class="sdbtn sdbtn-purple">
        <i class="ti ti-clipboard-check" aria-hidden="true"></i>Ir al Quiz
      </a>
      <a href="change_password.php" class="sdbtn sdbtn-orange">
        <i class="ti ti-key" aria-hidden="true"></i>Cambiar clave
      </a>
      <a href="logout.php" class="sdbtn sdbtn-ghost">
        <i class="ti ti-logout" aria-hidden="true"></i>Cerrar sesión
      </a>
    </div>

  </aside>

  <!-- ── MAIN CONTENT ── -->
  <main class="main-wrap">

    <!-- ══════════════════════════════
         TAB: ENGLISH
         ══════════════════════════════ -->
    <div id="tab-english" class="tab-panel active">
      <div class="grid-header">
        <div class="grid-title">English Courses</div>
        <!-- PHP_PLACEHOLDER: echo course count and teacher name -->
        <div class="grid-meta">6 cursos &middot; Teacher: Terry Hughes</div>
      </div>

      <div class="course-grid">
        <?php
        /* PHP_PLACEHOLDER ─────────────────────────────────────────────────────
         * Replace the static cards below with your original course loop.
         * Example mapping:
         *
         * foreach ($english_courses as $course):
         *   $score      = (int)$course['average_score'];
         *   $bar_color  = $score >= 90 ? '#3B6D11' : '#F97316';
         *   $pct_color  = $bar_color;
         *   $chip_class = $score >= 90 && $course['errors'] == 0 ? 'chip-good' : 'chip-warn';
         *   $chip_icon  = $score >= 90 ? 'ti-circle-check' : 'ti-alert-triangle';
         * ─────────────────────────────────────────────────────────────────── */
        ?>

        <!-- STATIC DEMO CARDS — replace with PHP loop above -->

        <div class="course-card">
          <div class="course-badge badge-english">
            <i class="ti ti-book" style="font-size:10px;" aria-hidden="true"></i>INGLÉS &middot; P8
          </div>
          <div class="course-unit">Unit 5 &mdash; What are you doing?</div>
          <div class="course-teacher">Teacher: <b>Terry Hughes</b></div>
          <div class="score-wrap">
            <div class="score-bar-bg"><div class="score-bar-fill" style="width:93%;background:#3B6D11;"></div></div>
            <div class="score-pct" style="color:#3B6D11;">93%</div>
          </div>
          <div class="errors-chip chip-warn">
            <i class="ti ti-alert-triangle" style="font-size:10px;" aria-hidden="true"></i>4 / 54 errores
          </div>
          <div class="card-btns">
            <a href="#" class="cbtn cbtn-primary"><i class="ti ti-door-enter" aria-hidden="true"></i>Entrar</a>
            <a href="#" class="cbtn cbtn-outline-green"><i class="ti ti-clipboard-check" aria-hidden="true"></i>Quiz</a>
            <a href="#" class="cbtn cbtn-outline-purple"><i class="ti ti-chart-bar" aria-hidden="true"></i>Puntajes</a>
          </div>
        </div>

        <div class="course-card">
          <div class="course-badge badge-english">
            <i class="ti ti-book" style="font-size:10px;" aria-hidden="true"></i>INGLÉS &middot; P7
          </div>
          <div class="course-unit">Unit 5 &mdash; How's the weather?</div>
          <div class="course-teacher">Teacher: <b>Terry Hughes</b></div>
          <div class="score-wrap">
            <div class="score-bar-bg"><div class="score-bar-fill" style="width:100%;background:#3B6D11;"></div></div>
            <div class="score-pct" style="color:#3B6D11;">100%</div>
          </div>
          <div class="errors-chip chip-good">
            <i class="ti ti-circle-check" style="font-size:10px;" aria-hidden="true"></i>0 / 51 errores
          </div>
          <div class="card-btns">
            <a href="#" class="cbtn cbtn-primary"><i class="ti ti-door-enter" aria-hidden="true"></i>Entrar</a>
            <a href="#" class="cbtn cbtn-outline-green"><i class="ti ti-clipboard-check" aria-hidden="true"></i>Quiz</a>
            <a href="#" class="cbtn cbtn-outline-purple"><i class="ti ti-chart-bar" aria-hidden="true"></i>Puntajes</a>
          </div>
        </div>

        <div class="course-card">
          <div class="course-badge badge-english">
            <i class="ti ti-book" style="font-size:10px;" aria-hidden="true"></i>INGLÉS &middot; P1
          </div>
          <div class="course-unit">Unit 4 &mdash; Shapes everywhere</div>
          <div class="course-teacher">Teacher: <b>Terry Hughes</b></div>
          <div class="score-wrap">
            <div class="score-bar-bg"><div class="score-bar-fill" style="width:81%;background:#F97316;"></div></div>
            <div class="score-pct" style="color:#F97316;">81%</div>
          </div>
          <div class="errors-chip chip-warn">
            <i class="ti ti-alert-triangle" style="font-size:10px;" aria-hidden="true"></i>8 / 43 errores
          </div>
          <div class="card-btns">
            <a href="#" class="cbtn cbtn-primary"><i class="ti ti-door-enter" aria-hidden="true"></i>Entrar</a>
            <a href="#" class="cbtn cbtn-outline-purple"><i class="ti ti-chart-bar" aria-hidden="true"></i>Puntajes</a>
          </div>
        </div>

        <div class="course-card">
          <div class="course-badge badge-english">
            <i class="ti ti-book" style="font-size:10px;" aria-hidden="true"></i>INGLÉS &middot; P1
          </div>
          <div class="course-unit">Unit 3 &mdash; I can count to 9</div>
          <div class="course-teacher">Teacher: <b>Terry Hughes</b></div>
          <div class="score-wrap">
            <div class="score-bar-bg"><div class="score-bar-fill" style="width:86%;background:#F97316;"></div></div>
            <div class="score-pct" style="color:#F97316;">86%</div>
          </div>
          <div class="errors-chip chip-warn">
            <i class="ti ti-alert-triangle" style="font-size:10px;" aria-hidden="true"></i>3 / 21 errores
          </div>
          <div class="card-btns">
            <a href="#" class="cbtn cbtn-primary"><i class="ti ti-door-enter" aria-hidden="true"></i>Entrar</a>
            <a href="#" class="cbtn cbtn-outline-green"><i class="ti ti-clipboard-check" aria-hidden="true"></i>Quiz</a>
            <a href="#" class="cbtn cbtn-outline-purple"><i class="ti ti-chart-bar" aria-hidden="true"></i>Puntajes</a>
          </div>
        </div>

        <div class="course-card">
          <div class="course-badge badge-english">
            <i class="ti ti-book" style="font-size:10px;" aria-hidden="true"></i>INGLÉS &middot; P1
          </div>
          <div class="course-unit">Unit 2 &mdash; Putting on your clothes</div>
          <div class="course-teacher">Teacher: <b>Terry Hughes</b></div>
          <div class="score-wrap">
            <div class="score-bar-bg"><div class="score-bar-fill" style="width:88%;background:#F97316;"></div></div>
            <div class="score-pct" style="color:#F97316;">88%</div>
          </div>
          <div class="errors-chip chip-warn">
            <i class="ti ti-alert-triangle" style="font-size:10px;" aria-hidden="true"></i>5 / 40 errores
          </div>
          <div class="card-btns">
            <a href="#" class="cbtn cbtn-primary"><i class="ti ti-door-enter" aria-hidden="true"></i>Entrar</a>
            <a href="#" class="cbtn cbtn-outline-purple"><i class="ti ti-chart-bar" aria-hidden="true"></i>Puntajes</a>
          </div>
        </div>

        <div class="course-card">
          <div class="course-badge badge-english">
            <i class="ti ti-book" style="font-size:10px;" aria-hidden="true"></i>INGLÉS &middot; P1
          </div>
          <div class="course-unit">Unit 1 &mdash; Touch your head</div>
          <div class="course-teacher">Teacher: <b>Terry Hughes</b></div>
          <div class="score-wrap">
            <div class="score-bar-bg"><div class="score-bar-fill" style="width:95%;background:#3B6D11;"></div></div>
            <div class="score-pct" style="color:#3B6D11;">95%</div>
          </div>
          <div class="errors-chip chip-good">
            <i class="ti ti-circle-check" style="font-size:10px;" aria-hidden="true"></i>2 / 38 errores
          </div>
          <div class="card-btns">
            <a href="#" class="cbtn cbtn-primary"><i class="ti ti-door-enter" aria-hidden="true"></i>Entrar</a>
            <a href="#" class="cbtn cbtn-outline-purple"><i class="ti ti-chart-bar" aria-hidden="true"></i>Puntajes</a>
          </div>
        </div>

        <?php /* end foreach */ ?>
      </div>
    </div>

    <!-- ══════════════════════════════
         TAB: TECHNICAL
         ══════════════════════════════ -->
    <div id="tab-technical" class="tab-panel">
      <div class="grid-header">
        <div class="grid-title">Technical Courses</div>
        <div class="grid-meta"><!-- PHP_PLACEHOLDER: technical course count + teacher --></div>
      </div>
      <div class="course-grid">
        <?php /* PHP_PLACEHOLDER: foreach $technical_courses — same card structure as English,
                  use badge-technical class instead of badge-english */ ?>
        <div style="padding:2rem;color:#C4BDED;font-size:13px;grid-column:1/-1;text-align:center;">
          <i class="ti ti-tool" style="font-size:32px;display:block;margin-bottom:8px;color:#EDE9FA;" aria-hidden="true"></i>
          No hay cursos técnicos asignados.
        </div>
      </div>
    </div>

    <!-- ══════════════════════════════
         TAB: PROGRESS
         ══════════════════════════════ -->
    <div id="tab-progress" class="tab-panel">

      <!-- Hero metrics -->
      <div class="hero-row">
        <div class="hero-card">
          <div class="hero-label">Promedio general</div>
          <!-- PHP_PLACEHOLDER: echo $avg_score . '%' -->
          <div class="hero-value" style="color:#F97316;">90%</div>
          <div class="hero-sub">6 unidades completadas</div>
          <div class="hero-bar-bg"><div class="hero-bar-fill" style="width:90%;background:#F97316;"></div></div>
        </div>
        <div class="hero-card">
          <div class="hero-label">Puntaje total</div>
          <!-- PHP_PLACEHOLDER: echo $total_score . ' / ' . $max_score -->
          <div class="hero-value" style="color:#7F77DD;">540 <span>/ 600</span></div>
          <div class="hero-sub">Suma de todas las unidades</div>
          <div class="hero-bar-bg"><div class="hero-bar-fill" style="width:90%;background:#7F77DD;"></div></div>
        </div>
        <div class="hero-card">
          <div class="hero-label">Errores totales</div>
          <!-- PHP_PLACEHOLDER: echo $total_errors . ' / ' . $total_questions -->
          <div class="hero-value" style="color:#E24B4A;">22 <span>/ 227</span></div>
          <div class="hero-sub">Tasa de error: 9.7%</div>
          <div class="hero-bar-bg"><div class="hero-bar-fill" style="width:10%;background:#E24B4A;"></div></div>
        </div>
        <div class="hero-card">
          <div class="hero-label">Unidades perfectas</div>
          <!-- PHP_PLACEHOLDER: echo $perfect_units . ' / ' . count($courses) -->
          <div class="hero-value" style="color:#3B6D11;">2 <span>/ 6</span></div>
          <div class="hero-sub">100% sin errores</div>
          <div class="hero-bar-bg"><div class="hero-bar-fill" style="width:33%;background:#3B6D11;"></div></div>
        </div>
      </div>

      <!-- Unit breakdown table -->
      <div class="section-card">
        <div class="sec-header">
          <div class="sec-title">Desglose por unidad</div>
          <!-- PHP_PLACEHOLDER: echo program + unit count -->
          <div class="sec-badge">INGLÉS &middot; 6 UNIDADES</div>
        </div>
        <table class="unit-table">
          <thead>
            <tr>
              <th>Unidad</th>
              <th>Período</th>
              <th>Nota</th>
              <th>Progreso</th>
              <th>Errores</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <?php
            /* PHP_PLACEHOLDER ─────────────────────────────────────────────
             * foreach ($all_courses as $c):
             *   $s     = (int)$c['score'];
             *   $bar   = $s >= 90 ? '#3B6D11' : ($s >= 80 ? '#F97316' : '#E24B4A');
             *   $pill  = $s >= 90 ? 'score-green' : ($s >= 80 ? 'score-orange' : 'score-red');
             *   $icon  = $s >= 90 ? 'ti-star' : ($c['errors'] > 0 ? 'ti-alert-circle' : 'ti-circle-check');
             *   $icol  = $s >= 90 ? '#F97316' : '#F97316';
             * ─────────────────────────────────────────────────────────── */
            ?>

            <!-- STATIC ROWS — replace with PHP loop above -->
            <tr>
              <td><span class="unit-name">Unit 5 &mdash; What are you doing?</span></td>
              <td style="color:#9B8FCC;">P8</td>
              <td><span class="score-pill score-green">93</span></td>
              <td><span class="mini-bar-bg"><span class="mini-bar-fill" style="width:93%;background:#3B6D11;"></span></span></td>
              <td style="color:#9B8FCC;font-size:10px;">4 / 54</td>
              <td><i class="ti ti-circle-check" style="color:#3B6D11;font-size:14px;" aria-hidden="true"></i></td>
            </tr>
            <tr>
              <td><span class="unit-name">Unit 5 &mdash; How's the weather?</span></td>
              <td style="color:#9B8FCC;">P7</td>
              <td><span class="score-pill score-green">100</span></td>
              <td><span class="mini-bar-bg"><span class="mini-bar-fill" style="width:100%;background:#3B6D11;"></span></span></td>
              <td style="color:#9B8FCC;font-size:10px;">0 / 51</td>
              <td><i class="ti ti-star" style="color:#F97316;font-size:14px;" aria-hidden="true"></i></td>
            </tr>
            <tr>
              <td><span class="unit-name">Unit 4 &mdash; Shapes everywhere</span></td>
              <td style="color:#9B8FCC;">P1</td>
              <td><span class="score-pill score-orange">81</span></td>
              <td><span class="mini-bar-bg"><span class="mini-bar-fill" style="width:81%;background:#F97316;"></span></span></td>
              <td style="color:#9B8FCC;font-size:10px;">8 / 43</td>
              <td><i class="ti ti-alert-circle" style="color:#F97316;font-size:14px;" aria-hidden="true"></i></td>
            </tr>
            <tr>
              <td><span class="unit-name">Unit 3 &mdash; I can count to 9</span></td>
              <td style="color:#9B8FCC;">P1</td>
              <td><span class="score-pill score-green">86</span></td>
              <td><span class="mini-bar-bg"><span class="mini-bar-fill" style="width:86%;background:#F97316;"></span></span></td>
              <td style="color:#9B8FCC;font-size:10px;">3 / 21</td>
              <td><i class="ti ti-circle-check" style="color:#3B6D11;font-size:14px;" aria-hidden="true"></i></td>
            </tr>
            <tr>
              <td><span class="unit-name">Unit 2 &mdash; Putting on your clothes</span></td>
              <td style="color:#9B8FCC;">P1</td>
              <td><span class="score-pill score-orange">88</span></td>
              <td><span class="mini-bar-bg"><span class="mini-bar-fill" style="width:88%;background:#F97316;"></span></span></td>
              <td style="color:#9B8FCC;font-size:10px;">5 / 40</td>
              <td><i class="ti ti-alert-circle" style="color:#F97316;font-size:14px;" aria-hidden="true"></i></td>
            </tr>
            <tr>
              <td><span class="unit-name">Unit 1 &mdash; Touch your head</span></td>
              <td style="color:#9B8FCC;">P1</td>
              <td><span class="score-pill score-green">95</span></td>
              <td><span class="mini-bar-bg"><span class="mini-bar-fill" style="width:95%;background:#3B6D11;"></span></span></td>
              <td style="color:#9B8FCC;font-size:10px;">2 / 38</td>
              <td><i class="ti ti-star" style="color:#F97316;font-size:14px;" aria-hidden="true"></i></td>
            </tr>

            <?php /* end foreach */ ?>
          </tbody>
        </table>

        <div class="total-row">
          <span class="total-label">TOTAL ACUMULADO</span>
          <div class="total-values">
            <!-- PHP_PLACEHOLDER: echo $total_score and $max_score -->
            <span class="total-pts">540 <span class="total-small">/ 600 pts</span></span>
            <!-- PHP_PLACEHOLDER: echo $avg_score -->
            <span class="total-avg">90% <span class="total-small">promedio</span></span>
            <!-- PHP_PLACEHOLDER: echo $total_errors -->
            <span class="total-err">22 errores totales</span>
          </div>
        </div>
      </div>

      <!-- Strengths / Weaknesses -->
      <div class="sw-row">
        <div class="sw-card">
          <div class="sw-title" style="color:#27500A;">
            <i class="ti ti-thumb-up" style="color:#3B6D11;" aria-hidden="true"></i>Fortalezas
          </div>
          <?php /* PHP_PLACEHOLDER: foreach top 4 units by score (desc) */ ?>
          <div class="sw-item">
            <div class="sw-dot" style="background:#3B6D11;"></div>
            <div><b>Vocabulario de clima</b> &mdash; Unit 5 P7 sin errores, 100%</div>
          </div>
          <div class="sw-item">
            <div class="sw-dot" style="background:#3B6D11;"></div>
            <div><b>Partes del cuerpo</b> &mdash; Unit 1 dominada, 95% y solo 2 errores</div>
          </div>
          <div class="sw-item">
            <div class="sw-dot" style="background:#3B6D11;"></div>
            <div><b>Verbos continuos</b> &mdash; Unit 5 P8 a 93%, errores mínimos</div>
          </div>
          <div class="sw-item">
            <div class="sw-dot" style="background:#3B6D11;"></div>
            <div><b>Números del 1–9</b> &mdash; Unit 3 con 86% y baja tasa de error</div>
          </div>
        </div>

        <div class="sw-card">
          <div class="sw-title" style="color:#993C1D;">
            <i class="ti ti-target" style="color:#F97316;" aria-hidden="true"></i>Áreas a reforzar
          </div>
          <?php /* PHP_PLACEHOLDER: foreach bottom units by score (asc) + high error rate */ ?>
          <div class="sw-item">
            <div class="sw-dot" style="background:#F97316;"></div>
            <div><b>Formas geométricas</b> &mdash; Unit 4 con 81%, mayor cantidad de errores (8/43)</div>
          </div>
          <div class="sw-item">
            <div class="sw-dot" style="background:#F97316;"></div>
            <div><b>Vocabulario de ropa</b> &mdash; Unit 2 al 88%, 5 errores por revisar</div>
          </div>
          <div class="sw-item">
            <div class="sw-dot" style="background:#E24B4A;"></div>
            <div><b>Período 1 en general</b> &mdash; 3 de 4 unidades P1 por debajo de 90%</div>
          </div>
          <div class="sw-item">
            <div class="sw-dot" style="background:#F97316;"></div>
            <div><b>Consistencia</b> &mdash; diferencia de 19 puntos entre mejor y peor unidad</div>
          </div>
        </div>
      </div>

    </div><!-- end tab-progress -->

  </main>
</div><!-- end dash-body -->

<script>
  function switchTab(name, btn) {
    document.querySelectorAll('.phase-tab').forEach(t => t.classList.remove('active'));
    document.querySelectorAll('.tab-panel').forEach(p => p.classList.remove('active'));
    btn.classList.add('active');
    document.getElementById('tab-' + name).classList.add('active');
  }
</script>

</body>
</html>
