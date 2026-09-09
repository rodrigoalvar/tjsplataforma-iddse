#!/usr/bin/env python3
# -*- coding: utf-8 -*-
#
# PDFtoOrthanc_ES.py — Conversión automática de PDFs médicos a DICOM con envío al Orthanc PACS
#
# Traducción al español del script original PDFtoOrthanc.py
# Copyright (C) 2025  Lucas Weber (original)
# Traducción: Adaptado para PORTAL_ESTUDIOS
#
# Este programa es software libre: puedes redistribuirlo y/o modificarlo
# bajo los términos de la Licencia Pública General GNU publicada por la
# Free Software Foundation, versión 3 de la licencia o (a tu criterio) cualquier
# versión posterior.
#
# Este programa se distribuye con la esperanza de que sea útil,
# pero SIN NINGUNA GARANTÍA; sin siquiera la garantía implícita de
# COMERCIABILIDAD o ADECUACIÓN A UN PROPÓSITO PARTICULAR.
# Consulta la Licencia Pública General GNU para más detalles.
#
# Debes haber recibido una copia de la Licencia Pública General GNU
# junto con este programa. Si no, consulta <https://www.gnu.org/licenses/>.


"""
PDFtoOrthanc (Versión en Español)
---------------------------------
Herramienta para convertir archivos PDF en objetos DICOM Encapsulated PDF
y enviarlos automáticamente a un servidor Orthanc a través de la REST API.

Autor original: Lucas Weber
Repositorio: https://github.com/byweber/PDFtoOrthanc
Licencia: GPLv3 o posterior
Traducción: Adaptado para PORTAL_ESTUDIOS
"""

import os
import re
import base64
import shutil
import datetime as dt
import logging
from logging.handlers import RotatingFileHandler
import json
import unicodedata
from concurrent.futures import ThreadPoolExecutor, as_completed
from typing import Dict, Any, Tuple, List

import requests

# -------------------------- CONFIGURACIONES (via ENV) --------------------------
ORTHANC_URL = os.getenv("ORTHANC_URL", "http://localhost:8042").rstrip("/")
ORTHANC_USER = os.getenv("ORTHANC_USER", "orthanc")
ORTHANC_PASSWORD = os.getenv("ORTHANC_PASSWORD", "orthanc")
PDF_SOURCE_FOLDER = os.getenv("PDF_SOURCE_FOLDER", "//localhost/ecg")

PROCESSED_PATH = os.path.join(PDF_SOURCE_FOLDER, "Procesados")
ERROR_PATH = os.path.join(PDF_SOURCE_FOLDER, "Errores")
DUPLICATE_PATH = os.path.join(PDF_SOURCE_FOLDER, "Duplicados")
LOG_PATH = os.getenv("PDFFLOW_LOG", os.path.join(PDF_SOURCE_FOLDER, "pdftoorthanc.log"))

CREATE_DATE_FOLDERS = os.getenv("CREATE_DATE_FOLDERS", "true").lower() == "true"
SKIP_DUP_CHECK = os.getenv("SKIP_DUP_CHECK", "false").lower() == "true"
MAX_WORKERS = int(os.getenv("MAX_WORKERS", "2"))  # Ajustar según capacidad del servidor
MAX_RETRIES = int(os.getenv("MAX_RETRIES", "3"))
BACKOFF_BASE_SEC = float(os.getenv("BACKOFF_BASE_SEC", "1.5"))
MAX_FILE_MB = float(os.getenv("MAX_FILE_MB", "50"))  # Preventivo, ajustar según Orthanc

SOPCLASS_PDF = '1.2.840.10008.5.1.4.1.1.104.1'

FIXED_EXAM = {
    "Type": os.getenv("EXAM_TYPE", "INFORME_MEDICO"),
    "Modality": os.getenv("EXAM_MODALITY", "OT")
}

INSTITUTION_NAME = os.getenv("INSTITUTION_NAME", "HOSPITAL DIGITAL")
REFERRING_PHYSICIAN = os.getenv("REFERRING_PHYSICIAN", "AUTOMATIZADO")

# -------------------------- LOGGING --------------------------
logger = logging.getLogger("pdftoorthanc")
logger.setLevel(logging.INFO)

