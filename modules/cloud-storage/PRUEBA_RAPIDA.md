# 🚀 Prueba Rápida con Estudio Real

## Opción 1: Usar el Script Web (Recomendado)

1. **Accede a:** `https://plataforma.iddse.com.ar/modules/cloud-storage/test-with-study.php`

2. **El script te mostrará:**
   - Lista de estudios disponibles en Orthanc
   - Botón para encolar cada estudio
   - Estado de la cola
   - Estudios ya subidos a R2
   - Ver manifest de estudios en R2

3. **Pasos:**
   - Haz clic en "Encolar" en un estudio
   - Ejecuta el worker (ver instrucciones abajo)
   - Verifica que el estudio aparezca en R2

---

## Opción 2: Manual (Línea de Comandos)

### Paso 1: Obtener un Study ID de Orthanc

```bash
# Listar estudios disponibles
curl -u orthanc:orthanc http://localhost:8042/studies | jq '.[0]'
```

O si no tienes `jq`:
```bash
curl -u orthanc:orthanc http://localhost:8042/studies
```

Copia el ID del primer estudio (ej: `abc123def456...`)

### Paso 2: Encolar el estudio

```bash
curl -X POST https://plataforma.iddse.com.ar/api/cloud-storage/enqueue \
  -H "Content-Type: application/json" \
  -d '{"orthanc_study_id": "TU_STUDY_ID_AQUI"}'
```

**Respuesta esperada:**
```json
{
  "success": true,
  "queue_id": 1,
  "message": "Estudio encolado correctamente"
}
```

### Paso 3: Ejecutar el worker

```bash
cd /var/www/tjsiddse/modules/cloud-storage
php workers/r2-upload-worker.php
```

**Salida esperada:**
```
[2026-03-12 XX:XX:XX] Iniciando worker R2...
Procesando cola...
Procesados: 1 de 1
[2026-03-12 XX:XX:XX] Worker finalizado.
```

**Si hay errores:**
- Revisa los mensajes de error
- Verifica credenciales R2
- Verifica que Orthanc esté accesible

### Paso 4: Verificar resultados

**En base de datos:**
```sql
-- Ver estado de la cola
SELECT * FROM r2_queue ORDER BY created_at DESC LIMIT 5;

-- Ver estudios en R2
SELECT * FROM r2_studies WHERE r2_status = 'online';
```

**Obtener manifest:**
```bash
curl https://plataforma.iddse.com.ar/api/cloud-storage/manifest/TU_STUDY_ID
```

Deberías recibir un JSON con todas las instancias y sus URLs presignadas.

---

## Verificar en Cloudflare R2

1. Ve a **Cloudflare Dashboard** → **R2** → **tjsmedical**
2. Deberías ver la estructura:
   ```
   tjsiddse/
     └── {StudyInstanceUID}/
         ├── manifest.json
         └── series/
             └── {SeriesInstanceUID}/
                 └── {SOPInstanceUID}.dcm
   ```

---

## Troubleshooting

### Error: "No se pudo conectar a la base de datos"
- Verifica `config/database.php`
- Verifica credenciales MySQL

### Error: "Error descargando instancia desde Orthanc"
- Verifica que Orthanc esté accesible
- Verifica credenciales en `api/config/orthanc_config.php`
- Verifica que el estudio exista en Orthanc

### Error: "Error subiendo a R2"
- Verifica credenciales R2 en `.env`
- Verifica que el bucket `tjsmedical` exista
- Verifica permisos del API Token

### Worker no procesa nada
- Verifica que haya estudios con `status = 'pending'` en `r2_queue`
- Verifica logs del worker
- Ejecuta manualmente para ver errores

---

## ✅ Checklist de Prueba

- [ ] Estudio encolado exitosamente
- [ ] Worker ejecutado sin errores
- [ ] Estudio aparece en `r2_studies` con `r2_status = 'online'`
- [ ] Estructura creada en R2 (verificar en Cloudflare Dashboard)
- [ ] Manifest se genera correctamente
- [ ] URLs presignadas funcionan (probar descargar una imagen)

---

**¡Listo!** Si todos los checks pasan, el módulo está funcionando correctamente. 🎉
