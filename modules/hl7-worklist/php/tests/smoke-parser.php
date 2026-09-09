<?php
/**
 * Smoke test del parser ORM^O01 con el mensaje de ejemplo del proveedor.
 * Uso: php php/tests/smoke-parser.php
 */

require_once dirname(__DIR__) . '/Hl7OrmWorklistParser.php';

$sample = "MSH|^~\\&|GASALUD|DCMSUITES|GRIS|DCMSUITES|20260805164229||ORM^O01|26080516422905848330|P|2.5|||NE|AL|ARG\r"
    . "EVN|R01|20260805164229\r"
    . "PID|1|36719655^^^^^DNI|164488^^^GASALUD||RAMIREZ REVIGLIONO^EZEQUIEL JOSUE||19920430|M|||||^^^ezequielrevigliono@gmail.com\r"
    . "PV1|1|O||L|||2132^DICHIARA MAURO DANIEL^DICHIARA MAURO DANIEL|25963^ABALOS GOROSTIAGA RAUL E      ^ABALOS GOROSTIAGA RAUL E      |||||||||||53213.8|||||||||||||||||||||||||20260805164100\r"
    . "IN1|1|||SWISS MEDICAL||||||||||||||||||||||||||||||||123|||||||||||||||\r"
    . "ORC|NW|53213.8|||||1^^10^20260805084000^20260805085000||||||N|\r"
    . "OBR|1|53213.8^GASALUD||128^ARPON||||||||||||||MAMOGRAFIA 1|8|||||MG|||1^^10^20260805084000^20260805085000\r"
    . "NTE|||\r"
    . "IPC|53213.8^GASALUD||||MG||MAMOGRAFIA 1\r";

$errors = 0;

function assertEq($label, $expected, $actual, &$errors): void
{
    if ((string)$expected !== (string)$actual) {
        echo "FAIL $label: expected [$expected] got [$actual]\n";
        $errors++;
    } else {
        echo "OK   $label = $actual\n";
    }
}

$data = Hl7OrmWorklistParser::parseContent($sample, 'PV1-8');
assertEq('accession', '53213.8', $data['accession_number'], $errors);
assertEq('patient_id', '36719655', $data['patient_id'], $errors);
assertEq('patient_name', 'RAMIREZ REVIGLIONO EZEQUIEL JOSUE', $data['patient_name'], $errors);
assertEq('dob', '1992-04-30', $data['patient_birth_date'], $errors);
assertEq('sex', 'M', $data['patient_sex'], $errors);
assertEq('modality', 'MG', $data['modality'], $errors);
assertEq('procedure', 'MAMOGRAFIA 1', $data['procedure_description'], $errors);
assertEq('scheduled_date', '2026-08-05', $data['scheduled_date'], $errors);
assertEq('scheduled_time', '08:40:00', $data['scheduled_time'], $errors);
assertEq('prestador PV1-8', '25963', $data['referring_physician'], $errors);

$data7 = Hl7OrmWorklistParser::parseContent($sample, 'PV1-7');
assertEq('prestador PV1-7', '2132', $data7['referring_physician'], $errors);

$dataNone = Hl7OrmWorklistParser::parseContent($sample, 'none');
if ($dataNone['referring_physician'] !== null) {
    echo "FAIL prestador none should be null\n";
    $errors++;
} else {
    echo "OK   prestador none = null\n";
}

$msgid = Hl7OrmWorklistParser::extractMessageControlId($sample);
assertEq('msg control id', '26080516422905848330', $msgid, $errors);
assertEq('order_control NW', 'NW', $data['order_control'] ?? '', $errors);

$sampleCa = "MSH|^~\\&|GASALUD|IDDSE|GRIS|IDDSE|20260909161432||ORM^O01|26090916143205848330|P|2.5|||NE|AL|ARG\r"
    . "PID|1|36719655^^^^^DNI|164488^^^GASALUD||RAMIREZ REVIGLIONO^EZEQUIEL JOSUE||19920430|M\r"
    . "PV1|1|O||L|||134625^ENRICO CASCO^ENRICO CASCO||||||||||||91514.1|||||||||||||||||||||||||20260909150700\r"
    . "ORC|CA|91514.1|||||1^^7^20260909115800^20260909120500\r"
    . "OBR|1|91514.1^GASALUD||5^ARTICULACION TEMPOROMANDIBULAR, 3 PO||||||||||||||RAYOS|1|||||CR|||1^^7^20260909115800^20260909120500\r"
    . "IPC|91514.1^GASALUD||||CR||RAYOS\r"
    . "EVN|R01|20260909161432\r";

$dataCa = Hl7OrmWorklistParser::parseContent($sampleCa, 'PV1-8');
assertEq('CA accession', '91514.1', $dataCa['accession_number'], $errors);
assertEq('CA order_control', 'CA', $dataCa['order_control'] ?? '', $errors);
assertEq('CA status', 'cancelled', $dataCa['status'] ?? '', $errors);
if (!Hl7OrmWorklistParser::isCancelOrderControl($dataCa['order_control'] ?? null)) {
    echo "FAIL isCancelOrderControl CA\n";
    $errors++;
} else {
    echo "OK   isCancelOrderControl CA\n";
}

if ($errors > 0) {
    echo "\n$errors error(s)\n";
    exit(1);
}
echo "\nSmoke test OK\n";
exit(0);
