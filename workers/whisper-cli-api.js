// whisper-cli-api.js - API Node.js completa para whisper-cli (RTX 5060 Ti)
// Deploy: npm init -y && npm i express multer cors form-data node-fetch pm2 && pm2 start whisper-cli-api.js -i 4

const express = require('express');
const multer = require('multer');
const cors = require('cors');
const { spawn, exec } = require('child_process');
const { promisify } = require('util');
const fs = require('fs').promises;
const path = require('path');
const os = require('os');
const FormData = require('form-data');
const fetch = require('node-fetch');

const execAsync = promisify(exec);

const app = express();
app.use(cors());
app.use(express.json());

// Config RTX optimizada
const CONFIG = {
  WHISPER_CLI: '/home/iddse/Documentos/TJS/whisper.cpp/build/bin/whisper-cli',
  MODELS_DIR: '/home/iddse/Documentos/TJS/whisper.cpp/models',
  UPLOAD_DIR: '/tmp/whisper-uploads',
  DEFAULT_MODEL: 'ggml-medium.bin',
  DEFAULT_THREADS: 8,
  DEFAULT_DEV: 0,   // Dispositivo GPU (0 = primera GPU)
  DEFAULT_ML: 10,   // Max length
  TIMEOUT: 60000,   // 60s max
  MAX_SIZE: 100 * 1024 * 1024,  // 100MB
  FFMPEG_REST_URL: process.env.FFMPEG_REST_URL || null  // URL de ffmpeg-rest (opcional, puede venir del request)
};

// Crear directorio de uploads si no existe
(async () => {
  try {
    await fs.mkdir(CONFIG.UPLOAD_DIR, { recursive: true });
  } catch (e) {
    console.error('Error creando directorio de uploads:', e);
  }
})();

// Cleanup uploads dir
const upload = multer({
  dest: CONFIG.UPLOAD_DIR,
  limits: { fileSize: CONFIG.MAX_SIZE },
  fileFilter: (req, file, cb) => {
    if (/\.(mp3|wav|flac|m4a|ogg)$/i.test(file.originalname)) cb(null, true);
    else cb(new Error('Solo MP3/WAV/FLAC/M4A/OGG'), false);
  }
});

// Health check
app.get('/health', (req, res) => {
  res.json({
    status: 'ok',
    uptime: process.uptime(),
    models: [],
    memory: process.memoryUsage()
  });
});

/**
 * Procesar salida de whisper-cli y extraer segmentos y texto
 * Algoritmo general basado en reglas para corregir palabras cortadas entre líneas
 */
