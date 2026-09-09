#!/bin/bash
# Script para configurar límites de PHP-FPM para subida de archivos grandes
# Ejecutar con: sudo bash configurar-limites-php-fpm.sh

echo "=== Configurando límites de PHP-FPM ==="
echo ""

# Verificar que se ejecuta como root
if [ "$EUID" -ne 0 ]; then 
    echo "Error: Este script debe ejecutarse con sudo"
    echo "Uso: sudo bash configurar-limites-php-fpm.sh"
    exit 1
fi

# Detectar versión de PHP
PHP_VERSION=$(php -v | head -1 | grep -oP '\d+\.\d+' | head -1)
echo "Versión de PHP detectada: $PHP_VERSION"

# Crear archivo de configuración
CONFIG_FILE="/etc/php/${PHP_VERSION}/fpm/conf.d/99-custom-upload-limits.ini"

echo "Creando archivo de configuración: $CONFIG_FILE"
cat > "$CONFIG_FILE" << 'EOF'
; Límites personalizados para subida de archivos grandes (AI Informes)
; Configurado para permitir archivos de hasta 500MB
upload_max_filesize = 500M
post_max_size = 500M
memory_limit = 512M
max_execution_time = 1800
max_input_time = 1800
EOF

echo "✓ Archivo de configuración creado"

# Verificar que el archivo se creó correctamente
if [ -f "$CONFIG_FILE" ]; then
    echo ""
    echo "Contenido del archivo:"
    cat "$CONFIG_FILE"
    echo ""
else
    echo "✗ Error: No se pudo crear el archivo de configuración"
    exit 1
fi

# Reiniciar PHP-FPM
echo "Reiniciando PHP-FPM..."
systemctl restart "php${PHP_VERSION}-fpm"

if [ $? -eq 0 ]; then
    echo "✓ PHP-FPM reiniciado correctamente"
else
    echo "✗ Error al reiniciar PHP-FPM"
    exit 1
fi

# Verificar estado
echo ""
echo "Estado de PHP-FPM:"
systemctl status "php${PHP_VERSION}-fpm" --no-pager | head -5

echo ""
echo "=== Configuración completada ==="
echo ""
echo "Para verificar los límites, ejecuta:"
echo "  php verificar-limites-php.php"
echo ""
echo "Nota: Los cambios se aplicarán a las nuevas peticiones PHP-FPM."
echo "Si usas Nginx, también verifica que client_max_body_size esté configurado:"
echo "  client_max_body_size 500M;"
