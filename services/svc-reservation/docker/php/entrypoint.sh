#!/bin/sh
set -e

cd /var/www/html

if [ ! -f vendor/autoload.php ]; then
  echo "[entrypoint] vendor missing. Run: docker compose run --rm auth-composer"
  exit 1
fi

mkdir -p storage/framework/cache/data
mkdir -p storage/framework/views
mkdir -p storage/framework/sessions
mkdir -p storage/logs
mkdir -p bootstrap/cache

chown -R www-data:www-data storage bootstrap/cache
chmod -R ug+rwX storage bootstrap/cache

php artisan config:clear || true
php artisan route:clear || true
php artisan cache:clear || true
php artisan view:clear || true

exec php-fpm
