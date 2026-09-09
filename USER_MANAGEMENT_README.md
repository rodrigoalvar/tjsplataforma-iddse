# Sistema de Gestión de Usuarios Profesionales

## Descripción General

El Sistema de Gestión de Usuarios Profesionales es una implementación completa para el Portal TJSMEDICAL que permite administrar usuarios médicos con jerarquías, permisos granulares y control de acceso basado en roles.

## Características Principales

### 🔐 Sistema de Autenticación y Autorización
- **Autenticación segura** con tokens de sesión
- **Sistema de permisos granular** por sección del sistema
- **Jerarquías de usuarios** con relaciones padre-hijo
- **Protección de usuarios ROOT** contra modificaciones no autorizadas

### 👥 Gestión de Usuarios
- **CRUD completo** de usuarios profesionales
- **Registro automático** como USER por defecto
- **Promoción manual** a ADMIN/ROOT por administradores
- **Gestión de jerarquías** padre-hijo
- **Auditoría completa** de acciones

### 🎯 Sistema de Permisos
- **Permisos por nivel**: ROOT, ADMIN, USER
- **Permisos específicos** por funcionalidad del sistema
- **Herencia de permisos** según jerarquía
- **Validación en tiempo real** desde JavaScript

### 📊 Interfaz de Usuario
- **Dashboard de gestión** con estadísticas
- **Vista de lista** y **vista jerárquica**
- **Filtros avanzados** por nivel, estado, jerarquía
- **Modales intuitivos** para crear/editar usuarios
- **Integración completa** con el sidebar del dashboard

## Estructura del Sistema

### Archivos Principales

```
├── database/
│   └── user_management_system.sql    # Script de base de datos
├── api/users/
│   ├── manage.php                    # API principal CRUD
│   ├── assignable.php                # API usuarios asignables
│   ├── hierarchy.php                 # API gestión jerarquías
│   ├── check-permission.php          # API verificación permisos
│   └── permissions.php               # API permisos del sistema
├── middleware/
│   └── permissions.php               # Sistema de permisos
├── user-management.html              # Interfaz principal
├── user-management.js                # Lógica JavaScript
└── install-user-management.php       # Script de instalación
```

### Base de Datos

#### Tablas Principales
- **`usuarios`**: Información de usuarios con jerarquías
- **`system_permissions`**: Permisos disponibles del sistema
- **`user_audit_logs`**: Logs de auditoría
- **`user_sessions`**: Gestión de sesiones

#### Campos Clave
- **`nivel`**: ROOT, ADMIN, USER
- **`padre_id`**: Relación jerárquica
- **`permisos`**: JSON con permisos específicos
- **`activo`**: Estado del usuario

## Instalación

### 1. Ejecutar Script de Base de Datos
```bash
# Ejecutar el script SQL
mysql -u root -p tjsmedical < database/user_management_system.sql
```

### 2. Instalación Automática
```bash
# Acceder al script de instalación
http://tu-dominio.com/install-user-management.php
```

### 3. Configuración Manual
1. Ejecutar el script SQL
2. Verificar que se creó el usuario ROOT
3. Cambiar credenciales por defecto
4. Crear usuarios ADMIN según necesidad

## Uso del Sistema

### Niveles de Usuario

#### ROOT (Máxima Jerarquía)
- ✅ Acceso completo al sistema
- ✅ Puede gestionar todos los usuarios
- ✅ Puede crear usuarios ADMIN y USER
- ✅ Sin restricciones de jerarquía
- 🛡️ Protegido contra eliminación por ADMIN

#### ADMIN (Nivel Superior)
- ✅ Puede gestionar usuarios USER
- ✅ Puede crear usuarios USER
- ✅ Puede asignar jerarquías
- ✅ Puede derivar estudios a cualquier usuario
- ❌ No puede modificar usuarios ROOT

#### USER (Nivel Básico)
- ✅ Acceso limitado según permisos
- ✅ Puede derivar estudios solo a sus hijos
- ❌ No puede gestionar otros usuarios
- ❌ No puede cambiar jerarquías

### Flujo de Trabajo

#### 1. Registro de Usuarios
```
Usuario se registra → Nivel USER automático → Sin jerarquía → Espera asignación
```

#### 2. Gestión por ADMIN/ROOT
```
ADMIN/ROOT accede → Ve usuarios sin jerarquía → Asigna a jerarquías → Configura permisos
```

#### 3. Derivación de Estudios
```
ROOT/ADMIN deriva a cualquiera → USER deriva solo a hijos → Sin herencia automática
```

### Permisos del Sistema

#### Generales
- `dashboard`: Acceso al panel principal
- `all`: Acceso completo (solo ROOT)

#### Estudios
- `estudios`: Gestión de estudios médicos

#### Informes
- `informes`: Creación de informes
- `gestionInformes`: Gestión completa de informes

#### Audio y Multimedia
- `grabacion`: Grabación de audio
- `visor`: Acceso al visor DICOM

