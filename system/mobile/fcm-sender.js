'use strict';
const http = require('http');
const fs = require('fs');
const crypto = require('crypto');

const KEY_PATH = '/home/mahin/firebase-service-account.json';
const HOST = '127.0.0.1';
const PORT = 31337;
const credentials = JSON.parse(fs.readFileSync(KEY_PATH, 'utf8'));
if (!credentials.project_id || !credentials.client_email || !credentials.private_key) {
  throw new Error('Invalid Firebase service account');
}
let tokenCache = null;

function b64url(value) {
  const raw = Buffer.isBuffer(value) ? value : Buffer.from(value);
  return raw.toString('base64').replace(/=/g, '').replace(/\+/g, '-').replace(/\//g, '_');
}

async function accessToken() {
  const now = Math.floor(Date.now() / 1000);
  if (tokenCache && tokenCache.exp > now + 90) return tokenCache.token;
  const header = b64url(JSON.stringify({ alg: 'RS256', typ: 'JWT' }));
  const claim = b64url(JSON.stringify({
    iss: credentials.client_email,
    scope: 'https://www.googleapis.com/auth/firebase.messaging',
    aud: 'https://oauth2.googleapis.com/token',
    iat: now, exp: now + 3500
  }));
  const unsigned = header + '.' + claim;
  const signature = crypto.sign('RSA-SHA256', Buffer.from(unsigned), credentials.private_key);
  const jwt = unsigned + '.' + b64url(signature);
  const form = new URLSearchParams({
    grant_type: 'urn:ietf:params:oauth:grant-type:jwt-bearer',
    assertion: jwt
  });
  const response = await fetch('https://oauth2.googleapis.com/token', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: form,
    signal: AbortSignal.timeout(12000)
  });
  const json = await response.json();
  if (!response.ok || !json.access_token) {
    throw new Error('OAuth token request failed: HTTP ' + response.status);
  }
  tokenCache = { token: json.access_token, exp: now + Number(json.expires_in || 3500) };
  return tokenCache.token;
}

function cleanData(data) {
  const out = {};
  if (!data || typeof data !== 'object') return out;
  for (const [key, value] of Object.entries(data)) {
    if (typeof key !== 'string' || key.length > 80) continue;
    out[key] = String(value).slice(0, 1000);
  }
  return out;
}
async function sendPush(input) {
  const deviceToken = String(input.token || '');
  const title = String(input.title || '').trim().slice(0, 160);
  const body = String(input.body || '').trim().slice(0, 1200);
  if (deviceToken.length < 40 || deviceToken.length > 255 || !title || !body) {
    return { ok: false, code: 400, status: 'INVALID_REQUEST' };
  }
  const access = await accessToken();
  const payload = {
    message: {
      token: deviceToken,
      notification: { title, body },
      data: cleanData(input.data),
      android: {
        priority: 'high',
        notification: {
          channel_id: 'support_tickets',
          click_action: 'FLUTTER_NOTIFICATION_CLICK'
        }
      }
    }
  };
  const response = await fetch(
    'https://fcm.googleapis.com/v1/projects/' +
      encodeURIComponent(credentials.project_id) + '/messages:send',
    {
      method: 'POST',
      headers: {
        Authorization: 'Bearer ' + access,
        'Content-Type': 'application/json'
      },
      body: JSON.stringify(payload),
      signal: AbortSignal.timeout(12000)
    }
  );
  const raw = await response.text();
  let json = {};
  try { json = JSON.parse(raw); } catch (_) {}
  const status = String(json?.error?.status || '');
  return {
    ok: response.ok,
    code: response.status,
    status,
    invalidToken: status === 'NOT_FOUND' || status === 'INVALID_ARGUMENT'
  };
}

const server = http.createServer((req, res) => {
  const remote = req.socket.remoteAddress || '';
  if (!['127.0.0.1', '::1', '::ffff:127.0.0.1'].includes(remote)) {
    res.writeHead(403).end();
    return;
  }
  if (req.method === 'GET' && req.url === '/health') {
    res.writeHead(200, { 'Content-Type': 'application/json' });
    res.end(JSON.stringify({ ok: true, project: credentials.project_id }));
    return;
  }
  if (req.method !== 'POST' || req.url !== '/send') {
    res.writeHead(404).end();
    return;
  }
  let size = 0;
  let body = '';
  req.setEncoding('utf8');
  req.on('data', chunk => {
    size += Buffer.byteLength(chunk);
    if (size > 65536) {
      res.writeHead(413).end();
      req.destroy();
      return;
    }
    body += chunk;
  });
  req.on('end', async () => {
    try {
      const input = JSON.parse(body || '{}');
      const result = await sendPush(input);
      res.writeHead(result.ok ? 200 : 502, { 'Content-Type': 'application/json' });
      res.end(JSON.stringify(result));
    } catch (error) {
      console.error(new Date().toISOString(), error.message);
      res.writeHead(502, { 'Content-Type': 'application/json' });
      res.end(JSON.stringify({ ok: false, status: 'BRIDGE_ERROR' }));
    }
  });
});

server.listen(PORT, HOST, () => {
  console.log(new Date().toISOString(), 'JM FCM sender listening on', HOST + ':' + PORT);
});
