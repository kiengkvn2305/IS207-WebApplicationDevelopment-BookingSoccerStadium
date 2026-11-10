# IS207-WebApplicationDevelopment-BookingSoccerStadium

## Theo dõi lỗi 500

Lỗi không được xử lý sẽ được ghi vào PHP/Apache error log cùng mã `request_id`,
đường dẫn, loại exception, thông báo, file/dòng và stack trace. Khi gặp 500,
đối chiếu mã `request_id` trong phản hồi với log để tìm nguyên nhân.

Trong môi trường phát triển, đặt `APP_DEBUG=true` trong `.env` để API trả thêm
thông tin exception (loại, thông báo, file, dòng và stack trace). Đặt lại
`APP_DEBUG=false` trên môi trường public vì thông tin lỗi có thể tiết lộ cấu
trúc ứng dụng hoặc dữ liệu nội bộ. Với XAMPP, kiểm tra `C:\xampp\apache\logs\error.log`
hoặc đường dẫn được cấu hình bởi `error_log` trong PHP.

## Cập nhật quy tắc đặt và hủy sân trên cơ sở dữ liệu hiện có

Không cần tạo lại database. Với database đã chạy ứng dụng, chạy migration một lần từ thư mục `database`:

```cmd
cd /d C:\xampp\htdocs\san-bong-mvc\database
C:\xampp\mysql\bin\mysql.exe -u root -p san_bong < migrate_booking_cancellation.sql
```

Database cài mới đã có schema tương ứng trong `schema.sql`.

Để bật chức năng đá vãng lai trên database hiện có, chạy một lần migration:

```cmd
cd /d C:\xampp\htdocs\san-bong-mvc\database
C:\xampp\mysql\bin\mysql.exe -u root -p san_bong < migrate_match_guest_players.sql
```

Để bật thanh thông báo cho khách hàng và chủ sân trên database hiện có, chạy migration:

```cmd
cd /d C:\xampp\htdocs\san-bong-mvc\database
C:\xampp\mysql\bin\mysql.exe -u root -p san_bong < migrate_notifications.sql
```