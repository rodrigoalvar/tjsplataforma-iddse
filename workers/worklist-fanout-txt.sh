#!/usr/bin/env bash
#
# Replica TXT desde la carpeta de MensajesRIS hacia el inbox de Worklist.
#
# No mueve ni elimina archivos del origen. Intenta crear un hardlink en el
# inbox de Worklist y, si no es posible (p. ej. distinto filesystem), copia el
# archivo a un nombre temporal y luego lo publica con rename atomico.
#
# Uso:
#   ./worklist-fanout-txt.sh
#
# Variables de entorno:
#   SOURCE_DIR=/var/www/tjsiddse/uploads/mensajesris_net
#   TARGET_DIR=/var/www/tjsiddse/uploads/inboxtxt
#   WORKLIST_FANOUT_BACKFILL=0|1   # 0 por defecto: solo nuevos eventos
#   WORKLIST_FANOUT_WAIT_SEC=0.5   # espera breve antes de link/copiar
#

set -euo pipefail

SOURCE_DIR="${SOURCE_DIR:-/var/www/tjsiddse/uploads/mensajesris_net}"
TARGET_DIR="${TARGET_DIR:-/var/www/tjsiddse/uploads/inboxtxt}"
BACKFILL="${WORKLIST_FANOUT_BACKFILL:-0}"
WAIT_SEC="${WORKLIST_FANOUT_WAIT_SEC:-0.5}"
PHP_BIN="${PHP_BIN:-php}"
ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
TXT_ALLOWED_SCRIPT="${ROOT_DIR}/workers/worklist-txt-ingest-allowed.php"

log() {
  echo "[$(date -Iseconds)] [worklist-fanout-txt] $*"
}

txt_ingest_allowed() {
  if [[ ! -f "$TXT_ALLOWED_SCRIPT" ]]; then
    log "WARN: no existe $TXT_ALLOWED_SCRIPT — se asume TXT permitido"
    return 0
  fi
  "$PHP_BIN" "$TXT_ALLOWED_SCRIPT"
}

is_txt() {
  case "$1" in
    *.txt|*.TXT) return 0 ;;
    *) return 1 ;;
  esac
}

fanout_one() {
  local src="$1"
  local base stamp tmp dest

  [[ -n "$src" ]] || return 0
  is_txt "$src" || return 0

  if ! txt_ingest_allowed; then
    # Canal solo HL7 / none: no copiar al inbox de worklist
    return 0
  fi

  # Nunca operar sobre archivos que no esten en el primer nivel del origen.
  if [[ "$(dirname "$src")" != "$SOURCE_DIR" ]]; then
    return 0
  fi

  sleep "$WAIT_SEC"

  if [[ ! -f "$src" ]]; then
    log "Omitido, el origen ya no existe: $src"
    return 0
  fi

  base="$(basename "$src")"
  stamp="$(date +%Y%m%d_%H%M%S_%N)"
  tmp="${TARGET_DIR}/.${stamp}_${base}.tmp.$$"
  dest="${TARGET_DIR}/${stamp}_${base}"

  rm -f -- "$tmp"

  if ln -- "$src" "$tmp" 2>/dev/null; then
    mv -- "$tmp" "$dest"
    log "Hardlink creado: $src -> $dest"
    return 0
  fi

  log "No se pudo crear hardlink; usando copia: $src"
  if cp --reflink=auto --preserve=timestamps -- "$src" "$tmp"; then
    mv -- "$tmp" "$dest"
    log "Copia creada: $src -> $dest"
    return 0
  fi

  rm -f -- "$tmp"
  log "ERROR: no se pudo replicar TXT hacia Worklist: $src" >&2
  return 1
}

if ! command -v inotifywait >/dev/null 2>&1; then
  echo "Falta inotifywait (paquete inotify-tools)." >&2
  exit 1
fi

if [[ ! -d "$SOURCE_DIR" ]]; then
  echo "No existe directorio origen: $SOURCE_DIR" >&2
  exit 1
fi

mkdir -p -- "$TARGET_DIR"

SOURCE_DIR="$(realpath "$SOURCE_DIR")"
TARGET_DIR="$(realpath "$TARGET_DIR")"

log "Origen TXT: $SOURCE_DIR"
log "Inbox Worklist: $TARGET_DIR"
log "Backfill inicial: $BACKFILL"

if [[ "$BACKFILL" == "1" ]]; then
  log "Procesando TXT existentes antes de iniciar monitoreo"
  while IFS= read -r -d '' existing; do
    fanout_one "$existing" || true
  done < <(find "$SOURCE_DIR" -maxdepth 1 -type f \( -name '*.txt' -o -name '*.TXT' \) -print0)
  log "Backfill inicial finalizado"
fi

inotifywait -m \
  -e close_write \
  -e moved_to \
  --format '%w%f' \
  "$SOURCE_DIR" 2>&1 |
while IFS= read -r filepath; do
  fanout_one "$filepath" || true
done
