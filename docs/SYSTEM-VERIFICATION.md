# Rà soát nghiệp vụ và giao diện — 13/11/2026

Đợt này xử lý thông báo đọc/chưa đọc, typography/background, quy chuẩn code và kiểm tra các miền nghiệp vụ hiện có. Kiến trúc PHP MVC và dữ liệu ứng dụng được giữ nguyên. Đây là phần bổ sung cho [báo cáo nền](./PROJECT-REVIEW.md).

## Thay đổi chức năng

| Nhóm | Kết quả |
|---|---|
| Thông báo | Từng thông báo có checkbox: tick là đã đọc, bỏ tick là chưa đọc. Nhãn chuyển thành hành động tương ứng. Nút toàn bộ đổi giữa “đánh dấu tất cả đã đọc/chưa đọc”. |
| Lưu trạng thái | Thêm POST unread và unread-all. Mọi thao tác giới hạn theo user và có guard/CSRF. POST trả danh sách/counter đã lưu; request đọc cũ không được ghi đè mutation mới. Lỗi mạng khôi phục checkbox và hiện lỗi. |
| Thanh toán tại sân | Sửa lỗi chủ sân chỉ xác nhận được đơn pending cũ. Đơn confirmed mới nay có nút xác nhận đã thanh toán và ghi payments đúng một lần, phục vụ lịch sử/doanh thu và hoàn tiền. Không thêm yêu cầu khách trả trước. |
| Hủy đơn miễn phí | Khi voucher giảm 100%, phí hủy là 0 thì hủy trực tiếp; không đưa khách vào trạng thái chờ chuyển khoản 0 đồng. |
| Cạnh tranh duyệt kèo | Test hai PHP process đã tái hiện deadlock khi chọn đối thủ. Sửa thứ tự khóa: khóa kèo chung trước từng lời mời, đọc số người sau khi đã giữ khóa. Hai phiên chỉ nhận một đối thủ hoặc một cầu thủ cho suất cuối. |
| Input API | Từ chối ID dạng array/object/boolean/số lẻ, object bất thường ở field scalar và giá sân thập phân. |
| Voucher | Từ chối thiếu/null active khi bật/tắt; thao tác đặt lại cùng trạng thái hợp lệ không bị coi là lỗi. |

Kèo vẫn tự đóng nhận đối thủ/cầu thủ khi đến giờ bắt đầu, không cho mở lại kèo quá giờ. Những người đã được nhận vẫn được lưu trong lịch sử.

## Typography, background và code style

- Body: **Be Vietnam Pro** 400/500/600/700; heading: **Barlow Condensed** 600/700. Có subset tiếng Việt, font-display swap, WOFF2 local và OFL. Không phụ thuộc Google Fonts khi chạy trang.
- Giữ hero ảnh Old Trafford có nguồn/giấy phép; bổ sung nền SVG đường sân và lưới sân vận động, điểm đỏ trầm và bảng màu thống nhất sáng/tối. Nền trang không thay dữ liệu ảnh sân thật.
- PHP theo PSR-12; JavaScript/CSS/HTML theo Prettier. Thêm EditorConfig, Git attributes và cấu hình formatter. Notification UI tách khỏi tiện ích dùng chung; logic chuyển trạng thái và khóa được gom thành helper nhỏ.
- Chi tiết review và lệnh check nằm tại [CODING-STANDARDS](./CODING-STANDARDS.md).

## Kết quả tự động

Môi trường: PHP 8.2.12, MariaDB 10.4.32 riêng tại 127.0.0.1:3308, database sannha_test; Node.js cho test HTTP. Import schema vào instance trống trước lượt chạy cuối. Không sửa .env hoặc đưa fixtures vào database đang dùng của ứng dụng.

