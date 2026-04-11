# 🎥 PRESENTATION MODE - Guía de Prueba & Uso

## ¿Cómo Usar el Modo Presentación?

### Opción 1: A través de `teacher_presentation.php` (Recomendado)
```
/lessons/lessons/academic/teacher_presentation.php?unit=UNIT_ID&step=0
```

**El flujo automático:**
1. Lee el `unit_id` y `step`
2. Obtiene las actividades de esa unidad
3. Redirige a: `/viewer.php?id=ACTIVITY_ID&next=teacher_presentation.php?unit=UNIT_ID&step=1`
4. El parámetro `next=` activa automáticamente el modo presentación

### Opción 2: Acceso Directo a una Actividad en Modo Presentación
```
/lessons/lessons/activities/writing_practice/viewer.php
  ?unit=UNIT_ID
  &id=ACTIVITY_ID
  &next=/lessons/lessons/academic/teacher_presentation.php?unit=UNIT_ID&step=1
```

## 📺 Layout en Modo Presentación

```
┌────────────────────────────────────────────────────┐
│  [HEADER - Título escalable con clamp]             │  Flex-shrink: 0
├────────────────────────────────────────────────────┤
│                                                     │
│  [CONTENIDO PRINCIPAL]                             │  Flex: 1
│  - Pregunta (clamp: 24px-40px)                     │  Overflow: auto
│  - Opciones/Respuestas (min 70px altura)           │
│  - Videos/Media (responsive)                       │
│                                                     │
├────────────────────────────────────────────────────┤
│ [CONTROLES: Botones grandes]                       │  Flex-shrink: 0
│ [BOTÓN SIGUIENTE ACTIVIDAD - Verde]                │
└────────────────────────────────────────────────────┘
```

## ✨ Características Clave

### Detección Automática
```javascript
// En el template se define:
window.PRESENTATION_MODE = true/false (boolean)
window.PRESENTATION_NEXT_URL = "..." (string)
```

### Prevención de Scroll
```javascript
// En viewer.php al reiniciar:
if (!window.PRESENTATION_MODE) {
    window.scrollTo({ top: 0, behavior: 'smooth' });
}
```

### CSS Escalable
```css
/* Títulos se adaptan al viewport width */
font-size: clamp(28px, 2.5vw, 44px);

/* Preguntas escalables */
font-size: clamp(24px, 2.8vw, 40px);

/* Opciones con altura mínima visible */
min-height: 70px;
```

## 🧪 Casos de Prueba

### Test 1: Actividad Simple (Multiple Choice)
**URL:**
```
/lessons/lessons/activities/multiple_choice/viewer.php
?unit=test_unit
&id=test_activity_1
&next=/lessons/lessons/academic/teacher_presentation.php?unit=test_unit&step=1
```

**Verificar:**
- [ ] Body tiene clase `presentation-mode`
- [ ] No hay back button
- [ ] Botón "siguiente actividad" visible
- [ ] Opciones tienen altura mínima 70px
- [ ] Título escalable (grande en pantalla grande)
- [ ] Sin scroll automático al cambiar

### Test 2: Writing Practice (Escritura)
**URL:**
```
/lessons/lessons/activities/writing_practice/viewer.php
?unit=test_unit
&id=test_activity_2
&next=/lessons/lessons/academic/teacher_presentation.php?unit=test_unit&step=2
```

**Verificar:**
- [ ] Textarea visible sin scroll obligatorio
- [ ] Controles en footer fijo
- [ ] Al hacer restart, no scrollea al top
- [ ] Botón Next estable sin jumps

### Test 3: Video + Preguntas
**URL:**
```
/lessons/lessons/activities/video_comprehension/viewer.php
?unit=test_unit
&id=test_activity_video
&next=/lessons/lessons/academic/teacher_presentation.php?unit=test_unit&step=3
```

**Verificar:**
- [ ] Video ocupa bien el espacio
- [ ] Contenido no se superpone
- [ ] Botones accesibles debajo

### Test 4: Proyector Simulado (1920x1080)
**En Chrome DevTools:**
```
1. F12 → Ctrl+Shift+M (Responsive)
2. Seleccionar: 1920 x 1080
3. Zoom: 100%
4. Verificar legibilidad sin zoom manual
```

