#!/usr/bin/env bash
#
# Vigila PDF (InformesPdf) y TXT (MensajesRIS) montados en Linux; al cerrarse el archivo
# llama a informes-carpeta-process-one.php.
#
# IMPORTANTE: sobre montajes CIFS/SMB, inotifywait suele NO recibir eventos cuando el archivo
# llega desde Windows u otro host. Usar además: informes-carpeta-poll-recent.php con systemd timer.
#
# Requisitos: inotify-tools, php CLI.
# Opcional: export WATCH_PDF=... WATCH_TXT=... para otras rutas (deben coincidir con configuración).
# Opcional: IC_INOTIFY_RECURSIVE=1 para vigilar subcarpetas (-r; más carga en árboles grandes).
#
# Uso:
#   ./watch-informes-carpeta.sh
#

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PHP_BIN="${PHP_BIN:-php}"
PROCESS_ONE="${SCRIPT_DIR}/informes-carpeta-process-one.php"

WATCH_PDF="${WATCH_PDF:-/var/www/tjsiddse/uploads/informespdf_net}"
WATCH_TXT="${WATCH_TXT:-/var/www/tjsiddse/uploads/mensajesris_net}"

if ! command -v inotifywait >/dev/null 2>&1; then
  echo "Falta inotifywait (paquete inotify-tools)." >&2
  exit 1
fi

for d in "$WATCH_PDF" "$WATCH_TXT"; do
  if [[ ! -d "$d" ]]; then
    echo "No existe directorio: $d" >&2
    exit 1
  fi
done

echo "[$(date -Iseconds)] Vigilando PDF: $WATCH_PDF"
echo "[$(date -Iseconds)] Vigilando TXT: $WATCH_TXT"

INOTIFY_EXTRA=()
if [[ "${IC_INOTIFY_RECURSIVE:-0}" == "1" ]]; then
  INOTIFY_EXTRA=(-r)
  echo "[$(date -Iseconds)] Modo recursivo (-r) activado"
fi

# No silenciar stderr: errores de inotifywait van al journal de systemd
inotifywait -m \
  "${INOTIFY_EXTRA[@]}" \
  -e close_write \
  -e moved_to \
  --format '%w%f' \
  "$WATCH_PDF" \
  "$WATCH_TXT" 2>&1 |
while IFS= read -r filepath; do
  [[ -n "$filepath" ]] || continue
  case "$filepath" in
    *.pdf|*.PDF|*.txt|*.TXT) ;;
    *) continue ;;
  esac

  sleep 0.3
  [[ -f "$filepath" ]] || continue

  echo "[$(date -Iseconds)] Procesando: $filepath"
  if "$PHP_BIN" "$PROCESS_ONE" "$filepath"; then
    :
  else
    echo "[$(date -Iseconds)] Falló: $filepath" >&2
  fi
done
