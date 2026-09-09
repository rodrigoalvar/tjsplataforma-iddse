/**
 * AudioPendingQueue — Cola de audios pendientes con IndexedDB
 *
 * Persiste blobs de audio en IndexedDB para sobrevivir recargas de página
 * cuando la subida al servidor falla (sesión expirada, error de red, etc.).
 *
 * Flujo:
 *   1. Al detener grabación → enqueue() guarda el blob en IndexedDB
 *   2. Si la subida al servidor tiene éxito → remove() limpia la entrada
 *   3. Al recuperar sesión → retryAll() reintenta las subidas pendientes
 *   4. Polling de sesión → si expira, el modal lista getPending() para descarga manual
 */
(function (global) {
    'use strict';

    const DB_NAME    = 'tjsmedical_audio_pending';
    const DB_VERSION = 1;
    const STORE_NAME = 'pending_audios';

    // ─── Apertura de la base de datos ──────────────────────────────────────────

    function openDB() {
        return new Promise((resolve, reject) => {
            if (!global.indexedDB) {
                reject(new Error('IndexedDB no disponible en este navegador'));
                return;
            }
            const req = global.indexedDB.open(DB_NAME, DB_VERSION);

            req.onupgradeneeded = (e) => {
                const db = e.target.result;
                if (!db.objectStoreNames.contains(STORE_NAME)) {
                    const store = db.createObjectStore(STORE_NAME, { keyPath: 'queueId' });
                    store.createIndex('userId',  'userId',  { unique: false });
                    store.createIndex('savedAt', 'savedAt', { unique: false });
                }
            };

            req.onsuccess  = (e) => resolve(e.target.result);
            req.onerror    = (e) => reject(e.target.error);
        });
    }

    // ─── API pública ───────────────────────────────────────────────────────────

    const AudioPendingQueue = {

        /**
         * Encolar un audio pendiente.
         * @param {Object} opts
         * @param {Blob}   opts.blob         - El blob de audio
         * @param {string} opts.recordingId  - ID temporal del workspace
         * @param {string} opts.studyId
         * @param {string} opts.patientId
         * @param {string} opts.panelId
         * @param {number} opts.userId
         * @param {string} opts.mimeType     - e.g. 'audio/webm'
         * @returns {Promise<string>}         - queueId generado
         */
        async enqueue(opts) {
            const queueId = 'apq_' + Date.now() + '_' + Math.random().toString(36).substr(2, 6);
            const entry = {
                queueId,
                blob:        opts.blob,
                recordingId: String(opts.recordingId || ''),
                studyId:     opts.studyId     || null,
                patientId:   opts.patientId   || null,
                panelId:     opts.panelId     || null,
                userId:      opts.userId      || null,
                mimeType:    opts.mimeType     || 'audio/webm',
                savedAt:     Date.now(),
                attempts:    0
            };

            try {
                const db    = await openDB();
                const tx    = db.transaction(STORE_NAME, 'readwrite');
                const store = tx.objectStore(STORE_NAME);
                await new Promise((res, rej) => {
                    const r = store.add(entry);
                    r.onsuccess = () => res(r.result);
                    r.onerror   = () => rej(r.error);
                });
                db.close();
                console.log('📦 AudioPendingQueue: audio encolado —', queueId);
                return queueId;
            } catch (err) {
                console.error('AudioPendingQueue.enqueue error:', err);
                return null;
            }
        },

        /**
         * Eliminar una entrada de la cola (subida exitosa).
         * @param {string} queueId
         */
        async remove(queueId) {
            if (!queueId) return;
            try {
                const db    = await openDB();
                const tx    = db.transaction(STORE_NAME, 'readwrite');
                const store = tx.objectStore(STORE_NAME);
                await new Promise((res, rej) => {
                    const r = store.delete(queueId);
                    r.onsuccess = () => res();
                    r.onerror   = () => rej(r.error);
                });
                db.close();
                console.log('🗑️ AudioPendingQueue: entrada eliminada —', queueId);
            } catch (err) {
                console.error('AudioPendingQueue.remove error:', err);
            }
        },

        /**
         * Obtener todos los audios pendientes (opcionalmente filtrar por userId).
         * @param {number|null} userId
         * @returns {Promise<Array>}
         */
        async getPending(userId = null) {
            try {
                const db    = await openDB();
                const tx    = db.transaction(STORE_NAME, 'readonly');
                const store = tx.objectStore(STORE_NAME);
                const all   = await new Promise((res, rej) => {
                    const r = store.getAll();
                    r.onsuccess = () => res(r.result);
                    r.onerror   = () => rej(r.error);
                });
                db.close();
                if (userId !== null) {
                    return all.filter(e => e.userId === userId);
                }
                return all;
            } catch (err) {
                console.error('AudioPendingQueue.getPending error:', err);
                return [];
            }
        },

        /**
         * Incrementar el contador de intentos de una entrada.
         * @param {string} queueId
         */
        async incrementAttempts(queueId) {
            try {
                const db    = await openDB();
                const tx    = db.transaction(STORE_NAME, 'readwrite');
                const store = tx.objectStore(STORE_NAME);
                const entry = await new Promise((res, rej) => {
                    const r = store.get(queueId);
                    r.onsuccess = () => res(r.result);
                    r.onerror   = () => rej(r.error);
                });
                if (entry) {
                    entry.attempts = (entry.attempts || 0) + 1;
                    await new Promise((res, rej) => {
                        const r = store.put(entry);
                        r.onsuccess = () => res();
                        r.onerror   = () => rej(r.error);
                    });
                }
                db.close();
            } catch (err) {
                console.error('AudioPendingQueue.incrementAttempts error:', err);
            }
        },

        /**
         * Eliminar entradas antiguas (más de N días) para evitar acumulación.
         * @param {number} days - Por defecto 7 días
         */
        async purgeOld(days = 7) {
            const cutoff = Date.now() - days * 24 * 60 * 60 * 1000;
            try {
                const db      = await openDB();
                const tx      = db.transaction(STORE_NAME, 'readwrite');
                const store   = tx.objectStore(STORE_NAME);
                const all     = await new Promise((res, rej) => {
                    const r = store.getAll();
                    r.onsuccess = () => res(r.result);
                    r.onerror   = () => rej(r.error);
                });
                let removed = 0;
                for (const entry of all) {
                    if (entry.savedAt < cutoff) {
                        store.delete(entry.queueId);
                        removed++;
                    }
                }
                db.close();
                if (removed > 0) {
                    console.log(`🧹 AudioPendingQueue: ${removed} entrada(s) antigua(s) eliminada(s)`);
                }
            } catch (err) {
                console.error('AudioPendingQueue.purgeOld error:', err);
            }
        },

        /**
         * ¿Hay audios pendientes para este usuario?
         * @param {number|null} userId
         * @returns {Promise<boolean>}
         */
        async hasPending(userId = null) {
            const pending = await this.getPending(userId);
            return pending.length > 0;
        }
    };

    // Exponer globalmente
    global.AudioPendingQueue = AudioPendingQueue;

    // Purgar entradas antiguas al cargar (en segundo plano, sin bloquear)
    setTimeout(() => AudioPendingQueue.purgeOld(7), 2000);

})(window);