function parseWhisperOutput(stdout) {
  const segments = [];
  const timings = {};
  
  const lines = stdout.split('\n');
  
  // Patrón para segmentos: [00:00:00.000 --> 00:00:01.920]   texto
  // IMPORTANTE: NO se usan \s* al final para preservar los espacios del texto
  // whisper-cli usa 3 espacios para nuevo segmento y 2 para continuación de palabra
  const segmentPattern = /^\[(\d{2}):(\d{2}):(\d{2})\.(\d{3})\s*-->\s*(\d{2}):(\d{2}):(\d{2})\.(\d{3})\](.+)$/;
  
  // Patrones para timings
  const timingPatterns = {
    load_time: /whisper_print_timings:\s+load time\s*=\s*([\d.]+)\s*ms/,
    mel_time: /whisper_print_timings:\s+mel time\s*=\s*([\d.]+)\s*ms/,
    sample_time: /whisper_print_timings:\s+sample time\s*=\s*([\d.]+)\s*ms/,
    encode_time: /whisper_print_timings:\s+encode time\s*=\s*([\d.]+)\s*ms/,
    decode_time: /whisper_print_timings:\s+decode time\s*=\s*([\d.]+)\s*ms/,
    batchd_time: /whisper_print_timings:\s+batchd time\s*=\s*([\d.]+)\s*ms/,
    prompt_time: /whisper_print_timings:\s+prompt time\s*=\s*([\d.]+)\s*ms/,
    total_time: /whisper_print_timings:\s+total time\s*=\s*([\d.]+)\s*ms/
  };
  
  // Extraer segmentos y texto de cada línea (sin timestamps)
  const textParts = [];
  
  for (const line of lines) {
    // Extraer segmentos con timestamps
    const segmentMatch = line.match(segmentPattern);
    if (segmentMatch) {
      const [, h1, m1, s1, ms1, h2, m2, s2, ms2, text] = segmentMatch;
      
      // Convertir timestamps a segundos
      const start = parseInt(h1) * 3600 + parseInt(m1) * 60 + parseInt(s1) + parseFloat('0.' + ms1);
      const end = parseInt(h2) * 3600 + parseInt(m2) * 60 + parseInt(s2) + parseFloat('0.' + ms2);
      
      // whisper-cli siempre pone 2 espacios antes de una continuación de palabra
      // y 3 espacios antes de un nuevo segmento (el 3er espacio es el separador natural).
      // Quitando exactamente 2 espacios del inicio, el espacio restante (si hay 3)
      // actúa como separador de palabra al concatenar directamente.
      const processedText = text.replace(/^ {2}/, '');
      
      segments.push({
        start: parseFloat(start.toFixed(3)),
        end: parseFloat(end.toFixed(3)),
        text: processedText.trim() // Para los segmentos, sí usar trim para limpieza
      });
      
      textParts.push(processedText);
    }
    
    // Extraer timings
    for (const [key, pattern] of Object.entries(timingPatterns)) {
      const match = line.match(pattern);
      if (match) {
        timings[key] = parseFloat(match[1]);
      }
    }
  }
  
  // Concatenar directamente todas las partes.
  // Cada parte ya tiene exactamente los espacios correctos:
  //   - Continuación de palabra: sin espacio al inicio  (ej: "ografía de cerebro")
  //   - Nuevo segmento: con 1 espacio al inicio          (ej: " Esto es una tom")
  // La concatenación directa produce el texto correcto.
  // Solo trim para quitar el espacio inicial del primer segmento y espacios finales
  const fullText = textParts.join('').trim();
  
  return {
    segments,
    text: fullText,
    timings
  };
}

