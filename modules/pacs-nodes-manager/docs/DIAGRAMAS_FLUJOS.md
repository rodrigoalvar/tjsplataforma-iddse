# 🔄 Diagramas de Flujos - PACS NODES MANAGER

**Versión**: 1.0.0  
**Fecha**: 2026-01-26  
**Autor**: Sistema TJSMEDICAL

---

## 📊 Flujo General del Módulo

```
┌─────────────┐
│   Usuario   │
└──────┬──────┘
       │
       ▼
┌─────────────────────────────────────┐
│   PACS NODES MANAGER (Frontend)    │
│  - Gestión de Nodos                 │
│  - Búsqueda C-FIND                  │
│  - Recuperación C-MOVE/C-GET        │
│  - Monitoreo de Jobs                │
└──────┬──────────────────────────────┘
       │
       │ HTTP REST API
       ▼
┌─────────────────────────────────────┐
│   API Endpoints (Backend PHP)       │
│  - /nodes.php                       │
│  - /find.php                        │
│  - /retrieve.php                    │
│  - /jobs.php                        │
└──────┬──────────────────────────────┘
       │
       ├──────────────────┬──────────────────┐
       ▼                  ▼                  ▼
┌──────────────┐  ┌──────────────┐  ┌──────────────┐
│   MySQL DB   │  │   Orthanc    │  │  Nodos PACS  │
│  - pacs_nodes│  │   API REST   │  │   Remotos    │
│  - jobs      │  │  - DicomMod  │  │  (C-FIND/    │
│  - queries   │  │  - C-MOVE    │  │   C-MOVE)    │
└──────────────┘  └──────────────┘  └──────────────┘
```

---

## 🔍 Flujo de Búsqueda C-FIND

```
Usuario
  │
  │ 1. Selecciona nodo + filtros
  ▼
Frontend (pacs-nodes-manager.js)
  │
  │ 2. POST /api/pacs-nodes-manager/find.php
  │    { node_id, Level, Query: {...} }
  ▼
Backend (find.php)
  │
  │ 3. Validar permisos y query
  │
  │ 4. Verificar cache
  │    SELECT * FROM pacs_node_queries
  │    WHERE node_id = X AND query_hash = Y
  │
  ├─► Cache Hit? ──SÍ──► Retornar resultados cacheados
  │
  └─► NO
      │
      │ 5. Obtener configuración del nodo
      │    SELECT * FROM pacs_nodes WHERE id = X
      │
      │ 6. Verificar que nodo esté en Orthanc
      │    GET /modalities/{aet}
      │
      │ 7. Ejecutar C-FIND en Orthanc
      │    POST /modalities/{aet}/query
      │    { Level: "Study", Query: {...} }
      │
      │ 8. Orthanc ejecuta C-FIND con nodo remoto
      │    ┌─────────────────────────────┐
      │    │ Orthanc ──C-FIND──► Nodo PACS│
      │    │         ◄──Respuesta─────────│
      │    └─────────────────────────────┘
      │
      │ 9. Procesar resultados DICOM → JSON
      │
      │ 10. Guardar en cache
      │     INSERT INTO pacs_node_queries
      │
      │ 11. Retornar resultados
      ▼
Frontend
  │
  │ 12. Mostrar resultados en tabla
  │
  │ 13. Usuario puede:
  │     - Ver detalles (preview)
  │     - Seleccionar para retrieve
  ▼
```

---

## 📥 Flujo de Recuperación C-MOVE

```
Usuario selecciona estudios
  │
  │ 1. POST /api/pacs-nodes-manager/retrieve.php
  │    { node_id, StudyInstanceUIDs: [...] }
  ▼
Backend (retrieve.php)
  │
  │ 2. Validar permisos y UIDs
  │
  │ 3. Crear job en BD
  │    INSERT INTO pacs_node_jobs
  │    (node_id, job_type, study_instance_uids, status='pending')
  │
  │ 4. Obtener configuración del nodo
  │
  │ 5. Ejecutar C-MOVE en Orthanc
  │    POST /modalities/{aet}/move
  │    {
  │      "Level": "Study",
  │      "Resources": [
  │        { "Level": "Study", "ID": "1.2.840..." }
  │      ],
  │      "TargetAet": "ORTHANC"  // AET local
  │    }
  │
  │ 6. Orthanc retorna job_id
  │    { "ID": "abc123-def456" }
  │
  │ 7. Actualizar job en BD
  │    UPDATE pacs_node_jobs
  │    SET orthanc_job_id = 'abc123', status = 'running'
  │
  │ 8. Retornar job_id al frontend
  ▼
Frontend
  │
  │ 9. Iniciar polling de progreso
  │    setInterval(() => {
  │      GET /api/pacs-nodes-manager/jobs.php?id={job_id}
  │    }, 2000)
  ▼
Backend (jobs.php)
  │
  │ 10. Consultar estado en Orthanc
  │     GET /jobs/{orthanc_job_id}
  │
  │ 11. Actualizar job en BD
  │     UPDATE pacs_node_jobs
  │     SET status = X, progress = Y
  │
  │ 12. Si completado:
  │     - status = 'success'
  │     - Llamar webhook (si existe)
  │     - Actualizar estadísticas
  │
  │ 13. Si falló:
  │     - status = 'failed'
  │     - Guardar error_message
  ▼
Frontend
  │
  │ 14. Mostrar progreso en UI
  │     [████████░░] 80%
  │
  │ 15. Cuando complete:
  │     - Notificar usuario
  │     - Opción de abrir en viewer
  ▼
```

