FROM php:8.2-fpm

RUN apt-get update && apt-get install -y \
    git unzip libzip-dev libicu-dev libpng-dev \
 && docker-php-ext-install pdo pdo_mysql intl zip \
 && rm -rf /var/lib/apt/lists/*

RUN { \
  echo 'opcache.enable=1'; \
  echo 'opcache.enable_cli=0'; \
  echo 'opcache.memory_consumption=256'; \
  echo 'opcache.max_accelerated_files=20000'; \
  echo 'opcache.validate_timestamps=1'; \
  echo 'opcache.revalidate_freq=2'; \
} > /usr/local/etc/php/conf.d/opcache.ini

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY docker/php/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]

CMD ["php-fpm"]
