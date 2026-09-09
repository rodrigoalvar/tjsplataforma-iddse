# Visualización de Asignaciones y Subasignaciones en Estudios Manager

## 📋 Comportamiento por Tipo de Usuario

### **1. Root / Admin**
**Permisos:** `nivel = 'root'` o `nivel = 'admin'`

**Vista en estudios-manager.html:**
- ✅ Ve TODOS los estudios del PACS (si tiene PACS QUERY)
- ✅ Ve columna "Asignado a" con usuarios asignados
- ✅ Ve columna "Derivaciones" con subasignaciones
- ✅ Puede asignar estudios a cualquier usuario
- ✅ Puede ver detalles completos de asignaciones y derivaciones

**Ejemplo de visualización:**
```
| Estudio | Paciente | ... | Asignado a          | Derivaciones        | Acciones |
|---------|----------|-----|---------------------|---------------------|----------|
| EST001  | Juan     | ... | TUCUMAN INFORMANTES | 4 derivaciones [👁️] | Botones  |
| EST002  | María    | ... | Sin asignar         | Sin derivaciones    | Botones  |
| EST003  | Pedro    | ... | BAIRES INFORMANTES  | BAIRES1 MEDICO      | Botones  |
```

### **2. Cuenta Principal (Padre)**
**Permisos:** `nivel = 'user'` con hijos (`padre_id = NULL` o tiene usuarios con `padre_id = su_id`)

**Vista en estudios-manager.html:**
- ✅ Ve solo estudios asignados a su cuenta
- ✅ Ve columna "Asignado a" (muestra "Asignado a mí")
- ✅ Ve columna "Derivaciones" con sus subasignaciones
- ✅ Puede derivar estudios a sus cuentas hijas
- ✅ Mantiene visibilidad del estudio después de derivarlo

**Ejemplo de visualización:**
```
| Estudio | Paciente | ... | Asignado a    | Derivaciones        | Acciones |
|---------|----------|-----|---------------|---------------------|----------|
| EST001  | Juan     | ... | Asignado a mí | 4 derivaciones [👁️] | Botones  |
| EST002  | María    | ... | Asignado a mí | Sin derivaciones    | Botones  |
```

### **3. Cuenta Hija**
**Permisos:** `nivel = 'user'` con `padre_id != NULL`

**Vista en estudios-manager.html:**
- ✅ Ve solo estudios derivados a su cuenta
- ✅ Ve columna "Asignado a" (muestra cuenta principal)
- ❌ NO ve columna "Derivaciones" (no aplica)
- ❌ NO puede derivar estudios

**Ejemplo de visualización:**
```
| Estudio | Paciente | ... | Asignado a          | Acciones |
|---------|----------|-----|---------------------|----------|
| EST001  | Juan     | ... | TUCUMAN INFORMANTES | Botones  |
```

## 🔄 Flujo Completo

### **Paso 1: Root asigna estudio**
```
Root → Asigna EST001 a TUCUMAN INFORMANTES

Base de datos:
study_assignments:
- study_id: EST001
- user_id: 10 (TUCUMAN)
- assigned_by: 1 (Root)

Visualización:
- Root ve: EST001 → Asignado a TUCUMAN | Sin derivaciones
- TUCUMAN ve: EST001 → Asignado a mí | Sin derivaciones
- Hijos de TUCUMAN: NO ven EST001
```

### **Paso 2: TUCUMAN deriva a hijos**
```
TUCUMAN → Deriva EST001 a LUIS FAJRE, SOLANA MEDICA, NATALE MEDICO

Base de datos:
study_subassignments:
- study_id: EST001, main_user_id: 10, subassigned_to_user_id: 11 (LUIS)
- study_id: EST001, main_user_id: 10, subassigned_to_user_id: 12 (SOLANA)
- study_id: EST001, main_user_id: 10, subassigned_to_user_id: 13 (NATALE)

Visualización:
- Root ve: EST001 → Asignado a TUCUMAN | 3 derivaciones [Ver detalles]
- TUCUMAN ve: EST001 → Asignado a mí | 3 derivaciones [Ver detalles]
- LUIS ve: EST001 → Asignado a TUCUMAN | (sin columna derivaciones)
- SOLANA ve: EST001 → Asignado a TUCUMAN | (sin columna derivaciones)
- NATALE ve: EST001 → Asignado a TUCUMAN | (sin columna derivaciones)
```

