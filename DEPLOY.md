# Guía de despliegue en cPanel (Escuela Capacita)

Resultado final: `somosescuelacapacita.com` pasa de WordPress a un sitio **estático** (carpeta `dist/`) y `/tienda/` **sigue siendo WordPress** (WooCommerce), sin tocarse.

- Docroot: `/home/corpor46/somosescuelacapacita.com`
- Repo (fuera del docroot): `/home/corpor46/repos/somosescuelacapacita`
- Respaldo del WordPress viejo: `/home/corpor46/_backup_wp_root`

> Regla de oro: **nunca borres, mueve**. Todo se puede deshacer (paso 6).

## 0. Respaldos (obligatorio)

1. cPanel > **Backup** > *Download a Full Account Backup* (o Backup Wizard). Guárdalo en tu PC.
2. cPanel > **phpMyAdmin** > selecciona `corpor46_wp936` (tienda) > **Exportar** > Rápido > SQL > Continuar.
3. Repite con `corpor46_wp536` (WordPress raíz). Ya no se usará, pero **no la borres**.
4. Pausa cambios en la tienda (pedidos de prueba, ediciones) hasta terminar.

## 1. Correo de reclamaciones y secretos del libro de reclamaciones

1. cPanel > **Email Accounts** > Create: `reclamaciones@somosescuelacapacita.com` (contraseña larga; guárdala).
2. En **Email Accounts > Connect Devices** de ese correo, confirma el servidor de salida SMTP (SSL, puerto 465). El código asume `mail.somosescuelacapacita.com`; si cPanel muestra otro host (por ejemplo el del servidor), se cambia `smtp.host` en `src/public/api/config.php` y se vuelve a desplegar. Si el certificado no coincide con el host, el envío fallará (ver el log del paso 5).
3. Cuando el paso 4 haya copiado los archivos: cPanel > **File Manager** > `somosescuelacapacita.com/api/`:
   - Copia `config.secret.example.php` como `config.secret.php`.
   - Edita `config.secret.php`: solo `smtp_password` = contraseña del correo. `mail_dry_run` debe quedar en `false`. hCaptcha es opcional (déjalo vacío).
   - Permisos de `config.secret.php`: 640. `api/storage/` y subcarpetas: 750 (escribibles por PHP).
4. Este archivo **nunca** va al repo (está ignorado) y `cp -R dist/.` **no lo borra** en futuros despliegues.
5. Completa los datos legales de `src/public/api/config.php` (razón social, RUC, dirección ya vienen cargados; revísalos).

## 2. Crear el repositorio en cPanel

1. Sube el proyecto a tu Git remoto (GitHub/GitLab) con el commit que preparaste.
2. cPanel > **Git™ Version Control** > **Create**.
3. *Clone a Repository* = activado.
   - **Clone URL**: `https://github.com/TU-USUARIO/somosescuelacapacita.git` (si es privado usa una *deploy key* SSH: `git@github.com:TU-USUARIO/somosescuelacapacita.git` y registra la clave pública que cPanel genera).
   - **Repository Path**: `/home/corpor46/repos/somosescuelacapacita` (**fuera** del docroot).
   - **Repository Name**: `somosescuelacapacita`.
4. **Create**. Espera a que termine el clonado. Rama: `main`.

## 3. CUTOVER: mover el WordPress raíz (NO borrar)

Hazlo en cPanel > **Terminal** (o File Manager, uno por uno). Mueve **solo** esto desde `/home/corpor46/somosescuelacapacita.com` a `/home/corpor46/_backup_wp_root`:

| Qué | Archivos / carpetas |
|---|---|
| Núcleo WordPress | `wp-admin/`, `wp-includes/`, `wp-content/` |
| Archivos `wp-*.php` | `wp-activate.php wp-blog-header.php wp-comments-post.php wp-config-sample.php wp-config.php wp-cron.php wp-links-opml.php wp-load.php wp-login.php wp-mail.php wp-settings.php wp-signup.php wp-trackback.php` |
| Otros | `index.php`, `index.php56`, `xmlrpc.php`, `license.txt`, `readme.html` |
| Reglas Apache viejas | `.htaccess`, `.htaccess.bk`, `.htaccess_old` |
| Basura/dumps (si existen) | `error_log`, `php_errorlog`, `*.zip`, `*.tgz`, `*.sql` |

**NO mover**: `tienda/`, `.well-known/` (SSL), `cgi-bin/`, `php.ini` y `.user.ini` (los gestiona cPanel), ni ninguna otra carpeta que no esté en la tabla (subdominios, otros sitios).

```bash
cd /home/corpor46/somosescuelacapacita.com
ls -A                      # revisa qué hay antes de mover
mkdir -p /home/corpor46/_backup_wp_root
for f in wp-admin wp-includes wp-content wp-*.php index.php index.php56 xmlrpc.php \
         license.txt readme.html .htaccess .htaccess.bk .htaccess_old \
         error_log php_errorlog *.zip *.tgz *.sql; do
  [ -e "$f" ] && mv -v "$f" /home/corpor46/_backup_wp_root/
done
ls -A                      # debe quedar: tienda, .well-known, cgi-bin, php.ini (y poco más)
```

Importante: `wp-content` raíz (con sus uploads) queda en el respaldo; las imágenes del sitio nuevo ya están en `dist/assets/`.

