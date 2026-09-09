/**
 * Global State Manager
 * Maneja el estado global de informes en progreso para persistencia entre navegaciones
 */

class GlobalStateManager {
    constructor() {
        this.STORAGE_KEY = 'portal_global_state';
        this.SESSION_KEY = 'portal_session_state';
        this.state = this.loadState();
        
        // Escuchar cambios en el storage para sincronizar entre pestañas
        window.addEventListener('storage', (e) => {
            if (e.key === this.STORAGE_KEY) {
                this.state = this.loadState();
                this.notifyStateChange();
            }
        });
        
        // Limpiar estado al cerrar la ventana
        window.addEventListener('beforeunload', () => {
            this.cleanup();
        });
    }
    
    /**
     * Carga el estado desde sessionStorage
     */
    loadState() {
        try {
            const stored = sessionStorage.getItem(this.STORAGE_KEY);
            return stored ? JSON.parse(stored) : {
                activeReports: {},
                currentContext: null,
                navigationHistory: []
            };
        } catch (error) {
            console.warn('Error loading global state:', error);
            return {
                activeReports: {},
                currentContext: null,
                navigationHistory: []
            };
        }
    }
    
    /**
     * Guarda el estado en sessionStorage
     */
    saveState() {
        try {
            sessionStorage.setItem(this.STORAGE_KEY, JSON.stringify(this.state));
            // También disparar evento personalizado para notificar cambios
            window.dispatchEvent(new CustomEvent('globalStateChanged', {
                detail: this.state
            }));
        } catch (error) {
            console.error('Error saving global state:', error);
        }
    }
    
    /**
     * Establece un informe como activo/en progreso
     */
    setActiveReport(studyId, reportData) {
        if (!studyId) return;
        
        this.state.activeReports[studyId] = {
            ...reportData,
            timestamp: Date.now(),
            isActive: true
        };
        
        this.state.currentContext = {
            studyId,
            page: 'editor',
            timestamp: Date.now()
        };
        
        this.saveState();
    }
    
    /**
     * Obtiene información de un informe activo
     */
    getActiveReport(studyId) {
        return this.state.activeReports[studyId] || null;
    }
    
    /**
     * Verifica si un informe está activo
     */
    isReportActive(studyId) {
        const report = this.state.activeReports[studyId];
        return report && report.isActive;
    }
    
    /**
     * Obtiene todos los informes activos
     */
    getAllActiveReports() {
        return Object.keys(this.state.activeReports)
            .filter(studyId => this.state.activeReports[studyId].isActive)
            .reduce((active, studyId) => {
                active[studyId] = this.state.activeReports[studyId];
                return active;
            }, {});
    }
    
    /**
     * Marca un informe como completado/guardado
     */
    completeReport(studyId) {
        if (this.state.activeReports[studyId]) {
            this.state.activeReports[studyId].isActive = false;
            this.state.activeReports[studyId].completedAt = Date.now();
        }
        
        // Si era el contexto actual, limpiarlo
        if (this.state.currentContext && this.state.currentContext.studyId === studyId) {
            this.state.currentContext = null;
        }
        
        this.saveState();
    }
    
    /**
     * Elimina un informe del estado
     */
    removeReport(studyId) {
        delete this.state.activeReports[studyId];
        
        // Si era el contexto actual, limpiarlo
        if (this.state.currentContext && this.state.currentContext.studyId === studyId) {
            this.state.currentContext = null;
        }
        
        this.saveState();
    }
    
    /**
     * Establece el contexto actual de navegación
     */
    setCurrentContext(context) {
        this.state.currentContext = {
            ...context,
            timestamp: Date.now()
        };
        
        // Agregar a historial de navegación
        this.state.navigationHistory.push({
            ...context,
            timestamp: Date.now()
        });
        
        // Mantener solo los últimos 10 elementos del historial
        if (this.state.navigationHistory.length > 10) {
            this.state.navigationHistory = this.state.navigationHistory.slice(-10);
        }
        
        this.saveState();
    }
    
    /**
     * Obtiene el contexto actual
     */
    getCurrentContext() {
        return this.state.currentContext;
    }
    
    /**
     * Obtiene el historial de navegación
     */
    getNavigationHistory() {
        return this.state.navigationHistory;
    }
    
    /**
     * Verifica si hay contenido persistido para un estudio
     */
    hasPersistedContent(studyId, patientId) {
        // Verificar en localStorage usando las claves de persistence.js
        const storageKey = `editor_persistence_${studyId}_${patientId}`;
        const sessionKey = `editor_session_${studyId}_${patientId}`;
        
        try {
            const persistedData = localStorage.getItem(storageKey);
            const sessionData = sessionStorage.getItem(sessionKey);
            
            if (persistedData) {
                const data = JSON.parse(persistedData);
                
                // Verificar contenido significativo en TinyMCE
                if (data.tinymceContent && data.tinymceContent.length > 200) {
                    const content = data.tinymceContent.toLowerCase();
                    if (!content.includes('plantilla') && !content.includes('template') || content.length > 500) {
                        return true;
                    }
                }
                
                // Verificar audio guardado
                if (data.audioBlob || (data.recordings && data.recordings.length > 0)) {
                    return true;
                }
                
                // Verificar cambios no guardados explícitos
                if (data.hasUnsavedChanges === true) {
                    return true;
                }
            }
            
            return false;
        } catch (error) {
            console.warn('Error checking persisted content:', error);
            return false;
        }
    }
    
    /**
     * Sincroniza el estado con la persistencia local
     */
    syncWithPersistence() {
        // Revisar todos los informes activos y verificar si realmente tienen contenido
        const activeReports = { ...this.state.activeReports };
        let hasChanges = false;
        
        Object.keys(activeReports).forEach(studyId => {
            const report = activeReports[studyId];
            if (report.isActive) {
                const hasContent = this.hasPersistedContent(studyId, report.patientId);
                if (!hasContent) {
                    // No hay contenido real, marcar como inactivo
                    activeReports[studyId].isActive = false;
                    hasChanges = true;
                }
            }
        });
        
        if (hasChanges) {
            this.state.activeReports = activeReports;
            this.saveState();
        }
    }
    
    /**
     * Notifica cambios de estado a los listeners
     */
    notifyStateChange() {
        window.dispatchEvent(new CustomEvent('globalStateChanged', {
            detail: this.state
        }));
    }
    
    /**
     * Limpia el estado (llamado al cerrar ventana)
     */
    cleanup() {
        // Sincronizar una última vez antes de cerrar
        this.syncWithPersistence();
    }
    
    /**
     * Obtiene estadísticas del estado actual
     */
    getStats() {
        const activeCount = Object.keys(this.state.activeReports)
            .filter(studyId => this.state.activeReports[studyId].isActive).length;
            
        return {
            activeReports: activeCount,
            totalReports: Object.keys(this.state.activeReports).length,
            currentContext: this.state.currentContext,
            lastUpdate: Math.max(
                ...Object.values(this.state.activeReports).map(r => r.timestamp || 0)
            )
        };
    }
}

// Crear instancia global
window.globalStateManager = new GlobalStateManager();

// Exportar para uso en módulos
if (typeof module !== 'undefined' && module.exports) {
    module.exports = GlobalStateManager;
}