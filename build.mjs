#!/usr/bin/env node
/**
 * Zero-dependency static site builder.
 *
 *   node build.mjs            build once into dist/
 *   node build.mjs --watch    rebuild on every change under src/
 *   node build.mjs --serve    build + watch + local dev server with live reload
 *                             (optional: --port 3000)
 *
 * Source layout (see README.md):
 *   src/layouts/base.html     document shell (head, scripts)  -> {{header}} {{content}} {{footer}}
 *   src/layouts/*.html        reusable partials (header, footer, ...)
 *   src/pages/*.html          one file per page: front matter + main content + optional slots
 *   src/assets/**             copied as-is to dist/assets/
 *   src/site.json             global values, available as {{site.*}}
 */
import fs from 'node:fs';
import path from 'node:path';
import http from 'node:http';
import {fileURLToPath} from 'node:url';

const ROOT = path.dirname(fileURLToPath(import.meta.url));
const SRC = path.join(ROOT, 'src');
const DIST = path.join(ROOT, 'dist');
const args = process.argv.slice(2);
const flag = n => args.includes(n);
const option = (n, d) => { const i = args.indexOf(n); return i >= 0 && args[i + 1] ? args[i + 1] : d; };

// ---------------------------------------------------------------- helpers
const read = p => fs.readFileSync(p, 'utf8');
const exists = p => fs.existsSync(p);

function listFiles(dir) {
  const out = [];
  if (!exists(dir)) return out;
  for (const e of fs.readdirSync(dir, {withFileTypes: true})) {
    const p = path.join(dir, e.name);
    e.isDirectory() ? out.push(...listFiles(p)) : out.push(p);
  }
  return out;
}

/** Copies src -> dest only when missing, or when size/mtime differ. Returns the number of files copied. */
function copyDir(src, dest) {
  let copied = 0;
  for (const file of listFiles(src)) {
    const target = path.join(dest, path.relative(src, file));
    const a = fs.statSync(file);
    const b = exists(target) ? fs.statSync(target) : null;
    if (b && a.size === b.size && Math.floor(a.mtimeMs) <= Math.floor(b.mtimeMs)) continue;
    fs.mkdirSync(path.dirname(target), {recursive: true});
    fs.copyFileSync(file, target);
    copied++;
  }
  return copied;
}

/** Removes files in dest that no longer exist in src (keeps dist/ in sync). */
function pruneDir(src, dest) {
  for (const file of listFiles(dest)) {
    if (!exists(path.join(src, path.relative(dest, file)))) fs.rmSync(file);
  }
}

function parseFrontMatter(text) {
  const m = text.match(/^---\r?\n([\s\S]*?)\r?\n---\r?\n?/);
  if (!m) return {data: {}, body: text};
  const data = {};
  for (const line of m[1].split(/\r?\n/)) {
    const kv = line.match(/^([\w.-]+):\s*(.*)$/);
    if (kv) data[kv[1]] = kv[2].trim();
  }
  return {data, body: text.slice(m[0].length)};
}

/** Splits a page body into slots. Text before the first marker is the "content" slot. */
function parseSlots(body) {
  const slots = {content: ''};
  const re = /<!--\s*@slot\s+([\w-]+)\s*-->/g;
  let name = 'content', last = 0, m;
  while ((m = re.exec(body))) {
    slots[name] = (slots[name] || '') + body.slice(last, m.index);
    name = m[1]; last = re.lastIndex;
  }
  slots[name] = (slots[name] || '') + body.slice(last);
  for (const k of Object.keys(slots)) slots[k] = slots[k].trim();
  return slots;
}

function flatten(obj, prefix = '', out = {}) {
  for (const [k, v] of Object.entries(obj)) {
    if (v && typeof v === 'object' && !Array.isArray(v)) flatten(v, `${prefix}${k}.`, out);
    else out[`${prefix}${k}`] = String(v ?? '');
  }
  return out;
}

const truthy = v => v !== undefined && v !== '' && v !== 'false' && v !== '0';
const escAttr = s => String(s).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;');

