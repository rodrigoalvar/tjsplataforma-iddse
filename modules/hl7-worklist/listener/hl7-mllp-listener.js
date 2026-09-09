#!/usr/bin/env node
/**
 * Receptor MLLP HL7 v2 — módulo hl7-worklist (sin Mirth).
 *
 * Lee modules/hl7-worklist/runtime/config.json (escrito por la plataforma al guardar Worklist).
 * Framing: 0x0B … 0x1C 0x0D
 * ACK: MSA|AA|{MSH-10}
 */

'use strict';

const net = require('net');
const fs = require('fs');
const path = require('path');
const { spawn } = require('child_process');

const MODULE_ROOT = path.resolve(__dirname, '..');
const CONFIG_PATH = path.join(MODULE_ROOT, 'runtime', 'config.json');
const LOG_PATH = path.join(MODULE_ROOT, 'logs', 'listener.log');

const VT = 0x0b;
const FS = 0x1c;
const CR = 0x0d;

let server = null;
let currentConfig = null;
let listenKey = '';

function log(msg) {
  const line = `[${new Date().toISOString()}] ${msg}`;
  console.log(line);
  try {
    fs.appendFileSync(LOG_PATH, line + '\n');
  } catch (_) { /* ignore */ }
}

function readConfig() {
  try {
    const raw = fs.readFileSync(CONFIG_PATH, 'utf8');
    return JSON.parse(raw);
  } catch (e) {
    return {
      hl7_enabled: 0,
      hl7_port: 2576,
      hl7_bind_host: '0.0.0.0',
      hl7_input_path: path.join(MODULE_ROOT, '..', '..', 'uploads', 'inbox-hl7'),
      hl7_spawn_worker_on_receive: 1,
      php_bin: 'php',
      process_one_script: path.join(MODULE_ROOT, 'workers', 'hl7-process-one.php'),
      heartbeat_path: path.join(MODULE_ROOT, 'runtime', 'listener.heartbeat'),
    };
  }
}

function writeHeartbeat(cfg, extra) {
  const hb = cfg.heartbeat_path || path.join(MODULE_ROOT, 'runtime', 'listener.heartbeat');
  try {
    fs.mkdirSync(path.dirname(hb), { recursive: true });
    fs.writeFileSync(
      hb,
      JSON.stringify({
        ts: Math.floor(Date.now() / 1000),
        pid: process.pid,
        enabled: Number(cfg.hl7_enabled) === 1,
        port: cfg.hl7_port,
        bind: cfg.hl7_bind_host,
        listening: !!server && server.listening,
        ...(extra || {}),
      })
    );
  } catch (e) {
    log('heartbeat error: ' + e.message);
  }
}

function extractMsgControlId(hl7) {
  const text = hl7.replace(/\r\n/g, '\r').replace(/\n/g, '\r');
  const msh = text.split('\r').find((s) => s.startsWith('MSH|'));
  if (!msh) return 'ACK' + Date.now();
  const parts = msh.split('|');
  // MSH-10 => index 9 (0=MSH, 1=enc, ... 9=control id) — wait: parts[0]=MSH, parts[1]=^~\&, parts[9]=MSH-10
  return (parts[9] && parts[9].trim()) || 'ACK' + Date.now();
}

function buildAck(msgControlId) {
  const ts = new Date()
    .toISOString()
    .replace(/[-:TZ.]/g, '')
    .slice(0, 14);
  const ackCtrl = 'ACK' + ts;
  const msh =
    `MSH|^~\\&|TJSMEDICAL|HL7WL|RIS|EXT|${ts}||ACK^O01|${ackCtrl}|P|2.5`;
  const msa = `MSA|AA|${msgControlId}`;
  const body = msh + '\r' + msa + '\r';
  return Buffer.concat([
    Buffer.from([VT]),
    Buffer.from(body, 'utf8'),
    Buffer.from([FS, CR]),
  ]);
}

function ensureDir(dir) {
  fs.mkdirSync(dir, { recursive: true });
}

function saveMessage(cfg, hl7Text) {
  const inbox = cfg.hl7_input_path;
  ensureDir(inbox);
  const msgId = extractMsgControlId(hl7Text).replace(/[^\w.-]+/g, '_');
  const fname = `${Date.now()}_${msgId}.hl7`;
  const full = path.join(inbox, fname);
  fs.writeFileSync(full, hl7Text.replace(/\n/g, '\r'), 'utf8');
  return full;
}

