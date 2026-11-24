# Local Docker environment

Production files are never modified; local overrides live in `docker/` and are bind-mounted.

- Shop (the only WordPress left): http://localhost:8080/tienda | Static site: `npm run dev` (http://localhost:3000) | phpMyAdmin: http://localhost:8081
- DB (host): 127.0.0.1:3307, user `wp` / pass `wp` (root/root)

    docker compose up -d db wordpress          # start
    docker compose --profile tools up -d phpmyadmin
    docker compose down                         # stop (keeps DB)
    docker compose down -v                      # reset DB volume
    docker compose --profile tools run --rm wpcli --path=/var/www/html <cmd>
    docker compose --profile tools run --rm wpcli --path=/var/www/html/tienda <cmd>

Import: `docker exec -i <db> mariadb -uroot -proot corpor46_wp536 < corpor46_wp536.sql` (same for wp936),
then `wp search-replace` prod URL -> localhost URL (tienda first, then root).
`docker/gen-configs.py` regenerates the local wp-config files from the prod ones.

## Fast file layout (named volume `wpfiles`)
WP core/plugins/uploads live in the Docker volume `wpfiles` (bind-mounting the whole folder was 60s+/page).
Only version-controlled custom code is bind-mounted from the project: `tienda/wp-content/mu-plugins`,
`tienda/wp-content/themes/hello-elementor-chilldren` and `docker/wp-config.tienda.php` (local, git-ignored).
Plugin/core/uploads changes happen inside the volume (wp-admin or wpcli) and are NOT written back to the project.

The root WordPress is gone from production (replaced by the static `dist/`). The old root files were moved to
`D:\TRABAJO\RESPALDOS\somosescuelacapacita-wp\`. To run the old root WP again from the volume:
`python docker/gen-configs.py D:/TRABAJO/RESPALDOS/somosescuelacapacita-wp` then
`docker compose -f docker-compose.yml -f docker-compose.legacy-root.yml up -d wordpress`.

Re-sync/reset the volume from the backup folder (slow, ~5 min): `docker compose down`, `docker volume rm web-somosescuelacapacita_wpfiles`, then
`MSYS_NO_PATHCONV=1 docker run --rm -v web-somosescuelacapacita_wpfiles:/dst -v "D:/TRABAJO/RESPALDOS/somosescuelacapacita-wp:/src:ro" alpine sh -c "tar -C /src --exclude='*.zip' --exclude='*.sql' --exclude='*.tgz' --exclude=error_log --exclude=debug.log -cf - . | tar -C /dst -xf - && chown -R 33:33 /dst"`

## Deploy simulation (docker-compose.deploy-test.yml)
Reproduces the production cutover (old WP root + /tienda docroot, then `cp -R dist/. docroot/`). Steps are at the top of
`docker-compose.deploy-test.yml`. `stale` = http://localhost:8090 (stale root WP files left in place), `clean` = http://localhost:8091
(root WP files moved away first). Uses its own volumes (`somosescuelacapacita-deploytest_dt_*`) and the `db` service of the main project.
Delete with `docker compose -f docker-compose.deploy-test.yml down -v`.

## Libro de Reclamaciones test (docker-compose.libro-test.yml)
Runs the real PHP api (`dist/`, so run `npm run build` first) under Apache + mod_php 8.1, separate from the shop stacks.
Project `capacita-libro`; each service has its own storage volume and `dist/` is mounted read-only (`dist/api/storage` never receives data).

    docker compose -f docker-compose.libro-test.yml -p capacita-libro up -d --build
    node tools/libro-e2e.mjs --fresh        # npm run test:libro -- --fresh ; --only=ok,strict,mailfail,nosmtp
    docker compose -f docker-compose.libro-test.yml -p capacita-libro down -v     # stop and drop the volumes

| Service | Port | Mail | Limits | Scenarios |
|---|---|---|---|---|
| `libro-ok` | 8093 | `LR_MAIL_DRY_RUN=1` (.eml in `storage/correo/`) | relaxed (no interval, fill 2 s) | success, validation, honeypot, origin, token reuse, counter recovery (deleted / corrupt / stale), archive failure, kill switch, private URLs |
| `libro-strict` | 8094 | dry-run | production (1 per IP / 30 min, fill 6 s) | too fast -> 403, rate limit -> 429 |
| `libro-mailfail` | 8095 | real sending to a closed SMTP port | relaxed | both e-mails `failed`, claim kept, `pendientes/` marker + `PENDING` log |
| `libro-nosmtp` | 8096 | production config without `config.secret.php` | relaxed | both e-mails `skipped`, marker + log |

- The test copy of `api/config.php` is patched inside the container by `docker/libro-test/entrypoint.sh` (env `LR_TEST_RELAX`, `LR_TEST_SMTP`); the repo files are untouched.
- To inspect by hand: `docker exec capacita-libro-libro-mailfail-1 ls /var/www/html/api/storage/pendientes` (same layout as production: `hojas/`, `correo/`, `log/claims.log`, `counter.dat`).
- Run with `--fresh` (the strict suite expects an empty volume). The script writes `docker/libro-test/results.md`.
- NOT covered: real SMTP delivery (host, certificate, inbox), hCaptcha, LiteSpeed, cPanel permissions.
- Failure injection beyond this (ARBICOP's `LR_TEST_FAIL`) is not ported: archive failure is produced by making the year folder read-only.
