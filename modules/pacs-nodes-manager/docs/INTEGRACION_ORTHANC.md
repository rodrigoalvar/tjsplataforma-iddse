# 🔌 Integración con Orthanc API - PACS NODES MANAGER

**Versión**: 1.0.0  
**Fecha**: 2026-01-26  
**Autor**: Sistema TJSMEDICAL

---

## 📋 Resumen

Este documento detalla cómo el módulo PACS NODES MANAGER utiliza la API REST de Orthanc para realizar operaciones DICOM estándar con nodos PACS remotos mediante dos protocolos:

1. **DIMSE** (C-FIND, C-MOVE, C-GET) - Para nodos legacy
2. **DICOMweb** (QIDO-RS, WADO-RS) - Para nodos modernos con streaming directo

El módulo actúa como proxy inteligente, detectando automáticamente el tipo de nodo y usando el protocolo apropiado.

---

## 🔧 Configuración de Nodos en Orthanc

### Endpoint: `PUT /modalities/{aet}`

Antes de poder realizar operaciones con un nodo remoto, debe estar configurado en Orthanc como una "modalidad remota" (remote modality).

**Request**:
```http
PUT http://orthanc-server:8042/modalities/HOSPITAL_CENTRAL
Authorization: Basic base64(username:password)
Content-Type: application/json
```

**Body**:
```json
{
  "AET": "HOSPITAL_CENTRAL",
  "Host": "192.168.1.100",
  "Port": 104,
  "Username": "dicom_user",
  "Password": "dicom_pass",
  "Manufacturer": "Generic",
  "CheckModality": true,
  "CheckFind": true,
  "CheckMove": true,
  "CheckStore": false
}
```

**Response (éxito)**:
```json
{
  "Status": "Success",
  "Message": "Modality configured"
}
```

**Implementación PHP**:
```php
public function syncNodeWithOrthanc($node) {
    $url = $this->orthancBaseUrl . '/modalities/' . $node['aet'];
    
    $data = [
        'AET' => $node['aet'],
        'Host' => $node['host'],
        'Port' => (int)$node['port'],
        'Manufacturer' => 'Generic'
    ];
    
    if (!empty($node['username'])) {
        $data['Username'] = $node['username'];
    }
    
    if (!empty($node['password'])) {
        $data['Password'] = $node['password'];
    }
    
    $response = $this->makeOrthancRequest('PUT', $url, $data);
    return $response;
}
```

---

## 🔍 Operación C-FIND (Búsqueda)

### Endpoint: `POST /modalities/{aet}/query`

Permite buscar estudios en un nodo PACS remoto usando consultas DICOM estándar.

**Request**:
```http
POST http://orthanc-server:8042/modalities/HOSPITAL_CENTRAL/query
Authorization: Basic base64(username:password)
Content-Type: application/json
```

**Body**:
```json
{
  "Level": "Study",
  "Query": {
    "PatientName": "*",
    "PatientID": "12345",
    "StudyDate": "20260101-20260301",
    "AccessionNumber": "ACC123",
    "ModalitiesInStudy": "CT"
  },
  "Timeout": 30
}
```

**Campos de Query DICOM comunes**:
- `PatientName`: Nombre del paciente (soporta wildcards `*`)
- `PatientID`: ID del paciente
- `PatientBirthDate`: Fecha de nacimiento (formato: YYYYMMDD)
- `StudyDate`: Fecha del estudio (formato: YYYYMMDD o rango YYYYMMDD-YYYYMMDD)
- `StudyTime`: Hora del estudio (formato: HHMMSS)
- `AccessionNumber`: Número de acceso
- `StudyInstanceUID`: UID único del estudio
- `StudyDescription`: Descripción del estudio
- `ModalitiesInStudy`: Modalidades (CT, MR, DX, US, etc.)
- `ReferringPhysicianName`: Nombre del médico solicitante
- `InstitutionName`: Nombre de la institución

