# Quy chuẩn code và cách review

Ứng dụng tiếp tục dùng PHP MVC, JavaScript thuần và CSS; không thêm framework hoặc build step để chạy website.

## PHP

- Áp dụng **PSR-12**, 4 dấu cách; khai báo visibility rõ ràng, một statement mỗi dòng, khối điều kiện có braces.
- Controller kiểm tra request/CSRF/vai trò, gọi model và trả response. View không chứa SQL. Query dùng prepared statements qua `Database::q`.
- Dùng tên rõ nghĩa cho code mới: `$user`, `$body`, `$parameters`, `$statement`, `$challengeId`. Giữ tên method công khai để không làm hỏng routes hoặc caller hiện hữu.
- Với nghiệp vụ nhiều bảng, giữ chung transaction; rollback rồi ném lại exception. Với duyệt kèo, khóa kèo chung trước khi khóa từng lời mời, sau đó mới đếm người đã được nhận.
- Không ghép input người dùng thành SQL. Các mảnh SQL như `NULL`/`COALESCE` phải được lựa chọn từ giá trị cố định trong code.
- Không đưa password, thông tin kết nối hoặc raw dữ liệu nhạy cảm vào response/log công khai.

PHP CS Fixer **3.95.30** đã được dùng cho toàn bộ PHP trong app/public/tests. Cấu hình nằm tại [.php-cs-fixer.dist.php](../.php-cs-fixer.dist.php). Tool phar dùng tạm ngoài repository; không cần cài để chạy website.

```powershell
php php-cs-fixer.phar fix --config=.php-cs-fixer.dist.php --dry-run --diff
```

## JavaScript, HTML và CSS

- Prettier **3.10.1**, 2 dấu cách, dấu nháy đơn trong JavaScript và print width 100 theo [.prettierrc.json](../.prettierrc.json).
- Khối thông báo được tách thành [notifications.js](../public/assets/js/notifications.js); [app.js](../public/assets/js/app.js) giữ tiện ích API, session, theme và hồ sơ dùng chung.
- Dùng event delegation cho danh sách động. Tránh lồng button trong button. Giữ label, focus, Escape và trạng thái disabled khi chờ request.
- Escape dữ liệu trước khi đưa vào HTML. URL thông báo chỉ cho phép các trang nội bộ đã định trước.
- Request đọc cũ không được ghi đè kết quả mutation mới. Với thông báo, POST trả luôn danh sách/counter đã lưu để UI cập nhật trong một response.
- Typography dùng `--font-body` và `--font-heading`; màu và nền dùng design token. Font WOFF2 có hỗ trợ tiếng Việt được lưu cục bộ kèm giấy phép.

```powershell
prettier --check "public/assets/js/*.js" "public/assets/css/*.css" "app/views/*.html" "tests/*.mjs"
```

[.editorconfig](../.editorconfig) quy định UTF-8, LF và indentation; [.gitattributes](../.gitattributes) giữ newline nhất quán giữa Windows và Git.

## Thứ tự review

1. Đọc các thay đổi chức năng trong [SYSTEM-VERIFICATION](./SYSTEM-VERIFICATION.md), sau đó xem diff logic của notification, thanh toán/hủy và khóa kèo.
2. Có thể dùng `git diff -w` để giảm phần thay đổi khoảng trắng khi review PHP/CSS/JS; HTML cũng được bung dòng nên cần đọc view đầy đủ nếu đánh giá markup.
3. Chạy formatter ở chế độ check, PHP lint/Node check và bộ test mô tả trong [tests/README](../tests/README.md).
4. Kiểm tra dữ liệu rỗng/sai, thiếu quyền, CSRF sai, gửi lại request và hai phiên cùng thao tác. Chỉ xác nhận hành vi đã thực sự kiểm thử.
