# Hướng dẫn cài đặt (Backend)

Yêu cầu:

- Máy có Docker Desktop và Docker Compose đã cài và đang chạy.
- Xác định địa chỉ IP nội bộ (LAN) của máy để cấu hình callback URL.

## Cấu hình .env

Mở file .env tương ứng và thay hai biến sau bằng địa chỉ IP mạng của máy (ví dụ: 192.168.20.13):

```env
VNPAY_RETURN_URL=http://<YOUR_MACHINE_IP>:8002/api/payments/return
VNPAY_IPN_URL=http://<YOUR_MACHINE_IP>:8002/api/payments/ipn
```

Ví dụ:

```env
VNPAY_RETURN_URL=http://192.168.20.13:8002/api/payments/return
VNPAY_IPN_URL=http://192.168.20.13:8002/api/payments/ipn
```

## Khởi chạy dịch vụ

Mở terminal (PowerShell / CMD) tại thư mục chứa docker-compose và chạy:

```powershell
docker compose up -d
```

## Kiểm tra dịch vụ

Sau khi khởi chạy thành công, kiểm tra Swagger UI:

- http://localhost:8001/api/documentation
- http://localhost:8002/api/documentation

Nếu thấy giao diện Swagger API là backend đã chạy thành công.

## Seed cơ sở dữ liệu

Chạy lần lượt các lệnh sau để migrate và seed dữ liệu:

```powershell
docker compose exec auth-app php artisan migrate:fresh --seed
docker compose exec payment-app php artisan migrate:fresh --seed
```

## Dữ liệu mẫu sau khi seed

- Tài khoản Admin:
  - Email: admin@gmail.com
  - Mật khẩu: 12345678
- Các dữ liệu sẵn có: cổng, bãi đỗ, slot, chính sách giá, cấu hình giờ cao điểm, chính sách giá hạn

Dùng DBeaver kết nối vào 2 csdl MySQL sau:

DB svc-auth

- Server Host=localhost
- Port=33061
- Database=auth_db
- Username=auth
- Password=auth

DB svc-payment

- Server Host=localhost
- Port=33061
- Database=payment_db
- Username=payment
- Password=payment

Khi nhập đầy đủ thông tin và bấm Test Connection, nếu cảnh báo tải drive hãy chấp nhận để tải. Nếu cảnh báo Public Key thì hãy vào tab Driver properties ở dòng allowPublicKeyRetrival đổi giá trị thành TRUE. Sau đó bấm test lại. Sau khi thành công bấm finish

## Lưu ý

- Đảm bảo địa chỉ IP trong .env là IP có thể truy cập từ thiết bị mobile/frontend nếu cần callback.
- Nếu thay đổi cổng hoặc cấu hình mạng, cập nhật URL tương ứng trong .env và chạy chạy lại

```powershell
docker compose up -d
```

## Bước tiếp theo

Sau khi backend hoạt động, tiếp tục chạy frontend và mobile theo hướng dẫn tương ứng trong các repo frontend/mobile.
