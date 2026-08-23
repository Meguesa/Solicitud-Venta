#!/usr/bin/env bash
set -euo pipefail

: "${FTP_SERVER:?FTP_SERVER no configurado}"
: "${FTP_USERNAME:?FTP_USERNAME no configurado}"
: "${FTP_PASSWORD:?FTP_PASSWORD no configurado}"

DEPLOY_SCOPE="${SOLICITUD_DEPLOY_SCOPE:-completo}"

case "$DEPLOY_SCOPE" in
  interfaz|ui)
    DEPLOY_SCOPE="ui"
    ;;
  correccion|correction)
    DEPLOY_SCOPE="correction"
    ;;
  sesion|session)
    DEPLOY_SCOPE="session"
    ;;
  completo|full)
    DEPLOY_SCOPE="full"
    ;;
  *)
    echo "SOLICITUD_DEPLOY_SCOPE invalido: ${DEPLOY_SCOPE}."
    exit 2
    ;;
esac

TMP_DIR="$(mktemp -d)"
trap 'rm -rf "$TMP_DIR"' EXIT

COMMON_FILE="$TMP_DIR/common.lftp"
cat > "$COMMON_FILE" <<'LFTP'
set cmd:fail-exit true
set ftp:passive-mode true
set ftp:ssl-force true
set ftp:ssl-protect-data true
set ftp:ssl-auth TLS
set ftp:sync-mode false
set ssl:verify-certificate true
set ssl:check-hostname true
set net:timeout 30
set net:max-retries 0
LFTP

assert_remote_allowed() {
  local remote="$1"
  case "$remote" in
    solicitud-venta/*|api/solicitud-venta/*|firma/*)
      return 0
      ;;
    *)
      echo "ERROR DE SEGURIDAD: ruta remota fuera de Solicitud de Venta: $remote"
      return 1
      ;;
  esac
}

append_tree() {
  local source_dir="$1"
  local remote_prefix="$2"
  local batch_file="$3"

  while IFS= read -r file; do
    local relative="${file#${source_dir}/}"
    local remote="${remote_prefix}/${relative}"
    assert_remote_allowed "$remote"
    printf "put '%s' -o '%s'\n" "$file" "$remote" >> "$batch_file"
  done < <(find "$source_dir" -type f | sort)
}

append_file() {
  local local_file="$1"
  local remote_file="$2"
  local batch_file="$3"

  test -s "$local_file"
  assert_remote_allowed "$remote_file"
  printf "put '%s' -o '%s'\n" "$local_file" "$remote_file" >> "$batch_file"
}

run_batch() {
  local label="$1"
  local timeout_seconds="$2"
  local batch_file="$3"
  local code=0

  if [ ! -s "$batch_file" ]; then
    echo "[$label] no hay archivos que publicar."
    return 0
  fi

  for attempt in 1 2 3; do
    echo "[$label] intento ${attempt}/3"

    if {
      cat "$COMMON_FILE"
      cat "$batch_file"
      printf '%s\n' 'bye'
    } | timeout --kill-after=10s "${timeout_seconds}s" lftp -u "$FTP_USERNAME","$FTP_PASSWORD" "ftp://$FTP_SERVER:21"; then
      echo "[$label] publicado correctamente."
      return 0
    else
      code=$?
    fi

    echo "[$label] fallo o agoto ${timeout_seconds}s (codigo ${code})."
    if [ "$attempt" -lt 3 ]; then
      sleep $((attempt * 6))
    fi
  done

  echo "[$label] ERROR despues de 3 intentos."
  return 1
}

UI_BATCH="$TMP_DIR/ui.lftp"
API_BATCH="$TMP_DIR/api.lftp"
FIRMA_BATCH="$TMP_DIR/firma.lftp"
CORRECTION_BATCH="$TMP_DIR/correction.lftp"
: > "$UI_BATCH"
: > "$API_BATCH"
: > "$FIRMA_BATCH"
: > "$CORRECTION_BATCH"

append_tree "solicitud-venta" "solicitud-venta" "$UI_BATCH"
append_tree "api/solicitud-venta" "api/solicitud-venta" "$API_BATCH"
append_tree "firma" "firma" "$FIRMA_BATCH"

append_file "solicitud-venta/index.html" "solicitud-venta/index.html" "$CORRECTION_BATCH"
append_file "solicitud-venta/correccion-validar-fix.js" "solicitud-venta/correccion-validar-fix.js" "$CORRECTION_BATCH"
append_file "solicitud-venta/correccion-fix.js" "solicitud-venta/correccion-fix.js" "$CORRECTION_BATCH"
append_file "api/solicitud-venta/reabrir-correccion.php" "api/solicitud-venta/reabrir-correccion.php" "$CORRECTION_BATCH"

echo "Alcance de despliegue: $DEPLOY_SCOPE"

echo "Proteccion activa: solo se permiten rutas solicitud-venta/, api/solicitud-venta/ y firma/."

if [ "$DEPLOY_SCOPE" = "correction" ]; then
  run_batch "Solicitud correccion" 120 "$CORRECTION_BATCH"
  exit 0
fi

if [ "$DEPLOY_SCOPE" = "ui" ] || [ "$DEPLOY_SCOPE" = "full" ]; then
  run_batch "Solicitud interfaz" 240 "$UI_BATCH"
fi

if [ "$DEPLOY_SCOPE" = "ui" ]; then
  echo "Interfaz publicada sin tocar backend ni firma publica."
  exit 0
fi

run_batch "Solicitud backend completo" 300 "$API_BATCH"

if [ "$DEPLOY_SCOPE" = "session" ]; then
  echo "Backend publicado sin tocar interfaz ni firma publica."
  exit 0
fi

run_batch "Solicitud firma publica" 120 "$FIRMA_BATCH"

echo "Solicitud de Venta publicada completamente desde su repositorio propio."