---

## 🔧 Flujo de Gestión de Nodos

```
Usuario crea/edita nodo
  │
  │ 1. POST/PUT /api/pacs-nodes-manager/nodes.php
  │    {
  │      "name": "Hospital Central",
  │      "aet": "HOSPITAL_CENTRAL",
  │      "host": "192.168.1.100",
  │      "port": 104,
  │      "username": "user",
  │      "password": "pass"
  │    }
  ▼
Backend (nodes.php)
  │
  │ 2. Validar datos
  │    - AET único
  │    - IP válida
  │    - Puerto válido (1-65535)
  │
  │ 3. Encriptar contraseña
  │    password_hash($password, PASSWORD_BCRYPT)
  │
  │ 4. Guardar/Actualizar en BD
  │    INSERT/UPDATE pacs_nodes
  │
  │ 5. Sincronizar con Orthanc
  │    ┌─────────────────────────────────┐
  │    │ PUT /modalities/{aet}            │
  │    │ {                                │
  │    │   "AET": "HOSPITAL_CENTRAL",     │
  │    │   "Host": "192.168.1.100",      │
  │    │   "Port": 104,                   │
  │    │   "Username": "user",            │
  │    │   "Password": "pass"             │
  │    │ }                                │
  │    └─────────────────────────────────┘
  │
  │ 6. Si éxito:
  │    - Retornar nodo creado/actualizado
  │
  │ 7. Si error:
  │    - Rollback en BD (si nuevo)
  │    - Retornar error al usuario
  ▼
Frontend
  │
  │ 8. Actualizar lista de nodos
  │
  │ 9. Mostrar notificación de éxito/error
  ▼
```

---

## 🧪 Flujo de Test de Conectividad (Ping)

```
Usuario hace clic en "Test Ping"
  │
  │ 1. POST /api/pacs-nodes-manager/ping.php?id={node_id}
  ▼
Backend (ping.php)
  │
  │ 2. Obtener nodo de BD
  │
  │ 3. Verificar que nodo esté en Orthanc
  │    GET /modalities/{aet}
  │
  │ 4. Intentar C-ECHO (ping DICOM)
  │    POST /modalities/{aet}/echo
  │
  │ 5. Orthanc ejecuta C-ECHO con nodo remoto
  │    ┌─────────────────────────────┐
  │    │ Orthanc ──C-ECHO──► Nodo    │
  │    │         ◄──Respuesta────────│
  │    └─────────────────────────────┘
  │
  │ 6. Actualizar last_ping en BD
  │    UPDATE pacs_nodes
  │    SET last_ping = NOW(),
  │        last_ping_status = 'success'|'failed'
  │
  │ 7. Retornar resultado
  │    {
  │      "success": true,
  │      "response_time_ms": 150,
  │      "status": "success"
  │    }
  ▼
Frontend
  │
  │ 8. Mostrar indicador de status
  │    ✅ Conectado (150ms)
  │    ❌ Error de conexión
  ▼
```

---

## 📊 Flujo de Dashboard/Estadísticas

```
Usuario accede a Dashboard
  │
  │ 1. GET /api/pacs-nodes-manager/dashboard.php
  ▼
Backend (dashboard.php)
  │
  │ 2. Obtener estadísticas de todos los nodos
  │    SELECT 
  │      n.id, n.name, n.is_active,
  │      COUNT(DISTINCT q.id) as total_queries,
  │      COUNT(DISTINCT j.id) as total_jobs,
  │      SUM(CASE WHEN j.status='success' THEN 1 ELSE 0 END) as successful_jobs,
  │      SUM(s.studies_retrieved) as total_studies_retrieved
  │    FROM pacs_nodes n
  │    LEFT JOIN pacs_node_queries q ON n.id = q.node_id
  │    LEFT JOIN pacs_node_jobs j ON n.id = j.node_id
  │    LEFT JOIN pacs_node_statistics s ON n.id = s.node_id
  │    GROUP BY n.id
  │
  │ 3. Obtener estadísticas por fecha (últimos 30 días)
  │    SELECT date, queries_count, retrieves_count, studies_retrieved
  │    FROM pacs_node_statistics
  │    WHERE date >= DATE_SUB(NOW(), INTERVAL 30 DAY)
  │
  │ 4. Obtener jobs recientes
  │    SELECT * FROM pacs_node_jobs
  │    WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
  │    ORDER BY created_at DESC
  │    LIMIT 20
  │
  │ 5. Retornar datos agregados
  │    {
  │      "nodes": [...],
  │      "statistics": [...],
  │      "recent_jobs": [...],
  │      "summary": {
  │        "total_nodes": 5,
  │        "active_nodes": 4,
  │        "total_queries_today": 150,
  │        "total_retrieves_today": 25,
  │        "success_rate": 95.5
  │      }
  │    }
  ▼
Frontend
  │
  │ 6. Renderizar gráficos y tablas
  │    - Gráfico de uso por nodo
  │    - Gráfico de éxito/fallo
  │    - Tabla de jobs recientes
  │    - Métricas resumidas
  ▼
```

