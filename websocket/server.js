'use strict';

const crypto = require('node:crypto');
const fs = require('node:fs');
const path = require('node:path');
const mysql = require('mysql2/promise');
const { WebSocketServer, WebSocket } = require('ws');

function loadEnv(file) {
  if (!fs.existsSync(file)) throw new Error(`Missing environment file: ${file}`);
  for (const rawLine of fs.readFileSync(file, 'utf8').split(/\r?\n/)) {
    const line = rawLine.trim();
    if (!line || line.startsWith('#')) continue;
    const separator = line.indexOf('=');
    if (separator < 1) continue;
    const name = line.slice(0, separator).trim();
    let value = line.slice(separator + 1).trim();
    if ((value.startsWith('"') && value.endsWith('"')) || (value[0] === "'" && value[value.length - 1] === "'")) {
      value = value.slice(1, -1);
    }
    if (process.env[name] === undefined) process.env[name] = value;
  }
}

loadEnv(path.resolve(__dirname, '..', '.env'));

const secret = process.env.NOTIFICATION_WS_SECRET || '';
const allowedOrigins = (process.env.NOTIFICATION_WS_ALLOWED_ORIGINS || '')
  .split(',')
  .map(origin => origin.trim())
  .filter(Boolean);
const port = Number(process.env.NOTIFICATION_WS_PORT || 3001);

if (Buffer.byteLength(secret) < 32) throw new Error('NOTIFICATION_WS_SECRET must be at least 32 bytes.');
if (!Number.isInteger(port) || port < 1 || port > 65535) throw new Error('NOTIFICATION_WS_PORT is invalid.');

const pool = mysql.createPool({
  host: process.env.DB_HOST || '127.0.0.1',
  port: Number(process.env.DB_PORT || 3306),
  database: process.env.DB_NAME || 'san_bong',
  user: process.env.DB_USER || 'root',
  password: process.env.DB_PASS || '',
  connectionLimit: 5,
  waitForConnections: true,
  queueLimit: 50
});

const clients = new Map();
const consumedTickets = new Set();
let pollRunning = false;

function decodeBase64Url(value) {
  return Buffer.from(value.replace(/-/g, '+').replace(/_/g, '/') + '='.repeat((4 - value.length % 4) % 4), 'base64');
}

function verifyTicket(ticket, requestOrigin) {
  if (typeof ticket !== 'string' || ticket.length > 2048) return null;
  const [encoded, suppliedSignature, extra] = ticket.split('.');
  if (!encoded || !suppliedSignature || extra !== undefined) return null;

  const expected = crypto.createHmac('sha256', secret).update(encoded).digest();
  const actual = decodeBase64Url(suppliedSignature);
  if (actual.length !== expected.length || !crypto.timingSafeEqual(actual, expected)) return null;

  let payload;
  try {
    payload = JSON.parse(decodeBase64Url(encoded).toString('utf8'));
  } catch {
    return null;
  }
  if (!Number.isSafeInteger(payload.sub) || payload.sub < 1
      || !Number.isSafeInteger(payload.since) || payload.since < 0
      || !Number.isSafeInteger(payload.exp) || payload.exp < Date.now() / 1000
      || typeof payload.nonce !== 'string' || payload.nonce.length !== 32
      || typeof payload.origin !== 'string' || payload.origin !== requestOrigin
      || (allowedOrigins.length && !allowedOrigins.includes(requestOrigin))) return null;
  return payload;
}

const server = new WebSocketServer({
  host: '127.0.0.1',
  port,
  maxPayload: 2048,
  perMessageDeflate: false,
  verifyClient: ({ origin }, done) => done(Boolean(origin), 403, 'Origin required')
});

server.on('connection', (socket, request) => {
  const timeout = setTimeout(() => socket.close(4401, 'Authentication required'), 5000);
  socket.once('message', raw => {
    let message;
    try {
      message = JSON.parse(raw.toString());
    } catch {
      socket.close(4400, 'Invalid authentication message');
      return;
    }
    if (message.type !== 'auth') {
      socket.close(4400, 'Authentication required');
      return;
    }
    const payload = verifyTicket(message.ticket, request.headers.origin || '');
    if (!payload || consumedTickets.has(payload.nonce)) {
      socket.close(4401, 'Invalid or expired ticket');
      return;
    }
    consumedTickets.add(payload.nonce);
    setTimeout(() => consumedTickets.delete(payload.nonce), 60_000).unref();
    clearTimeout(timeout);
    clients.set(socket, { userId: payload.sub, lastId: payload.since });
    socket.send(JSON.stringify({ type: 'ready' }));
  });
  socket.on('close', () => {
    clearTimeout(timeout);
    clients.delete(socket);
  });
  socket.on('error', error => console.error('WebSocket client error:', error.message));
});

async function pollNotifications() {
  if (pollRunning) return;
  pollRunning = true;
  try {
    const connected = [...clients.entries()].filter(([socket]) => socket.readyState === WebSocket.OPEN);
    if (!connected.length) return;
    const cursors = new Map();
    for (const [, state] of connected) {
      cursors.set(state.userId, Math.min(cursors.get(state.userId) ?? state.lastId, state.lastId));
    }
    const userCursors = [...cursors.entries()];
    const rowsByUser = new Map();
    for (let offset = 0; offset < userCursors.length; offset += 5) {
      const batch = await Promise.all(userCursors.slice(offset, offset + 5).map(async ([userId, lastId]) => {
        const [rows] = await pool.execute(
          'SELECT id,user_id FROM notifications WHERE user_id=? AND id>? ORDER BY id ASC LIMIT 500',
          [userId, lastId]
        );
        return [userId, rows];
      }));
      for (const [userId, rows] of batch) rowsByUser.set(userId, rows);
    }
    for (const [socket, state] of connected) {
      const newRows = (rowsByUser.get(state.userId) || []).filter(row => Number(row.id) > state.lastId);
      for (const row of newRows) {
        if (socket.readyState !== WebSocket.OPEN) break;
        state.lastId = Number(row.id);
        socket.send(JSON.stringify({ type: 'notification', id: state.lastId }));
      }
    }
  } catch (error) {
    console.error('Notification poll failed:', error.message);
  } finally {
    pollRunning = false;
  }
}

const pollTimer = setInterval(pollNotifications, 1000);
pollTimer.unref();

server.on('listening', () => console.log(`Notification WebSocket listening on 127.0.0.1:${port}`));

async function shutdown() {
  clearInterval(pollTimer);
  for (const socket of clients.keys()) socket.close(1001, 'Server shutting down');
  server.close();
  await pool.end();
}

process.once('SIGINT', shutdown);
process.once('SIGTERM', shutdown);
