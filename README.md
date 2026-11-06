# Quản lý nhà trọ & căn hộ (PHP + MySQL/MariaDB)

Giao diện web cho database `boarding_house` (WampServer, PHP 7.4+/8.x, kết nối bằng PDO).

## Cài đặt
1. Chạy hai file SQL: `01_schema_wamp.sql` rồi `02_seed_data_wamp.sql` (phpMyAdmin → Import).
2. Chép cả thư mục `quanly` vào `C:\wamp64\www\quanly`.
3. Nếu cần, sửa thông tin kết nối trong `config.php` (mặc định: `127.0.0.1`, user `root`, không mật khẩu).
4. Mở `http://localhost/quanly/setup.php` để đặt mật khẩu cho 2 tài khoản mẫu, rồi **xóa `setup.php`**.
5. Đăng nhập tại `http://localhost/quanly/`:
   - `owner@example.com` (Chủ trọ), `staff@example.com` (Nhân viên).

Giao diện dùng Bootstrap/Chart.js qua CDN nên máy cần có Internet khi mở trang.

## Chức năng (bám theo database)
| Trang | Chức năng |
|---|---|
| Tổng quan | Tỷ lệ lấp đầy, công nợ, hợp đồng sắp hết hạn, sửa chữa, biểu đồ doanh thu |
| Cơ sở / Phòng | CRUD cơ sở (hạn thanh toán), phòng, tiện nghi, lọc theo trạng thái |
| Khách thuê | CRUD, tìm kiếm, kênh nhận thông báo (Zalo/Email/SMS), lịch sử thuê |
| Hợp đồng | Tạo (trigger tự đồng bộ trạng thái phòng + gắn dịch vụ), gia hạn, người ở cùng, đơn giá riêng, chấm dứt/hoàn cọc |
| Dịch vụ | Điện/nước/internet/vệ sinh…, theo công tơ / cố định / đầu người |
| Chỉ số điện nước | Nhập theo cơ sở + kỳ, tự lấy chỉ số cũ, tính mức dùng |
| Hóa đơn | Lập hàng loạt (`generate_monthly_invoices`), phát hành (`issue_invoices_for_period`), khoản thu thêm, giảm trừ, hủy, in |
| Thanh toán | Ghi nhận nhiều hình thức, trạng thái hóa đơn tự cập nhật bằng trigger |
| Sửa chữa | Yêu cầu, phân công, chi phí, nhật ký; đổi trạng thái tự nhắn khách |
| Thông báo | Hàng đợi Zalo/Email/SMS, nhắc nợ, nhắc hết hạn, thông báo chung |
| Báo cáo | 5 báo cáo từ các view, xuất CSV, in |
| Người dùng | Quản lý tài khoản và phân quyền (Chủ trọ / Quản lý / Nhân viên) |

## Quy trình cuối tháng
1. **Chỉ số điện nước** → nhập chỉ số kỳ.
2. **Hóa đơn → Công cụ** → “Lập hóa đơn hàng loạt”, kiểm tra, rồi “Phát hành”.
3. **Thanh toán** → ghi nhận khi khách trả; hóa đơn tự chuyển Đã trả / Trả một phần.
4. Hằng ngày: “Cập nhật quá hạn + tạo nhắc nợ” (hoặc đặt lịch `cron_daily.php`).

## Lưu ý
- Việc gửi tin thật qua Zalo OA/Email/SMS cần một tiến trình gửi riêng đọc bảng `notifications` (trạng thái PENDING). Trang Thông báo cho đánh dấu thủ công.
- Phân quyền: Nhân viên không sửa được cơ sở, dịch vụ, hợp đồng, không lập/phát hành/hủy hóa đơn.