// Función para convertir audio usando ffmpeg-rest (preferido) o ffmpeg local (fallback)
async function convertAudioIfNeeded(filePath, ffmpegRestUrl = null) {
  const ext = path.extname(filePath).toLowerCase().slice(1);
  // Formatos que NO necesitan conversión (se envían directamente a whisper-cli)
  // Nota: OGG se convierte porque whisper-cli puede tener problemas con ciertos codecs OGG (Opus, Vorbis)
  const supportedFormats = ['mp3', 'wav', 'flac'];
  
  // Si ya está en formato soportado, no convertir
  if (supportedFormats.includes(ext)) {
    return { converted: false, path: filePath };
  }
  
  // Prioridad 1: Usar ffmpeg-rest si está disponible
  const ffmpegUrl = ffmpegRestUrl || CONFIG.FFMPEG_REST_URL;
  if (ffmpegUrl) {
    try {
      const convertUrl = `${ffmpegUrl.replace(/\/$/, '')}/audio/mp3`;
      console.log(`Intentando conversión con ffmpeg-rest: ${convertUrl}`);
      
      const form = new FormData();
      const fileStream = require('fs').createReadStream(filePath);
      const fileName = path.basename(filePath);
      // Detectar MIME type básico
      const ext = path.extname(filePath).toLowerCase();
      const mimeTypes = {
        '.mp3': 'audio/mpeg',
        '.wav': 'audio/wav',
        '.flac': 'audio/flac',
        '.ogg': 'audio/ogg',
        '.m4a': 'audio/mp4',
        '.mp4': 'audio/mp4',
        '.aac': 'audio/aac',
        '.webm': 'audio/webm'
      };
      const mimeType = mimeTypes[ext] || 'audio/mp4';
      
      form.append('file', fileStream, {
        filename: fileName,
        contentType: mimeType
      });
      
      const response = await fetch(convertUrl, {
        method: 'POST',
        body: form,
        headers: form.getHeaders()
      });
      
      if (response.ok) {
        // node-fetch v2 usa .buffer(), v3 usa .arrayBuffer()
        const buffer = response.buffer ? await response.buffer() : Buffer.from(await response.arrayBuffer());
        if (buffer.length > 0) {
          const outputPath = filePath.replace(/\.[^.]+$/, '.mp3');
          await fs.writeFile(outputPath, buffer);
          const stats = await fs.stat(outputPath);
          
          console.log(`Audio convertido con ffmpeg-rest: ${path.basename(filePath)} -> ${path.basename(outputPath)} (${Math.round(stats.size / 1024)} KB)`);
          // Eliminar archivo original si es temporal
          await fs.unlink(filePath).catch(() => {});
          return { converted: true, path: outputPath };
        } else {
          throw new Error('ffmpeg-rest devolvió un archivo vacío');
        }
      } else {
        const errorText = await response.text().catch(() => 'Error desconocido');
        throw new Error(`ffmpeg-rest error HTTP ${response.status}: ${errorText}`);
      }
    } catch (error) {
      console.warn(`Error en conversión con ffmpeg-rest: ${error.message}. Intentando ffmpeg local como fallback...`);
      // Continuar con fallback a ffmpeg local
    }
  }
  
  // Prioridad 2: Usar ffmpeg local como fallback
  try {
    await execAsync('ffmpeg -version');
  } catch (e) {
    console.warn('ffmpeg no disponible, intentando enviar archivo directamente a whisper-cli');
    return { converted: false, path: filePath };
  }
  
  // Convertir a MP3 usando ffmpeg local
  const outputPath = filePath.replace(/\.[^.]+$/, '.mp3');
  const command = `ffmpeg -y -i "${filePath}" -acodec libmp3lame -ar 16000 -ac 1 -b:a 64k "${outputPath}" 2>&1`;
  
  try {
    const { stdout, stderr } = await execAsync(command);
    // Verificar que el archivo convertido existe y tiene contenido
    const stats = await fs.stat(outputPath);
    if (stats.size > 0) {
      console.log(`Audio convertido con ffmpeg local: ${path.basename(filePath)} -> ${path.basename(outputPath)} (${Math.round(stats.size / 1024)} KB)`);
      // Eliminar archivo original si es temporal
      await fs.unlink(filePath).catch(() => {});
      return { converted: true, path: outputPath };
    } else {
      throw new Error('Archivo convertido está vacío');
    }
  } catch (error) {
    console.warn(`Error en conversión con ffmpeg local: ${error.message}. Intentando enviar archivo original a whisper-cli`);
    return { converted: false, path: filePath };
  }
}

