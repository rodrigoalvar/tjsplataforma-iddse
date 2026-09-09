#!/bin/bash
# Script para instalar y configurar ffmpeg-rest con PM2
# Sistema TJSMEDICAL - Portal de Estudios Médicos

set -e

echo "🚀 Configurando ffmpeg-rest con PM2..."

# Colores para output
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
NC='\033[0m' # No Color

# Verificar si PM2 está instalado
if ! command -v pm2 &> /dev/null; then
    echo -e "${YELLOW}PM2 no está instalado. Instalando...${NC}"
    sudo npm install -g pm2
fi

# Solicitar información del proyecto
echo -e "${GREEN}Por favor, proporciona la siguiente información:${NC}"
read -p "Ruta completa del directorio de ffmpeg-rest (ej: /home/iddse/Documentos/TJS/ffmpeg-rest): " FFMPEG_REST_DIR

if [ ! -d "$FFMPEG_REST_DIR" ]; then
    echo -e "${RED}Error: El directorio $FFMPEG_REST_DIR no existe.${NC}"
    exit 1
fi

# Verificar que existe package.json
if [ ! -f "$FFMPEG_REST_DIR/package.json" ]; then
    echo -e "${RED}Error: No se encontró package.json en $FFMPEG_REST_DIR${NC}"
    exit 1
fi

# Leer puerto del package.json o solicitar
PORT=$(grep -o '"port"[[:space:]]*:[[:space:]]*[0-9]*' "$FFMPEG_REST_DIR/package.json" | grep -o '[0-9]*' | head -1)
if [ -z "$PORT" ]; then
    read -p "Puerto donde corre ffmpeg-rest (default: 3000): " PORT
    PORT=${PORT:-3000}
fi

# Leer host del package.json o usar 0.0.0.0 por defecto
HOST="0.0.0.0"

echo -e "${GREEN}Configurando PM2 para ffmpeg-rest...${NC}"

# Crear archivo de configuración PM2 (usar .cjs para compatibilidad con ES modules)
cat > "$FFMPEG_REST_DIR/ecosystem.config.cjs" <<EOF
module.exports = {
  apps: [
    {
      name: 'ffmpeg-rest',
      script: 'npm',
      args: 'run dev',
      cwd: '$FFMPEG_REST_DIR',
      instances: 1,
      exec_mode: 'fork',
      env: {
        NODE_ENV: 'production',
        PORT: $PORT,
        HOST: '$HOST'
      },
      error_file: '/home/iddse/.pm2/logs/ffmpeg-rest-error.log',
      out_file: '/home/iddse/.pm2/logs/ffmpeg-rest-out.log',
      log_date_format: 'YYYY-MM-DD HH:mm:ss Z',
      merge_logs: true,
      autorestart: true,
      max_restarts: 10,
      min_uptime: '10s',
      max_memory_restart: '1G',
      watch: false,
      ignore_watch: ['node_modules', 'logs', '*.log']
    },
    {
      name: 'ffmpeg-rest-worker',
      script: 'npm',
      args: 'run dev:worker',
      cwd: '$FFMPEG_REST_DIR',
      instances: 1,
      exec_mode: 'fork',
      env: {
        NODE_ENV: 'production'
      },
      error_file: '/home/iddse/.pm2/logs/ffmpeg-rest-worker-error.log',
      out_file: '/home/iddse/.pm2/logs/ffmpeg-rest-worker-out.log',
      log_date_format: 'YYYY-MM-DD HH:mm:ss Z',
      merge_logs: true,
      autorestart: true,
      max_restarts: 10,
      min_uptime: '10s',
      max_memory_restart: '1G',
      watch: false,
      ignore_watch: ['node_modules', 'logs', '*.log']
    }
  ]
};
EOF

echo -e "${GREEN}Archivo ecosystem.config.cjs creado en $FFMPEG_REST_DIR${NC}"

# Instalar dependencias si es necesario
if [ ! -d "$FFMPEG_REST_DIR/node_modules" ]; then
    echo -e "${YELLOW}Instalando dependencias de Node.js...${NC}"
    cd "$FFMPEG_REST_DIR"
    npm install
fi

# Detener procesos existentes si están corriendo
echo -e "${YELLOW}Deteniendo procesos existentes de ffmpeg-rest...${NC}"
pm2 delete ffmpeg-rest 2>/dev/null || true
pm2 delete ffmpeg-rest-worker 2>/dev/null || true

# Iniciar con PM2
echo -e "${GREEN}Iniciando ffmpeg-rest con PM2...${NC}"
cd "$FFMPEG_REST_DIR"
pm2 start ecosystem.config.cjs

# Guardar configuración de PM2
pm2 save

# Configurar PM2 para iniciar al arrancar el sistema
echo -e "${YELLOW}Configurando PM2 para iniciar al arrancar el sistema...${NC}"
pm2 startup | tail -1 | sudo bash

echo -e "${GREEN}✅ ffmpeg-rest configurado correctamente con PM2${NC}"
echo ""
echo -e "${GREEN}Comandos útiles:${NC}"
echo "  pm2 status                    # Ver estado de los procesos"
echo "  pm2 logs ffmpeg-rest          # Ver logs de ffmpeg-rest"
echo "  pm2 logs ffmpeg-rest-worker    # Ver logs del worker"
echo "  pm2 restart ffmpeg-rest       # Reiniciar ffmpeg-rest"
echo "  pm2 restart ffmpeg-rest-worker # Reiniciar worker"
echo "  pm2 stop ffmpeg-rest         # Detener ffmpeg-rest"
echo "  pm2 monit                     # Monitor en tiempo real"
echo ""
echo -e "${GREEN}ffmpeg-rest debería estar corriendo en: http://$HOST:$PORT${NC}"
