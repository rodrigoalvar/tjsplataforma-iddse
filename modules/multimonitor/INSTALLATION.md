# Instalación — Multi-Monitor WorkSpace

## Pre-requisitos

- Sistema TJSMEDICAL con núcleo instalado (tablas `system_permissions` y `user_permissions` presentes)
- Navegador basado en Chromium 100+ para aprovechamiento completo de la Window Management API
- Equipo con dos o más monitores para activar la funcionalidad (en un solo monitor el módulo se instala pero el ícono no se muestra)

## Paso 1: Verificar compatibilidad

**Navegador:**
`https://<host>/modules/multimonitor/install.php`

**CLI:**
```bash
php /var/www/tjsiddse/modules/multimonitor/install.php
```

El script verifica que existan las tablas `system_permissions` y `user_permissions`. No crea tablas adicionales (módulo 100% frontend).

## Paso 2: Registrar permisos

`https://<host>/modules/multimonitor/install-permissions.php`

Registra el permiso `feature_multimonitor` en `system_permissions` bajo la categoría `interfaz`.
Este script es **idempotente**: puede ejecutarse varias veces sin error.

Verificar que aparezca el mensaje de éxito: **"Creado: feature_multimonitor"** (o "Actualizado" si ya existía).

## Paso 3: Asignar el permiso a usuarios

1. Ir a **Gestión de Usuarios** (`/user-management.html`)
2. Editar la cuenta de prueba → sección **Interfaz/GUI**
3. Activar el permiso **"Multi-Monitor: WorkSpace en segundo monitor"**
4. Guardar

Los usuarios `root` o con permiso `all` tienen el acceso automáticamente.

## Paso 4: Verificar

1. Iniciar sesión con la cuenta que tiene el permiso
2. Con dos monitores conectados → debe aparecer el ícono 🖥️ en el sidebar header
3. Con un solo monitor → el ícono no aparece (comportamiento correcto)
4. Clic en el ícono → modal selector de monitores
5. Seleccionar monitor secundario → WorkSpace se abre en nueva ventana
6. Verificar que el enlace a WorkSpace desaparece del sidebar
7. Cerrar la ventana de WorkSpace → el enlace debe reaparecer

## Rollback

```
https://<host>/modules/multimonitor/uninstall.php
```

Elimina el permiso `feature_multimonitor` de `system_permissions` y sus asignaciones a usuarios.
No modifica ningún archivo ni tabla de datos del núcleo.

## Troubleshooting

| Problema | Solución |
|---|---|
| Ícono no aparece con 2 monitores | Verificar que el permiso `feature_multimonitor` esté asignado al usuario |
| Modal no muestra los monitores correctamente | El browser puede requerir permiso `window-management`; aceptar el prompt |
| Ventana no se abre en el monitor correcto | Fallback activo (browser no soporta Window Management API); posicionar manualmente |
| Enlace WorkSpace no reaparece al cerrar | Recargar el dashboard; el `BroadcastChannel` restaura el estado |
| Popup bloqueado | Permitir ventanas emergentes para el sitio en configuración del navegador |

## Notas de compatibilidad

- **Window Management API** (`getScreenDetails()`): Chromium 100+, Edge 100+. Requiere permiso del usuario.
- **BroadcastChannel**: soportado en todos los navegadores modernos (Chrome, Firefox, Edge, Safari 15.4+).
- **Fallback**: si el browser no soporta Window Management API, se usa una estimación de posición del segundo monitor basada en `screen.width`. La ventana puede no quedar exactamente en el monitor correcto pero el usuario puede moverla manualmente.
