/**
 * Acción rápida QA: despublicar/republicar en portal desde estudios-manager e informes-manager.
 */
(function (global) {
    'use strict';

    const API_BASE = 'modules/qa-publicacion/api/';
    const MOTIVOS = [
        'series_otro_paciente',
        'demograficos_incorrectos',
        'calidad_imagen',
        'informe_erroneo',
        'otro',
    ];

    const QaQuickAction = {
        enabled: false,
        canBajarPacs: false,
        installed: false,
        qaEnabled: false,
        studyStatus: new Map(),
        informeStatus: new Map(),

        async init() {
            try {
                const res = await fetch('api/auth/validate-session-simple.php', { credentials: 'include' });
                const data = await res.json();
                const user = data.user || data.data;
                if (!data.success || !user) {
                    return;
                }
                let permisos = user.permisos || [];
                if (typeof permisos === 'string') {
                    try { permisos = JSON.parse(permisos); } catch (e) { permisos = []; }
                }
                const has = (k) => Array.isArray(permisos) && (
                    permisos.includes('all') || permisos.includes(k) || user.nivel === 'root'
                );
                this.enabled = has('qa_despublicar_rapido') || has('qa_revisar');
                this.canBajarPacs = has('qa_bajar_pacs') || has('gestionInformes');
            } catch (e) {
                console.warn('[QaQuickAction] init:', e);
            }
        },

        showToast(message, type = 'success') {
            let container = document.getElementById('qaToastContainer');
            if (!container) {
                container = document.createElement('div');
                container.id = 'qaToastContainer';
                container.className = 'toast-container position-fixed top-0 end-0 p-3';
                container.style.zIndex = '10050';
                document.body.appendChild(container);
            }

            const toastId = 'qaToast_' + Date.now();
            const bgClass = type === 'success' ? 'bg-success'
                : type === 'error' ? 'bg-danger'
                    : type === 'warning' ? 'bg-warning text-dark' : 'bg-info text-dark';
            const icon = type === 'success' ? 'check-circle'
                : type === 'error' ? 'exclamation-triangle' : 'info-circle';

            container.insertAdjacentHTML('beforeend', `
                <div id="${toastId}" class="toast align-items-center text-white ${bgClass} border-0" role="alert">
                    <div class="d-flex">
                        <div class="toast-body">
                            <i class="fas fa-${icon} me-2"></i>${message}
                        </div>
                        <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
                    </div>
                </div>`);

            const el = document.getElementById(toastId);
            const toast = bootstrap.Toast.getOrCreateInstance(el, {
                autohide: true,
                delay: type === 'error' ? 5000 : 3500,
            });
            toast.show();
            el.addEventListener('hidden.bs.toast', () => el.remove());
        },

        motivoLabel(key) {
            const map = {
                series_otro_paciente: 'Series de otro paciente',
                demograficos_incorrectos: 'Demográficos incorrectos',
                calidad_imagen: 'Calidad de imagen',
                informe_erroneo: 'Informe erróneo',
                otro: 'Otro',
            };
            return map[key] || key;
        },

        studyUid(study) {
            return String(study?.study_instance_uid || '').trim();
        },

        getStudyEstado(study) {
            const uid = this.studyUid(study);
            if (!uid) return null;
            const row = this.studyStatus.get(uid);
            return row?.estado || null;
        },

        getInformeEstado(informe) {
            const id = parseInt(informe?.id, 10);
            if (!id) return null;
            const row = this.informeStatus.get(id);
            return row?.estado || null;
        },

        async apiPost(endpoint, body) {
            const res = await fetch(API_BASE + endpoint, {
                method: 'POST',
                credentials: 'include',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(body),
            });
            const data = await res.json();
            if (!data.success) {
                throw new Error(data.error || data.message || 'Error en solicitud QA');
            }
            return data;
        },

        async refreshStudyStatuses(studies) {
            if (!this.enabled || !Array.isArray(studies) || !studies.length) return;
            const uids = [...new Set(studies.map((s) => this.studyUid(s)).filter(Boolean))];
            if (!uids.length) return;
            try {
                const data = await this.apiPost('get-status-batch.php', { study_instance_uids: uids });
                this.installed = !!data.installed;
                this.qaEnabled = !!data.enabled;
                Object.entries(data.studies || {}).forEach(([uid, row]) => {
                    this.studyStatus.set(uid, row);
                });
            } catch (e) {
                console.warn('[QaQuickAction] refreshStudyStatuses:', e);
            }
        },

        async refreshInformeStatuses(informes) {
            if (!this.enabled || !Array.isArray(informes) || !informes.length) return;
            const ids = [...new Set(informes.map((i) => parseInt(i.id, 10)).filter((id) => id > 0))];
            if (!ids.length) return;
            try {
                const data = await this.apiPost('get-status-batch.php', { informe_ids: ids });
                this.installed = !!data.installed;
                this.qaEnabled = !!data.enabled;
                Object.entries(data.informes || {}).forEach(([id, row]) => {
                    this.informeStatus.set(parseInt(id, 10), row);
                });
            } catch (e) {
                console.warn('[QaQuickAction] refreshInformeStatuses:', e);
            }
        },

        notifyChange(detail) {
            document.dispatchEvent(new CustomEvent('qa-status-changed', { detail: detail || {} }));
        },

        ensureModal() {
            if (document.getElementById('qaQuickActionModal')) {
                return;
            }
            const html = `
<div class="modal fade" id="qaQuickActionModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="qaQuickActionTitle">QA Portal</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <p id="qaQuickActionDesc" class="text-muted small"></p>
        <div class="mb-3" id="qaQuickMotivoWrap">
          <label class="form-label">Motivo</label>
          <select class="form-select" id="qaQuickMotivo">
            ${MOTIVOS.map((m) => `<option value="${m}">${this.motivoLabel(m)}</option>`).join('')}
          </select>
        </div>
        <div class="mb-3" id="qaQuickDetalleWrap">
          <label class="form-label">Detalle (opcional)</label>
          <textarea class="form-control" id="qaQuickDetalle" rows="2"></textarea>
        </div>
        <div class="form-check d-none" id="qaQuickBajarPacsWrap">
          <input class="form-check-input" type="checkbox" id="qaQuickBajarPacs">
          <label class="form-check-label" for="qaQuickBajarPacs">También bajar del PACS (eliminar serie DOC)</label>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
        <button type="button" class="btn btn-warning" id="qaQuickConfirmBtn">Confirmar</button>
      </div>
    </div>
  </div>
</div>`;
            document.body.insertAdjacentHTML('beforeend', html);
        },

        openModal(options) {
            this.ensureModal();
            const modalEl = document.getElementById('qaQuickActionModal');
            const title = document.getElementById('qaQuickActionTitle');
            const desc = document.getElementById('qaQuickActionDesc');
            const motivoWrap = document.getElementById('qaQuickMotivoWrap');
            const bajarWrap = document.getElementById('qaQuickBajarPacsWrap');
            const confirmBtn = document.getElementById('qaQuickConfirmBtn');

            title.textContent = options.title || 'QA Portal';
            desc.textContent = options.description || '';
            motivoWrap.style.display = options.requireMotivo === false ? 'none' : '';
            bajarWrap.classList.toggle('d-none', !options.showBajarPacs);
            document.getElementById('qaQuickBajarPacs').checked = false;

            const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
            const handler = async () => {
                confirmBtn.removeEventListener('click', handler);
                confirmBtn.disabled = true;
                try {
                    await options.onConfirm({
                        motivo: document.getElementById('qaQuickMotivo').value,
                        motivo_detalle: document.getElementById('qaQuickDetalle').value,
                        bajar_de_pacs: document.getElementById('qaQuickBajarPacs').checked,
                    });
                    modal.hide();
                } catch (e) {
                    this.showToast(e.message || 'Error QA', 'error');
                } finally {
                    confirmBtn.disabled = false;
                }
            };
            confirmBtn.addEventListener('click', handler);
            modal.show();
        },

        renderPortalActionButton(estado, onclick, extraClass = 'btn-sm btn-action') {
            const isBlocked = estado === 'bloqueado';
            const isPending = estado === 'pendiente';

            if (isBlocked) {
                if (!this.qaEnabled) {
                    return `<button type="button" class="btn ${extraClass} btn-secondary qa-portal-btn" title="Bloqueado en QA — sigue visible en portal (QA inactivo). Clic para marcar como publicado."
                        onclick="${onclick}">
                        <i class="fas fa-globe-americas"></i>
                    </button>`;
                }
                return `<button type="button" class="btn ${extraClass} btn-danger qa-portal-btn" title="Oculto del portal — Clic para republicar"
                    onclick="${onclick}">
                    <i class="fas fa-user-slash"></i>
                </button>`;
            }
            if (isPending) {
                return `<button type="button" class="btn ${extraClass} btn-warning text-dark qa-portal-btn" title="Pendiente de publicación en portal — Clic para ocultar"
                    onclick="${onclick}">
                    <i class="fas fa-hourglass-half"></i>
                </button>`;
            }
            return `<button type="button" class="btn ${extraClass} btn-success qa-portal-btn" title="Visible en portal del paciente — Clic para ocultar"
                onclick="${onclick}">
                <i class="fas fa-globe-americas"></i>
            </button>`;
        },

        renderStudyBadge(study) {
            const estado = this.getStudyEstado(study);
            if (estado === 'bloqueado') {
                if (!this.qaEnabled) {
                    return '<span class="badge bg-secondary ms-1" title="Bloqueado en QA; el paciente sigue viendo el estudio mientras QA esté inactivo"><i class="fas fa-ban me-1"></i>QA BLOQUEADO <span class="opacity-75">·</span> <i class="fas fa-globe-americas me-1"></i>VISIBLE EN PORTAL</span>';
                }
                return '<span class="badge bg-danger ms-1" title="Oculto en portal del paciente"><i class="fas fa-eye-slash me-1"></i>PORTAL OCULTO</span>';
            }
            if (estado === 'pendiente') {
                if (!this.qaEnabled) {
                    return '<span class="badge bg-light text-muted border ms-1" title="Pendiente en QA; sin efecto en portal mientras QA esté inactivo">QA PENDIENTE</span>';
                }
                return '<span class="badge bg-warning text-dark ms-1" title="Pendiente de publicación en portal">QA PENDIENTE</span>';
            }
            return '';
        },

        renderInformeBadge(informe) {
            const estado = this.getInformeEstado(informe);
            if (estado === 'bloqueado') {
                if (!this.qaEnabled) {
                    return '<span class="badge bg-secondary ms-1" title="Bloqueado en QA; el paciente sigue viendo el informe mientras QA esté inactivo"><i class="fas fa-ban me-1"></i>QA BLOQUEADO <span class="opacity-75">·</span> <i class="fas fa-globe-americas me-1"></i>VISIBLE EN PORTAL</span>';
                }
                return '<span class="badge bg-danger ms-1" title="Informe oculto en portal"><i class="fas fa-eye-slash me-1"></i>PORTAL OCULTO</span>';
            }
            if (estado === 'pendiente') {
                if (!this.qaEnabled) {
                    return '<span class="badge bg-light text-muted border ms-1">QA PENDIENTE</span>';
                }
                return '<span class="badge bg-warning text-dark ms-1">QA PENDIENTE</span>';
            }
            return '';
        },

        renderStudyButton(study) {
            if (!this.enabled || !study || !this.studyUid(study)) {
                return '';
            }
            const sid = String(study.id || '').replace(/'/g, "\\'");
            const estado = this.getStudyEstado(study);
            const studyPayload = this.escapeStudyPayload(study);

            if (estado === 'bloqueado') {
                return this.renderPortalActionButton(
                    estado,
                    `QaQuickAction.republicarEstudio(derivacionesManager.studies.find(s => s.id === '${sid}') || ${studyPayload})`
                );
            }

            return this.renderPortalActionButton(
                estado,
                `QaQuickAction.despublicarEstudio(derivacionesManager.studies.find(s => s.id === '${sid}') || ${studyPayload})`
            );
        },

        renderInformeButton(informe) {
            if (!this.enabled || !informe || !informe.id) {
                return '';
            }
            const iid = informe.id;
            const titulo = String(informe.titulo || informe.study_description || '').replace(/'/g, "\\'");
            const estado = this.getInformeEstado(informe);

            if (estado === 'bloqueado') {
                return this.renderPortalActionButton(
                    estado,
                    `QaQuickAction.republicarInforme({id:${iid},titulo:'${titulo}'})`,
                    'btn-sm'
                );
            }

            return this.renderPortalActionButton(
                estado,
                `QaQuickAction.despublicarInforme({id:${iid},titulo:'${titulo}'})`,
                'btn-sm'
            );
        },

        escapeStudyPayload(study) {
            return `{id:'${String(study.id || '').replace(/'/g, "\\'")}',study_instance_uid:'${String(study.study_instance_uid || '').replace(/'/g, "\\'")}',patient_id:'${String(study.patient_id || '').replace(/'/g, "\\'")}',patient_name:'${String(study.patient_name || '').replace(/'/g, "\\'")}',study_description:'${String(study.study_description || '').replace(/'/g, "\\'")}',accession_number:'${String(study.accession_number || '').replace(/'/g, "\\'")}',orthanc_id:'${String(study.orthanc_id || study.id || '').replace(/'/g, "\\'")}',modality:'${String(study.modality || '').replace(/'/g, "\\'")}',study_date:'${String(study.study_date || study.date || '').replace(/'/g, "\\'")}'}`;
        },

        studyLogPayload(study) {
            return {
                patient_name: study?.patient_name || '',
                patient_id: study?.patient_id || '',
                modality: study?.modality || '',
                study_date: study?.study_date || study?.date || '',
                study_description: study?.study_description || '',
            };
        },

        portalEffectiveFromResponse(data) {
            return data?.portal_effective !== false && data?.qa_enabled !== false;
        },

        toastAfterBlock(data, entityLabel) {
            if (this.portalEffectiveFromResponse(data)) {
                this.showToast(`${entityLabel} despublicado del portal del paciente.`, 'success');
                return;
            }
            this.showToast(
                `${entityLabel} marcado como bloqueado en QA. Sigue visible en portal hasta activar «QA habilitado».`,
                'warning'
            );
        },

        toastAfterPublish(data, entityLabel) {
            if (this.portalEffectiveFromResponse(data)) {
                this.showToast(`${entityLabel} republicado en el portal del paciente.`, 'success');
                return;
            }
            this.showToast(`${entityLabel} marcado como publicado en QA.`, 'success');
        },

        despublicarEstudio(study) {
            if (!this.enabled) return;
            const uid = this.studyUid(study);
            if (!uid) {
                this.showToast('El estudio no tiene Study Instance UID; no se puede despublicar.', 'warning');
                return;
            }
            this.openModal({
                title: 'Despublicar imágenes del portal',
                description: `Paciente: ${study.patient_name || ''} — Estudio: ${study.study_description || study.id}`,
                showBajarPacs: false,
                onConfirm: async ({ motivo, motivo_detalle }) => {
                    const data = await this.apiPost('set-study-status.php', {
                        orthanc_id: study.orthanc_id || study.id,
                        study_instance_uid: uid,
                        patient_id_pacs: study.patient_id,
                        accession_number: study.accession_number,
                        estado: 'bloqueado',
                        motivo,
                        motivo_detalle,
                        ...this.studyLogPayload(study),
                    });
                    this.studyStatus.set(uid, { estado: 'bloqueado', motivo, ...(data.status || {}) });
                    if (typeof data.qa_enabled === 'boolean') {
                        this.qaEnabled = data.qa_enabled;
                    }
                    this.toastAfterBlock(data, 'Estudio');
                    this.notifyChange({ type: 'study', study_instance_uid: uid, estado: 'bloqueado' });
                },
            });
        },

        republicarEstudio(study) {
            if (!this.enabled) return;
            const uid = this.studyUid(study);
            if (!uid) return;
            this.openModal({
                title: 'Republicar imágenes en portal',
                description: study.study_description || study.id,
                requireMotivo: false,
                showBajarPacs: false,
                onConfirm: async () => {
                    const data = await this.apiPost('set-study-status.php', {
                        orthanc_id: study.orthanc_id || study.id,
                        study_instance_uid: uid,
                        patient_id_pacs: study.patient_id,
                        accession_number: study.accession_number,
                        estado: 'publicado',
                        ...this.studyLogPayload(study),
                    });
                    this.studyStatus.set(uid, { estado: 'publicado', ...(data.status || {}) });
                    if (typeof data.qa_enabled === 'boolean') {
                        this.qaEnabled = data.qa_enabled;
                    }
                    this.toastAfterPublish(data, 'Estudio');
                    this.notifyChange({ type: 'study', study_instance_uid: uid, estado: 'publicado' });
                },
            });
        },

        despublicarInforme(informe) {
            if (!this.enabled) return;
            this.openModal({
                title: 'Despublicar informe del portal',
                description: informe.titulo || `Informe #${informe.id}`,
                showBajarPacs: this.canBajarPacs,
                onConfirm: async ({ motivo, motivo_detalle, bajar_de_pacs }) => {
                    const data = await this.apiPost('set-informe-status.php', {
                        informe_id: informe.id,
                        estado: 'bloqueado',
                        motivo,
                        motivo_detalle,
                        bajar_de_pacs: !!bajar_de_pacs,
                    });
                    this.informeStatus.set(informe.id, { estado: 'bloqueado', motivo, bajado_de_pacs: !!bajar_de_pacs });
                    if (typeof data.qa_enabled === 'boolean') {
                        this.qaEnabled = data.qa_enabled;
                    }
                    if (bajar_de_pacs) {
                        this.showToast('Informe bloqueado y bajado del PACS.', 'success');
                    } else {
                        this.toastAfterBlock(data, 'Informe');
                    }
                    this.notifyChange({ type: 'informe', informe_id: informe.id, estado: 'bloqueado' });
                    if (global.InformesManager && typeof global.InformesManager.loadReports === 'function') {
                        global.InformesManager.loadReports(global.InformesManager.state?.currentPage || 1);
                    }
                },
            });
        },

        republicarInforme(informe) {
            if (!this.enabled) return;
            this.openModal({
                title: 'Republicar informe en portal',
                description: informe.titulo || `Informe #${informe.id}`,
                requireMotivo: false,
                showBajarPacs: false,
                onConfirm: async () => {
                    const data = await this.apiPost('set-informe-status.php', {
                        informe_id: informe.id,
                        estado: 'publicado',
                    });
                    this.informeStatus.set(informe.id, { estado: 'publicado' });
                    if (typeof data.qa_enabled === 'boolean') {
                        this.qaEnabled = data.qa_enabled;
                    }
                    this.toastAfterPublish(data, 'Informe');
                    this.notifyChange({ type: 'informe', informe_id: informe.id, estado: 'publicado' });
                    if (global.InformesManager && typeof global.InformesManager.loadReports === 'function') {
                        global.InformesManager.loadReports(global.InformesManager.state?.currentPage || 1);
                    }
                },
            });
        },
    };

    global.QaQuickAction = QaQuickAction;
    document.addEventListener('DOMContentLoaded', () => QaQuickAction.init());
})(window);
