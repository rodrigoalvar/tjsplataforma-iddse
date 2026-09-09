# Implementación de Subasignaciones/Derivaciones de Estudios

## 📋 Resumen

Este documento describe la implementación de un sistema de subasignaciones que permite a usuarios principales derivar estudios a cuentas hijas sin modificar la asignación original.

## 🎯 Objetivo

Permitir rastrear y visualizar:
1. **Asignaciones principales**: Estudios asignados a cuentas principales
2. **Subasignaciones/Derivaciones**: Estudios derivados de cuentas principales a cuentas hijas
3. **Historial completo**: Ver el árbol completo de asignaciones y derivaciones

## 🗄️ Estructura de Base de Datos

### Tabla Nueva: `study_subassignments`

```sql
CREATE TABLE study_subassignments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    study_id VARCHAR(255) NOT NULL,
    main_user_id INT NOT NULL,              -- Usuario principal asignado
    subassigned_to_user_id INT NOT NULL,     -- Usuario hijo derivado
    assigned_by_user_id INT NOT NULL,        -- Usuario que hizo la derivación
    subassigned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    status ENUM('active', 'inactive') DEFAULT 'active',
    
    INDEX idx_study_main (study_id, main_user_id),
    INDEX idx_subassigned_to (subassigned_to_user_id),
    INDEX idx_main_user (main_user_id),
    INDEX idx_assigned_by (assigned_by_user_id),
    INDEX idx_status (status),
    
    FOREIGN KEY (main_user_id) REFERENCES usuarios(id) ON DELETE CASCADE,
    FOREIGN KEY (subassigned_to_user_id) REFERENCES usuarios(id) ON DELETE CASCADE,
    FOREIGN KEY (assigned_by_user_id) REFERENCES usuarios(id) ON DELETE CASCADE
);
```

### Campos Clave:
- `main_user_id`: Usuario principal que tiene asignado el estudio
- `subassigned_to_user_id`: Usuario hijo al que se derivó
- `assigned_by_user_id`: Usuario que realizó la derivación (generalmente el principal)
- `status`: Permite desactivar derivaciones sin eliminar el registro

## 📊 Flujo de Trabajo

### **Escenario 1: Root asigna estudio a Principal**
```
Acción: Root asigna EST001 a Principal
Resultado en BD:
- study_assignments: EST001 → Principal (assigned_by: Root)
```

### **Escenario 2: Principal deriva a Hijo 1**
```
Acción: Principal deriva EST001 a Hijo 1
Resultado en BD:
- study_assignments: EST001 → Principal (mantiene)
- study_subassignments: EST001 (main: Principal, subassigned: Hijo 1, assigned_by: Principal)
```

### **Escenario 3: Visualización según Rol**

#### **Desde Root/Admin:**
```
EST001 - Asignado Principal
       └─ Derivado a: Hijo 1 (por Principal)

EST002 - Asignado Principal

EST003 - Asignado Hijo 2
```

#### **Desde Principal:**
```
EST001 - Asignado a mí
       └─ Derivado a: Hijo 1

EST002 - Asignado a mí
       └─ Sin derivaciones
```

#### **Desde Hijo 1:**
```
EST001 - Recibido de: Principal
```

## 🔌 APIs Implementadas

### 1. `api/create_subassignment.php`
**Propósito**: Crear derivaciones de estudios

**Request:**
```json
{
    "study_id": "EST001",
    "main_user_id": 5,
    "subassigned_to_user_ids": [7, 8],
    "assigned_by_user_id": 5
}
```

**Response:**
```json
{
    "success": true,
    "message": "Estudio derivado exitosamente a 2 usuario(s)",
    "data": {
        "study_id": "EST001",
        "main_user_id": 5,
        "subassigned_count": 2,
        "assigned_by": 5,
        "subassigned_at": "2024-01-15 10:30:00"
    }
}
```

**Validaciones:**
- ✅ Verificar que el estudio esté asignado al usuario principal
- ✅ Verificar que los usuarios hijos sean realmente hijos del principal
- ✅ Evitar duplicados

### 2. `api/get_subassignments.php`
**Propósito**: Obtener derivaciones

