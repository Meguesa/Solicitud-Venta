#!/usr/bin/env bash
set -euo pipefail

# Ejecutar exclusivamente desde _deploy_qa/ creado por el builder QA.
SCOPE="${QA_DEPLOY_SCOPE:-diagnostico}"
DRY_RUN="${QA_DEPLOY_DRY_RUN:-0}"
case "$SCOPE" in
  diagnostico|completo) ;;
  *) echo "QA: alcance no permitido: $SCOPE" >&2; exit 2 ;;
esac
case "$DRY_RUN" in
  0|1) ;;
  *) echo "QA: dry-run invalido" >&2; exit 2 ;;
esac

test -s "api/solicitud-venta-qa/_qa_guard.php"
test -s "api/solicitud-venta-qa/_common.php"
test -s "solicitud-venta-qa/diagnostico.php"
test -s "solicitud-venta-qa/prueba-escritura.php"
test -s "solicitud-venta-qa/prueba-lista.php"
test -s "solicitud-venta-qa/diagnostico-esquema.php"
test -s "solicitud-venta-qa/ajustar-esquema.php"
test ! -e "api/solicitud-venta/_common.php"
test ! -e "solicitud-venta/index.php"
test ! -e "firma/index.html"

TMP_DIR="$(mktemp -d)"
trap 'rm -rf "$TMP_DIR"' EXIT
BATCH="$TMP_DIR/qa.lftp"
: > "$BATCH"
declare -A DIRS=()
COUNT=0

assert_remote_qa() {
  local path="$1"
  case "$path" in
    solicitud-venta-qa/*|api/solicitud-venta-qa/*|firma-qa/*) ;;
    *) echo "QA: destino fuera de ambiente aislado: $path" >&2; exit 3 ;;
  esac
  if [[ "$path" == *".."* || "$path" == *"//"* || ! "$path" =~ ^[a-zA-Z0-9_./-]+$ ]]; then
    echo "QA: ruta remota invalida" >&2
    exit 3
  fi
}

append_file() {
  local local_file="$1" remote_file="$2" parent
  test -f "$local_file" && test -s "$local_file" && test ! -L "$local_file"
  [[ "$local_file" == "$remote_file" ]] || {
    echo "QA: el archivo local debe coincidir con su ruta de destino" >&2
    exit 3
  }
  assert_remote_qa "$remote_file"
  parent="${remote_file%/*}"
  if [[ -z "${DIRS[$parent]+present}" ]]; then
    # lftp mkdir -p puede devolver 550 "File exists" si el directorio ya existe.
    # Primero verificarlo sin cambiar el directorio remoto; crearlo solo si falta.
    # -f tolera respuestas 550 de "File exists"; la segunda verificacion exige
    # que el directorio realmente sea consultable antes de subir archivos.
    printf "cls -d '%s' > /dev/null || mkdir -pf '%s'\n" "$parent" "$parent" >> "$BATCH"
    printf "cls -d '%s' > /dev/null\n" "$parent" >> "$BATCH"
    DIRS[$parent]=1
  fi
  printf "put '%s' -o '%s'\n" "$local_file" "$remote_file" >> "$BATCH"
  COUNT=$((COUNT + 1))
}

if [[ "$SCOPE" == "diagnostico" ]]; then
  append_file "api/solicitud-venta-qa/_qa_guard.php" "api/solicitud-venta-qa/_qa_guard.php"
  append_file "api/solicitud-venta-qa/_common.php" "api/solicitud-venta-qa/_common.php"
  append_file "solicitud-venta-qa/diagnostico.php" "solicitud-venta-qa/diagnostico.php"
  append_file "solicitud-venta-qa/prueba-escritura.php" "solicitud-venta-qa/prueba-escritura.php"
  append_file "solicitud-venta-qa/prueba-lista.php" "solicitud-venta-qa/prueba-lista.php"
  append_file "solicitud-venta-qa/diagnostico-esquema.php" "solicitud-venta-qa/diagnostico-esquema.php"
  append_file "solicitud-venta-qa/ajustar-esquema.php" "solicitud-venta-qa/ajustar-esquema.php"
else
  for folder in "solicitud-venta-qa" "api/solicitud-venta-qa" "firma-qa"; do
    while IFS= read -r -d '' file; do
      append_file "$file" "$file"
    done < <(find "$folder" -type f -print0 | sort -z)
  done
fi
test "$COUNT" -gt 0
echo "QA: alcance=$SCOPE; archivos autorizados=$COUNT; rutas privadas y sin eliminaciones."

if [[ "$DRY_RUN" == "1" ]]; then
  echo "QA: DRY RUN, no se ha conectado a cPanel:"
  cat "$BATCH"
  exit 0
fi

: "${FTP_SERVER:?QA: FTP_SERVER no configurado}"
: "${FTP_USERNAME:?QA: FTP_USERNAME no configurado}"
: "${FTP_PASSWORD:?QA: FTP_PASSWORD no configurado}"

COMMON="$TMP_DIR/common.lftp"
cat > "$COMMON" <<'LFTP'
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

for attempt in 1 2 3; do
  echo "QA: FTPS, intento $attempt de 3"
  if {
    cat "$COMMON"
    cat "$BATCH"
    printf 'bye\n'
  } | timeout --kill-after=10s 240s lftp -u "$FTP_USERNAME","$FTP_PASSWORD" "ftp://$FTP_SERVER:21"; then
    echo "QA: archivos publicados solo en rutas QA."
    exit 0
  fi
  if [[ "$attempt" -lt 3 ]]; then sleep "$((attempt * 5))"; fi
done
echo "QA: fallo FTPS; no se ejecutaron operaciones de borrado." >&2
exit 1
