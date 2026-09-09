# Local AET (Calling AET Alternativo)

**Fecha**: 2026-03-07  
**Versión**: 1.1.0  
**Autor**: Sistema TJSMEDICAL

---

## 📋 Resumen

El campo **Local AET** (también conocido como **LocalAet** en Orthanc) permite configurar un AET alternativo que Orthanc usará como **Calling AET** cuando actúe como SCU (Service Class User) hacia un peer específico. Esto permite diferenciar en los logs del PACS remoto las operaciones iniciadas desde el módulo **PACS Nodes Manager**.

---

## 🎯 Objetivo

Cuando Orthanc realiza operaciones C-FIND, C-MOVE o C-GET hacia un PACS remoto usando la API REST del módulo PACS Nodes Manager, el peer remoto verá estas asociaciones con un AET diferente al AET global de Orthanc, permitiendo:

1. **Identificación clara** en los logs del PACS remoto de las operaciones iniciadas desde el módulo
2. **Auditoría diferenciada** entre operaciones normales y operaciones del módulo
3. **Configuración de políticas** específicas en el PACS remoto para este AET alternativo

---

## 🔧 Configuración

### En Orthanc

Orthanc tiene dos niveles de configuración de AET:

#### 1. AET Principal (Global)
En `orthanc.json`:
```json
{
  "DicomAet": "IDNEOPACS",
  "DicomCheckCalledAet": false,
  "DicomPort": 4242
}
```

Este es el AET con el que Orthanc:
- Escucha conexiones entrantes (C-STORE, C-FIND, C-MOVE entrantes)
- Normalmente se usa como `TargetAet` cuando se solicita a un peer que envíe estudios

#### 2. Local AET (Por Modalidad)
En la definición de una modalidad (vía REST API o configuración extendida):
```json
{
  "IDDSEPACS": {
    "AET": "DCM4CHEE",
    "Host": "192.168.0.253",
    "Port": 4006,
    "LocalAet": "IDNEOPACS_QR"
  }
}
```

**LocalAet**: Hace que solo para este peer, Orthanc use `"IDNEOPACS_QR"` como Calling AET cuando actúa como SCU (C-FIND, C-MOVE, C-GET) hacia ese peer.

---

## 📝 Uso en PACS Nodes Manager

### Configuración del Campo

En el formulario de creación/edición de nodos, el campo **Local AET** está disponible en la sección de configuración DIMSE:

- **Campo**: `local_aet`
- **Tipo**: Texto (máximo 16 caracteres)
- **Opcional**: Sí
- **Ubicación**: Después del campo "AET" en el formulario

### Ejemplo de Configuración

```
Nombre del Nodo: IDDSEPACS
Tipo: DIMSE
AET: DCM4CHEE
Local AET: IDNEOPACS_QR  ← Campo nuevo
Host: 192.168.0.253
Puerto: 4006
```

### Comportamiento

1. **Si se especifica `local_aet`**:
   - Orthanc usará este AET como Calling AET cuando realice operaciones hacia este peer
   - En los logs del PACS remoto se verá: `Calling AET: IDNEOPACS_QR`

2. **Si NO se especifica `local_aet`**:
   - Orthanc usará su DicomAet global (`IDNEOPACS`) como Calling AET
   - Comportamiento estándar de Orthanc

---

## 🔄 Sincronización con Orthanc

Cuando se crea o actualiza un nodo DIMSE/Hybrid, el módulo sincroniza automáticamente la configuración con Orthanc usando la API REST:

```php
PUT /modalities/{nodeId}
{
  "AET": "DCM4CHEE",
  "Host": "192.168.0.253",
  "Port": 4006,
  "LocalAet": "IDNEOPACS_QR",  // ← Se incluye si está configurado
  "AllowFind": true,
  "AllowMove": true,
  ...
}
```

---

## 📊 Ejemplo Práctico

### Escenario

- **Orthanc Principal**: `DicomAet = "IDNEOPACS"`
- **Peer Remoto**: `AET = "DCM4CHEE"`
- **Nodo Configurado**: `Local AET = "IDNEOPACS_QR"`

### Operación C-MOVE

Cuando se ejecuta un retrieve desde el módulo PACS Nodes Manager:

1. El módulo llama a: `POST /modalities/IDDSEPACS/move`
2. Orthanc establece una asociación DICOM hacia `DCM4CHEE`
3. **Calling AET**: `IDNEOPACS_QR` (en lugar de `IDNEOPACS`)
4. **Called AET**: `DCM4CHEE`
5. En los logs de `DCM4CHEE` se verá:
   ```
   Association Request from: IDNEOPACS_QR
   Operation: C-MOVE
   ```

### Operación C-FIND

Similarmente, cuando se realiza una búsqueda:

1. El módulo llama a: `POST /modalities/IDDSEPACS/query`
2. Orthanc establece una asociación DICOM hacia `DCM4CHEE`
3. **Calling AET**: `IDNEOPACS_QR`
4. En los logs de `DCM4CHEE` se verá:
   ```
   Association Request from: IDNEOPACS_QR
   Operation: C-FIND
   ```

---

## 🗄️ Base de Datos

### Campo en la Tabla

```sql
ALTER TABLE `pacs_nodes` 
ADD COLUMN `local_aet` VARCHAR(16) DEFAULT NULL 
COMMENT 'AET alternativo (LocalAet) que Orthanc usará como Calling AET para este peer. Permite diferenciar operaciones del PACS Nodes Manager en los logs del PACS remoto.';
```

### Migración

Ejecutar el script de migración:
```bash
mysql -u usuario -p base_de_datos < modules/pacs-nodes-manager/database/migration_add_local_aet.sql
```

---

## 📚 Referencias

- [Documentación de Orthanc - DicomModalities](https://book.orthanc-server.com/users/configuration.html#dicom-modalities)
- [Documentación de Orthanc - REST API - Modalities](https://book.orthanc-server.com/users/rest.html#modalities)

---

## ✅ Checklist de Implementación

- [x] Campo `local_aet` agregado a la tabla `pacs_nodes`
- [x] Migración SQL creada
- [x] Campo agregado al formulario HTML
- [x] JavaScript actualizado para manejar el campo
- [x] API `nodes.php` actualizada para crear/actualizar con `local_aet`
- [x] `PacsNodeConfig::syncNodeWithOrthanc()` actualizado para incluir `LocalAet`
- [x] Documentación creada

---

## 🔍 Troubleshooting

### El Local AET no se está usando

1. Verificar que el campo esté configurado en la base de datos:
   ```sql
   SELECT id, name, aet, local_aet FROM pacs_nodes WHERE id = ?;
   ```

2. Verificar que la sincronización con Orthanc fue exitosa:
   ```bash
   curl -u orthanc:orthanc http://localhost:8042/modalities/{nodeId}
   ```
   Debe mostrar `"LocalAet": "IDNEOPACS_QR"` si está configurado.

3. Revisar los logs de sincronización:
   ```
   [PacsNodeConfig] Usando LocalAet: IDNEOPACS_QR para diferenciar operaciones del PACS Nodes Manager
   ```

### El AET no aparece en los logs del peer remoto

1. Verificar que el peer remoto acepta el AET como Calling AET válido
2. Verificar que no hay restricciones de seguridad en el peer remoto
3. Revisar los logs de Orthanc para ver qué AET está usando realmente

---

**Última actualización**: 2026-03-07