**Response**:
```json
{
  "ID": "abc123-def456-ghi789",
  "Type": "DicomAnswers",
  "Content": {
    "0008,0020": {
      "Name": "StudyDate",
      "Type": "String",
      "Value": "20260115"
    },
    "0008,0030": {
      "Name": "StudyTime",
      "Type": "String",
      "Value": "143000"
    },
    "0010,0010": {
      "Name": "PatientName",
      "Type": "String",
      "Value": "APELLIDO^NOMBRE"
    },
    "0010,0020": {
      "Name": "PatientID",
      "Type": "String",
      "Value": "12345"
    },
    "0020,000D": {
      "Name": "StudyInstanceUID",
      "Type": "String",
      "Value": "1.2.840.113619.2.55.3.123456789"
    },
    "0008,1030": {
      "Name": "StudyDescription",
      "Type": "String",
      "Value": "CT Tórax"
    },
    "0008,0050": {
      "Name": "AccessionNumber",
      "Type": "String",
      "Value": "ACC123"
    },
    "0008,0061": {
      "Name": "ModalitiesInStudy",
      "Type": "String",
      "Value": "CT"
    }
  }
}
```

**Obtener resultados expandidos**:
```http
GET http://orthanc-server:8042/queries/{query_id}/answers
```

**Implementación PHP**:
```php
public function executeCFind($nodeAet, $query) {
    // 1. Ejecutar query
    $url = $this->orthancBaseUrl . '/modalities/' . $nodeAet . '/query';
    $response = $this->makeOrthancRequest('POST', $url, $query);
    
    if (!isset($response['ID'])) {
        throw new Exception('Error en C-FIND: no se obtuvo ID de query');
    }
    
    $queryId = $response['ID'];
    
    // 2. Obtener respuestas
    $answersUrl = $this->orthancBaseUrl . '/queries/' . $queryId . '/answers';
    $answers = $this->makeOrthancRequest('GET', $answersUrl);
    
    // 3. Procesar respuestas DICOM a formato JSON estructurado
    $results = [];
    foreach ($answers as $answer) {
        $results[] = $this->parseDicomAnswer($answer);
    }
    
    // 4. Limpiar query temporal
    $this->makeOrthancRequest('DELETE', $this->orthancBaseUrl . '/queries/' . $queryId);
    
    return $results;
}

private function parseDicomAnswer($answer) {
    $content = $answer['Content'] ?? [];
    
    return [
        'PatientName' => $content['0010,0010']['Value'] ?? '',
        'PatientID' => $content['0010,0020']['Value'] ?? '',
        'PatientBirthDate' => $content['0010,0030']['Value'] ?? '',
        'StudyInstanceUID' => $content['0020,000D']['Value'] ?? '',
        'StudyDate' => $content['0008,0020']['Value'] ?? '',
        'StudyTime' => $content['0008,0030']['Value'] ?? '',
        'StudyDescription' => $content['0008,1030']['Value'] ?? '',
        'AccessionNumber' => $content['0008,0050']['Value'] ?? '',
        'ModalitiesInStudy' => $content['0008,0061']['Value'] ?? '',
        'NumberOfStudyRelatedSeries' => $content['0020,1206']['Value'] ?? 0,
        'NumberOfStudyRelatedInstances' => $content['0020,1208']['Value'] ?? 0
    ];
}
```

---

## 📥 Operación C-MOVE (Recuperación)

### Endpoint: `POST /modalities/{aet}/move`

Permite recuperar estudios desde un nodo PACS remoto hacia Orthanc local usando C-MOVE.

**Request**:
```http
POST http://orthanc-server:8042/modalities/HOSPITAL_CENTRAL/move
Authorization: Basic base64(username:password)
Content-Type: application/json
```

**Body**:
```json
{
  "Level": "Study",
  "Resources": [
    {
      "Level": "Study",
      "ID": "1.2.840.113619.2.55.3.123456789"
    }
  ],
  "TargetAet": "ORTHANC",
  "Timeout": 300
}
```

**Parámetros**:
- `Level`: Nivel DICOM ("Patient", "Study", "Series", "Instance")
- `Resources`: Array de recursos a mover (cada uno con Level e ID)
- `TargetAet`: AET del Orthanc local (donde se recibirán los estudios)
- `Timeout`: Tiempo máximo en segundos (default: 300)

