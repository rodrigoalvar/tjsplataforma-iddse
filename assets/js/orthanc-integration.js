/**
 * Integración de Orthanc con el Portal de Estudios
 * Maneja la comunicación con la API de Orthanc para obtener estudios de pacientes
 */
class OrthancIntegration {
    constructor() {
        this.apiBaseUrl = 'api/';
        /** Base pública para ZIP (/studies/{id}/archive), misma que pacs_download_url en BD */
        this._downloadBaseUrl = null;
        this._downloadBaseUrlPromise = null;
        this._pacsInformeImageState = { pages: [], index: 0, title: '' };
        this._pacsInformeNavBound = false;
    }

    /**
     * Resuelve la URL base de descargas (configuración PACS → pacs_download_url).
     * Equivale a ORTHANC_PUBLIC_HOST en medical-portal/config.php usado por download.php.
     */
    async ensureDownloadBaseUrl() {
        if (this._downloadBaseUrl) {
            return this._downloadBaseUrl;
        }
        if (this._downloadBaseUrlPromise) {
            return this._downloadBaseUrlPromise;
        }
        this._downloadBaseUrlPromise = (async () => {
            try {
                const response = await fetch(this.apiBaseUrl + 'config/get-download-url.php');
                const data = await response.json();
                if (data.success && data.download_url) {
                    return String(data.download_url).replace(/\/+$/, '');
                }
            } catch (e) {
                console.warn('No se pudo obtener download_url, usando fallback:', e);
            }
            return 'https://demoportal.tanjousoft.com.ar/visorweb';
        })();
        try {
            this._downloadBaseUrl = await this._downloadBaseUrlPromise;
        } finally {
            this._downloadBaseUrlPromise = null;
        }
        return this._downloadBaseUrl;
    }
    
    /**
     * Codifica string a base64 (compatible con todos los navegadores)
     */
    base64Encode(str) {
        try {
            return btoa(unescape(encodeURIComponent(str)));
        } catch (e) {
            // Fallback manual si btoa no está disponible
            const chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/=';
            let result = '';
            let i = 0;
            str = unescape(encodeURIComponent(str));
            while (i < str.length) {
                const a = str.charCodeAt(i++);
                const b = i < str.length ? str.charCodeAt(i++) : 0;
                const c = i < str.length ? str.charCodeAt(i++) : 0;
                const bitmap = (a << 16) | (b << 8) | c;
                result += chars.charAt((bitmap >> 18) & 63) + chars.charAt((bitmap >> 12) & 63) +
                    (i - 2 < str.length ? chars.charAt((bitmap >> 6) & 63) : '=') +
                    (i - 1 < str.length ? chars.charAt(bitmap & 63) : '=');
            }
            return result;
        }
    }
    
    /**
     * Decodifica string desde base64 (compatible con todos los navegadores)
     */
    base64Decode(str) {
        try {
            return decodeURIComponent(escape(atob(str)));
        } catch (e) {
            // Fallback manual si atob no está disponible
            const chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/=';
            str = str.replace(/[^A-Za-z0-9\+\/\=]/g, '');
            let result = '';
            let i = 0;
            while (i < str.length) {
                const enc1 = chars.indexOf(str.charAt(i++));
                const enc2 = chars.indexOf(str.charAt(i++));
                const enc3 = chars.indexOf(str.charAt(i++));
                const enc4 = chars.indexOf(str.charAt(i++));
                const bitmap = (enc1 << 18) | (enc2 << 12) | (enc3 << 6) | enc4;
                if (enc3 !== 64) result += String.fromCharCode((bitmap >> 16) & 255);
                if (enc4 !== 64) result += String.fromCharCode((bitmap >> 8) & 255);
            }
            return decodeURIComponent(escape(result));
        }
    }
    
    /**
     * Obtiene los estudios de un paciente desde Orthanc
     * @param {string} patientId - ID del paciente
     * @param {string} searchType - Tipo de búsqueda: 'documento' o 'id_interno'
     * @returns {Promise<Array>} - Lista de estudios
     */
    async getPatientStudies(patientId, searchType = 'documento') {
        try {
            const url = `${this.apiBaseUrl}get_patient_studies.php?patient_id=${encodeURIComponent(patientId)}&search_type=${encodeURIComponent(searchType)}`;
            const response = await fetch(url);

            const contentType = response.headers.get('content-type') || '';
            const isJsonResponse = contentType.toLowerCase().includes('application/json');

            // Manejar errores HTTP específicos antes de parsear JSON
            if (!response.ok) {
                if (response.status === 403 && isJsonResponse) {
                    const result403 = await response.json();
                    const error403 = new Error(result403.error || 'Acceso denegado');
                    error403.statusCode = 403;
                    error403.isInactivePatient = true;
                    throw error403;
                }

                const error = new Error(
                    response.status >= 500
                        ? 'El servidor PACS no responde temporalmente (HTTP ' + response.status + '). Intente nuevamente en unos minutos.'
                        : 'Error HTTP ' + response.status + ' al consultar estudios.'
                );
                error.statusCode = response.status;
                throw error;
            }

            if (!isJsonResponse) {
                const error = new Error('Respuesta inválida del servidor: se esperaba JSON y se recibió otro formato.');
                error.statusCode = response.status || 500;
                throw error;
            }

            const result = await response.json();

            if (!result.success) {
                const error = new Error(result.error);
                // Detectar si es error de paciente inactivo
                if (result.error && result.error.includes('inactivo')) {
                    error.isInactivePatient = true;
                }
                throw error;
            }
            
            return result;
        } catch (error) {
            // Solo loguear errores que no sean de paciente inactivo (para no saturar consola)
            if (!error.isInactivePatient) {
                console.error('Error obteniendo estudios del paciente:', error);
            }
            throw error;
        }
    }

    /**
     * Obtiene estudios desde Portal v2 (backend paralelo).
     */
    async getPatientStudiesV2(patientId, searchType = 'documento') {
        const url = `${this.apiBaseUrl}portal-estudios/v2/search.php?patient_id=${encodeURIComponent(patientId)}&search_type=${encodeURIComponent(searchType)}`;
        const response = await fetch(url);
        const data = await response.json().catch(() => ({}));
        if (!response.ok || data.success === false) {
            throw new Error(data.error || data.message || `Error HTTP ${response.status}`);
        }
        return data;
    }

