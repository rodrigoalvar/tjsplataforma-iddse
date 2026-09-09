#!/bin/bash
# Script para corregir permisos del directorio de configuración del módulo de email

echo "═══════════════════════════════════════════════════════════════"
echo "  Corrección de Permisos - Módulo de Email"
echo "═══════════════════════════════════════════════════════════════"
echo ""

# Directorio del módulo
MODULE_DIR="/var/www/tjsidimagenes/modules/email"
CONFIG_DIR="$MODULE_DIR/config"

# Detectar usuario del servidor web
WEB_USER=""
if id www-data &>/dev/null; then
    WEB_USER="www-data"
elif id apache &>/dev/null; then
    WEB_USER="apache"
elif id nginx &>/dev/null; then
    WEB_USER="nginx"
else
    echo "⚠️  No se pudo detectar el usuario del servidor web."
    echo "   Por favor, ejecuta manualmente:"
    echo "   sudo chown -R dicomsuites:www-data $CONFIG_DIR"
    echo "   sudo chmod -R 775 $CONFIG_DIR"
    exit 1
fi

echo "📁 Directorio de configuración: $CONFIG_DIR"
echo "👤 Usuario del servidor web detectado: $WEB_USER"
echo ""

# Verificar que el directorio existe
if [ ! -d "$CONFIG_DIR" ]; then
    echo "❌ El directorio no existe: $CONFIG_DIR"
    exit 1
fi

# Cambiar propietario y permisos
echo "🔧 Aplicando permisos..."
sudo chown -R dicomsuites:$WEB_USER "$CONFIG_DIR"
sudo chmod -R 775 "$CONFIG_DIR"

# Verificar resultado
if [ $? -eq 0 ]; then
    echo "✅ Permisos aplicados correctamente"
    echo ""
    echo "📋 Permisos actuales:"
    ls -la "$CONFIG_DIR" | grep -E "^d|^-" | head -5
    echo ""
    echo "✅ El módulo de email ahora puede guardar la configuración."
else
    echo "❌ Error al aplicar permisos"
    exit 1
fi