fmt = logging.Formatter('%(asctime)s %(levelname)s %(message)s')
# Consola
ch = logging.StreamHandler()
ch.setFormatter(fmt)
logger.addHandler(ch)
# Archivo rotativo
os.makedirs(os.path.dirname(LOG_PATH), exist_ok=True)
fh = RotatingFileHandler(LOG_PATH, maxBytes=5 * 1024 * 1024, backupCount=5, encoding='utf-8')
fh.setFormatter(fmt)
logger.addHandler(fh)


def jlog(level: str, **fields):
    """Log en formato JSON (campo 'msg' opcional)."""
    msg = json.dumps(fields, ensure_ascii=False)
    logger.log(getattr(logging, level.upper(), logging.INFO), msg)


# -------------------------- UTILIDADES --------------------------
REGEX_ID = re.compile(r'^\d+$')  # Solo dígitos
REGEX_DATE = re.compile(r'^(\d{6}|\d{8})$')  # 6 u 8 dígitos


def ensure_dirs():
    """Crear directorios necesarios si no existen."""
    for d in [PROCESSED_PATH, ERROR_PATH, DUPLICATE_PATH]:
        os.makedirs(d, exist_ok=True)


def build_date_folder_path(base: str, study_date: str) -> str:
    """Construir ruta de carpeta por fecha si está habilitado."""
    if CREATE_DATE_FOLDERS and study_date and len(study_date) >= 8:
        date_folder = f"{study_date[0:4]}-{study_date[4:6]}-{study_date[6:8]}"
        return os.path.join(base, date_folder)
    return base


def normalize_name_token(token: str) -> str:
    """Normalizar token de nombre: eliminar acentos, mantener letras y espacios."""
    token = token.strip()
    token = unicodedata.normalize('NFKD', token)
    token = ''.join(ch for ch in token if not unicodedata.combining(ch))
    token = re.sub(r"[^A-Za-z\s]", " ", token)  # Remover números y puntuación
    token = re.sub(r"\s+", " ", token).strip()
    return token.upper()


def is_valid_name_part(token: str) -> bool:
    """Validar que el token sea una parte válida de nombre (solo letras mayúsculas y espacios)."""
    return bool(token) and re.fullmatch(r"[A-Z ]{1,}", token) is not None


def format_dicom_date(date_str: str) -> str:
    """
    Retorna YYYYMMDD. Soporta DDMMYY, DDMMYYYY, YYYYMMDD. 
    Si YY >=70 => 19xx, si no 20xx.
    """
    if not date_str or not REGEX_DATE.match(date_str):
        return dt.datetime.now().strftime('%Y%m%d')
    try:
        if len(date_str) == 6:
            dd, mm, yy = int(date_str[0:2]), int(date_str[2:4]), int(date_str[4:6])
            year = 1900 + yy if yy >= 70 else 2000 + yy
            d = dt.date(year, mm, dd)
        elif len(date_str) == 8:
            if date_str[:4] in ("19" + date_str[6:8], "20" + date_str[6:8]):
                # Ya puede estar en YYYYMMDD
                year, mm, dd = int(date_str[0:4]), int(date_str[4:6]), int(date_str[6:8])
                d = dt.date(year, mm, dd)
            else:
                # Asumir DDMMYYYY
                dd, mm, year = int(date_str[0:2]), int(date_str[2:4]), int(date_str[4:8])
                d = dt.date(year, mm, dd)
        else:
            d = dt.date.today()
        return d.strftime('%Y%m%d')
    except Exception:
        return dt.datetime.now().strftime('%Y%m%d')


def move_file_safe(source: str, dest_folder: str, study_date: str) -> str:
    """Mover archivo de forma segura, creando directorio si es necesario."""
    final_folder = build_date_folder_path(dest_folder, study_date)
    os.makedirs(final_folder, exist_ok=True)
    filename = os.path.basename(source)
    dest = os.path.join(final_folder, filename)
    if os.path.exists(dest):
        ts = dt.datetime.now().strftime('%H%M%S')
        base, ext = os.path.splitext(filename)
        dest = os.path.join(final_folder, f"{base}-{ts}{ext}")
    shutil.move(source, dest)
    jlog("info", event="archivo_movido", src=source, dest=dest)
    return dest


