#!/usr/bin/env bash
# Instala cron del worker de cola FTP (cada minuto)
WORKER_PATH="/var/www/tjsiddse/workers/ftp-retry-worker.php"
LOG_PATH="/var/www/tjsiddse/logs/ftp-queue-worker.log"
CRON_COMMAND="* * * * * php $WORKER_PATH >> $LOG_PATH 2>&1"

if [ ! -f "$WORKER_PATH" ]; then
  echo "❌ No existe: $WORKER_PATH"
  exit 1
fi

if crontab -l 2>/dev/null | grep -q "$WORKER_PATH"; then
  echo "✅ Cron FTP ya instalado:"
  crontab -l 2>/dev/null | grep "$WORKER_PATH"
  exit 0
fi

(crontab -l 2>/dev/null; echo "$CRON_COMMAND") | crontab -
echo "✅ Cron FTP instalado: $CRON_COMMAND"
