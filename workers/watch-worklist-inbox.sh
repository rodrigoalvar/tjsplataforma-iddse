#!/usr/bin/env bash
#
# Vigila una carpeta con inotifywait: al cerrarse un .txt (o moverse ahí),
# procesa con worklist-process-one.php y mueve a processed/failed según resultado.
#
# Requisitos: inotify-tools (inotifywait), php CLI.
#
# Uso:
#   ./watch-worklist-inbox.sh /ruta/inbox
#   WATCH_DIR=/ruta/inbox ./watch-worklist-inbox.sh
#
# Systemd ejemplo (servicio):
#   ExecStart=/var/www/tjsiddse/workers/watch-worklist-inbox.sh /var/spool/worklist-portal/inbox
#

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
PHP_BIN="${PHP_BIN:-php}"
PROCESS_ONE="${SCRIPT_DIR}/worklist-process-one.php"

WATCH_DIR="${1:-${WATCH_DIR:-}}"

if [[ -z "$WATCH_DIR" ]]; then
  echo "Uso: $0 <directorio-inbox>" >&2
  echo "   o: WATCH_DIR=/ruta/inbox $0" >&2
  exit 1
fi

if ! command -v inotifywait >/dev/null 2>&1; then
  echo "Falta inotifywait (paquete inotify-tools en Debian/Ubuntu)." >&2
  exit 1
fi

if [[ ! -d "$WATCH_DIR" ]]; then
  echo "No existe el directorio: $WATCH_DIR" >&2
  exit 1
fi

WATCH_DIR="$(realpath "$WATCH_DIR")"

echo "[$(date -Iseconds)] Vigilando TXT en: $WATCH_DIR"
echo "[$(date -Iseconds)] Procesador: $PROCESS_ONE"

# close_write: escritura terminada
# moved_to: renombre atómico desde .tmp → .txt
inotifywait -m \
  -e close_write \
  -e moved_to \
  --format '%w%f' \
  "$WATCH_DIR" 2>/dev/null |
while IFS= read -r filepath; do
  [[ -n "$filepath" ]] || continue
  case "$filepath" in
    *.txt|*.TXT) ;;
    *) continue ;;
  esac

  # No re-procesar si cae dentro de subcarpetas processed/failed bajo el inbox
  if [[ "$filepath" == *'/processed/'* || "$filepath" == *'/failed/'* ]]; then
    continue
  fi

  # El kernel puede disparar antes de que el archivo sea estable: breve espera
  sleep 0.3
  [[ -f "$filepath" ]] || continue

  echo "[$(date -Iseconds)] Procesando: $filepath"
  if "$PHP_BIN" "$PROCESS_ONE" "$filepath"; then
    :
  else
    echo "[$(date -Iseconds)] Falló procesamiento: $filepath" >&2
  fi
done
