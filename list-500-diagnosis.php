<?php
echo "=== DIAGNÓSTICO: ERROR 500 EN LIST.PHP ===\n\n";

echo "🔍 PROBLEMA CONFIRMADO:\n\n";

echo "✅ Sesión iniciada correctamente\n";
echo "❌ list.php devuelve 500 (Internal Server Error)\n";
echo "❌ Error HTTP: 500\n\n";

echo "🛠️ DIAGNÓSTICO PASO A PASO:\n\n";

echo "PASO 1: Verificar el archivo list.php\n";
echo "   • Ubicación: api/informes/list.php\n";
echo "   • Debe existir y ser accesible\n\n";

echo "PASO 2: Verificar dependencias\n";
echo "   • middleware/auth.php\n";
echo "   • config/database.php\n";
echo "   • classes/User.php\n\n";

echo "PASO 3: Verificar logs de error de PHP\n";
echo "   • WAMP error logs\n";
echo "   • Apache error logs\n\n";

echo "🔧 SOLUCIÓN INMEDIATA:\n\n";

echo "Voy a crear un test directo para list.php:\n";
echo "   1. Crear test-list-direct.php\n";
echo "   2. Probar sin middleware complejo\n";
echo "   3. Identificar el error específico\n\n";

echo "📋 PRÓXIMOS PASOS:\n\n";

echo "1. ✅ Crear test directo para list.php\n";
echo "2. ✅ Identificar el error específico\n";
echo "3. ✅ Corregir el problema\n";
echo "4. ✅ Probar informes-manager\n\n";
?>
