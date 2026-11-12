# IS207-WebApplicationDevelopment-BookingSoccerStadium

Ứng dụng đặt sân PHP MVC, JavaScript thuần và MySQL/MariaDB. Không cần npm hoặc Composer để chạy website.

## Chạy bằng XAMPP

1. Bật **MySQL** và **Apache** trong XAMPP. PHP cần `pdo_mysql`, `mbstring`, `fileinfo` và session; đã kiểm tra bằng PHP 8.2.12 và MariaDB 10.4.32.
2. Nếu chưa có cấu hình, sao chép [.env.example](.env.example) thành `.env`, điền thông tin database. Giữ `APP_DEBUG=false` khi public.
3. **Chỉ với database cài mới, trống:** import [database/schema.sql](database/schema.sql) bằng phpMyAdmin hoặc lệnh sau trong **Command Prompt**:

   ```cmd
   cd /d C:\xampp\htdocs\san-bong-mvc
   C:\xampp\mysql\bin\mysql.exe -u root -p < database\schema.sql
   ```

   Schema hiện đã chứa đủ bảng, cột và stored routines. Không chạy lại các migration cũ trên database vừa cài bằng schema này. Với database đang dùng, sao lưu và chỉ chạy migration còn thiếu theo lịch sử cài đặt; một số migration cũ không chạy lặp được.
4. Mở `http://localhost/san-bong-mvc/public/`. Apache cần `mod_rewrite` và cho phép `.htaccess`. Khi cấu hình virtual host, trỏ DocumentRoot vào [public/](public/).
5. Tài khoản seed phục vụ demo: `admin@sannha.vn` / `Admin@12345`; `chusan@sannha.vn` và `khach@sannha.vn` / `Demo@12345`. Đổi mật khẩu khi sử dụng ngoài môi trường demo. Chủ sân phải nhập đủ tài khoản ngân hàng để sân xuất hiện; ảnh và địa chỉ do chủ sân nhập.

Có thể chạy PHP server từ thư mục dự án sau khi MySQL đã hoạt động:

```powershell
& C:/xampp/php/php.exe -S 127.0.0.1:8088 -t public public/index.php
```

Mở `http://127.0.0.1:8088/`. PHP server này chỉ dành cho phát triển.

Xem [báo cáo rà soát và checklist demo](docs/PROJECT-REVIEW.md), [hướng dẫn kiểm thử độc lập](tests/README.md) và [nguồn/giấy phép hình ảnh](public/assets/ASSET-SOURCES.md).

Đợt bổ sung ngày 13/11/2026: đọc/chưa đọc thông báo, typography/background, xác nhận thanh toán tại sân, hủy đơn 0 đồng và khóa duyệt kèo đồng thời. Xem [349 kiểm tra nghiệp vụ/HTTP và phạm vi xác nhận](docs/SYSTEM-VERIFICATION.md), cùng [quy chuẩn code để review](docs/CODING-STANDARDS.md).

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

### Stored routines quản lý giao dịch DB

Ứng dụng dùng các stored procedure `sannha_tx_begin`, `sannha_tx_commit`,
`sannha_tx_rollback` để quản lý ranh giới transaction và function
`sannha_cancel_fee` để tính phí hủy 70% với cùng quy tắc làm tròn ở database.
FUNCTION chỉ tính toán; trong MySQL/MariaDB, FUNCTION không được commit hoặc
rollback transaction. Sau khi tạo database bằng `schema.sql`, hãy cài routine:

```cmd
cd /d C:\xampp\htdocs\san-bong-mvc
C:\xampp\mysql\bin\mysql.exe -u root -p san_bong < database\migrate_transaction_routines.sql
```

Migration có thể chạy lại an toàn. Các thao tác đặt sân, thanh toán, hủy lịch,
voucher, kèo, đăng ký tài khoản và cập nhật ảnh/tiện ích dùng chung các routine
này thông qua lớp `Database`.

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

Để phát hiện và ngăn ghi đè hồ sơ từ biểu mẫu cũ khi có nhiều phiên cùng chỉnh sửa, chạy migration:

```cmd
cd /d C:\xampp\htdocs\san-bong-mvc\database
C:\xampp\mysql\bin\mysql.exe -u root -p san_bong < migrate_concurrency_safety.sql
```

Để bật lọc giá/vị trí, lý do khóa khung giờ và voucher trên database hiện có,
chạy migration sau khi đã áp dụng các migration `migrate_pitch_availability.sql`,
`migrate_venue_features.sql` và các migration nghiệp vụ đặt sân hiện có:

```cmd
cd /d C:\xampp\htdocs\san-bong-mvc\database
C:\xampp\mysql\bin\mysql.exe -u root -p san_bong < migrate_search_vouchers.sql
```

Sau đó, chủ sân nhập tọa độ hoặc dùng nút lấy vị trí để khách có thể lọc theo
bán kính. Quản trị viên có thể tạo, tắt/bật voucher; mức giảm, giá trị tối thiểu,
thời gian áp dụng và giới hạn lượt sử dụng được kiểm tra lại trong giao dịch đặt sân.
