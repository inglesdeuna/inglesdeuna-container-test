# 📝 PRESENTATION MODE - Código & Cambios Clave

## 1️⃣ DETECCIÓN DE PRESENTACIÓN (Template)

**Archivo:** `lessons/lessons/core/_activity_viewer_template.php`

### Antes:
```php
function render_activity_viewer($title, $icon, $content)
{
    $unit = isset($_GET['unit']) ? trim((string) $_GET['unit']) : '';
    $assignment = isset($_GET['assignment']) ? trim((string) $_GET['assignment']) : '';
    // ... sin detección de presentación
}
```

### Después:
```php
function render_activity_viewer($title, $icon, $content)
{
    $unit = isset($_GET['unit']) ? trim((string) $_GET['unit']) : '';
    $assignment = isset($_GET['assignment']) ? trim((string) $_GET['assignment']) : '';
    $source = isset($_GET['source']) ? trim((string) $_GET['source']) : '';
    $embedded = isset($_GET['embedded']) && (string) $_GET['embedded'] === '1';
    
    // 🆕 NUEVO: Detectar modo presentación
    $nextUrl = isset($_GET['next']) ? trim((string) $_GET['next']) : '';
    $isPresentationMode = $nextUrl !== '';
    
    // ... resto del código
}
```

**¿Qué hace?**
- Detecta si hay parámetro `?next=`
- Si existe, activa modo presentación
- Guarda la URL siguiente para navegación

---

## 2️⃣ HTML CON CLASE PRESENTATION-MODE

**Archivo:** `lessons/lessons/core/_activity_viewer_template.php`

### Antes:
```html
<body>

<div class="activity-wrapper">

    <?php if (!$embedded) { ?>
    <div class="top-row">
        <a href="<?= htmlspecialchars($backUrl, ENT_QUOTES, 'UTF-8') ?>" class="back-btn">↩ Back</a>
    </div>
    <?php } ?>

    <div class="viewer-content">
        <?= $content ?>
    </div>

</div>

</body>
```

### Después:
```html
<body<?= $isPresentationMode ? ' class="presentation-mode"' : '' ?>>

<div class="activity-wrapper">

    <?php if (!$embedded && !$isPresentationMode) { ?>
    <div class="top-row">
        <a href="<?= htmlspecialchars($backUrl, ENT_QUOTES, 'UTF-8') ?>" class="back-btn">↩ Back</a>
    </div>
    <?php } ?>

    <div class="viewer-content">
        <?= $content ?>
        
        <?php if ($isPresentationMode && $nextUrl !== '') { ?>
        <div style="flex-shrink: 0; padding: 12px 16px; background: #f8fbff; border-top: 1px solid #e5e7eb; display: flex; justify-content: center;">
            <a href="<?= htmlspecialchars($nextUrl, ENT_QUOTES, 'UTF-8') ?>" class="pres-next-button">
                <span>siguiente actividad</span>
                <span style="font-size: 18px;">▶</span>
            </a>
        </div>
        <?php } ?>
    </div>

</div>

<script>
// 🆕 NUEVO: Variables globales para JavaScript
window.PRESENTATION_MODE = <?= json_encode($isPresentationMode) ?>;
window.PRESENTATION_NEXT_URL = <?= json_encode($nextUrl) ?>;

// 🆕 NUEVO: Prevenir scrolls automáticos
if (window.PRESENTATION_MODE) {
    var originalScrollTo = window.scrollTo;
    window.scrollTo = function() {
        // Silenciar smooth scrolls en presentación
        if (arguments.length > 0 && typeof arguments[0] === 'object' && arguments[0].behavior === 'smooth') {
            return; // Ignorar
        }
        originalScrollTo.apply(window, arguments);
    };
}
</script>

</body>
```

**¿Qué hace?**
- `class="presentation-mode"` en body → dispara CSS de presentación
- Obtiene el parámetro `class` solo si NO hay presentación (oculta en presentación)
- Agrega botón verde "siguiente actividad" en el footer
- Define `window.PRESENTATION_MODE` globalmente
- Override `window.scrollTo()` para silenciar smooth scrolls

---

## 3️⃣ CSS IMPORT (Template)

**Archivo:** `lessons/lessons/core/_activity_viewer_template.php`

### Nuevo:
```html
<head>
    <meta charset="UTF-8">
    <title><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <!-- 🆕 NUEVO: CSS de Presentación -->
    <link rel="stylesheet" href="../../assets/css/presentation-mode.css">
    
    <style>
        /* ... rest of styles ... */
    </style>
</head>
```

**¿Qué hace?**
- Importa el CSS global de presentación
- Se aplica solo cuando el body tiene clase `presentation-mode`

---

## 4️⃣ CSS PRESENTATION MODE (Global)

**Archivo:** `lessons/lessons/assets/css/presentation-mode.css` (NUEVO)

