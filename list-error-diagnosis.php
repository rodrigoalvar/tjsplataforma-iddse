<?php
echo "=== DIAGNÓSTICO: ERROR 500 EN list.php ===\n\n";

echo "🔍 PROBLEMA ACTUAL:\n";
echo "   • GET http://localhost/portal_estudios/api/informes/list.php?page=1 500\n";
echo "   • Error al cargar lista de informes en informes-manager\n\n";

echo "🔧 POSIBLES CAUSAS:\n\n";

echo "1. ✅ AUTENTICACIÓN:\n";
echo "   • list.php requiere token de sesión válido\n";
echo "   • Si no hay token, devuelve 401\n";
echo "   • Si el token es inválido, puede dar 500\n\n";

echo "2. ✅ MIDDLEWARE AUTH:\n";
echo "   • list.php usa middleware/auth.php\n";
echo "   • Require classes/User.php\n";
echo "   • Necesita config/database.php\n\n";

echo "3. ✅ DIFERENCIA CON get.php:\n";
echo "   • get.php: Funciona (validación dentro del archivo)\n";
echo "   • list.php: Error 500 (usa middleware externo)\n\n";

echo "4. ✅ SESIÓN DEL USUARIO:\n";
echo "   • Usuario puede no tener session_token cookie\n";
echo "   • Token puede estar expirado\n";
echo "   • Token puede ser inválido\n\n";

echo "🛠️ SOLUCIONES PROPUESTAS:\n\n";

echo "OPCIÓN 1: VERIFICAR SESIÓN DEL USUARIO\n";
echo "   1. Cerrar sesión en el sistema\n";
echo "   2. Volver a iniciar sesión\n";
echo "   3. Verificar que se crea el cookie session_token\n";
echo "   4. Probar nuevamente informes-manager\n\n";

echo "OPCIÓN 2: SIMPLIFICAR list.php\n";
echo "   • Modificar list.php para usar validación similar a get.php\n";
echo "   • Incluir validación dentro del archivo\n";
echo "   • No depender de middleware externo\n\n";

echo "OPCIÓN 3: VERIFICAR LOGS DETALLADOS\n";
echo "   • Habilitar display_errors temporalmente\n";
echo "   • Ver error exacto que causa el 500\n";
echo "   • Corregir causa específica\n\n";

echo "📋 ARCHIVO DE PRUEBA CREADO:\n";
echo "   • test-list-api.html\n";
echo "   • Probar desde el navegador\n";
echo "   • Ver consola para detalles\n";
echo "   • Verificar si hay token de sesión\n\n";

echo "🔍 PRÓXIMO PASO:\n";
echo "   1. Abrir test-list-api.html en el navegador\n";
echo "   2. Hacer clic en el botón de test\n";
echo "   3. Revisar la consola del navegador\n";
echo "   4. Compartir el error exacto\n\n";

echo "Si el problema es de sesión:\n";
echo "   • Cerrar sesión\n";
echo "   • Volver a iniciar sesión\n";
echo "   • Probar nuevamente\n\n";

echo "Si el problema persiste:\n";
echo "   • Compartir el error exacto de la consola\n";
echo "   • Revisar si hay token de sesión\n";
echo "   • Podemos modificar list.php para simplificar autenticación\n";
?>
