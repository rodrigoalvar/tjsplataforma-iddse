# Configurar Conversión Remota de Audio con ffmpeg-rest

## Arquitectura

Cuando tienes una arquitectura distribuida:
- **Servidor 1**: Nginx + PHP-FPM (aplicación web) - recursos limitados
- **Servidor 2**: Whisper.cpp + ffmpeg-rest (192.168.0.33) - recursos potentes (RTX, RAM, i9)

La conversión de M4A a MP3 se hace **en el servidor de Whisper usando ffmpeg-rest** para aprovechar sus recursos.

## ¿Qué es ffmpeg-rest?

**ffmpeg-rest** (crisog/ffmpeg-rest) es una API REST que expone ffmpeg como servicio web. Permite convertir archivos de audio/video mediante peticiones HTTP sin necesidad de tener ffmpeg instalado localmente.

## Flujo

```
Usuario sube M4A
    ↓
Servidor Nginx/PHP-FPM recibe archivo
    ↓
PHP detecta formato M4A
    ↓
PHP envía M4A al servidor de Whisper para conversión
    ↓
Servidor Whisper convierte M4A → MP3 usando ffmpeg local (RTX/i9)
    ↓
Servidor Whisper devuelve MP3 convertido
    ↓
PHP envía MP3 a Whisper para transcripción
    ↓
Whisper transcribe MP3
    ↓
Resultado devuelto a PHP
```

## Instalación de ffmpeg-rest

En el servidor de Whisper (192.168.0.33):

### Opción 1: Usando Docker (Recomendado)

```bash
docker run -d \
  --name ffmpeg-rest \
  -p 3000:3000 \
  -e FFMPEG_PATH=/usr/bin/ffmpeg \
  crisog/ffmpeg-rest
```

### Opción 2: Usando Node.js

```bash
# Instalar Node.js si no está instalado
curl -fsSL https://deb.nodesource.com/setup_18.x | sudo -E bash -
sudo apt-get install -y nodejs

# Instalar ffmpeg-rest globalmente
npm install -g @crisog/ffmpeg-rest

# O clonar el repositorio
git clone https://github.com/crisog/ffmpeg-rest.git
cd ffmpeg-rest
npm install
```

### Paso 2: Configurar ffmpeg-rest para escuchar en la red

ffmpeg-rest debe estar configurado para escuchar en `0.0.0.0` (no solo localhost):

```bash
# Si usas Docker, ya está configurado
# Si usas Node.js directamente:
ffmpeg-rest --host 0.0.0.0 --port 3000
```

### Paso 3: Configurar como servicio systemd (Recomendado)

Crear archivo `/etc/systemd/system/ffmpeg-rest.service`:

```ini
[Unit]
Description=FFmpeg REST API
After=network.target

[Service]
Type=simple
User=tu-usuario
WorkingDirectory=/ruta/a/ffmpeg-rest
ExecStart=/usr/bin/node /ruta/a/ffmpeg-rest/index.js --host 0.0.0.0 --port 3000
Restart=always
Environment="NODE_ENV=production"

[Install]
WantedBy=multi-user.target
```

Activar y iniciar:

```bash
sudo systemctl daemon-reload
sudo systemctl enable ffmpeg-rest
sudo systemctl start ffmpeg-rest
sudo systemctl status ffmpeg-rest
```

### Paso 4: Configurar en la aplicación

1. Ve a **Configuración → AI Informes**
2. En la sección **"Configuración de FFmpeg REST"**, ingresa la URL:
   - Ejemplo: `http://192.168.0.33:3000`
3. Haz clic en **"Probar"** para verificar la conexión
4. Guarda la configuración

### Paso 5: Verificar conectividad

Desde el servidor de Nginx/PHP-FPM:

```bash
# Probar endpoint de información
curl http://192.168.0.33:3000/endpoints

# Probar conversión (requiere archivo de prueba)
curl -X POST http://192.168.0.33:3000/audio/mp3 \
  -F "file=@test.m4a" \
  --output converted.mp3
```

## Endpoints de ffmpeg-rest

Según la documentación oficial, los endpoints principales son:

- `POST /audio/mp3` - Convierte audio a MP3 (devuelve archivo binario)
- `POST /audio/wav` - Convierte audio a WAV (devuelve archivo binario)
- `GET /endpoints` - Lista todos los endpoints disponibles
- `GET /` - Documentación de la API

## Solución de Problemas

### Error: "ffmpeg-rest no está configurado"

1. Ve a **Configuración → AI Informes**
2. Configura la URL de ffmpeg-rest en la sección correspondiente
3. Haz clic en **"Probar"** para verificar la conexión

### Error: "Error al conectar con ffmpeg-rest"

1. Verifica que ffmpeg-rest esté corriendo:
   ```bash
   # Si usas Docker
   docker ps | grep ffmpeg-rest
   
   # Si usas systemd
   sudo systemctl status ffmpeg-rest
   ```

2. Verifica que esté escuchando en la red (no solo localhost):
   ```bash
   netstat -tlnp | grep 3000
   # Debe mostrar 0.0.0.0:3000, no 127.0.0.1:3000
   ```

3. Verifica conectividad desde el servidor de Nginx:
   ```bash
   curl http://192.168.0.33:3000/endpoints
   ```

4. Verifica firewall:
   ```bash
   sudo ufw allow 3000/tcp
   ```

### Error: "Error de ffmpeg-rest: Invalid file format"

- Verifica que el archivo de audio sea válido
- ffmpeg-rest soporta múltiples formatos, pero algunos pueden requerir codecs adicionales

### Error: "Respuesta inesperada de ffmpeg-rest"

- Verifica que la URL sea correcta (debe incluir `http://` y el puerto)
- Verifica que no haya un proxy o balanceador de carga interfiriendo

## Fallback Local

Si la conversión remota con ffmpeg-rest falla, el sistema intentará conversión local como fallback (requiere ffmpeg instalado en el servidor de Nginx/PHP-FPM).

## Notas

- ffmpeg-rest devuelve el archivo convertido directamente como binario (no JSON)
- Los archivos temporales se limpian automáticamente después de la conversión
- El timeout de conversión es de 5 minutos (300 segundos)
- ffmpeg-rest debe estar en el mismo servidor o red accesible que Whisper para aprovechar los recursos (RTX/i9)
