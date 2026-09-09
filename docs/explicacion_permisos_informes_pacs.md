# Explicación: Informes en PACS no visibles para usuarios sin permiso

## Problema Identificado

### Situación Actual:
- **Usuario ROOT**: Ve informes que están en PACS
- **Usuario luisfajre@mail.com**: NO ve informes que están en PACS

### Causa del Problema:

El sistema tiene un filtro de permisos en `api/informes/list.php` que funciona así:

```php
// Línea 232-234
if ($user_id && !$can_view_all) {
    $dataQuery .= " AND i.usuario_id = :user_id";
}
```

Esto significa:
- Si el usuario NO tiene el permiso `verTodosInformes` o `all`, **solo ve SUS propios informes**
- Si el usuario tiene el permiso `verTodosInformes` o `all`, ve **TODOS los informes** del sistema

---

## ¿Por qué pasa esto?

### Escenario Probable:

1. **Los informes en PACS fueron creados por otro usuario** (ej: ROOT o otro usuario)
2. **Usuario luisfajre@mail.com NO tiene el permiso `verTodosInformes` o `all`**
3. **Por lo tanto**, el sistema filtra los informes mostrando solo los que tienen `usuario_id = ID de luisfajre@mail.com`
4. **Los informes en PACS que fueron creados por otros usuarios NO aparecen** en su lista
5. **Como no aparecen en la lista, tampoco ve el badge "En PACS"** ni los botones relacionados

---

## Soluciones Posibles

### Opción 1: Asignar Permiso "Ver Todos Informes"
- Asignar el permiso `verTodosInformes` al usuario `luisfajre@mail.com`
- Esto le permitirá ver TODOS los informes del sistema, incluidos los que están en PACS
- **Consecuencia**: También verá informes de otros usuarios que NO están en PACS

### Opción 2: Cambiar la Lógica (Recomendado)
- Modificar el filtro para que los usuarios vean:
  - Sus propios informes
  - Informes en PACS (independientemente de quién los creó)
- Esto requiere modificar la consulta SQL en `api/informes/list.php`

### Opción 3: Verificar Permisos del Usuario
- Verificar qué permisos tiene el usuario `luisfajre@mail.com`
- Si debería tener `verTodosInformes` pero no lo tiene, asignárselo
- Si NO debería tenerlo, usar la Opción 2

---

## Verificación Necesaria

Para confirmar el problema, verificar:

1. **¿Qué informes están en PACS?**
   ```sql
   SELECT id, titulo, usuario_id, pacs_series_id, pacs_instance_id 
   FROM informes 
   WHERE pacs_series_id IS NOT NULL OR pacs_instance_id IS NOT NULL;
   ```

2. **¿Quién creó esos informes?**
   - Ver el campo `usuario_id` de los informes en PACS

3. **¿Qué permisos tiene luisfajre@mail.com?**
   ```sql
   SELECT id, email, permisos 
   FROM usuarios 
   WHERE email = 'luisfajre@mail.com';
   ```

4. **¿Tiene el permiso `verTodosInformes` o `all`?**
   - Si NO, ese es el problema

---

## Implementación de Solución Recomendada (Opción 2)

Si se desea que los usuarios vean:
- Sus propios informes (siempre)
- Informes en PACS (aunque no sean suyos)

Se puede modificar la consulta SQL para incluir informes que tienen `pacs_series_id` o `pacs_instance_id` incluso si no son del usuario.