// Endpoint principal
app.post('/api/transcribe', upload.single('file'), async (req, res) => {
  const startTime = Date.now();
  let convertedFile = null;
  
  try {
    if (!req.file) {
      return res.status(400).json({ success: false, error: 'Archivo audio requerido' });
    }

    const { 
      model = CONFIG.DEFAULT_MODEL, 
      language = 'es', 
      threads = CONFIG.DEFAULT_THREADS, 
      dev = CONFIG.DEFAULT_DEV,  // Dispositivo GPU (0 = primera GPU)
      ml = CONFIG.DEFAULT_ML,    // Max length
      timestamps = true,
      ffmpeg_rest_url = null  // URL de ffmpeg-rest (opcional, puede venir del request)
    } = req.body;

    // Verificar modelo
    const modelPath = path.join(CONFIG.MODELS_DIR, model);
    try {
      await fs.access(modelPath);
    } catch (e) {
      await fs.unlink(req.file.path).catch(() => {});
      return res.status(400).json({ 
        success: false, 
        error: `Modelo no encontrado: ${model}` 
      });
    }

    // Verificar que el archivo existe y tiene contenido
    const fileStats = await fs.stat(req.file.path);
    console.log(`Archivo recibido: ${req.file.originalname} (${Math.round(fileStats.size / 1024)} KB, tipo: ${req.file.mimetype})`);
    
    if (fileStats.size === 0) {
      await fs.unlink(req.file.path).catch(() => {});
      return res.status(400).json({ 
        success: false, 
        error: 'El archivo está vacío' 
      });
    }

    // Convertir audio si es necesario (usando ffmpeg-rest si está disponible)
    console.log(`Verificando conversión. ffmpeg_rest_url: ${ffmpeg_rest_url || CONFIG.FFMPEG_REST_URL || 'no configurado'}`);
    const conversionResult = await convertAudioIfNeeded(req.file.path, ffmpeg_rest_url || CONFIG.FFMPEG_REST_URL);
    const fileToTranscribe = conversionResult.path;
    if (conversionResult.converted) {
      convertedFile = fileToTranscribe;
      console.log(`Archivo convertido: ${path.basename(fileToTranscribe)}`);
    } else {
      console.log(`Archivo no necesita conversión o conversión no disponible: ${path.basename(fileToTranscribe)}`);
    }

    // Verificar que el archivo a transcribir existe
    try {
      const finalStats = await fs.stat(fileToTranscribe);
      console.log(`Archivo a transcribir: ${path.basename(fileToTranscribe)} (${Math.round(finalStats.size / 1024)} KB)`);
      if (finalStats.size === 0) {
        await fs.unlink(req.file.path).catch(() => {});
        if (convertedFile && convertedFile !== req.file.path) {
          await fs.unlink(convertedFile).catch(() => {});
        }
        return res.status(400).json({ 
          success: false, 
          error: 'El archivo a transcribir está vacío después de la conversión' 
        });
      }
    } catch (e) {
      await fs.unlink(req.file.path).catch(() => {});
      if (convertedFile && convertedFile !== req.file.path) {
        await fs.unlink(convertedFile).catch(() => {});
      }
      return res.status(400).json({ 
        success: false, 
        error: `El archivo a transcribir no existe: ${fileToTranscribe}` 
      });
    }

    // Args optimizados RTX (formato correcto para whisper-cli)
    const args = [
      '-m', modelPath,
      '-f', fileToTranscribe,
      '--language', language,
      '-t', threads.toString(),
      '-dev', dev.toString(),  // Dispositivo GPU (0 = primera GPU)
      '-ml', ml.toString()     // Max length
    ];

    // Nota: --timestamps no es necesario, whisper-cli siempre muestra timestamps

    console.log(`Ejecutando: whisper-cli ${args.join(' ')}`);

    // Spawn con timeout
    return new Promise((resolve, reject) => {
      const proc = spawn(CONFIG.WHISPER_CLI, args, { 
        timeout: CONFIG.TIMEOUT,
        stdio: ['ignore', 'pipe', 'pipe']
      });
      
      let stdout = '';
      let stderr = '';

      proc.stdout.on('data', data => {
        stdout += data.toString();
      });

      proc.stderr.on('data', data => {
        stderr += data.toString();
        // También capturar stdout en stderr (whisper-cli puede escribir en ambos)
        stdout += data.toString();
      });

      proc.on('close', async (code) => {
        // Cleanup del archivo subido y convertido
        await fs.unlink(req.file.path).catch(() => {});
        if (convertedFile && convertedFile !== req.file.path) {
          await fs.unlink(convertedFile).catch(() => {});
        }
        
        if (code === 0) {
          // Log de salida para debugging
          console.log(`whisper-cli completado. stdout length: ${stdout.length}, stderr length: ${stderr.length}`);
          if (stdout.length > 0) {
            console.log(`Primeros 500 chars de stdout: ${stdout.substring(0, 500)}`);
          }
          if (stderr.length > 0 && stderr !== stdout) {
            console.log(`Primeros 500 chars de stderr: ${stderr.substring(0, 500)}`);
          }
          
          // Procesar salida
          const parsed = parseWhisperOutput(stdout);
          
          if (!parsed.text || parsed.text.trim() === '') {
            console.error(`ERROR: Transcripción vacía. stdout completo: ${stdout.substring(0, 2000)}`);
            return resolve(res.status(400).json({ 
              success: false, 
              error: `La transcripción está vacía. Verifica el archivo de audio. (stdout length: ${stdout.length}, segments encontrados: ${parsed.segments ? parsed.segments.length : 0})` 
            }));
          }
          
          const duration = (Date.now() - startTime) / 1000;
          
          resolve(res.json({
            success: true,
            mode: 'cli',
            model,
            language,
            duration,
            text: parsed.text,
            segments: parsed.segments,
            timings: parsed.timings,
            usage: { 
              vram: 'GPU RTX 5060 Ti 16GB', 
              cpu: `${threads}t`,
              total_time_ms: parsed.timings.total_time || duration * 1000
            }
          }));
        } else {
          const errorMsg = stderr || `whisper-cli falló con código ${code}`;
          console.error('Error whisper-cli:', errorMsg);
          resolve(res.status(500).json({ 
            success: false, 
            error: `whisper-cli falló (code ${code}): ${errorMsg.substring(0, 500)}` 
          }));
        }
      });

      proc.on('error', async (error) => {
        await fs.unlink(req.file.path).catch(() => {});
        if (convertedFile && convertedFile !== req.file.path) {
          await fs.unlink(convertedFile).catch(() => {});
        }
        console.error('Error ejecutando whisper-cli:', error);
        resolve(res.status(500).json({ 
          success: false, 
          error: `Error ejecutando whisper-cli: ${error.message}` 
        }));
      });
    });
  } catch (error) {
    if (req.file) {
      await fs.unlink(req.file.path).catch(() => {});
    }
    if (convertedFile && convertedFile !== req.file?.path) {
      await fs.unlink(convertedFile).catch(() => {});
    }
    console.error('Error en /api/transcribe:', error);
    res.status(500).json({ 
      success: false, 
      error: error.message 
    });
  }
});

