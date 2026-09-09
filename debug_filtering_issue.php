<?php
echo "=== DIAGNÓSTICO DEL PROBLEMA DE FILTRADO DE ESTUDIOS ===\n\n";

echo "🔍 PROBLEMA REPORTADO:\n";
echo "   - Usuario 'TUCUMAN INFORMANTES' (ID: 10) está logueado\n";
echo "   - Modo: 'Estudios Asignados' (sin PACS QUERY)\n";
echo "   - Console log muestra: 'Estudios asignados cargados: 1'\n";
echo "   - Contador de antecedentes actualizado para 1 estudio\n";
echo "   - PERO la lista de estudios aparece vacía\n\n";

echo "📋 ANÁLISIS DEL FLUJO:\n";
echo "   1. loadAssignedStudies() → Carga estudios en this.studies\n";
echo "   2. applyLocalFilters() → Filtra this.studies → this.filteredStudies\n";
echo "   3. renderStudies() → Renderiza this.filteredStudies en la tabla\n\n";

echo "🔍 POSIBLES CAUSAS:\n";
echo "   A) Los filtros están eliminando el estudio cargado\n";
echo "   B) El estudio no tiene el formato esperado para el filtrado\n";
echo "   C) Problema en la función renderStudies()\n";
echo "   D) Problema con el selector de la tabla\n\n";

echo "🧪 VERIFICANDO DATOS DEL USUARIO TUCUMAN INFORMANTES...\n\n";

// Configuración de base de datos
$host = 'localhost';
$dbname = 'portal_estudios';
$username = 'root';
$password = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // 1. Verificar usuario
    echo "1. 📋 VERIFICANDO USUARIO:\n";
    $stmt = $pdo->prepare("SELECT id, username, user_type FROM users WHERE id = 10");
    $stmt->execute();
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($user) {
        echo "   ✅ Usuario encontrado: {$user['username']} (ID: {$user['id']}, Tipo: {$user['user_type']})\n\n";
        
        // 2. Verificar estudios asignados
        echo "2. 📋 VERIFICANDO ESTUDIOS ASIGNADOS:\n";
        $stmt = $pdo->prepare("
            SELECT sa.study_id, sa.user_id, sa.status,
                   s.patient_name, s.patient_id, s.study_date, s.study_time, 
                   s.modality, s.study_description
            FROM study_assignments sa
            LEFT JOIN studies s ON sa.study_id = s.id
            WHERE sa.user_id = 10 AND sa.status = 'active'
        ");
        $stmt->execute();
        $assignments = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo "   📊 Estudios asignados encontrados: " . count($assignments) . "\n\n";
        
        foreach ($assignments as $assignment) {
            echo "   📋 ESTUDIO ASIGNADO:\n";
            echo "      - Study ID: {$assignment['study_id']}\n";
            echo "      - Paciente: {$assignment['patient_name']}\n";
            echo "      - ID Paciente: {$assignment['patient_id']}\n";
            echo "      - Fecha: {$assignment['study_date']}\n";
            echo "      - Hora: {$assignment['study_time']}\n";
            echo "      - Modalidad: {$assignment['modality']}\n";
            echo "      - Descripción: {$assignment['study_description']}\n";
            echo "      - Estado: {$assignment['status']}\n\n";
            
            // 3. Verificar formato de fecha
            echo "   🔍 ANÁLISIS DE FORMATO DE FECHA:\n";
            $studyDate = $assignment['study_date'];
            echo "      - Fecha original: '$studyDate'\n";
            echo "      - Formato esperado: YYYYMMDD (sin guiones)\n";
            echo "      - Longitud: " . strlen($studyDate) . " caracteres\n";
            
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $studyDate)) {
                echo "      - ⚠️  PROBLEMA DETECTADO: Fecha tiene formato YYYY-MM-DD\n";
                echo "      - 🔧 SOLUCIÓN: Convertir a YYYYMMDD para filtros\n";
                $convertedDate = str_replace('-', '', $studyDate);
                echo "      - ✅ Fecha convertida: '$convertedDate'\n";
            } elseif (preg_match('/^\d{8}$/', $studyDate)) {
                echo "      - ✅ Formato correcto: YYYYMMDD\n";
            } else {
                echo "      - ❌ Formato desconocido o inválido\n";
            }
            echo "\n";
        }
        
        // 4. Simular filtros por defecto
        echo "3. 🔍 SIMULANDO FILTROS POR DEFECTO:\n";
        echo "   - Sin filtro de fecha (modo estudios asignados)\n";
        echo "   - Sin filtro de ID de paciente\n";
        echo "   - Sin filtro de búsqueda general\n";
        echo "   - Sin filtro de modalidad\n";
        echo "   - ✅ El estudio debería pasar todos los filtros\n\n";
        
        // 5. Verificar estructura de la tabla HTML
        echo "4. 🔍 VERIFICANDO ESTRUCTURA HTML:\n";
        echo "   - Selector esperado: '.studies-table tbody'\n";
        echo "   - Archivo: dashboard-unified.html\n";
        echo "   - ⚠️  VERIFICAR: ¿Existe el elemento con clase 'studies-table'?\n\n";
        
    } else {
        echo "   ❌ Usuario con ID 10 no encontrado\n\n";
    }
    
} catch (PDOException $e) {
    echo "❌ Error de base de datos: " . $e->getMessage() . "\n\n";
}

echo "🔧 PASOS DE SOLUCIÓN RECOMENDADOS:\n\n";
echo "1. 📋 VERIFICAR SELECTOR HTML:\n";
echo "   - Abrir dashboard-unified.html\n";
echo "   - Buscar elemento con clase 'studies-table'\n";
echo "   - Verificar que tenga un <tbody>\n\n";

echo "2. 🔍 AGREGAR DEBUG EN JAVASCRIPT:\n";
echo "   - En applyLocalFilters(): console.log('this.studies:', this.studies);\n";
echo "   - En applyLocalFilters(): console.log('this.filteredStudies:', this.filteredStudies);\n";
echo "   - En renderStudies(): console.log('tbody encontrado:', !!tbody);\n\n";

echo "3. 🔧 VERIFICAR FORMATO DE FECHAS:\n";
echo "   - Si las fechas vienen como YYYY-MM-DD, convertir a YYYYMMDD\n";
echo "   - Modificar get_user_assigned_studies_fixed.php si es necesario\n\n";

echo "4. 🧪 PROBAR CON FILTROS DESHABILITADOS:\n";
echo "   - Comentar temporalmente todos los filtros en applyLocalFilters()\n";
echo "   - Asignar directamente: this.filteredStudies = this.studies;\n\n";

echo "=== FIN DEL DIAGNÓSTICO ===\n";
?>