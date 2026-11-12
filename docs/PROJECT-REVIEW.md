# Rà soát và nâng cấp Sân Nhà

Ngày kiểm tra: 12/11/2026. Phạm vi: cải tiến ứng dụng hiện hữu, giữ PHP MVC, routes, dữ liệu và quy tắc thanh toán trực tiếp tại sân.

## 1. Cấu trúc thực tế

- [public/index.php](../public/index.php) nhận request; [bootstrap](../app/bootstrap.php) đọc cấu hình, autoload class và mở session; [routes](../app/routes.php) điều phối trang/API.
- [core](../app/core/) cung cấp PDO prepared statements, transaction qua stored routines, session, CSRF, JSON và guard vai trò.
- [controllers](../app/controllers/) kiểm tra request, phân quyền, gọi model và trả JSON/HTML.
- [models](../app/models/) chứa truy vấn và nghiệp vụ; [views](../app/views/) là HTML, không chứa SQL. [JavaScript](../public/assets/js/) gọi API bằng fetch và dựng dữ liệu đã escape. Không có framework, Composer, bundler, service hay repository riêng.
- MySQL/MariaDB InnoDB: users/owner_profiles/pitches, bookings/booking_slots/unavailable_slots, vouchers/voucher_redemptions, payments/cancel_requests/sepay_webhook_events, venue_images/amenities/reviews, match_challenges/requests, notifications/audit_logs/login_attempts.
- Chức năng thực tế: khách xem/lọc lịch, đặt sân, dùng voucher, xem lịch cá nhân, hủy hai bước; đánh giá, tìm đối thủ và cầu thủ vãng lai; chủ sân quản lý sân/ảnh/tiện ích/ngân hàng/khung giờ, hủy/đối soát; admin duyệt/khóa tài khoản, quản lý đánh giá/voucher và xem thống kê/nhật ký.

Luồng đặt sân: UI chọn ngày/giờ → BookingController kiểm tra vai trò/CSRF/dữ liệu → Booking khóa và đọc lại sân khả dụng → tính giá từ DB → khóa voucher → kiểm tra giờ chủ sân đóng → ghi bookings và booking_slots, tăng lượt voucher trong cùng transaction → commit → ghi nhật ký/thông báo → UI hiển thị mã đơn. Khóa chính `(pitch_id,date,hour)` tiếp tục là ràng buộc cuối cùng chống đặt trùng.

## 2. Lỗi phát hiện và đã sửa

| Lỗi | Ảnh hưởng | Chỉnh sửa |
|---|---|---|
| Nút tạo voucher không có type=submit nhưng JS tìm đúng thuộc tính này | Null.disabled, form không gửi | Khai báo submit rõ ràng |
| Pattern voucher dùng dấu gạch ngang chưa escape theo regex v của HTML hiện đại | Console báo lỗi, mất validation frontend | Escape gạch ngang, giới hạn 3–32 ký tự |
| /api/me thiếu profile_version | Sau lần cập nhật đầu và tải lại, UI gửi version=1, bị 409 | Trả version thực tế |
| CSRF cho phép so sánh hai chuỗi rỗng khi token session chưa tạo | Có thể vượt kiểm tra token trước lần lấy /me | Bắt buộc token tồn tại và khác rỗng |
| HTTPS=off vẫn được xem là HTTPS | Cookie secure có thể không hoạt động trên HTTP | Xét chính xác giá trị off; bật strict session |
| body() có thể trả scalar JSON cho return type array | Request sai gây 500 | Chỉ chấp nhận JSON object, trả 400 |
| Giờ bị intval ép từ số thập phân, dữ liệu scalar, giờ trùng | Request sai được chuyển thành giờ khác | Kiểm tra số nguyên, array và không trùng trước xử lý |
| Pitch được đọc trước transaction rồi chỉ khóa ID | Có thể đặt sân vừa tắt hoặc tính giá từ snapshot cũ | Đọc lại sân/owner/ngân hàng và giá trong locking read |
| Voucher nhận subtotal âm, loại/mức giảm và ngày không hợp lệ từ DB | Có thể trả giá sai | Kiểm tra cấu hình, chặn subtotal âm, yêu cầu transaction khi khóa |
| Hai request đổi ngày có thể trả về ngược thứ tự | UI hiển thị lịch của ngày cũ | Số thứ tự request, bỏ response cũ, hiện trạng thái lỗi rõ |
| UI không cập nhật lịch khi API trả 409 | Khách tiếp tục thấy giờ vừa bị chiếm | Giữ HTTP status trong Error, đóng dialog và tải lại lịch |
| Trang đặt sân không có vùng flash | Thông báo thành công thành alert trình duyệt | Thêm vùng status rõ ràng |
| CSS tích lũy nhiều lớp màu xanh/đỏ và logo MU tự vẽ, footer cố định che nội dung | Xung đột thiết kế, nhận diện sai | Bỏ các khối theme chồng chéo, thêm design system, logo nguồn gốc và footer trong luồng trang |
| Schema thiếu image_path, venue_images, venue_reviews và sepay_webhook_events/routines | Cài mới xong vẫn lỗi runtime | Schema cài mới đầy đủ, đã import kiểm thử |
| Cuối schema có DELETE audit_logs/bookings/pitches/users | Nguy cơ mất dữ liệu khi import | Bỏ các câu lệnh thao tác thử |
| Đăng ký thiếu giới hạn email/tên cụm sân, password có thể vượt giới hạn bcrypt | 500 hoặc bị cắt mật khẩu | Giới hạn backend, từ chối password trên 72 byte |
| Đăng nhập/đăng ký không khóa nút trong lúc gửi | Có thể gửi nhiều request khi nhấn nhanh | Disable nút và khôi phục trong finally |

