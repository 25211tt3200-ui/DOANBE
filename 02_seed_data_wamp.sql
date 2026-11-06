-- =====================================================================
-- DỮ LIỆU MẪU + KỊCH BẢN CHẠY THỬ (bản cho WampServer, chạy SAU 01_schema_wamp.sql)
-- Toàn bộ dữ liệu cá nhân là giả lập.
--   phpMyAdmin: Import file này; hoặc trong console MySQL của Wamp:
--     mysql -u root --default-character-set=utf8mb4
--     mysql> source C:/wamp64/www/db/02_seed_data_wamp.sql
--   File chỉ chạy được 1 lần trên DB trống (id cố định). Muốn chạy lại: bỏ comment
--   dòng DROP DATABASE ở đầu 01_schema_wamp.sql rồi chạy lại cả hai file theo thứ tự.
-- =====================================================================
USE boarding_house;
SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
 
-- ---------- Người dùng ----------
-- password_hash dưới đây chỉ là chuỗi giữ chỗ, KHÔNG đăng nhập được.
-- Tạo hash thật trong PHP:  password_hash('mat_khau', PASSWORD_DEFAULT)  rồi UPDATE vào bảng users.
INSERT INTO users (id, full_name, email, phone, password_hash, role) VALUES
(1, 'Nguyễn Thị Lan', 'owner@example.com', '0901000001', '$2b$12$REPLACE_WITH_REAL_BCRYPT_HASH', 'OWNER'),
(2, 'Trần Văn Bảo',   'staff@example.com', '0901000002', '$2b$12$REPLACE_WITH_REAL_BCRYPT_HASH', 'STAFF');
 
-- ---------- Cơ sở ----------
INSERT INTO properties (id, owner_id, name, property_type, address, ward, district, city, payment_due_days) VALUES
(1, 1, 'Nhà trọ Hoa Mai',       'BOARDING_HOUSE',     '45/6 Nguyễn Văn Linh', 'Phường Tân Phong', 'Quận 7',      'TP. Hồ Chí Minh', 5),
(2, 1, 'Chung cư mini Sunrise', 'APARTMENT_BUILDING', '12 Lê Văn Việt',       'Phường Hiệp Phú',  'TP. Thủ Đức', 'TP. Hồ Chí Minh', 7);
 
-- ---------- Phòng ----------
INSERT INTO rooms (id, property_id, room_code, floor_no, area_m2, base_rent, max_occupants, status, amenities) VALUES
(1, 1, 'P101', 1, 18, 2200000, 2, 'AVAILABLE',   JSON_ARRAY('Máy lạnh','Nhà vệ sinh riêng')),
(2, 1, 'P102', 1, 20, 2500000, 3, 'AVAILABLE',   JSON_ARRAY('Máy lạnh','Nhà vệ sinh riêng','Ban công')),
(3, 1, 'P201', 2, 22, 2800000, 3, 'AVAILABLE',   JSON_ARRAY('Máy lạnh','Nhà vệ sinh riêng','Ban công')),
(4, 1, 'P202', 2, 18, 2200000, 2, 'AVAILABLE',   JSON_ARRAY('Quạt trần','Nhà vệ sinh riêng')),
(5, 1, 'P203', 2, 18, 2200000, 2, 'MAINTENANCE', JSON_ARRAY('Quạt trần','Nhà vệ sinh riêng')),
(6, 2, 'A501', 5, 45, 7500000, 4, 'AVAILABLE',   JSON_ARRAY('2 phòng ngủ','Máy lạnh','Nội thất cơ bản')),
(7, 2, 'A502', 5, 38, 6500000, 3, 'AVAILABLE',   JSON_ARRAY('1 phòng ngủ','Máy lạnh','Nội thất cơ bản'));
 
-- ---------- Dịch vụ ----------
INSERT INTO services (id, property_id, code, name, billing_type, unit_name, unit_price) VALUES
(1, 1, 'DIEN',     'Tiền điện',   'METERED',        'kWh',   3500),
(2, 1, 'NUOC',     'Tiền nước',   'METERED',        'm3',    20000),
(3, 1, 'INTERNET', 'Internet',    'FIXED_PER_ROOM', 'tháng', 100000),
(4, 1, 'VESINH',   'Phí vệ sinh', 'PER_PERSON',     'người', 30000),
(5, 2, 'DIEN',     'Tiền điện',   'METERED',        'kWh',   3800),
(6, 2, 'NUOC',     'Tiền nước',   'METERED',        'm3',    22000),
(7, 2, 'QUANLY',   'Phí quản lý', 'FIXED_PER_ROOM', 'tháng', 300000);
 
