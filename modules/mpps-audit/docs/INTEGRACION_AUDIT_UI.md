# Integración UI — Pestaña en Auditoría

Parches mínimos de núcleo para mostrar **MPPS / Equipos** dentro de Auditoría.

## Archivos tocados

1. `audit-manager.html`
2. `modules/audit-manager/assets/js/audit-manager.js`

No se modifica `app-container.html`, `dashboard-unified.html` ni `sidebar-gui-manager.js` en Fase 1.

## 1. Tab en `audit-manager.html`

Añadir en `#auditTabs` (después de Estadísticas):

```html
<li class="nav-item" role="presentation">
    <button class="nav-link" id="auditTabMpps" data-bs-toggle="tab" data-bs-target="#paneMpps" type="button" role="tab">
        MPPS / Equipos
        <span class="badge rounded-pill bg-info text-dark ms-1 d-none" id="auditMppsMockBadge">Demo</span>
    </button>
</li>
```

Añadir pane `#paneMpps` con cards (`mppsStatTotal`, `mppsStatInProgress`, `mppsStatOrphan`, `mppsStatGhost`), filtros y tabla `#mppsEventsTable` / `#mppsEventsBody`.

## 2. Init en `audit-manager.js`

En `init()`, tras el listener de Estadísticas, cargar el script del módulo al mostrar la pestaña:

```javascript
(function initMppsAuditTab() {
    const tab = el('auditTabMpps');
    if (!tab) return;
    function boot() {
        function afterReady() {
            if (window.MppsAuditTab) {
                window.MppsAuditTab.init();
                window.MppsAuditTab.reload();
            }
        }
        if (window.MppsAuditTab) { afterReady(); return; }
        const s = document.createElement('script');
        s.src = 'modules/mpps-audit/assets/js/mpps-audit-tab.js?v=202609021900';
        s.onload = afterReady;
        document.head.appendChild(s);
    }
    tab.addEventListener('shown.bs.tab', boot);
})();
```

## 3. Script del módulo

`modules/mpps-audit/assets/js/mpps-audit-tab.js` — no requiere estar en el HTML estático; se carga bajo demanda.

## Visibilidad

Misma página: requiere permiso `audit_manager` (chequeo existente al entrar a Auditoría).

## Revertir

1. Quitar `<li>` `#auditTabMpps` y `#paneMpps`.
2. Quitar bloque `initMppsAuditTab` de `audit-manager.js`.
3. (Opcional) `uninstall.php` del módulo.
