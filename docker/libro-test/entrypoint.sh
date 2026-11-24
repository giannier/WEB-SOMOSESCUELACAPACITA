#!/bin/sh
# dist/ is mounted read-only at /dist. It is copied to the docroot so that the TEST COPY of api/config.php can be adjusted
# per scenario without touching the project. The repo's config.php is never modified. storage/ is a named volume
# (mounted over the copy) so runtime data persists between restarts and never reaches dist/.
set -e
cp -a /dist/. /var/www/html/

CFG=/var/www/html/api/config.php
if [ "$LR_TEST_RELAX" = "1" ]; then
  # relaxed limits: no per-IP interval, short fill time (only the test copy)
  sed -i \
    -e "s/'interval_seconds' => 1800/'interval_seconds' => 0/" \
    -e "s/'per_day'          => 5/'per_day'          => 1000/" \
    -e "s/'global_per_hour'  => 20/'global_per_hour'  => 1000/" \
    -e "s/'global_per_day'   => 80/'global_per_day'   => 1000/" \
    -e "s/'min_fill_seconds' => 6/'min_fill_seconds' => 2/" "$CFG"
fi
if [ "$LR_TEST_SMTP" = "unreachable" ]; then
  # real (non dry-run) sending against a closed port: every send fails fast, as a dead SMTP would
  sed -i -e "s/'host'        => 'mail.somosescuelacapacita.com'/'host'        => '127.0.0.1'/" -e "s/'port'        => 465/'port'        => 9/" "$CFG"
  printf '<?php\nreturn ["smtp_password" => "test-only"];\n' > /var/www/html/api/config.secret.php
fi
# Named volumes mount as root: hand storage/ to Apache (cPanel gives PHP its own user).
mkdir -p /var/www/html/api/storage
chown -R www-data:www-data /var/www/html/api/storage /var/www/html/api/config.secret.php 2>/dev/null || true
exec apache2-foreground