-- ---------- Khách thuê ----------
INSERT INTO tenants (id, full_name, gender, date_of_birth, phone, email, zalo_id, national_id, permanent_address, preferred_channel) VALUES
(1, 'Nguyễn Văn An', 'MALE',   '1999-03-12', '0912000001', 'an.nguyen@example.com', '0912000001', '079200000001', 'Long An',    'ZALO'),
(2, 'Trần Thị Bích', 'FEMALE', '2000-07-25', '0912000002', NULL,                    '0912000002', '079200000002', 'Tiền Giang', 'ZALO'),
(3, 'Lê Hoàng Minh', 'MALE',   '1997-11-02', '0912000003', 'minh.le@example.com',   NULL,         '079200000003', 'Bến Tre',    'EMAIL'),
(4, 'Phạm Quốc Huy', 'MALE',   '1995-01-30', '0912000004', 'huy.pham@example.com',  '0912000004', '079200000004', 'Đồng Nai',   'ZALO'),
(5, 'Võ Thanh Hằng', 'FEMALE', '1992-09-18', '0912000005', 'hang.vo@example.com',   NULL,         '079200000005', 'Cần Thơ',    'EMAIL');
 
-- ---------- Hợp đồng (trigger tự set phòng OCCUPIED + gắn dịch vụ mặc định) ----------
INSERT INTO contracts (id, contract_no, room_id, start_date, end_date, monthly_rent, deposit_amount,
                       deposit_status, deposit_paid_date, status, signed_at) VALUES
(1, 'HD-2026-001', 1, '2026-06-01', '2027-05-31', 2200000,  2200000, 'HELD', '2026-06-01', 'ACTIVE', '2026-05-28'),
(2, 'HD-2026-002', 2, '2026-08-15', '2027-02-14', 2500000,  2500000, 'HELD', '2026-08-15', 'ACTIVE', '2026-08-10'),
(3, 'HD-2026-003', 3, '2026-01-01', '2026-12-31', 2800000,  2800000, 'HELD', '2026-01-01', 'ACTIVE', '2025-12-28'),
(4, 'HD-2026-004', 6, '2026-03-01', '2027-02-28', 7500000, 15000000, 'HELD', '2026-03-01', 'ACTIVE', '2026-02-25');
 
INSERT INTO contract_tenants (contract_id, tenant_id, is_primary, move_in_date) VALUES
(1, 1, 1, '2026-06-01'),
(1, 2, 0, '2026-06-01'),   -- người ở cùng phòng P101
(2, 3, 1, '2026-08-15'),
(3, 4, 1, '2026-01-01'),
(4, 5, 1, '2026-03-01');
 
-- Ưu đãi riêng: hợp đồng P201 được giá điện 3.000 đ/kWh
UPDATE contract_services SET custom_unit_price = 3000 WHERE contract_id = 3 AND service_id = 1;
 
-- Thủ tục trợ giúp chỉ dùng cho file seed: ghi nhận thanh toán theo số hợp đồng + kỳ
-- (p_amount = NULL -> trả đủ số tiền hóa đơn)
DROP PROCEDURE IF EXISTS seed_pay;
DELIMITER $$
CREATE PROCEDURE seed_pay(IN p_contract_no VARCHAR(50), IN p_period DATE, IN p_amount DECIMAL(14,0),
                          IN p_method VARCHAR(20), IN p_paid_at DATETIME, IN p_ref VARCHAR(100),
                          IN p_received_by BIGINT, IN p_note VARCHAR(255))
BEGIN
    DECLARE v_id BIGINT;
    DECLARE v_total DECIMAL(14,0);
 
    SELECT i.id, i.total_amount INTO v_id, v_total
      FROM invoices i JOIN contracts c ON c.id = i.contract_id
     WHERE c.contract_no = p_contract_no AND i.period = p_period AND i.status <> 'CANCELLED';
 
    INSERT INTO payments (invoice_id, amount, method, paid_at, reference_code, received_by, note)
    VALUES (v_id, COALESCE(p_amount, v_total), p_method, p_paid_at, p_ref, p_received_by, p_note);
END$$
DELIMITER ;
 
-- =====================================================================
-- KỲ 09/2026: nhập chỉ số -> lập hóa đơn -> phát hành -> thanh toán
-- =====================================================================
INSERT INTO meter_readings (room_id, service_id, period, previous_reading, current_reading, reading_date, recorded_by) VALUES
(1, 1, '2026-09-01', 1200, 1290, '2026-09-30', 2),   -- P101 điện
(1, 2, '2026-09-01',   85,   91, '2026-09-30', 2),   -- P101 nước
(2, 1, '2026-09-01',  500,  560, '2026-09-30', 2),
(2, 2, '2026-09-01',   40,   44, '2026-09-30', 2),
(3, 1, '2026-09-01', 3000, 3120, '2026-09-30', 2),
(3, 2, '2026-09-01',  150,  158, '2026-09-30', 2),
(6, 5, '2026-09-01',  800,  950, '2026-09-30', 2),   -- A501 điện
(6, 6, '2026-09-01',   60,   66, '2026-09-30', 2);   -- A501 nước
 
CALL generate_monthly_invoices('2026-09-01', NULL, '2026-10-01');
CALL issue_invoices_for_period('2026-09-01', NULL);
 
