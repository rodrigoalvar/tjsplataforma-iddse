<?php
/**
 * Generador de archivos DICOM Worklist (.wl)
 * Sistema TJSMEDICAL - Portal de Estudios Médicos
 * 
 * Genera archivos en formato .wl para Orthanc
 */

class DicomWorklistGenerator {
    
    /**
     * Genera un archivo .wl a partir de datos de worklist
     * 
     * @param array $data Datos del worklist
     * @param string $outputPath Ruta donde guardar el archivo
     * @return string Ruta del archivo generado
     * @throws Exception Si hay error al generar
     */
    public static function generate($data, $outputPath = null) {
        // Validar datos requeridos
        if (empty($data['accession_number'])) {
            throw new Exception('accession_number es requerido');
        }
        
        // Si no se especifica ruta, usar el accession_number como nombre
        if (!$outputPath) {
            $outputPath = sys_get_temp_dir() . '/' . $data['accession_number'] . '.wl';
        }
        
        // Generar contenido del archivo .wl
        $content = self::generateContent($data);
        
        // Escribir archivo
        $dir = dirname($outputPath);
        if (!is_dir($dir)) {
            if (!mkdir($dir, 0755, true)) {
                throw new Exception("No se pudo crear el directorio: $dir");
            }
        }
        
        if (file_put_contents($outputPath, $content) === false) {
            throw new Exception("No se pudo escribir el archivo: $outputPath");
        }
        
        return $outputPath;
    }
    
    /**
     * Genera el contenido del archivo .wl
     * 
     * @param array $data Datos del worklist
     * @return string Contenido del archivo
     */
    private static function generateContent($data) {
        $lines = [];
        
        // Encabezado
        $lines[] = '[Message]';
        $lines[] = 'Type=Add';
        $lines[] = '';
        $lines[] = '[DicomData]';
        
        // Mapear campos a tags DICOM
        $mapping = [
            'accession_number' => ['0008.0050', $data['accession_number'] ?? ''],
            'modality' => ['0008.0060', $data['modality'] ?? ''],
            'referring_physician' => ['0008.0090', $data['referring_physician'] ?? ''],
            'patient_name' => ['0010.0010', self::formatPatientName($data['patient_name'] ?? '')],
            'patient_id' => ['0010.0020', $data['patient_id'] ?? ''],
            'patient_birth_date' => ['0010.0030', self::formatDate($data['patient_birth_date'] ?? '')],
            'patient_sex' => ['0010.0040', self::formatSex($data['patient_sex'] ?? '')],
            'equipment_name' => ['0040.0001', $data['equipment_name'] ?? ''],
            'scheduled_date' => ['0040.0002', self::formatDate($data['scheduled_date'] ?? '')],
            'scheduled_time' => ['0040.0003', self::formatTime($data['scheduled_time'] ?? '')],
            'procedure_description' => ['0040.0007', $data['procedure_description'] ?? ''],
            'reason_for_study' => ['0032.1060', $data['reason_for_study'] ?? '']
        ];
        
        // Agregar líneas con formato (TAG)=VALOR
        foreach ($mapping as $field => $tagData) {
            list($tag, $value) = $tagData;
            if (!empty($value)) {
                $lines[] = "($tag)=$value";
            }
        }
        
        return implode("\n", $lines);
    }
    
    /**
     * Formatea el nombre del paciente al formato DICOM (APELLIDO^NOMBRE)
     * 
     * @param string $name Nombre completo
     * @return string Nombre formateado
     */
    private static function formatPatientName($name) {
        // Si ya está en formato DICOM, mantenerlo
        if (strpos($name, '^') !== false) {
            return $name;
        }
        
        // Intentar separar apellido y nombre
        $parts = preg_split('/\s+/', trim($name), 2);
        if (count($parts) >= 2) {
            return $parts[0] . '^' . $parts[1];
        }
        
        return $name;
    }
    
    /**
     * Formatea una fecha al formato DICOM (YYYYMMDD)
     * 
     * @param string $date Fecha en formato YYYY-MM-DD
     * @return string Fecha en formato YYYYMMDD
     */
    private static function formatDate($date) {
        if (empty($date)) {
            return '';
        }
        
        // Si ya está en formato YYYYMMDD, mantenerlo
        if (preg_match('/^\d{8}$/', $date)) {
            return $date;
        }
        
        // Convertir de YYYY-MM-DD a YYYYMMDD
        $dateObj = DateTime::createFromFormat('Y-m-d', $date);
        if ($dateObj) {
            return $dateObj->format('Ymd');
        }
        
        return '';
    }
    
    /**
     * Formatea una hora al formato DICOM (HHMMSS)
     * 
     * @param string $time Hora en formato HH:MM:SS
     * @return string Hora en formato HHMMSS
     */
    private static function formatTime($time) {
        if (empty($time)) {
            return '';
        }
        
        // Si ya está en formato HHMMSS, mantenerlo
        if (preg_match('/^\d{6}$/', $time)) {
            return $time;
        }
        
        // Convertir de HH:MM:SS a HHMMSS
        $timeObj = DateTime::createFromFormat('H:i:s', $time);
        if ($timeObj) {
            return $timeObj->format('His');
        }
        
        // Intentar HH:MM
        $timeObj = DateTime::createFromFormat('H:i', $time);
        if ($timeObj) {
            return $timeObj->format('His');
        }
        
        return '';
    }
    
    /**
     * Formatea el sexo al formato DICOM (M, F, O)
     * 
     * @param string $sex Sexo
     * @return string Sexo formateado
     */
    private static function formatSex($sex) {
        if (empty($sex)) {
            return '';
        }
        
        $sex = strtoupper(substr($sex, 0, 1));
        if (!in_array($sex, ['M', 'F', 'O'])) {
            return 'O';
        }
        
        return $sex;
    }
}