---

## 🔄 Flujo de Cache de Queries

```
Nueva búsqueda C-FIND
  │
  │ 1. Generar hash de query
  │    md5(json_encode([
  │      'node_id' => X,
  │      'Level' => 'Study',
  │      'Query' => {...}
  │    ]))
  ▼
Backend
  │
  │ 2. Buscar en cache
  │    SELECT * FROM pacs_node_queries
  │    WHERE node_id = X 
  │      AND query_hash = 'abc123...'
  │      AND expires_at > NOW()
  │
  ├─► Cache Hit?
  │   │
  │   ├─► SÍ ──► Retornar resultados cacheados
  │   │         (más rápido, sin consulta remota)
  │   │
  │   └─► NO ──► Continuar con C-FIND real
  │
  │ 3. Ejecutar C-FIND (ver flujo anterior)
  │
  │ 4. Guardar en cache
  │    INSERT INTO pacs_node_queries
  │    (
  │      node_id,
  │      query_hash,
  │      query_params,
  │      results_count,
  │      results_data,  -- Opcional: guardar resultados completos
  │      expires_at = NOW() + INTERVAL 5 MINUTE
  │    )
  │
  │ 5. Limpiar cache expirado (job periódico)
  │    DELETE FROM pacs_node_queries
  │    WHERE expires_at < NOW()
  ▼
```

---

## 🚨 Flujo de Manejo de Errores

```
Operación (C-FIND, C-MOVE, etc.)
  │
  │ 1. Intentar operación
  ▼
Backend
  │
  ├─► Éxito ──► Continuar flujo normal
  │
  └─► Error
      │
      ├─► Error de Conectividad
      │   │
      │   ├─► Timeout
      │   │   └─► Retry con backoff exponencial
      │   │       (1s, 2s, 4s, 8s, max 3 intentos)
      │   │
      │   └─► Conexión rechazada
      │       └─► Marcar nodo como inactivo
      │           UPDATE pacs_nodes SET is_active = 0
      │
      ├─► Error DICOM
      │   │
      │   ├─► AET no encontrado
      │   │   └─► Verificar configuración en Orthanc
      │   │
      │   ├─► Query inválida
      │   │   └─► Validar formato de query
      │   │
      │   └─► Error de autenticación
      │       └─► Verificar credenciales
      │
      └─► Error de Base de Datos
          │
          └─► Log error + Retornar mensaje genérico
              (no exponer detalles técnicos)
      │
      ▼
Frontend
  │
  │ 2. Mostrar mensaje de error amigable
  │    "No se pudo conectar con el nodo. Verifique la configuración."
  │
  │ 3. Opción de reintentar
  │
  │ 4. Log detallado en servidor
  │    error_log("[PACS_NODES] Error: " . $e->getMessage())
  ▼
```

---

## 🔐 Flujo de Autenticación y Permisos

```
Request a API
  │
  │ 1. Verificar token de sesión
  ▼
Backend (_auth.php)
  │
  ├─► Token válido?
  │   │
  │   ├─► NO ──► HTTP 401 Unauthorized
  │   │
  │   └─► SÍ ──► Continuar
  │
  │ 2. Obtener usuario de sesión
  │
  │ 3. Verificar permiso específico
  │    SELECT * FROM user_permissions
  │    WHERE user_id = X
  │      AND permission_key = 'pacs_nodes_manager'
  │
  ├─► Tiene permiso?
  │   │
  │   ├─► NO ──► HTTP 403 Forbidden
  │   │         "No tienes permisos para gestionar nodos PACS"
  │   │
  │   └─► SÍ ──► Continuar con operación
  │
  │ 4. Log de acceso
  │    INSERT INTO access_logs
  │    (user_id, endpoint, timestamp)
  ▼
```

---

**Fin de Diagramas de Flujos**

*Estos diagramas deben actualizarse cuando cambien los flujos de trabajo del módulo.*