| Bộ kiểm tra | Số kiểm tra đạt | Phạm vi |
|---|---:|---|
| [integration.php](../tests/integration.php) | 28 | Schema, giá mới, voucher, rollback, trùng giờ, khóa giờ, version hồ sơ, hủy/replay token, hai process đặt cùng giờ |
| [match-expiry.php](../tests/match-expiry.php) | 22 | Trước/đúng/quá giờ, đóng lời mời chờ, chặn nhận/mở lại, giữ lịch sử accepted |
| [workflows.php](../tests/workflows.php) | 85 | Tài khoản, hash/rate-limit, duyệt chủ sân, ngân hàng, notification, voucher, thanh toán, hủy/hoàn, ghép đội, đánh giá, tiện ích/ảnh, xử lý webhook ở model |
| [match-concurrency.php](../tests/match-concurrency.php) | 2 | Hai process duyệt đối thủ và cầu thủ cùng lúc, chỉ một người được nhận |
| [http-smoke.mjs](../tests/http-smoke.mjs) | 23 | Login ba vai trò, JSON lỗi, CSRF, giờ sai, API lịch/dashboard, voucher trùng, webhook chưa cấu hình |
| [http-audit.mjs](../tests/http-audit.mjs) | 189 | Guard ẩn danh trên 42 endpoint, ma trận vai trò/CSRF, notification persistence/ownership, ID sai, đăng ký không tự lên admin, owner chờ duyệt, upload ảnh hợp lệ/giả và xóa ảnh |
| **Tổng** | **349** | Các assertion nghiệp vụ và HTTP; không tính kiểm tra trình duyệt/formatter bên dưới |

Đếm số kiểm tra không đồng nghĩa mọi tổ hợp đầu vào đã được chứng minh đúng. Các test giữ phạm vi cụ thể, không sử dụng dữ liệu thật để tạo giao dịch giả.

## Kiểm tra trình duyệt và bản XAMPP

- Chromium xác nhận tick/bỏ tick đổi label và read_at/counter; bulk read/unread hoạt động. Khi POST bị giả lập lỗi 503, checkbox khôi phục và hiện lỗi, không báo thành công giả.
- Font tiếng Việt tải từ asset local và được document.fonts xác nhận đã load. Trang đặt sân/hộp thông báo không tràn ngang, panel nằm trong viewport ở 1920, 1440, 1366, 768, 430 và 375px.
- Đã kiểm tra các API công khai của bản XAMPP tại localhost/san-bong-mvc/public: me, slots, top-rated, matches đều trả kết quả bình thường. Không đăng ký tài khoản hoặc tạo đặt sân giả trên database này.
- Các lần trước đã kiểm tra browser tạo voucher, đặt sân, cập nhật profile sau tải lại và tự hết hạn kèo. Các test mới tập trung thêm vào notification, typography và lỗi cạnh tranh được phát hiện.

## Phạm vi chưa xác nhận

- Chưa chuyển khoản hoặc nhận webhook từ ngân hàng/SePay thật. Test model kiểm tra xử lý số tiền, loại giao dịch và idempotency; nó không xác nhận hợp đồng chữ ký, tài khoản nhận tiền, hoặc hạ tầng của nhà cung cấp.
- Chưa load test hoặc kiểm thử mọi tổ hợp chạy đồng thời giữa hủy/duyệt/thanh toán; chưa kiểm tra tất cả trình duyệt/thiết bị và lỗi mất kết nối database ngay giữa commit.
- Một số audit/notification của nghiệp vụ khác vẫn chạy sau commit chính; muốn đảm bảo giao nhận bền vững khi DB lỗi ở bước sau cần cơ chế outbox. Không coi kết quả này là chứng nhận production hay audit bảo mật độc lập.
- Một số migration cũ không idempotent; không import schema mới hoặc chạy hàng loạt migration lên database hiện hữu. Đợt này không thêm migration cho notification vì dùng lại read_at sẵn có.
- Hero JPEG 4K còn khoảng 3,95 MB; có thể bổ sung phiên bản AVIF/WebP/srcset ở bước tối ưu ảnh riêng. Logo MU vẫn cần quyền sử dụng phù hợp trước khi thương mại hóa.

## Chạy lại

Làm theo [tests/README](../tests/README.md) để tạo database độc lập rồi chạy các bộ test theo thứ tự trong bảng. Không chạy integration test vào database đã chứa fixtures từ lượt trước. Test workflows/match-expiry/match-concurrency tự dọn dữ liệu riêng; test HTTP smoke có để lại một voucher test trên database kiểm thử.