### Estructura Base:
```css
/* Fullscreen layout */
body.presentation-mode {
    margin: 0;
    padding: 0;
    background: #000;
    overflow: hidden !important;
    height: 100vh;
    width: 100vw;
}

body.presentation-mode .activity-wrapper {
    max-width: 100%;  /* NO hay máximo */
    width: 100vw;     /* Toma todo el ancho */
    height: 100vh;    /* Toma todo el alto */
    margin: 0;
    padding: 0;
    display: flex;
    flex-direction: column;  /* Apila: header, content, footer */
}

body.presentation-mode .viewer-content {
    flex: 1;          /* Toma espacio disponible */
    overflow: hidden; /* NO scroll sin necesidad */
    display: flex !important;
    flex-direction: column !important;
}
```

### Encabezado - Flex-shrink: 0 (No encoge)
```css
body.presentation-mode .viewer-content :is(.mc-intro, .dd-intro, .lo-intro, etc) {
    margin: 0 !important;
    padding: 14px 18px !important;
    flex-shrink: 0 !important;  /* 🔑 Clave: NO encoge */
    background: linear-gradient(180deg, #f8fbff 0%, #ffffff 100%) !important;
    border-bottom: 2px solid #e0e7ff !important;
}

body.presentation-mode .viewer-content :is(.mc-intro h2, .dd-intro h2, etc) {
    font-size: clamp(28px, 2.5vw, 44px) !important;  /* Escalable */
    line-height: 1.1 !important;
    margin: 0 !important;
    text-align: center !important;
    color: #5b21b6 !important;
}
```

### Contenido - Flex: 1 (Expande)
```css
body.presentation-mode .viewer-content :is(.mc-card, #sentenceBox, etc) {
    padding: 0 !important;
    flex: 1 !important;           /* 🔑 Clave: Toma espacio disponible */
    overflow-y: auto !important;  /* Scroll SOLO si necesario */
    overflow-x: hidden !important;
    background: #fff !important;
}

body.presentation-mode .viewer-content :is(.mc-question, #promptText, etc) {
    font-size: clamp(24px, 2.8vw, 40px) !important;  /* Escalable */
    line-height: 1.3 !important;
    padding: 20px 24px !important;
    text-align: center !important;
}

body.presentation-mode .viewer-content :is(.mc-option, .vc-option) {
    min-height: 70px !important;  /* 🔑 Grande y clickeable */
    font-size: clamp(18px, 2.2vw, 32px) !important;
    padding: 18px 20px !important;
}
```

### Controles - Flex-shrink: 0 (No encoge)
```css
body.presentation-mode .viewer-content :is(.mc-controls, .controls, etc) {
    gap: 12px !important;
    flex-shrink: 0 !important;    /* 🔑 Clave: Siempre visible */
    padding: 14px 18px !important;
    background: linear-gradient(180deg, #f8fbff 0%, #ffffff 100%) !important;
    border-top: 2px solid #e0e7ff !important;
    display: flex !important;
    justify-content: center !important;
    align-items: center !important;
}

body.presentation-mode .viewer-content :is(.mc-btn, .dd-btn, etc) {
    padding: 16px 22px !important;     /* 🔑 GRANDE */
    min-width: 160px !important;
    font-size: 16px !important;
    font-weight: 800 !important;
    border-radius: 10px !important;
}
```

### Botón Siguiente - Estilos especiales
```css
body.presentation-mode .pres-next-button {
    background: linear-gradient(180deg, #22c55e 0%, #16a34a 100%) !important;
    color: #fff !important;
    padding: 16px 28px !important;
    font-size: 17px !important;
    font-weight: 800 !important;
    border-radius: 10px !important;
    min-width: 200px !important;
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    gap: 10px !important;
    box-shadow: 0 8px 16px rgba(34, 197, 94, 0.3) !important;
    transition: all 0.2s ease !important;
}

body.presentation-mode .pres-next-button:hover {
    filter: brightness(1.08) !important;
    transform: translateY(-2px) !important;
    box-shadow: 0 12px 24px rgba(34, 197, 94, 0.4) !important;
}
```

---

## 5️⃣ ELIMINACIÓN DE SCROLL AUTOMÁTICO

**Archivo:** `lessons/lessons/activities/writing_practice/viewer.php`

### Antes:
```javascript
restartBtn.addEventListener('click', function () {
    submitted = false;
    submitBtn.disabled = false;
    // ...reiniciar todo...
    window.scrollTo({ top: 0, behavior: 'smooth' });  // ❌ Siempre scrollea
});
```

### Después:
```javascript
restartBtn.addEventListener('click', function () {
    submitted = false;
    submitBtn.disabled = false;
    // ...reiniciar todo...
    // ✅ NUEVO: Solo scrollear si no está en presentación
    if (!window.PRESENTATION_MODE) {
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }
});
```

**¿Qué hace?**
- Revisa la variable global `window.PRESENTATION_MODE`
- Si está en presentación, NO ejecuta el scroll
- Si no está en presentación, scrollea normalmente

