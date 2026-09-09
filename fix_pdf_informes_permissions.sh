#!/bin/bash
# Script para corregir permisos de los directorios necesarios para PACS
# Ejecutar con: sudo ./fix_pdf_informes_permissions.sh

BASE_DIR="/var/www/tjslosalisos/uploads"
PDF_DIR="$BASE_DIR/pdf_informes"
TEMP_JPG_DIR="$BASE_DIR/temp_jpg"
PNG_DIR="$BASE_DIR/png_informes"

echo "🔧 Corrigiendo permisos de los directorios necesarios para PACS..."
echo ""

# Cambiar grupo a www-data para todos los directorios
echo "📁 Cambiando grupo a www-data..."
chgrp www-data "$BASE_DIR" 2>/dev/null && echo "✅ Grupo cambiado a www-data para $BASE_DIR" || echo "⚠️ No se pudo cambiar el grupo de $BASE_DIR"
chgrp www-data "$PDF_DIR" 2>/dev/null && echo "✅ Grupo cambiado a www-data para $PDF_DIR" || echo "⚠️ No se pudo cambiar el grupo de $PDF_DIR"
chgrp www-data "$TEMP_JPG_DIR" 2>/dev/null && echo "✅ Grupo cambiado a www-data para $TEMP_JPG_DIR" || echo "⚠️ No se pudo cambiar el grupo de $TEMP_JPG_DIR"
if [ -d "$PNG_DIR" ]; then
    chgrp www-data "$PNG_DIR" 2>/dev/null && echo "✅ Grupo cambiado a www-data para $PNG_DIR" || echo "⚠️ No se pudo cambiar el grupo de $PNG_DIR"
fi

echo ""
echo "🔐 Estableciendo permisos 775 (rwxrwxr-x)..."
# Establecer permisos 775 (rwxrwxr-x) - propietario y grupo pueden escribir
chmod 775 "$BASE_DIR" && echo "✅ Permisos establecidos a 775 para $BASE_DIR" || echo "❌ Error estableciendo permisos para $BASE_DIR"
chmod 775 "$PDF_DIR" && echo "✅ Permisos establecidos a 775 para $PDF_DIR" || echo "❌ Error estableciendo permisos para $PDF_DIR"
chmod 775 "$TEMP_JPG_DIR" && echo "✅ Permisos establecidos a 775 para $TEMP_JPG_DIR" || echo "❌ Error estableciendo permisos para $TEMP_JPG_DIR"
if [ -d "$PNG_DIR" ]; then
    chmod 775 "$PNG_DIR" && echo "✅ Permisos establecidos a 775 para $PNG_DIR" || echo "❌ Error estableciendo permisos para $PNG_DIR"
fi

# Verificar permisos finales
echo ""
echo "📋 Permisos finales:"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
ls -ld "$BASE_DIR"
ls -ld "$PDF_DIR"
ls -ld "$TEMP_JPG_DIR"
if [ -d "$PNG_DIR" ]; then
    ls -ld "$PNG_DIR"
fi
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"

echo ""
echo "✅ Permisos corregidos. El servidor web (www-data) ahora puede escribir en todos los directorios."
echo ""
echo "💡 Si aún hay problemas, verifica que:"
echo "   1. El grupo www-data existe: getent group www-data"
echo "   2. Los directorios tienen permisos 775: ls -ld $BASE_DIR"
echo "   3. El servidor web puede escribir: sudo -u www-data touch $TEMP_JPG_DIR/test.txt && sudo -u www-data rm $TEMP_JPG_DIR/test.txt"

