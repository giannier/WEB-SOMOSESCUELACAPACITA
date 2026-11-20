"""Generate local wp-configs from the prod ones (originals untouched).

Usage: python docker/gen-configs.py [SOURCE_DIR]   (SOURCE_DIR holds wp-config.php and tienda/wp-config.php;
default: project root; after the cleanup use D:/TRABAJO/RESPALDOS/somosescuelacapacita-wp).
Output (git-ignored, contains prod salts): docker/wp-config.root.php, docker/wp-config.tienda.php
"""
import re, sys, os
SRC = sys.argv[1] if len(sys.argv) > 1 else '.'
def gen(src, dst, db, url):
    s = open(src, encoding='utf-8').read()
    s = re.sub(r"^define\(\s*'WP_TOOLKIT_API_TOKEN'.*\n", "", s, flags=re.M)
    s = re.sub(r"(define\(\s*'DB_NAME',\s*)'[^']*'", r"\1'%s'" % db, s)
    s = re.sub(r"(define\(\s*'DB_USER',\s*)'[^']*'", r"\1'wp'", s)
    s = re.sub(r"(define\(\s*'DB_PASSWORD',\s*)'[^']*'", r"\1'wp'", s)
    s = re.sub(r"(define\(\s*'DB_HOST',\s*)'[^']*'", r"\1'db'", s)
    s = re.sub(r"define\(\s*'WP_DEBUG',\s*false\s*\);",
      "define( 'WP_DEBUG', true );\ndefine( 'WP_DEBUG_LOG', true );\ndefine( 'WP_DEBUG_DISPLAY', false );\n"
      "define( 'WP_HOME', '%s' );\ndefine( 'WP_SITEURL', '%s' );\n"
      "define( 'FORCE_SSL_ADMIN', false );\ndefine( 'WP_CACHE', false );\n"
      "define( 'DISABLE_WP_CRON', true );\ndefine( 'WP_ENVIRONMENT_TYPE', 'local' );\n"
      "define( 'AUTOMATIC_UPDATER_DISABLED', true );\ndefine( 'FS_METHOD', 'direct' );" % (url, url), s)
    assert "WP_HOME" in s and "'db'" in s
    open(dst, 'w', encoding='utf-8', newline='\n').write(s)
gen(os.path.join(SRC,'wp-config.php'),'docker/wp-config.root.php','corpor46_wp536','http://localhost:8080')
gen(os.path.join(SRC,'tienda','wp-config.php'),'docker/wp-config.tienda.php','corpor46_wp936','http://localhost:8080/tienda')
