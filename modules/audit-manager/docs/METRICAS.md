# Métricas: servidor vs red vs cliente

- **`rtt_ms`** (ping): tiempo ida y vuelta del navegador al endpoint liviano. Si es alto y el procesamiento en servidor del ping es bajo, suele indicar latencia de red o dispositivo lento.
- **`server_processing_ms` en `record.php`**: tiempo que tardó **solo** el script de registro; es una cota inferior trivial. Para acciones reales, conviene que el endpoint de negocio mida su propio tiempo y lo envíe en `metadata` o en una extensión futura.
- **`client_duration_ms`**: tiempo total en el cliente (por ejemplo desde inicio de `fetch` hasta fin). Si es mucho mayor que RTT + tiempo de servidor declarado, puede haber trabajo pesado en JS o render.

Estas métricas son **heurísticas operativas**, no un diagnóstico de red de laboratorio.
