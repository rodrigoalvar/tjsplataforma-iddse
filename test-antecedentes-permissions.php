<?php
/**
 * Script de prueba para verificar permisos de antecedentes de un usuario
 * Uso: php test-antecedentes-permissions.php email@ejemplo.com
 */

require_once __DIR__ . '/config/database.php';

$email = $argv[1] ?? null;

if (!$email) {
    echo "Uso: php test-antecedentes-permissions.php email@ejemplo.com\n";
    exit(1);
}

try {
    $db = getDBConnection();
    
    if (!$db) {
        echo "❌ Error: No se pudo conectar a la base de datos\n";
        exit(1);
    }
    
    echo "=== VERIFICACIÓN DE PERMISOS DE ANTECEDENTES ===\n\n";
    echo "Email: $email\n\n";
    
    // Obtener usuario
    $stmt = $db->prepare("SELECT id, nombre, apellido, email, permisos FROM usuarios WHERE email = ? AND activo = 1");
    $stmt->execute([$email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) {
        echo "❌ Usuario no encontrado o inactivo\n";
        exit(1);
    }
    
    echo "✅ Usuario encontrado:\n";
    echo "   ID: {$user['id']}\n";
    echo "   Nombre: {$user['nombre']} {$user['apellido']}\n";
    echo "   Email: {$user['email']}\n\n";
    
    // Procesar permisos
    $permisos = [];
    if ($user['permisos']) {
        if (is_string($user['permisos'])) {
            $permisos = json_decode($user['permisos'], true) ?: [];
        } else {
            $permisos = $user['permisos'];
        }
    }
    
    echo "📋 Permisos del usuario (" . count($permisos) . "):\n";
    foreach ($permisos as $perm) {
        echo "   - $perm\n";
    }
    echo "\n";
    
    // Verificar permisos de antecedentes
    echo "🔍 Verificación de permisos de antecedentes:\n";
    
    $hasAntecedentes = in_array('antecedentes', $permisos) || in_array('all', $permisos);
    $hasAntecedentesNotas = in_array('antecedentes_notas', $permisos) || $hasAntecedentes;
    $hasAntecedentesImagenes = in_array('antecedentes_imagenes', $permisos) || $hasAntecedentes;
    $hasAntecedentesCamara = in_array('antecedentes_camara', $permisos) || $hasAntecedentes;
    $hasAntecedentesArchivos = in_array('antecedentes_archivos', $permisos) || $hasAntecedentes;
    
    echo "   - antecedentes (general): " . ($hasAntecedentes ? "✅ SÍ" : "❌ NO") . "\n";
    echo "   - antecedentes_notas: " . ($hasAntecedentesNotas ? "✅ SÍ" : "❌ NO") . "\n";
    echo "   - antecedentes_imagenes: " . ($hasAntecedentesImagenes ? "✅ SÍ" : "❌ NO") . "\n";
    echo "   - antecedentes_camara: " . ($hasAntecedentesCamara ? "✅ SÍ" : "❌ NO") . "\n";
    echo "   - antecedentes_archivos: " . ($hasAntecedentesArchivos ? "✅ SÍ" : "❌ NO") . "\n";
    echo "\n";
    
    // Resumen
    $pestanasVisibles = [];
    if ($hasAntecedentesNotas) $pestanasVisibles[] = "Notas y Texto";
    if ($hasAntecedentesImagenes) $pestanasVisibles[] = "Imágenes";
    if ($hasAntecedentesCamara) $pestanasVisibles[] = "Cámara";
    if ($hasAntecedentesArchivos) $pestanasVisibles[] = "Archivos";
    
    echo "📊 Pestañas que deberían mostrarse:\n";
    if (empty($pestanasVisibles)) {
        echo "   ⚠️  NINGUNA (solo se mostrarán QR Móvil y Existentes)\n";
    } else {
        foreach ($pestanasVisibles as $pestana) {
            echo "   ✅ $pestana\n";
        }
    }
    echo "\n";
    
    // Verificar permisos en system_permissions
    echo "🔍 Permisos disponibles en system_permissions:\n";
    $stmt = $db->query("SELECT permission_key, permission_name FROM system_permissions WHERE category = 'antecedentes' ORDER BY permission_key");
    $systemPerms = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($systemPerms as $sp) {
        $tiene = in_array($sp['permission_key'], $permisos);
        echo "   - {$sp['permission_key']}: " . ($tiene ? "✅ Asignado" : "❌ No asignado") . "\n";
    }
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    exit(1);
}
?>