# -------------------------- PARSING DE ARCHIVOS --------------------------

def _parse_dicom_name_parts(name_parts: List[str]) -> Tuple[str, str, str]:
    """
    Extrae el primer, medio y último nombre de una lista de partes del nombre.
    Lógica: LastName^FirstName^MiddleName.
    Ej: ['JUAN', 'PEDRO', 'SILVA', 'SANTOS'] -> ('JUAN PEDRO', 'SILVA', 'SANTOS')
    """
    first_name = 'PACIENTE'
    middle_name = ''
    last_name = 'NOMBRE'

    if not name_parts:
        return first_name, middle_name, last_name

    if len(name_parts) == 1:
        # Si hay solo una parte, se asume que es el apellido.
        last_name = name_parts[0]
    else:  # 2 o más partes
        last_name = name_parts[-1]  # El último item es el Apellido (LastName)
        first_name = name_parts[0]  # El primer item es el Nombre (FirstName)
        if len(name_parts) > 2:
            # Lo que esté entre el primero y el último es el Nombre del Medio (MiddleName)
            middle_name = ' '.join(name_parts[1:-1])

    return first_name, middle_name, last_name


def validate_parts(parts):
    """Validar formato estructurado de nombre de archivo."""
    # Para el formato ESTRUCTURADO, el mínimo de partes ahora es 5 (ID_Nombre_Apellido_Fecha_Acc)
    if len(parts) < 5:
        return 'Formato incompleto'
    patient_id = parts[0]
    date_str = parts[-2]
    acc_num = parts[-1]
    if not REGEX_ID.match(patient_id):
        return 'PatientID debe ser solo números'
    if not REGEX_DATE.match(date_str):
        return 'Fecha inválida'
    if not REGEX_ID.match(acc_num):
        return 'AccessionNumber debe ser solo números'
    # Validar nombre (tokens entre PatientID y Fecha)
    for p in parts[1:-2]:
        p_norm = normalize_name_token(p)
        if not is_valid_name_part(p_norm):
            return f"Nombre inválido en el campo: {p}"
    return None


def parse_filename(filename: str) -> Dict[str, Any]:
    """
    Parsear nombre de archivo para extraer información del paciente y estudio.
    Soporta formato estructurado y formato legado.
    """
    base = os.path.splitext(filename)[0]
    parts_raw = base.split('_')
    parts = [p.strip() for p in parts_raw if p.strip()]
    err = validate_parts(parts)
    if not err:
        patient_id = parts[0]
        date_str = parts[-2]
        accession_number = parts[-1]
        name_parts_raw = parts[1:-2]

        name_parts = [normalize_name_token(p) for p in name_parts_raw]
        first_name, middle_name, last_name = _parse_dicom_name_parts(name_parts)

        study_date = format_dicom_date(date_str)
        return {
            'IsValid': True,
            'Format': 'ESTRUTURADO',
            'PatientID': patient_id,
            'FirstName': first_name,
            'MiddleName': middle_name,
            'LastName': last_name,
            'DateString': date_str,
            'StudyDate': study_date,
            'AccessionNumber': accession_number,
            'HasIds': True,
            'Error': None
        }

    # LEGADO: buscar último token de fecha válido
    date_index = -1
    for i in range(len(parts) - 1, -1, -1):
        if REGEX_DATE.match(parts[i]):
            try:
                _ = format_dicom_date(parts[i])
                date_index = i
                break
            except Exception:
                continue

    if date_index > 0 and date_index >= 2:  # Necesita al menos Nombre_Apellido_Fecha
        name_parts_raw = parts[:date_index]
        name_parts = [normalize_name_token(p) for p in name_parts_raw]
        first_name, middle_name, last_name = _parse_dicom_name_parts(name_parts)

        study_date = format_dicom_date(parts[date_index])
        return {
            'IsValid': True,
            'Format': 'LEGADO',
            'PatientID': '',
            'FirstName': first_name,
            'MiddleName': middle_name,
            'LastName': last_name,
            'DateString': parts[date_index],
            'StudyDate': study_date,
            'AccessionNumber': '',
            'HasIds': False,
            'Error': None
        }

    return {
        'IsValid': False,
        'Format': 'INVALIDO',
        'PatientID': '',
        'FirstName': 'ERROR',
        'MiddleName': '',
        'LastName': 'FORMATO',
        'DateString': '',
        'StudyDate': dt.datetime.now().strftime('%Y%m%d'),
        'AccessionNumber': '',
        'HasIds': False,
        'Error': 'Formato inválido'
    }


