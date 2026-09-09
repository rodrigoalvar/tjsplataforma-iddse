#!/bin/bash
# Script para corregir permisos del directorio de plantillas de WhatsApp
# Ejecutar con: sudo ./fix-templates-permissions.sh

TEMPLATES_DIR="/var/www/tjsiddse/modules/whatsapp/templates"

echo "═══════════════════════════════════════════════════════════════"
echo "  Corrigiendo permisos para WhatsApp templates..."
echo "═══════════════════════════════════════════════════════════════"
echo ""

# Obtener el usuario del servidor web
WEB_USER=$(ps aux | grep -E "[a]pache|[n]ginx|[h]ttpd|php-fpm" | grep -v grep | head -1 | awk '{print $1}')
if [ -z "$WEB_USER" ] || [ "$WEB_USER" = "root" ]; then
    WEB_USER="www-data"
fi

echo "Usuario del servidor web detectado: $WEB_USER"
echo ""

# Crear directorio si no existe
if [ ! -d "$TEMPLATES_DIR" ]; then
    echo "Creando directorio de plantillas..."
    mkdir -p "$TEMPLATES_DIR"
fi

# Verificar permisos actuales
echo "Permisos ANTES:"
ls -la "$TEMPLATES_DIR" 2>/dev/null || echo "Directorio no existe"
echo ""

# Cambiar grupo del directorio
echo "Cambiando grupo a $WEB_USER..."
chgrp $WEB_USER "$TEMPLATES_DIR" 2>/dev/null && echo "✓ Grupo del directorio cambiado" || echo "✗ Error cambiando grupo del directorio"

# Establecer permisos con sticky bit para grupo
echo ""
echo "Estableciendo permisos..."
chmod 2775 "$TEMPLATES_DIR" && echo "✓ Permisos del directorio establecidos (2775)" || echo "✗ Error estableciendo permisos del directorio"

# Cambiar permisos de archivos existentes
if [ -d "$TEMPLATES_DIR" ]; then
    echo ""
    echo "Ajustando permisos de archivos existentes..."
    find "$TEMPLATES_DIR" -type f -exec chmod 664 {} \; 2>/dev/null && echo "✓ Permisos de archivos ajustados" || echo "✗ Error ajustando permisos de archivos"
    find "$TEMPLATES_DIR" -type f -exec chgrp $WEB_USER {} \; 2>/dev/null && echo "✓ Grupo de archivos cambiado" || echo "✗ Error cambiando grupo de archivos"
fi

# Verificar
echo ""
echo "═══════════════════════════════════════════════════════════════"
echo "Permisos DESPUÉS:"
echo "═══════════════════════════════════════════════════════════════"
ls -la "$TEMPLATES_DIR"
echo ""

# Verificar permisos
if [ -d "$TEMPLATES_DIR" ]; then
    DIR_PERMS=$(stat -c "%a" "$TEMPLATES_DIR")
    DIR_GROUP=$(stat -c "%G" "$TEMPLATES_DIR")
    echo "Directorio: permisos=$DIR_PERMS, grupo=$DIR_GROUP"
    if [ "$DIR_GROUP" = "$WEB_USER" ] && [ "$DIR_PERMS" = "2775" ]; then
        echo "✓ Directorio configurado correctamente"
    else
        echo "✗ Directorio NO está configurado correctamente"
        echo "  Ejecute manualmente: chgrp $WEB_USER $TEMPLATES_DIR && chmod 2775 $TEMPLATES_DIR"
    fi
fi

echo ""
echo "═══════════════════════════════════════════════════════════════"


