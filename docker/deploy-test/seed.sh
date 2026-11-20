#!/bin/sh
# Builds the simulated production docroots. Runs inside the `seed` container (alpine).
#   base  : copy of the CURRENT production docroot (old WP root + /tienda) taken from the wpfiles volume
#   stale : base + `cp -R dist/. docroot/` (what a naive first deploy does: stale WP files remain)
#   clean : base + cutover (stale root WP files MOVED to /backup) + `cp -R dist/. docroot/`
set -eu

# Root-level items moved away during the cutover (must match DEPLOY.md).
# Never moved: tienda, .well-known, cgi-bin, php.ini and .user.ini (cPanel-managed PHP settings).
cutover() {
  DOC="$1"; BK="$2"
  mkdir -p "$BK"
  cd "$DOC"
  for item in wp-admin wp-includes wp-content wp-*.php index.php index.php56 xmlrpc.php \
              license.txt readme.html .htaccess .htaccess.bk .htaccess_old \
              *.zip *.tgz *.sql error_log php_errorlog; do
    for f in $item; do
      [ -e "$f" ] && mv "$f" "$BK/" && echo "moved $f"
    done
  done
  return 0
}

wipe() { find "$1" -mindepth 1 -maxdepth 1 -exec rm -rf {} + ; }

case "${1:-}" in
  base)
    wipe /base
    tar -C /src --exclude='*.zip' --exclude='*.sql' --exclude='*.tgz' --exclude=debug.log \
        --exclude=.atl --exclude=.cpanel.yml --exclude=.gitignore --exclude=docker-compose.yml \
        -cf - . | tar -C /base -xf -
    ;;
  stale)
    wipe /stale
    cp -a /base/. /stale/
    cp -R /dist/. /stale/
    chown -R 33:33 /stale
    ;;
  clean)
    wipe /clean; wipe /backup
    cp -a /base/. /clean/
    cutover /clean /backup
    cp -R /dist/. /clean/
    chown -R 33:33 /clean
    ;;
  *) echo "usage: seed.sh base|stale|clean" >&2; exit 2 ;;
esac
echo "seed $1 done"
