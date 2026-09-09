# Instalación de Whisper CLI API

Este documento contiene las instrucciones para instalar y configurar la API whisper-cli-api.js en el servidor con RTX 5060 Ti.

## Requisitos Previos

- Node.js instalado (versión 16 o superior)
- whisper-cli compilado y funcionando
- Acceso al directorio de modelos de whisper.cpp

## Pasos de Instalación

### 1. Preparar el directorio

```bash
# Crear directorio para la API
mkdir -p /home/iddse/Documentos/TJS/whisper-cli-api
cd /home/iddse/Documentos/TJS/whisper-cli-api
```

### 2. Copiar el archivo whisper-cli-api.js

Si el archivo está en el servidor principal, cópialo al servidor RTX:

```bash
# Desde el servidor principal, copiar a RTX (ajustar IP y ruta)
scp /var/www/tjsiddse/workers/whisper-cli-api.js usuario@IP_RTX:/home/iddse/Documentos/TJS/whisper-cli-api/
```

O si ya está en el servidor RTX, verifica que esté en la ubicación correcta.

### 3. Inicializar proyecto Node.js

```bash
cd /home/iddse/Documentos/TJS/whisper-cli-api
npm init -y
```

### 4. Instalar dependencias

```bash
npm install express multer cors
```

### 5. Verificar rutas en whisper-cli-api.js

Edita el archivo `whisper-cli-api.js` y verifica/ajusta las siguientes rutas en la sección `CONFIG`:

```javascript
const CONFIG = {
  WHISPER_CLI: '/home/iddse/Documentos/TJS/whisper.cpp/build/bin/whisper-cli',  // Ruta al binario whisper-cli
  MODELS_DIR: '/home/iddse/Documentos/TJS/whisper.cpp/models',                  // Ruta a los modelos
  UPLOAD_DIR: '/tmp/whisper-uploads',                                           // Directorio temporal para uploads
  DEFAULT_MODEL: 'ggml-medium.bin',                                             // Modelo por defecto
  DEFAULT_THREADS: 8,                                                           // Threads CPU
  DEFAULT_NGL: 99,                                                              // Layers GPU (99 = todas)
  TIMEOUT: 60000,                                                               // Timeout en ms (60s)
  MAX_SIZE: 100 * 1024 * 1024                                                   // Tamaño máximo archivo (100MB)
};
```

**Importante:** Ajusta las rutas según tu instalación de whisper.cpp.

### 6. Probar la API

```bash
# Ejecutar directamente (modo prueba)
node whisper-cli-api.js
```

Deberías ver:
```
🚀 Whisper CLI API en http://0.0.0.0:3001
📁 Modelos: /home/iddse/Documentos/TJS/whisper.cpp/models
💾 Uploads: /tmp/whisper-uploads
🔧 Whisper CLI: /home/iddse/Documentos/TJS/whisper.cpp/build/bin/whisper-cli
```

### 7. Probar endpoint de salud

En otra terminal o desde el servidor principal:

```bash
curl http://IP_RTX:3001/health
```

Debería devolver un JSON con el estado de la API.

### 8. Instalar PM2 (Recomendado para producción)

```bash
# Instalar PM2 globalmente
npm install -g pm2

# Iniciar la API con PM2
cd /home/iddse/Documentos/TJS/whisper-cli-api
pm2 start whisper-cli-api.js --name whisper-cli-api

# Ver estado
pm2 status

# Ver logs
pm2 logs whisper-cli-api

# Guardar configuración para que persista después de reinicios
pm2 save

# Configurar inicio automático
pm2 startup
# (Seguir las instrucciones que muestra el comando)
```

### 9. Configurar firewall (si es necesario)

Si hay un firewall, permitir el puerto 3001:

```bash
# UFW (Ubuntu)
sudo ufw allow 3001/tcp

# Firewalld (CentOS/RHEL)
sudo firewall-cmd --permanent --add-port=3001/tcp
sudo firewall-cmd --reload
```

### 10. Verificar que whisper-cli funciona

Antes de usar la API, verifica que whisper-cli funciona manualmente:

```bash
# Probar whisper-cli directamente
/home/iddse/Documentos/TJS/whisper.cpp/build/bin/whisper-cli \
  -m /home/iddse/Documentos/TJS/whisper.cpp/models/ggml-medium.bin \
  -f /ruta/a/archivo.mp3 \
  --language es \
  -t 8 \
  -ngl 99
```

## Configuración en el Sistema Principal

Una vez que la API esté corriendo en el servidor RTX:

1. Ir a **Configuración → AI Informes** en el sistema principal
2. Seleccionar **Método de Transcripción**: `whisper-cli`
3. Configurar **URL API de Whisper CLI**: `http://IP_RTX:3001`
4. Hacer clic en **Probar** para verificar la conexión
5. Guardar la configuración

## Solución de Problemas

### Error: "Cannot find module 'express'"
```bash
cd /home/iddse/Documentos/TJS/whisper-cli-api
npm install express multer cors
```

### Error: "whisper-cli: command not found"
Verifica la ruta en `CONFIG.WHISPER_CLI` y que el binario tenga permisos de ejecución:
```bash
chmod +x /home/iddse/Documentos/TJS/whisper.cpp/build/bin/whisper-cli
```

### Error: "Modelo no encontrado"
Verifica la ruta en `CONFIG.MODELS_DIR` y que el modelo exista:
```bash
ls -lh /home/iddse/Documentos/TJS/whisper.cpp/models/
```

### La API no responde
- Verifica que esté corriendo: `pm2 status` o `ps aux | grep whisper-cli-api`
- Verifica los logs: `pm2 logs whisper-cli-api`
- Verifica el puerto: `netstat -tulpn | grep 3001`
- Verifica el firewall

### Error de permisos en /tmp/whisper-uploads
```bash
sudo mkdir -p /tmp/whisper-uploads
sudo chmod 777 /tmp/whisper-uploads
```

## Comandos Útiles PM2

```bash
# Ver estado
pm2 status

# Ver logs en tiempo real
pm2 logs whisper-cli-api

# Reiniciar
pm2 restart whisper-cli-api

# Detener
pm2 stop whisper-cli-api

# Eliminar
pm2 delete whisper-cli-api

# Monitoreo
pm2 monit
```

## Notas

- El puerto por defecto es **3001**, puedes cambiarlo con la variable de entorno `PORT`
- Los archivos subidos se eliminan automáticamente después de procesarlos
- La API procesa archivos MP3, WAV, FLAC y M4A
- El tamaño máximo por defecto es 100MB (configurable en `CONFIG.MAX_SIZE`)
