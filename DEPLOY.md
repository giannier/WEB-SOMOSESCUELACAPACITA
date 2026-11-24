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
   - `.cpanel.yml` usa rutas absolutas y **no** crea carpetas: antes del primer despliegue confirma en File Manager que existe `/home/corpor46/somosescuelacapacita.com/tienda/wp-content/mu-plugins/`. Si faltara, el último `cp` falla y cPanel marca el despliegue como fallido (el sitio estático ya habría quedado copiado); créala a mano y repite.
   - Si el botón sale deshabilitado: el repo del servidor debe estar limpio (sin cambios locales) y `.cpanel.yml` debe estar commiteado.
3. Ahora haz el **paso 1.3** (crear `api/config.secret.php`) si aún no existe.

## 5. Smoke test (checklist)

Abre en ventana privada:

- [ ] Anota la versión de PHP del dominio en cPanel > **MultiPHP Manager**, antes y después del cambio; debe ser 8.1 o superior y no cambiar.
- [ ] `/`, `/nosotros/`, `/formaciones/`, `/cursos/`, `/diplomados/`, `/seminarios/`, `/convenios/`, `/postula/`, `/contacto/`, `/politica-de-privacidad/`: cargan con estilos e imágenes.
- [ ] `/robots.txt` y `/sitemap.xml` responden.
- [ ] `/loquesea/` muestra la página 404 del sitio.
- [ ] `/wp-login.php` y `/wp-admin/` dan 404 (esperado).
- [ ] `/api/config.php`, `/api/storage/` y `/api/lib/` dan 403 (esperado).
- [ ] `/tienda/` carga la tienda; `/tienda/wp-login.php` carga; el panel `/tienda/wp-admin/` entra con tus credenciales.
- [ ] **Compra de prueba**: en Micuentaweb cambia a **modo TEST** (Back Office > Configuración > Tienda > Modo TEST), usa tarjeta de prueba del PSP, completa un pedido en `/tienda/checkout/` y verifica que el pedido pasa a *Procesando/Completado* y llega el correo. **Vuelve a modo PRODUCCIÓN al terminar**.
- [ ] **Moodle**: con un usuario de prueba, comprueba que el pedido de prueba lo matricula en el curso (y el correo de matrícula del tema hijo). Elimina/anula el pedido y el usuario de prueba después.
- [ ] **Libro de reclamaciones**: en `/libro-de-reclamaciones/` envía un reclamo de prueba; deben llegar DOS correos: uno a `reclamaciones@` (aviso a la empresa, con el PDF) y otro al correo ingresado (con el PDF); son independientes. Si no llega el de la empresa, queda un archivo en `api/storage/pendientes/` (ver `src/public/api/README.md`). Si no llega: revisa `api/storage/log/claims.log` (File Manager) y la contraseña/host SMTP del paso 1.
- [ ] Tras 10 minutos: Search Console / Analytics sin picos de 404.

Notas:
- La **sincronización del catálogo/tienda** (productos, precios, enlaces desde el sitio estático) es una fase posterior; hoy el sitio solo enlaza a `/tienda/`.
- **URLs antiguas**: las páginas principales conservan la misma ruta (`/nosotros/`, `/contacto/`, `/cursos/`, `/diplomados/`, `/seminarios/`, `/formaciones/`, `/convenios/`, `/postula/`, `/libro-de-reclamaciones/`). Ya hay 301 de `/inicio/` a `/`. Rutas del WordPress viejo **sin equivalente** (darán 404): `/in-house/`, `/preview/`, `/sample-page/`, `/shop/`, `/cart/`, `/checkout/`, `/my-account/` (eran páginas de WooCommerce del raíz; la tienda real vive en `/tienda/`) y la entrada `/AAAA/MM/DD/hello-world/`. Si algo estaba indexado en Google, agrega `RewriteRule` 301 en `src/public/.htaccess` (por ejemplo `^in-house/?$ /formaciones/ [R=301,L]`) y vuelve a desplegar.
- `dist/.htaccess` se copia en **cada** despliegue y reemplaza el `.htaccess` del docroot. Si cPanel (MultiPHP Manager) había escrito ahí un bloque con la versión de PHP, se pierde: tras cada despliegue vuelve a abrir MultiPHP Manager, confirma la versión anotada (si cambió, vuelve a guardarla) y comprueba que `/tienda/` y `/api/token.php` responden.
- Estos servidores pueden usar LiteSpeed (el `.htaccess` viejo traía bloques LSCACHE): `.htaccess` es compatible, pero la prueba local se hizo con Apache; verifica los puntos del smoke test.

