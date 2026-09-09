# 📋 Detalles Técnicos: C-MOVE con API de Orthanc

**Fecha**: 2026-03-06  
**Versión**: 1.0.0  
**Autor**: Sistema TJSMEDICAL

---

## 🎯 Resumen

Este documento detalla cómo funciona la operación **C-MOVE** (recuperación de estudios) usando la API REST de Orthanc, incluyendo ejemplos prácticos, formato de requests/responses, y troubleshooting.

---

## 📡 Endpoint de Orthanc

### URL Base
```
POST http://{orthanc-host}:{orthanc-port}/modalities/{modality-id}/move
```

### Ejemplo Real
```
POST http://192.168.0.189:8042/modalities/NODE_1/move
```

### Autenticación
- **Tipo**: HTTP Basic Authentication
- **Header**: `Authorization: Basic {base64(username:password)}`
- **Ejemplo**: `Authorization: Basic b3J0aGFuYzpvcnRoYW5j`

---

## 📤 Request Format

### Headers Requeridos
```http
POST /modalities/NODE_1/move HTTP/1.1
Host: 192.168.0.189:8042
Authorization: Basic b3J0aGFuYzpvcnRoYW5j
Content-Type: application/json
```

### Body JSON (Formato Estándar)
```json
{
  "Level": "Study",
  "Resources": [
    {
      "Level": "Study",
      "ID": "1.3.12.2.1107.5.2.40.150109.30000026030605451436200000094"
    }
  ],
  "TargetAet": "ORTHANC",
  "Timeout": 300
}
```

### Parámetros Detallados

| Parámetro | Tipo | Requerido | Descripción |
|-----------|------|-----------|-------------|
| `Level` | string | ✅ Sí | Nivel DICOM: `"Patient"`, `"Study"`, `"Series"`, `"Instance"` |
| `Resources` | array | ✅ Sí | Array de objetos con `Level` e `ID` |
| `Resources[].Level` | string | ✅ Sí | Nivel del recurso (debe coincidir con `Level` principal) |
| `Resources[].ID` | string | ✅ Sí | **StudyInstanceUID** del estudio a recuperar |
| `TargetAet` | string | ✅ Sí | AET del Orthanc local donde se recibirán los estudios |
| `Timeout` | integer | ❌ No | Tiempo máximo en segundos (default: 300) |

### Ejemplo con Múltiples Estudios
```json
{
  "Level": "Study",
  "Resources": [
    {
      "Level": "Study",
      "ID": "1.3.12.2.1107.5.2.40.150109.30000026030605451436200000094"
    },
    {
      "Level": "Study",
      "ID": "1.3.12.2.1107.5.2.40.150109.30000026030605451436200000095"
    }
  ],
  "TargetAet": "ORTHANC",
  "Timeout": 600
}
```

---

## 📥 Response Format

### Response Exitoso (200 OK)
```json
{
  "ID": "a1b2c3d4-e5f6-7890-abcd-ef1234567890",
  "Type": "MoveJob",
  "State": "Running",
  "Progress": 0,
  "Priority": 0,
  "CreationTime": "2026-03-06T23:59:06.123456Z",
  "Content": {
    "Description": "Move to ORTHANC",
    "LocalAet": "ORTHANC",
    "RemoteAet": "NODE_1",
    "Resources": [
      {
        "Level": "Study",
        "ID": "1.3.12.2.1107.5.2.40.150109.30000026030605451436200000094"
      }
    ]
  }
}
```

### Response de Error (400/500)
```json
{
  "HttpError": "Bad Request",
  "HttpStatus": 400,
  "Message": "Unknown DICOM tag",
  "Method": "POST",
  "OrthancError": "BadRequest",
  "OrthancStatus": 3,
  "Uri": "/modalities/NODE_1/move"
}
```

---

## 🔧 Ejemplo con cURL

### Comando Básico
```bash
curl -X POST \
  http://192.168.0.189:8042/modalities/NODE_1/move \
  -u orthanc:orthanc \
  -H "Content-Type: application/json" \
  -d '{
    "Level": "Study",
    "Resources": [
      {
        "Level": "Study",
        "ID": "1.3.12.2.1107.5.2.40.150109.30000026030605451436200000094"
      }
    ],
    "TargetAet": "ORTHANC",
    "Timeout": 300
  }'
```