/** Template engine: {{include name}}, {{#if k}}..{{/if}}, {{#unless k}}..{{/unless}}, {{var}}. Unknown {{x}} are left untouched. */
function render(tpl, vars, layouts) {
  let out = tpl;
  for (let i = 0; i < 5 && /\{\{include\s/.test(out); i++) {
    out = out.replace(/\{\{include\s+([\w-]+)\s*\}\}/g, (_, n) => {
      if (!(n in layouts)) throw new Error(`Missing layout partial: src/layouts/${n}.html`);
      return layouts[n];
    });
  }
  for (let i = 0; i < 5 && /\{\{#(if|unless)\s/.test(out); i++) {
    out = out.replace(/\{\{#(if|unless)\s+([\w.-]+)\s*\}\}([\s\S]*?)\{\{\/\1\}\}/g, (_, kind, key, inner) =>
      (kind === 'if') === truthy(vars[key]) ? inner : '');
  }
  return out.replace(/\{\{\s*([\w.-]+)\s*\}\}/g, (all, key) => (key in vars ? vars[key] : all));
}

/** Adds the "active" markup (Elementor nav-menu convention) to the menu entry matching this page. */
function markActive(html, pagePath, elementorId) {
  return html.replace(/<li class="([^"]*menu-item[^"]*)"><a href="\{\{root\}\}([^"]*)"([^>]*)>/g, (all, liClass, href, rest) => {
    if (href !== pagePath) return all;
    const extra = ` current-menu-item page_item${elementorId ? ` page-item-${elementorId}` : ''} current_page_item`;
    const attrs = rest.replace(/class="([^"]*)"/, (_, c) => `class="${c} elementor-item-active"`);
    return `<li class="${liClass}${extra}"><a href="{{root}}${href}" aria-current="page"${attrs}>`;
  });
}

/** Marks a top-level menu entry as ancestor of the current page (front matter "menu_parent: formaciones/"). */
function markParent(html, parent) {
  if (!parent) return html;
  return html.replace(/<li class="([^"]*menu-item[^"]*)"><a href="{{root}}([^"]*)"/g, (all, liClass, href) =>
    href === parent ? `<li class="${liClass} current-menu-ancestor current-menu-parent current_page_parent current_page_ancestor"><a href="{{root}}${href}"` : all);
}

// ---------------------------------------------------------------- build
function build() {
  const t0 = Date.now();
  const site = exists(path.join(SRC, 'site.json')) ? JSON.parse(read(path.join(SRC, 'site.json'))) : {};
  const siteVars = flatten(site, 'site.');

  const layouts = {};
  for (const f of listFiles(path.join(SRC, 'layouts'))) {
    if (f.endsWith('.html')) layouts[path.basename(f, '.html')] = read(f);
  }

  fs.mkdirSync(DIST, {recursive: true});
  const written = new Set();
  const pagesDir = path.join(SRC, 'pages');
  let pageCount = 0;

  for (const file of listFiles(pagesDir).filter(f => f.endsWith('.html'))) {
    const rel = path.relative(pagesDir, file).replace(/\\/g, '/');
    const {data, body} = parseFrontMatter(read(file));
    const slots = parseSlots(body);

    // Output path: front matter "path" wins ("" = home, "nosotros/" = dist/nosotros/index.html)
    let pagePath = 'path' in data ? data.path : (rel === 'index.html' ? '' : rel.replace(/\.html$/, '/'));
    pagePath = pagePath.replace(/^\/+/, '');
    if (pagePath && !pagePath.endsWith('/')) pagePath += '/';
    const depth = pagePath ? pagePath.split('/').filter(Boolean).length : 0;
    const root = depth === 0 ? './' : '../'.repeat(depth);

    const layoutName = data.layout || 'base';
    if (!layouts[layoutName]) throw new Error(`${rel}: missing layout "${layoutName}"`);
    const headerName = data.header || 'header', footerName = data.footer || 'footer';
    if (!layouts[headerName]) throw new Error(`${rel}: missing layout "${headerName}"`);
    if (!layouts[footerName]) throw new Error(`${rel}: missing layout "${footerName}"`);

    const elementorId = data.elementor_id || '';
    const title = data.title || site.name || '';
    const vars = {
      ...siteVars,
      ...data,
      root,
      path: pagePath,
      title,
      title_uri: encodeURIComponent(title),
      description: escAttr(data.description || ''),
      canonical: (site.url || '').replace(/\/+$/, '') + '/' + pagePath,
      // SEO front matter keys: title, description, og_image (path under dist/), noindex
      robots: truthy(data.noindex) ? 'noindex,follow' : 'index,follow,max-image-preview:large',
      og_image_url: (site.url || '').replace(/\/+$/, '') + '/' + String(data.og_image || site.og_image || '').replace(/^\/+/, ''),
      elementor_id: elementorId,
      wrap_open: elementorId ? `<div data-elementor-type="wp-page" data-elementor-id="${elementorId}" class="elementor elementor-${elementorId}" data-elementor-post-type="page">` : '',
      wrap_close: elementorId ? '</div>' : '',
      year: String(new Date().getFullYear()),
      header: markParent(markActive(layouts[headerName], pagePath, elementorId), data.menu_parent),
      footer: layouts[footerName],
      content: slots.content || '',
      head: slots.head || '',
      scripts: slots.scripts || '',
      before_header: slots.before_header || '',
      after_footer: slots.after_footer || '',
    };

    // Render twice: the first pass inlines header/footer/slots into the layout, the second resolves their placeholders.
    let html = render(layouts[layoutName], vars, layouts);
    html = render(html, vars, layouts);

    const outFile = path.join(DIST, pagePath, 'index.html');
    fs.mkdirSync(path.dirname(outFile), {recursive: true});
    fs.writeFileSync(outFile, html);
    written.add(path.resolve(outFile));
    pageCount++;
  }

  // Remove stale pages from dist (html only), then sync assets
  for (const f of listFiles(DIST)) {
    if (f.endsWith('.html') && !written.has(path.resolve(f))) fs.rmSync(f);
  }
  const assetsSrc = path.join(SRC, 'assets');
  const copied = copyDir(assetsSrc, path.join(DIST, 'assets'));
  pruneDir(assetsSrc, path.join(DIST, 'assets'));
  // src/public/** goes to the root of dist/ (.htaccess, robots.txt, ...)
  copyDir(path.join(SRC, 'public'), DIST);

  console.log(`[build] ${pageCount} pages, ${copied} assets copied, ${Date.now() - t0} ms`);
}

