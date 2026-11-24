#!/usr/bin/env node
/**
 * End-to-end test of the Libro de Reclamaciones api against docker-compose.libro-test.yml.
 *
 *   npm run build
 *   docker compose -f docker-compose.libro-test.yml -p capacita-libro up -d --build
 *   node tools/libro-e2e.mjs [--fresh] [--only=ok,strict,mailfail,nosmtp]
 *
 * --fresh recreates the services (empty storage volumes, numbering restarts at 000001).
 * Needs Node >= 18 and docker. Writes docker/libro-test/results.md. Mail is NEVER really delivered:
 * dry-run (.eml files) or an unreachable SMTP port, so real SMTP delivery is NOT covered by this test.
 */
import { execFileSync } from 'node:child_process';
import { writeFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const COMPOSE = path.join(ROOT, 'docker-compose.libro-test.yml');
const PROJECT = 'capacita-libro';
const T = {
  ok: { url: 'http://localhost:8093', container: `${PROJECT}-libro-ok-1` },
  strict: { url: 'http://localhost:8094', container: `${PROJECT}-libro-strict-1` },
  mailfail: { url: 'http://localhost:8095', container: `${PROJECT}-libro-mailfail-1` },
  nosmtp: { url: 'http://localhost:8096', container: `${PROJECT}-libro-nosmtp-1` },
};
const YEAR = String(new Date().getFullYear());
const ST = '/var/www/html/api/storage';
const only = (process.argv.find((a) => a.startsWith('--only=')) || '').slice(7).split(',').filter(Boolean);
const results = [];
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const sh = (cmd, args) => execFileSync(cmd, args, { encoding: 'utf8', env: { ...process.env, MSYS_NO_PATHCONV: '1' } });
const dx = (t, script) => sh('docker', ['exec', t.container, 'sh', '-c', script]).trim();
const claimsIn = (t) => dx(t, `ls ${ST}/hojas/${YEAR}/ 2>/dev/null | grep -c '\.json$' || true`) || '0';

function check(group, name, cond, detail = '') {
  results.push({ group, name, ok: !!cond, detail: String(detail) });
  console.log(`${cond ? 'PASS' : 'FAIL'}  [${group}] ${name}${cond ? '' : '  -> ' + detail}`);
}

class Visitor {
  constructor(t) { this.t = t; this.cookies = new Map(); }
  async req(p, opts = {}) {
    const cookie = [...this.cookies].map(([k, v]) => `${k}=${v}`).join('; ');
    const headers = { Origin: this.t.url, Referer: this.t.url + '/libro-de-reclamaciones/', ...(cookie ? { Cookie: cookie } : {}), ...(opts.headers || {}) };
    const res = await fetch(this.t.url + p, { redirect: 'manual', ...opts, headers });
    for (const sc of res.headers.getSetCookie?.() ?? []) {
      const [pair] = sc.split(';');
      const i = pair.indexOf('=');
      this.cookies.set(pair.slice(0, i), pair.slice(i + 1));
    }
    return res;
  }
  async token() { const r = await this.req('/api/token.php'); return { status: r.status, token: (await r.json().catch(() => ({}))).token }; }
  async submit(fields, { origin = true } = {}) {
    const headers = { 'Content-Type': 'application/x-www-form-urlencoded' };
    if (!origin) { headers.Origin = ''; headers.Referer = ''; }
    const res = await this.req('/api/reclamo.php', { method: 'POST', body: new URLSearchParams(fields), headers });
    return { status: res.status, json: await res.json().catch(() => null) };
  }
}

let seq = 0; // each claim gets distinct content: identical repeats are rejected as duplicates by design
const payload = (token, over = {}) => ({
  token, website_alt: '', name: 'María José Quispe Ñahui', document_type: 'DNI', document_number: '12345678',
  address: 'Av. Prueba 123, Lima', phone: '984 423 725', email: 'consumidor@example.com',
  item_type: 'Servicio', item_description: 'Curso de prueba, módulo I', amount: '150.50',
  claim_type: 'Reclamo', detail: 'No recibí el acceso al aula virtual pese a haber pagado el curso. ÁÉÍÓÚ ñ ¿? #' + (++seq) + '/' + Date.now(),
  request: 'Solicito el acceso inmediato.', consent_truth: '1', consent_privacy: '1', ...over,
});

async function fresh(t, wait = 2600, over = {}) {
  const v = new Visitor(t);
  const { token } = await v.token();
  await sleep(wait);
  return { v, fields: payload(token, over) };
}
const jsonOf = (t, claim) => JSON.parse(dx(t, `cat ${ST}/hojas/${YEAR}/${claim}.json`));
const number = (claim) => Number(claim.split('-')[2]);

async function suiteOk() {
  const t = T.ok, g = 'ok';
  // Static page + API exposure
  const page = await (await fetch(t.url + '/libro-de-reclamaciones/')).text();
  check(g, 'form page served (honeypot + reclamaciones.js)', page.includes('name="website_alt"') && page.includes('reclamaciones.js'));
  for (const p of ['/api/config.php', '/api/storage/counter.dat', '/api/lib/', '/api/src/Notifier.php']) {
    const s = (await fetch(t.url + p)).status;
    check(g, `${p} is not public (403)`, s === 403, s);
  }
  // Success flow
  const a = await fresh(t);
  const ok1 = await a.v.submit(a.fields);
  check(g, 'valid claim -> 200 ok + LR number', ok1.status === 200 && ok1.json?.ok && /^LR-\d{4}-\d{6}$/.test(ok1.json.claim_number), JSON.stringify(ok1));
  const c1 = ok1.json?.claim_number;
  const rec = jsonOf(t, c1);
  check(g, 'sheet archived (PDF starts with %PDF, JSON has the data)', dx(t, `head -c 5 ${ST}/hojas/${YEAR}/${c1}.pdf`) === '%PDF-' && rec.email === 'consumidor@example.com');
  check(g, 'delivery state recorded: company+consumer = dry_run', rec.notifications?.company?.state === 'dry_run' && rec.notifications?.consumer?.state === 'dry_run', JSON.stringify(rec.notifications));
  check(g, 'both .eml written (empresa + consumidor), no pending marker', dx(t, `ls ${ST}/correo | grep -c "${c1}"`) === '2' && dx(t, `ls ${ST}/pendientes 2>/dev/null | wc -l`) === '0');
  const reuse = await a.v.submit(a.fields);
  check(g, 'token is single-use (replay rejected 403)', reuse.status === 403, reuse.status);

  // Validation / abuse
  const b = await fresh(t, 2600, { email: 'not-an-email', detail: 'corto' });
  const bad = await b.v.submit(b.fields);
  check(g, 'invalid fields -> 422 with field errors', bad.status === 422 && bad.json?.errors?.email && bad.json?.errors?.detail, JSON.stringify(bad));
  const before = claimsIn(t);
  const h = await fresh(t, 2600, { website_alt: 'bot' });
  const hp = await h.v.submit(h.fields);
  check(g, 'honeypot -> fake 200 (LR-0000-000000), nothing archived', hp.status === 200 && hp.json?.claim_number === 'LR-0000-000000' && claimsIn(t) === before);
  const n = await fresh(t);
  check(g, 'no Origin/Referer -> 403', (await n.v.submit(n.fields, { origin: false })).status === 403);
  const x = await fresh(t);
  check(g, 'wrong token -> 403', (await x.v.submit({ ...x.fields, token: 'x'.repeat(64) })).status === 403);
  check(g, 'GET reclamo.php -> 405', (await new Visitor(t).req('/api/reclamo.php')).status === 405);

  // Counter recovery
  const last = number(c1);
  dx(t, `rm -f ${ST}/counter.dat`);
  const r1 = await fresh(t); const rr1 = await r1.v.submit(r1.fields);
  check(g, 'counter.dat deleted -> rebuilt from archive, next = last+1 (no reuse)', rr1.json?.ok && number(rr1.json.claim_number) === last + 1, JSON.stringify(rr1));
  dx(t, `printf 'garbage{' > ${ST}/counter.dat`);
  const r2 = await fresh(t); const rr2 = await r2.v.submit(r2.fields);
  check(g, 'corrupt counter.dat -> recovered, next = last+2', rr2.json?.ok && number(rr2.json.claim_number) === last + 2, JSON.stringify(rr2));
  dx(t, `printf '{"year":${YEAR},"number":1}' > ${ST}/counter.dat`);
  const r3 = await fresh(t); const rr3 = await r3.v.submit(r3.fields);
  check(g, 'stale counter (behind the archive) -> never below the archive, next = last+3', rr3.json?.ok && number(rr3.json.claim_number) === last + 3, JSON.stringify(rr3));

  // Archive failure: year dir not writable for www-data
  const dir = `${ST}/hojas/${YEAR}`;
  dx(t, `chown root:root ${dir} && chmod 555 ${dir}`);
  const f = await fresh(t); const nBefore = claimsIn(t); const mailsBefore = dx(t, `ls ${ST}/correo | wc -l`);
  const fail = await f.v.submit(f.fields);
  dx(t, `chown www-data:www-data ${dir} && chmod 750 ${dir}`);
  check(g, 'archive not writable -> 500, claim NOT confirmed', fail.status === 500 && !fail.json?.ok, JSON.stringify(fail));
  check(g, 'archive failure: nothing archived, no e-mail written', claimsIn(t) === nBefore && dx(t, `ls ${ST}/correo | wc -l`) === mailsBefore);
  check(g, 'archive failure logged as "NOT archived"', dx(t, `tail -n 5 ${ST}/log/claims.log`).includes('NOT archived'));
  const retry = await f.v.submit(f.fields);
  check(g, 'same token works again once the archive is writable (token kept)', retry.status === 200 && retry.json?.ok, JSON.stringify(retry));
  const gap = number(retry.json?.claim_number ?? 'LR-0-0') - (last + 3);
  console.log(`INFO  numbering gap after a failed archive: ${gap - 1} number(s) skipped (never reused)`);

  // Kill switch
  dx(t, `touch ${ST}/.disabled`);
  const k = new Visitor(t);
  const kt = await k.token();
  const kp = await k.submit(payload('x'));
  check(g, 'kill switch (storage/.disabled): token.php 503', kt.status === 503, kt.status);
  check(g, 'kill switch: reclamo.php 503 with a message that points to the mailbox', kp.status === 503 && /@/.test(kp.json?.message || ''), JSON.stringify(kp));
  dx(t, `rm -f ${ST}/.disabled`);
  const kr = await fresh(t); const krs = await kr.v.submit(kr.fields);
  check(g, 'kill switch removed -> claims accepted again', krs.status === 200 && krs.json?.ok, JSON.stringify(krs));
}

async function suiteStrict() {
  const t = T.strict, g = 'strict';
  const a = await fresh(t, 0);
  const early = await a.v.submit(a.fields);
  check(g, 'submitted before min fill time (6 s) -> 403', early.status === 403, early.status);
  const b = await fresh(t, 6500);
  const ok = await b.v.submit(b.fields);
  check(g, 'after 6.5 s -> 200', ok.status === 200 && ok.json?.ok, JSON.stringify(ok));
  const c = await fresh(t, 6500, { detail: 'Otro detalle distinto del anterior para evitar duplicado.' });
  const lim = await c.v.submit(c.fields);
  check(g, 'second claim from the same IP within 30 min -> 429 (rate limit)', lim.status === 429, JSON.stringify(lim));
  check(g, '429 message points to the mailbox and no new sheet is archived', /@/.test(lim.json?.message || '') && claimsIn(t) === '1', claimsIn(t));
}

async function suitePending(name, expectState) {
  const t = T[name], g = name;
  const a = await fresh(t);
  const r = await a.v.submit(a.fields);
  check(g, 'claim is registered (200 ok) even though e-mail fails', r.status === 200 && r.json?.ok, JSON.stringify(r));
  check(g, 'response tells the consumer to keep the number (sent=false)', r.json?.sent === false && r.json?.message.includes(r.json.claim_number), JSON.stringify(r.json));
  const c = r.json?.claim_number;
  const rec = jsonOf(t, c);
  check(g, `company + consumer state = ${expectState}`, rec.notifications?.company?.state === expectState && rec.notifications?.consumer?.state === expectState, JSON.stringify(rec.notifications));
  const marker = JSON.parse(dx(t, `cat ${ST}/pendientes/${c}.json`));
  check(g, 'pending marker written with sheet path', marker.claim_number === c && marker.sheet === `hojas/${YEAR}/${c}.json`, JSON.stringify(marker));
  check(g, 'PENDING line in claims.log, PDF archived', dx(t, `tail -n 10 ${ST}/log/claims.log`).includes(`PENDING`) && dx(t, `head -c 5 ${ST}/hojas/${YEAR}/${c}.pdf`) === '%PDF-');
  check(g, 'no .eml files (nothing was delivered)', dx(t, `ls ${ST}/correo 2>/dev/null | wc -l`) === '0');
  check(g, 'storage/pendientes is not public (403)', (await fetch(t.url + '/api/storage/pendientes/' + c + '.json')).status === 403);
}

function up(fresh_) {
  if (fresh_) sh('docker', ['compose', '-f', COMPOSE, '-p', PROJECT, 'down', '-v']);
  sh('docker', ['compose', '-f', COMPOSE, '-p', PROJECT, 'up', '-d', '--build']);
}
async function waitReady(t) {
  for (let i = 0; i < 60; i++) { try { if ((await fetch(t.url + '/api/token.php')).status) return; } catch { /* starting */ } await sleep(1000); }
  throw new Error('service not ready: ' + t.url);
}

(async () => {
  if (process.argv.includes('--fresh')) up(true);
  const run = (k) => !only.length || only.includes(k);
  for (const k of Object.keys(T)) if (run(k)) await waitReady(T[k]);
  if (run('ok')) await suiteOk();
  if (run('strict')) await suiteStrict();
  if (run('mailfail')) await suitePending('mailfail', 'failed');
  if (run('nosmtp')) await suitePending('nosmtp', 'skipped');

  const failed = results.filter((r) => !r.ok);
  const md = ['# Libro de Reclamaciones test results', '', `Run: ${new Date().toISOString()} - PHP 8.1 + Apache (docker-compose.libro-test.yml)`, '',
    `${results.length - failed.length}/${results.length} checks passed. Mail was never really delivered (dry-run or unreachable SMTP).`, '',
    '| Result | Group | Check |', '|---|---|---|', ...results.map((r) => `| ${r.ok ? 'PASS' : 'FAIL'} | ${r.group} | ${r.name}${r.ok ? '' : ' -> ' + r.detail.replace(/\|/g, '/')} |`), ''].join('\n');
  writeFileSync(path.join(ROOT, 'docker/libro-test/results.md'), md);
  console.log(`\n${results.length - failed.length}/${results.length} passed`);
  process.exit(failed.length ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(2); });
