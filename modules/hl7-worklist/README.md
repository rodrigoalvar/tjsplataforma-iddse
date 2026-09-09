# Módulo HL7 Worklist (MLLP)

Receptor **HL7 v2 ORM^O01** por TCP/MLLP que inserta/actualiza la **worklist** DICOM de TJSMEDICAL.

- Sin Mirth
- Configurable desde **Configuración → Worklist**
- Coexiste con PULL `.txt`
- Versión: **1.0.0**

## Instalación rápida

```bash
php /var/www/tjsiddse/modules/hl7-worklist/install.php
php /var/www/tjsiddse/modules/hl7-worklist/php/tests/smoke-parser.php
```

Servicio:

```bash
sudo cp modules/hl7-worklist/listener/hl7-mllp-listener.service.example /etc/systemd/system/hl7-mllp-listener.service
sudo systemctl daemon-reload
sudo systemctl enable --now hl7-mllp-listener
```

Documentación de portabilidad: [INTEGRATION.md](INTEGRATION.md)  
Manual técnico (portal): `/doc/tecnico/hl7-worklist-modulo.md`  
Manual usuario: `/doc/usuario/worklist-hl7-recepcion.md`

## Flujo

1. RIS → MLLP (`hl7_bind_host:hl7_port`)
2. Listener ACK `MSA|AA|…` y guarda `.hl7` en inbox
3. `hl7-process-one.php` → `WorklistIngestionService::ingestHl7Content`
4. BD `worklist` + sync Orthanc (mismo pipeline que TXT)
