# Libro de Reclamaciones API

Self-contained PHP module (no DB, no Composer). Lives in `src/public/api/`; the build copies
`src/public/**` verbatim to `dist/` root, so it is served at `/api/` (`token.php`, `reclamo.php`).

Flow: token + honeypot + same-origin + rate limit + validation -> correlative `LR-YYYY-NNNNNN` ->
one-page PDF (Ley 29571; Montserrat/Open Sans embedded) -> archived in `storage/hojas/YYYY/` ->
then TWO independent e-mails, company first: one to the internal mailbox (`smtp.internal_bcc`) and one
to the consumer, each with the PDF. A failure of one never blocks the other.

## cPanel setup
1. Create the mailbox (e.g. `reclamaciones@somosescuelacapacita.com`) and match `smtp.*` in `config.php`.
2. Copy `config.secret.example.php` to `config.secret.php` ON THE SERVER; set the password (never commit).
3. Fill every `TODO` in `config.php` (legal name, RUC, address). hCaptcha keys are optional.
4. PHP 8.1+ with `mbstring`, `zlib`, `openssl` (`curl` if hCaptcha is used).
5. `storage/` must be writable by PHP (750/755). `storage/.htaccess` denies all HTTP access.
6. Keep `mail_dry_run` false in production. For local tests set it true (or env `LR_MAIL_DRY_RUN=1`):
   both e-mails are written to `storage/correo/<claim>-empresa.eml` and `<claim>-consumidor.eml`; nothing is sent.
7. Keep the PDFs 2 years (Art. 12, D.S. 011-2011-PCM); back up `storage/hojas/`.

## Reviewing unsent claims (operator)
Each archived `storage/hojas/YYYY/LR-YYYY-NNNNNN.json` has a `notifications` block:
`{company, consumer}`, each with `state` (`sent` | `failed` | `skipped` | `dry_run`), `at` and, on failure,
`error_class` (class only, no secrets). When the company notification is not `sent`/`dry_run`, a marker
`storage/pendientes/<claim>.json` is also written and a `PENDING` line goes to `storage/log/claims.log`.
- One listing finds them: `ls storage/pendientes/`. Open the marker, then the PDF in `storage/hojas/YYYY/`, and
  answer the consumer manually (the sheet holds their e-mail).
- When handled, delete the marker. Never delete the sheet or `storage/counter.dat`.
- If the archive itself cannot be written, the endpoint answers 500, nothing is e-mailed and an `ERROR ... NOT archived`
  line is logged. If the counter is missing/corrupt it is rebuilt from the highest archived sheet of the year;
  if it cannot be read or written the endpoint fails closed (500, no number issued).

## Fonts
`fonts/*.ttf` are Latin (cp1252) subsets of Montserrat and Open Sans (SIL OFL, as in the site kit).
`.php`/`.z` are FPDF definitions: `php tools/makefont/makefont.php fonts/X.ttf cp1252` (dev only).

## Add to the repo root
`.gitignore`:
```
src/public/api/config.secret.php
dist/api/config.secret.php
```
`.cpanel.yml`: nothing required (`cp -R dist/.` never deletes server-side `config.secret.php`/`storage/`).
`storage/**` placeholders are already git-ignored by their own `.gitignore` files; do not commit PDFs.
