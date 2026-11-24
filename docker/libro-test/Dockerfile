# Production-like Apache + mod_php (cPanel EA-PHP 8.1 equivalent) for the Libro de Reclamaciones test.
ARG PHP_VERSION=8.1
FROM php:${PHP_VERSION}-apache
RUN a2enmod rewrite headers expires deflate alias \
 && sed -ri "s#AllowOverride None#AllowOverride All#" /etc/apache2/apache2.conf \
 && echo "ServerName localhost" > /etc/apache2/conf-enabled/servername.conf
COPY entrypoint.sh /usr/local/bin/libro-entrypoint.sh
RUN chmod +x /usr/local/bin/libro-entrypoint.sh
ENTRYPOINT ["libro-entrypoint.sh"]