Đã sửa cả mô tả admin sai rằng đánh giá chỉ dành cho đơn đã thanh toán. Giữ đúng nghiệp vụ hiện hữu: khách đăng nhập được đánh giá, không tự ý thêm điều kiện đã mua.

## 3. Thay đổi giao diện và tích hợp

Hero Old Trafford, CTA tìm sân/tìm đồng đội, ba bước đặt sân, màu đỏ #DA291C, nền #090A0D/#101114 và điểm nhấn #FBE122. Giữ chế độ sáng/tối; mặc định tối nếu chưa chọn. Thẻ cụm sân hiển thị ảnh do chủ sân cung cấp, giá thấp nhất của giờ còn trống, số sân và đánh giá thực tế; thiếu ảnh thì ghi rõ, không dùng ảnh sân khác làm dữ liệu giả. Sắp xếp giá/đánh giá trên tập dữ liệu API hiện có.

Admin/chủ sân có điều hướng theo khu vực, sidebar trên desktop, bảng cuộn trong vùng trên mobile. Admin có tìm kiếm trong tối đa 100 tài khoản đã tải, đúng giới hạn backend hiện hữu. Không bổ sung thống kê giả hoặc biểu đồ trang trí. Thống kê tiền giữ định nghĩa cũ là tổng các đơn trạng thái paid, chưa phải báo cáo kế toán gồm phí hủy/hoàn tiền.

Nguồn ảnh, bản quyền logo và yêu cầu trước khi dùng thương mại nằm tại [ASSET-SOURCES](../public/assets/ASSET-SOURCES.md). Ảnh Old Trafford tải cục bộ ở 3840px; logo SVG giữ nguyên hình/tỷ lệ. Font Barlow tiếp tục dùng vì phù hợp typography thể thao và không cần thêm thư viện.

## 4. File đã thay đổi hoặc thêm

| Nhóm | File |
|---|---|
| Guard và request | [.htaccess](../.htaccess), [Auth.php](../app/core/Auth.php), [Controller.php](../app/core/Controller.php) |
| Backend và dữ liệu | [AuthController](../app/controllers/AuthController.php), [BookingController](../app/controllers/BookingController.php), [OwnerController](../app/controllers/OwnerController.php), [Booking](../app/models/Booking.php), [Pitch](../app/models/Pitch.php), [Voucher](../app/models/Voucher.php), [schema](../database/schema.sql) |
| Views | [booking](../app/views/booking.html), [login](../app/views/login.html), [admin](../app/views/admin.html), [owner](../app/views/owner.html) |
| Styles và scripts | [style.css](../public/assets/css/style.css), [united.css](../public/assets/css/united.css), [app.js](../public/assets/js/app.js), [booking.js](../public/assets/js/booking.js), [login.js](../public/assets/js/login.js), [admin.js](../public/assets/js/admin.js), [owner.js](../public/assets/js/owner.js) |
| Assets | [Old Trafford](../public/assets/old-trafford.jpg), [crest](../public/assets/manchester-united-crest.svg), [nguồn](../public/assets/ASSET-SOURCES.md) |
| Tests và tài liệu | [integration](../tests/integration.php), [HTTP smoke](../tests/http-smoke.mjs), [test bootstrap](../tests/bootstrap.php), [test router](../tests/http-router.php), [hướng dẫn test](../tests/README.md), [README](../README.md), báo cáo này |

## 5. Bằng chứng kiểm thử

