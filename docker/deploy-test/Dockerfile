# Production-like Apache + PHP 8.1 (cPanel EA-PHP 8.1 equivalent) for the cutover simulation.
# Uses the official WordPress image only for its PHP extensions; WordPress itself is NOT auto-installed
# because the entrypoint is replaced by plain apache2-foreground.
FROM wordpress:php8.1-apache
RUN a2enmod rewrite headers expires deflate alias \
 && echo "ServerName localhost" > /etc/apache2/conf-enabled/servername.conf
ENTRYPOINT ["apache2-foreground"]
