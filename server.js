'use strict';

const http = require('node:http');
const fs = require('node:fs');
const path = require('node:path');
const { URL } = require('node:url');

const HOST = '127.0.0.1';
const PORT = Number(process.env.PORT || 8787);
const CANVAS_HOSTS = new Set([
  'canvas.ox.ac.uk',
  'universityofoxford.beta.instructure.com',
  'universityofoxford.test.instructure.com',
  'oxeval.instructure.com'
]);
const ROOT = __dirname;

const server = http.createServer(async (req, res) => {
  const requestUrl = new URL(req.url, `http://${HOST}:${PORT}`);
  if (req.method === 'GET' && requestUrl.pathname === '/') return serveIndex(res);
  if (req.method !== 'POST' || requestUrl.pathname !== '/canvas-proxy.php') return sendJson(res, 404, { error: 'Not found.' });

  const authorization = req.headers.authorization || '';
  const tokenMatch = authorization.match(/^Bearer\s+([^\s]+)$/i);
  if (!tokenMatch || tokenMatch[1].length > 4096) return sendJson(res, 401, { error: 'A valid bearer token is required.' });

  let target;
  try { target = new URL(requestUrl.searchParams.get('url') || ''); }
  catch { return sendJson(res, 400, { error: 'A valid Canvas API URL is required.' }); }
  const validationError = validateTarget(target);
  if (validationError) return sendJson(res, 400, { error: validationError });

  try {
    const upstream = await fetch(target, {
      method: 'GET',
      headers: { Authorization: `Bearer ${tokenMatch[1]}`, Accept: 'application/json' },
      redirect: 'manual',
      signal: AbortSignal.timeout(60000)
    });
    const headers = { 'Content-Type': upstream.headers.get('content-type') || 'application/json; charset=utf-8', 'Cache-Control': 'no-store' };
    for (const name of ['link', 'retry-after']) {
      const value = upstream.headers.get(name);
      if (value) headers[name] = value;
    }
    res.writeHead(upstream.status, headers);
    res.end(Buffer.from(await upstream.arrayBuffer()));
  } catch {
    sendJson(res, 502, { error: 'Could not reach Canvas. Check your connection and try again.' });
  }
});

function validateTarget(target) {
  if (target.protocol !== 'https:' || !CANVAS_HOSTS.has(target.hostname) || target.port || target.username || target.password || target.hash) return 'This Canvas instance is not in the configured allowlist.';
  if (/^\/api\/v1\/courses\/[1-9]\d*$/.test(target.pathname)) {
    return target.search ? 'Course detail requests must not include query parameters.' : null;
  }
  if (target.pathname !== '/api/v1/calendar_events') return 'Only course details and course calendar event requests are allowed.';
  const allowed = new Set(['start_date', 'end_date', 'context_codes[]', 'per_page', 'excludes[]', 'page']);
  for (const key of target.searchParams.keys()) if (!allowed.has(key)) return `Unsupported query parameter: ${key}`;
  for (const key of ['start_date', 'end_date', 'per_page']) {
    if (target.searchParams.getAll(key).length !== 1) return `Exactly one ${key} parameter is required.`;
  }
  if (target.searchParams.getAll('page').length > 1) return 'Only one pagination page may be requested.';
  const start = target.searchParams.get('start_date');
  const end = target.searchParams.get('end_date');
  const context = target.searchParams.getAll('context_codes[]');
  const perPage = target.searchParams.get('per_page');
  const excludes = target.searchParams.getAll('excludes[]');
  const page = target.searchParams.get('page') || '1';
  if (!isDate(start) || !isDate(end) || end < start) return 'A valid start_date and end_date are required.';
  if (context.length !== 1 || !/^course_[1-9]\d*$/.test(context[0])) return 'A single positive course context code is required.';
  if (perPage !== '100' || excludes.length !== 1 || excludes[0] !== 'child_events') return 'Invalid calendar request options.';
  if (!/^[1-9]\d*$/.test(page)) return 'Invalid pagination page.';
  return null;
}

function isDate(value) {
  if (!value || !/^\d{4}-\d{2}-\d{2}$/.test(value)) return false;
  const date = new Date(`${value}T00:00:00Z`);
  return Number.isFinite(date.getTime()) && date.toISOString().slice(0, 10) === value;
}

function serveIndex(res) {
  fs.readFile(path.join(ROOT, 'index.html'), (error, data) => {
    if (error) return sendJson(res, 500, { error: 'Could not load the tool page.' });
    res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8', 'Cache-Control': 'no-store' });
    res.end(data);
  });
}

function sendJson(res, status, value) {
  res.writeHead(status, { 'Content-Type': 'application/json; charset=utf-8', 'Cache-Control': 'no-store' });
  res.end(JSON.stringify(value));
}

server.listen(PORT, HOST, () => console.log(`Canvas Calendar to CSV running at http://${HOST}:${PORT}`));