## 6. ROLLBACK (volver al WordPress anterior)

> **ATENCIÓN: `api/storage/` contiene los reclamos (PDF/JSON) y el correlativo `counter.dat`: son registros legales que se conservan 2 años (Art. 12, D.S. 011-2011-PCM). NUNCA los muevas, borres ni sobrescribas, y nunca borres `counter.dat`. Tampoco muevas `api/config.secret.php`.**

Cuando quieras deshacer (todo está en `_backup_wp_root`):

```bash
cd /home/corpor46/somosescuelacapacita.com
# 0) respaldo extra de estado (copia, no mueve) por si algo sale mal
mkdir -p /home/corpor46/_backup_api_state
cp -a api/storage /home/corpor46/_backup_api_state/ && cp -a api/config.secret.php /home/corpor46/_backup_api_state/
# 1) quita lo que puso el sitio estático en la raíz (NO toques tienda/, .well-known/, cgi-bin/, api/storage ni api/config.secret.php)
mkdir -p /home/corpor46/_backup_static
for f in index.html 404.html robots.txt sitemap.xml .htaccess assets contacto convenios cursos          diplomados formaciones libro-de-reclamaciones nosotros politica-de-privacidad postula seminarios; do
  [ -e "$f" ] && mv -v "$f" /home/corpor46/_backup_static/
done
# 1b) de api/ mueve SOLO el código; storage/ y config.secret.php se quedan en su sitio
mkdir -p /home/corpor46/_backup_static/api
for f in api/* api/.[!.]*; do
  case "$f" in api/storage|api/config.secret.php) continue;; esac
  [ -e "$f" ] && mv -v "$f" /home/corpor46/_backup_static/api/
done
# 2) devuelve el WordPress viejo (incluye .htaccess original)
cd /home/corpor46/_backup_wp_root && for f in * .[!.]*; do [ -e "$f" ] && mv -v "$f" /home/corpor46/somosescuelacapacita.com/; done
```

Verifica `/` (WordPress) y `/tienda/`, y que `api/storage/` y `api/config.secret.php` siguen en su sitio (el WordPress viejo no los usa; déjalos ahí). La base `corpor46_wp536` nunca se tocó. Para recuperar todo desde cero usa el *Full Backup* del paso 0.

Para volver a poner el sitio estático después: repite 3 y 4. Como `storage/` y `config.secret.php` no se movieron, el correlativo y los reclamos archivados continúan; no hace falta recrearlos. Si por error se movieron, restáuralos desde `_backup_api_state/` **antes** de recibir nuevos reclamos.

## Interruptor de emergencia del Libro de Reclamaciones (kill switch)

Sirve para dejar de aceptar reclamos de inmediato (abuso masivo, fallo del archivado, SMTP comprometido) **sin borrar ni mover nada** y sin tocar el resto del sitio.

**Apagar** (cPanel > File Manager, o Terminal):

```bash
touch /home/corpor46/somosescuelacapacita.com/api/storage/.disabled
```

- `api/token.php` y `api/reclamo.php` responden 503. En la página, el formulario muestra "No pudimos preparar el formulario... escríbenos a reclamaciones@somosescuelacapacita.com". La página y el resto del sitio siguen en línea.
- `api/storage/` es solo del servidor: ningún despliegue crea ni borra `.disabled`, así que el interruptor sobrevive a nuevos despliegues.
- **Reactivar**: borra solo ese archivo (`rm .../api/storage/.disabled`) y envía un reclamo de prueba.
- Mientras esté apagado, atiende los reclamos que lleguen a `reclamaciones@` y regístralos cuando reactives (la obligación legal de responder en 15 días hábiles sigue corriendo). Si el apagado va a durar, deja un aviso visible en la página y avisa al consumidor por correo.
- Alternativa solo temporal (un despliegue la deshace): renombrar `api/reclamo.php` a `api/reclamo.php.off`.

## Revisión de pendientes (Libro de Reclamaciones)