## 🎨 Componentes de UI

### **Columna "Asignado a"**
Siempre visible para todos los usuarios.

**Formatos:**
- `Sin asignar` - Estudio no asignado (solo Root/Admin)
- `Usuario Nombre` - Asignación única con botones de acción
- `N usuarios` - Asignaciones múltiples con botón "Ver detalles"

### **Columna "Derivaciones"**
Visible solo si:
- Usuario es Root/Admin Y hay asignaciones o subasignaciones
- Usuario es cuenta principal Y tiene subasignaciones

**Formatos:**
- `Sin derivaciones` - No hay derivaciones
- `Usuario Nombre` - Derivación única con fecha
- `N derivaciones` - Múltiples derivaciones con botón "Ver detalles"

### **Modal "Detalles de Derivaciones"**
Muestra:
- Información del estudio
- Usuario principal asignado
- Lista de usuarios hijos derivados con:
  - Nombre completo
  - Email
  - Matrícula profesional
  - Fecha de derivación

## 📊 Lógica de Visualización

### **Determinar si mostrar columna "Derivaciones":**
```javascript
const hasSubassignments = Object.keys(this.studySubassignments).length > 0;
const isRootOrAdmin = this.currentUserLevel === 'root' || this.currentUserLevel === 'admin';
const hasAssignments = Object.keys(this.studyAssignments).length > 0;

const shouldShowColumn = (isRootOrAdmin && (hasAssignments || hasSubassignments)) || 
                         (!isRootOrAdmin && hasSubassignments);
```

### **Determinar visibilidad por fila:**
```javascript
const shouldShowSubassignments = (isRootOrAdmin && (hasAssignments || hasSubassignments)) || 
                                 (!isRootOrAdmin && hasSubassignments);

<td style="display: ${shouldShowSubassignments ? '' : 'none'};">
    ${subassignmentsInfo}
</td>
```

## 🔍 Consultas de Datos

### **Cargar Asignaciones:**
```javascript
await this.loadStudyAssignments();
// GET api/get_study_assignments.php
// Resultado: this.studyAssignments = { "EST001": [...], "EST002": [...] }
```

### **Cargar Subasignaciones:**
```javascript
await this.loadStudySubassignments();
// GET api/get_subassignments.php
// Resultado: this.studySubassignments = { "EST001": [...], "EST002": [...] }
```

### **Obtener datos por estudio:**
```javascript
const assignments = this.getStudyAssignments(studyId);
const subassignments = this.getStudySubassignments(studyId);
```

## ✅ Estado Actual

- ✅ Columna "Derivaciones" implementada
- ✅ Lógica de visibilidad por nivel de usuario
- ✅ Formato para 1 derivación
- ✅ Formato para múltiples derivaciones
- ✅ Modal de detalles de derivaciones
- ✅ Integración con APIs existentes
- ✅ Captura de nivel de usuario
- ✅ Renderizado dinámico

## 📝 Notas Importantes

1. **La asignación principal NO se modifica** al crear subasignaciones
2. **El usuario principal siempre ve sus estudios** incluso después de derivarlos
3. **Los hijos solo ven estudios derivados a ellos** explícitamente
4. **Root/Admin ven todo** el árbol de asignaciones y derivaciones
5. **La columna se oculta automáticamente** si no hay datos relevantes para el usuario

## 🚀 Próximos Pasos (Opcional)

1. **Botón "Derivar a hijos"** en la UI para cuentas principales
2. **API para crear derivaciones** desde el frontend
3. **Filtros por derivaciones** (con/sin derivaciones)
4. **Estadísticas** de derivaciones en el dashboard
5. **Notificaciones** cuando se deriva un estudio

