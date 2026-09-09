# Copia de plantillas a Médicos Informantes

Implementación (2026-09-02): “asignar dueños” = **copiar** plantilla a cada cuenta `medico_informante`, sin cambiar la lógica de permisos existente.

---

## Modelo

- `usuario_id` = dueño (quién “tiene” la plantilla) — igual que antes  
- `creado_por` = quién la creó / quien ejecutó la copia  
- `copiado_de` = `plantillas.id` de la plantilla origen (NULL si no es copia)  
- `template_id` de la copia: `{origen}_u{userId}` (único por UNIQUE global)

No hay tabla de multi-owner ni copy-on-write.

---

## Archivos

| Archivo | Rol |
|---------|-----|
| `api/plantillas/plantillas_common.php` | Helpers: columnas, auth, `canCopy`, unique id |
| `api/plantillas/copy-to-users.php` | GET list-medicos / POST copy |
| `api/plantillas/list.php` | JOIN creador/dueño + `can_copy_to_owners` |
| `api/plantillas/save.php` | Setea `creado_por` al crear |
| `database/agregar_plantillas_creado_por.php` | Migración (también auto en APIs) |
| `assets/js/template-manager-module.js` | UI “Copiar a médicos…” |
| `components/informes-manager.html` | Cache bust JS |

---

## API copy-to-users

**GET** `?action=list-medicos`  
Usuarios `activo=1` y `rol=medico_informante`.

**POST**

```json
{
  "source_template_id": "rx_torax",
  "user_ids": [12, 15],
  "skip_if_exists": true
}
```

Permiso: `plantillas` + (`rol=transcriptor` **o** `ver_todas_plantillas` **o** `all`).

Si `skip_if_exists` y ya existe fila con `usuario_id` destino y `copiado_de` = id origen → omitir.

---

## UI

- Botón **Copiar a médicos…** visible si `can_copy_to_owners` y hay plantilla cargada/guardada.
- Lista muestra creador, dueño y badge “copia”.
- Meta bajo el editor: Creada por / Dueño / Es una copia.

---

## Pruebas

1. Transcriptor guarda plantilla → aparece botón copiar.  
2. Copia a 2 médicos → 2 filas nuevas con `usuario_id` distintos y mismo contenido.  
3. Médico A edita su copia → no cambia la de B ni la original.  
4. Segunda copia a A → omitida (`skipped_already_has_copy`).  
5. List con ver todas: se ven todas; sin ver todas: solo propias/familia.

---

*Última actualización: 2026-09-02*
