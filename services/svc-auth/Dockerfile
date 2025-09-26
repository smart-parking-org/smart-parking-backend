# ------------------------------
# PHP-FPM 8.2 cho Laravel (Local Dev Fast)
# ------------------------------
FROM php:8.2-fpm

# 1) Hệ thống & PHP extensions cần cho Laravel
RUN apt-get update && apt-get install -y \
    git unzip libzip-dev libicu-dev libpng-dev \
 && docker-php-ext-install pdo pdo_mysql intl zip \
 # phpredis để dùng REDIS_CLIENT=phpredis
 && pecl install redis \
 && docker-php-ext-enable redis \
 # OPcache
 && docker-php-ext-install opcache \
 && rm -rf /var/lib/apt/lists/*

# 2) PHP config: OPcache + một số tối ưu dev
RUN { \
  echo 'opcache.enable=1'; \
  echo 'opcache.enable_cli=0'; \
  echo 'opcache.memory_consumption=256'; \
  echo 'opcache.max_accelerated_files=20000'; \
  echo 'opcache.validate_timestamps=1'; \
  echo 'opcache.revalidate_freq=2'; \
} > /usr/local/etc/php/conf.d/opcache.ini

# 3) Composer CLI
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# 4) Thư mục làm việc trỏ vào code (sẽ bind-mount từ host)
WORKDIR /var/www/html

# Copy trước composer.* để tận dụng layer cache khi build
COPY composer.json composer.lock ./
# Nếu muốn nhanh nhất, build không có dev deps:
# RUN composer install --no-interaction --prefer-dist --no-dev --optimize-autoloader
# Nếu vẫn cần dev deps, dùng dòng dưới:
RUN composer install --no-interaction --prefer-dist --optimize-autoloader --no-scripts

COPY . .

# 5) Entrypoint: tự cài vendor nếu volume rỗng rồi chạy php-fpm
# -> bạn không cần vào container chạy lệnh
RUN php artisan package:discover --ansi || true \
 && chown -R www-data:www-data storage bootstrap/cache

CMD ["php-fpm"]
