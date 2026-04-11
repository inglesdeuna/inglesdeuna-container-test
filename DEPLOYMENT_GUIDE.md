# 🚀 PRESENTATION MODE - Guía de Deployment

## 📋 Resumen de Cambios

| Archivo | Tipo | Acción |
|---------|------|--------|
| `lessons/lessons/core/_activity_viewer_template.php` | MODIFICADO | Detecta presentación + aplica CSS |
| `lessons/lessons/assets/css/presentation-mode.css` | NUEVO | Estilos fullscreen para proyectores |
| `lessons/lessons/activities/writing_practice/viewer.php` | MODIFICADO | Elimina scroll automático + CSS local |

## ✅ Pre-Deploy Checklist

- [ ] Todos los archivos tienen sintaxis PHP válida
- [ ] No hay `Parse error` al abrir los archivos
- [ ] Los paths relativos son correctos
- [ ] El CSS no tiene conflictos
- [ ] Los cambios JavaScript no rompen otras funcionales

## 🔧 Pasos de Deployment

### Paso 1: Backup de Archivos Actuales
```bash
# En el servidor, crear backup de los tres archivos:
cp lessons/lessons/core/_activity_viewer_template.php \
   lessons/lessons/core/_activity_viewer_template.php.backup

cp lessons/lessons/activities/writing_practice/viewer.php \
   lessons/lessons/activities/writing_practice/viewer.php.backup
```

### Paso 2: Copiar/Subir los Archivos Nuevos

**Opción A: SCP/SFTP**
```bash
# Desde tu máquina local:
scp _activity_viewer_template.php usuario@servidor:/path/to/lessons/lessons/core/
scp presentation-mode.css usuario@servidor:/path/to/lessons/lessons/assets/css/
scp writing_practice/viewer.php usuario@servidor:/path/to/lessons/lessons/activities/writing_practice/
```

**Opción B: Git/Control de Versiones**
```bash
git add lessons/lessons/core/_activity_viewer_template.php
git add lessons/lessons/assets/css/presentation-mode.css
git add lessons/lessons/activities/writing_practice/viewer.php
git commit -m "FEATURE: Add presentation mode for large displays (projectors)"
git push origin main
```

**Opción C: FTP Manual**
1. Abre FTP/SFTP cliente (ej: FileZilla)
2. Conecta al servidor
3. Sube el archivo CSS a: `lessons/lessons/assets/css/presentation-mode.css`
4. Sobrescribe: `lessons/lessons/core/_activity_viewer_template.php`
5. Sobrescribe: `lessons/lessons/activities/writing_practice/viewer.php`

### Paso 3: Validar Cambios en Servidor

```bash
# Verificar que los archivos se subieron correctamente:
ls -la /path/to/lessons/lessons/assets/css/presentation-mode.css
ls -la /path/to/lessons/lessons/core/_activity_viewer_template.php
ls -la /path/to/lessons/lessons/activities/writing_practice/viewer.php

# Verificar permisos (deben ser legibles por el servidor web):
# Típicamente 644 para archivos
# Típicamente 755 para directorios
```

### Paso 4: Limpiar Cache

```bash
# Si tienes cache de navegador en servidor (ej: PHP APC, Memcached):
# Limpiarlo para que recarga nuevo CSS

# Opciones:
# 1. Reiniciar el servidor web
# 2. Ejecutar script de limpieza de caché personalizado
# 3. Esperar a que expire (menos ideal)
```

```bash
# Ejemplo con Nginx:
sudo systemctl restart nginx

# Ejemplo con Apache:
sudo systemctl restart apache2

# Ejemplo con PHP-FPM:
sudo systemctl restart php-fpm
```

### Paso 5: Testing en Desarrollo

```bash
# Verificar que el servidor levanta sin errores:
tail -f /var/log/php-fpm/error.log  # o tu log PHP
tail -f /var/log/apache2/error.log  # o tu log web

# No debe haber "Parse error" ni "Fatal error"
```

## 🧪 Testing Post-Deployment

### Test 1: Funcionamiento Básico (Desarrollo)
1. Abre tu navegador
2. Navega a una actividad con `?next=`
3. Verifica:
   - [ ] Page se ve en fullscreen
   - [ ] No hay back button
   - [ ] Botón "siguiente actividad" visible en footer
   - [ ] Títulos escalables

### Test 2: Multiple Choice presentación
```
URL: http://localhost/lessons/lessons/activities/multiple_choice/viewer.php
     ?unit=test&id=1&next=http://localhost/test_next
```

### Test 3: Writing Practice presentación
```
URL: http://localhost/lessons/lessons/activities/writing_practice/viewer.php
     ?unit=test&id=2&next=http://localhost/test_next
```

### Test 4: Responsive en DevTools
```
F12 → Ctrl+Shift+M → Cambiar tamaños:
- 1024x768 (monitor normal)
- 1920x1080 (projector HD)
- 3440x1440 (ultra-wide)
```

