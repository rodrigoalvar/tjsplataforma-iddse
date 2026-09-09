# Instalar ffmpeg-rest con PM2

## Opción 1: Script Automático (Recomendado)

1. **Hacer el script ejecutable:**
   ```bash
   chmod +x /var/www/tjsiddse/workers/instalar-ffmpeg-rest-pm2.sh
   ```

2. **Ejecutar el script:**
   ```bash
   /var/www/tjsiddse/workers/instalar-ffmpeg-rest-pm2.sh
   ```

3. **El script te pedirá:**
   - Ruta del directorio de ffmpeg-rest
   - Puerto (si no está en package.json)

4. **El script automáticamente:**
   - Verifica que PM2 esté instalado
   - Crea el archivo `ecosystem.config.js`
   - Instala dependencias si es necesario
   - Inicia los procesos con PM2
   - Configura PM2 para iniciar al arrancar el sistema

## Opción 2: Configuración Manual

1. **Editar el archivo de configuración:**
   ```bash
   nano /var/www/tjsiddse/workers/ffmpeg-rest-ecosystem.config.js
   ```

2. **Ajustar las rutas:**
   - Cambiar `cwd: '/home/iddse/Documentos/TJS/ffmpeg-rest'` por tu ruta real
   - Ajustar el puerto si es diferente de 3000

3. **Copiar el archivo a tu directorio de ffmpeg-rest:**
   ```bash
   cp /var/www/tjsiddse/workers/ffmpeg-rest-ecosystem.config.cjs /ruta/a/tu/ffmpeg-rest/ecosystem.config.cjs
   ```
   
   **Nota:** Usamos `.cjs` en lugar de `.js` porque el proyecto usa ES modules (`"type": "module"` en package.json).

4. **Iniciar con PM2:**
   ```bash
   cd /ruta/a/tu/ffmpeg-rest
   pm2 start ecosystem.config.cjs
   ```

5. **Guardar configuración:**
   ```bash
   pm2 save
   ```

6. **Configurar para iniciar al arrancar:**
   ```bash
   pm2 startup | tail -1 | sudo bash
   ```

## Comandos Útiles

```bash
# Ver estado de los procesos
pm2 status

# Ver logs en tiempo real
pm2 logs ffmpeg-rest
pm2 logs ffmpeg-rest-worker

# Ver logs de ambos
pm2 logs

# Reiniciar procesos
pm2 restart ffmpeg-rest
pm2 restart ffmpeg-rest-worker

# Reiniciar todos
pm2 restart all

# Detener procesos
pm2 stop ffmpeg-rest
pm2 stop ffmpeg-rest-worker

# Eliminar procesos
pm2 delete ffmpeg-rest
pm2 delete ffmpeg-rest-worker

# Monitor en tiempo real
pm2 monit

# Ver información detallada
pm2 show ffmpeg-rest
pm2 show ffmpeg-rest-worker
```

## Verificar que está funcionando

1. **Verificar procesos:**
   ```bash
   pm2 status
   ```
   Deberías ver `ffmpeg-rest` y `ffmpeg-rest-worker` en estado `online`.

2. **Probar el endpoint:**
   ```bash
   curl http://localhost:3000/endpoints
   # O desde otro servidor:
   curl http://192.168.0.33:3000/endpoints
   ```

3. **Ver logs:**
   ```bash
   pm2 logs ffmpeg-rest --lines 50
   ```

## Solución de Problemas

### Error: "PM2 command not found"
```bash
sudo npm install -g pm2
```

### Error: "Cannot find module"
Asegúrate de que las dependencias estén instaladas:
```bash
cd /ruta/a/tu/ffmpeg-rest
npm install
```

### Los procesos no inician
Verifica los logs:
```bash
pm2 logs ffmpeg-rest --err
pm2 logs ffmpeg-rest-worker --err
```

### Cambiar puerto o configuración
1. Edita `ecosystem.config.js`
2. Reinicia: `pm2 restart all`
3. Guarda: `pm2 save`

## Notas

- **ffmpeg-rest** corre en el puerto 3000 por defecto
- **ffmpeg-rest-worker** es el worker que procesa las conversiones
- Los logs se guardan en `~/.pm2/logs/`
- PM2 reinicia automáticamente los procesos si fallan
- Los procesos se inician automáticamente al reiniciar el servidor
