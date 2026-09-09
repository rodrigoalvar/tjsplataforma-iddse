-- =====================================================
-- Orthanc Lua: encolado automático → Cloud Storage R2
-- OnStableStudy = estudio estable/completo
-- =====================================================
-- Configurar URL y token (pestaña "Encolado automático" del portal).
-- Orthanc.json: "LuaScripts": ["/ruta/completa/notify-r2.lua"]
--
-- HTTPS / certificados: si en el log aparece "SSL CA cert" / libCURL:
--   • Actualizá CA en el servidor Orthanc: apt install ca-certificates && update-ca-certificates
--   • Docker: imagen con bundle actualizado o montar /etc/ssl/certs
--   • O usá http:// hacia IP/nombre interno del portal (misma LAN) si es aceptable
--
-- Logging: se usa print() (compatible con todas las versiones). LogWarning/LogInfo
-- no existen como globales en muchos builds de Orthanc.
-- =====================================================

local R2_AUTO_ENQUEUE_URL   = 'https://plataforma.iddse.com.ar/modules/cloud-storage/api/auto-enqueue.php'
local R2_AUTO_ENQUEUE_TOKEN = 'CAMBIAR_POR_TOKEN_SEGURO'

function OnStableStudy(studyId, tags, metadata, origin)
    if R2_AUTO_ENQUEUE_URL == '' or R2_AUTO_ENQUEUE_TOKEN == '' or R2_AUTO_ENQUEUE_TOKEN == 'CAMBIAR_POR_TOKEN_SEGURO' then
        print('[R2 auto-enqueue] Falta URL o token')
        return
    end

    if origin and origin['RequestOrigin'] == 'Lua' then
        return
    end

    SetHttpTimeout(20)

    local modalities = ''
    if tags then
        modalities = tags['ModalitiesInStudy'] or tags['Modality'] or ''
    end

    local payload = {
        orthanc_study_id   = studyId,
        study_instance_uid = (tags and tags['StudyInstanceUID']) or '',
        modality           = modalities,
        patient_id         = (tags and tags['PatientID']) or '',
        remote_aet         = (origin and origin['RemoteAet']) or 'unknown',
    }

    local headers = {
        ['Content-Type']  = 'application/json',
        ['Authorization'] = 'Bearer ' .. R2_AUTO_ENQUEUE_TOKEN,
    }

    local body = DumpJson(payload)
    local ok, success, status = pcall(function()
        return HttpPost(R2_AUTO_ENQUEUE_URL, body, headers)
    end)

    if not ok then
        print('[R2 auto-enqueue] HttpPost excepción (¿SSL/certificado?): ' .. tostring(success))
        return
    end

    if not success then
        print('[R2 auto-enqueue] HTTP falló study=' .. tostring(studyId) .. ' status=' .. tostring(status))
    else
        print('[R2 auto-enqueue] OK study=' .. tostring(studyId) .. ' modality=' .. tostring(payload.modality))
    end
end