// List models
app.get('/api/models', async (req, res) => {
  try {
    const models = await fs.readdir(CONFIG.MODELS_DIR);
    const modelFiles = models.filter(f => f.match(/ggml.*\.bin$/));
    
    const modelList = await Promise.all(
      modelFiles.map(async (f) => {
        try {
          const stats = await fs.stat(path.join(CONFIG.MODELS_DIR, f));
          return {
            name: f,
            size: stats.size,
            size_mb: (stats.size / (1024 * 1024)).toFixed(2)
          };
        } catch (e) {
          return { name: f, size: 0, size_mb: '0' };
        }
      })
    );
    
    res.json(modelList);
  } catch (e) {
    console.error('Error listando modelos:', e);
    res.status(500).json({ error: e.message });
  }
});

const PORT = process.env.PORT || 3001;
app.listen(PORT, '0.0.0.0', () => {
  console.log(`🚀 Whisper CLI API en http://0.0.0.0:${PORT}`);
  console.log(`📁 Modelos: ${CONFIG.MODELS_DIR}`);
  console.log(`💾 Uploads: ${CONFIG.UPLOAD_DIR}`);
  console.log(`🔧 Whisper CLI: ${CONFIG.WHISPER_CLI}`);
});

// Graceful shutdown
process.on('SIGTERM', () => {
  console.log('Shutdown graceful...');
  process.exit(0);
});
