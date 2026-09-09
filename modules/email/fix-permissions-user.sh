#!/bin/bash
# Script para cambiar permisos del módulo de email
# Permite editar sin sudo

echo "🔧 Ajustando permisos del módulo de email..."

# Obtener el usuario actual
CURRENT_USER=$(whoami)
CURRENT_GROUP=$(id -gn)

echo "Usuario actual: $CURRENT_USER"
echo "Grupo actual: $CURRENT_GROUP"

# Cambiar ownership (requiere sudo, pero el script lo hace una vez)
if [ "$EUID" -ne 0 ]; then 
    echo "⚠️  Este script requiere permisos sudo para cambiar ownership."
    echo "Ejecuta: sudo $0"
    exit 1
fi

# Cambiar ownership a tu usuario
chown -R $CURRENT_USER:$CURRENT_GROUP /var/www/tjsidimagenes/modules/email

# Dar permisos de lectura y escritura
chmod -R 775 /var/www/tjsidimagenes/modules/email

# Asegurar que el servidor web también pueda leer
chmod -R 755 /var/www/tjsidimagenes/modules/email

# Archivos específicos con permisos de escritura
chmod 664 /var/www/tjsidimagenes/modules/email/config/*.php
chmod 664 /var/www/tjsidimagenes/modules/email/templates/*.html
chmod 664 /var/www/tjsidimagenes/modules/email/api/*.php
chmod 664 /var/www/tjsidimagenes/modules/email/logs/*.log 2>/dev/null || true

echo "✅ Permisos ajustados correctamente"
echo ""
echo "Ahora puedes editar los archivos sin sudo."





