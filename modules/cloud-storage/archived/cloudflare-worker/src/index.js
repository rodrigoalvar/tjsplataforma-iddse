/**
 * Cloudflare Worker para extraer ZIPs de R2
 * 
 * Este Worker recibe una solicitud HTTP POST con información del ZIP a extraer,
 * lo descomprime directamente en R2 y notifica al backend PHP cuando termina.
 */

export default {
  async fetch(request, env) {
    // Solo aceptar POST
    if (request.method !== 'POST') {
      return new Response(JSON.stringify({
        success: false,
        error: 'Method not allowed. Use POST.'
      }), {
        status: 405,
        headers: { 'Content-Type': 'application/json' }
      });
    }
    
    try {
      // Validar autenticación
      const authHeader = request.headers.get('Authorization');
      if (!authHeader || !authHeader.startsWith('Bearer ')) {
        return new Response(JSON.stringify({
          success: false,
          error: 'Unauthorized. Missing or invalid Authorization header.'
        }), {
          status: 401,
          headers: { 'Content-Type': 'application/json' }
        });
      }
      
      const token = authHeader.substring(7);
      const expectedToken = env.AUTH_TOKEN || '';
      
      if (!expectedToken || token !== expectedToken) {
        return new Response(JSON.stringify({
          success: false,
          error: 'Invalid authentication token'
        }), {
          status: 401,
          headers: { 'Content-Type': 'application/json' }
        });
      }
      
      // Parsear payload
      const payload = await request.json();
      const { bucket, zipKey, studyInstanceUID, queueId, callbackUrl } = payload;
      
      if (!zipKey || !studyInstanceUID) {
        return new Response(JSON.stringify({
          success: false,
          error: 'Missing required fields: zipKey and studyInstanceUID are required'
        }), {
          status: 400,
          headers: { 'Content-Type': 'application/json' }
        });
      }
      
      console.log(`[EXTRACT] Iniciando extracción: ${zipKey}, StudyUID: ${studyInstanceUID}`);
      
      // Iniciar extracción en segundo plano
      // Nota: Para ZIPs grandes, esto podría exceder el tiempo límite del Worker
      // En ese caso, considerar usar Durable Objects o procesamiento por partes
      extractZipFromR2(env.R2_BUCKET, zipKey, studyInstanceUID, callbackUrl, queueId, env.AUTH_TOKEN)
        .then(() => {
          console.log(`[EXTRACT] Extracción completada exitosamente: ${zipKey}`);
        })
        .catch(err => {
          console.error(`[EXTRACT] Error en extracción: ${err.message}`, err);
          // Notificar error al callback si está disponible
          if (callbackUrl) {
            notifyCallback(callbackUrl, env.AUTH_TOKEN, {
              queueId,
              studyInstanceUID,
              zipKey,
              status: 'error',
              error: err.message
            }).catch(callbackErr => {
              console.error(`[EXTRACT] Error notificando callback: ${callbackErr.message}`);
            });
          }
        });
      
      // Responder inmediatamente (202 Accepted) - procesamiento asíncrono
      return new Response(JSON.stringify({
        success: true,
        message: 'Extraction started',
        zipKey,
        studyInstanceUID
      }), {
        status: 202,
        headers: { 'Content-Type': 'application/json' }
      });
      
    } catch (error) {
      console.error('[EXTRACT] Error procesando request:', error);
      return new Response(JSON.stringify({
        success: false,
        error: error.message || 'Internal server error'
      }), {
        status: 500,
        headers: { 'Content-Type': 'application/json' }
      });
    }
  }
};

/**
 * Extrae ZIP de R2 y escribe archivos individuales
 * @param {R2Bucket} r2Bucket - Binding del bucket R2
 * @param {string} zipKey - Key del ZIP en R2
 * @param {string} studyInstanceUID - Study Instance UID
 * @param {string} callbackUrl - URL del callback PHP
 * @param {number} queueId - ID de la cola
 * @param {string} authToken - Token de autenticación para callback
 */