---

## 6️⃣ PODER DE CLAMP()

### CSS Escalable para Todos los Tamaños:
```css
/* Títulos */
font-size: clamp(28px, 2.5vw, 44px);
/* En 1920px: ~76px */
/* En 3440px: ~86px (clamped a 44px) */

/* Preguntas */
font-size: clamp(24px, 2.8vw, 40px);
/* En 1920px: ~77px */
/* En 3440px: ~96px (clamped a 40px) */

/* Opciones */
font-size: clamp(18px, 2.2vw, 32px);
/* En 1920px: ~64px */
/* En 3440px: ~75px (clamped a 32px) */
```

**Fórmula:** `clamp(MIN_FIXED, RELATIVE_VW, MAX_FIXED)`
- MIN: tamaño mínimo para pantallas pequeñas
- RELATIVE: % del viewport width (se adapta)
- MAX: tamaño máximo para pantallasgigantes

---

## 7️⃣ FLUJO DE CONTROL

```
┌─────────────────────────────────────────┐
│ URL tiene ?next=SIGUIENTE_URL           │
└─────────────────────────────────────────┘
              ↓
┌─────────────────────────────────────────┐
│ PHP: $isPresentationMode = true         │
│ PHP: $nextUrl = "..."                   │
└─────────────────────────────────────────┘
              ↓
┌─────────────────────────────────────────┐
│ <body class="presentation-mode">         │
│ window.PRESENTATION_MODE = true          │
│ window.PRESENTATION_NEXT_URL = "..."     │
└─────────────────────────────────────────┘
              ↓
┌─────────────────────────────────────────┐
│ CSS presentation-mode.css se aplica     │
│ - 100vw x 100vh                         │
│ - Flex layout: thead | content | footer │
│ - Sin margin/padding/border              │
└─────────────────────────────────────────┘
              ↓
┌─────────────────────────────────────────┐
│ JavaScript check window.PRESENTATION_   │
│ MODE al hacer scrollTo()                │
│ - Si true: ignora smooth scrolls        │
│ - Si false: ejecuta normal              │
└─────────────────────────────────────────┘
              ↓
┌─────────────────────────────────────────┐
│ Usuario ve FULLSCREEN en 100vh          │
│ - Click "siguiente actividad ▶"         │
│ - Va a ?next= URL sin jumps             │
└─────────────────────────────────────────┘
```

---

## 📦 Resumen de Cambios

| Componente | Qué cambió | Por qué |
|------------|-----------|--------|
| Template PHP | Detección `$nextUrl`, `$isPresentationMode` | Activar modo |
| HTML Body | Clase `presentation-mode` condicional | CSS targeting |
| HTML Back | Oculto si `$isPresentationMode` | Más espacio |
| HTML Footer | Botón verde si hay `$nextUrl` | Navegación |
| JavaScript | Override `window.scrollTo()` | Prevenir jumps |
| JavaScript | `window.PRESENTATION_MODE` global | Usar en viewers |
| CSS | presentation-mode.css link | Importar estilos |
| CSS | presentation-mode.css styles | Fullscreen layout |
| Writing Practice | Check `!window.PRESENTATION_MODE` | Condicional scroll |
| Writing Practice | CSS local de presentación | Ajustes específicos |

---

## 🔄 ¿Cómo Funciona en Ciclo?

```javascript
// 1. Entrada
GET /viewer.php?id=1&next=/teacher_presentation.php?unit=X&step=1

// 2. PHP Detecta
$nextUrl = "/teacher_presentation.php?unit=X&step=1"
$isPresentationMode = true

// 3. HTML Genera
<body class="presentation-mode">
window.PRESENTATION_MODE = true
window.PRESENTATION_NEXT_URL = "/teacher_presentation.php?..."

// 4. CSS Aplica
body.presentation-mode .viewer-content { flex: 1; }
body.presentation-mode .mc-option { min-height: 70px; }

// 5. JavaScript Controla
if (!window.PRESENTATION_MODE) { window.scrollTo(...) }

// 6. Usuario Navega
Click → href="<?= $nextUrl ?>"

// 7. Siguiente Actividad
GET /teacher_presentation.php?unit=X&step=1

// Loop continúa...
```

---

## ✅ Validación

Para verificar que todo funciona:

```javascript
// En consola (F12):

// 1. ¿Estamos en presentación?
console.log(window.PRESENTATION_MODE); // debe ser true

// 2. ¿Cuál es la siguiente URL?
console.log(window.PRESENTATION_NEXT_URL); // debe tener URL

// 3. ¿El body tiene la clase?
console.log(document.body.className); // debe tener "presentation-mode"

// 4. ¿El back button está oculto?
console.log(document.querySelector('.back-btn')); // debe ser null

// 5. ¿El botón siguiente existe?
console.log(document.querySelector('.pres-next-button')); // debe existir
```

---

**Documentación Técnica - Version 1.0 | Estado: ✅ Complete**