**Query Params:**
- `study_id`: Filtrar por estudio específico
- `main_user_id`: Filtrar por usuario principal
- `subassigned_to_user_id`: Filtrar por usuario hijo
- `status`: Filtrar por estado (active/inactive)

**Response:**
```json
{
    "success": true,
    "data": {
        "subassignments": [
            {
                "id": 1,
                "study_id": "EST001",
                "main_user_id": 5,
                "subassigned_to_user_id": 7,
                "assigned_by_user_id": 5,
                "subassigned_at": "2024-01-15 10:30:00",
                "main_user_nombre": "Dr. Principal",
                "main_user_email": "principal@mail.com",
                "subassigned_user_nombre": "Dr. Hijo",
                "subassigned_user_email": "hijo@mail.com",
                "asignador_nombre": "Dr. Principal"
            }
        ],
        "total": 1
    }
}
```

### 3. `api/get_study_full_assignment_info.php`
**Propósito**: Obtener información completa de asignaciones y derivaciones

**Query Params:**
- `study_id` (requerido): ID del estudio

**Response:**
```json
{
    "success": true,
    "data": {
        "study_id": "EST001",
        "main_assignments": [
            {
                "id": 100,
                "assigned_to": 5,
                "user_nombre": "Dr. Principal",
                "assigned_date": "2024-01-10 09:00:00"
            }
        ],
        "subassignments": [
            {
                "main_assignment_id": 100,
                "main_user": {
                    "id": 5,
                    "nombre": "Dr. Principal"
                },
                "derivations": [
                    {
                        "subassigned_to_user_id": 7,
                        "user_nombre": "Dr. Hijo 1",
                        "subassigned_at": "2024-01-15 10:30:00"
                    }
                ]
            }
        ]
    }
}
```

## 🎨 Cambios en la UI

### 1. Columna "Derivaciones" en `estudios-manager.html`
**Para usuarios con nivel Root/Admin:**
```html
<thead>
    <tr>
        <th>Estudio</th>
        <th>Paciente</th>
        <th>Modalidad</th>
        <th>Derivaciones</th>  <!-- NUEVA -->
        <th>Fecha</th>
        <th>Acciones</th>
    </tr>
</thead>
```

### 2. Modal de Información de Derivaciones
```javascript
{
    estudio: "EST001",
    asignadoPrincipal: "Dr. Principal (principal@mail.com)",
    derivaciones: [
        {
            derivadoA: "Dr. Hijo 1 (hijo1@mail.com)",
            por: "Dr. Principal",
            fecha: "15/01/2024 10:30"
        }
    ]
}
```

### 3. Botón "Ver Derivaciones" en acciones
- Solo visible si hay derivaciones
- Abre modal con información completa

## 🔄 Cambios Necesarios en el Código Existente

### **Modificar `api/get_all_studies.php`:**
Agregar información de derivaciones a la respuesta:
```php
// Para cada estudio, agregar información de derivaciones
$subassignmentSQL = "SELECT ... FROM study_subassignments 
                      WHERE study_id = ? AND status = 'active'";
```

### **Modificar `assets/js/estudios-manager.js`:**
1. Agregar lógica para crear derivaciones
2. Agregar columna de derivaciones en la tabla
3. Implementar modal de información de derivaciones
4. Modificar función de asignación para soportar derivaciones

## ✅ Ventajas de esta Implementación

1. **No modifica estructura existente** - `study_assignments` permanece intacta
2. **Historial completo** - Se puede auditar quién derivó qué y cuándo
3. **Flexible** - Permite múltiples derivaciones del mismo estudio
4. **Trazable** - Cada derivación tiene timestamp y usuario responsable
5. **Reversible** - Status permite desactivar sin eliminar

## 🚀 Próximos Pasos

1. Ejecutar `sql/create_subassignments_table.sql`
2. Probar APIs con herramientas como Postman
3. Modificar `estudios-manager.js` para usar las nuevas APIs
4. Agregar UI para visualización de derivaciones
5. Documentar cambios en el manual de usuario

## 📝 Notas de Implementación

- El sistema **NO modifica** asignaciones existentes
- Un estudio puede ser derivado a múltiples hijos
- Las derivaciones pueden ser desactivadas (status = 'inactive')
- Solo el usuario principal puede derivar estudios a sus hijos

