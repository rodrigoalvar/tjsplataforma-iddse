#!/bin/bash
# Script para configurar el cron del worker R2
# Ejecutar: bash setup-cron.sh

CRON_LINE="*/1 * * * * php /var/www/tjsiddse/modules/cloud-storage/workers/r2-upload-worker.php >> /var/www/tjsiddse/modules/cloud-storage/logs/r2-worker.log 2>&1"

echo "=== Configuración de Cron para Worker R2 ==="
echo ""
echo "Línea de cron a agregar:"
echo "$CRON_LINE"
echo ""

# Verificar si ya existe
if crontab -l 2>/dev/null | grep -q "r2-upload-worker.php"; then
    echo "⚠️  Ya existe una entrada de cron para el worker R2."
    echo ""
    echo "Entrada actual:"
    crontab -l 2>/dev/null | grep "r2-upload-worker.php"
    echo ""
    read -p "¿Deseas reemplazarla? (s/n): " -n 1 -r
    echo ""
    if [[ ! $REPLY =~ ^[Ss]$ ]]; then
        echo "Operación cancelada."
        exit 0
    fi
    # Eliminar la entrada antigua
    crontab -l 2>/dev/null | grep -v "r2-upload-worker.php" | crontab -
fi

# Agregar la nueva entrada
(crontab -l 2>/dev/null; echo "$CRON_LINE") | crontab -

echo "✅ Cron configurado exitosamente!"
echo ""
echo "Para verificar:"
echo "  crontab -l | grep r2-upload-worker"
echo ""
echo "Para ver los logs:"
echo "  tail -f /var/www/tjsiddse/modules/cloud-storage/logs/r2-worker.log"
echo ""
