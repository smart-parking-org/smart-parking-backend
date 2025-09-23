# Sử dụng image PHP chính thức 8.2 với FPM (FastCGI Process Manager)
# FPM giúp xử lý PHP nhanh hơn và thường dùng kèm với Nginx
FROM php:8.2-fpm

# Cập nhật package và cài thêm các thư viện cần thiết cho Laravel
# - git, unzip: phục vụ Composer khi tải gói
# - libzip-dev: hỗ trợ extension zip
# - libonig-dev: hỗ trợ regex (trước đây cần cho mbstring)
# - libicu-dev: hỗ trợ extension intl (đa ngôn ngữ, i18n)
# - libpng-dev: xử lý ảnh
# Sau đó cài các extension PHP: pdo, pdo_mysql, intl, zip
RUN apt-get update && apt-get install -y \
    git unzip libzip-dev libonig-dev libicu-dev libpng-dev \
    && docker-php-ext-install pdo pdo_mysql intl zip

# Copy binary Composer từ image composer:2 sang container
# (khỏi cần cài đặt Composer thủ công)
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Đặt thư mục làm việc mặc định trong container là /var/www/html
WORKDIR /var/www/html

# Copy toàn bộ mã nguồn từ máy host vào container
COPY . .

# Cài đặt các dependency Laravel (theo composer.json)
# --no-interaction: không hỏi đáp
# --prefer-dist: tải nhanh hơn bằng cách dùng bản phát hành thay vì clone git
RUN composer install --no-interaction --prefer-dist

# Laravel cần quyền ghi cho 2 thư mục: storage và bootstrap/cache
# Gán quyền cho user www-data (người chạy PHP-FPM trong container)
RUN chown -R www-data:www-data storage bootstrap/cache

# Lệnh mặc định khi container chạy: khởi động PHP-FPM
CMD ["php-fpm"]