**Response**:
```json
{
  "ID": "job-abc123-def456",
  "Type": "MoveJob",
  "State": "Running",
  "Progress": 0,
  "Priority": 0,
  "CreationTime": "2026-01-26T14:30:00Z",
  "Content": {
    "Description": "Move to ORTHANC",
    "LocalAet": "ORTHANC",
    "RemoteAet": "HOSPITAL_CENTRAL",
    "Resources": [
      {
        "Level": "Study",
        "ID": "1.2.840.113619.2.55.3.123456789"
      }
    ]
  }
}
```

**Monitoreo del Job**:
```http
GET http://orthanc-server:8042/jobs/{job_id}
```

**Response del Job**:
```json
{
  "ID": "job-abc123-def456",
  "Type": "MoveJob",
  "State": "Success",
  "Progress": 100,
  "Priority": 0,
  "CreationTime": "2026-01-26T14:30:00Z",
  "EffectiveRuntime": 45.2,
  "Content": {
    "Description": "Move to ORTHANC",
    "LocalAet": "ORTHANC",
    "RemoteAet": "HOSPITAL_CENTRAL",
    "Resources": [...],
    "Status": "Success",
    "FailedInstances": 0,
    "Instances": 50
  }
}
```

**Estados posibles del Job**:
- `Pending`: Job creado pero no iniciado
- `Running`: Job en ejecución
- `Success`: Job completado exitosamente
- `Failure`: Job falló
- `Paused`: Job pausado
- `Retry`: Job en reintento

**Implementación PHP**:
```php
public function executeCMove($nodeAet, $studyInstanceUIDs, $targetAet = 'ORTHANC') {
    // 1. Preparar recursos
    $resources = [];
    foreach ($studyInstanceUIDs as $uid) {
        $resources[] = [
            'Level' => 'Study',
            'ID' => $uid
        ];
    }
    
    // 2. Ejecutar C-MOVE
    $url = $this->orthancBaseUrl . '/modalities/' . $nodeAet . '/move';
    $data = [
        'Level' => 'Study',
        'Resources' => $resources,
        'TargetAet' => $targetAet,
        'Timeout' => 300
    ];
    
    $response = $this->makeOrthancRequest('POST', $url, $data);
    
    if (!isset($response['ID'])) {
        throw new Exception('Error en C-MOVE: no se obtuvo ID de job');
    }
    
    return [
        'job_id' => $response['ID'],
        'status' => $response['State'] ?? 'Pending',
        'progress' => $response['Progress'] ?? 0
    ];
}

public function getJobStatus($jobId) {
    $url = $this->orthancBaseUrl . '/jobs/' . $jobId;
    $response = $this->makeOrthancRequest('GET', $url);
    
    return [
        'job_id' => $jobId,
        'status' => $response['State'] ?? 'Unknown',
        'progress' => $response['Progress'] ?? 0,
        'content' => $response['Content'] ?? null
    ];
}
```

---

## 📥 Operación C-GET (Alternativa a C-MOVE)

### Endpoint: `POST /modalities/{aet}/store`

C-GET es similar a C-MOVE pero el nodo remoto envía directamente los estudios (push) en lugar de que Orthanc los solicite (pull).

**Nota**: Orthanc no tiene un endpoint específico para C-GET, pero se puede usar `store` si el nodo remoto está configurado para enviar automáticamente.

**Alternativa**: Usar C-MOVE que es más estándar y mejor soportado.

---

## 🧪 Operación C-ECHO (Test de Conectividad)

### Endpoint: `POST /modalities/{aet}/echo`

Permite verificar la conectividad con un nodo PACS remoto.

**Request**:
```http
POST http://orthanc-server:8042/modalities/HOSPITAL_CENTRAL/echo
Authorization: Basic base64(username:password)
```

**Response (éxito)**:
```json
{
  "Status": "Success",
  "Message": "Echo successful"
}
```