### Con Variables
```bash
ORTHANC_URL="http://192.168.0.189:8042"
ORTHANC_USER="orthanc"
ORTHANC_PASS="orthanc"
MODALITY_ID="NODE_1"
STUDY_UID="1.3.12.2.1107.5.2.40.150109.30000026030605451436200000094"
TARGET_AET="ORTHANC"

curl -X POST \
  "${ORTHANC_URL}/modalities/${MODALITY_ID}/move" \
  -u "${ORTHANC_USER}:${ORTHANC_PASS}" \
  -H "Content-Type: application/json" \
  -d "{
    \"Level\": \"Study\",
    \"Resources\": [
      {
        \"Level\": \"Study\",
        \"ID\": \"${STUDY_UID}\"
      }
    ],
    \"TargetAet\": \"${TARGET_AET}\",
    \"Timeout\": 300
  }"
```

---

## 📊 Monitoreo del Job

### Obtener Estado del Job
```bash
curl -u orthanc:orthanc \
  http://192.168.0.189:8042/jobs/{job-id}
```

### Response del Job Completado
```json
{
  "ID": "a1b2c3d4-e5f6-7890-abcd-ef1234567890",
  "Type": "MoveJob",
  "State": "Success",
  "Progress": 100,
  "Priority": 0,
  "CreationTime": "2026-03-06T23:59:06.123456Z",
  "EffectiveRuntime": 45.2,
  "Content": {
    "Description": "Move to ORTHANC",
    "LocalAet": "ORTHANC",
    "RemoteAet": "NODE_1",
    "Resources": [
      {
        "Level": "Study",
        "ID": "1.3.12.2.1107.5.2.40.150109.30000026030605451436200000094"
      }
    ],
    "Status": "Success",
    "FailedInstances": 0,
    "Instances": 50
  }
}
```

### Estados Posibles del Job

| Estado | Descripción |
|--------|-------------|
| `Pending` | Job creado pero no iniciado |
| `Running` | Job en ejecución |
| `Success` | Job completado exitosamente |
| `Failure` | Job falló |
| `Paused` | Job pausado |
| `Retry` | Job en reintento |

---

## 💻 Implementación Actual en el Código

### Ubicación
- **Archivo**: `modules/pacs-nodes-manager/PacsNodeClient.php`
- **Método**: `executeCMove()`
- **Línea**: ~424

### Código Actual
```php
public function executeCMove($node, $studyInstanceUIDs, $targetAet = 'ORTHANC') {
    // Validaciones
    if ($node['node_type'] === 'dicomweb' || !empty($node['dicomweb_url'])) {
        throw new Exception('C-MOVE no está disponible para nodos DICOMweb.');
    }
    
    if ($node['node_type'] !== 'dimse' && empty($node['aet'])) {
        throw new Exception('C-MOVE solo está disponible para nodos DIMSE');
    }
    
    // Preparar recursos
    $resources = [];
    foreach ($studyInstanceUIDs as $uid) {
        $resources[] = [
            'Level' => 'Study',
            'ID' => $uid
        ];
    }
    
    // Construir URL y datos
    $modalityId = $this->getOrthancModalityId($node);
    $url = $this->orthancBaseUrl . '/modalities/' . $modalityId . '/move';
    
    $data = [
        'Level' => 'Study',
        'Resources' => $resources,
        'TargetAet' => $targetAet ?: 'ORTHANC',
        'Timeout' => 300
    ];
    
    // Logging
    error_log("C-MOVE Request: URL=$url, ModalityId=$modalityId");
    error_log("C-MOVE Data: " . json_encode($data, JSON_PRETTY_PRINT));
    
    // Ejecutar request
    try {
        $response = $this->makeOrthancRequest('POST', $url, $data);
    } catch (Exception $e) {
        error_log("C-MOVE Error: " . $e->getMessage());
        throw new Exception('Error en C-MOVE: ' . $e->getMessage());
    }
    
    // Validar response
    if (!isset($response['ID'])) {
        throw new Exception('Error en C-MOVE: no se obtuvo ID de job');
    }
    
    return [
        'job_id' => $response['ID'],
        'status' => $response['State'] ?? 'Pending',
        'progress' => $response['Progress'] ?? 0
    ];
}
```