async function extractZipFromR2(r2Bucket, zipKey, studyInstanceUID, callbackUrl, queueId, authToken) {
  console.log(`[EXTRACT] Iniciando extracción: ${zipKey}`);
  
  // 1. Obtener ZIP de R2
  const zipObject = await r2Bucket.get(zipKey);
  if (!zipObject) {
    throw new Error(`ZIP no encontrado en R2: ${zipKey}`);
  }
  
  console.log(`[EXTRACT] ZIP encontrado, tamaño: ${zipObject.size} bytes`);
  
  // 2. Leer ZIP como ArrayBuffer
  const zipData = await zipObject.arrayBuffer();
  console.log(`[EXTRACT] ZIP leído en memoria: ${zipData.byteLength} bytes`);
  
  // 3. Descomprimir usando fflate (librería ZIP pura JavaScript)
  // Usar CDN para evitar problemas de bundling
  const fflateUrl = 'https://cdn.jsdelivr.net/npm/fflate@0.7.4/lib/browser.min.js';
  
  // Importar fflate dinámicamente
  // Nota: En Workers, podemos usar import() o cargar desde CDN
  // Para simplificar, usaremos una implementación básica o fflate
  
  try {
    // Intentar usar fflate desde CDN (si está disponible en Workers)
    // Alternativa: usar una implementación más simple o procesar por partes
    const { unzipSync } = await import('https://cdn.jsdelivr.net/npm/fflate@0.7.4/+esm');
    
    console.log(`[EXTRACT] Descomprimiendo ZIP...`);
    const files = unzipSync(new Uint8Array(zipData));
    
    console.log(`[EXTRACT] ZIP descomprimido, ${Object.keys(files).length} archivos encontrados`);
    
    // 4. Escribir cada archivo a R2
    let filesExtracted = 0;
    let filesSkipped = 0;
    
    for (const [fileName, fileData] of Object.entries(files)) {
      // Solo procesar archivos DICOM y mantener estructura
      // El manifest.json ya está en el ZIP y se mantendrá
      if (fileName.endsWith('.dcm') || fileName.includes('/series/') || fileName.endsWith('manifest.json')) {
        try {
          await r2Bucket.put(fileName, fileData, {
            httpMetadata: {
              contentType: fileName.endsWith('.dcm') ? 'application/dicom' : 
                          fileName.endsWith('.json') ? 'application/json' : 
                          'application/octet-stream'
            }
          });
          filesExtracted++;
          
          // Log cada 10 archivos para no saturar logs
          if (filesExtracted % 10 === 0) {
            console.log(`[EXTRACT] Progreso: ${filesExtracted} archivos extraídos...`);
          }
        } catch (putError) {
          console.error(`[EXTRACT] Error escribiendo archivo ${fileName}:`, putError);
          filesSkipped++;
        }
      } else {
        filesSkipped++;
      }
    }
    
    console.log(`[EXTRACT] Extracción completada: ${filesExtracted} archivos extraídos, ${filesSkipped} omitidos`);
    
    // 5. Eliminar ZIP original solo si la extracción fue exitosa
    if (filesExtracted > 0) {
      await r2Bucket.delete(zipKey);
      console.log(`[EXTRACT] ZIP eliminado: ${zipKey}`);
    } else {
      throw new Error('No se extrajeron archivos del ZIP');
    }
    
    // 6. Notificar callback al backend PHP
    if (callbackUrl) {
      await notifyCallback(callbackUrl, authToken, {
        queueId,
        studyInstanceUID,
        zipKey,
        status: 'ok',
        filesExtracted,
        filesSkipped
      });
    }
    
  } catch (importError) {
    // Si falla la importación de fflate, usar alternativa
    console.error('[EXTRACT] Error importando fflate, intentando alternativa:', importError);
    
    // Alternativa: usar una implementación más básica o procesar manualmente
    // Por ahora, lanzar error para que se maneje arriba
    throw new Error(`Error descomprimiendo ZIP: ${importError.message}`);
  }
}

/**
 * Notifica al backend PHP que la extracción terminó
 * @param {string} callbackUrl - URL del callback
 * @param {string} authToken - Token de autenticación
 * @param {object} data - Datos a enviar
 */
async function notifyCallback(callbackUrl, authToken, data) {
  try {
    console.log(`[EXTRACT] Notificando callback: ${callbackUrl}`);
    
    const response = await fetch(callbackUrl, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'Authorization': `Bearer ${authToken}`
      },
      body: JSON.stringify(data)
    });
    
    if (!response.ok) {
      const errorText = await response.text();
      throw new Error(`Callback failed: HTTP ${response.status} - ${errorText}`);
    }
    
    const result = await response.json();
    console.log('[EXTRACT] Callback notificado exitosamente:', result);
    return result;
    
  } catch (error) {
    console.error('[EXTRACT] Error notificando callback:', error);
    throw error;
  }
}