**Response (error)**:
```json
{
  "Status": "Failure",
  "Message": "Connection timeout"
}
```

**Implementación PHP**:
```php
public function testConnection($nodeAet, $timeout = 10) {
    $startTime = microtime(true);
    
    try {
        $url = $this->orthancBaseUrl . '/modalities/' . $nodeAet . '/echo';
        $response = $this->makeOrthancRequest('POST', $url, null, $timeout);
        
        $responseTime = (microtime(true) - $startTime) * 1000; // ms
        
        return [
            'success' => true,
            'response_time_ms' => round($responseTime, 2),
            'status' => 'success'
        ];
    } catch (Exception $e) {
        return [
            'success' => false,
            'response_time_ms' => null,
            'status' => 'failed',
            'error' => $e->getMessage()
        ];
    }
}
```

---

## 🔄 Gestión de Queries Temporales

Orthanc almacena las queries C-FIND temporalmente. Es importante limpiarlas después de usarlas.

**Listar queries activas**:
```http
GET http://orthanc-server:8042/queries
```

**Eliminar query**:
```http
DELETE http://orthanc-server:8042/queries/{query_id}
```

**Implementación PHP (limpieza automática)**:
```php
public function cleanupQueries() {
    // Obtener todas las queries
    $queries = $this->makeOrthancRequest('GET', $this->orthancBaseUrl . '/queries');
    
    // Eliminar queries antiguas (más de 1 hora)
    foreach ($queries as $queryId) {
        $query = $this->makeOrthancRequest('GET', $this->orthancBaseUrl . '/queries/' . $queryId);
        
        $creationTime = strtotime($query['CreationTime'] ?? 'now');
        $age = time() - $creationTime;
        
        if ($age > 3600) { // 1 hora
            $this->makeOrthancRequest('DELETE', $this->orthancBaseUrl . '/queries/' . $queryId);
        }
    }
}
```

---

## 🚨 Manejo de Errores Comunes

### Error: "Unknown modality"

**Causa**: El nodo no está configurado en Orthanc.

**Solución**: Sincronizar nodo con `PUT /modalities/{aet}` antes de usar.

### Error: "Connection timeout"

**Causa**: El nodo remoto no responde o está inaccesible.

**Solución**: 
- Verificar conectividad de red
- Verificar que el puerto esté abierto
- Verificar firewall

### Error: "DICOM association rejected"

**Causa**: 
- AET incorrecto
- Credenciales incorrectas
- Nodo remoto no acepta conexiones

**Solución**:
- Verificar AET del nodo remoto
- Verificar username/password
- Contactar administrador del nodo remoto

### Error: "Query failed"

**Causa**: Query DICOM mal formada o campos no soportados.

**Solución**:
- Validar formato de query
- Verificar que los campos DICOM sean válidos
- Consultar documentación DICOM estándar

---

## 📊 Mejores Prácticas

1. **Cache de Queries**: Cachear resultados de C-FIND por 5 minutos para evitar consultas repetidas.

2. **Timeouts Apropiados**:
   - C-ECHO: 10 segundos
   - C-FIND: 30 segundos
   - C-MOVE: 300 segundos (5 minutos)

3. **Limpieza de Queries**: Limpiar queries temporales de Orthanc periódicamente.

4. **Monitoreo de Jobs**: Polling cada 2-5 segundos para jobs C-MOVE.

5. **Manejo de Errores**: Implementar retry con backoff exponencial para errores transitorios.

6. **Validación**: Validar todos los parámetros antes de enviar a Orthanc.

7. **Logging**: Registrar todas las operaciones para debugging y auditoría.

---

## 🔐 Seguridad

1. **Autenticación**: Todas las peticiones a Orthanc deben usar autenticación básica.

2. **Credenciales**: Almacenar credenciales de nodos encriptadas en BD.

3. **Validación de Inputs**: Validar y sanitizar todos los inputs antes de enviar a Orthanc.

4. **Rate Limiting**: Implementar límites de rate para prevenir abuso.

5. **Logs**: No registrar contraseñas en logs.