# -------------------------- ORTHANC --------------------------

def get_auth_header(user: str, password: str) -> Dict[str, str]:
    """Generar headers de autenticación Basic para Orthanc."""
    headers = {}
    if user and password:
        auth = f"{user}:{password}"
        b64auth = base64.b64encode(auth.encode()).decode()
        headers['Authorization'] = f"Basic {b64auth}"
    return headers


def req_with_retry(method: str, url: str, session: requests.Session, headers: Dict[str, str],
                   json_body: Dict[str, Any] | None = None, timeout: float = 60.0) -> requests.Response:
    """Solicitud HTTP con reintentos y backoff exponencial."""
    last_exc = None
    for attempt in range(1, MAX_RETRIES + 1):
        try:
            r = session.request(method=method, url=url, headers=headers, json=json_body, timeout=timeout)
            r.raise_for_status()
            return r
        except Exception as e:
            last_exc = e
            wait = BACKOFF_BASE_SEC ** attempt
            jlog("warning", event="http_reintento", intento=attempt, espera_s=round(wait, 2), url=url, error=str(e))
            try:
                import time
                time.sleep(wait)
            except Exception:
                pass
    raise last_exc  # Se agotaron los reintentos


def test_orthanc_connection(url: str, headers: Dict[str, str]) -> Tuple[bool, str]:
    """Probar conexión con Orthanc y obtener versión."""
    with requests.Session() as s:
        try:
            r = req_with_retry("GET", f"{url}/system", s, headers, timeout=10)
            version = r.json().get('Version', '')
            return True, version
        except Exception as e:
            return False, str(e)


def find_duplicate(accession: str | None, patient_id: str | None,
                   patient_name_dicom: str | None, patient_name_natural: str | None,
                   study_date: str, url: str, headers: Dict[str, str]) -> Tuple[bool, str | None]:
    """
    Verificar duplicados con una jerarquía robusta:
    1. AccessionNumber
    2. PatientID + StudyDate
    3. PatientName (formato DICOM: APELLIDO^NOMBRE) + StudyDate
    4. PatientName (formato Natural: NOMBRE APELLIDO) + StudyDate
    """
    with requests.Session() as s:
        # 1) Por AccessionNumber
        if accession:
            try:
                body = {"Level": "Study", "Query": {"AccessionNumber": accession}}
                r = req_with_retry("POST", f"{url}/tools/find", s, {**headers, 'Content-Type': 'application/json'}, body, timeout=30)
                data = r.json()
                if data:
                    jlog("info", event="duplicado_encontrado", metodo="accession", valor=accession, study_id=data[0])
                    return True, data[0]
            except Exception as e:
                jlog("warning", event="busqueda_accession_fallida", accession=accession, error=str(e))

        # 2) Por PatientID + StudyDate
        if patient_id:
            try:
                body = {"Level": "Study", "Query": {"PatientID": patient_id, "StudyDate": study_date}}
                r = req_with_retry("POST", f"{url}/tools/find", s, {**headers, 'Content-Type': 'application/json'}, body, timeout=30)
                data = r.json()
                if data:
                    jlog("info", event="duplicado_encontrado", metodo="patient_id_fecha", valor=patient_id, study_id=data[0])
                    return True, data[0]
            except Exception as e:
                jlog("warning", event="busqueda_patient_id_fecha_fallida", patient_id=patient_id, study_date=study_date, error=str(e))

        # 3) Por PatientName (formato DICOM) + StudyDate
        if patient_name_dicom:
            try:
                body = {"Level": "Study", "Query": {"PatientName": patient_name_dicom, "StudyDate": study_date}}
                r = req_with_retry("POST", f"{url}/tools/find", s, {**headers, 'Content-Type': 'application/json'}, body, timeout=30)
                data = r.json()
                if data:
                    jlog("info", event="duplicado_encontrado", metodo="patient_name_dicom", valor=patient_name_dicom, study_id=data[0])
                    return True, data[0]
            except Exception as e:
                jlog("warning", event="busqueda_patient_name_dicom_fallida", name=patient_name_dicom, study_date=study_date, error=str(e))

        # 4) Por PatientName (formato Natural) + StudyDate - Fallback final
        if patient_name_natural and patient_name_natural.replace(" ", "") != patient_name_dicom.replace("^", ""):
            try:
                body = {"Level": "Study", "Query": {"PatientName": patient_name_natural, "StudyDate": study_date}}
                r = req_with_retry("POST", f"{url}/tools/find", s, {**headers, 'Content-Type': 'application/json'}, body, timeout=30)
                data = r.json()
                if data:
                    jlog("info", event="duplicado_encontrado", metodo="patient_name_natural", valor=patient_name_natural, study_id=data[0])
                    return True, data[0]
            except Exception as e:
                jlog("warning", event="busqueda_patient_name_natural_fallida", name=patient_name_natural, study_date=study_date, error=str(e))

    return False, None


