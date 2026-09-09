#!/bin/bash
# Script para configurar PATH en PHP-FPM para que encuentre ffmpeg
# Ejecutar con: sudo bash configurar-path-php-fpm.sh

echo "=== Configurando PATH en PHP-FPM para ffmpeg ==="
echo ""

# Verificar que se ejecuta como root
if [ "$EUID" -ne 0 ]; then 
    echo "Error: Este script debe ejecutarse con sudo"
    echo "Uso: sudo bash configurar-path-php-fpm.sh"
    exit 1
fi

# Detectar versión de PHP
PHP_VERSION=$(php -r "echo PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;" 2>/dev/null)
if [ -z "$PHP_VERSION" ]; then
    echo "Error: No se pudo detectar la versión de PHP"
    exit 1
fi

echo "Versión de PHP detectada: $PHP_VERSION"
echo ""

# Archivo de configuración del pool
POOL_CONFIG="/etc/php/${PHP_VERSION}/fpm/pool.d/www.conf"

if [ ! -f "$POOL_CONFIG" ]; then
    echo "Error: No se encontró el archivo de configuración del pool: $POOL_CONFIG"
    echo "Buscando archivos alternativos..."
    POOL_CONFIG=$(find /etc/php -name "www.conf" -type f 2>/dev/null | head -1)
    if [ -z "$POOL_CONFIG" ]; then
        echo "Error: No se encontró ningún archivo de configuración del pool"
        exit 1
    fi
    echo "Usando: $POOL_CONFIG"
fi

echo "Archivo de configuración: $POOL_CONFIG"
echo ""

# Crear backup
BACKUP_FILE="${POOL_CONFIG}.backup.$(date +%Y%m%d_%H%M%S)"
cp "$POOL_CONFIG" "$BACKUP_FILE"
echo "✓ Backup creado: $BACKUP_FILE"
echo ""

# Verificar si ya tiene env[PATH] configurado
if grep -q "^env\[PATH\]" "$POOL_CONFIG"; then
    echo "⚠ Advertencia: Ya hay una configuración de PATH en el archivo"
    echo "¿Deseas actualizarla? (s/n): "
    read -r UPDATE
    if [ "$UPDATE" != "s" ] && [ "$UPDATE" != "S" ]; then
        echo "Operación cancelada"
        exit 0
    fi
    # Eliminar líneas existentes de PATH
    sed -i '/^env\[PATH\]/d' "$POOL_CONFIG"
fi

# Obtener PATH actual del sistema
CURRENT_PATH=$(echo $PATH)
if [ -z "$CURRENT_PATH" ]; then
    CURRENT_PATH="/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin"
fi

# Agregar /usr/bin si no está
if [[ "$CURRENT_PATH" != *"/usr/bin"* ]]; then
    CURRENT_PATH="/usr/bin:$CURRENT_PATH"
fi

# Buscar la sección [www] y agregar configuración de PATH después
if grep -q "^\[www\]" "$POOL_CONFIG"; then
    # Agregar después de [www]
    sed -i '/^\[www\]/a env[PATH] = '"$CURRENT_PATH" "$POOL_CONFIG")
    echo "✓ PATH configurado en el pool"
else
    echo "⚠ Advertencia: No se encontró la sección [www]"
    echo "Agregando configuración al final del archivo..."
    echo "" >> "$POOL_CONFIG"
    echo "; PATH configurado automáticamente para ffmpeg" >> "$POOL_CONFIG"
    echo "env[PATH] = $CURRENT_PATH" >> "$POOL_CONFIG"
fi

echo ""
echo "PATH configurado: $CURRENT_PATH"
echo ""

# Verificar configuración de PHP-FPM
echo "Verificando configuración de PHP-FPM..."
if php-fpm${PHP_VERSION} -t 2>/dev/null || php-fpm -t 2>/dev/null; then
    echo "✓ Configuración de PHP-FPM es válida"
    echo ""
    echo "¿Deseas reiniciar PHP-FPM ahora? (s/n): "
    read -r RESTART
    if [ "$RESTART" = "s" ] || [ "$RESTART" = "S" ]; then
        systemctl restart "php${PHP_VERSION}-fpm" 2>/dev/null || systemctl restart php-fpm
        if [ $? -eq 0 ]; then
            echo "✓ PHP-FPM reiniciado correctamente"
        else
            echo "✗ Error al reiniciar PHP-FPM"
            exit 1
        fi
    else
        echo "Recuerda ejecutar: sudo systemctl restart php${PHP_VERSION}-fpm"
    fi
else
    echo "✗ Error en la configuración de PHP-FPM"
    echo "Restaurando backup..."
    cp "$BACKUP_FILE" "$POOL_CONFIG"
    exit 1
fi

echo ""
echo "=== Configuración completada ==="
echo ""
echo "El PATH ahora incluye /usr/bin, por lo que PHP-FPM debería poder encontrar ffmpeg."
echo "Prueba accediendo a: https://plataforma.iddse.com.ar/verificar-ffmpeg.php"
