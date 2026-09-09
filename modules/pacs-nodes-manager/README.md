# 📡 PACS NODES MANAGER - Módulo de Gestión de Nodos PACS Remotos

**Versión**: 1.0.0  
**Fecha**: 2026-01-26  
**Autor**: Sistema TJSMEDICAL

---

## 🎯 Descripción General

El módulo **PACS NODES MANAGER** permite gestionar nodos PACS remotos y realizar operaciones DICOM estándar (C-FIND, C-MOVE, C-GET) para buscar y recuperar estudios desde múltiples servidores DICOM remotos mediante la API de Orthanc.

---

## ✨ Características Principales

### 🔧 Gestión de Nodos
- ✅ **CRUD completo** de nodos PACS remotos
- ✅ **Configuración automática** en Orthanc `DicomModalities`
- ✅ **Test de conectividad** (C-ECHO) con indicadores de status
- ✅ **Autenticación** por nodo (username/password)
- ✅ **Validación** de configuración antes de guardar

### 🔍 Búsqueda C-FIND
- ✅ **Búsqueda avanzada** con filtros DICOM estándar
- ✅ **Soporte para múltiples filtros**: PatientID, PatientName, StudyDate, AccessionNumber, Modalidad
- ✅ **Cache inteligente** de resultados (TTL configurable)
- ✅ **Paginación** de resultados
- ✅ **Preview de estudios** antes de recuperar

### 📥 Recuperación C-MOVE/C-GET
- ✅ **Recuperación asincrónica** de estudios
- ✅ **Monitoreo en tiempo real** del progreso de jobs
- ✅ **Webhooks** para notificar llegada de estudios
- ✅ **Múltiples estudios** en una sola operación
- ✅ **Estadísticas** de recuperaciones

### 📊 Dashboard y Monitoreo
- ✅ **Estadísticas de uso** por nodo
- ✅ **Monitor de jobs** activos
- ✅ **Logs detallados** de operaciones
- ✅ **Tasa de éxito** de operaciones

### 🎨 Interfaz de Usuario
- ✅ **Tabla responsive** con nodos y status
- ✅ **Buscador unificado** (selección de nodo + filtros)
- ✅ **Preview de estudios** antes de retrieve
- ✅ **Integración con viewer** (botón "Buscar en otros PACS")
- ✅ **Notificaciones** en tiempo real

---

## 📁 Estructura del Módulo

```
modules/pacs-nodes-manager/
├── README.md                      # Este archivo
├── PacsNodesManager.php           # Clase principal
├── PacsNodeClient.php            # Cliente DICOM
├── PacsNodeConfig.php            # Gestor de configuración
├── install.php                   # Instalador
│
├── api/                           # Endpoints REST
│   ├── nodes.php                 # CRUD nodos
│   ├── ping.php                  # Test conectividad
│   ├── find.php                  # C-FIND
│   ├── retrieve.php              # C-MOVE/C-GET
│   ├── jobs.php                  # Monitoreo jobs
│   └── dashboard.php              # Estadísticas
│
├── config/                       # Configuración
│   └── nodes_config.php
│
├── database/                     # Scripts SQL
│   └── install.sql
│
├── docs/                         # Documentación
│   ├── ANALISIS_DISENO.md        # Análisis de diseño
│   ├── DIAGRAMAS_FLUJOS.md      # Diagramas de flujos
│   ├── INTEGRACION_ORTHANC.md   # Integración con Orthanc
│   ├── INSTALACION.md            # Guía de instalación
│   ├── USO.md                    # Guía de uso
│   └── API_REFERENCE.md          # Referencia de API
│
├── assets/                       # Recursos frontend
│   ├── js/
│   │   └── pacs-nodes-manager.js
│   └── css/
│       └── pacs-nodes-manager.css
│
└── logs/                         # Logs
    └── pacs-nodes.log
```

---

## 🚀 Inicio Rápido

### 1. Instalación

```bash
# Acceder al instalador desde el navegador
http://tu-dominio.com/modules/pacs-nodes-manager/install.php
```

El instalador creará:
- ✅ Tablas de base de datos
- ✅ Permisos del sistema
- ✅ Configuración inicial