def send_pdf_as_dicom(pdf_path: str, url: str, headers: Dict[str, str], tags: Dict[str, Any]) -> Dict[str, Any]:
    """
    Enviar PDF como objeto DICOM Encapsulated PDF a Orthanc.
    Convierte el PDF a base64 y lo envía usando la REST API de Orthanc.
    """
    size_mb = round(os.path.getsize(pdf_path) / (1024 * 1024), 2)
    if size_mb > MAX_FILE_MB:
        raise RuntimeError(f"PDF por encima del límite permitido: {size_mb} MB > {MAX_FILE_MB} MB")
    with open(pdf_path, 'rb') as f:
        pdf_bytes = f.read()
    pdf_b64 = base64.b64encode(pdf_bytes).decode()
    payload = {"Tags": tags, "Content": f"data:application/pdf;base64,{pdf_b64}"}
    timeout = max(60.0, 15.0 + size_mb * 1.5)  # timeout proporcional al tamaño
    with requests.Session() as s:
        r = req_with_retry("POST", f"{url}/tools/create-dicom", s, {**headers, 'Content-Type': 'application/json'},
                           payload, timeout=timeout)
        return r.json()


# -------------------------- PROCESAMIENTO --------------------------

def process_file(full_path: str, orthanc_url: str, headers: Dict[str, str]) -> Dict[str, Any]:
    """Procesar un archivo PDF individual."""
    name = os.path.basename(full_path)
    jlog("info", event="procesamiento_inicio", archivo=name)
    parsed = parse_filename(name)
    if not parsed['IsValid']:
        jlog("warning", event="formato_invalido", archivo=name, razon=parsed['Error'])
        moved = move_file_safe(full_path, ERROR_PATH, '')
        return {'Success': False, 'Skipped': True, 'Reason': 'Formato de archivo inválido', 'File': name,
                'MovedTo': moved}

    # Montar los dos formatos de nombre para búsqueda y para la tag
    pn_parts_for_search = [parsed.get('LastName', ''), parsed.get('FirstName', ''), parsed.get('MiddleName', '')]
    while pn_parts_for_search and not pn_parts_for_search[-1]:
        pn_parts_for_search.pop()
    patient_name_for_search = '^'.join(pn_parts_for_search)

    name_parts_for_tag = [parsed.get('FirstName', ''), parsed.get('MiddleName', ''), parsed.get('LastName', '')]
    patient_name_for_tag = ' '.join(part for part in name_parts_for_tag if part)

    if not SKIP_DUP_CHECK:
        # La llamada de la función ahora pasa los DOS formatos de nombre
        exists, study_id = find_duplicate(
            accession=parsed.get('AccessionNumber') or None,
            patient_id=parsed.get('PatientID') or None,
            patient_name_dicom=patient_name_for_search,
            patient_name_natural=patient_name_for_tag,
            study_date=parsed['StudyDate'],
            url=orthanc_url,
            headers=headers
        )
        if exists:
            jlog("info", event="duplicado_detectado", archivo=name, accession=parsed.get('AccessionNumber'),
                 study_id=study_id)
            moved = move_file_safe(full_path, DUPLICATE_PATH, parsed['StudyDate'])
            return {'Success': False, 'Skipped': True, 'Duplicate': True,
                    'AccessionNumber': parsed.get('AccessionNumber', ''), 'File': name, 'Reason': 'Estudio ya existe',
                    'MovedTo': moved}

    hhmmss = dt.datetime.now().strftime('%H%M%S')
    tags = {
        "PatientName": patient_name_for_tag,
        "StudyDescription": FIXED_EXAM['Type'],
        "StudyDate": parsed['StudyDate'],
        "StudyTime": hhmmss,
        "SeriesDescription": f"{FIXED_EXAM['Type']} - PDF",
        "SeriesDate": parsed['StudyDate'],
        "SeriesTime": hhmmss,
        "SeriesNumber": "1",
        "Modality": FIXED_EXAM['Modality'],
        "ContentDate": parsed['StudyDate'],
        "ContentTime": hhmmss,
        "InstanceNumber": "1",
        "InstitutionName": INSTITUTION_NAME,
        "ReferringPhysicianName": REFERRING_PHYSICIAN,
        "SOPClassUID": SOPCLASS_PDF
    }
    if parsed['PatientID']:
        tags['PatientID'] = parsed['PatientID']
    if parsed['AccessionNumber']:
        tags['AccessionNumber'] = parsed['AccessionNumber']

    try:
        resp = send_pdf_as_dicom(full_path, orthanc_url, headers, tags)
        size_mb = round(os.path.getsize(full_path) / (1024 * 1024), 2)
        jlog("info", event="enviado_exitoso", archivo=name, size_mb=size_mb, instance_id=resp.get('ID'))
        moved = move_file_safe(full_path, PROCESSED_PATH, parsed['StudyDate'])
        return {'Success': True, 'InstanceId': resp.get('ID'), 'FileSize': size_mb, 'File': name, 'MovedTo': moved}
    except Exception as e:
        jlog("error", event="envio_fallido", archivo=name, error=str(e))
        moved = move_file_safe(full_path, ERROR_PATH, '')
        return {'Success': False, 'Error': str(e), 'File': name, 'MovedTo': moved}


