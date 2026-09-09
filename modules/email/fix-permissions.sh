#!/bin/bash
#
# Script para corregir permisos del módulo de email
# Ejecutar con: sudo bash fix-permissions.sh
#

echo "🔧 Corrigiendo permisos del módulo de email..."

# Obtener directorio del script
SCRIPT_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
cd "$SCRIPT_DIR"

# Detectar usuario del servidor web
if id "www-data" &>/dev/null; then
    WEB_USER="www-data"
elif id "apache" &>/dev/null; then
    WEB_USER="apache"
elif id "nginx" &>/dev/null; then
    WEB_USER="nginx"
else
    echo "⚠️  No se encontró usuario del servidor web. Usando www-data por defecto."
    WEB_USER="www-data"
fi

echo "📁 Usuario del servidor web: $WEB_USER"

# Cambiar propietario de directorios que necesitan escritura
echo "📝 Cambiando propietario de directorios..."
sudo chown -R $WEB_USER:$WEB_USER config templates logs api 2>/dev/null || {
    echo "⚠️  No se pudo cambiar propietario. Intentando solo con permisos..."
}

# Dar permisos de escritura
echo "🔐 Aplicando permisos..."
sudo chmod -R 775 config templates logs api

# Verificar permisos
echo "✅ Verificando permisos..."
ls -ld config templates logs api

# Probar escritura
echo "🧪 Probando escritura..."
if touch config/test.txt 2>/dev/null && rm -f config/test.txt; then
    echo "✅ Permisos de escritura en config: OK"
else
    echo "❌ Permisos de escritura en config: FALLO"
fi

if touch templates/test.txt 2>/dev/null && rm -f templates/test.txt; then
    echo "✅ Permisos de escritura en templates: OK"
else
    echo "❌ Permisos de escritura en templates: FALLO"
fi

if touch logs/test.txt 2>/dev/null && rm -f logs/test.txt; then
    echo "✅ Permisos de escritura en logs: OK"
else
    echo "❌ Permisos de escritura en logs: FALLO"
fi

if touch api/test.txt 2>/dev/null && rm -f api/test.txt; then
    echo "✅ Permisos de escritura en api: OK"
else
    echo "❌ Permisos de escritura en api: FALLO"
fi

echo ""
echo "✨ Proceso completado!"
echo ""
echo "Si aún hay problemas, ejecute manualmente:"
echo "  sudo chown -R $WEB_USER:$WEB_USER config templates logs api"
echo "  sudo chmod -R 775 config templates logs api"

