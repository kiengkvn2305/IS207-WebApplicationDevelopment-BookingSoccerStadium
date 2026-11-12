# Kiểm thử độc lập

Các test không đọc `.env` của dự án. Chúng chỉ kết nối **127.0.0.1:3308 / sannha_test / root / mật khẩu trống**. Chỉ dùng MariaDB instance mới, riêng biệt với dữ liệu thật. Fixtures chỉ tồn tại trong database kiểm thử; không thêm vào dữ liệu của website.

Đã chạy với PHP 8.2.12, MariaDB 10.4.32, Node.js và trình duyệt Chromium tích hợp.

## Chuẩn bị instance trống

Tại thư mục gốc dự án, trong PowerShell, chọn một thư mục tạm chưa tồn tại:

```powershell
$qaData = Join-Path $env:TEMP ('sannha-test-' + [guid]::NewGuid().ToString('N'))
& C:/xampp/mysql/bin/mysql_install_db.exe --datadir=$qaData --port=3308 --silent
& C:/xampp/mysql/bin/mysqld.exe --no-defaults --basedir=C:/xampp/mysql --datadir=$qaData --port=3308 --bind-address=127.0.0.1 --console
```

Giữ terminal đó chạy. Trong terminal khác tại thư mục gốc dự án, tạo bản schema chỉ dùng cho test:

```powershell
$qaSchema = Join-Path $env:TEMP 'sannha-test-schema.sql'
$sql = [IO.File]::ReadAllText((Join-Path (Get-Location) 'database/schema.sql')).Replace('san_bong','sannha_test')
[IO.File]::WriteAllText($qaSchema,$sql,(New-Object System.Text.UTF8Encoding($false)))
$qaSchema = $qaSchema.Replace('\','/')
& C:/xampp/mysql/bin/mysql.exe --host=127.0.0.1 --port=3308 --user=root --default-character-set=utf8mb4 "--execute=source $qaSchema"
& C:/xampp/php/php.exe tests/integration.php
```

Kỳ vọng **28 checks passed**: cấu trúc schema, chuẩn hóa voucher, giới hạn giảm, cấu hình lỗi, hết lượt, khóa transaction, giá mới, rollback, chặn giờ, phiên bản hồ sơ, hủy một lần và hai PHP worker cùng đặt một giờ.

Test tích hợp cần database mới cho mỗi lần chạy vì cố ý giữ dữ liệu để kiểm tra kết quả. Tắt instance cũ trước khi khởi tạo instance mới trên cùng cổng.

## Kiểm thử HTTP

Sau khi integration test đạt, có thể chạy thêm các kiểm tra độc lập sau trên cùng instance:

```powershell
& C:/xampp/php/php.exe tests/match-expiry.php
& C:/xampp/php/php.exe tests/workflows.php
& C:/xampp/php/php.exe tests/match-concurrency.php
```

Kỳ vọng lần lượt **22**, **85**, **2** kiểm tra đạt. Các file chỉ dùng bootstrap database test cổng 3308; workflow/concurrency fixtures được dọn trong finally.

Sau khi integration test thành công, chạy terminal riêng:

```powershell
& C:/xampp/php/php.exe -S 127.0.0.1:8089 -t public tests/http-router.php
```

Sau đó:

```powershell
node tests/http-smoke.mjs
node tests/http-audit.mjs
```

Kỳ vọng **23 HTTP checks passed**: CSRF, JSON lỗi, xác thực, phân quyền, hồ sơ sau tải lại, giờ sai, API lịch, tạo/trùng voucher, dashboard và webhook chưa cấu hình. Node cần hỗ trợ built-in fetch và Headers.getSetCookie (Node 20+).

HTTP audit bổ sung kỳ vọng **189 HTTP audit checks passed**: khám phá 42 endpoint có guard, kiểm tra từng vai trò/CSRF, đọc/chưa đọc thông báo và ownership, registration không nâng quyền, ID/giá sai và upload/xóa ảnh. Nó gọi [http-fixtures.php](./http-fixtures.php) bằng PHP CLI để tạo/dọn fixtures; dùng đường dẫn XAMPP PHP trong file test. Toàn bộ sáu bộ kiểm tra có tổng **349** assertion đạt trong lượt chạy cuối ngày 13/11/2026; xem [báo cáo phạm vi](../docs/SYSTEM-VERIFICATION.md).

Router test chỉ dùng cho PHP development server, không triển khai lên hosting. Dừng các PHP server bằng Ctrl+C; tắt MariaDB kiểm thử bằng `C:/xampp/mysql/bin/mysqladmin.exe --host=127.0.0.1 --port=3308 --user=root shutdown`. Không tắt MySQL của dự án ở cổng khác.

## Kiểm thử trình duyệt đã thực hiện

Kiểm tra riêng lỗi nhận kèo quá giờ, dùng cùng database test đã chuẩn bị:

```powershell
& C:/xampp/php/php.exe tests/match-expiry.php
```

Kỳ vọng **22 match expiry checks passed**. Test cố định đồng hồ của connection DB, kiểm tra trước giờ/đúng giờ/quá giờ, chặn gửi và duyệt lời mời, ngăn mở lại kèo hết hạn, giữ lịch sử người đã được nhận và giữ nguyên booking. Fixtures riêng được dọn sau test; không đọc `.env`.

Đã kiểm tra thêm timer bằng Chromium với response fixture có thời gian máy chủ ngay trước giờ đá: nút nhận kèo xuất hiện trước giờ, tự biến mất khi đến giờ, danh sách tự tải lại và không hiện nút đăng lại/mở nhận người. Việc từ chối ở backend được kiểm tra độc lập bằng 22 test database ở trên.

- Đăng nhập admin, tạo voucher qua form và thấy voucher mới trong bảng.
- Đăng nhập chủ sân, tải lịch và bảng sân từ database.
- Đăng nhập khách, chọn ngày/cụm sân/giờ, xác nhận và nhận mã đơn.
- Tải lại trang, mở hồ sơ phiên bản 2, lưu thành công lên phiên bản 3.
- Kiểm tra tràn ngang của trang đặt sân, admin và chủ sân tại 1920, 1440, 1366, 768, 430 và 375px; không thấy tràn ngang ở các trạng thái đã kiểm tra.

Đây không phải kiểm thử đầy đủ mọi nhánh nghiệp vụ hoặc mọi thiết bị. Giao dịch ngân hàng/SePay thật và database hiện hữu chưa được kiểm thử trong đợt này.