function safeBuild() {
  try { build(); return true; } catch (e) { console.error('[build] ERROR:', e.message); return false; }
}

// ---------------------------------------------------------------- dev server
const MIME = {
  '.html': 'text/html; charset=utf-8', '.css': 'text/css; charset=utf-8', '.js': 'text/javascript; charset=utf-8',
  '.mjs': 'text/javascript; charset=utf-8', '.json': 'application/json; charset=utf-8', '.svg': 'image/svg+xml',
  '.png': 'image/png', '.jpg': 'image/jpeg', '.jpeg': 'image/jpeg', '.gif': 'image/gif', '.webp': 'image/webp',
  '.ico': 'image/x-icon', '.woff': 'font/woff', '.woff2': 'font/woff2', '.ttf': 'font/ttf', '.otf': 'font/otf',
  '.eot': 'application/vnd.ms-fontobject', '.txt': 'text/plain; charset=utf-8', '.xml': 'application/xml',
};
const RELOAD_SNIPPET = '<script>new EventSource("/__reload").onmessage=()=>location.reload()</script>';

function serve(port) {
  const clients = new Set();
  const server = http.createServer((req, res) => {
    const url = decodeURIComponent((req.url || '/').split('?')[0]);
    if (url === '/__reload') {
      res.writeHead(200, {'content-type': 'text/event-stream', 'cache-control': 'no-cache', connection: 'keep-alive'});
      res.write('\n'); clients.add(res); req.on('close', () => clients.delete(res)); return;
    }
    let file = path.join(DIST, path.normalize(url));
    if (!file.startsWith(DIST)) { res.writeHead(403); return res.end('Forbidden'); }
    if (exists(file) && fs.statSync(file).isDirectory()) {
      if (!url.endsWith('/')) { res.writeHead(301, {location: url + '/'}); return res.end(); }
      file = path.join(file, 'index.html');
    }
    if (!exists(file)) { res.writeHead(404, {'content-type': 'text/plain'}); return res.end('404 Not found: ' + url); }
    const type = MIME[path.extname(file).toLowerCase()] || 'application/octet-stream';
    res.writeHead(200, {'content-type': type, 'cache-control': 'no-store'});
    if (type.startsWith('text/html')) return res.end(read(file).replace('</body>', RELOAD_SNIPPET + '</body>'));
    fs.createReadStream(file).pipe(res);
  });
  const listen = p => {
    server.once('error', e => { if (e.code === 'EADDRINUSE') listen(p + 1); else throw e; });
    server.listen(p, () => console.log(`[serve] http://localhost:${p}/`));
  };
  listen(port);
  return () => { for (const c of clients) c.write('data: reload\n\n'); };
}

// ---------------------------------------------------------------- main
safeBuild();

if (flag('--watch') || flag('--serve')) {
  const notify = flag('--serve') ? serve(Number(option('--port', 3000))) : () => {};
  let timer = null;
  const schedule = () => {
    clearTimeout(timer);
    timer = setTimeout(() => { if (safeBuild()) notify(); }, 120);
  };
  try {
    fs.watch(SRC, {recursive: true}, schedule);
  } catch {
    // Fallback for platforms without recursive watch: watch every directory.
    const dirs = [SRC, ...listFiles(SRC).map(f => path.dirname(f))];
    for (const d of new Set(dirs)) fs.watch(d, schedule);
  }
  fs.watch(path.join(ROOT, 'build.mjs'), schedule);
  console.log('[watch] watching src/ (Ctrl+C to stop)');
}
