# Escuela Capacita – sitio estático

Clon estático de https://somosescuelacapacita.com (WordPress + Elementor + Hello). La tienda (`/tienda/`) sigue siendo un WordPress aparte y **no** forma parte de este proyecto: los enlaces apuntan a `/tienda/` (en el servidor local de desarrollo dan 404, en producción funcionan). Las tipografías (Montserrat, Open Sans) se cargan desde Google Fonts.

## Comandos

```bash
npm run dev     # compila, vigila cambios y sirve en http://localhost:3000 (recarga automática)
npm run build   # genera dist/
npm run watch   # compila al guardar, sin servidor
npm run check   # verifica que dist/ no tenga assets faltantes
```

Solo requiere Node >= 18 (sin dependencias).

## Estructura

```
src/layouts/base.html          <head>, scripts y esqueleto (usa {{header}} {{content}} {{footer}})
src/layouts/header.html        cabecera compartida (el ítem activo se marca solo)
src/layouts/header-home.html   cabecera del inicio (mismos textos, ids de Elementor del inicio)
src/layouts/footer.html        pie compartido (footer-home.html = pie + barra de copyright)
src/layouts/joinchat.html      botón flotante de WhatsApp
src/pages/*.html               una página = front matter + contenido
src/assets/                    css, js, fuentes e imágenes (se copian tal cual)
src/public/                    .htaccess, robots.txt, sitemap.xml, 404.html y api/ (Libro de Reclamaciones)
src/site.json                  valores globales ({{site.name}}, {{site.whatsapp}}...)
```

## Editar header / footer

Edita `src/layouts/header.html` o `footer.html`; el cambio se aplica a todas las páginas. Los estilos compartidos están en `src/assets/css/layout.css`. Tus ajustes propios: `src/assets/css/custom.css` (se carga al final).

Variantes: el inicio usa `header: header-home` y `footer: footer-home`. Cursos, Diplomados y Seminarios llevan `menu_parent: formaciones/` para marcar "Formaciones" como padre activo del menú.

## Agregar una página

Crea `src/pages/mi-pagina.html`:

```html
---
title: Mi página – Escuela Capacita
description: Texto para SEO (opcional)
elementor_id: 134
body_class: page elementor-page
---
<section>...contenido principal...</section>

<!-- @slot head -->
<link rel="stylesheet" href="{{root}}assets/css/pages/mi-pagina.css">
```

Sale como `dist/mi-pagina/index.html` (URL limpia `/mi-pagina/`). Slots opcionales: `head`, `scripts`, `before_header`, `after_footer`. Añade el enlace en `header.html` con `href="{{root}}mi-pagina/"`. Opciones de front matter: `path`, `header`, `footer`, `layout`, `menu_parent`.

## Formularios y WhatsApp

Contacto y Postula tenían formularios de Elementor (PHP). Ahora llevan `data-whatsapp="{{site.whatsapp}}"` y `assets/js/forms.js` valida y abre `wa.me` con el mensaje armado (número en `src/site.json`). En Postula el CV no puede adjuntarse: se pide enviarlo en la conversación. El botón flotante de WhatsApp (Joinchat) conserva su número y mensaje en `src/layouts/joinchat.html`.

El Libro de Reclamaciones usa su propio backend PHP en `src/public/api/` (ver `src/public/api/README.md`).

## Despliegue

`dist/` **se versiona** (cPanel solo copia archivos, no ejecuta npm). Cada cambio: edita `src/` → `npm run build` → commit (incluye `dist/`).

`dist/` debe quedar como raíz del dominio. Importante al sustituir el WordPress actual de la raíz: `/tienda/` conserva su propio WordPress y `.htaccess`, así que no hay que borrar ni sobrescribir esa carpeta. El `.htaccess` incluido usa `DirectoryIndex index.html` (evita el conflicto con el `index.php` de WordPress), no reescribe nada hacia `/tienda/` y bloquea `api/storage`, `api/lib` y `api/src`.
