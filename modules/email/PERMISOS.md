# Ajuste de Permisos del Módulo de Email

Para poder editar los archivos del módulo de email sin necesidad de usar `sudo`, ejecuta el siguiente comando:

## Opción 1: Script Automático (Recomendado)

```bash
cd /var/www/tjsidimagenes
sudo ./modules/email/fix-permissions-user.sh
```

## Opción 2: Comandos Manuales

Si prefieres hacerlo manualmente:

```bash
cd /var/www/tjsidimagenes

# Cambiar ownership a tu usuario
sudo chown -R $(whoami):$(id -gn) modules/email

# Dar permisos de lectura y escritura
sudo chmod -R 775 modules/email

# Archivos específicos con permisos de escritura
sudo chmod 664 modules/email/config/*.php
sudo chmod 664 modules/email/templates/*.html
sudo chmod 664 modules/email/api/*.php
```

## Opción 3: Cambiar Permisos de Todo el Proyecto

Si quieres poder editar todo el proyecto sin sudo:

```bash
cd /var/www/tjsidimagenes

# Cambiar ownership de todo el proyecto
sudo chown -R $(whoami):$(id -gn) .

# Dar permisos de lectura y escritura
sudo chmod -R 775 .

# Asegurar que el servidor web también pueda leer
sudo chmod -R 755 .
```

**Nota:** Después de cambiar permisos, asegúrate de que el servidor web (www-data) también pueda leer los archivos. Si tienes problemas, puedes agregar tu usuario al grupo www-data:

```bash
sudo usermod -a -G www-data $(whoami)
```

Luego cierra sesión y vuelve a iniciar sesión para que los cambios surtan efecto.





