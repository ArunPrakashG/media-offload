// Production entry. Wraps Astro's standalone handler so every response,
// static or rendered, gets the same security and cache headers.
// Caddy adds HSTS, nosniff, Referrer-Policy and Permissions-Policy in front.
import http from 'node:http';

process.env.ASTRO_NODE_AUTOSTART = 'disabled';
const { handler } = await import('./dist/server/entry.mjs');

const SITE = 'https://usenimbus.dev';
const host = process.env.HOST ?? '0.0.0.0';
const port = Number(process.env.PORT ?? 4321);

// Script and style hashes come from Astro's <meta> CSP. This header adds the
// directives a meta tag cannot carry.
const CSP = "frame-ancestors 'none'; base-uri 'self'; form-action 'self'; object-src 'none'; upgrade-insecure-requests";

function cacheControl(path) {
  if (path.startsWith('/_astro/')) return 'public, max-age=31536000, immutable';
  if (/\.(png|ico|svg|webmanifest)$/.test(path)) return 'public, max-age=86400';
  if (/\.(md|txt|xml|json)$/.test(path)) return 'public, max-age=3600';
  return 'public, max-age=300, stale-while-revalidate=86400';
}

/** The HTML page a Markdown alternate belongs to. */
function canonicalFor(path) {
  if (path === '/index.md') return `${SITE}/`;
  return `${SITE}${path.replace(/\.md$/, '/')}`;
}

http
  .createServer((req, res) => {
    const path = (req.url ?? '/').split('?')[0];
    res.setHeader('Content-Security-Policy', CSP);
    res.setHeader('X-Frame-Options', 'DENY');
    res.setHeader('Cross-Origin-Opener-Policy', 'same-origin');
    res.setHeader('Cache-Control', cacheControl(path));
    if (path.endsWith('.md')) res.setHeader('Link', `<${canonicalFor(path)}>; rel="canonical"`);
    if (path.endsWith('.txt') || path.endsWith('.md')) res.setHeader('Access-Control-Allow-Origin', '*');
    handler(req, res);
  })
  .listen(port, host, () => console.log(`nimbus-web listening on http://${host}:${port}`));