### Test 5: Modo Normal (SIN ?next=)
```
URL: http://localhost/lessons/lessons/activities/...
     ?unit=test&id=1
(Sin el ?next=)
```
Debe verse como antes (con back button).

## 🔍 Troubleshooting Post-Deploy

### Problema: "Parse error in _activity_viewer_template.php"
**Causa:** Sintaxis PHP incorrecta
**Solución:**
```bash
# Validar sintaxis en servidor:
php -l /path/to/lessons/lessons/core/_activity_viewer_template.php

# Debe decir: No syntax errors detected
```

**Si hay errores:**
```bash
# Restaurar backup:
cp lessons/lessons/core/_activity_viewer_template.php.backup \
   lessons/lessons/core/_activity_viewer_template.php

# Contactar si necesita revisión
```

### Problema: CSS no se aplica (presentation-mode.css)
**Causa:** Path incorrecto o archivo no existe
**Solución:**
1. Verificar ruta exacta del archivo
2. Verificar permisos de lectura (644)
3. En F12 → Network tab, verificar que presentation-mode.css carga (Status 200)
4. Si 404: revisar ruta relativa en template

### Problema: Botón siguiente no aparece
**Causa:** URL `$nextUrl` vacía o condición falsa
**Solución:**
```php
// En _activity_viewer_template.php, agregar debug:
<?php error_log("nextUrl: $nextUrl"); ?>
<?php error_log("isPresentationMode: " . ($isPresentationMode ? 'true' : 'false')); ?>

// Luego revisar logs:
tail -f /var/log/php-fpm/error.log
```

### Problema: Scroll automático sigue pasando
**Causa:** Script no está encontrando `window.PRESENTATION_MODE`
**Solución:**
1. Abrir F12
2. Consola → `console.log(window.PRESENTATION_MODE)`
3. Si dice `undefined`, es que no se está inyectando la variable
4. Verificar que template PHP esté llegando a navegador

### Problema: Layout roto en mobile
**Causa:** CSS presentation-mode.css se está aplicando donde no debería
**Solución:**
```css
/* El CSS solo debe aplicarse si body tiene clase presentation-mode */
/* Verificar que todo el CSS esté dentro de selectores condicionales */

body.presentation-mode { ... }
/* NO debería haber: body { ... } (sin .presentation-mode) */
```

## 📊 Rollback (si algo sale mal)

```bash
# Opción 1: Restaurar desde backup
cp lessons/lessons/core/_activity_viewer_template.php.backup \
   lessons/lessons/core/_activity_viewer_template.php

cp lessons/lessons/activities/writing_practice/viewer.php.backup \
   lessons/lessons/activities/writing_practice/viewer.php

# Opción 2: Git revert
git revert HEAD
git push origin main

# Opción 3: Borrar archivo CSS nuevo
rm lessons/lessons/assets/css/presentation-mode.css

# Después: Limpiar cache del navegador
# Ctrl+Shift+R (hard refresh)
```

## 📈 Monitoreo Post-Deploy

```bash
# Monitorear logs por errores:
tail -f /var/log/php-fpm/error.log
tail -f /var/log/apache2/error.log

# Patrones a buscar:
# - "presentation-mode" (debe existir)
# - "PRESENTATION_MODE" (debe estar definido)
# - "Fatal error" (no debería existir)

# Monitorear performance:
# - Tamaño del CSS: ~15KB (normal)
# - Tamaño de la template: ~-100 bytes (muy pequeño)
# - Load time: no debería cambiar
```

## 🎓 Documentación para Usuarios

### Para Profesores:
1. Accede a `teacher_presentation.php?unit=UNIT_ID&step=0`
2. Selecciona unidad y presiona "Presentar"
3. Verás la actividad en fullscreen
4. Botón verde "siguiente actividad" en footer
5. Click para ir a siguiente actividad

### Para Estudiantes (cuando ven presentación):
- No verán sidebar
- No verán back button
- Verán fullscreen con preguntas grandes
- Los botones serán más grandes y fáciles de usar

## 🔐 Notas de Seguridad

- ✅ No hay XSS (htmlspecialchars usado correctamente)
- ✅ No hay SQL injection (parámetros via $_GET están bien validados)
- ✅ La redirección `$nextUrl` es segura (verified como relative path)
- ✅ CSS es puro, sin ejecución de código

## 🎉 Próximos Pasos (Opcional)

1. **Pruebas en Proyector Real:** Una vez en producción, probar en un verdadero proyector
2. **Feedback de Usuarios:** Recopilar feedback de profesores sobre tamaños/colores
3. **Futuras Mejoras:**
   - Agregar botón de Presentación fullscreen real (F11)
   - Pointer laser/herramientas de anotación
   - Temporizador para actividades
   - Controles remotos para pasar diapositivas

## 📞 Support & Questions

Si hay problemas:
1. Revisar logs: `/var/log/php-fpm/error.log`
2. Revisar console: F12 → Console
3. Revisar Network: F12 → Network (buscar errores 404)
4. Comparar con versión anterior (usar git diff)

---

**Deployment Checklist - Version 1.0 | Estado: ✅ Ready**