    /**
     * Resuelve y abre URL final para un estudio de Portal v2.
     */
    async openStudyV2(study) {
        if (!study || !study.open_strategy || !study.open_strategy.type) {
            alert('No hay estrategia de apertura disponible para este estudio.');
            return;
        }
        const device = this.isMobileDevice() ? 'mobile' : 'desktop';
        const payload = {
            study_instance_uid: study.study_instance_uid || '',
            orthanc_id: study.orthanc_id || '',
            strategy_type: study.open_strategy.type,
            source_node_id: study.open_strategy.node_id || 0,
            device
        };
        try {
            const response = await fetch(`${this.apiBaseUrl}portal-estudios/v2/open.php`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });
            const data = await response.json().catch(() => ({}));
            if (!response.ok || !data.success || !data.open_url) {
                throw new Error(data.error || data.message || 'No se pudo resolver la URL de apertura');
            }
            window.open(data.open_url, '_blank');
        } catch (error) {
            console.error('Error abriendo estudio v2:', error);
            alert(error.message || 'No se pudo abrir el estudio');
        }
    }

    /**
     * Render de filas para Portal v2 (paridad con legacy: PDF / informe PACS encapsulado).
     */
    generateStudiesHTMLV2(studies) {
        if (!Array.isArray(studies) || studies.length === 0) {
            return `
                <tr>
                    <td colspan="4" class="text-center py-4">
                        <i class="fas fa-info-circle text-muted me-2"></i>
                        No se encontraron estudios para este paciente
                    </td>
                </tr>
            `;
        }

        const orthancIdsWithInforme = new Set();
        studies.forEach((study) => {
            if (study.orthanc_id && study.has_informe) {
                orthancIdsWithInforme.add(study.orthanc_id);
            }
        });

        const escapePath = (path) => (path || '').replace(/'/g, "\\'");

        return studies.map((study) => {
            const formattedDate = this.formatDate(study.study_date || '');
            const modality = study.modality || 'N/A';
            const desc = study.study_description || 'Sin descripción';

            if (study.is_informe && (study.pdf_path || (study.informe_view_strategy && study.informe_view_strategy !== 'none'))) {
                const informeStudyId = study.study_id;
                if (informeStudyId && orthancIdsWithInforme.has(informeStudyId)) {
                    return '';
                }
                const orphanPayload = encodeURIComponent(JSON.stringify(study));
                const orphanTitle = (study.study_description || 'Informe Médico').replace(/'/g, "\\'");
                return `
                    <tr>
                        <td>${formattedDate}</td>
                        <td>${modality}</td>
                        <td class="hide-mobile">${desc}</td>
                        <td>
                            <button class="btn btn-sm btn-success" onclick="orthancIntegration.viewInformeV2(JSON.parse(decodeURIComponent('${orphanPayload}')))" title="Ver informe médico">
                                <i class="fas fa-file-pdf me-1"></i> Ver informe
                            </button>
                        </td>
                    </tr>
                `;
            }

            const isPacsPdfInforme = study.is_pacs_pdf_informe === true;
            const canOpen = !!(study.open_strategy && study.open_strategy.type);
            const strategyType = (study.open_strategy && study.open_strategy.type) ? study.open_strategy.type : 'none';
            const badge = strategyType === 'r2'
                ? '<span class="badge bg-info ms-1">R2</span>'
                : (strategyType === 'remote'
                    ? '<span class="badge bg-secondary ms-1">Remoto</span>'
                    : '<span class="badge bg-success ms-1">Local</span>');
            const payload = encodeURIComponent(JSON.stringify(study));

            const viewStrategy = study.informe_view_strategy || 'none';
            const canViewInforme = study.has_informe === true && viewStrategy !== 'none';
            const hasMultipleInformes = canViewInforme && study.informes && Array.isArray(study.informes) && study.informes.length > 1;
            const informeTitle = canViewInforme ? (study.informe_titulo || 'Informe Médico').replace(/'/g, "\\'") : '';
            let informesDataBase64 = '';
            if (hasMultipleInformes) {
                const informesData = study.informes.map((inf) => ({
                    pdf_path: inf.pdf_path,
                    titulo: inf.titulo || 'Informe Médico',
                    informe_id: inf.informe_id,
                    fecha: inf.fecha || '',
                    hora: inf.hora || '',
                    informe_view_strategy: inf.informe_view_strategy || viewStrategy,
                    informe_pacs: inf.informe_pacs || study.informe_pacs || {},
                    pacs_series_id: inf.pacs_series_id,
                    pacs_instance_id: inf.pacs_instance_id,
                }));
                informesDataBase64 = this.base64Encode(JSON.stringify(informesData));
            }
            const buttonId = hasMultipleInformes ? `btn-pdf-v2-${Date.now()}-${Math.random().toString(36).substr(2, 9)}` : '';
            const safePatientIdForProxy = (study.patient_id || '').replace(/'/g, "\\'");
            const safeStudyDescShort = (study.study_description || 'Informe').replace(/'/g, "\\'");

            let verBtn = '';
            if (isPacsPdfInforme && study.orthanc_id) {
                verBtn = `
                    <button class="btn btn-sm btn-primary" onclick="orthancIntegration.viewPacsPdfInforme('${study.orthanc_id}', '${safePatientIdForProxy}', '${safeStudyDescShort}')" title="Ver informe PDF">
                        <i class="fas fa-eye"></i> <span class="btn-text">Ver</span>
                    </button>`;
            } else if (canOpen) {
                verBtn = `
                    <button class="btn btn-sm btn-primary" onclick="orthancIntegration.openStudyV2(JSON.parse(decodeURIComponent('${payload}')))" title="Ver estudio">
                        <i class="fas fa-eye"></i> <span class="btn-text">Ver</span>
                    </button>`;
            } else {
                verBtn = `
                    <button class="btn btn-sm btn-primary" disabled title="Ver estudio">
                        <i class="fas fa-eye"></i> <span class="btn-text">Ver</span>
                    </button>`;
            }

            let downloadBtn = '';
            if (isPacsPdfInforme && study.orthanc_id) {
                downloadBtn = `
                    <button class="btn btn-sm btn-secondary" onclick="orthancIntegration.downloadPacsPdfInforme('${study.orthanc_id}', '${safePatientIdForProxy}')" title="Descargar informe PDF">
                        <i class="fas fa-download"></i> <span class="btn-text d-none d-sm-inline">Descargar</span>
                    </button>`;
            } else if (!isPacsPdfInforme && study.orthanc_id) {
                downloadBtn = `
                    <button class="btn btn-sm btn-secondary" onclick="orthancIntegration.downloadStudy('${(study.orthanc_id || '').replace(/'/g, "\\'")}', '${(study.patient_id || '').replace(/'/g, "\\'")}', '${(study.patient_name || '').replace(/'/g, "\\'")}', '${study.study_date || ''}', '${(study.study_description || '').replace(/'/g, "\\'")}')" title="Descargar estudio">
                        <i class="fas fa-download"></i> <span class="btn-text d-none d-sm-inline">Descargar</span>
                    </button>`;
            }

            const informeBtnLabel = viewStrategy === 'pacs_images' ? 'Ver informe' : 'Ver PDF';
            const informeBtnIcon = viewStrategy === 'pacs_images' ? 'fa-file-image' : 'fa-file-pdf';
            const pdfBtn = canViewInforme ? `
                <button ${hasMultipleInformes ? `id="${buttonId}" data-informes-base64="${informesDataBase64}" data-study-base64="${this.base64Encode(JSON.stringify({ orthanc_id: study.orthanc_id, study_id: study.study_id, patient_id: study.patient_id, study_description: study.study_description, informe_view_strategy: viewStrategy, informe_pacs: study.informe_pacs, informe_id: study.informe_id, informe_pdf_path: study.informe_pdf_path, informe_titulo: study.informe_titulo }))}"` : ''} class="btn btn-sm btn-success ${hasMultipleInformes ? 'btn-view-informe-multiple-v2' : ''}" onclick="${hasMultipleInformes ? `orthancIntegration.handleMultipleInformesV2Click('${buttonId}')` : `orthancIntegration.viewInformeV2(JSON.parse(decodeURIComponent('${payload}')))`}" title="${hasMultipleInformes ? 'Ver informes médicos (' + study.informes.length + ')' : 'Ver informe médico'}">
                    <i class="fas ${informeBtnIcon}"></i> <span class="btn-text">${informeBtnLabel}${hasMultipleInformes ? ' (' + study.informes.length + ')' : ''}</span>
                </button>` : '';

            return `
                <tr>
                    <td>${formattedDate}</td>
                    <td>${modality}</td>
                    <td class="hide-mobile">${desc}</td>
                    <td style="white-space: nowrap;">
                        <div class="d-flex align-items-center gap-2" style="flex-wrap: nowrap;">
                            <div class="btn-group" role="group" style="flex-shrink: 0;">
                                ${verBtn}
                                ${downloadBtn}
                                ${pdfBtn}
                            </div>
                            ${badge}
                        </div>
                    </td>
                </tr>
            `;
        }).filter((html) => html !== '').join('');
    }
    
    /**
     * Formatea una fecha DICOM (YYYYMMDD) a formato legible
     * @param {string} dateStr - Fecha en formato DICOM
     * @returns {string} - Fecha formateada
     */
    formatDate(dateStr) {
        if (!dateStr || dateStr.length !== 8) return dateStr;
        
        const year = dateStr.substring(0, 4);
        const month = dateStr.substring(4, 6);
        const day = dateStr.substring(6, 8);
        
        return `${day}/${month}/${year}`;
    }
    
    /**
     * Formatea una hora DICOM (HHMMSS) a formato legible
     * @param {string} timeStr - Hora en formato DICOM
     * @returns {string} - Hora formateada
     */
    formatTime(timeStr) {
        if (!timeStr || timeStr.length < 4) return timeStr;
        
        const hours = timeStr.substring(0, 2);
        const minutes = timeStr.substring(2, 4);
        
        return `${hours}:${minutes}`;
    }

    /**
     * Normaliza nombre DICOM para saludo (misma idea que extractFirstName en medical-portal/includes/functions.php).
     */
    formatPatientWelcomeName(fullName) {
        if (!fullName || typeof fullName !== 'string') return 'Paciente';
        const normalized = fullName
            .replace(/\^/g, ' ')
            .replace(/[,;]+/g, ' ')
            .replace(/\s+/g, ' ')
            .trim();
        if (!normalized) return 'Paciente';

        const parts = normalized.split(' ');
        const firstNumericPartIdx = parts.findIndex((part) => /\d/.test(part));
        const nameOnly = firstNumericPartIdx > 0
            ? parts.slice(0, firstNumericPartIdx).join(' ').trim()
            : normalized;

        return nameOnly || 'Paciente';
    }

    isMobileDevice() {
        const hasTouch = ('ontouchstart' in window) || (navigator.maxTouchPoints > 0);
        const mediaMobile = window.matchMedia ? window.matchMedia('(max-width: 768px)').matches : false;
        const uaMobile = /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(navigator.userAgent || '');
        return mediaMobile || (hasTouch && window.innerWidth <= 1024) || uaMobile;
    }

    openStudyViewerFromData(defaultUrl, mobileUrl, desktopUrl) {
        const isMobile = this.isMobileDevice();
        const targetUrl = isMobile
            ? (mobileUrl || defaultUrl || desktopUrl)
            : (desktopUrl || defaultUrl || mobileUrl);

        if (!targetUrl) {
            alert('No se encontro una URL de visor disponible para este estudio.');
            return;
        }

        window.open(targetUrl, '_blank');
    }
    
    /**
     * Genera el HTML para mostrar los estudios en el modal
     * @param {Array} studies - Lista de estudios
     * @returns {string} - HTML generado
     */
    generateStudiesHTML(studies) {
        if (!studies || studies.length === 0) {
            return `
                <tr>
                    <td colspan="4" class="text-center py-4">
                        <i class="fas fa-info-circle text-muted me-2"></i>
                        No se encontraron estudios para este paciente
                    </td>
                </tr>
            `;
        }
        
        // Crear un mapa de estudios con sus orthanc_id para verificar asociaciones
        // y un conjunto de orthanc_id que tienen informes asociados
        const orthancIdToStudy = new Map();
        const orthancIdsWithInforme = new Set();
        
        studies.forEach(study => {
            if (study.orthanc_id) {
                orthancIdToStudy.set(study.orthanc_id, study);
                if (study.has_informe) {
                    orthancIdsWithInforme.add(study.orthanc_id);
                }
            }
        });
        
        return studies.map(study => {
            const formattedDate = this.formatDate(study.study_date);
            const formattedTime = this.formatTime(study.study_time);
            
            // Escapar comillas simples en las rutas para evitar problemas con onclick
            const escapePath = (path) => (path || '').replace(/'/g, "\\'");
            
            // Si es un informe con PDF, verificar si está asociado a un estudio
            if (study.is_informe && study.pdf_path) {
                const informeStudyId = study.study_id;
                // Si este informe está asociado a un estudio que ya está en la lista (por orthanc_id),
                // no mostrar línea separada - el botón verde ya está en la línea del estudio
                if (informeStudyId && orthancIdsWithInforme.has(informeStudyId)) {
                    return ''; // No mostrar esta línea, el botón verde ya está en la línea del estudio
                }
                
                // Solo mostrar línea separada si el informe NO está asociado a ningún estudio
                const safePdfPath = escapePath(study.pdf_path);
                return `
                    <tr>
                        <td>${formattedDate}</td>
                        <td>${study.modality || 'DOC'}</td>
                        <td class="hide-mobile">${study.study_description || 'Informe Médico'}</td>
                        <td>
                            <button class="btn btn-sm btn-success" onclick="orthancIntegration.viewPdf('${safePdfPath}', '${(study.study_description || 'Informe Médico').replace(/'/g, "\\'")}')" title="Ver informe PDF en visor integrado">
                                <i class="fas fa-file-pdf me-1"></i> Ver PDF
                            </button>
                        </td>
                    </tr>
                `;
            }
            
            // Si es un estudio de Orthanc
            const hasInforme = study.has_informe === true && study.informe_pdf_path;
            const hasMultipleInformes = hasInforme && study.informes && Array.isArray(study.informes) && study.informes.length > 1;
            const safePdfPath = hasInforme ? escapePath(study.informe_pdf_path) : null;
            const informeTitle = hasInforme ? (study.informe_titulo || 'Informe Médico').replace(/'/g, "\\'") : '';
            
            // Preparar datos de informes para JavaScript (usar base64 para evitar problemas de escape)
            let informesDataBase64 = '';
            if (hasMultipleInformes) {
                const informesData = study.informes.map(inf => ({
                    pdf_path: inf.pdf_path,
                    titulo: inf.titulo || 'Informe Médico'
                }));
                informesDataBase64 = this.base64Encode(JSON.stringify(informesData));
            }
            
            // Generar ID único para el botón (para data attributes)
            const buttonId = hasMultipleInformes ? `btn-pdf-${Date.now()}-${Math.random().toString(36).substr(2, 9)}` : '';
            
            const isPacsPdfInforme = study.is_pacs_pdf_informe === true;
            const safePatientIdForProxy = (study.patient_id || '').replace(/'/g, "\\'");
            const safeStudyDescShort = (study.study_description || 'Informe').replace(/'/g, "\\'");
            
            return `
                <tr>
                    <td>${formattedDate}</td>
                    <td>${study.modality || 'N/A'}</td>
                    <td class="hide-mobile">${study.study_description || 'Sin descripción'}</td>
                    <td style="white-space: nowrap;">
                        <div class="d-flex align-items-center gap-2" style="flex-wrap: nowrap;">
                            ${study.viewer_url || study.orthanc_id || hasInforme ? `
                                <div class="btn-group" role="group" style="flex-shrink: 0;">
                                    ${isPacsPdfInforme && study.orthanc_id ? `
                                        <button class="btn btn-sm btn-primary" onclick="orthancIntegration.viewPacsPdfInforme('${study.orthanc_id}', '${safePatientIdForProxy}', '${safeStudyDescShort}')" title="Ver informe PDF">
                                            <i class="fas fa-eye"></i> <span class="btn-text">Ver</span>
                                        </button>
                                    ` : ''}
                                    ${!isPacsPdfInforme && (study.viewer_url || study.viewer_url_mobile || study.viewer_url_desktop) ? `
                                        <button class="btn btn-sm btn-primary" onclick="orthancIntegration.openStudyViewerFromData('${(study.viewer_url || '').replace(/'/g, "\\'")}', '${(study.viewer_url_mobile || '').replace(/'/g, "\\'")}', '${(study.viewer_url_desktop || '').replace(/'/g, "\\'")}')" title="Ver estudio">
                                            <i class="fas fa-eye"></i> <span class="btn-text">Ver</span>
                                        </button>
                                    ` : ''}
                                    ${isPacsPdfInforme && study.orthanc_id ? `
                                        <button class="btn btn-sm btn-secondary" onclick="orthancIntegration.downloadPacsPdfInforme('${study.orthanc_id}', '${safePatientIdForProxy}')" title="Descargar informe PDF">
                                            <i class="fas fa-download"></i> <span class="btn-text d-none d-sm-inline">Descargar</span>
                                        </button>
                                    ` : ''}
                                    ${!isPacsPdfInforme && study.orthanc_id ? `
                                        <button class="btn btn-sm btn-secondary" onclick="orthancIntegration.downloadStudy('${study.orthanc_id || ''}', '${(study.patient_id || '').replace(/'/g, "\\'")}', '${(study.patient_name || '').replace(/'/g, "\\'")}', '${study.study_date || ''}', '${(study.study_description || '').replace(/'/g, "\\'")}')" title="Descargar estudio">
                                            <i class="fas fa-download"></i> <span class="btn-text d-none d-sm-inline">Descargar</span>
                                        </button>
                                    ` : ''}
                                    ${hasInforme ? `
                                        <button ${hasMultipleInformes ? `id="${buttonId}" data-informes-base64="${informesDataBase64}"` : ''} class="btn btn-sm btn-success ${hasMultipleInformes ? 'btn-view-pdf-multiple' : ''}" onclick="${hasMultipleInformes ? `orthancIntegration.handleMultiplePdfsClick('${buttonId}')` : `orthancIntegration.viewPdf('${safePdfPath}', '${informeTitle}')`}" title="${hasMultipleInformes ? 'Ver informes médicos PDF (' + study.informes.length + ' informes)' : 'Ver informe médico PDF'}">
                                            <i class="fas fa-file-pdf"></i> <span class="btn-text">Ver PDF${hasMultipleInformes ? ' (' + study.informes.length + ')' : ''}</span>
                                        </button>
                                    ` : ''}
                                </div>
                            ` : ''}
                        </div>
                    </td>
                </tr>
            `;
        }).filter(html => html !== '').join(''); // Filtrar líneas vacías
    }
    
    /**
     * Obtiene la URL base del proyecto
     * @returns {string} - URL base del proyecto
     */
    getBaseUrl() {
        const pathname = window.location.pathname;
        // Si el pathname incluye /portal_estudios o /PORTAL_ESTUDIOS, extraerlo
        const portalMatch = pathname.match(/\/(PORTAL_ESTUDIOS|portal_estudios)/i);
        if (portalMatch) {
            return window.location.origin + portalMatch[0];
        }
        // Si no, usar el pathname sin el archivo actual
        const pathWithoutFile = pathname.substring(0, pathname.lastIndexOf('/'));
        return window.location.origin + (pathWithoutFile || '');
    }
    
    /**
     * Construye la URL completa del PDF
     * @param {string} pdfPath - Ruta relativa del PDF
     * @returns {string} - URL completa del PDF
     */
    buildPdfUrl(pdfPath) {
        const baseUrl = this.getBaseUrl();
        // Eliminar cualquier barra inicial del pdfPath si existe
        const cleanPath = pdfPath.startsWith('/') ? pdfPath.substring(1) : pdfPath;
        return `${baseUrl}/${cleanPath}`;
    }
    
    /**
     * Ruta relativa al proxy de informe PDF encapsulado en PACS (DOC + INFORME).
     */
    buildPacsPdfInformeProxyPath(orthancStudyId, patientId, disposition) {
        const params = new URLSearchParams({
            study_id: orthancStudyId,
            patient_id: patientId,
            disposition: disposition || 'inline',
        });
        return 'api/paciente_pacs_pdf_proxy.php?' + params.toString();
    }
    
    viewPacsPdfInforme(orthancStudyId, patientId, title) {
        if (!orthancStudyId || !patientId) {
            alert('No se puede abrir el informe.');
            return;
        }
        const rel = this.buildPacsPdfInformeProxyPath(orthancStudyId, patientId, 'inline');
        this.viewPdf(rel, title || 'Informe');
    }
    
    downloadPacsPdfInforme(orthancStudyId, patientId) {
        if (!orthancStudyId || !patientId) {
            alert('No se puede descargar el informe.');
            return;
        }
        const baseUrl = this.getBaseUrl();
        const rel = this.buildPacsPdfInformeProxyPath(orthancStudyId, patientId, 'attachment');
        const cleanPath = rel.startsWith('/') ? rel.substring(1) : rel;
        window.location.href = baseUrl + '/' + cleanPath;
    }

    /**
     * Proxy v2: informe desde serie DOC en estudio mixto (PDF o páginas).
     */
    buildPacsInformeProxyPath(opts = {}) {
        const params = new URLSearchParams({
            study_id: opts.study_id || '',
            patient_id: opts.patient_id || '',
            disposition: opts.disposition || 'inline',
            action: opts.action || 'pdf',
        });
        if (opts.series_id) params.set('series_id', opts.series_id);
        if (opts.instance_id) params.set('instance_id', opts.instance_id);
        if (opts.informe_id) params.set('informe_id', String(opts.informe_id));
        return 'api/paciente_pacs_informe_proxy.php?' + params.toString();
    }

    /**
     * Portal v2: abre informe según estrategia (disco / PACS PDF / PACS imágenes).
     */
    viewInformeV2(study) {
        if (!study) {
            alert('No se puede abrir el informe.');
            return;
        }
        const strategy = study.informe_view_strategy || 'none';
        const title = study.informe_titulo || study.study_description || study.titulo || 'Informe Médico';
        const patientId = study.patient_id || '';
        const orthancId = study.orthanc_id || study.study_id || '';
        const pacs = study.informe_pacs || {};
        const informeId = study.informe_id || null;

        if (strategy === 'disk') {
            const path = study.informe_pdf_path || study.pdf_path;
            if (!path) {
                alert('Informe no disponible en disco.');
                return;
            }
            this.viewPdf(path, title);
            return;
        }
        if (strategy === 'pacs_pdf') {
            if (!orthancId || !patientId) {
                alert('No se puede abrir el informe desde PACS.');
                return;
            }
            const rel = this.buildPacsInformeProxyPath({
                study_id: orthancId,
                patient_id: patientId,
                series_id: pacs.series_id || study.pacs_series_id,
                informe_id: informeId,
                action: 'pdf',
                disposition: 'inline',
            });
            this.viewPdf(rel, title);
            return;
        }
        if (strategy === 'pacs_images') {
            this.viewPacsInformeImages({
                studyId: orthancId,
                patientId,
                seriesId: pacs.series_id || study.pacs_series_id,
                informeId,
                title,
            });
            return;
        }
        alert('Informe no disponible.');
    }

    handleMultipleInformesV2Click(buttonId) {
        const button = document.getElementById(buttonId);
        if (!button) return;
        const informesDataBase64 = button.getAttribute('data-informes-base64');
        const studyBase64 = button.getAttribute('data-study-base64');
        if (!informesDataBase64) return;
        try {
            const informes = JSON.parse(this.base64Decode(informesDataBase64));
            const studyCtx = studyBase64 ? JSON.parse(this.base64Decode(studyBase64)) : {};
            if (!Array.isArray(informes) || informes.length === 0) return;
            if (informes.length === 1) {
                this.viewInformeV2(Object.assign({}, studyCtx, informes[0], {
                    informe_view_strategy: informes[0].informe_view_strategy,
                    informe_titulo: informes[0].titulo,
                    informe_id: informes[0].informe_id,
                }));
                return;
            }
            this.showInformePickerModal(studyCtx, informes);
        } catch (error) {
            console.error('handleMultipleInformesV2Click:', error);
            alert('Error al cargar los informes.');
        }
    }

    _escapeHtml(str) {
        return String(str ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    _getInformeStrategyMeta(strategy) {
        switch (strategy) {
            case 'disk':
                return { label: 'PDF servidor', icon: 'fa-file-pdf', badgeClass: 'bg-success-subtle text-success border-success' };
            case 'pacs_pdf':
                return { label: 'PDF PACS', icon: 'fa-file-pdf', badgeClass: 'bg-primary-subtle text-primary border-primary' };
            case 'pacs_images':
                return { label: 'Imagen PACS', icon: 'fa-file-image', badgeClass: 'bg-info-subtle text-info border-info' };
            default:
                return { label: 'Informe', icon: 'fa-file', badgeClass: 'bg-light text-muted border' };
        }
    }

    showInformePickerModal(studyCtx, informes) {
        const modalEl = document.getElementById('informePickerModal');
        const listEl = document.getElementById('informePickerList');
        const subtitleEl = document.getElementById('informePickerSubtitle');
        if (!modalEl || !listEl || typeof bootstrap === 'undefined' || !bootstrap.Modal) {
            alert('No se pudo abrir el selector de informes.');
            return;
        }

        if (subtitleEl) {
            const parts = [];
            if (studyCtx.informe_titulo || studyCtx.study_description) {
                parts.push(studyCtx.informe_titulo || studyCtx.study_description);
            }
            parts.push(`${informes.length} informe${informes.length > 1 ? 's' : ''} disponible${informes.length > 1 ? 's' : ''}`);
            subtitleEl.textContent = parts.join(' · ');
        }

        listEl.innerHTML = informes.map((inf, idx) => {
            const strategy = inf.informe_view_strategy || 'none';
            const meta = this._getInformeStrategyMeta(strategy);
            const titulo = this._escapeHtml(inf.titulo || 'Informe Médico');
            let fechaLine = '';
            if (inf.fecha) {
                const fechaFmt = this.formatDate(inf.fecha);
                const horaFmt = inf.hora ? this.formatTime(inf.hora) : '';
                fechaLine = `<small class="text-muted d-block">${this._escapeHtml(fechaFmt)}${horaFmt ? ' · ' + this._escapeHtml(horaFmt) : ''}</small>`;
            }
            return `
                <button type="button" class="list-group-item list-group-item-action informe-picker-item" data-index="${idx}">
                    <div class="d-flex align-items-center justify-content-between gap-3 w-100">
                        <div class="text-start flex-grow-1 min-w-0">
                            <div class="fw-semibold text-truncate">${titulo}</div>
                            ${fechaLine}
                        </div>
                        <span class="badge informe-picker-badge border ${meta.badgeClass}">
                            <i class="fas ${meta.icon} me-1"></i>${meta.label}
                        </span>
                    </div>
                </button>`;
        }).join('');

        listEl.querySelectorAll('.informe-picker-item').forEach((btn) => {
            btn.addEventListener('click', () => {
                const idx = parseInt(btn.getAttribute('data-index'), 10);
                const chosen = informes[idx];
                if (!chosen) return;
                const pickerModal = bootstrap.Modal.getInstance(modalEl);
                if (pickerModal) pickerModal.hide();
                this.viewInformeV2(Object.assign({}, studyCtx, chosen, {
                    informe_view_strategy: chosen.informe_view_strategy,
                    informe_titulo: chosen.titulo,
                    informe_id: chosen.informe_id,
                }));
            });
        });

        let pickerModal = bootstrap.Modal.getInstance(modalEl);
        if (!pickerModal) {
            pickerModal = new bootstrap.Modal(modalEl, { backdrop: true, keyboard: true, focus: true });
        }
        pickerModal.show();
    }

    _ensurePacsInformeNavBound() {
        if (this._pacsInformeNavBound) return;
        const prev = document.getElementById('pacsInformePrevPage');
        const next = document.getElementById('pacsInformeNextPage');
        if (prev) {
            prev.addEventListener('click', () => this._stepPacsInformePage(-1));
        }
        if (next) {
            next.addEventListener('click', () => this._stepPacsInformePage(1));
        }
        this._pacsInformeNavBound = true;
    }

    _stepPacsInformePage(delta) {
        const state = this._pacsInformeImageState;
        if (!state.pages.length) return;
        const next = state.index + delta;
        if (next < 0 || next >= state.pages.length) return;
        state.index = next;
        this._renderPacsInformeImagePage();
    }

    _renderPacsInformeImagePage() {
        const state = this._pacsInformeImageState;
        const page = state.pages[state.index];
        const imgEl = document.getElementById('pacsInformeImageEl');
        const numEl = document.getElementById('pacsInformePageNum');
        const totalEl = document.getElementById('pacsInformePageTotal');
        const spinner = document.getElementById('pdfLoadingSpinner');
        if (!page || !imgEl) return;
        if (spinner) spinner.style.display = 'flex';
        imgEl.onload = () => {
            if (spinner) spinner.style.display = 'none';
        };
        imgEl.onerror = () => {
            if (spinner) spinner.style.display = 'none';
            alert('No se pudo cargar la página del informe.');
        };
        const rel = this.buildPacsInformeProxyPath({
            study_id: state.studyId,
            patient_id: state.patientId,
            series_id: state.seriesId,
            instance_id: page.instance_id,
            informe_id: state.informeId,
            action: 'preview',
        });
        imgEl.src = this.buildPdfUrl(rel) + '&_=' + Date.now();
        if (numEl) numEl.textContent = String(state.index + 1);
        if (totalEl) totalEl.textContent = String(state.pages.length);
    }

    _showPacsInformeImageMode(title) {
        this._ensurePacsInformeNavBound();
        const iframe = document.getElementById('pdfViewerFrame');
        const imageWrap = document.getElementById('pacsInformeImageViewer');
        const nav = document.getElementById('pacsInformeImageNav');
        const hint = document.getElementById('pdfViewerFooterHint');
        const modalTitle = document.getElementById('pdfViewerTitle');
        if (iframe) {
            iframe.style.display = 'none';
            iframe.src = '';
        }
        if (imageWrap) {
            imageWrap.classList.remove('d-none');
            imageWrap.classList.add('d-flex');
        }
        if (nav) nav.classList.remove('d-none');
        if (hint) hint.textContent = 'Informe desde PACS (imágenes)';
        if (modalTitle) modalTitle.textContent = title || 'Informe Médico';
    }

    _hidePacsInformeImageMode() {
        const iframe = document.getElementById('pdfViewerFrame');
        const imageWrap = document.getElementById('pacsInformeImageViewer');
        const nav = document.getElementById('pacsInformeImageNav');
        const hint = document.getElementById('pdfViewerFooterHint');
        if (imageWrap) {
            imageWrap.classList.add('d-none');
            imageWrap.classList.remove('d-flex');
        }
        if (nav) nav.classList.add('d-none');
        if (iframe) iframe.style.display = 'block';
        if (hint) hint.textContent = 'Visor oficial de PDF.js con todas las funciones integradas';
    }

    async viewPacsInformeImages(opts = {}) {
        const { studyId, patientId, seriesId, informeId, title } = opts;
        if (!studyId || !patientId) {
            alert('No se puede abrir el informe desde PACS.');
            return;
        }
        const modalElement = document.getElementById('pdfViewerModal');
        if (!modalElement || typeof bootstrap === 'undefined' || !bootstrap.Modal) {
            alert('Visor no disponible. Recargue la página.');
            return;
        }
        let pdfModal = bootstrap.Modal.getInstance(modalElement);
        if (!pdfModal) {
            pdfModal = new bootstrap.Modal(modalElement, { backdrop: true, keyboard: true, focus: true });
        }
        const spinner = document.getElementById('pdfLoadingSpinner');
        if (spinner) spinner.style.display = 'flex';
        this._showPacsInformeImageMode(title);
        pdfModal.show();

        try {
            const rel = this.buildPacsInformeProxyPath({
                study_id: studyId,
                patient_id: patientId,
                series_id: seriesId,
                informe_id: informeId,
                action: 'pages',
            });
            const res = await fetch(this.buildPdfUrl(rel));
            const data = await res.json();
            if (!res.ok || !data.success || !Array.isArray(data.pages) || data.pages.length === 0) {
                throw new Error(data.error || 'Sin páginas disponibles');
            }
            this._pacsInformeImageState = {
                pages: data.pages,
                index: 0,
                studyId,
                patientId,
                seriesId: data.series_id || seriesId,
                informeId,
                title: title || 'Informe Médico',
            };
            this._renderPacsInformeImagePage();
        } catch (error) {
            if (spinner) spinner.style.display = 'none';
            console.error('viewPacsInformeImages:', error);
            alert(error.message || 'No se pudo cargar el informe desde PACS.');
            pdfModal.hide();
        }
    }
    
    /**
     * Maneja el clic en el botón de múltiples PDFs
     * @param {string} buttonId - ID del botón que contiene los datos
     */
    handleMultiplePdfsClick(buttonId) {
        const button = document.getElementById(buttonId);
        if (!button) {
            console.error('Botón no encontrado:', buttonId);
            return;
        }
        
        const informesDataBase64 = button.getAttribute('data-informes-base64');
        if (!informesDataBase64) {
            console.error('No se encontraron datos de informes en el botón');
            return;
        }
        
        try {
            const informesDataJson = this.base64Decode(informesDataBase64);
            this.viewPdfMultiple(informesDataJson);
        } catch (error) {
            console.error('Error decodificando datos de informes:', error);
            alert('Error al cargar los informes. Por favor, intente nuevamente.');
        }
    }
    
    /**
     * Visualiza múltiples PDFs concatenados en un modal integrado
     * @param {string} informesDataJson - JSON string con array de informes [{pdf_path, titulo}, ...]
     */
    async viewPdfMultiple(informesDataJson) {
        try {
            const informes = JSON.parse(informesDataJson);
            if (!Array.isArray(informes) || informes.length === 0) {
                console.error('Datos de informes inválidos');
                return;
            }
            
            // Verificar que el modal existe
            const modalElement = document.getElementById('pdfViewerModal');
            if (!modalElement) {
                console.error('Modal de PDF no encontrado');
                alert('Error: No se pudo abrir el visor de PDF. Por favor, recarga la página.');
                return;
            }
            
            // Actualizar título del modal
            const modalTitle = document.getElementById('pdfViewerTitle');
            if (modalTitle) {
                modalTitle.textContent = `Informes Médicos (${informes.length} informe${informes.length > 1 ? 's' : ''})`;
            }
            
            // Obtener elementos del visor
            const viewerFrame = document.getElementById('pdfViewerFrame');
            const loadingSpinner = document.getElementById('pdfLoadingSpinner');
            
            if (!viewerFrame) {
                console.error('Iframe del visor PDF no encontrado');
                return;
            }
            
            // Mostrar loading spinner
            if (loadingSpinner) {
                loadingSpinner.style.display = 'flex';
                loadingSpinner.innerHTML = `
                    <div class="spinner-border spinner-border-lg" role="status">
                        <span class="visually-hidden">Combinando PDFs...</span>
                    </div>
                    <div class="mt-3">Combinando ${informes.length} informe${informes.length > 1 ? 's' : ''}...</div>
                `;
            }
            if (viewerFrame) viewerFrame.style.display = 'none';
            
            // Verificar que Bootstrap está disponible
            if (typeof bootstrap === 'undefined' || !bootstrap.Modal) {
                console.error('Bootstrap Modal no está disponible');
                alert('Error: Bootstrap no está cargado correctamente. Por favor, recarga la página.');
                return;
            }
            
            // Obtener o crear instancia del modal
            let pdfModal = bootstrap.Modal.getInstance(modalElement);
            if (!pdfModal) {
                pdfModal = new bootstrap.Modal(modalElement, {
                    backdrop: true,
                    keyboard: true,
                    focus: true
                });
            }
            
            // Preparar rutas de PDFs para enviar al servidor
            const pdfPaths = informes.map(inf => inf.pdf_path);
            
            // Llamar a la API del servidor para concatenar los PDFs
            const baseUrl = this.getBaseUrl();
            const mergeUrl = `${baseUrl}/api/merge_pdfs.php`;
            
            console.log('Llamando a merge_pdfs.php con rutas:', pdfPaths);
            
            // Configurar el evento del modal ANTES de mostrarlo
            let mergedPdfUrl = null;
            let pdfReady = false;
            let modalShown = false;
            
            const setupViewer = () => {
                if (!mergedPdfUrl) {
                    console.warn('PDF aún no está listo, esperando...');
                    pdfReady = false;
                    return;
                }
                
                pdfReady = true;
                
                // Si el modal ya está visible, configurar inmediatamente
                if (modalShown || modalElement.classList.contains('show')) {
                    loadPdfInViewer();
                }
            };
            
            const loadPdfInViewer = () => {
                if (!pdfReady || !mergedPdfUrl) {
                    console.warn('PDF no está listo aún');
                    return;
                }
                
                const pathParts = window.location.pathname.split('/').filter(p => p);
                if (pathParts.length > 0 && pathParts[pathParts.length - 1].includes('.html')) {
                    pathParts.pop();
                }
                const basePath = pathParts.length > 0 ? '/' + pathParts.join('/') : '';
                const viewerBaseUrl = window.location.origin + basePath;
                const viewerUrl = `${viewerBaseUrl}/libs/pdfjs/web/viewer.html?file=${encodeURIComponent(mergedPdfUrl)}`;
                
                console.log('Configurando visor con URL:', viewerUrl.substring(0, 100) + '...');
                viewerFrame.src = viewerUrl;
                
                viewerFrame.addEventListener('load', () => {
                    console.log('Visor cargado');
                    if (loadingSpinner) loadingSpinner.style.display = 'none';
                    if (viewerFrame) viewerFrame.style.display = 'block';
                }, { once: true });
                
                // Timeout de seguridad
                setTimeout(() => {
                    if (loadingSpinner && loadingSpinner.style.display !== 'none') {
                        console.warn('Timeout esperando carga del visor');
                        loadingSpinner.style.display = 'none';
                        if (viewerFrame) viewerFrame.style.display = 'block';
                    }
                }, 10000);
            };
            
            // Escuchar cuando el modal se muestre
            modalElement.addEventListener('shown.bs.modal', () => {
                console.log('Modal mostrado');
                modalShown = true;
                if (pdfReady) {
                    loadPdfInViewer();
                }
            }, { once: true });
            
            // Limpiar el blob URL cuando se cierre el modal
            modalElement.addEventListener('hidden.bs.modal', () => {
                if (mergedPdfUrl) {
                    URL.revokeObjectURL(mergedPdfUrl);
                    mergedPdfUrl = null;
                }
                if (viewerFrame) viewerFrame.src = '';
                if (loadingSpinner) {
                    loadingSpinner.style.display = 'flex';
                    loadingSpinner.innerHTML = `
                        <div class="spinner-border spinner-border-lg" role="status">
                            <span class="visually-hidden">Cargando...</span>
                        </div>
                    `;
                }
            }, { once: false });
            
            // Mostrar el modal
            pdfModal.show();
            
            try {
                const response = await fetch(mergeUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({ pdf_paths: pdfPaths })
                });
                
                console.log('Respuesta del servidor:', response.status, response.statusText);
                console.log('Content-Type:', response.headers.get('Content-Type'));
                
                if (!response.ok) {
                    // Intentar leer el error como JSON si es posible
                    const contentType = response.headers.get('Content-Type') || '';
                    let errorMessage = `Error del servidor: ${response.status}`;
                    
                    if (contentType.includes('application/json')) {
                        try {
                            const errorData = await response.json();
                            errorMessage = errorData.error || errorMessage;
                        } catch (e) {
                            // Ignorar error al parsear JSON
                        }
                    }
                    
                    throw new Error(errorMessage);
                }
                
                // Verificar que la respuesta sea un PDF
                const contentType = response.headers.get('Content-Type') || '';
                if (!contentType.includes('application/pdf') && !contentType.includes('application/octet-stream')) {
                    // Intentar leer como texto para ver el error
                    const textResponse = await response.text();
                    console.error('Respuesta no es PDF:', textResponse);
                    throw new Error('El servidor no devolvió un PDF válido. Verifique los logs del servidor.');
                }
                
                // Obtener el PDF combinado como blob
                const mergedPdfBlob = await response.blob();
                console.log('Blob creado, tamaño:', mergedPdfBlob.size, 'bytes');
                
                if (mergedPdfBlob.size === 0) {
                    throw new Error('El PDF combinado está vacío');
                }
                
                // Usar blob URL directamente (más eficiente que data URL para PDFs grandes)
                mergedPdfUrl = URL.createObjectURL(mergedPdfBlob);
                console.log('Blob URL creado:', mergedPdfUrl);
                
                // Configurar el visor ahora que tenemos el PDF
                setupViewer();
                
            } catch (error) {
                console.error('Error combinando PDFs:', error);
                if (loadingSpinner) {
                    loadingSpinner.innerHTML = `
                        <div class="text-danger">
                            <i class="fas fa-exclamation-triangle fa-3x mb-3"></i>
                            <div>Error al combinar los PDFs</div>
                            <small class="d-block mt-2">${error.message}</small>
                            <div class="mt-3">
                                <button class="btn btn-sm btn-outline-light me-2" onclick="window.location.reload()">Recargar</button>
                                <button class="btn btn-sm btn-outline-light" onclick="this.closest('.modal').querySelector('.btn-close').click()">Cerrar</button>
                            </div>
                        </div>
                    `;
                }
            }
            
        } catch (error) {
            console.error('Error en viewPdfMultiple:', error);
            alert('Error al cargar los informes. Por favor, intente nuevamente.');
        }
    }
    
    /**
     * Visualiza un PDF en un modal integrado
     * @param {string} pdfPath - Ruta relativa del PDF
     * @param {string} title - Título del PDF (opcional)
     */
    viewPdf(pdfPath, title = 'Informe Médico') {
        this._hidePacsInformeImageMode();
        const pdfUrl = this.buildPdfUrl(pdfPath);
        
        // Verificar que el modal existe
        const modalElement = document.getElementById('pdfViewerModal');
        if (!modalElement) {
            console.error('Modal de PDF no encontrado');
            alert('Error: No se pudo abrir el visor de PDF. Por favor, recarga la página.');
            return;
        }
        
        // Actualizar título del modal
        const modalTitle = document.getElementById('pdfViewerTitle');
        if (modalTitle) {
            modalTitle.textContent = title;
        }
        
        // Verificar que Bootstrap está disponible
        if (typeof bootstrap === 'undefined' || !bootstrap.Modal) {
            console.error('Bootstrap Modal no está disponible');
            alert('Error: Bootstrap no está cargado correctamente. Por favor, recarga la página.');
            return;
        }
        
        // Obtener o crear instancia del modal
        let pdfModal = bootstrap.Modal.getInstance(modalElement);
        if (!pdfModal) {
            // Crear nueva instancia con opciones explícitas
            pdfModal = new bootstrap.Modal(modalElement, {
                backdrop: true,
                keyboard: true,
                focus: true
            });
        }
        
        // Obtener elementos del visor
        const viewerFrame = document.getElementById('pdfViewerFrame');
        const loadingSpinner = document.getElementById('pdfLoadingSpinner');
        
        if (!viewerFrame) {
            console.error('Iframe del visor PDF no encontrado');
            return;
        }
        
        // Mostrar loading spinner
        if (loadingSpinner) loadingSpinner.style.display = 'flex';
        if (viewerFrame) viewerFrame.style.display = 'none';
        
        // Mostrar el modal
        pdfModal.show();
        
        // Usar el viewer oficial de PDF.js local desde /libs/pdfjs/web
        // El viewer.html local apunta al PDF mediante el parámetro ?file=
        // Construir la URL base del proyecto
        const pathParts = window.location.pathname.split('/').filter(p => p);
        // Remover el último segmento si estamos en paciente.html
        if (pathParts.length > 0 && pathParts[pathParts.length - 1].includes('.html')) {
            pathParts.pop();
        }
        const basePath = pathParts.length > 0 ? '/' + pathParts.join('/') : '';
        const baseUrl = window.location.origin + basePath;
        const viewerUrl = `${baseUrl}/libs/pdfjs/web/viewer.html?file=${encodeURIComponent(pdfUrl)}`;
        
        console.log('URL del viewer local:', viewerUrl);
        console.log('URL del PDF:', pdfUrl);
        
        // Configurar el iframe cuando el modal esté visible
        modalElement.addEventListener('shown.bs.modal', () => {
            viewerFrame.src = viewerUrl;
            
            // Ocultar spinner cuando el iframe cargue
            viewerFrame.addEventListener('load', () => {
                if (loadingSpinner) loadingSpinner.style.display = 'none';
                if (viewerFrame) viewerFrame.style.display = 'block';
            }, { once: true });
            
            // Timeout de seguridad
            setTimeout(() => {
                if (loadingSpinner && loadingSpinner.style.display !== 'none') {
                    loadingSpinner.style.display = 'none';
                    if (viewerFrame) viewerFrame.style.display = 'block';
                }
            }, 5000);
        }, { once: true });
        
        // Limpiar el iframe al cerrar el modal
        modalElement.addEventListener('hidden.bs.modal', () => {
            if (viewerFrame) viewerFrame.src = '';
            if (loadingSpinner) loadingSpinner.style.display = 'flex';
            this._hidePacsInformeImageMode();
            const imgEl = document.getElementById('pacsInformeImageEl');
            if (imgEl) imgEl.src = '';
        }, { once: false });
    }
    
    /**
     * Inicializa el visor PDF local (embebido, sin iframe externo)
     * @param {string} pdfUrl - URL completa del PDF
     */
    initLocalPdfViewer(pdfUrl) {
        const canvas = document.getElementById('pdfCanvas');
        const loadingSpinner = document.getElementById('pdfLoadingSpinner');
        const pageInfo = document.getElementById('pdfPageInfo');
        const pageSlider = document.getElementById('pdfPageSlider');
        const prevBtn = document.getElementById('pdfPrevPage');
        const nextBtn = document.getElementById('pdfNextPage');
        const zoomInBtn = document.getElementById('pdfZoomIn');
        const zoomOutBtn = document.getElementById('pdfZoomOut');
        const fitWidthBtn = document.getElementById('pdfFitWidth');
        const downloadBtn = document.getElementById('pdfDownloadBtn');
        const canvasContainer = document.getElementById('pdfViewerContainer');
        
        if (!canvas || !canvasContainer) {
            console.error('Canvas o contenedor del PDF no encontrado');
            if (loadingSpinner) {
                loadingSpinner.innerHTML = '<div class="text-danger">Error: Elementos del visor no encontrados</div>';
            }
            return;
        }
        
        const ctx = canvas.getContext('2d');
        
        // Verificar que PDF.js esté disponible
        if (typeof pdfjsLib === 'undefined') {
            console.error('PDF.js no está disponible');
            if (loadingSpinner) {
                loadingSpinner.innerHTML = '<div class="text-danger">Error: PDF.js no está cargado</div>';
            }
            return;
        }
        
        let pdfDoc = null;
        let pageNum = 1;
        let scale = 1.5;
        let baseScale = 1.5;
        let rendering = false;
        
        // Mostrar spinner y ocultar canvas
        if (loadingSpinner) loadingSpinner.style.display = 'flex';
        canvas.style.display = 'none';
        
        // Cargar el PDF
        pdfjsLib.getDocument({ url: pdfUrl, withCredentials: false }).promise.then(pdf => {
            pdfDoc = pdf;
            if (pageInfo) pageInfo.textContent = `Página ${pageNum} de ${pdf.numPages}`;
            
            // Ocultar spinner y mostrar canvas
            if (loadingSpinner) loadingSpinner.style.display = 'none';
            canvas.style.display = 'block';
            
            // Auto-ajustar al ancho al inicio
            autoFitWidth();
        }).catch(error => {
            console.error('Error cargando PDF:', error);
            if (loadingSpinner) {
                loadingSpinner.innerHTML = `
                    <div class="text-danger">
                        <i class="fas fa-exclamation-triangle fa-3x mb-3"></i>
                        <div>Error al cargar el PDF</div>
                        <small class="d-block mt-2">${error.message}</small>
                        <button class="btn btn-sm btn-outline-light mt-3" onclick="window.location.reload()">Recargar</button>
                    </div>
                `;
            }
        });
        
        // Función para auto-ajustar al ancho
        const autoFitWidth = () => {
            if (!pdfDoc) return;
            pdfDoc.getPage(pageNum).then(page => {
                const viewport = page.getViewport({ scale: 1 });
                const containerWidth = canvasContainer.clientWidth - 40;
                const newScale = containerWidth / viewport.width;
                scale = Math.max(0.5, Math.min(3.0, newScale));
                baseScale = scale;
                renderPage(pageNum);
                canvasContainer.scrollTop = 0;
                canvasContainer.scrollLeft = 0;
            });
        };
        
        // Función para renderizar una página con alta calidad
        const renderPage = (num) => {
            if (rendering || !pdfDoc) return;
            rendering = true;
            
            pdfDoc.getPage(num).then(page => {
                const dpr = window.devicePixelRatio || 1;
                const viewport = page.getViewport({ scale: scale });
                const outputScale = Math.max(dpr, 1.5);
                
                canvas.height = Math.floor(viewport.height * outputScale);
                canvas.width = Math.floor(viewport.width * outputScale);
                canvas.style.height = viewport.height + 'px';
                canvas.style.width = viewport.width + 'px';
                
                ctx.save();
                ctx.scale(outputScale, outputScale);
                
                const renderContext = {
                    canvasContext: ctx,
                    viewport: viewport
                };
                
                page.render(renderContext).promise.then(() => {
                    ctx.restore();
                    rendering = false;
                    if (pageInfo) pageInfo.textContent = `Página ${num} de ${pdfDoc.numPages}`;
                    if (pageSlider) pageSlider.value = num;
                }).catch(err => {
                    ctx.restore();
                    rendering = false;
                    console.error('Error renderizando página:', err);
                });
            }).catch(err => {
                rendering = false;
                console.error('Error obteniendo página:', err);
            });
        };
        
        // Navegación de páginas
        if (prevBtn) {
            prevBtn.onclick = () => {
                if (pageNum <= 1 || !pdfDoc) return;
                pageNum--;
                renderPage(pageNum);
            };
        }
        
        if (nextBtn) {
            nextBtn.onclick = () => {
                if (!pdfDoc || pageNum >= pdfDoc.numPages) return;
                pageNum++;
                renderPage(pageNum);
            };
        }
        
        // Slider de páginas
        if (pageSlider) {
            pageSlider.oninput = () => {
                if (!pdfDoc) return;
                const newPageNum = parseInt(pageSlider.value);
                if (newPageNum !== pageNum && newPageNum >= 1 && newPageNum <= pdfDoc.numPages) {
                    pageNum = newPageNum;
                    renderPage(pageNum);
                }
            };
        }
        
        // Zoom
        if (zoomInBtn) {
            zoomInBtn.onclick = () => {
                if (scale >= 5.0) return;
                scale = Math.min(scale + 0.25, 5.0);
                renderPage(pageNum);
            };
        }
        
        if (zoomOutBtn) {
            zoomOutBtn.onclick = () => {
                if (scale <= 0.25) return;
                scale = Math.max(scale - 0.25, 0.25);
                renderPage(pageNum);
            };
        }
        
        if (fitWidthBtn) {
            fitWidthBtn.onclick = () => {
                autoFitWidth();
            };
        }
        
        // Descarga
        if (downloadBtn) {
            downloadBtn.onclick = () => {
                const link = document.createElement('a');
                link.href = pdfUrl;
                link.download = pdfUrl.split('/').pop();
                document.body.appendChild(link);
                link.click();
                document.body.removeChild(link);
            };
        }
        
        // Gestos táctiles
        let lastPinchDistance = 0;
        let initialScale = scale;
        let isPanning = false;
        let panStartX = 0;
        let panStartY = 0;
        let lastPanX = 0;
        let lastPanY = 0;
        let lastTapTime = 0;
        let tapTimeout = null;
        
        // Touch events
        canvas.addEventListener('touchstart', (e) => {
            if (e.touches.length === 1) {
                const touch = e.touches[0];
                panStartX = touch.clientX;
                panStartY = touch.clientY;
                lastPanX = canvasContainer.scrollLeft;
                lastPanY = canvasContainer.scrollTop;
                
                // Doble tap
                const currentTime = Date.now();
                const tapLength = currentTime - lastTapTime;
                
                if (tapLength < 350 && tapLength > 0) {
                    e.preventDefault();
                    clearTimeout(tapTimeout);
                    isPanning = false;
                    
                    const isZoomedIn = scale > (baseScale * 1.1);
                    
                    if (isZoomedIn) {
                        autoFitWidth();
                    } else {
                        scale = Math.min(Math.max(baseScale * 2.0, 2.0), 5.0);
                        renderPage(pageNum);
                        setTimeout(() => {
                            const canvasRect = canvas.getBoundingClientRect();
                            const tapX = touch.clientX - canvasRect.left;
                            const tapY = touch.clientY - canvasRect.top;
                            const pdfX = (tapX + canvasContainer.scrollLeft) / baseScale;
                            const pdfY = (tapY + canvasContainer.scrollTop) / baseScale;
                            canvasContainer.scrollLeft = Math.max(0, (pdfX * scale) - (canvasContainer.clientWidth / 2));
                            canvasContainer.scrollTop = Math.max(0, (pdfY * scale) - (canvasContainer.clientHeight / 2));
                        }, 150);
                    }
                    lastTapTime = 0;
                } else {
                    tapTimeout = setTimeout(() => {
                        lastTapTime = currentTime;
                    }, 350);
                }
            } else if (e.touches.length === 2) {
                e.preventDefault();
                clearTimeout(tapTimeout);
                isPanning = false;
                
                const touch1 = e.touches[0];
                const touch2 = e.touches[1];
                lastPinchDistance = Math.hypot(
                    touch2.clientX - touch1.clientX,
                    touch2.clientY - touch1.clientY
                );
                initialScale = scale;
            }
        }, { passive: false });
        
        canvas.addEventListener('touchmove', (e) => {
            if (e.touches.length === 1 && isPanning) {
                e.preventDefault();
                const touch = e.touches[0];
                const deltaX = panStartX - touch.clientX;
                const deltaY = panStartY - touch.clientY;
                
                const newScrollX = lastPanX + deltaX;
                const newScrollY = lastPanY + deltaY;
                const maxScrollX = Math.max(0, canvas.width - canvasContainer.clientWidth);
                const maxScrollY = Math.max(0, canvas.height - canvasContainer.clientHeight);
                
                canvasContainer.scrollLeft = Math.max(0, Math.min(newScrollX, maxScrollX));
                canvasContainer.scrollTop = Math.max(0, Math.min(newScrollY, maxScrollY));
                
            } else if (e.touches.length === 2) {
                e.preventDefault();
                isPanning = false;
                
                const touch1 = e.touches[0];
                const touch2 = e.touches[1];
                const currentDistance = Math.hypot(
                    touch2.clientX - touch1.clientX,
                    touch2.clientY - touch1.clientY
                );
                
                if (lastPinchDistance > 0) {
                    const scaleFactor = currentDistance / lastPinchDistance;
                    let newScale = initialScale * scaleFactor;
                    newScale = Math.max(0.25, Math.min(5.0, newScale));
                    
                    if (Math.abs(newScale - scale) > 0.05) {
                        scale = newScale;
                        clearTimeout(pinchZoomTimeout);
                        pinchZoomTimeout = setTimeout(() => {
                            if (pdfDoc && !rendering) {
                                renderPage(pageNum);
                            }
                        }, 30);
                    }
                }
            } else if (e.touches.length === 1) {
                const touch = e.touches[0];
                const deltaX = Math.abs(panStartX - touch.clientX);
                const deltaY = Math.abs(panStartY - touch.clientY);
                
                if (deltaX > 8 || deltaY > 8) {
                    isPanning = true;
                    clearTimeout(tapTimeout);
                }
            }
        }, { passive: false });
        
        let pinchZoomTimeout = null;
        
        canvas.addEventListener('touchend', (e) => {
            if (e.touches.length < 2 && lastPinchDistance > 0) {
                clearTimeout(pinchZoomTimeout);
                if (pdfDoc && !rendering) {
                    renderPage(pageNum);
                }
                lastPinchDistance = 0;
                isPanning = false;
            } else if (e.touches.length === 0) {
                isPanning = false;
                clearTimeout(tapTimeout);
            }
        });
        
        // Mouse pan para desktop
        let isMousePanning = false;
        let mouseStartX = 0;
        let mouseStartY = 0;
        let mouseLastScrollX = 0;
        let mouseLastScrollY = 0;
        
        canvas.addEventListener('mousedown', (e) => {
            if (e.button === 0) {
                isMousePanning = true;
                mouseStartX = e.clientX;
                mouseStartY = e.clientY;
                mouseLastScrollX = canvasContainer.scrollLeft;
                mouseLastScrollY = canvasContainer.scrollTop;
                canvas.style.cursor = 'grabbing';
            }
        });
        
        canvas.addEventListener('mousemove', (e) => {
            if (isMousePanning) {
                e.preventDefault();
                const deltaX = mouseStartX - e.clientX;
                const deltaY = mouseStartY - e.clientY;
                canvasContainer.scrollLeft = mouseLastScrollX + deltaX;
                canvasContainer.scrollTop = mouseLastScrollY + deltaY;
            }
        });
        
        canvas.addEventListener('mouseup', () => {
            isMousePanning = false;
            canvas.style.cursor = 'grab';
        });
        
        canvas.addEventListener('mouseleave', () => {
            isMousePanning = false;
            canvas.style.cursor = 'grab';
        });
    }
    
    /**
     * Función legacy - inicializaba el visor personalizado (ya no se usa)
     * @param {string} pdfUrl - URL completa del PDF
     * @deprecated Usar el viewer oficial de PDF.js en iframe
     */
    initPdfViewer_LEGACY(pdfUrl) {
        const canvas = document.getElementById('pdfCanvas');
        if (!canvas) {
            console.error('Canvas del PDF no encontrado');
            return;
        }
        
        const ctx = canvas.getContext('2d');
        const loadingSpinner = document.getElementById('pdfLoadingSpinner');
        const pageInfo = document.getElementById('pdfPageInfo');
        const pageSlider = document.getElementById('pdfPageSlider');
        const prevBtn = document.getElementById('pdfPrevPage');
        const nextBtn = document.getElementById('pdfNextPage');
        const zoomInBtn = document.getElementById('pdfZoomIn');
        const zoomOutBtn = document.getElementById('pdfZoomOut');
        const fitWidthBtn = document.getElementById('pdfFitWidth');
        const downloadBtn = document.getElementById('pdfDownloadBtn');
        const canvasContainer = canvas.parentElement; // Contenedor para scroll
        
        // Verificar que PDF.js esté disponible
        if (typeof pdfjsLib === 'undefined') {
            console.error('PDF.js no está disponible');
            if (loadingSpinner) {
                loadingSpinner.innerHTML = `
                    <div class="text-danger">
                        <i class="fas fa-exclamation-triangle fa-3x mb-3"></i>
                        <div>Error: PDF.js no está cargado</div>
                    </div>
                `;
            }
            return;
        }
        
        let pdfDoc = null;
        let pageNum = 1;
        let scale = 1.5; // Escala base (será ajustada automáticamente al inicio)
        let baseScale = 1.5; // Escala base (ajuste automático al ancho)
        let rendering = false;
        let currentViewport = null;
        
        // Habilitar scroll en el contenedor y hacer que el canvas sea interactivo
        if (canvasContainer) {
            canvasContainer.style.overflow = 'auto';
            canvasContainer.style.position = 'relative';
        }
        
        // Limpiar listeners anteriores si existen
        if (prevBtn) prevBtn.onclick = null;
        if (nextBtn) nextBtn.onclick = null;
        if (zoomInBtn) zoomInBtn.onclick = null;
        if (zoomOutBtn) zoomOutBtn.onclick = null;
        if (fitWidthBtn) fitWidthBtn.onclick = null;
        if (downloadBtn) downloadBtn.onclick = null;
        if (pageSlider) pageSlider.oninput = null;
        
        // Mostrar spinner de carga
        if (loadingSpinner) loadingSpinner.style.display = 'flex';
        canvas.style.display = 'none';
        
        // Cargar el PDF
        pdfjsLib.getDocument(pdfUrl).promise.then(pdf => {
            pdfDoc = pdf;
            if (pageInfo) pageInfo.textContent = `Página ${pageNum} de ${pdf.numPages}`;
            if (pageSlider) {
                pageSlider.max = pdf.numPages;
                pageSlider.value = pageNum;
            }
            
            // Ocultar spinner
            if (loadingSpinner) loadingSpinner.style.display = 'none';
            canvas.style.display = 'block';
            
            // Auto-ajustar al ancho al inicio
            autoFitWidth();
        }).catch(error => {
            console.error('Error cargando PDF:', error);
            if (loadingSpinner) {
                loadingSpinner.innerHTML = `
                    <div class="text-danger">
                        <i class="fas fa-exclamation-triangle fa-3x mb-3"></i>
                        <div>Error al cargar el PDF</div>
                        <small class="d-block mt-2">${error.message}</small>
                        <button class="btn btn-sm btn-outline-light mt-3" onclick="window.location.reload()">
                            Recargar
                        </button>
                    </div>
                `;
            }
        });
        
        // Función para auto-ajustar al ancho al inicio
        const autoFitWidth = () => {
            if (!pdfDoc) return;
            pdfDoc.getPage(pageNum).then(page => {
                const viewport = page.getViewport({ scale: 1 });
                // Calcular el ancho disponible del contenedor (con márgenes)
                const containerWidth = canvasContainer ? (canvasContainer.clientWidth - 40) : (window.innerWidth - 100);
                const newScale = containerWidth / viewport.width;
                scale = Math.max(newScale, 0.5); // Mínimo de 0.5x zoom
                scale = Math.min(scale, 3.0); // Máximo de 3x zoom
                baseScale = scale; // Guardar la escala base (ajuste automático)
                renderPage(pageNum);
                // Reset scroll al centro
                if (canvasContainer) {
                    setTimeout(() => {
                        canvasContainer.scrollTop = 0;
                        canvasContainer.scrollLeft = 0;
                    }, 100);
                }
            });
        };
        
        // Función para renderizar una página con alta calidad
        const renderPage = (num) => {
            if (rendering || !pdfDoc) return;
            rendering = true;
            
            pdfDoc.getPage(num).then(page => {
                // Calcular DPR (Device Pixel Ratio) para alta calidad en pantallas retina
                const dpr = window.devicePixelRatio || 1;
                
                // Calcular viewport con la escala actual
                const viewport = page.getViewport({ scale: scale });
                currentViewport = viewport;
                
                // Ajustar tamaño del canvas considerando el DPR para alta calidad
                const outputScale = Math.max(dpr, 1.5); // Mínimo 1.5x para buena calidad
                canvas.height = Math.floor(viewport.height * outputScale);
                canvas.width = Math.floor(viewport.width * outputScale);
                
                // Estilo CSS para que el canvas se muestre al tamaño correcto
                canvas.style.height = viewport.height + 'px';
                canvas.style.width = viewport.width + 'px';
                
                // Configurar contexto para alta resolución
                ctx.save();
                ctx.scale(outputScale, outputScale);
                
                const renderContext = {
                    canvasContext: ctx,
                    viewport: viewport
                };
                
                page.render(renderContext).promise.then(() => {
                    ctx.restore();
                    rendering = false;
                    if (pageInfo) pageInfo.textContent = `Página ${num} de ${pdfDoc.numPages}`;
                    if (pageSlider) pageSlider.value = num;
                }).catch(err => {
                    ctx.restore();
                    rendering = false;
                    console.error('Error renderizando página:', err);
                });
            }).catch(err => {
                rendering = false;
                console.error('Error obteniendo página:', err);
            });
        };
        
        // Navegación de páginas
        if (prevBtn) {
            prevBtn.onclick = () => {
                if (pageNum <= 1 || !pdfDoc) return;
                pageNum--;
                renderPage(pageNum);
            };
        }
        
        if (nextBtn) {
            nextBtn.onclick = () => {
                if (!pdfDoc || pageNum >= pdfDoc.numPages) return;
                pageNum++;
                renderPage(pageNum);
            };
        }
        
        // Slider de páginas
        if (pageSlider) {
            pageSlider.oninput = () => {
                if (!pdfDoc) return;
                const newPageNum = parseInt(pageSlider.value);
                if (newPageNum !== pageNum && newPageNum >= 1 && newPageNum <= pdfDoc.numPages) {
                    pageNum = newPageNum;
                    renderPage(pageNum);
                }
            };
        }
        
        // Zoom in (incrementos más suaves para mejor calidad)
        if (zoomInBtn) {
            zoomInBtn.onclick = () => {
                if (scale >= 5.0) return; // Máximo 5x zoom
                scale += 0.25;
                scale = Math.min(scale, 5.0);
                renderPage(pageNum);
            };
        }
        
        // Zoom out (incrementos más suaves)
        if (zoomOutBtn) {
            zoomOutBtn.onclick = () => {
                if (scale <= 0.25) return; // Mínimo 0.25x zoom
                scale -= 0.25;
                scale = Math.max(scale, 0.25);
                renderPage(pageNum);
            };
        }
        
        // Ajustar al ancho (también resetea la posición de scroll)
        if (fitWidthBtn) {
            fitWidthBtn.onclick = () => {
                autoFitWidth();
                // Scroll al inicio
                if (canvasContainer) {
                    canvasContainer.scrollTop = 0;
                    canvasContainer.scrollLeft = 0;
                }
            };
        }
        
        // Soporte para gestos táctiles (pinch zoom, pan, y doble tap)
        let lastPinchDistance = 0;
        let lastTouchTime = 0;
        let initialScale = scale;
        let pinchZoomTimeout = null;
        
        // Pan (arrastre) con un dedo
        let isPanning = false;
        let lastPanX = 0;
        let lastPanY = 0;
        let panStartX = 0;
        let panStartY = 0;
        
        // Doble tap para zoom
        let lastTapTime = 0;
        let tapTimeout = null;
        let doubleTapScale = scale;
        
        if (canvas && canvasContainer) {
            // Touch start - detectar tipo de gesto
            canvas.addEventListener('touchstart', (e) => {
                if (e.touches.length === 1) {
                    // Un dedo - preparar para pan o doble tap
                    const touch = e.touches[0];
                    panStartX = touch.clientX;
                    panStartY = touch.clientY;
                    lastPanX = canvasContainer.scrollLeft;
                    lastPanY = canvasContainer.scrollTop;
                    
                    // Detectar doble tap
                    const currentTime = Date.now();
                    const tapLength = currentTime - lastTapTime;
                    
                    if (tapLength < 350 && tapLength > 0) {
                        // Es un doble tap
                        e.preventDefault();
                        clearTimeout(tapTimeout);
                        isPanning = false; // Cancelar pan si estaba activo
                        
                        // Toggle zoom: si está ampliado (más de la base), reducir; si no, ampliar a 2x
                        const isZoomedIn = scale > (baseScale * 1.1); // 10% más que la base significa que está ampliado
                        
                        if (isZoomedIn) {
                            // Reducir al ancho (ajuste automático)
                            autoFitWidth();
                        } else {
                            // Ampliar a 2x de la escala base (o mínimo 2x)
                            const newScale = Math.max(baseScale * 2.0, 2.0);
                            scale = Math.min(newScale, 5.0);
                            
                            // Obtener posición del tap relativa al canvas
                            const canvasRect = canvas.getBoundingClientRect();
                            const tapX = touch.clientX - canvasRect.left;
                            const tapY = touch.clientY - canvasRect.top;
                            
                            // Calcular el punto en el PDF antes del zoom
                            const scaleFactor = scale / baseScale;
                            const pdfX = (tapX + canvasContainer.scrollLeft) / baseScale;
                            const pdfY = (tapY + canvasContainer.scrollTop) / baseScale;
                            
                            // Renderizar con nuevo zoom
                            renderPage(pageNum);
                            
                            // Ajustar scroll para mantener el punto del tap centrado después del zoom
                            if (canvasContainer) {
                                setTimeout(() => {
                                    const newScrollX = (pdfX * scale) - (canvasContainer.clientWidth / 2);
                                    const newScrollY = (pdfY * scale) - (canvasContainer.clientHeight / 2);
                                    
                                    canvasContainer.scrollLeft = Math.max(0, newScrollX);
                                    canvasContainer.scrollTop = Math.max(0, newScrollY);
                                }, 150);
                            }
                        }
                        
                        lastTapTime = 0; // Reset para evitar triple tap
                    } else {
                        // Podría ser un solo tap - esperar un poco para confirmar
                        tapTimeout = setTimeout(() => {
                            // Si no hay segundo tap en 300ms, no hacer nada
                            lastTapTime = currentTime;
                        }, 350);
                    }
                    
                } else if (e.touches.length === 2) {
                    // Dos dedos - pinch zoom
                    e.preventDefault();
                    clearTimeout(tapTimeout);
                    isPanning = false;
                    
                    const touch1 = e.touches[0];
                    const touch2 = e.touches[1];
                    
                    lastPinchDistance = Math.hypot(
                        touch2.clientX - touch1.clientX,
                        touch2.clientY - touch1.clientY
                    );
                    initialScale = scale;
                }
            }, { passive: false });
            
            // Touch move - manejar pan y pinch zoom
            canvas.addEventListener('touchmove', (e) => {
                if (e.touches.length === 1 && isPanning) {
                    // Pan con un dedo - mover el documento
                    e.preventDefault();
                    const touch = e.touches[0];
                    const deltaX = panStartX - touch.clientX;
                    const deltaY = panStartY - touch.clientY;
                    
                    // Aplicar desplazamiento al contenedor
                    const newScrollX = lastPanX + deltaX;
                    const newScrollY = lastPanY + deltaY;
                    
                    // Limitar scroll dentro de los bordes del canvas
                    const maxScrollX = Math.max(0, canvas.width - canvasContainer.clientWidth);
                    const maxScrollY = Math.max(0, canvas.height - canvasContainer.clientHeight);
                    
                    canvasContainer.scrollLeft = Math.max(0, Math.min(newScrollX, maxScrollX));
                    canvasContainer.scrollTop = Math.max(0, Math.min(newScrollY, maxScrollY));
                    
                } else if (e.touches.length === 2) {
                    // Pinch zoom con dos dedos
                    e.preventDefault();
                    isPanning = false;
                    
                    const touch1 = e.touches[0];
                    const touch2 = e.touches[1];
                    const currentDistance = Math.hypot(
                        touch2.clientX - touch1.clientX,
                        touch2.clientY - touch1.clientY
                    );
                    
                    if (lastPinchDistance > 0) {
                        const scaleFactor = currentDistance / lastPinchDistance;
                        let newScale = initialScale * scaleFactor;
                        
                        // Limitar zoom
                        newScale = Math.max(0.25, Math.min(5.0, newScale));
                        
                        // Solo actualizar si hay cambio significativo (mejor performance)
                        if (Math.abs(newScale - scale) > 0.05) {
                            scale = newScale;
                            
                            // Renderizado más fluido durante pinch (cada 30ms para mayor fluidez)
                            clearTimeout(pinchZoomTimeout);
                            pinchZoomTimeout = setTimeout(() => {
                                if (pdfDoc && !rendering) {
                                    renderPage(pageNum);
                                }
                            }, 30);
                        }
                    }
                } else if (e.touches.length === 1) {
                    // Un dedo moviendo - activar pan si se mueve lo suficiente
                    const touch = e.touches[0];
                    const deltaX = Math.abs(panStartX - touch.clientX);
                    const deltaY = Math.abs(panStartY - touch.clientY);
                    
                    // Si el movimiento es significativo (más de 8px), activar pan y cancelar doble tap
                    if (deltaX > 8 || deltaY > 8) {
                        isPanning = true;
                        clearTimeout(tapTimeout); // Cancelar detección de doble tap
                    }
                }
            }, { passive: false });
            
            // Touch end - finalizar gestos
            canvas.addEventListener('touchend', (e) => {
                if (e.touches.length < 2 && lastPinchDistance > 0) {
                    // Terminó el pinch zoom - hacer render final de alta calidad
                    clearTimeout(pinchZoomTimeout);
                    if (pdfDoc && !rendering) {
                        renderPage(pageNum);
                    }
                    lastPinchDistance = 0;
                    isPanning = false;
                } else if (e.touches.length === 0) {
                    // No hay toques - reset pan y limpiar timeouts
                    isPanning = false;
                    clearTimeout(tapTimeout);
                } else if (e.touches.length === 1) {
                    // Quedó un dedo después del pinch - podría ser pan
                    const touch = e.touches[0];
                    panStartX = touch.clientX;
                    panStartY = touch.clientY;
                    lastPanX = canvasContainer.scrollLeft;
                    lastPanY = canvasContainer.scrollTop;
                }
            });
            
            // Mouse events para desktop (pan con clic y arrastre)
            let isMousePanning = false;
            let mouseStartX = 0;
            let mouseStartY = 0;
            let mouseLastScrollX = 0;
            let mouseLastScrollY = 0;
            
            canvas.addEventListener('mousedown', (e) => {
                if (e.button === 0) { // Botón izquierdo
                    isMousePanning = true;
                    mouseStartX = e.clientX;
                    mouseStartY = e.clientY;
                    mouseLastScrollX = canvasContainer.scrollLeft;
                    mouseLastScrollY = canvasContainer.scrollTop;
                    canvas.style.cursor = 'grabbing';
                }
            });
            
            canvas.addEventListener('mousemove', (e) => {
                if (isMousePanning) {
                    e.preventDefault();
                    const deltaX = mouseStartX - e.clientX;
                    const deltaY = mouseStartY - e.clientY;
                    
                    canvasContainer.scrollLeft = mouseLastScrollX + deltaX;
                    canvasContainer.scrollTop = mouseLastScrollY + deltaY;
                }
            });
            
            canvas.addEventListener('mouseup', () => {
                isMousePanning = false;
                canvas.style.cursor = 'grab';
            });
            
            canvas.addEventListener('mouseleave', () => {
                isMousePanning = false;
                canvas.style.cursor = 'grab';
            });
        }
        
        // Descarga
        if (downloadBtn) {
            downloadBtn.onclick = () => {
                const link = document.createElement('a');
                link.href = pdfUrl;
                link.download = pdfUrl.split('/').pop();
                document.body.appendChild(link);
                link.click();
                document.body.removeChild(link);
            };
        }
        
        // Teclado shortcuts (solo cuando el modal está abierto)
        const modalElement = document.getElementById('pdfViewerModal');
        if (modalElement) {
            const keydownHandler = (e) => {
                // Solo procesar si el modal está visible
                if (!modalElement.classList.contains('show') || !pdfDoc) return;
                
                if (e.key === 'ArrowLeft' || e.key === 'ArrowUp') {
                    e.preventDefault();
                    if (prevBtn && prevBtn.onclick) prevBtn.onclick();
                } else if (e.key === 'ArrowRight' || e.key === 'ArrowDown') {
                    e.preventDefault();
                    if (nextBtn && nextBtn.onclick) nextBtn.onclick();
                } else if (e.key === '+' || e.key === '=') {
                    e.preventDefault();
                    if (zoomInBtn && zoomInBtn.onclick) zoomInBtn.onclick();
                } else if (e.key === '-') {
                    e.preventDefault();
                    if (zoomOutBtn && zoomOutBtn.onclick) zoomOutBtn.onclick();
                }
            };
            
            // Agregar listener cuando se abre el modal
            modalElement.addEventListener('shown.bs.modal', () => {
                document.addEventListener('keydown', keydownHandler);
            }, { once: false });
            
            // Remover listener cuando se cierra el modal
            modalElement.addEventListener('hidden.bs.modal', () => {
                document.removeEventListener('keydown', keydownHandler);
            }, { once: false });
        }
    }
    
    /**
     * Descarga un PDF (función legacy, ahora usa el botón del visor oficial)
     * @param {string} pdfPath - Ruta relativa del PDF
     */
    downloadPdf(pdfPath) {
        const pdfUrl = this.buildPdfUrl(pdfPath);
        const link = document.createElement('a');
        link.href = pdfUrl;
        link.download = pdfPath.split('/').pop();
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
    }
    
    /**
     * Actualiza el modal con los estudios del paciente
     * @param {string} patientId - ID del paciente (documento o id_interno)
     * @param {string} searchType - Tipo de búsqueda: 'documento' o 'id_interno'
     */
    async updateStudiesModal(patientId, searchType = 'documento') {
        const modalBody = document.querySelector('#estudioModal .modal-body');
        const tableBody = document.querySelector('#estudioModal tbody');
        const modalTitle = document.getElementById('estudioModalTitle');
        const modalTitleMeta = document.getElementById('estudioModalTitleMeta');
        if (modalTitle) {
            modalTitle.textContent = 'Consultando estudios…';
        }
        if (modalTitleMeta) {
            modalTitleMeta.textContent = '';
            modalTitleMeta.classList.add('d-none');
        }
        
        if (!tableBody) {
            console.error('No se encontró la tabla de estudios en el modal');
            return;
        }
        
        // Mostrar loading con progreso
        let loadingDots = 0;
        const loadingInterval = setInterval(() => {
            loadingDots = (loadingDots + 1) % 4;
            const dots = '.'.repeat(loadingDots);
            const loadingRow = tableBody.querySelector('.loading-row');
            if (loadingRow) {
                loadingRow.innerHTML = `
                    <td colspan="4" class="text-center py-4">
                        <div class="spinner-border text-primary" role="status">
                            <span class="visually-hidden">Cargando...</span>
                        </div>
                        <div class="mt-2">Consultando CLOUD PACS${dots}</div>
                        <small class="text-muted d-block mt-1">Esto puede tomar unos segundos...</small>
                    </td>
                `;
            }
        }, 500);
        
        tableBody.innerHTML = `
            <tr class="loading-row">
                <td colspan="4" class="text-center py-4">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Cargando...</span>
                    </div>
                    <div class="mt-2">Consultando CLOUD PACS...</div>
                    <small class="text-muted d-block mt-1">Esto puede tomar unos segundos...</small>
                </td>
            </tr>
        `;
        
        try {
            let response = null;
            let studies = [];
            let patientName = 'Paciente';
            let useV2 = false;

            // Intentar v2 primero; si está deshabilitado o falla, caer al legacy.
            try {
                const v2Response = await this.getPatientStudiesV2(patientId, searchType);
                const rawV2 = String(v2Response?.config?.v2_enabled ?? '0').trim().toLowerCase();
                const v2Enabled = rawV2 === '1' || rawV2 === 'true' || rawV2 === 'yes' || rawV2 === 'on' || rawV2 === 'si' || rawV2 === 'sí';
                if (v2Enabled) {
                    useV2 = true;
                    response = v2Response;
                    studies = Array.isArray(v2Response.studies) ? v2Response.studies : [];
                    const firstName = studies[0]?.patient_name || '';
                    patientName = firstName || (v2Response?.patient_data?.nombre || 'Paciente');
                }
            } catch (v2Error) {
                console.warn('[portal-v2] fallback a legacy:', v2Error?.message || v2Error);
            }

            if (!useV2) {
                response = await this.getPatientStudies(patientId, searchType);
                studies = response.data || [];
                patientName = response.patient_name || 'Paciente';
            }
            
            // Limpiar el intervalo de loading
            clearInterval(loadingInterval);

            const qaInfo = response?.qa || null;
            let studiesHTML = useV2 ? this.generateStudiesHTMLV2(studies) : this.generateStudiesHTML(studies);
            if ((!studies || studies.length === 0) && qaInfo && qaInfo.enabled && (qaInfo.pendientes > 0 || qaInfo.ocultos > 0)) {
                const pendingMsg = qaInfo.message_pendiente
                    || 'Su estudio está pendiente de validación y publicación. Por favor, consulte más tarde o comuníquese con la institución.';
                studiesHTML = `
                    <tr>
                        <td colspan="4" class="text-center py-4">
                            <i class="fas fa-clock text-warning me-2"></i>
                            ${pendingMsg}
                        </td>
                    </tr>
                `;
            }
            tableBody.innerHTML = studiesHTML;

            const displayName = this.formatPatientWelcomeName(patientName);
            if (modalTitle) {
                modalTitle.textContent = `Hola ${displayName}, estos son tus estudios e informes`;
            }
            if (modalTitleMeta) {
                const totalInformes = response.total_informes || 0;
                const totalStudies = useV2
                    ? (response.total_studies != null ? response.total_studies : (response.count || studies.length || 0))
                    : (response.total_studies || 0);
                let metaText = '';
                if (totalInformes > 0 || totalStudies > 0) {
                    const parts = [];
                    if (totalInformes > 0) parts.push(`${totalInformes} informe${totalInformes > 1 ? 's' : ''}`);
                    if (totalStudies > 0) parts.push(`${totalStudies} estudio${totalStudies > 1 ? 's' : ''}`);
                    metaText = `${parts.join(', ')} encontrado${parts.length > 1 ? 's' : ''}`;
                } else {
                    metaText = '0 encontrados';
                }
                modalTitleMeta.textContent = metaText;
                modalTitleMeta.classList.remove('d-none');
            }
            
        } catch (error) {
            // Limpiar el intervalo de loading
            clearInterval(loadingInterval);
            
            // Mostrar mensaje diferente según el tipo de error
            let errorMessage = '';
            let errorIcon = 'fas fa-exclamation-triangle';
            let errorClass = 'text-danger';
            let helpText = '';
            
            if (error.isInactivePatient || (error.message && error.message.includes('inactivo'))) {
                // Error específico para paciente inactivo
                errorMessage = 'El paciente está inactivo y no puede buscar estudios';
                errorIcon = 'fas fa-ban';
                errorClass = 'text-warning';
                helpText = 'Contacte al administrador si necesita activar este paciente';
            } else if (error.message && error.message.includes('No se encontró')) {
                // Error de paciente no encontrado
                errorMessage = 'No se encontraron estudios para este paciente';
                errorIcon = 'fas fa-search';
                errorClass = 'text-info';
                helpText = 'Verifique que el ID ingresado sea correcto';
            } else {
                // Error genérico
                errorMessage = error.message || 'Error al cargar los estudios';
                errorIcon = 'fas fa-exclamation-triangle';
                errorClass = 'text-danger';
                helpText = 'Verifique la conexión con el servidor PACS';
            }
            
            // Solo loguear en consola si NO es error de paciente inactivo (para reducir ruido)
            if (!error.isInactivePatient) {
                console.error('Error cargando estudios:', error);
            }
            
            tableBody.innerHTML = `
                <tr>
                    <td colspan="4" class="text-center py-4 ${errorClass}">
                        <i class="${errorIcon} me-2" style="font-size: 2rem;"></i>
                        <div class="mt-2"><strong>${errorMessage}</strong></div>
                        ${helpText ? `<div class="mt-2"><small class="text-muted">${helpText}</small></div>` : ''}
                    </td>
                </tr>
            `;
            if (modalTitle) {
                modalTitle.textContent = 'Mis estudios';
            }
            if (modalTitleMeta) {
                modalTitleMeta.textContent = '';
                modalTitleMeta.classList.add('d-none');
            }
        }
    }
    
    /**
     * Descarga un estudio desde Orthanc
     * @param {string} studyId - ID del estudio en Orthanc
     * @param {string} patientId - ID del paciente (opcional, para nombre de archivo)
     * @param {string} patientName - Nombre del paciente (opcional, para nombre de archivo)
     * @param {string} studyDate - Fecha del estudio (opcional, para nombre de archivo)
     */
    async downloadStudy(studyId, patientId = '', patientName = '', studyDate = '', studyDescription = '') {
        try {
            if (!studyId) {
                console.error('No se proporcionó ID del estudio');
                alert('Error: No se proporcionó ID del estudio');
                return;
            }
            
            console.log('Descargando estudio:', studyId);
            
            const orthancBaseUrl = await this.ensureDownloadBaseUrl();
            
            // Construir nombre del archivo en formato: idpaciente-nombrepaciente-fechaestudio-descripcion.zip
            let filename = '';
            if (patientId && patientName && studyDate) {
                // Formatear fecha: convertir a formato YYYYMMDD
                const formattedDate = studyDate.replace(/-/g, '').substring(0, 8);
                
                // Construir partes del filename
                const parts = [];
                parts.push(patientId.trim());
                parts.push(patientName.trim());
                parts.push(formattedDate);
                if (studyDescription && studyDescription.trim()) {
                    parts.push(studyDescription.trim());
                }
                
                filename = `${parts.join('-')}.zip`;
            } else {
                // Fallback: usar solo el ID del estudio
                filename = `study_${studyId}.zip`;
            }
            
            // Construir URL de descarga con parámetro filename
            const downloadUrl = `${orthancBaseUrl}/studies/${studyId}/archive?filename=${encodeURIComponent(filename)}`;
            
            console.log('URL de descarga:', downloadUrl);
            
            // Crear enlace temporal para descarga (sin abrir nueva pestaña)
            const link = document.createElement('a');
            link.href = downloadUrl;
            link.download = filename;
            // No usar target='_blank' para evitar abrir nueva pestaña
            
            // Ejecutar descarga
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
            
            console.log('Descarga iniciada para estudio:', studyId);
            
        } catch (error) {
            console.error('Error descargando estudio:', error);
            alert('Error al descargar el estudio. Verifique la conexión con Orthanc.');
        }
    }
}

// Instancia global para uso en el HTML
const orthancIntegration = new OrthancIntegration();