Cada reclamo se archiva **antes** de enviar correos. Si falla el aviso a la empresa (`reclamaciones@`) —SMTP caído, contraseña cambiada, buzón lleno o `config.secret.php` ausente— el reclamo sigue registrado y queda un marcador `api/storage/pendientes/<número>.json` más una línea `PENDING` en `api/storage/log/claims.log`. Nadie recibe un aviso automático: hay que revisarlo.

**Cuándo:** una vez por semana, y siempre después de cambiar el SMTP (contraseña, host, puerto) o de un despliegue que toque `api/`. La respuesta al consumidor vence en 15 días hábiles (Ley 29571): un pendiente olvidado es un incumplimiento.

```bash
cd /home/corpor46/somosescuelacapacita.com/api/storage
ls pendientes/ 2>/dev/null | wc -l          # cuántos marcadores hay (0 = todo entregado)
ls pendientes/                              # números de reclamo pendientes
cat pendientes/LR-2026-000001.json          # estado de cada correo (sent | failed | skipped) y ruta de la hoja
grep -E "PENDING|ERROR" log/claims.log | tail -20
```

1. Abre el marcador: indica qué correo falló (`company` / `consumer`) y la clase del error. Corrige la causa (contraseña/host SMTP en `config.secret.php` / `config.php`) y haz un reclamo de prueba.
2. La hoja está en `api/storage/hojas/AAAA/<número>.pdf` (descárgala por File Manager) y los datos en `<número>.json` (incluye el correo del consumidor).
3. Envía tú la constancia: a la empresa (si no llegó) y al consumidor, desde `reclamaciones@somosescuelacapacita.com`, adjuntando el PDF.
4. Cuando esté atendido, **borra solo el marcador** de `pendientes/`. Nunca borres ni muevas las hojas, `counter.dat` ni `config.secret.php`.
5. Si el archivo `<número>.json` de la hoja no existe pero hubo un error `NOT archived` en el log, el reclamo **no** quedó registrado y el consumidor vio un error: escríbele para que lo reenvíe.

El reenvío automático no existe aún (a diferencia de ARBICOP): es un procedimiento manual. Los escenarios que generan estos marcadores se prueban en local (ver `docker/README.md`).

## Revertir un despliegue (rollback del sitio estático)

`git revert` + *Deploy HEAD Commit* **no** borra lo que ya se copió (`cp` nunca borra): sobrescribe los archivos que cambian, pero un archivo que el commit revertido había **añadido** queda en el servidor. Pasos:

1. En tu PC: `git revert <commit>` (no uses `reset --force` sobre `main`), `npm run build` y `npm run check` si la reversión toca `src/`, commit incluyendo `dist/`, `git push`.
2. cPanel > Git Version Control > *Update from Remote* > *Deploy HEAD Commit*.
3. Revisa el smoke test (paso 5). Para quitar un archivo sobrante, muévelo (no lo borres) a `/home/corpor46/_backup_static/`.
4. Para el libro de reclamaciones usa primero el kill switch (arriba) si el problema está en `api/`.
5. La tienda: el despliegue solo copia el tema hijo y `elementor-safe-mode.php`. Si el tema hijo rompe la tienda, activa el tema padre `hello-elementor` desde `/tienda/wp-admin/` > Apariencia > Temas (si no puedes entrar, renombra la carpeta `hello-elementor-chilldren` y WordPress cae a un tema por defecto) o mueve `mu-plugins/elementor-safe-mode.php` a `_backup_static/`; no repitas el despliegue hasta corregirlo. El rollback completo al WordPress anterior es el paso 6.

## Mantenimiento habitual

1. Edita `src/`, ejecuta `npm run build` y `npm run check`. Si tocaste `.cpanel.yml`, `tienda/` o `src/public/api/`: `npm run check:deploy` (valida `.cpanel.yml`: solo `cp`, rutas absolutas, nada se borra) y, para `api/`, `npm run test:libro` con Docker (ver `docker/README.md`).
2. Commit **incluyendo `dist/`**, push, y en cPanel: *Update from Remote* > *Deploy HEAD Commit*.
3. Tras desplegar: repite el control de MultiPHP Manager (ver nota del paso 5) y revisa los pendientes del libro de reclamaciones una vez por semana (sección "Revisión de pendientes").