-- P101, P201, A501 thanh toán đủ bằng chuyển khoản
CALL seed_pay('HD-2026-001', '2026-09-01', NULL, 'BANK_TRANSFER', '2026-10-03 09:30:00', 'FT26100001', 2, 'Thanh toán đủ');
CALL seed_pay('HD-2026-003', '2026-09-01', NULL, 'BANK_TRANSFER', '2026-10-03 10:05:00', 'FT26100002', 2, 'Thanh toán đủ');
CALL seed_pay('HD-2026-004', '2026-09-01', NULL, 'BANK_TRANSFER', '2026-10-04 08:20:00', 'FT26100003', 2, 'Thanh toán đủ');
-- P102 chỉ trả 1.000.000 đ tiền mặt -> còn nợ
CALL seed_pay('HD-2026-002', '2026-09-01', 1000000, 'CASH', '2026-10-04 18:00:00', NULL, 2, 'Trả trước một phần');
 
-- =====================================================================
-- KỲ 10/2026: chỉ số cũ được trigger tự lấy từ kỳ 09 (không cần nhập previous_reading)
-- =====================================================================
INSERT INTO meter_readings (room_id, service_id, period, current_reading, reading_date, recorded_by) VALUES
(1, 1, '2026-10-01', 1385,  '2026-10-31', 2),
(1, 2, '2026-10-01',   97.5,'2026-10-31', 2),
(2, 1, '2026-10-01',  640,  '2026-10-31', 2),
(2, 2, '2026-10-01',   49,  '2026-10-31', 2),
(3, 1, '2026-10-01', 3250,  '2026-10-31', 2),
(3, 2, '2026-10-01',  166,  '2026-10-31', 2),
(6, 5, '2026-10-01', 1110,  '2026-10-31', 2),
(6, 6, '2026-10-01',   72,  '2026-10-31', 2);
 
CALL generate_monthly_invoices('2026-10-01', NULL, '2026-11-01');
CALL issue_invoices_for_period('2026-10-01', NULL);
 
-- P101 trả đủ kỳ 10 sớm qua MoMo
CALL seed_pay('HD-2026-001', '2026-10-01', NULL, 'MOMO', '2026-11-02 20:15:00', 'MM0001', 2, NULL);
 
-- Cập nhật quá hạn + tạo thông báo nhắc nợ
CALL mark_overdue_invoices();
CALL enqueue_overdue_reminders(3);
 
DROP PROCEDURE seed_pay;
 
-- =====================================================================
-- YÊU CẦU SỬA CHỮA
-- =====================================================================
INSERT INTO maintenance_requests (id, room_id, tenant_id, title, description, category, priority) VALUES
(1, 1, 1, 'Máy lạnh không mát',       'Máy lạnh chạy nhưng không làm mát, có tiếng kêu lạ.', 'AIR_CONDITIONER', 'HIGH'),
(2, 2, 3, 'Vòi nước bồn rửa bị rỉ',   'Vòi nước rỉ liên tục, hao nước.',                      'PLUMBING',        'MEDIUM'),
(3, 3, 4, 'Wifi chập chờn',           'Mạng yếu vào buổi tối.',                               'INTERNET',        'LOW');
 
-- Chuyển trạng thái: trigger tự ghi maintenance_logs và completed_at
UPDATE maintenance_requests
   SET status = 'IN_PROGRESS', assigned_to = 2, vendor_name = 'Thợ điện lạnh Phúc Thịnh',
       estimated_cost = 450000, scheduled_at = '2026-11-06 09:00:00'
 WHERE id = 1;
UPDATE maintenance_requests SET status = 'COMPLETED', actual_cost = 0, assigned_to = 2 WHERE id = 3;
 
INSERT INTO maintenance_logs (request_id, author_user_id, message) VALUES
(1, 2, 'Đã liên hệ thợ, hẹn qua kiểm tra sáng 06/11.'),
(3, 2, 'Khởi động lại modem và đổi kênh wifi, mạng đã ổn định.');
 
-- =====================================================================
-- TRUY VẤN THỬ (bỏ comment để chạy)
-- =====================================================================
-- SELECT * FROM v_room_overview ORDER BY property_id, room_code;
-- SELECT * FROM v_overdue_debts;
-- SELECT * FROM v_monthly_revenue ORDER BY period, property_id;
-- SELECT * FROM v_contracts_expiring;
-- SELECT * FROM v_open_maintenance;
-- SELECT invoice_no, status, fmt_vnd(total_amount) AS tong, fmt_vnd(paid_amount) AS da_tra, fmt_vnd(balance) AS con_no FROM invoices ORDER BY id;
-- SELECT description, quantity, unit_name, fmt_vnd(unit_price) AS don_gia, fmt_vnd(amount) AS thanh_tien FROM invoice_items WHERE invoice_id = 1;
-- SELECT channel, recipient, subject, body, status FROM notifications ORDER BY id;