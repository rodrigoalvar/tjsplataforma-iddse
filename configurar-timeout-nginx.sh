#!/bin/bash
# Script para configurar timeouts de Nginx para generación de informes AI
# Ejecutar con: sudo bash configurar-timeout-nginx.sh

echo "=== Configurando Timeouts de Nginx para AI Informes ==="
echo ""

# Verificar que se ejecuta como root
if [ "$EUID" -ne 0 ]; then 
    echo "Error: Este script debe ejecutarse con sudo"
    echo "Uso: sudo bash configurar-timeout-nginx.sh"
    exit 1
fi

# Buscar archivos de configuración de Nginx
NGINX_SITES="/etc/nginx/sites-available"
NGINX_ENABLED="/etc/nginx/sites-enabled"

echo "Buscando archivos de configuración de Nginx..."
echo ""

# Listar archivos disponibles
if [ -d "$NGINX_SITES" ]; then
    echo "Archivos disponibles en $NGINX_SITES:"
    ls -1 "$NGINX_SITES" | grep -v "default"
    echo ""
    read -p "Ingresa el nombre del archivo de configuración (ej: plataforma.iddse.com.ar): " SITE_NAME
    
    if [ -z "$SITE_NAME" ]; then
        echo "Error: Debes especificar un nombre de archivo"
        exit 1
    fi
    
    CONFIG_FILE="$NGINX_SITES/$SITE_NAME"
    
    if [ ! -f "$CONFIG_FILE" ]; then
        echo "Error: El archivo $CONFIG_FILE no existe"
        exit 1
    fi
else
    echo "Error: No se encontró el directorio $NGINX_SITES"
    exit 1
fi

echo "Archivo de configuración: $CONFIG_FILE"
echo ""

# Crear backup
BACKUP_FILE="${CONFIG_FILE}.backup.$(date +%Y%m%d_%H%M%S)"
cp "$CONFIG_FILE" "$BACKUP_FILE"
echo "✓ Backup creado: $BACKUP_FILE"
echo ""

# Verificar si ya tiene timeouts configurados
if grep -q "fastcgi_read_timeout\|proxy_read_timeout" "$CONFIG_FILE"; then
    echo "⚠ Advertencia: Ya hay timeouts configurados en el archivo"
    echo "¿Deseas actualizarlos? (s/n): "
    read -r UPDATE
    if [ "$UPDATE" != "s" ] && [ "$UPDATE" != "S" ]; then
        echo "Operación cancelada"
        exit 0
    fi
fi

# Crear archivo temporal con la configuración
TEMP_FILE=$(mktemp)

# Leer el archivo original y agregar/actualizar timeouts
cat "$CONFIG_FILE" | while IFS= read -r line; do
    # Si encontramos el bloque server, agregar timeouts después
    if [[ "$line" =~ ^[[:space:]]*server[[:space:]]*\{ ]]; then
        echo "$line"
        echo ""
        echo "    # Timeouts para generación de informes AI (configurado automáticamente)"
        echo "    fastcgi_read_timeout 1800s;      # 30 minutos para PHP-FPM"
        echo "    fastcgi_send_timeout 1800s;      # 30 minutos"
        echo "    proxy_read_timeout 1800s;        # 30 minutos (si usas proxy)"
        echo "    proxy_connect_timeout 60s;       # 1 minuto para conexión"
        echo "    proxy_send_timeout 1800s;        # 30 minutos"
        echo ""
    # Si encontramos un location específico para ai-informes.php, agregar timeouts ahí también
    elif [[ "$line" =~ location.*ai-informes\.php ]]; then
        echo "$line"
        echo "        fastcgi_read_timeout 1800s;"
        echo "        fastcgi_send_timeout 1800s;"
    # Si ya hay timeouts configurados, actualizarlos
    elif [[ "$line" =~ fastcgi_read_timeout ]]; then
        echo "    fastcgi_read_timeout 1800s;      # 30 minutos para PHP-FPM"
    elif [[ "$line" =~ fastcgi_send_timeout ]]; then
        echo "    fastcgi_send_timeout 1800s;      # 30 minutos"
    elif [[ "$line" =~ proxy_read_timeout ]]; then
        echo "    proxy_read_timeout 1800s;        # 30 minutos"
    elif [[ "$line" =~ proxy_send_timeout ]]; then
        echo "    proxy_send_timeout 1800s;        # 30 minutos"
    else
        echo "$line"
    fi
done > "$TEMP_FILE"

# Reemplazar el archivo original
mv "$TEMP_FILE" "$CONFIG_FILE"

echo "✓ Timeouts configurados en $CONFIG_FILE"
echo ""

# Verificar configuración de Nginx
echo "Verificando configuración de Nginx..."
if nginx -t; then
    echo "✓ Configuración de Nginx es válida"
    echo ""
    echo "¿Deseas recargar Nginx ahora? (s/n): "
    read -r RELOAD
    if [ "$RELOAD" = "s" ] || [ "$RELOAD" = "S" ]; then
        systemctl reload nginx
        if [ $? -eq 0 ]; then
            echo "✓ Nginx recargado correctamente"
        else
            echo "✗ Error al recargar Nginx"
            exit 1
        fi
    else
        echo "Recuerda ejecutar: sudo systemctl reload nginx"
    fi
else
    echo "✗ Error en la configuración de Nginx"
    echo "Restaurando backup..."
    cp "$BACKUP_FILE" "$CONFIG_FILE"
    exit 1
fi

echo ""
echo "=== Configuración completada ==="
echo ""
echo "Timeouts configurados:"
echo "  - fastcgi_read_timeout: 1800s (30 minutos)"
echo "  - fastcgi_send_timeout: 1800s (30 minutos)"
echo "  - proxy_read_timeout: 1800s (30 minutos)"
echo "  - proxy_send_timeout: 1800s (30 minutos)"
echo ""
echo "Backup guardado en: $BACKUP_FILE"