### 2. Configurar Primer Nodo

1. Acceder a **PACS NODES MANAGER** desde el sidebar
2. Clic en **➕ Nuevo Nodo**
3. Completar formulario:
   - Nombre: "Hospital Central"
   - AET: "HOSPITAL_CENTRAL"
   - Host: "192.168.1.100"
   - Puerto: 104
   - Usuario/Contraseña (si aplica)
4. Clic en **Guardar**
5. El nodo se sincronizará automáticamente con Orthanc

### 3. Realizar Primera Búsqueda

1. Seleccionar nodo del dropdown
2. Ingresar filtros (ej: PatientID, StudyDate)
3. Clic en **🔍 Buscar**
4. Ver resultados en tabla
5. Seleccionar estudios y clic en **📥 Recuperar**

---

## 📚 Documentación Completa

### Para Desarrolladores
- **[ANALISIS_DISENO.md](docs/ANALISIS_DISENO.md)** - Análisis completo de diseño
- **[DIAGRAMAS_FLUJOS.md](docs/DIAGRAMAS_FLUJOS.md)** - Diagramas de flujos de trabajo
- **[INTEGRACION_ORTHANC.md](docs/INTEGRACION_ORTHANC.md)** - Detalles técnicos de integración con Orthanc
- **[API_REFERENCE.md](docs/API_REFERENCE.md)** - Referencia completa de endpoints

### Para Usuarios
- **[INSTALACION.md](docs/INSTALACION.md)** - Guía detallada de instalación
- **[USO.md](docs/USO.md)** - Guía de uso para usuarios finales

---

## 🔐 Permisos Requeridos

### Permisos del Sistema

1. **`pacs_nodes_manager`** (Funcional)
   - Requerido para todas las operaciones del módulo
   - Asignar a usuarios que necesiten gestionar nodos

2. **`gui_pacs_nodes_manager`** (Interfaz)
   - Requerido para mostrar en el sidebar
   - Asignar a usuarios que necesiten acceso visual

### Asignación de Permisos

1. Ir a **Gestión Usuarios** (`user-management.html`)
2. Seleccionar usuario
3. Asignar permisos:
   - ✅ `pacs_nodes_manager`
   - ✅ `gui_pacs_nodes_manager`

---

## 🔌 Endpoints API Principales

### Gestión de Nodos
```
GET    /api/pacs-nodes-manager/nodes.php              # Listar
POST   /api/pacs-nodes-manager/nodes.php              # Crear
PUT    /api/pacs-nodes-manager/nodes.php?id={id}      # Actualizar
DELETE /api/pacs-nodes-manager/nodes.php?id={id}      # Eliminar
```

### Operaciones DICOM
```
POST   /api/pacs-nodes-manager/ping.php?id={id}       # Test conectividad
POST   /api/pacs-nodes-manager/find.php               # Búsqueda C-FIND
POST   /api/pacs-nodes-manager/retrieve.php           # Recuperación C-MOVE
```

### Monitoreo
```
GET    /api/pacs-nodes-manager/jobs.php                # Listar jobs
GET    /api/pacs-nodes-manager/jobs.php?id={id}        # Estado de job
GET    /api/pacs-nodes-manager/dashboard.php           # Estadísticas
```

Ver **[API_REFERENCE.md](docs/API_REFERENCE.md)** para documentación completa.

---

## 🗄️ Modelo de Datos

### Tablas Principales

1. **`pacs_nodes`**: Configuración de nodos PACS remotos
2. **`pacs_node_queries`**: Cache de consultas C-FIND
3. **`pacs_node_jobs`**: Monitoreo de jobs asincrónicos
4. **`pacs_node_statistics`**: Estadísticas de uso

Ver **[ANALISIS_DISENO.md](docs/ANALISIS_DISENO.md)** para esquema completo.

---

## 🔄 Integración con Orthanc

El módulo utiliza la API REST de Orthanc para:

- **Configurar nodos**: `PUT /modalities/{aet}`
- **C-FIND**: `POST /modalities/{aet}/query`
- **C-MOVE**: `POST /modalities/{aet}/move`
- **C-ECHO**: `POST /modalities/{aet}/echo`
- **Monitoreo**: `GET /jobs/{id}`

