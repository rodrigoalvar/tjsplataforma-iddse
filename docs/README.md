# Documentación - Portal Estudios

## 📚 Índice de Documentación

### **Sistema de Permisos**
- **[Documentación Completa](permissions-system-documentation.md)** - Documentación técnica completa del sistema de permisos
- **[Guía Rápida](permissions-quick-guide.md)** - Implementación en 3 pasos
- **[Referencia de Código](permissions-code-reference.md)** - Ejemplos y referencias de código

### **Correcciones de Modales**
- **[Documentación de Correcciones](modal-fixes-documentation.md)** - Correcciones técnicas de modales
- **[Ejemplos de Código](modal-code-examples.md)** - Código y templates para modales
- **[Resumen Ejecutivo](modal-fixes-summary.md)** - Resumen de correcciones realizadas

### **Sistema de Permisos - Estudios Manager**
- **[Documentación de Permisos](estudios-manager-permissions-documentation.md)** - Implementación específica en estudios-manager

### **Control de Acceso - Dashboard**
- **[Control de Acceso Dashboard](dashboard-access-control-documentation.md)** - Sistema de permisos granular en dashboard-unified

### **APIs del Sistema**
- **[API de Worklist](WORKLIST_API.md)** - Documentación completa de la API REST para gestión de Worklist DICOM
- **[API de Informes Recibidos](INFORMES_RECIBIDOS_API.md)** - Documentación de la API para recibir informes PDF desde sistemas externos

## 🎯 Sistema de Permisos

### **Archivo Principal**
```
assets/js/simple-permission-manager.js
```

### **Implementación Estándar**
```javascript
document.addEventListener('DOMContentLoaded', async function() {
    const isAuthenticated = await requireAuth();
    if (!isAuthenticated) return;
    
    await requirePermissionSimple('PERMISSION_KEY', 'SECTION_NAME', {
        title: 'Acceso Denegado - SECTION_NAME',
        message: 'No tienes permisos para acceder a <strong>SECTION_NAME</strong>.',
        redirectUrl: 'dashboard-unified.html',
        showLogoutButton: true
    });
});
```

### **Secciones Implementadas**
- ✅ **Gestión Estudios** - `estudios-manager.html`
- ✅ **Gestión Informes** - `components/informes-manager.html`
- 🔄 **Gestión Usuarios** - Pendiente
- 🔄 **Visor DICOM** - Pendiente
- 🔄 **Gestión Plantillas** - Pendiente
- 🔄 **PACS Query** - Pendiente

## 🔧 Correcciones de Modales

### **Problemas Solucionados**
- ✅ **Backdrop sobre modal** - Z-index y pointer-events
- ✅ **Modal de acceso denegado** - Bootstrap modal profesional
- ✅ **Limpieza de backdrops** - Cleanup automático
- ✅ **Consistencia visual** - Mismos estilos en todos los modales

### **Archivos Afectados**
- `estudios-manager.html` - Modales de reasignación, desasignación, información
- `assets/js/estudios-manager.js` - Lógica de modales dinámicos
- `dashboard-unified.html` - Modal de antecedentes médicos
- `assets/js/dashboard-with-permissions.js` - Visualización de imágenes

## 📋 Archivos de Prueba

### **Sistema de Permisos**
- **`test-simple-permissions.html`** - Prueba de funcionalidad
- **`debug-permission-manager.html`** - Debug avanzado
- **`test-permissions-api.html`** - Prueba de API completa
- **`test-informes-permissions.html`** - Prueba específica de Gestión Informes

### **Modales**
- **`test-denied-message.html`** - Prueba de mensaje de acceso denegado

## 🚀 Uso Rápido

### **Implementar Permisos en Nueva Sección**
1. Incluir scripts necesarios
2. Agregar verificación de autenticación
3. Agregar verificación de permisos
4. Reemplazar variables (PERMISSION_KEY, SECTION_NAME)

### **Corregir Modal con Backdrop**
1. Agregar CSS específico para z-index
2. Inicializar Bootstrap modal con opciones correctas
3. Implementar cleanup de backdrops
4. Verificar pointer-events

## 📞 Soporte

Para dudas o problemas:
1. Revisar documentación correspondiente
2. Usar archivos de prueba para debug
3. Verificar consola del navegador
4. Consultar ejemplos de código

---

**Última actualización**: 24 de octubre de 2025  
**Versión**: 1.0  
**Estado**: Activo
