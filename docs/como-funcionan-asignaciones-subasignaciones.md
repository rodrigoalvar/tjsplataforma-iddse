# Cómo Funcionan las Asignaciones y Subasignaciones

## 📋 Resumen

El sistema de asignaciones y subasignaciones permite:
1. **Asignar estudios** a usuarios principales (desde Root/Admin)
2. **Derivar estudios** de usuarios principales a sus cuentas hijas (subasignaciones)
3. **Visualizar** la cadena completa de asignaciones y derivaciones

## 🔄 Flujo de Trabajo

### **Paso 1: Asignar Estudio a Usuario Principal**

**Quién lo hace:** Root o Admin

**Cómo:**
1. Abrir `estudios-manager.html`
2. Buscar estudios en PACS
3. Seleccionar estudio(s)
4. Click en "Asignar Seleccionados" o botón de asignación individual
5. Seleccionar usuario principal
6. Confirmar asignación

**Resultado en BD:**
```sql
INSERT INTO study_assignments (study_id, user_id, assigned_by, status)
VALUES ('EST001', 10, 1, 'active');
-- Estudio EST001 asignado a TUCUMAN INFORMANTES (ID: 10) por Admin Root (ID: 1)
```

### **Paso 2: Derivar Estudio a Cuenta Hija (Subasignación)**

**Quién lo hace:** Usuario principal (cuenta padre)

**Cómo:**
1. Usuario principal inicia sesión
2. Ve sus estudios asignados en `estudios-manager.html`
3. Selecciona estudio(s) para derivar
4. Click en "Derivar a hijos" (funcionalidad futura en UI)
5. Selecciona cuenta(s) hija(s)
6. Confirmar derivación

**Resultado en BD:**
```sql
INSERT INTO study_subassignments (
    study_id, 
    main_user_id, 
    subassigned_to_user_id, 
    assigned_by_user_id, 
    status
) VALUES ('EST001', 10, 11, 10, 'active');
-- Estudio EST001 derivado de TUCUMAN (ID: 10) a LUIS FAJRE (ID: 11)
```

**Importante:** La asignación principal en `study_assignments` NO se modifica.

## 📊 Visualización

### **Desde Root/Admin:**
```
Estudio EST001
├─ Asignado a: TUCUMAN INFORMANTES (principal)
└─ Derivaciones:
   ├─ LUIS FAJRE (hijo)
   ├─ SOLANA MEDICA (hijo)
   └─ NATALE MEDICO (hijo)
```

### **Desde Usuario Principal (TUCUMAN):**
```
Estudio EST001
├─ Asignado a mí
└─ Derivado a:
   ├─ LUIS FAJRE
   ├─ SOLANA MEDICA
   └─ NATALE MEDICO
```

### **Desde Usuario Hijo (LUIS FAJRE):**
```
Estudio EST001
└─ Recibido de: TUCUMAN INFORMANTES
```

## 🗄️ Estructura de Datos

### **Tabla `study_assignments`:**
```sql
id | study_id | user_id | assigned_by | status  | assigned_date
---|----------|---------|-------------|---------|---------------
1  | EST001   | 10      | 1           | active  | 2025-10-28
```

### **Tabla `study_subassignments`:**
```sql
id | study_id | main_user_id | subassigned_to_user_id | assigned_by_user_id | status  | subassigned_at
---|----------|--------------|------------------------|---------------------|---------|---------------
1  | EST001   | 10           | 11                     | 10                  | active  | 2025-10-28
2  | EST001   | 10           | 12                     | 10                  | active  | 2025-10-28
3  | EST001   | 10           | 13                     | 10                  | active  | 2025-10-28
```

## 🔍 Consultas Útiles

### **Ver todas las asignaciones:**
```sql
SELECT 
    sa.study_id,
    u.nombre,
    u.apellido,
    sa.assigned_date
FROM study_assignments sa
LEFT JOIN usuarios u ON sa.user_id = u.id
WHERE sa.status = 'active'
ORDER BY sa.assigned_date DESC;
```

