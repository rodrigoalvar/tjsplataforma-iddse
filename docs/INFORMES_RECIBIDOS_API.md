# API de Informes Recibidos - Documentación

## Descripción

Sistema para recibir informes médicos en formato PDF desde otros sistemas externos. Los informes deben venir acompañados de un archivo TXT con datos DICOM que permiten identificar y vincular el informe con el estudio correspondiente.

## Endpoints

### 1. Recibir Informe PDF

**Endpoint:** `POST /api/informes/recibir-pdf.php`

**Descripción:** Recibe un archivo PDF y un archivo TXT con datos DICOM.

**Content-Type:** `multipart/form-data`

**Parámetros:**
- `pdf` (file, requerido): Archivo PDF del informe
- `txt` (file, requerido): Archivo TXT con datos DICOM

**Formato del archivo TXT:**

```
[Message]
Type=Add
[DicomData]
;Numero de acceso
(0008.0050)=541527
;Modalidad
(0008.0060)=CR
;Medico referente
(0008.0090)=4518      
;Nombre del paciente
(0010.0010)=CAZAUX^MERCEDES GRACIELA
;Identificacion del paciente
(0010.0020)=10010647
;Nombre del equipo
(0040.0001)=RAYOS
;Fecha del procedimiento
(0040.0002)=20250731
;Hora del procedimiento
(0040.0003)=104100
;Descripcion del procedimiento
(0040.0007)=RADIOGRAFIA O TELERADIOGRAFIA DE T
;Fecha de nacimiento del paciente
(0010.0030)=19520303
;Sexo del paciente
(0010.0040)=F
;Descripcion del procedimiento requerido
(0032.1060)=RADIOGRAFIA O TELERADIOGRAFIA DE T
```

**Tags DICOM soportados:**
- `(0008.0050)` - Accession Number (requerido)
- `(0008.0060)` - Modality
- `(0008.0090)` - Referring Physician
- `(0010.0010)` - Patient Name
- `(0010.0020)` - Patient ID
- `(0010.0030)` - Patient Birth Date (formato: YYYYMMDD)
- `(0010.0040)` - Patient Sex (M/F/O)
- `(0040.0001)` - Equipment Name
- `(0040.0002)` - Procedure Date (formato: YYYYMMDD)
- `(0040.0003)` - Procedure Time (formato: HHMMSS)
- `(0040.0007)` - Procedure Description
- `(0032.1060)` - Reason for Study

**Ejemplo con cURL:**

```bash
curl -X POST http://tu-servidor/api/informes/recibir-pdf.php \
  -F "pdf=@/ruta/al/informe.pdf" \
  -F "txt=@/ruta/al/datos.txt"
```

**Respuesta exitosa:**

```json
{
  "success": true,
  "message": "Informe recibido exitosamente",
  "data": {
    "id": 1,
    "accession_number": "541527",
    "estudio_id": 123,
    "estado": "vinculado",
    "pdf_path": "uploads/informes_recibidos/informe_541527_20250101_120000.pdf",
    "txt_path": "uploads/informes_recibidos/datos_541527_20250101_120000.txt"
  }
}
```

**Respuesta de error:**

```json
{
  "success": false,
  "error": "Mensaje de error descriptivo"
}
```

### 2. Listar Informes Recibidos

**Endpoint:** `GET /api/informes/recibidos/list.php`

**Descripción:** Obtiene la lista de informes recibidos con filtros opcionales.

**Parámetros de consulta:**
- `page` (int, opcional): Número de página (default: 1)
- `limit` (int, opcional): Elementos por página (default: 50)
- `estado` (string, opcional): Filtrar por estado (recibido, procesado, vinculado, error)
- `accession_number` (string, opcional): Buscar por número de acceso
- `search` (string, opcional): Búsqueda general (paciente, ID, etc.)

**Ejemplo:**

```bash
curl "http://tu-servidor/api/informes/recibidos/list.php?estado=vinculado&page=1&limit=50"
```

**Respuesta:**

```json
{
  "success": true,
  "data": [
    {
      "id": 1,
      "accession_number": "541527",
      "pdf_path": "uploads/informes_recibidos/informe_541527_20250101_120000.pdf",
      "txt_path": "uploads/informes_recibidos/datos_541527_20250101_120000.txt",
      "estudio_id": 123,
      "patient_name": "CAZAUX MERCEDES GRACIELA",
      "patient_id": "10010647",
      "modality": "CR",
      "estado": "vinculado",
      "fecha_recepcion_formatted": "01/01/2025 12:00:00"
    }
  ],
  "pagination": {
    "page": 1,
    "limit": 50,
    "total": 1,
    "pages": 1
  }
}
```

## Estados de los Informes

- **recibido**: El informe fue recibido pero aún no se ha procesado o vinculado
- **procesado**: El informe fue procesado pero no vinculado a un estudio
- **vinculado**: El informe fue vinculado exitosamente a un estudio (por accession_number)
- **error**: Ocurrió un error al procesar el informe

## Vinculación con Estudios

El sistema busca automáticamente el estudio correspondiente usando el campo `accession_number` (Número de Acceso DICOM) en la tabla `estudios`. Si encuentra un estudio con el mismo `accession_number`, el informe se marca como "vinculado" y se guarda la referencia al estudio.

## Almacenamiento de Archivos

Los archivos recibidos se almacenan en:
- **Directorio:** `uploads/informes_recibidos/`
- **Formato PDF:** `informe_{accession_number}_{timestamp}.pdf`
- **Formato TXT:** `datos_{accession_number}_{timestamp}.txt`

## Base de Datos

La tabla `informes_recibidos` almacena toda la información de los informes recibidos. Ver el script SQL en `database/create_informes_recibidos_table.sql` para la estructura completa.

## Interfaz de Usuario

La interfaz para visualizar los informes recibidos está disponible en:
- **URL:** `informes-recibidos.html`
- **Funcionalidades:**
  - Listar todos los informes recibidos
  - Filtrar por estado, número de acceso o búsqueda general
  - Ver detalles completos de cada informe
  - Ver el PDF del informe
  - Acceder al estudio vinculado (si existe)

## Notas Importantes

1. El campo `accession_number` es **obligatorio** en el archivo TXT
2. El tamaño máximo del archivo PDF es **50MB**
3. Los archivos se almacenan permanentemente en el servidor
4. La vinculación con estudios es automática si existe un estudio con el mismo `accession_number`
5. La interfaz aún no está visible en el sidebar (como se solicitó)
