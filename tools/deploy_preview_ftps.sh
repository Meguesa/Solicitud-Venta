#!/usr/bin/env bash
set -euo pipefail

: "${FTP_SERVER:?FTP_SERVER no configurado}"
: "${FTP_USERNAME:?FTP_USERNAME no configurado}"
: "${FTP_PASSWORD:?FTP_PASSWORD no configurado}"

SOURCE_DIR="_preview/solicitud-venta-preview"
REMOTE_DIR="solicitud-venta-preview"

test -d "$SOURCE_DIR"
test -s "$SOURCE_DIR/index.php"
test -s "$SOURCE_DIR/preview-guard.js"

TMP_DIR="$(mktemp -d)"
trap 'rm -rf "$TMP_DIR"' EXIT

BATCH="$TMP_DIR/preview.lftp"
cat > "$BATCH" <<'LFTP'
set cmd:fail-exit true
set ftp:passive-mode true
set ftp:ssl-force true
set ftp:ssl-protect-data true
set ftp:ssl-auth TLS
set ftp:sync-mode false
set ssl:verify-certificate true
set ssl:check-hostname true
set net:timeout 30
set net:max-retries 1
LFTP

declare -A CREATED_DIRS
CREATED_DIRS["solicitud-venta-preview"]=1

# cPanel ya puede contener la carpeta Preview. lftp devuelve 550 cuando
# mkdir intenta crear una carpeta existente, asi que desactivamos fail-exit
# solo durante la preparacion de directorios.
printf '%s\n' 'set cmd:fail-exit false' >> "$BATCH"
printf '%s\n' 'mkdir solicitud-venta-preview' >> "$BATCH"

PUTS="$TMP_DIR/puts.lftp"
: > "$PUTS"

while IFS= read -r file; do
  relative="${file#${SOURCE_DIR}/}"
  remote="${REMOTE_DIR}/${relative}"
  case "$remote" in
    solicitud-venta-preview/*) ;;
    *)
      echo "ERROR: ruta fuera del Preview: $remote"
      exit 1
      ;;
  esac
  remote_parent="${remote%/*}"
  if [ -z "${CREATED_DIRS[$remote_parent]+x}" ]; then
    printf "mkdir -p '%s'\n" "$remote_parent" >> "$BATCH"
    CREATED_DIRS["$remote_parent"]=1
  fi
  printf "put '%s' -o '%s'\n" "$file" "$remote" >> "$PUTS"
done < <(find "$SOURCE_DIR" -type f | sort)

printf '%s\n' 'set cmd:fail-exit true' >> "$BATCH"
cat "$PUTS" >> "$BATCH"
printf '%s\n' 'bye' >> "$BATCH"

echo "Publicando exclusivamente en /solicitud-venta-preview/"

for attempt in 1 2 3; do
  echo "Intento ${attempt}/3"
  if timeout --kill-after=10s 300s lftp -u "$FTP_USERNAME","$FTP_PASSWORD" "ftp://$FTP_SERVER:21" < "$BATCH"; then
    echo "Preview publicado correctamente."
    exit 0
  fi
  if [ "$attempt" -lt 3 ]; then sleep $((attempt * 5)); fi
done

echo "ERROR: no fue posible publicar el Preview."
exit 1
