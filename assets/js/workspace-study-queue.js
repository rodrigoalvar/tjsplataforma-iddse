/**
 * Cola de lectura compartida entre dashboard-unified y workspace.
 * Persistencia en localStorage + sync vía BroadcastChannel (multimonitor).
 */
(function(global) {
    'use strict';

    const STORAGE_KEY = 'workspace_study_queue';
    const AUTO_ADVANCE_KEY = 'workspace_auto_advance_queue';
    const MM_CHANNEL = 'mm-workspace-state';

    let _channel = null;
    if (typeof BroadcastChannel !== 'undefined') {
        try { _channel = new BroadcastChannel(MM_CHANNEL); } catch (_) {}
    }

    function getStudyKey(study) {
        if (!study) return '';
        return String(
            study.id ||
            study.orthanc_study_id ||
            study.orthancStudyId ||
            study.study_instance_uid ||
            study.studyInstanceUID ||
            study.studyId ||
            ''
        );
    }

    function findIndexByKey(items, key) {
        if (!key || !Array.isArray(items)) return -1;
        return items.findIndex(function(item) {
            return getStudyKey(item) === key;
        });
    }

    function serializeStudy(study) {
        return {
            id: study.id || '',
            study_instance_uid: study.study_instance_uid || study.studyInstanceUID || '',
            orthanc_study_id: study.orthanc_study_id || study.orthancStudyId || study.id || '',
            patient_id: study.patient_id || study.patientId || '',
            patient_name: study.patient_name || study.patientName || '',
            modality: study.modality || '',
            study_description: study.study_description || study.studyDescription || ''
        };
    }

    function load() {
        try {
            const raw = localStorage.getItem(STORAGE_KEY);
            if (!raw) return null;
            const state = JSON.parse(raw);
            if (!state || state.version !== 1) return null;
            return state;
        } catch (_) {
            return null;
        }
    }

    function save(state) {
        try {
            localStorage.setItem(STORAGE_KEY, JSON.stringify(state));
            notifyUpdate();
            return true;
        } catch (_) {
            return false;
        }
    }

    function notifyUpdate() {
        try {
            if (_channel) {
                _channel.postMessage({ type: 'mm-queue-updated', ts: Date.now() });
            }
            window.dispatchEvent(new CustomEvent('studyQueueUpdated'));
        } catch (_) {}
    }

    function clear() {
        try {
            localStorage.removeItem(STORAGE_KEY);
            notifyUpdate();
        } catch (_) {}
    }

    function build(sortedEligibleStudies, currentStudy, userId, sortSnapshot) {
        const items = (sortedEligibleStudies || []).map(serializeStudy);
        const state = {
            version: 1,
            userId: userId || '',
            createdAt: Date.now(),
            active: items.length > 0,
            sortSnapshot: sortSnapshot || {},
            currentStudyKey: getStudyKey(currentStudy),
            completedKeys: [],
            items: items
        };
        save(state);
        return state;
    }

    function isActive() {
        const state = load();
        return !!(state && state.active && state.items && state.items.length > 0);
    }

    function setCurrentStudyKey(key) {
        const state = load();
        if (!state) return false;
        state.currentStudyKey = key || '';
        return save(state);
    }

    function setCurrentStudyKeyFromUrl(url) {
        if (!url) return false;
        try {
            const u = new URL(url, window.location.href);
            const p = u.searchParams;
            const key = p.get('studyId') || p.get('orthancStudyId') || p.get('studyInstanceUID') || '';
            if (key) return setCurrentStudyKey(key);
        } catch (_) {}
        return false;
    }

    function markCompleted(studyKey) {
        const state = load();
        if (!state) return false;
        const key = studyKey || state.currentStudyKey;
        if (!key) return false;
        if (!Array.isArray(state.completedKeys)) state.completedKeys = [];
        if (!state.completedKeys.includes(key)) {
            state.completedKeys.push(key);
        }
        return save(state);
    }

    function isStudyCompleted(studyKey) {
        const state = load();
        if (!state || !Array.isArray(state.completedKeys)) return false;
        return state.completedKeys.includes(studyKey || state.currentStudyKey);
    }

    function getPosition() {
        const state = load();
        if (!state || !state.active || !state.items.length) return null;
        const idx = findIndexByKey(state.items, state.currentStudyKey);
        return {
            current: idx >= 0 ? idx + 1 : 0,
            total: state.items.length,
            index: idx
        };
    }

    function getNext(afterKey) {
        const state = load();
        if (!state || !state.active || !state.items.length) return null;

        const key = afterKey || state.currentStudyKey;
        const idx = findIndexByKey(state.items, key);
        const startIdx = idx >= 0 ? idx + 1 : 0;
        const completed = state.completedKeys || [];

        for (let i = startIdx; i < state.items.length; i++) {
            const item = state.items[i];
            const itemKey = getStudyKey(item);
            if (completed.includes(itemKey)) continue;
            return item;
        }
        return null;
    }

    function hasNext(afterKey) {
        return getNext(afterKey) !== null;
    }

    function getNextPreview(afterKey) {
        const next = getNext(afterKey);
        if (!next) return null;
        return {
            patient_name: next.patient_name || '',
            patient_id: next.patient_id || '',
            modality: next.modality || ''
        };
    }

    function buildWorkspaceUrl(item, baseHref) {
        if (!item) return '';
        const params = new URLSearchParams();
        params.set('studyId', item.id || '');
        if (item.study_instance_uid) params.set('studyInstanceUID', item.study_instance_uid);
        if (item.orthanc_study_id) params.set('orthancStudyId', item.orthanc_study_id);
        if (item.patient_id) params.set('patientId', item.patient_id);
        if (item.patient_name) params.set('patientName', item.patient_name);
        if (item.modality) params.set('modality', item.modality);
        if (item.study_description) params.set('studyDescription', item.study_description);
        const base = baseHref || (window.location.pathname.includes('/components/')
            ? 'workspace.html'
            : 'components/workspace.html');
        return new URL(base + '?' + params.toString(), window.location.href).href;
    }

    function toStudyData(item) {
        const serialized = serializeStudy(item);
        return {
            studyId: serialized.id || null,
            studyInstanceUID: serialized.study_instance_uid || null,
            orthancStudyId: serialized.orthanc_study_id || null,
            patientId: serialized.patient_id || null,
            patientName: serialized.patient_name || null,
            modality: serialized.modality || null,
            studyDescription: serialized.study_description || null
        };
    }

    function isAutoAdvanceEnabled() {
        try {
            return localStorage.getItem(AUTO_ADVANCE_KEY) === 'true';
        } catch (_) {
            return false;
        }
    }

    function setAutoAdvanceEnabled(enabled) {
        try {
            localStorage.setItem(AUTO_ADVANCE_KEY, enabled ? 'true' : 'false');
            return true;
        } catch (_) {
            return false;
        }
    }

    function getQueueBadgeForStudy(study) {
        const state = load();
        if (!state || !state.active) return null;
        const key = getStudyKey(study);
        if (!key || key !== state.currentStudyKey) return null;
        const pos = getPosition();
        if (!pos || !pos.total) return null;
        return pos.current + ' / ' + pos.total;
    }

    function onUpdate(callback) {
        if (typeof callback !== 'function') return function() {};
        const handler = function() { callback(); };
        window.addEventListener('studyQueueUpdated', handler);
        window.addEventListener('storage', function(ev) {
            if (ev.key === STORAGE_KEY) handler();
        });
        if (_channel) {
            _channel.addEventListener('message', function(ev) {
                if (ev.data && ev.data.type === 'mm-queue-updated') handler();
            });
        }
        return function() {
            window.removeEventListener('studyQueueUpdated', handler);
        };
    }

    const StudyQueue = {
        STORAGE_KEY: STORAGE_KEY,
        AUTO_ADVANCE_KEY: AUTO_ADVANCE_KEY,
        getStudyKey: getStudyKey,
        serializeStudy: serializeStudy,
        load: load,
        save: save,
        clear: clear,
        build: build,
        isActive: isActive,
        setCurrentStudyKey: setCurrentStudyKey,
        setCurrentStudyKeyFromUrl: setCurrentStudyKeyFromUrl,
        markCompleted: markCompleted,
        isStudyCompleted: isStudyCompleted,
        getPosition: getPosition,
        getNext: getNext,
        hasNext: hasNext,
        getNextPreview: getNextPreview,
        buildWorkspaceUrl: buildWorkspaceUrl,
        toStudyData: toStudyData,
        isAutoAdvanceEnabled: isAutoAdvanceEnabled,
        setAutoAdvanceEnabled: setAutoAdvanceEnabled,
        getQueueBadgeForStudy: getQueueBadgeForStudy,
        onUpdate: onUpdate,
        notifyUpdate: notifyUpdate
    };

    global.StudyQueue = StudyQueue;
})(typeof window !== 'undefined' ? window : this);
