<?php
/**
 * Parser para archivos TXT de Worklist DICOM
 * Sistema TJSMEDICAL - Portal de Estudios Médicos
 * 
 * Formato esperado:
 * [Message]
 * Type=Add
 * [DicomData]
 * (0008.0050)=581309
 * (0008.0060)=MR
 * ...
 */

class TxtWorklistParser {
    
    /**
     * Parsea un archivo TXT de worklist
     * 
     * @param string $filePath Ruta al archivo TXT
     * @return array Datos parseados o null si hay error
     */
    public static function parseFile($filePath) {
        if (!file_exists($filePath)) {
            throw new Exception("El archivo no existe: $filePath");
        }
        
        $content = file_get_contents($filePath);
        if ($content === false) {
            throw new Exception("No se pudo leer el archivo: $filePath");
        }
        
        return self::parseContent($content);
    }
    
    /**
     * Parsea el contenido de un archivo TXT de worklist
     * 
     * @param string $content Contenido del archivo
     * @return array Datos parseados
     */
    public static function parseContent($content) {
        $lines = explode("\n", $content);
        $data = [];
        $inDicomData = false;
        
        foreach ($lines as $line) {
            $line = trim($line);
            
            // Ignorar líneas vacías
            if (empty($line)) {
                continue;
            }
            
            // Detectar sección DicomData
            if (preg_match('/^\[DicomData\]/i', $line)) {
                $inDicomData = true;
                continue;
            }
            
            // Solo procesar líneas dentro de DicomData
            if (!$inDicomData) {
                continue;
            }
            
            // Parsear líneas con formato (TAG)=VALOR
            if (preg_match('/^\(([0-9A-F]{4}\.[0-9A-F]{4})\)=(.+)$/i', $line, $matches)) {
                $tag = $matches[1];
                $value = trim($matches[2]);
                
                // Mapear tags DICOM a campos de la base de datos
                $data = array_merge($data, self::mapDicomTag($tag, $value));
            }
        }
        
        // Validar campos requeridos
        if (empty($data['accession_number'])) {
            throw new Exception("El campo accession_number es requerido");
        }
        
        if (empty($data['scheduled_date'])) {
            throw new Exception("El campo scheduled_date es requerido");
        }
        
        if (empty($data['scheduled_time'])) {
            throw new Exception("El campo scheduled_time es requerido");
        }
        
        return $data;
    }
    
    /**
     * Mapea un tag DICOM a un campo de la base de datos
     * 
     * @param string $tag Tag DICOM (ej: 0008.0050)
     * @param string $value Valor del tag
     * @return array Array con los campos mapeados
     */
    private static function mapDicomTag($tag, $value) {
        $mapping = [
            '0008.0050' => 'accession_number',      // Accession Number
            '0008.0060' => 'modality',               // Modality
            '0008.0090' => 'referring_physician',    // Referring Physician
            '0010.0010' => 'patient_name',          // Patient Name
            '0010.0020' => 'patient_id',            // Patient ID
            '0010.0030' => 'patient_birth_date',    // Patient Birth Date
            '0010.0040' => 'patient_sex',           // Patient Sex
            '0040.0001' => 'equipment_name',        // Scheduled Station AE Title
            '0040.0002' => 'scheduled_date',        // Scheduled Procedure Step Start Date
            '0040.0003' => 'scheduled_time',        // Scheduled Procedure Step Start Time
            '0040.0007' => 'procedure_description', // Scheduled Procedure Step Description
            '0032.1060' => 'reason_for_study'       // Requested Procedure Description
        ];
        
        $field = $mapping[$tag] ?? null;
        
        if (!$field) {
            return [];
        }
        
        $result = [];
        
        // Procesar según el tipo de campo
        switch ($field) {
            case 'patient_name':
                // Formato: APELLIDO^NOMBRE → convertir a formato normal
                $result['patient_name'] = str_replace('^', ' ', $value);
                break;
                
            case 'patient_birth_date':
                // Formato: YYYYMMDD → DATE
                if (preg_match('/^(\d{4})(\d{2})(\d{2})$/', $value, $matches)) {
                    $result['patient_birth_date'] = $matches[1] . '-' . $matches[2] . '-' . $matches[3];
                }
                break;
                
            case 'scheduled_date':
                // Formato: YYYYMMDD → DATE
                if (preg_match('/^(\d{4})(\d{2})(\d{2})$/', $value, $matches)) {
                    $result['scheduled_date'] = $matches[1] . '-' . $matches[2] . '-' . $matches[3];
                }
                break;
                
            case 'scheduled_time':
                // Formato: HHMMSS → TIME
                if (preg_match('/^(\d{2})(\d{2})(\d{2})$/', $value, $matches)) {
                    $result['scheduled_time'] = $matches[1] . ':' . $matches[2] . ':' . $matches[3];
                }
                break;
                
            case 'patient_sex':
                // Normalizar a M, F, O
                $sex = strtoupper(substr($value, 0, 1));
                if (!in_array($sex, ['M', 'F', 'O'])) {
                    $sex = 'O';
                }
                $result['patient_sex'] = $sex;
                break;
                
            default:
                $result[$field] = $value;
                break;
        }
        
        return $result;
    }
    
    /**
     * Valida los datos parseados
     * 
     * @param array $data Datos a validar
     * @return bool True si es válido
     * @throws Exception Si hay errores de validación
     */
    public static function validate($data) {
        $required = ['accession_number', 'scheduled_date', 'scheduled_time'];
        
        foreach ($required as $field) {
            if (empty($data[$field])) {
                throw new Exception("El campo $field es requerido");
            }
        }
        
        // Validar formato de fecha
        if (!empty($data['scheduled_date'])) {
            $date = DateTime::createFromFormat('Y-m-d', $data['scheduled_date']);
            if (!$date || $date->format('Y-m-d') !== $data['scheduled_date']) {
                throw new Exception("Formato de fecha inválido: " . $data['scheduled_date']);
            }
        }
        
        // Validar formato de hora
        if (!empty($data['scheduled_time'])) {
            $time = DateTime::createFromFormat('H:i:s', $data['scheduled_time']);
            if (!$time || $time->format('H:i:s') !== $data['scheduled_time']) {
                throw new Exception("Formato de hora inválido: " . $data['scheduled_time']);
            }
        }
        
        return true;
    }
}