### Test 5: Pantalla Ultra-wide (3440x1440)
**En Chrome DevTools:**
```
1. F12 → Ctrl+Shift+M
2. Custom: 3440 x 1440
3. Zoom: 100%
4. Los textos deben estar legibles (no demasiado grandes)
```

## 🔍 Debugging

### Verificar si está en modo Presentación
```javascript
// En consola del navegador (F12):
window.PRESENTATION_MODE
// Debe retornar: true o false

window.PRESENTATION_NEXT_URL
// Debe retornar: la URL siguiente o ""
```

### Ver el state del DOM
```javascript
// Verificar clase del body:
document.body.classList.contains('presentation-mode')
// Debe retornar: true en presentación

// Ver si back button existe:
document.querySelector('.back-btn')
// Debe retornar: null en presentación
```

### Inspeccionar scrolling
```javascript
// Monitorear scroll automático:
window._originalScroll = window.scrollTo;
window.scrollTo = function(opts) {
    console.log('scrollTo called with:', opts);
    window._originalScroll.apply(window, arguments);
};
```

## 📊 Breakpoints Responsivos

| Tamaño | Uso | Comportamiento |
|--------|-----|------------------|
| ≤768px | Tablets/Mobile | Layout vertical, full width |
| 769-1920px | Monitores | Layout normal con constraints |
| 1920-3440px | Proyectores grandes | Fullscreen, textos maximizados |
| >3440px | Ultra-wide | Clamped max, centrado |

## 🎬 Flujo Completo de Presentación

```
[profesor_dashboard.php]
        ↓
[elige unidad + hace click "Presentar"]
        ↓
[teacher_presentation.php?unit=X&step=0]
        ↓
[redirige a activity viewer con ?next=teacher_presentation.php?unit=X&step=1]
        ↓
[viewer.php detecta ?next= → class="presentation-mode"]
        ↓
[Estudiante ve FULLSCREEN - sin sidebar, sin back button]
        ↓
[Click "Siguiente Actividad ▶"]
        ↓
[Va a teacher_presentation.php?unit=X&step=1]
        ↓
[teacher_presentation.php obtiene siguiente actividad]
        ↓
[Redirige a siguiente viewer con ?next=teacher_presentation.php?unit=X&step=2]
        ↓
[Ciclo continúa sin scripts ni jumps]
```

## 📝 Resumen de URLs

### ❌ Modo Normal (Sin Presentación)
```
/activities/writing_practice/viewer.php
?unit=123
&id=activity_abc
```

### ✅ Modo Presentación (Con Presentación)
```
/activities/writing_practice/viewer.php
?unit=123
&id=activity_abc
&next=/academic/teacher_presentation.php?unit=123&step=1
```

**La diferencia:** El parámetro `next=SIGUIENTE_URL`

## 🛠️ Troubleshooting

### Problema: Still viendo back button
**Solución:** Verificar que `?next=` NO esté vacío
```javascript
// Debería ser:
?next=/lessons/lessons/academic/teacher_presentation.php?unit=test&step=1

// NO:
?next=
```

### Problema: Scrolling automático sigue ocurriendo
**Solución:** Revisar que `window.PRESENTATION_MODE` sea `true`
```javascript
// En consola:
console.log(window.PRESENTATION_MODE);
```

### Problema: Textos muy pequeños en proyector
**Solución:** Los `clamp()` se adaptan, pero si son muy pequeños aumentar el `vw` central:
```css
/* Actual: */
font-size: clamp(28px, 2.5vw, 44px);

/* Si es muy pequeño en 1920px: */
font-size: clamp(28px, 3vw, 44px); /* Aumentar de 2.5 a 3 */
```

### Problema: Botones no se ven en footer
**Solución:** Verificar que `.mc-controls` tenga `flex-shrink: 0` en CSS

## 📞 Contacto & Soporte

Para resetear a modo normal sin presentación, simplemente:
```
Quita el parámetro ?next= de la URL
```

---

**Documentación:** Presentation Mode v1.0 | **Estado:** ✅ Active
