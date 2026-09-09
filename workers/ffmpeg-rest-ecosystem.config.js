// Configuración PM2 para ffmpeg-rest
// Sistema TJSMEDICAL - Portal de Estudios Médicos
// 
// Uso:
// 1. Edita las rutas y puertos según tu configuración
// 2. Ejecuta: pm2 start ecosystem.config.cjs
// 3. Guarda: pm2 save

// Nota: Este archivo debe renombrarse a .cjs para evitar conflictos con ES modules
// O usar: pm2 start ecosystem.config.cjs

export default {
  apps: [
    {
      name: 'ffmpeg-rest',
      script: 'npm',
      args: 'run dev',
      // Ajusta esta ruta según donde tengas ffmpeg-rest
      cwd: '/home/iddse/Documentos/TJS/ffmpeg-rest',
      instances: 1,
      exec_mode: 'fork',
      env: {
        NODE_ENV: 'production',
        PORT: 3000,
        HOST: '0.0.0.0'  // Escuchar en todas las interfaces
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
      // Ajusta esta ruta según donde tengas ffmpeg-rest
      cwd: '/home/iddse/Documentos/TJS/ffmpeg-rest',
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
