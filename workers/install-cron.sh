#!/bin/bash
# Script para instalar/verificar el cron job del worker de transcripción
# Sistema TJSMEDICAL - Portal de Estudios Médicos

WORKER_PATH="/var/www/tjsiddse/workers/transcription-queue-worker.php"
CRON_COMMAND="* * * * * php $WORKER_PATH >> /dev/null 2>&1"

# Verificar si el worker existe
if [ ! -f "$WORKER_PATH" ]; then
    echo "❌ Error: El archivo worker no existe en $WORKER_PATH"
    exit 1
fi

# Verificar si el cron job ya existe
if crontab -l 2>/dev/null | grep -q "$WORKER_PATH"; then
    echo "✅ El cron job ya está instalado"
    crontab -l 2>/dev/null | grep "$WORKER_PATH"
    exit 0
fi

# Agregar el cron job
(crontab -l 2>/dev/null; echo "$CRON_COMMAND") | crontab -

if [ $? -eq 0 ]; then
    echo "✅ Cron job instalado exitosamente"
    echo "   Comando: $CRON_COMMAND"
    echo ""
    echo "Para verificar, ejecuta: crontab -l"
else
    echo "❌ Error al instalar el cron job"
    exit 1
fi
