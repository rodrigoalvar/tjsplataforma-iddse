<?php
/**
 * Bootstrap del módulo hl7-worklist.
 */

if (!defined('HL7_WORKLIST_MODULE_ROOT')) {
    define('HL7_WORKLIST_MODULE_ROOT', dirname(__DIR__));
}

require_once __DIR__ . '/Hl7OrmWorklistParser.php';
require_once __DIR__ . '/Hl7WorklistModule.php';
