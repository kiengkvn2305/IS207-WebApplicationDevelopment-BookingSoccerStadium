# IS207-WebApplicationDevelopment-BookingSoccerStadium

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