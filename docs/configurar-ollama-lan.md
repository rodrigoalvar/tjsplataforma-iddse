# Configurar Ollama para Acceso por LAN/WAN

## Problema

Por defecto, Ollama solo escucha en `localhost` (127.0.0.1), lo que significa que solo acepta conexiones desde la misma máquina. Para permitir acceso desde otras máquinas en la red (LAN) o desde Internet (WAN), necesitas configurar Ollama para que escuche en todas las interfaces de red.

## Solución

### Opción 1: Escuchar en todas las interfaces (Recomendada)

Configura Ollama para escuchar en `0.0.0.0`, lo que permite conexiones desde cualquier IP:

```bash
export OLLAMA_HOST=0.0.0.0:11434
```

### Opción 2: Escuchar en IP específica de la LAN

Si prefieres limitar a una IP específica de tu red:

```bash
export OLLAMA_HOST=192.168.0.33:11434
```

## Hacer la Configuración Permanente

### Método 1: Archivo de entorno del usuario

Agrega la variable al archivo `~/.bashrc` o `~/.profile`:

```bash
echo 'export OLLAMA_HOST=0.0.0.0:11434' >> ~/.bashrc
source ~/.bashrc
```

### Método 2: Servicio systemd (Recomendado para producción)

1. Editar el archivo de servicio de Ollama:

```bash
sudo systemctl edit ollama
```

2. Agregar la siguiente configuración:

```ini
[Service]
Environment="OLLAMA_HOST=0.0.0.0:11434"
```

3. Recargar y reiniciar el servicio:

```bash
sudo systemctl daemon-reload
sudo systemctl restart ollama
```

### Método 3: Archivo de entorno del sistema

Crear o editar `/etc/systemd/system/ollama.service.d/override.conf`:

```ini
[Service]
Environment="OLLAMA_HOST=0.0.0.0:11434"
```

Luego:

```bash
sudo systemctl daemon-reload
sudo systemctl restart ollama
```

## Verificar la Configuración

1. Verificar que Ollama esté escuchando en la red:

```bash
netstat -tlnp | grep 11434
# O
ss -tlnp | grep 11434
```

Deberías ver algo como:
```
tcp  0  0  0.0.0.0:11434  0.0.0.0:*  LISTEN
```

Si ves `127.0.0.1:11434`, Ollama aún está escuchando solo en localhost.

2. Probar conexión desde otra máquina:

```bash
curl http://192.168.0.33:11434/api/tags
```

## Seguridad

⚠️ **IMPORTANTE**: Al exponer Ollama en la red, considera:

1. **Firewall**: Configura reglas de firewall para limitar acceso solo a IPs confiables:
   ```bash
   sudo ufw allow from 192.168.0.0/24 to any port 11434
   ```

2. **Autenticación**: Ollama no tiene autenticación por defecto. Si expones a Internet, considera usar un proxy reverso con autenticación (nginx, Apache, etc.).

3. **VPN**: Para acceso remoto, usa una VPN en lugar de exponer directamente a Internet.

## Solución de Problemas

### Error: "Connection refused"

- Verifica que Ollama esté corriendo: `systemctl status ollama`
- Verifica que `OLLAMA_HOST` esté configurado: `echo $OLLAMA_HOST`
- Verifica que Ollama esté escuchando en la red: `netstat -tlnp | grep 11434`
- Reinicia Ollama después de cambiar la configuración

### Error: "Connection timed out"

- Verifica reglas de firewall: `sudo ufw status`
- Verifica que la IP sea accesible desde la red: `ping 192.168.0.33`
- Verifica que el puerto no esté bloqueado

### Verificar logs de Ollama

```bash
sudo journalctl -u ollama -f
```

## Referencias

- [Documentación oficial de Ollama](https://github.com/ollama/ollama/blob/main/docs/faq.md#how-do-i-configure-ollama-to-listen-on-a-specific-address)