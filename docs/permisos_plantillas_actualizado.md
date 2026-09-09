# Permisos de Plantillas - Sistema Actualizado

## Resumen de Permisos

El sistema de plantillas ahora tiene **DOS permisos independientes**:

1. **"Gestión de Plantillas"** (`plantillas`)
2. **"Ver Todas"** (`ver_todas_plantillas`)

---

## 1. Permiso "Gestión de Plantillas" (`plantillas`)

### ¿Qué permite?
- ✅ **Usar el Gestor de Plantillas** (abrir el modal de gestión)
- ✅ **Crear plantillas** (cualquier usuario autenticado también puede hacerlo)
- ✅ **Editar SUS PROPIAS plantillas**
- ✅ **Eliminar SUS PROPIAS plantillas**

### ¿Qué NO permite sin "Ver Todas"?
- ❌ **Ver plantillas de otros usuarios** (solo ve las propias)
- ❌ **Editar plantillas de otros usuarios**
- ❌ **Eliminar plantillas de otros usuarios**

---

## 2. Permiso "Ver Todas" (`ver_todas_plantillas`)

### ¿Qué permite?
- ✅ **Ver plantillas de cualquier usuario** en el sistema
- ✅ **Ver plantillas del sistema** (usuario_id IS NULL)

### ¿Qué NO permite sin "Gestión de Plantillas"?
- ❌ **Usar el Gestor de Plantillas**
- ❌ **Editar plantillas de otros** (necesita también "Gestión de Plantillas")
- ❌ **Eliminar plantillas de otros** (necesita también "Gestión de Plantillas")

---

## 3. Combinación de Permisos

### Solo "Gestión de Plantillas" (`plantillas`)
- ✅ Usar el gestor de plantillas
- ✅ Ver solo SUS propias plantillas
- ✅ Crear sus propias plantillas
- ✅ Editar solo SUS propias plantillas
- ✅ Eliminar solo SUS propias plantillas

### Solo "Ver Todas" (`ver_todas_plantillas`)
- ✅ Ver plantillas de cualquier usuario
- ❌ NO puede usar el gestor de plantillas
- ❌ NO puede editar plantillas (ni propias ni de otros)
- ❌ NO puede eliminar plantillas (ni propias ni de otros)

### "Gestión de Plantillas" + "Ver Todas" (`plantillas` + `ver_todas_plantillas`)
- ✅ Usar el gestor de plantillas
- ✅ Ver TODAS las plantillas del sistema
- ✅ Crear sus propias plantillas
- ✅ Editar CUALQUIER plantilla (propias y de otros)
- ✅ Eliminar CUALQUIER plantilla (propias y de otros)

### Sin ninguno de los dos permisos
- ❌ NO puede usar el gestor de plantillas
- ✅ Ver solo SUS propias plantillas
- ✅ Crear sus propias plantillas
- ✅ Editar solo SUS propias plantillas
- ✅ Eliminar solo SUS propias plantillas

---

## Tabla de Resumen de Permisos

| Acción | Sin permisos | Solo "Gestión" | Solo "Ver Todas" | Ambos permisos |
|--------|--------------|----------------|------------------|-----------------|
| **Ver plantillas** | Solo propias | Solo propias | Todas | Todas |
| **Crear plantillas** | ✅ Sí (propias) | ✅ Sí (propias) | ✅ Sí (propias) | ✅ Sí (propias) |
| **Usar Gestor** | ❌ No | ✅ Sí | ❌ No | ✅ Sí |
| **Editar plantillas** | Solo propias | Solo propias | ❌ No | Cualquier plantilla |
| **Eliminar plantillas** | Solo propias | Solo propias | ❌ No | Cualquier plantilla |

---

## Lógica de Verificación en el Código

### Backend (PHP)

#### `api/plantillas/list.php` - Ver plantillas
```php
// Para ver todas las plantillas: necesita 'ver_todas_plantillas', 'plantillas' o 'all'
$can_view_all = in_array('all', $user_permisos) || 
               in_array('plantillas', $user_permisos) ||
               in_array('ver_todas_plantillas', $user_permisos);
```

#### `api/plantillas/save.php` - Editar plantillas
```php
// Para usar el gestor: necesita 'plantillas' o 'all'
$can_manage_plantillas = in_array('all', $user_permisos) || 
                        in_array('plantillas', $user_permisos);

// Para editar plantillas de otros: necesita:
// 1. 'plantillas' o 'all' (para usar el gestor)
// 2. 'ver_todas_plantillas', 'plantillas' o 'all' (para ver todas)
```

#### `api/plantillas/delete.php` - Eliminar plantillas
```php
// Misma lógica que save.php
// Para eliminar plantillas de otros: necesita ambos permisos
```

### Frontend (JavaScript)

#### `assets/js/informes-manager.js`
- `checkPlantillasPermission()`: Verifica permiso 'plantillas' o 'all' para abrir el gestor
- `openTemplateManager()`: Requiere permiso 'plantillas' o 'all'

---

## Notas Importantes

1. **Crear plantillas NO requiere ningún permiso especial**: Todos los usuarios autenticados pueden crear sus propias plantillas.

2. **"Gestión de Plantillas" es necesario para usar el gestor**: Sin este permiso, el botón "Gestionar" no se muestra.

3. **"Ver Todas" es necesario para ver plantillas de otros**: Sin este permiso, solo verá sus propias plantillas.

4. **Para editar/eliminar plantillas de otros, necesita AMBOS permisos**:
   - "Gestión de Plantillas" (para usar el gestor)
   - "Ver Todas" (para ver plantillas de otros)

5. **El permiso `all` otorga automáticamente todos los permisos**: Los usuarios con permiso `all` tienen automáticamente `plantillas` y `ver_todas_plantillas`.

---

## Ubicación en la Base de Datos

### Permiso "Gestión de Plantillas"
- **Tabla**: `system_permissions`
- **Clave**: `plantillas`
- **Nombre**: "Gestión de Plantillas"
- **Categoría**: `plantillas`
- **Descripción**: "Permite gestionar plantillas de informes"

### Permiso "Ver Todas"
- **Tabla**: `system_permissions`
- **Clave**: `ver_todas_plantillas`
- **Nombre**: "Ver Todas"
- **Categoría**: `plantillas`
- **Descripción**: "Permite ver las plantillas de cualquier usuario en el sistema"

