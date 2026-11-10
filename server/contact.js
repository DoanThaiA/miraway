#!/usr/bin/env node
/*
 * API nhận form liên hệ và gửi email qua Resend.
 * Không cần cài thư viện (chỉ dùng module có sẵn của Node, chạy được từ Node 12).
 *
 * Biến môi trường:
 *   RESEND_API_KEY  (bắt buộc)  API key của Resend
 *   MAIL_TO         email nhận, mặc định hien@miratech.vn
 *   MAIL_FROM       người gửi, phải thuộc domain đã verify trên Resend
 *                   mặc định "Miraway Website <onboarding@resend.dev>"
 *   PORT            mặc định 3001 (chỉ nghe trên 127.0.0.1, nginx proxy vào)
 *
 * Chạy thử:  RESEND_API_KEY=re_xxx node server/contact.js
 */
'use strict';
const http = require('http');
const https = require('https');
const fs = require('fs');
const path = require('path');

// Đọc file .env nếu có (không ghi đè biến đã có sẵn)
for (const f of [process.env.ENV_FILE, path.join(__dirname, '.env')]) {
  if (!f || !fs.existsSync(f)) continue;
  for (const line of fs.readFileSync(f, 'utf8').split(/\r?\n/)) {
    const m = line.match(/^\s*([A-Z0-9_]+)\s*=\s*(.*?)\s*$/);
    if (m && !(m[1] in process.env)) process.env[m[1]] = m[2].replace(/^["']|["']$/g, '');
  }
}

const API_KEY = process.env.RESEND_API_KEY || '';
const MAIL_TO = (process.env.MAIL_TO || 'hien@miratech.vn').split(',').map(s => s.trim()).filter(Boolean);
const MAIL_FROM = process.env.MAIL_FROM || 'Miraway Website <onboarding@resend.dev>';
const PORT = Number(process.env.PORT || 3001);
const HOST = process.env.HOST || '127.0.0.1';

if (!API_KEY) console.warn('[contact] Thiếu RESEND_API_KEY — mọi yêu cầu gửi mail sẽ lỗi.');

// Giới hạn 5 lần gửi / 10 phút / IP
const hits = new Map();
const LIMIT = 5, WINDOW = 10 * 60 * 1000;
function rateLimited(ip) {
  const now = Date.now();
  const list = (hits.get(ip) || []).filter(t => now - t < WINDOW);
  list.push(now);
  hits.set(ip, list);
  return list.length > LIMIT;
}
setInterval(() => {
  const now = Date.now();
  for (const [ip, list] of hits) if (!list.some(t => now - t < WINDOW)) hits.delete(ip);
}, WINDOW).unref();

const esc = s => String(s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const clean = (v, max) => (typeof v === 'string' ? v.trim().slice(0, max) : '');

function validate(b) {
  const d = {
    name: clean(b.name, 100),
    company: clean(b.company, 150),
    email: clean(b.email, 150),
    phone: clean(b.phone, 20),
    topic: clean(b.topic, 120),
    message: clean(b.message, 1000),
    page: clean(b.page, 300),
  };
  if (d.name.length < 2) return [null, 'name'];
  if (!/^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(d.email)) return [null, 'email'];
  if (!/^\+?\d{9,15}$/.test(d.phone.replace(/[\s.\-()]/g, ''))) return [null, 'phone'];
  if (d.message.length < 10) return [null, 'message'];
  if (b.consent !== true) return [null, 'consent'];
  return [d, null];
}

function buildEmail(d) {
  const rows = [
    ['Họ và tên', d.name],
    ['Công ty / Tổ chức', d.company || '—'],
    ['Email', d.email],
    ['Số điện thoại', d.phone],
    ['Giải pháp quan tâm', d.topic || '—'],
  ];
  const time = new Date().toLocaleString('vi-VN', { timeZone: 'Asia/Ho_Chi_Minh' });
  const html = `<div style="font-family:Arial,sans-serif;font-size:15px;color:#0B1B33;max-width:600px">
  <h2 style="color:#0052B8;margin:0 0 16px">Yêu cầu tư vấn mới từ website</h2>
  <table cellpadding="8" style="border-collapse:collapse;width:100%">
    ${rows.map(([k, v]) => `<tr><td style="border-bottom:1px solid #E3E8EF;color:#55657E;width:170px">${k}</td><td style="border-bottom:1px solid #E3E8EF"><b>${esc(v)}</b></td></tr>`).join('')}
  </table>
  <h3 style="margin:20px 0 8px">Nội dung</h3>
  <div style="white-space:pre-wrap;background:#F5F7FB;border-radius:8px;padding:14px">${esc(d.message)}</div>
  <p style="color:#8090A8;font-size:13px;margin-top:20px">Gửi lúc ${esc(time)}${d.page ? ` từ ${esc(d.page)}` : ''}</p>
</div>`;
  const text = rows.map(([k, v]) => `${k}: ${v}`).join('\n') + `\n\nNội dung:\n${d.message}\n\nGửi lúc ${time}`;
  return {
    from: MAIL_FROM,
    to: MAIL_TO,
    reply_to: d.email,
    subject: `[Website] Yêu cầu tư vấn từ ${d.name}${d.company ? ` – ${d.company}` : ''}`,
    html,
    text,
  };
}

function sendResend(payload) {
  return new Promise((resolve, reject) => {
    const body = Buffer.from(JSON.stringify(payload));
    const req = https.request({
      method: 'POST',
      hostname: 'api.resend.com',
      path: '/emails',
      headers: { Authorization: `Bearer ${API_KEY}`, 'Content-Type': 'application/json', 'Content-Length': body.length },
      timeout: 10000,
    }, res => {
      let data = '';
      res.on('data', c => (data += c));
      res.on('end', () => (res.statusCode >= 200 && res.statusCode < 300 ? resolve(data) : reject(new Error(`Resend ${res.statusCode}: ${data}`))));
    });
    req.on('timeout', () => req.destroy(new Error('Resend timeout')));
    req.on('error', reject);
    req.end(body);
  });
}

function reply(res, code, obj) {
  res.writeHead(code, { 'Content-Type': 'application/json; charset=utf-8', 'Cache-Control': 'no-store' });
  res.end(JSON.stringify(obj));
}

const server = http.createServer((req, res) => {
  const url = req.url.split('?')[0];
  if (req.method === 'GET' && url === '/api/health') return reply(res, 200, { ok: true });
  if (url !== '/api/contact') return reply(res, 404, { ok: false, error: 'not_found' });
  if (req.method !== 'POST') return reply(res, 405, { ok: false, error: 'method_not_allowed' });

  const ip = (req.headers['x-real-ip'] || req.socket.remoteAddress || '').toString();
  let size = 0, raw = '';
  req.on('data', c => {
    size += c.length;
    if (size > 20000) { reply(res, 413, { ok: false, error: 'too_large' }); req.destroy(); return; }
    raw += c;
  });
  req.on('end', async () => {
    if (res.writableEnded) return;
    let body;
    try { body = JSON.parse(raw); } catch { return reply(res, 400, { ok: false, error: 'invalid_json' }); }
    if (body.website) return reply(res, 200, { ok: true }); // honeypot
    const [data, bad] = validate(body || {});
    if (!data) return reply(res, 422, { ok: false, error: 'invalid_' + bad });
    if (rateLimited(ip)) return reply(res, 429, { ok: false, error: 'rate_limited' });
    if (!API_KEY) return reply(res, 500, { ok: false, error: 'not_configured' });
    try {
      await sendResend(buildEmail(data));
      console.log(`[contact] sent from ${data.email} (${ip})`);
      reply(res, 200, { ok: true });
    } catch (err) {
      console.error('[contact]', err.message);
      reply(res, 502, { ok: false, error: 'send_failed' });
    }
  });
});

server.listen(PORT, HOST, () => console.log(`[contact] listening on http://${HOST}:${PORT}`));
