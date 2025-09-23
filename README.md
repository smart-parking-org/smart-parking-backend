# Smart Parking Backend

Đây là monorepo chứa toàn bộ mã nguồn cho phần backend của dự án Smart Parking. Dự án được cấu trúc để quản lý các services một cách tập trung và hiệu quả.

## 🚀 Bắt đầu

### Chạy service `svc-auth` với Laragon (Windows)

```bash
cd services/svc-auth

copy .env.example .env
php artisan key:generate

# Nếu chỉ cần chạy nhanh, chưa cần DB:
# sửa .env:
#   SESSION_DRIVER=file
#   CACHE_STORE=file
#   QUEUE_CONNECTION=sync

php artisan serve --host=127.0.0.1 --port=8001
```

_Mở trình duyệt: http://127.0.0.1:8001 → thấy trang Laravel mặc định ✅_

## 📁 Cấu trúc thư mục

- `/services`: Thư mục chứa mã nguồn của các microservices.
- `...`: (Sẽ cập nhật thêm khi có các thành phần khác).