- PHP lint: toàn bộ PHP trong app/public/tests không lỗi cú pháp. Node --check: toàn bộ JavaScript và HTTP smoke test không lỗi cú pháp. VS Code Problems không báo lỗi tại lần kiểm tra.
- Import schema vào MariaDB 10.4.32 **riêng biệt** ở cổng 3308, database sannha_test thành công. Không thay đổi .env, không kết nối hoặc ghi dữ liệu thật của dự án.
- **28 kiểm tra integration thành công**, gồm hai PHP process thực sự cùng đặt sân, rollback khi trùng giờ, chỉ tiêu thụ một lượt voucher, đọc giá mới, từ chối sân tắt, giờ chủ sân khóa, hủy/replay token, mở lại giờ đã hủy và hồ sơ version cũ.
- **23 kiểm tra HTTP thành công**, gồm CSRF rỗng/sai, JSON lỗi, khách chưa đăng nhập, khách gọi API admin, giờ thập phân/trùng/scalar/không liên tiếp, đăng nhập ba vai trò, API dashboard/slots, tạo voucher và mã trùng.
- Trình duyệt: admin tạo voucher qua form thành công; chủ sân tải dashboard; khách chọn sân/giờ và nhận mã đơn; khách tải lại rồi cập nhật hồ sơ version 2 thành công.
- Kiểm tra document overflow của ba trang đặt sân/admin/chủ sân ở **1920, 1440, 1366, 768, 430, 375px**: không tràn ngang ở trạng thái được kiểm tra. Bảng vẫn có thể cuộn ngang trong container.

Fixtures là dữ liệu kiểm thử trên instance tạm. Kết quả không phải chứng nhận toàn bộ hệ thống đã sẵn sàng production.

## 6. Chưa kiểm thử và giới hạn còn lại

- MySQL/Apache của ứng dụng ban đầu chưa chạy: API /slots tại cổng phát triển của cấu hình thật trả lỗi kết nối DB. Chỉ kiểm thử đầy đủ trên MariaDB độc lập; chưa xác nhận database hiện hữu đã có các migration nào.
- Chưa thực hiện chuyển khoản ngân hàng hoặc webhook SePay thật; cần kiểm tra hợp đồng chữ ký/timestamp với nhà cung cấp và đúng tài khoản nhận tiền của từng chủ sân. Chưa mô phỏng toàn bộ nhánh hoàn tiền, hủy sát giờ hoặc tải ảnh trong browser.
- Chưa regression toàn bộ luồng ghép đội/cầu thủ vãng lai và cạnh tranh duyệt người. Chưa load test, audit bảo mật độc lập hay thử tất cả trình duyệt/thiết bị.
- Một số notification/audit được ghi sau transaction chính. Nếu bước này thất bại sau commit, client có thể nhận lỗi dù giao dịch chính thành công. Cần xử lý bằng outbox/idempotency trong một đợt có phạm vi riêng; khóa giờ vẫn ngăn đặt trùng cùng giờ.
- Một số migration cũ có ALTER/CREATE không idempotent. Không chạy hàng loạt vào database đang có dữ liệu; schema mới chỉ dùng cho cài mới.
- Ảnh hero 4K khoảng 3,95 MB; tải cục bộ và chỉ dùng ở hero, ảnh sân lazy-load. Có thể tiếp tục cung cấp AVIF/WebP và srcset trước triển khai mạng di động. Font Google và VietQR vẫn cần mạng.
- Logo Manchester United không có giấy phép thương mại được xác nhận; dự án giáo dục không tự động có quyền dùng khi triển khai thương mại. Footer đã ghi nền tảng độc lập.
- Chưa xác nhận MySQL 5.7/8.0; stored routine đã được chạy bằng MariaDB 10.4.32. Không đổi hệ quản trị hay kiến trúc để né vấn đề tương thích.

## 7. Chạy và chuẩn bị demo

Thực hiện theo [README](../README.md). Với database cũ, sao lưu trước, kiểm tra phiên bản schema; không import schema lên dữ liệu hiện hữu. Để chạy test riêng, dùng [tests/README](../tests/README.md).

- [ ] Bật Apache/MySQL, kiểm tra /api/me và /api/slots không lỗi.
- [ ] Kiểm tra đủ bảng/cột/stored routines, đổi mật khẩu seed ngoài môi trường demo.
- [ ] Chủ sân nhập ngân hàng, địa chỉ, ảnh và tọa độ thực nếu cần lọc khoảng cách.
- [ ] Admin tạo voucher hợp lệ; kiểm tra mã hết hạn, hết lượt, giá trị tối thiểu và trùng mã.
- [ ] Khách chọn ngày mai khi demo sau 22:00; đặt một giờ, xem mã đơn và lịch cá nhân.
- [ ] Mở hai phiên đặt cùng giờ; chỉ một đơn thành công, phiên còn lại nhận thông báo xung đột.
- [ ] Thử sai mật khẩu, chưa đăng nhập, gọi URL admin bằng tài khoản khách, input không hợp lệ.
- [ ] Thử hủy trước hơn hai giờ và sát giờ; đối chiếu quy tắc phí 70%, không giả lập đã nhận tiền.
- [ ] Kiểm tra điện thoại, bàn phím/focus, trạng thái dữ liệu rỗng, ảnh và kết nối mạng.
- [ ] Khi vấn đáp: giải thích front controller → guard → controller → model → transaction → khóa chính slot; giải thích khóa voucher, rollback, optimistic profile version và giới hạn các test đã chạy.