# -------------------------- PRINCIPAL --------------------------

def main():
    logger.info("=== PDF para Orthanc v2 (Python) - Versión en Español ===")

    folder = PDF_SOURCE_FOLDER
    if not os.path.isdir(folder):
        logger.error(f"Carpeta inválida o no encontrada: {folder}. Saliendo.")
        return

    ensure_dirs()

    headers = get_auth_header(ORTHANC_USER, ORTHANC_PASSWORD)
    connected, info = test_orthanc_connection(ORTHANC_URL, headers)
    if not connected:
        logger.error(f"Falla en la conexión con Orthanc: {info}")
        return
    logger.info(f"Conectado al Orthanc, versión: {info}")

    files = [f for f in os.listdir(folder) if f.lower().endswith('.pdf')]
    if len(files) == 0:
        logger.warning("Ningún archivo PDF encontrado en la carpeta.")
        return

    logger.info(f"Archivos encontrados: {len(files)} | workers={MAX_WORKERS}")

    total_ok = 0
    total_dup = 0
    total_err = 0

    file_paths = [os.path.join(folder, f) for f in files]

    if MAX_WORKERS > 1:
        with ThreadPoolExecutor(max_workers=MAX_WORKERS) as ex:
            futures = {ex.submit(process_file, p, ORTHANC_URL, headers): p for p in file_paths}
            for fut in as_completed(futures):
                res = fut.result()
                if res.get('Success'):
                    total_ok += 1
                elif res.get('Duplicate'):
                    total_dup += 1
                else:
                    total_err += 1
    else:
        for p in file_paths:
            res = process_file(p, ORTHANC_URL, headers)
            if res.get('Success'):
                total_ok += 1
            elif res.get('Duplicate'):
                total_dup += 1
            else:
                total_err += 1

    summary = {"procesados": total_ok, "Duplicados": total_dup, "errores": total_err}
    jlog("info", event="resumen", **summary)
    print("Resumen del procesamiento:")
    print(f"  Procesados con éxito: {total_ok}")
    print(f"  Duplicados omitidos: {total_dup}")
    print(f"  Errores: {total_err}")


if __name__ == "__main__":
    main()

