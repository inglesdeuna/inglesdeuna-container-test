#!/usr/bin/env bash
set -euo pipefail

# ============================================================
# Deploy completo (build + stop + run) para este proyecto PHP
# Uso:
#   bash scripts/deploy_docker.sh
#   APP_NAME=inglesdeuna PORT=8080 bash scripts/deploy_docker.sh
# ============================================================

APP_NAME="${APP_NAME:-inglesdeuna-app}"
IMAGE_NAME="${IMAGE_NAME:-${APP_NAME}:latest}"
PORT="${PORT:-8080}"
CONTAINER_PORT="${CONTAINER_PORT:-80}"

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

printf '\n[1/5] Proyecto: %s\n' "$ROOT_DIR"
printf '[2/5] Build imagen: %s\n' "$IMAGE_NAME"
docker build -t "$IMAGE_NAME" "$ROOT_DIR"

printf '\n[3/5] Detener contenedor anterior (si existe): %s\n' "$APP_NAME"
if docker ps -a --format '{{.Names}}' | grep -q "^${APP_NAME}$"; then
  docker rm -f "$APP_NAME"
fi

printf '\n[4/5] Levantar contenedor nuevo\n'
ENV_ARGS=()
for key in ELEVENLABS_API_KEY CLOUDINARY_CLOUD_NAME CLOUDINARY_API_KEY CLOUDINARY_API_SECRET DATABASE_URL; do
  if [[ -n "${!key:-}" ]]; then
    ENV_ARGS+=("-e" "$key=${!key}")
  fi
done

docker run -d \
  --name "$APP_NAME" \
  -p "${PORT}:${CONTAINER_PORT}" \
  --restart unless-stopped \
  "${ENV_ARGS[@]}" \
  "$IMAGE_NAME"

printf '\n[5/5] Estado y acceso\n'
docker ps --filter "name=${APP_NAME}"
printf '\n✅ Deploy listo: http://localhost:%s\n\n' "$PORT"
