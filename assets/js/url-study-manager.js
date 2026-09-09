/**
 * URL Study Manager - Maneja la variable global URLESTUDIO
 */
class URLStudyManager {
    constructor() {
        this.STORAGE_KEY = 'URLESTUDIO';
        this.loadURLESTUDIO();
        window.URLESTUDIO = this.urlestudio;
        
        window.addEventListener('storage', (e) => {
            if (e.key === this.STORAGE_KEY) {
                this.loadURLESTUDIO();
                window.URLESTUDIO = this.urlestudio;
                this.notifyChange();
            }
        });
    }
    
    loadURLESTUDIO() {
        try {
            const stored = sessionStorage.getItem(this.STORAGE_KEY);
            this.urlestudio = stored ? JSON.parse(stored) : null;
        } catch (error) {
            console.warn('Error loading URLESTUDIO:', error);
            this.urlestudio = null;
        }
    }
    
    saveURLESTUDIO() {
        try {
            if (this.urlestudio) {
                sessionStorage.setItem(this.STORAGE_KEY, JSON.stringify(this.urlestudio));
            } else {
                sessionStorage.removeItem(this.STORAGE_KEY);
            }
            window.URLESTUDIO = this.urlestudio;
            this.notifyChange();
        } catch (error) {
            console.error('Error saving URLESTUDIO:', error);
        }
    }
    
    setActiveStudy(studyData) {
        this.urlestudio = {
            patientId: studyData.patientId,
            patientName: studyData.patientName,
            modality: studyData.modality,
            studyDescription: studyData.studyDescription,
            studyId: studyData.studyId,
            studyInstanceUID: studyData.studyInstanceUID,
            studyDate: studyData.studyDate || studyData.date || studyData.study_date || '',
            startedAt: Date.now(),
            lastModified: Date.now()
        };
        this.saveURLESTUDIO();
        console.log('URLESTUDIO establecido:', this.urlestudio);
    }
    
    getActiveStudy() {
        return this.urlestudio;
    }
    
    hasActiveStudy() {
        return this.urlestudio !== null && this.urlestudio !== undefined;
    }
    
    isActiveStudy(studyId) {
        return this.hasActiveStudy() && this.urlestudio.studyId === studyId;
    }
    
    clearActiveStudy() {
        this.urlestudio = null;
        this.saveURLESTUDIO();
        console.log('URLESTUDIO limpiado');
    }
    
    getEditorURL() {
        if (!this.hasActiveStudy()) {
            // Determinar la ruta correcta según la ubicación actual
            const currentPath = window.location.pathname;
            const basePath = currentPath.includes('/components/') ? '../' : '';
            const url = `${basePath}components/editor.html`;
            console.log('URL generada (sin estudio activo):', url, 'Desde:', currentPath);
            return url;
        }
        const params = new URLSearchParams();
        params.set('patientId', this.urlestudio.patientId);
        params.set('patientName', this.urlestudio.patientName);
        params.set('modality', this.urlestudio.modality);
        params.set('studyDescription', this.urlestudio.studyDescription);
        params.set('studyId', this.urlestudio.studyId);
        params.set('studyInstanceUID', this.urlestudio.studyInstanceUID);
        if (this.urlestudio.studyDate) {
            params.set('studyDate', this.urlestudio.studyDate);
        }
        
        // Determinar la ruta correcta según la ubicación actual
        const currentPath = window.location.pathname;
        const basePath = currentPath.includes('/components/') ? '../' : '';
        const url = `${basePath}components/editor.html?${params.toString()}`;
        console.log('URL generada (con estudio activo):', url, 'Desde:', currentPath);
        return url;
    }
    
    hasPersistedContent() {
        if (!this.hasActiveStudy()) return false;
        const key = `editor_persistence_${this.urlestudio.studyId}_${this.urlestudio.patientId}`;
        const data = localStorage.getItem(key);
        if (data) {
            try {
                const parsed = JSON.parse(data);
                // Si hasUnsavedChanges es explícitamente false, no hay contenido no guardado
                if (parsed.hasUnsavedChanges === false) {
                    return false;
                }
                // Solo considerar como "persistido" si tiene cambios sin guardar (true o undefined con contenido)
                return ((parsed.tinymce && parsed.tinymce.content && parsed.tinymce.content.trim().length > 200 &&
                         (parsed.hasUnsavedChanges === true || parsed.hasUnsavedChanges === undefined)) ||
                        (parsed.audio && (parsed.hasUnsavedChanges === true || parsed.hasUnsavedChanges === undefined)) ||
                        parsed.hasUnsavedChanges === true);
            } catch (e) { return false; }
        }
        return false;
    }
    
    notifyChange() {
        window.dispatchEvent(new CustomEvent('urlestudio-changed', {
            detail: { activeStudy: this.urlestudio, hasActiveStudy: this.hasActiveStudy() }
        }));
    }
}

window.urlStudyManager = new URLStudyManager();