/**
 * Spike 1 — zero-dependency static file server.
 *
 * Serves this directory (assets/editor-spike/) so the prototype's ES module
 * imports and node_modules script tags resolve over http:// (opening
 * index.html directly via file:// blocks `<script type="module">` imports
 * under most browsers' CORS rules).
 *
 * Usage:  node server.js [port]   (default port 8934)
 */
const http = require('http');
const fs = require('fs');
const path = require('path');

const PORT = Number(process.argv[2]) || 8934;
const ROOT = __dirname;

const MIME = {
  '.html': 'text/html; charset=utf-8',
  '.js': 'text/javascript; charset=utf-8',
  '.mjs': 'text/javascript; charset=utf-8',
  '.css': 'text/css; charset=utf-8',
  '.json': 'application/json; charset=utf-8',
  '.map': 'application/json; charset=utf-8',
  '.png': 'image/png',
  '.svg': 'image/svg+xml',
};

http.createServer((req, res) => {
  let urlPath = decodeURIComponent(req.url.split('?')[0]);
  if (urlPath === '/') urlPath = '/index.html';

  const filePath = path.join(ROOT, urlPath);

  // Basic containment check — don't serve files outside ROOT.
  if (!filePath.startsWith(ROOT)) {
    res.writeHead(403);
    res.end('Forbidden');
    return;
  }

  fs.readFile(filePath, (err, data) => {
    if (err) {
      res.writeHead(404, { 'Content-Type': 'text/plain' });
      res.end('Not found: ' + urlPath);
      return;
    }
    const ext = path.extname(filePath);
    res.writeHead(200, {
      'Content-Type': MIME[ext] || 'application/octet-stream',
      'Cache-Control': 'no-cache',
    });
    res.end(data);
  });
}).listen(PORT, () => {
  console.log(`Spike 1 static server: http://127.0.0.1:${PORT}/`);
  console.log(`  Block theme demo:   http://127.0.0.1:${PORT}/index.html`);
  console.log(`  Classic theme demo: http://127.0.0.1:${PORT}/index-classic.html`);
});
