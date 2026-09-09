#!/bin/bash
# Script para corregir permisos del directorio de configuración de WhatsApp
# Ejecutar con: sudo ./fix-permissions.sh

CONFIG_DIR="/var/www/tjsiddse/modules/whatsapp/config"
CONFIG_FILE="$CONFIG_DIR/whatsapp_config.php"

echo "═══════════════════════════════════════════════════════════════"
echo "  Corrigiendo permisos para WhatsApp config..."
echo "═══════════════════════════════════════════════════════════════"
echo ""

# Obtener el usuario del servidor web
WEB_USER=$(ps aux | grep -E "[a]pache|[n]ginx|[h]ttpd" | grep -v grep | head -1 | awk '{print $1}')
if [ -z "$WEB_USER" ] || [ "$WEB_USER" = "root" ]; then
    WEB_USER="www-data"
fi

echo "Usuario del servidor web detectado: $WEB_USER"
echo ""

# Verificar permisos actuales
echo "Permisos ANTES:"
ls -la "$CONFIG_DIR" 2>/dev/null || echo "Directorio no existe"
echo ""

# Cambiar grupo del directorio y archivo
echo "Cambiando grupo a $WEB_USER..."
chgrp $WEB_USER "$CONFIG_DIR" 2>/dev/null && echo "✓ Grupo del directorio cambiado" || echo "✗ Error cambiando grupo del directorio"
chgrp $WEB_USER "$CONFIG_FILE" 2>/dev/null && echo "✓ Grupo del archivo cambiado" || echo "✗ Error cambiando grupo del archivo"

# Establecer permisos
echo ""
echo "Estableciendo permisos..."
chmod 2775 "$CONFIG_DIR" && echo "✓ Permisos del directorio establecidos (2775)" || echo "✗ Error estableciendo permisos del directorio"
chmod 664 "$CONFIG_FILE" && echo "✓ Permisos del archivo establecidos (664)" || echo "✗ Error estableciendo permisos del archivo"

# Verificar
echo ""
echo "═══════════════════════════════════════════════════════════════"
echo "Permisos DESPUÉS:"
echo "═══════════════════════════════════════════════════════════════"
ls -la "$CONFIG_DIR"
echo ""

# Verificar permisos
if [ -d "$CONFIG_DIR" ]; then
    DIR_PERMS=$(stat -c "%a" "$CONFIG_DIR")
    DIR_GROUP=$(stat -c "%G" "$CONFIG_DIR")
    echo "Directorio: permisos=$DIR_PERMS, grupo=$DIR_GROUP"
    if [ "$DIR_GROUP" = "$WEB_USER" ] && [ "$DIR_PERMS" = "2775" ]; then
        echo "✓ Directorio configurado correctamente"
    else
        echo "✗ Directorio NO está configurado correctamente"
    fi
fi

if [ -f "$CONFIG_FILE" ]; then
    FILE_PERMS=$(stat -c "%a" "$CONFIG_FILE")
    FILE_GROUP=$(stat -c "%G" "$CONFIG_FILE")
    echo "Archivo: permisos=$FILE_PERMS, grupo=$FILE_GROUP"
    if [ "$FILE_GROUP" = "$WEB_USER" ] && [ "$FILE_PERMS" = "664" ]; then
        echo "✓ Archivo configurado correctamente"
    else
        echo "✗ Archivo NO está configurado correctamente"
    fi
fi

echo ""
echo "═══════════════════════════════════════════════════════════════"

