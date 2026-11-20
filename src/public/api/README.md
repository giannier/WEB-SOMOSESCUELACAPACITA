# Libro de Reclamaciones API

Self-contained PHP module (no DB, no Composer). Lives in `src/public/api/`; the build copies
`src/public/**` verbatim to `dist/` root, so it is served at `/api/` (`token.php`, `reclamo.php`).

Flow: token + honeypot + same-origin + rate limit + validation -> correlative `LR-YYYY-NNNNNN` ->
one-page PDF (Ley 29571; Montserrat/Open Sans embedded) -> archived in `storage/hojas/YYYY/` ->
e-mailed to the consumer with hidden copy to the company mailbox.

## cPanel setup
1. Create the mailbox (e.g. `reclamaciones@somosescuelacapacita.com`) and match `smtp.*` in `config.php`.
2. Copy `config.secret.example.php` to `config.secret.php` ON THE SERVER; set the password (never commit).
3. Fill every `TODO` in `config.php` (legal name, RUC, address). hCaptcha keys are optional.
4. PHP 8.1+ with `mbstring`, `zlib`, `openssl` (`curl` if hCaptcha is used).
5. `storage/` must be writable by PHP (750/755). `storage/.htaccess` denies all HTTP access.
6. Keep `mail_dry_run` false in production. For local tests set it true (or env `LR_MAIL_DRY_RUN=1`):
   the e-mail is written to `storage/correo/*.eml` and nothing is sent.
7. Keep the PDFs 2 years (Art. 12, D.S. 011-2011-PCM); back up `storage/hojas/`.

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
