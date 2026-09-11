FROM php:8.3-apache

RUN docker-php-ext-install mysqli \
    && cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

WORKDIR /var/www/html
COPY . .
COPY deploy/apache.conf /etc/apache2/conf-available/vetrix.conf
COPY deploy/php.ini /usr/local/etc/php/conf.d/vetrix.ini
RUN a2enconf vetrix \
    && a2dismod -f mpm_event mpm_worker \
    && a2enmod mpm_prefork \
    && apache2ctl configtest \
    && sed -i 's/\r$//' deploy/start.sh

ENV VETRIX_APP_ENV=production
EXPOSE 80
CMD ["sh", "deploy/start.sh"]
