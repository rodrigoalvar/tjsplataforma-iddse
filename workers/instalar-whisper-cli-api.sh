#!/bin/bash
# Script de instalación para Whisper CLI API
# Ejecutar en el servidor con RTX 5060 Ti

set -e

echo "=========================================="
echo "Instalación de Whisper CLI API"
echo "=========================================="
echo ""

# Colores para output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Verificar Node.js
echo -e "${YELLOW}Verificando Node.js...${NC}"
if ! command -v node &> /dev/null; then
    echo -e "${RED}Error: Node.js no está instalado${NC}"
    echo "Instala Node.js con: curl -fsSL https://deb.nodesource.com/setup_20.x | sudo -E bash - && sudo apt-get install -y nodejs"
    exit 1
fi

NODE_VERSION=$(node --version)
echo -e "${GREEN}Node.js encontrado: $NODE_VERSION${NC}"
echo ""

# Verificar npm
echo -e "${YELLOW}Verificando npm...${NC}"
if ! command -v npm &> /dev/null; then
    echo -e "${RED}Error: npm no está instalado${NC}"
    exit 1
fi

NPM_VERSION=$(npm --version)
echo -e "${GREEN}npm encontrado: $NPM_VERSION${NC}"
echo ""

# Directorio de trabajo
API_DIR="/home/iddse/Documentos/TJS/whisper-cli-api"
echo -e "${YELLOW}Creando directorio: $API_DIR${NC}"
mkdir -p "$API_DIR"
cd "$API_DIR"
echo -e "${GREEN}Directorio creado${NC}"
echo ""

# Verificar si whisper-cli-api.js existe
if [ ! -f "whisper-cli-api.js" ]; then
    echo -e "${YELLOW}whisper-cli-api.js no encontrado en $API_DIR${NC}"
    echo "Por favor, copia el archivo whisper-cli-api.js a este directorio"
    echo "O ejecuta este script desde el directorio donde está el archivo"
    exit 1
fi

echo -e "${GREEN}Archivo whisper-cli-api.js encontrado${NC}"
echo ""

# Inicializar npm si no existe package.json
if [ ! -f "package.json" ]; then
    echo -e "${YELLOW}Inicializando proyecto npm...${NC}"
    npm init -y
    echo -e "${GREEN}Proyecto npm inicializado${NC}"
    echo ""
else
    echo -e "${GREEN}package.json ya existe${NC}"
    echo ""
fi

# Instalar dependencias
echo -e "${YELLOW}Instalando dependencias...${NC}"
npm install express multer cors
echo -e "${GREEN}Dependencias instaladas${NC}"
echo ""

# Verificar rutas en el archivo
echo -e "${YELLOW}Verificando configuración...${NC}"
echo "Por favor, verifica las siguientes rutas en whisper-cli-api.js:"
echo "  - WHISPER_CLI: Ruta al binario whisper-cli"
echo "  - MODELS_DIR: Ruta a los modelos de whisper.cpp"
echo ""

# Crear directorio de uploads
UPLOAD_DIR="/tmp/whisper-uploads"
echo -e "${YELLOW}Creando directorio de uploads: $UPLOAD_DIR${NC}"
sudo mkdir -p "$UPLOAD_DIR"
sudo chmod 777 "$UPLOAD_DIR"
echo -e "${GREEN}Directorio de uploads creado${NC}"
echo ""

# Preguntar si instalar PM2
read -p "¿Deseas instalar PM2 para gestión de procesos? (s/n): " -n 1 -r
echo ""
if [[ $REPLY =~ ^[SsYy]$ ]]; then
    echo -e "${YELLOW}Instalando PM2...${NC}"
    sudo npm install -g pm2
    echo -e "${GREEN}PM2 instalado${NC}"
    echo ""
    
    read -p "¿Deseas iniciar la API con PM2 ahora? (s/n): " -n 1 -r
    echo ""
    if [[ $REPLY =~ ^[SsYy]$ ]]; then
        echo -e "${YELLOW}Iniciando API con PM2...${NC}"
        pm2 start whisper-cli-api.js --name whisper-cli-api
        pm2 save
        echo -e "${GREEN}API iniciada con PM2${NC}"
        echo ""
        echo "Comandos útiles:"
        echo "  pm2 status          - Ver estado"
        echo "  pm2 logs whisper-cli-api - Ver logs"
        echo "  pm2 restart whisper-cli-api - Reiniciar"
    fi
else
    echo -e "${YELLOW}PM2 no instalado. Puedes ejecutar la API con: node whisper-cli-api.js${NC}"
fi

echo ""
echo -e "${GREEN}=========================================="
echo "Instalación completada"
echo "==========================================${NC}"
echo ""
echo "Próximos pasos:"
echo "1. Verifica las rutas en whisper-cli-api.js"
echo "2. Prueba la API: curl http://localhost:3001/health"
echo "3. Configura en el sistema principal: Configuración → AI Informes"
echo ""