function spawnWorker(cfg, filePath) {
  if (Number(cfg.hl7_spawn_worker_on_receive) !== 1) return;
  const php = cfg.php_bin || 'php';
  const script = cfg.process_one_script;
  if (!script || !fs.existsSync(script)) {
    log('process_one_script no encontrado: ' + script);
    return;
  }
  if (!php || /php-fpm/i.test(String(php))) {
    log('php_bin inválido para CLI (usar /usr/bin/php): ' + php);
    return;
  }
  const child = spawn(php, [script, filePath], {
    detached: true,
    stdio: ['ignore', 'ignore', 'pipe'],
  });
  let errBuf = '';
  if (child.stderr) {
    child.stderr.on('data', (d) => {
      errBuf += d.toString();
      if (errBuf.length > 2000) errBuf = errBuf.slice(-2000);
    });
  }
  child.on('error', (e) => log('spawn error: ' + e.message + ' php=' + php));
  child.on('exit', (code, signal) => {
    if (code !== 0) {
      log(
        'worker exit code=' +
          code +
          (signal ? ' signal=' + signal : '') +
          ' php=' +
          php +
          ' file=' +
          filePath +
          (errBuf ? ' stderr=' + errBuf.trim().slice(0, 500) : '')
      );
    }
  });
  child.unref();
  log('spawn worker: ' + filePath + ' php=' + php);
}

function handleSocket(socket) {
  let buf = Buffer.alloc(0);
  socket.on('data', (chunk) => {
    // Siempre la config más reciente (tick refresca currentConfig cada 15s)
    const cfg = currentConfig || readConfig();
    buf = Buffer.concat([buf, chunk]);
    while (true) {
      const start = buf.indexOf(VT);
      if (start < 0) {
        buf = Buffer.alloc(0);
        break;
      }
      if (start > 0) buf = buf.slice(start);
      const endFs = buf.indexOf(FS, 1);
      if (endFs < 0) break;
      // Expect CR after FS
      const msgBuf = buf.slice(1, endFs);
      buf = buf.slice(endFs + 1);
      if (buf[0] === CR) buf = buf.slice(1);

      const hl7Text = msgBuf.toString('utf8');
      const msgId = extractMsgControlId(hl7Text);
      try {
        const saved = saveMessage(cfg, hl7Text);
        socket.write(buildAck(msgId));
        log(`recv OK msgId=${msgId} file=${saved}`);
        spawnWorker(cfg, saved);
      } catch (e) {
        log('error processing: ' + e.message);
        try {
          socket.write(buildAck(msgId)); // AA still — message on disk if possible
        } catch (_) {}
      }
    }
  });
  socket.on('error', (e) => log('socket error: ' + e.message));
}

function stopServer() {
  if (server) {
    try {
      server.close();
    } catch (_) {}
    server = null;
  }
  listenKey = '';
}

function startServer(cfg) {
  const host = cfg.hl7_bind_host || '0.0.0.0';
  const port = Number(cfg.hl7_port) || 2575;
  const key = `${host}:${port}`;

  if (Number(cfg.hl7_enabled) !== 1) {
    if (server) {
      log('HL7 disabled — closing listener');
      stopServer();
    }
    return;
  }

  if (server && listenKey === key && server.listening) {
    return;
  }

  stopServer();
  server = net.createServer((socket) => handleSocket(socket));
  server.on('error', (e) => log('server error: ' + e.message));
  server.listen(port, host, () => {
    listenKey = key;
    log(`listening on ${key}`);
  });
}

function tick() {
  currentConfig = readConfig();
  try {
    ensureDir(path.dirname(LOG_PATH));
    ensureDir(path.join(MODULE_ROOT, 'runtime'));
    if (currentConfig.hl7_input_path) ensureDir(currentConfig.hl7_input_path);
  } catch (_) {}
  startServer(currentConfig);
  writeHeartbeat(currentConfig);
}

log('hl7-mllp-listener starting, config=' + CONFIG_PATH);
tick();
setInterval(tick, 15000);

process.on('SIGHUP', () => {
  log('SIGHUP — reload config');
  tick();
});
process.on('SIGTERM', () => {
  log('SIGTERM');
  stopServer();
  process.exit(0);
});
