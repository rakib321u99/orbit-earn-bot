FROM php:8.3-apache

# MySQL driver for the bot's database (TiDB Cloud / any MySQL).
RUN docker-php-ext-install pdo_mysql \
    && a2enmod rewrite \
    && apt-get update -q && apt-get install -y -q ca-certificates && rm -rf /var/lib/apt/lists/*

COPY . /var/www/html/
RUN chown -R www-data:www-data /var/www/html \
    && mkdir -p /var/www/html/uploads/proofs \
    && chown -R www-data:www-data /var/www/html/uploads

COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

EXPOSE 80
ENTRYPOINT ["docker-entrypoint.sh"]
CMD ["apache2-foreground"]
