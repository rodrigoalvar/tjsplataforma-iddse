<?php
/**
 * CORS para manifest / proxy WADO cuando el visor está en otro host (ej. webportal → plataforma).
 * El UDV puede enviar Authorization en XHR; el preflight OPTIONS debe listarla en Allow-Headers.
 */
class StudyHistoryCors {
    public static function allowHeaders() {
        return 'Content-Type, Range, Authorization, Accept, X-Requested-With';
    }

    public static function sendAllowOriginAndHeaders() {
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Headers: ' . self::allowHeaders());
    }
}
