# Soporte para Archivos M4A

El sistema ahora soporta automáticamente archivos M4A (y otros formatos como AAC, MP4) convirtiéndolos a MP3 antes de enviarlos a whisper.cpp para transcripción.

## Requisitos

Para que la conversión funcione, necesitas tener **ffmpeg** instalado en el servidor.

### Instalación de ffmpeg

#### Ubuntu/Debian:
```bash
sudo apt-get update
sudo apt-get install ffmpeg
```

#### CentOS/RHEL:
```bash
sudo yum install ffmpeg
# O para versiones más recientes:
sudo dnf install ffmpeg
```

#### Verificar instalación:
```bash
ffmpeg -version
```

## Formatos Soportados

### Formatos soportados directamente (sin conversión):
- MP3
- WAV
- OGG
- FLAC
- WebM

### Formatos que se convierten automáticamente a MP3:
- M4A
- AAC
- MP4 (audio)
- M4V (audio)

## Funcionamiento

1. Cuando subes un archivo M4A, el sistema detecta automáticamente que necesita conversión
2. Se convierte a MP3 usando ffmpeg con parámetros optimizados para transcripción de voz:
   - Frecuencia de muestreo: 16kHz (suficiente para voz)
   - Canales: Mono (recomendado para transcripción)
   - Bitrate: 64kbps (suficiente para voz)
3. El archivo MP3 convertido se envía a whisper.cpp
4. El archivo temporal se elimina automáticamente después de la transcripción

## Logs

Si hay problemas con la conversión, revisa los logs de PHP:
```bash
tail -f /var/log/php-fpm/error.log
# O según tu configuración:
tail -f /var/log/php/error.log
```

Los mensajes de log incluyen:
- `WhisperClient: Convirtiendo m4a a MP3: ...`
- `WhisperClient: Conversión exitosa. Archivo convertido: ...`
- `WhisperClient: Error en conversión: ...`

## Solución de Problemas

### Error: "ffmpeg no está instalado"
- Instala ffmpeg siguiendo las instrucciones arriba
- Verifica que ffmpeg esté en el PATH del usuario que ejecuta PHP (generalmente www-data)

### Error: "Error al convertir el archivo de audio"
- Verifica que el archivo M4A no esté corrupto
- Verifica los permisos del directorio temporal (`uploads/audio/`)
- Revisa los logs para ver el error específico de ffmpeg

### El archivo se convierte pero la transcripción falla
- Verifica que whisper.cpp esté funcionando correctamente
- Prueba con un archivo MP3 directamente para aislar el problema
- Revisa los logs de whisper-server

## Notas

- Los archivos convertidos son temporales y se eliminan automáticamente
- La conversión puede agregar unos segundos al tiempo total de procesamiento
- La calidad de la conversión está optimizada para transcripción de voz, no para reproducción de música
