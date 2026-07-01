# CLAUDE.md — inglesdeuna-container-test

Contexto para Claude Code al trabajar en este repositorio. Léelo antes de tocar código.

## Qué es este proyecto

Plataforma bilingüe de Let's Institute (Cúcuta, Colombia) con dos marcas:
- **ONES / inglesdeuna** — inglés para adultos (inglesdeuna.com)
- **LET'S Institute** — preescolar a bachillerato (kids)

Ambas corren sobre la misma base de código.

## Stack técnico (verificado en el repo)

- **Backend:** PHP 8.2 (php:8.2-apache), sin framework — PHP plano con require_once
- **DB:** PostgreSQL vía PDO (pdo_pgsql), conexión en lessons/lessons/config/db.php usando DATABASE_URL
- **Frontend:** HTML + JS vanilla dentro de los .php, algo de React inline vía Babel en actividades específicas
- **Contenedor:** Docker sobre Apache, desplegado en Render (Dockerfile en raíz)
- **Dependencias PHP:** Composer, solo cloudinary/cloudinary_php (composer.json)
- **Media:** Cloudinary (imágenes/audio), ElevenLabs (TTS) vía tts.php en cada actividad
- **Repo:** inglesdeuna/inglesdeuna-container-test, rama main

## Estructura del repo

/
├── Dockerfile                      # PHP 8.2 + Apache + pdo_pgsql
├── index.php                       # entrypoint raíz
├── composer.json
├── scripts/deploy_docker.sh        # carga .env y despliega
├── lessons/lessons/                # el "monolito" — casi toda la app vive aquí
│   ├── core/                       # bootstrap, templates, feedback compartido
│   │   ├── bootstrap.php           # session_start + output buffering (incluido en casi todo)
│   │   ├── db.php
│   │   ├── _activity_viewer_template.php
│   │   ├── _activity_editor_template.php
│   │   ├── _activity_feedback.js   # API unificada: showFeedback, computeScore, showCompleted
│   │   └── ui/                     # activity_header/footer/navigation.php (parciales compartidos)
│   ├── config/                     # db.php, cloudinary.php, security.php, init_db.php, tts_secrets.php
│   ├── academic/                   # dashboard, login, cursos, matrículas, quiz de estudiante/docente
│   │   ├── student_dashboard.php / .css
│   │   ├── teacher_course.php, teacher_unit.php, ...
│   │   └── login.php, login_student.php
│   ├── admin/                      # panel admin (dashboard, flashcards.json, flipbooks.json, etc.)
│   └── activities/                 # los 16+ tipos de actividad — un folder por tipo
│       ├── multiple_choice/        # patrón de referencia (usado para el feedback module)
│       ├── eval/                   # admin_eval.php, eval_viewer.php, exam_question_selector.php
│       ├── quiz/                   # _quiz_lib.php — lógica de scoring compartida por eval y quiz
│       ├── unscramble / unscramble_kids
│       ├── drag_drop / drag_drop_kids
│       ├── roleplay / roleplay_kids
│       ├── free_conversation
│       ├── reading_comprehension
│       ├── hub/                    # index.php, index_english.php — registro central de actividades
│       └── ... (crossword, dictation, dot_to_dot, fillblank, flashcards, flipbooks,
│                hangman, listen_order, match, matching_lines, memory_cards,
│                order_sentences, powerpoint, pronunciation, question_answer,
│                review_match, tracing, video_comprehension, writing_practice)
└── mobile/                         # React Native (Expo) — screens/ y utils/

## Patrón de una actividad (verificado en multiple_choice/ y eval/)

Cada actividad sigue (con variaciones) esta convención de archivos dentro de activities/<nombre>/:

- editor.php — UI de creación/edición para docentes/admin
- viewer.php — UI que ve el estudiante
- tts.php — endpoint que genera audio vía ElevenLabs y sube a Cloudinary
- <nombre>.js / <nombre>.css — lógica y estilos propios de la actividad
- questions.json o similar — datos de ejemplo/semilla
- login.php — algunas actividades tienen su propio guard de sesión

Actividades más complejas (eval, roleplay, roleplay_kids, quiz) añaden archivos propios:
admin_eval.php, eval_results.php, exam_question_selector.php, _quiz_lib.php (compartida entre eval/ y quiz/), etc.

**Registro en el hub:** toda actividad nueva debe añadirse en
lessons/lessons/activities/hub/index.php y index_english.php (mapa "slug" => "Nombre visible").

## Convenciones de código que debes respetar

1. **Bootstrap:** casi todo archivo ejecutable empieza con
   require_once __DIR__ . '/../../core/bootstrap.php'; (o ruta relativa equivalente) — hace session_start() + ob_start().
2. **DB:** nunca abras una conexión nueva a mano — usa require_once .../config/db.php, que expone $pdo (PDO, ERRMODE_EXCEPTION, sslmode=require).
3. **Escape de salida:** patrón local function h(string $v): string { return htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); } — repítelo/reutilízalo, no expongas HTML sin escapar.
4. **Feedback/scoring:** para actividades tipo quiz, usa la API de core/_activity_feedback.js (showFeedback, computeScore, showCompleted) en vez de reinventar UI de resultados — multiple_choice es la implementación de referencia.
5. **Tokens de acceso sin login** (ej. eval_viewer.php?t={token}): valida siempre el token contra la tabla correspondiente y no asumas columnas — el bug histórico más costoso del repo fue un JOIN sin e.unit_id que rompía la validación silenciosamente.
6. **Modo preview para admin/docente:** patrón repetido — session_start(), chequear $_SESSION['admin_logged'] o $_SESSION['academic_logged'], http_response_code(403) + die() si no autorizado.
7. **PRG (Post/Redirect/Get):** usado en editores para evitar reenvío de formularios.

## Sistema de diseño (aplica a las 16 actividades)

- Shell/card blanco #ffffff, sombra rgba(127,119,221,.13)
- Fuentes: Fredoka (títulos), Nunito (cuerpo/botones)
- Naranja #F97316: títulos, botones Prev/Next
- Púrpura #7F77DD: botón Listen/acción, badges, flechas
- Progreso: gradiente naranja→púrpura
- Card: borde #EDE9FA, radio 24px
- Página de finalización: fondo linear-gradient(160deg,#EDE9FA,#FFF0E6), score en Fredoka 52px #F97316
- Tarjeta de feedback en speaking/roleplay: "You said" fondo #F5F3FF borde-izq #7F77DD; "Improvement" fondo #FFF0E6 borde-izq #F97316

## Flujo de trabajo esperado en este repo

1. git checkout main && git pull origin main
2. Cambios
3. Probar localmente con scripts/deploy_docker.sh (requiere .env con DATABASE_URL, ELEVENLABS_API_KEY, CLOUDINARY_*)
4. git add . && git commit -m "mensaje claro" && git push origin main
5. Verificar en Render (deploy automático desde main)

Diagnóstico de variables de entorno en producción: lessons/lessons/academic/env_health.php (requiere sesión admin), agrega ?format=json para salida JSON.

## Qué NO asumir

- No hay framework (no Laravel/Symfony) — es PHP plano, no busques rutas de framework.
- No hay tests automatizados visibles en el repo raíz — valida manualmente o pregunta antes de asumir cobertura.
- El mobile (mobile/) es un proyecto Expo/React Native separado del sitio web — no comparten código directamente.