Ver **[INTEGRACION_ORTHANC.md](docs/INTEGRACION_ORTHANC.md)** para detalles técnicos.

---

## 🎨 Interfaz de Usuario

### Página Principal

- **Lista de Nodos**: Tabla con status, última consulta, acciones
- **Buscador Unificado**: Dropdown de nodos + filtros DICOM
- **Resultados**: Tabla con estudios encontrados
- **Monitor de Jobs**: Lista de jobs activos con progreso
- **Dashboard**: Estadísticas y gráficos

### Integración con Viewer

Botón **"Buscar en otros PACS"** en:
- Pantalla de paciente (`paciente.html`)
- Visor DICOM (`viewer.html`)

Permite buscar y recuperar estudios directamente desde el contexto del paciente.

---

## 🧪 Testing

### Tests Incluidos

- ✅ Unit tests para clases PHP
- ✅ Integration tests para APIs
- ✅ E2E tests para flujos completos

### Ejecutar Tests

```bash
# Tests unitarios
php tests/unit/PacsNodeClientTest.php

# Tests de integración
php tests/integration/ApiTest.php
```

---

## 🐛 Troubleshooting

### El módulo no aparece en el sidebar

**Solución**:
1. Verificar permiso `gui_pacs_nodes_manager`
2. Limpiar caché del navegador
3. Verificar que `sidebar-gui-manager.js` esté cargado

### Error al crear nodo

**Solución**:
1. Verificar que Orthanc esté accesible
2. Verificar formato de AET (máx 16 caracteres)
3. Verificar IP y puerto válidos
4. Revisar logs en `logs/pacs-nodes.log`

### C-FIND no retorna resultados

**Solución**:
1. Verificar conectividad con `Test Ping`
2. Verificar que el nodo esté configurado en Orthanc
3. Verificar formato de query DICOM
4. Revisar logs de Orthanc

### C-MOVE falla o se queda colgado

**Solución**:
1. Verificar que el nodo remoto acepte C-MOVE
2. Verificar que el AET local esté configurado en Orthanc
3. Aumentar timeout si es necesario
4. Verificar logs del job en Orthanc

Ver documentación completa en **[docs/](docs/)** para más soluciones.

---

## 📊 Requisitos del Sistema

- **PHP**: 7.4+
- **MySQL/MariaDB**: 5.7+ (soporte JSON)
- **Orthanc**: 1.x o 2.x con API REST habilitada
- **Extensiones PHP**: `curl`, `json`, `openssl`
- **Navegador**: Chrome, Firefox, Edge, Safari (versiones recientes)

---

## 🔮 Roadmap

### Versión 1.1 (Próxima)
- [ ] Búsqueda simultánea en múltiples nodos
- [ ] Exportación de resultados a CSV/Excel
- [ ] Notificaciones push en tiempo real

### Versión 1.2 (Futuro)
- [ ] Sincronización automática de estudios
- [ ] Soporte para C-STORE (envío a nodos remotos)
- [ ] Integración con sistemas de scheduling

---

## 📝 Changelog

Ver **[docs/CHANGELOG.md](docs/CHANGELOG.md)** para historial completo de versiones.

### Versión 1.0.0 (2026-01-26)
- ✅ Implementación inicial
- ✅ CRUD de nodos
- ✅ C-FIND con cache
- ✅ C-MOVE con monitoreo
- ✅ Dashboard y estadísticas
- ✅ Integración con viewer

---

## 📞 Soporte

Para problemas o preguntas:

1. Revisar documentación en `docs/`
2. Verificar logs en `logs/pacs-nodes.log`
3. Revisar logs de Orthanc
4. Contactar al administrador del sistema

---

## 📄 Licencia

Este módulo es parte del Sistema TJSMEDICAL y está sujeto a la misma licencia del proyecto principal.

---

## 👥 Contribuciones

Las contribuciones son bienvenidas. Por favor:

1. Revisar la documentación de diseño
2. Seguir los estándares de código del proyecto
3. Actualizar documentación con cambios
4. Incluir tests para nuevas funcionalidades

---

**Última actualización**: 2026-01-26  
**Versión**: 1.0.0
