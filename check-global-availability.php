<?php
echo "=== VERIFICACIÓN DE DISPONIBILIDAD GLOBAL ===\n\n";

echo "🔍 PROBLEMA POTENCIAL IDENTIFICADO:\n";
echo "   - Los checkboxes usan 'onchange=\"derivacionesManager.handleStudySelection()\"'\n";
echo "   - Si 'derivacionesManager' no está disponible globalmente, la función no se ejecuta\n\n";

echo "📋 VERIFICACIONES NECESARIAS:\n";
echo "   1. ¿Está 'derivacionesManager' disponible globalmente?\n";
echo "   2. ¿Se ejecuta 'handleStudySelection()' cuando se marca un checkbox?\n";
echo "   3. ¿Se actualiza el contador de estudios seleccionados?\n";
echo "   4. ¿Se muestra/oculta el div 'bulkActions'?\n";
echo "   5. ¿Se habilita/deshabilita el botón 'assignSelectedBtn'?\n\n";

echo "🧪 DEBUGGING AGREGADO:\n";
echo "   ✓ Console.log en handleStudySelection()\n";
echo "   ✓ Verificación de elementos DOM\n";
echo "   ✓ Estado de botones y contadores\n\n";

echo "🔧 POSIBLES SOLUCIONES:\n";
echo "   1. Verificar que 'derivacionesManager' esté disponible globalmente\n";
echo "   2. Usar event listeners en lugar de onchange inline\n";
echo "   3. Verificar que el objeto se inicialice correctamente\n\n";

echo "📊 PASOS PARA VERIFICAR:\n";
echo "   1. Abrir estudios-manager.html\n";
echo "   2. Abrir Developer Tools (F12)\n";
echo "   3. Ir a la pestaña Console\n";
echo "   4. Escribir: 'typeof derivacionesManager'\n";
echo "   5. Debería mostrar: 'object'\n";
echo "   6. Marcar un checkbox de estudio\n";
echo "   7. Verificar mensajes de debug en Console\n\n";

echo "❌ SI NO FUNCIONA:\n";
echo "   • 'typeof derivacionesManager' → 'undefined'\n";
echo "   • No aparecen mensajes de debug al marcar checkbox\n";
echo "   • El contador no se actualiza\n";
echo "   • El botón 'Asignar Seleccionados' permanece deshabilitado\n\n";

echo "✅ SI FUNCIONA:\n";
echo "   • 'typeof derivacionesManager' → 'object'\n";
echo "   • Aparecen mensajes de debug al marcar checkbox\n";
echo "   • El contador se actualiza correctamente\n";
echo "   • El botón 'Asignar Seleccionados' se habilita\n\n";

echo "🚀 ARCHIVOS DE PRUEBA:\n";
echo "   • debug-study-assignment.html - Diagnóstico interactivo\n";
echo "   • test-study-assignment.html - Prueba completa\n\n";

echo "⚠️ NOTA IMPORTANTE:\n";
echo "   El problema más probable es que 'derivacionesManager' no está\n";
echo "   disponible globalmente cuando se ejecuta el onchange del checkbox.\n";
?>
