# PDFtoOrthanc (Versión en Español)

[![Python 3.8+](https://img.shields.io/badge/python-3.8+-blue.svg)](https://www.python.org/downloads/)
[![License: GPL v3](https://img.shields.io/badge/License-GPLv3-blue.svg)](https://www.gnu.org/licenses/gpl-3.0)

Una herramienta Python para convertir archivos PDF médicos a formato DICOM y enviarlos automáticamente a un servidor Orthanc PACS.

## 📋 Características

- ✅ Conversión automática de PDFs a DICOM (SOP Class: Encapsulated PDF)
- ✅ Detección de archivos PDF corruptos
- ✅ Verificación de duplicados basada en múltiples criterios
- ✅ Procesamiento paralelo con control de workers
- ✅ Organización automática en carpetas por fecha
- ✅ Reintento automático con backoff exponencial
- ✅ Logs estructurados en JSON
- ✅ Soporte a dos formatos de nomenclatura de archivo
- ✅ Validación rigurosa de nombres de pacientes

## 🚀 Instalación

### Pré-requisitos

- Python 3.8 o superior
- Servidor Orthanc configurado y accesible
- Bibliotecas Python: `requests`

### Instalación de dependencias

```bash
pip install requests
```

## 📁 Estructura de Archivos

El script organiza automáticamente los archivos procesados:

```
PDF_SOURCE_FOLDER/
├── archivo1.pdf          # Archivos a procesar
├── archivo2.pdf
├── Procesados/          # PDFs enviados con éxito
│   └── 2024-01-15/      # Organizados por fecha (opcional)
├── Duplicados/           # PDFs que ya existen en Orthanc
│   └── 2024-01-15/
├── Errores/                # PDFs con error en el procesamiento
└── pdftoorthanc.log     # Archivo de log
```

## 📝 Formato de los Archivos PDF

### Formato Estructurado (Recomendado)
```
PatientID_FirstName_MiddleName_LastName_Date_AccessionNumber.pdf
```

**Ejemplo:** `12345_JUAN_PEDRO_SILVA_SANTOS_15012024_98765.pdf`

- **PatientID**: Solo números
- **Nombres**: Solo letras y espacios
- **Fecha**: DDMMAAAA o DDMMAA
- **AccessionNumber**: Solo números

### Formato Legado
```
FirstName_MiddleName_LastName_Date.pdf
```

**Ejemplo:** `MARIA_OLIVEIRA_15012024.pdf`

## ⚙️ Configuración

### Variables de Entorno

| Variable | Valor por Defecto | Descripción |
|----------|-------------------|-------------|
| `ORTHANC_URL` | `http://localhost:8042` | URL del servidor Orthanc |
| `ORTHANC_USER` | `orthanc` | Usuario del Orthanc |
| `ORTHANC_PASSWORD` | `orthanc` | Contraseña del Orthanc |
| `PDF_SOURCE_FOLDER` | `//localhost/ecg` | Carpeta con los PDFs |
| `CREATE_DATE_FOLDERS` | `true` | Crear subcarpetas por fecha |
| `SKIP_DUP_CHECK` | `false` | Omitir verificación de duplicados |
| `MAX_WORKERS` | `2` | Número de hilos paralelas |
| `MAX_RETRIES` | `3` | Intentos de reintento |
| `BACKOFF_BASE_SEC` | `1.5` | Base del backoff exponencial |
| `MAX_FILE_MB` | `50` | Tamaño máximo del archivo (MB) |
| `EXAM_TYPE` | `INFORME_MEDICO` | Tipo del examen |
| `EXAM_MODALITY` | `OT` | Modalidad DICOM |
| `INSTITUTION_NAME` | `HOSPITAL DIGITAL` | Nombre de la institución |
| `REFERRING_PHYSICIAN` | `AUTOMATIZADO` | Médico solicitante |
| `PDFFLOW_LOG` | `{PDF_SOURCE_FOLDER}/pdftoorthanc.log` | Ruta del log |

### Ejemplo de configuración con archivo `.env`

```bash
# .env
ORTHANC_URL=http://tu-servidor-orthanc:8042
ORTHANC_USER=admin
ORTHANC_PASSWORD=contraseña_segura
PDF_SOURCE_FOLDER=/ruta/a/pdfs
MAX_WORKERS=4
MAX_FILE_MB=100
INSTITUTION_NAME=Hospital XYZ
```

## 🖥️ Uso

### Ejecución básica

```bash
python PDFtoOrthanc_ES.py
```

### Ejecución con variables de entorno

```bash
export ORTHANC_URL="http://192.168.1.100:8042"
export PDF_SOURCE_FOLDER="/datos/informes"
export MAX_WORKERS=4
python PDFtoOrthanc_ES.py
```

## 🔍 Verificación de Duplicados

El script verifica duplicados usando cuatro métodos diferentes:

1. **AccessionNumber** (más confiable)
2. **PatientID + StudyDate**
3. **PatientName (formato DICOM) + StudyDate**
4. **PatientName (formato natural) + StudyDate**

## 📊 Logs

Los logs se generan en formato JSON estructurado:

```json
{
  "event": "procesamiento_inicio",
  "archivo": "12345_JUAN_SILVA_15012024_98765.pdf"
}
{
  "event": "enviado_exitoso",
  "archivo": "12345_JUAN_SILVA_15012024_98765.pdf",
  "size_mb": 2.5,
  "instance_id": "abc123-def456-ghi789"
}
```

### Principales eventos de log:

- `procesamiento_inicio`: Inicio del procesamiento
- `corrupted_pdf`: PDF corrupto detectado
- `duplicado_encontrado`: Duplicado encontrado
- `enviado_exitoso`: Envío exitoso
- `envio_fallido`: Error en el envío
- `resumen`: Resumen final

## 🛡️ Validaciones

### Validación de PDF
- Verifica cabecera `%PDF-`
- Verifica marcador de fin `%%EOF`
- Tamaño mínimo de 1KB

### Validación de Nombres
- Elimina acentos y caracteres especiales
- Convierte a mayúsculas
- Valida formato de nombres

### Validación de Datos
- PatientID: solo números
- Fechas: formato DDMMAAAA o DDMMAA
- AccessionNumber: solo números

## 🔧 Solución de Problemas

### Problemas Comunes

**Error de conexión con Orthanc:**
```
Falla en la conexión con Orthanc: Connection refused
```
- Verifica que Orthanc esté ejecutándose
- Confirma URL, usuario y contraseña
- Prueba conectividad de red

**Archivo movido a carpeta "Errores":**
- Verifica el formato del nombre del archivo
- Confirma que el PDF no esté corrupto
- Consulta los logs para detalles

**Rendimiento lento:**
- Ajusta `MAX_WORKERS` según el hardware
- Verifica latencia de red con Orthanc
- Considera `MAX_FILE_MB` si los archivos son grandes

## 📈 Rendimiento

### Recomendaciones de Hardware

- **CPU**: 2+ núcleos para procesamiento paralelo
- **RAM**: 2GB+ (basado en el tamaño de los PDFs)
- **Red**: Latencia baja con servidor Orthanc
- **Disco**: SSD para I/O rápido

### Configuraciones de Rendimiento

```bash
# Para servidor dedicado
MAX_WORKERS=8
MAX_FILE_MB=100

# Para ambiente compartido
MAX_WORKERS=2
MAX_FILE_MB=50
```

## 🤝 Integración con PORTAL_ESTUDIOS

Para más información sobre cómo integrar esta herramienta con el sistema PORTAL_ESTUDIOS, consulta:

`docs/analisis-pdftoorthanc-integracion.md`

Este documento incluye:
- Análisis de las diferentes formas de integración
- Plan recomendado de implementación
- Ejemplos de código PHP para integración directa

## 📄 Licencia

Este proyecto está licenciado bajo la GNU General Public License v3.0 (GPLv3) - consulta el archivo [LICENSE](LICENSE) para más detalles.

---

**Desarrollado con ❤️ para facilitar la integración de documentos médicos con sistemas PACS**

