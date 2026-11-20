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