### **Ver todas las subasignaciones:**
```sql
SELECT 
    ss.study_id,
    u_main.nombre as principal_nombre,
    u_sub.nombre as hijo_nombre,
    ss.subassigned_at
FROM study_subassignments ss
LEFT JOIN usuarios u_main ON ss.main_user_id = u_main.id
LEFT JOIN usuarios u_sub ON ss.subassigned_to_user_id = u_sub.id
WHERE ss.status = 'active'
ORDER BY ss.subassigned_at DESC;
```

### **Ver información completa de un estudio:**
```sql
SELECT 
    sa.study_id,
    u_main.nombre as asignado_a,
    ss.subassigned_to_user_id,
    u_sub.nombre as derivado_a,
    ss.subassigned_at
FROM study_assignments sa
LEFT JOIN usuarios u_main ON sa.user_id = u_main.id
LEFT JOIN study_subassignments ss ON sa.study_id = ss.study_id AND sa.user_id = ss.main_user_id
LEFT JOIN usuarios u_sub ON ss.subassigned_to_user_id = u_sub.id
WHERE sa.study_id = 'EST001' AND sa.status = 'active';
```

## 🎯 Casos de Uso

### **Caso 1: Estudio Solo Asignado (Sin Derivaciones)**
```
study_assignments:
- EST001 → TUCUMAN (ID: 10)

study_subassignments:
- (vacío)

Resultado:
- TUCUMAN ve EST001
- Hijos de TUCUMAN NO ven EST001
```

### **Caso 2: Estudio Asignado y Derivado**
```
study_assignments:
- EST001 → TUCUMAN (ID: 10)

study_subassignments:
- EST001: TUCUMAN (10) → LUIS (11)
- EST001: TUCUMAN (10) → SOLANA (12)

Resultado:
- TUCUMAN ve EST001 (asignado a él)
- LUIS ve EST001 (derivado de TUCUMAN)
- SOLANA ve EST001 (derivado de TUCUMAN)
- NATALE NO ve EST001 (no derivado a él)
```

### **Caso 3: Múltiples Asignaciones del Mismo Estudio**
```
study_assignments:
- EST001 → TUCUMAN (ID: 10)
- EST001 → BAIRES (ID: 14)

study_subassignments:
- EST001: TUCUMAN (10) → LUIS (11)
- EST001: BAIRES (14) → BAIRES1 (15)

Resultado:
- TUCUMAN ve EST001 y puede derivarlo a sus hijos
- BAIRES ve EST001 y puede derivarlo a sus hijos
- LUIS ve EST001 (derivado de TUCUMAN)
- BAIRES1 ve EST001 (derivado de BAIRES)
```

## ⚠️ Reglas Importantes

1. **Asignación Principal NO se Modifica:**
   - Cuando se crea una subasignación, la asignación en `study_assignments` permanece intacta
   - El usuario principal siempre mantiene acceso al estudio

2. **Solo Hijos Directos:**
   - Un usuario solo puede derivar a sus hijos directos (`padre_id = user_id`)
   - No se pueden hacer derivaciones a "nietos" u otros niveles

3. **Múltiples Derivaciones:**
   - Un estudio puede ser derivado a múltiples hijos
   - Cada derivación es independiente

4. **Estado Activo:**
   - Solo las asignaciones y subasignaciones con `status = 'active'` son visibles
   - Se puede desactivar sin eliminar el registro

## 🔧 APIs Disponibles

### **1. Obtener Asignaciones:**
```
GET api/get_study_assignments.php
```

### **2. Obtener Subasignaciones:**
```
GET api/get_subassignments.php
?study_id=EST001              // Filtrar por estudio
&main_user_id=10              // Filtrar por usuario principal
&subassigned_to_user_id=11    // Filtrar por usuario hijo
&status=active                // Filtrar por estado
```

### **3. Crear Subasignación:**
```
POST api/create_subassignment.php
{
    "study_id": "EST001",
    "main_user_id": 10,
    "subassigned_to_user_ids": [11, 12, 13],
    "assigned_by_user_id": 10
}
```

### **4. Información Completa:**
```
GET api/get_study_full_assignment_info.php?study_id=EST001
```

## 📝 Notas

- Las subasignaciones se crean manualmente o mediante la API
- El sistema está diseñado para soportar jerarquías de usuarios
- La visualización en `estudios-manager.html` es automática una vez que hay datos