---

## 🌐 Operaciones DICOMweb (QIDO-RS / WADO-RS)

### Soporte en Orthanc

Orthanc soporta nativamente DICOMweb a través de sus endpoints REST. El módulo puede:

1. **Proxy directo**: Para nodos DICOMweb remotos, hacer proxy de las peticiones
2. **Usar Orthanc local**: Para nodos locales, usar los endpoints DICOMweb nativos de Orthanc
3. **Traducción DIMSE→DICOMweb**: Para nodos DIMSE, traducir las respuestas a formato DICOMweb

### Endpoints DICOMweb de Orthanc

Orthanc expone los siguientes endpoints DICOMweb:

#### QIDO-RS
```
GET /dicom-web/studies                    # Listar estudios
GET /dicom-web/studies/{studyUID}/series   # Listar series
GET /dicom-web/studies/{studyUID}/series/{seriesUID}/instances  # Listar instancias
```

#### WADO-RS
```
GET /dicom-web/studies/{studyUID}/series/{seriesUID}/instances/{sopInstanceUID}  # Obtener instancia
GET /dicom-web/studies/{studyUID}/series/{seriesUID}/instances/{sopInstanceUID}/frames/{frames}  # Obtener frames
```

### Proxy a Nodos DICOMweb Remotos

Cuando el nodo es de tipo `dicomweb`, el módulo actúa como proxy:

```php
public function proxyDicomwebRequest($node, $path, $queryParams) {
    $baseUrl = rtrim($node['dicomweb_url'], '/');
    $fullUrl = $baseUrl . '/' . $path;
    
    if (!empty($queryParams)) {
        $fullUrl .= '?' . http_build_query($queryParams);
    }
    
    // Preparar autenticación
    $headers = ['Content-Type: application/dicom+json'];
    
    if ($node['dicomweb_auth_type'] === 'basic' && 
        !empty($node['dicomweb_username']) && 
        !empty($node['dicomweb_password'])) {
        $headers[] = 'Authorization: Basic ' . base64_encode(
            $node['dicomweb_username'] . ':' . $node['dicomweb_password']
        );
    }
    
    // Hacer proxy de la petición
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $fullUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    // Retornar respuesta con headers apropiados
    header('Content-Type: application/dicom+json');
    http_response_code($httpCode);
    echo $response;
}
```

### Traducción DIMSE → DICOMweb

Para nodos DIMSE, el módulo puede traducir las respuestas a formato DICOMweb:

```php
public function dimseToDicomweb($node, $path, $queryParams) {
    // 1. Ejecutar C-FIND en Orthanc
    $results = $this->executeCFind($node['aet'], $this->buildDimseQuery($queryParams));
    
    // 2. Convertir resultados DIMSE a formato DICOMweb JSON
    $dicomwebResults = [];
    foreach ($results as $result) {
        $dicomwebResults[] = $this->convertDimseToDicomwebFormat($result);
    }
    
    // 3. Retornar en formato DICOMweb
    header('Content-Type: application/dicom+json');
    echo json_encode($dicomwebResults);
}

private function convertDimseToDicomwebFormat($dimseResult) {
    // Convertir formato DIMSE a formato DICOMweb JSON
    return [
        '00080020' => ['Value' => [$dimseResult['StudyDate']]],
        '00080030' => ['Value' => [$dimseResult['StudyTime']]],
        '00100010' => ['Value' => [['Alphabetic' => $dimseResult['PatientName']]]],
        '00100020' => ['Value' => [$dimseResult['PatientID']]],
        '0020000D' => ['Value' => [$dimseResult['StudyInstanceUID']]],
        '00081030' => ['Value' => [$dimseResult['StudyDescription']]],
        '00080050' => ['Value' => [$dimseResult['AccessionNumber']]],
        '00080061' => ['Value' => [$dimseResult['ModalitiesInStudy']]]
    ];
}
```

---

**Fin de Integración con Orthanc**

*Este documento debe actualizarse cuando cambien las APIs de Orthanc o se agreguen nuevas funcionalidades.*