### Método `makeOrthancRequest()`
```php
private function makeOrthancRequest($method, $url, $data = null) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
    curl_setopt($ch, CURLOPT_USERPWD, $this->orthancCredentials['username'] . ':' . $this->orthancCredentials['password']);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    
    if ($method === 'POST' && $data) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    }
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error) {
        throw new Exception('Error de conexión con Orthanc: ' . $error);
    }
    
    if ($httpCode >= 400) {
        $errorMsg = json_decode($response, true);
        throw new Exception('Error HTTP ' . $httpCode . ': ' . ($errorMsg['Message'] ?? $response));
    }
    
    return json_decode($response, true) ?? [];
}
```

---

## 🔍 Análisis del Error "Unknown DICOM tag"

### Error Observado
```
Error HTTP 500: Unknown DICOM tag
```

### Posibles Causas

1. **Formato Incorrecto del Request**
   - ❌ El JSON no está bien formateado
   - ❌ Faltan campos requeridos
   - ❌ Tipos de datos incorrectos

2. **StudyInstanceUID Inválido**
   - ❌ El UID no existe en el nodo remoto
   - ❌ El UID tiene formato incorrecto
   - ❌ El UID no es un StudyInstanceUID válido

3. **Configuración del Nodo**
   - ❌ El nodo no está registrado en Orthanc
   - ❌ El `modality-id` no coincide con el AET
   - ❌ El nodo no tiene `AllowMove` habilitado

4. **Problemas de Conectividad**
   - ❌ El nodo remoto no responde
   - ❌ Firewall bloqueando la conexión
   - ❌ El nodo remoto rechaza la petición C-MOVE

5. **TargetAet Incorrecto**
   - ❌ El `TargetAet` no coincide con el AET del Orthanc local
   - ❌ El Orthanc local no está configurado para recibir estudios

---

## ✅ Checklist de Verificación

Antes de ejecutar C-MOVE, verificar:

- [ ] El nodo está registrado en Orthanc: `GET /modalities/{id}`
- [ ] El nodo tiene `AllowMove: true` en su configuración
- [ ] El `modality-id` usado coincide con el ID en Orthanc
- [ ] El `TargetAet` coincide con el AET del Orthanc local
- [ ] El StudyInstanceUID existe en el nodo remoto (verificar con C-FIND)
- [ ] La conectividad con el nodo remoto funciona (C-ECHO)
- [ ] El formato del JSON es correcto
- [ ] Los headers HTTP son correctos (Content-Type, Authorization)

---

## 🧪 Comandos de Prueba

### 1. Verificar Nodo en Orthanc
```bash
curl -u orthanc:orthanc \
  http://192.168.0.189:8042/modalities/NODE_1
```

### 2. Test de Conectividad (C-ECHO)
```bash
curl -X POST \
  -u orthanc:orthanc \
  http://192.168.0.189:8042/modalities/NODE_1/echo
```

### 3. Verificar StudyInstanceUID con C-FIND
```bash
curl -X POST \
  -u orthanc:orthanc \
  -H "Content-Type: application/json" \
  -d '{
    "Level": "Study",
    "Query": {
      "StudyInstanceUID": "1.3.12.2.1107.5.2.40.150109.30000026030605451436200000094"
    }
  }' \
  http://192.168.0.189:8042/modalities/NODE_1/find
```

### 4. Ejecutar C-MOVE (Prueba)
```bash
curl -X POST \
  -u orthanc:orthanc \
  -H "Content-Type: application/json" \
  -d '{
    "Level": "Study",
    "Resources": [
      {
        "Level": "Study",
        "ID": "1.3.12.2.1107.5.2.40.150109.30000026030605451436200000094"
      }
    ],
    "TargetAet": "ORTHANC",
    "Timeout": 300
  }' \
  http://192.168.0.189:8042/modalities/NODE_1/move
```

---

## 📝 Notas Importantes

1. **TargetAet**: Debe ser el AET del Orthanc local, no del nodo remoto
2. **Modality ID**: Puede ser diferente del AET del nodo si se usa `orthanc_node_id`
3. **StudyInstanceUID**: Debe ser un UID válido que exista en el nodo remoto
4. **Timeout**: Es opcional, pero recomendado para estudios grandes
5. **Asíncrono**: C-MOVE retorna inmediatamente un job ID, el proceso es asíncrono

---

## 🔗 Referencias

- [Documentación Oficial de Orthanc - C-MOVE](https://book.orthanc-server.com/users/rest.html#performing-c-move)
- [Documentación DICOM - C-MOVE](https://www.dicomstandard.org/current/)
- Documentación interna: `modules/pacs-nodes-manager/docs/INTEGRACION_ORTHANC.md`

---

**Última actualización**: 2026-03-06
