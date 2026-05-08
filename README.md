# inglesdeuna-container-test
Sandbox contenedor InglesDeUna – pruebas Hangman

## Guía rápida: que los cambios sí aparezcan en GitHub

Si no ves cambios en GitHub, este es el flujo obligatorio en tu máquina local:

1. `git checkout main`
2. `git pull origin main`
3. aplicar cambios
4. `git add .`
5. `git commit -m "mensaje claro"`
6. `git push origin main`
7. recargar la página del repo en GitHub

---

## PostgreSQL en DBeaver (sin error de base inexistente)

El error `database "inglesdeuna" does not exist` aparece cuando se corre `GRANT` antes de crear la base, o cuando se ejecuta en la conexión equivocada.

### Paso 1: crear usuario/base (conectado a `postgres`)

- Abre DBeaver y conéctate a una base existente: `postgres`.
- Ejecuta completo: `scripts/dbeaver_step1_server.sql`.
- Si la consulta de verificación no devuelve filas, descomenta y ejecuta:
  `CREATE DATABASE inglesdeuna OWNER inglesdeuna_user;`

### Paso 2: permisos internos (conectado a `inglesdeuna`)

- Abre una nueva consola SQL, pero ahora sobre la base `inglesdeuna`.
- Ejecuta completo: `scripts/dbeaver_step2_database.sql`.

### DATABASE_URL para la app

```bash
DATABASE_URL=postgres://inglesdeuna_user:cambia_esta_clave@localhost:5432/inglesdeuna
```

## Variables para TTS (ElevenLabs + Cloudinary)

Para generar audio desde actividades como listen_order, fillblank y order_sentences, define estas variables antes de desplegar:

```bash
export ELEVENLABS_API_KEY="tu_api_key_de_elevenlabs"
export CLOUDINARY_CLOUD_NAME="tu_cloud_name"
export CLOUDINARY_API_KEY="tu_cloudinary_api_key"
export CLOUDINARY_API_SECRET="tu_cloudinary_api_secret"
export DATABASE_URL="postgres://inglesdeuna_user:cambia_esta_clave@localhost:5432/inglesdeuna"

bash scripts/deploy_docker.sh
```

Si no exportas `ELEVENLABS_API_KEY`, el generador mostrara:
`ElevenLabs API key not configured. Set the ELEVENLABS_API_KEY environment variable.`

Tambien puedes crear un archivo `.env` en la raiz del proyecto con esas mismas variables.
El script `scripts/deploy_docker.sh` lo carga automaticamente si existe.

### Diagnostico rapido en navegador

Con sesion de admin iniciada, abre:

- `lessons/lessons/academic/env_health.php`

Muestra el estado `present`/`missing` para variables requeridas sin exponer valores secretos.
Version JSON:

- `lessons/lessons/academic/env_health.php?format=json`