## 4. Deploy HEAD Commit

1. cPanel > **Git Version Control** > *Manage* en `somosescuelacapacita` > pestaña **Pull or Deploy**.
2. **Update from Remote** (trae el último commit) y luego **Deploy HEAD Commit**.
   - Ejecuta `.cpanel.yml`: copia `dist/` al docroot y el código propio de la tienda (tema hijo `hello-elementor-chilldren` + mu-plugin `elementor-safe-mode.php`) a `/tienda/wp-content/`. Solo `cp`: no borra nada.
   - Si el botón sale deshabilitado: el repo del servidor debe estar limpio (sin cambios locales) y `.cpanel.yml` debe estar commiteado.
3. Ahora haz el **paso 1.3** (crear `api/config.secret.php`) si aún no existe.

## 5. Smoke test (checklist)

Abre en ventana privada:

- [ ] `/`, `/nosotros/`, `/formaciones/`, `/cursos/`, `/diplomados/`, `/seminarios/`, `/convenios/`, `/postula/`, `/contacto/`, `/politica-de-privacidad/`: cargan con estilos e imágenes.
- [ ] `/robots.txt` y `/sitemap.xml` responden.
- [ ] `/loquesea/` muestra la página 404 del sitio.
- [ ] `/wp-login.php` y `/wp-admin/` dan 404 (esperado).
- [ ] `/api/config.php`, `/api/storage/` y `/api/lib/` dan 403 (esperado).
- [ ] `/tienda/` carga la tienda; `/tienda/wp-login.php` carga; el panel `/tienda/wp-admin/` entra con tus credenciales.
- [ ] **Compra de prueba**: en Micuentaweb cambia a **modo TEST** (Back Office > Configuración > Tienda > Modo TEST), usa tarjeta de prueba del PSP, completa un pedido en `/tienda/checkout/` y verifica que el pedido pasa a *Procesando/Completado* y llega el correo. **Vuelve a modo PRODUCCIÓN al terminar**.
- [ ] **Moodle**: con un usuario de prueba, comprueba que el pedido de prueba lo matricula en el curso (y el correo de matrícula del tema hijo). Elimina/anula el pedido y el usuario de prueba después.
- [ ] **Libro de reclamaciones**: en `/libro-de-reclamaciones/` envía un reclamo de prueba; debe llegar el PDF al correo ingresado y copia a `reclamaciones@`. Si no llega: revisa `api/storage/log/claims.log` (File Manager) y la contraseña/host SMTP del paso 1.
- [ ] Tras 10 minutos: Search Console / Analytics sin picos de 404.

Notas:
- La **sincronización del catálogo/tienda** (productos, precios, enlaces desde el sitio estático) es una fase posterior; hoy el sitio solo enlaza a `/tienda/`.
- **URLs antiguas**: las páginas principales conservan la misma ruta (`/nosotros/`, `/contacto/`, `/cursos/`, `/diplomados/`, `/seminarios/`, `/formaciones/`, `/convenios/`, `/postula/`, `/libro-de-reclamaciones/`). Ya hay 301 de `/inicio/` a `/`. Rutas del WordPress viejo **sin equivalente** (darán 404): `/in-house/`, `/preview/`, `/sample-page/`, `/shop/`, `/cart/`, `/checkout/`, `/my-account/` (eran páginas de WooCommerce del raíz; la tienda real vive en `/tienda/`) y la entrada `/AAAA/MM/DD/hello-world/`. Si algo estaba indexado en Google, agrega `RewriteRule` 301 en `src/public/.htaccess` (por ejemplo `^in-house/?$ /formaciones/ [R=301,L]`) y vuelve a desplegar.
- Estos servidores pueden usar LiteSpeed (el `.htaccess` viejo traía bloques LSCACHE): `.htaccess` es compatible, pero la prueba local se hizo con Apache; verifica los puntos del smoke test.

## 6. ROLLBACK (volver al WordPress anterior)

Cuando quieras deshacer (todo está en `_backup_wp_root`):

```bash
cd /home/corpor46/somosescuelacapacita.com
# 1) quita lo que puso el sitio estático en la raíz (NO toques tienda/, .well-known/, cgi-bin/)
mkdir -p /home/corpor46/_backup_static
for f in index.html 404.html robots.txt sitemap.xml .htaccess api assets contacto convenios cursos \
         diplomados formaciones libro-de-reclamaciones nosotros politica-de-privacidad postula seminarios; do
  [ -e "$f" ] && mv -v "$f" /home/corpor46/_backup_static/
done
# 2) devuelve el WordPress viejo (incluye .htaccess original)
cd /home/corpor46/_backup_wp_root && for f in * .[!.]*; do [ -e "$f" ] && mv -v "$f" /home/corpor46/somosescuelacapacita.com/; done
```

Verifica `/` (WordPress) y `/tienda/`. La base `corpor46_wp536` nunca se tocó. Para recuperar todo desde cero usa el *Full Backup* del paso 0.

Para volver a poner el sitio estático después: repite 3 y 4.

## Mantenimiento habitual

1. Edita `src/`, ejecuta `npm run build` y `npm run check`.
2. Commit **incluyendo `dist/`**, push, y en cPanel: *Update from Remote* > *Deploy HEAD Commit*.