#### Administración
- `usuarios`: Gestión de usuarios
- `configuracion`: Configuración del sistema
- `plantillas`: Gestión de plantillas

## APIs Disponibles

### Gestión de Usuarios
```http
GET    /api/users/manage.php              # Listar usuarios
POST   /api/users/manage.php              # Crear usuario
PUT    /api/users/manage.php?id={id}      # Actualizar usuario
DELETE /api/users/manage.php?id={id}      # Eliminar usuario
```

### Usuarios Asignables
```http
GET /api/users/assignable.php?action=assignable           # Usuarios asignables
GET /api/users/assignable.php?action=without_hierarchy    # Sin jerarquía
GET /api/users/assignable.php?action=possible_parents    # Posibles padres
```

### Jerarquías
```http
GET    /api/users/hierarchy.php           # Obtener jerarquía
POST   /api/users/hierarchy.php           # Asignar a jerarquía
DELETE /api/users/hierarchy.php?user_id={id} # Remover de jerarquía
```

### Permisos
```http
POST /api/users/check-permission.php      # Verificar permiso
GET  /api/users/permissions.php           # Permisos del sistema
```

## Seguridad

### Validaciones Implementadas
- ✅ **Prevención de escalación**: USER no puede promoverse a ADMIN
- ✅ **Protección ROOT**: ADMIN no puede modificar usuarios ROOT
- ✅ **Validación de jerarquías**: Prevención de ciclos
- ✅ **Auditoría completa**: Log de todas las acciones críticas
- ✅ **Validación de permisos**: En cada endpoint y acción

### Mejores Prácticas
- 🔒 Cambiar credenciales por defecto
- 🔒 Usar HTTPS en producción
- 🔒 Revisar logs de auditoría regularmente
- 🔒 Implementar rate limiting
- 🔒 Backup regular de configuraciones

## Casos de Uso

### Caso 1: Nuevo Usuario se Registra
1. Usuario completa formulario de registro
2. Sistema crea cuenta como USER
3. ADMIN/ROOT ve usuario en lista "Sin jerarquía"
4. ADMIN/ROOT asigna usuario a jerarquía específica
5. Usuario hereda permisos según jerarquía

### Caso 2: Derivación de Estudios
1. ROOT/ADMIN asigna estudio a Dr. Juan (ADMIN)
2. Solo Dr. Juan ve el estudio
3. Dr. Juan deriva estudio a Dr. María (su hijo)
4. Ambos ven el estudio
5. Dr. María puede derivar a sus hijos si los tiene

### Caso 3: Gestión de Jerarquías
1. ADMIN crea Dr. Ana como cuenta padre
2. ADMIN asigna Dr. Pedro como hijo de Dr. Ana
3. Dr. Ana puede gestionar Dr. Pedro
4. Dr. Pedro hereda permisos de Dr. Ana
5. Sistema previene ciclos automáticamente

## Troubleshooting

### Problemas Comunes

#### Error: "No tienes permisos para gestionar usuarios"
- Verificar que el usuario tiene nivel ADMIN o ROOT
- Verificar que tiene permiso 'usuarios' asignado

#### Error: "No tienes permisos para asignar estudios"
- Verificar jerarquía: USER solo puede asignar a sus hijos
- Verificar que el usuario objetivo existe y está activo

#### Error: "No puedes crear un ciclo en la jerarquía"
- Verificar que no se está asignando un padre como hijo de su descendiente
- Revisar la jerarquía actual antes de hacer cambios

### Logs de Auditoría
```sql
-- Ver logs de auditoría
SELECT * FROM user_audit_logs 
ORDER BY created_at DESC 
LIMIT 50;

-- Ver acciones específicas
SELECT * FROM user_audit_logs 
WHERE action = 'hierarchy_assign' 
ORDER BY created_at DESC;
```

## Mantenimiento

### Tareas Regulares
- 📊 Revisar estadísticas de usuarios
- 🔍 Verificar logs de auditoría
- 🧹 Limpiar sesiones expiradas
- 🔄 Actualizar permisos según necesidades
- 📋 Backup de configuraciones

### Scripts de Mantenimiento
```sql
-- Limpiar sesiones expiradas
DELETE FROM user_sessions WHERE expires_at < NOW();

-- Verificar usuarios sin jerarquía
SELECT * FROM usuarios 
WHERE padre_id IS NULL 
AND nivel = 'user' 
AND activo = 1;

-- Estadísticas de uso
SELECT 
    nivel,
    COUNT(*) as total,
    AVG(TIMESTAMPDIFF(DAY, created_at, NOW())) as dias_promedio
FROM usuarios 
WHERE activo = 1 
GROUP BY nivel;
```

## Soporte

Para soporte técnico o consultas sobre el sistema:
- 📧 Revisar logs de error en el servidor
- 📋 Verificar configuración de base de datos
- 🔍 Consultar logs de auditoría
- 📚 Revisar esta documentación

---

**Sistema TJSMEDICAL - Portal de Estudios Médicos**  
*Versión 1.0 - Implementación completa de gestión de usuarios profesionales*


