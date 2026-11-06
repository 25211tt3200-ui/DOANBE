-- =====================================================================
-- HỆ THỐNG QUẢN LÝ NHÀ TRỌ & CĂN HỘ CHO THUÊ
-- (Boarding House & Apartment Management)
--
-- DBMS     : bản chỉnh cho WampServer - chạy được trên MySQL 5.7 / 8.0 / 8.4
--            và MariaDB 10.4+ (InnoDB). Không còn dùng utf8mb4_0900_ai_ci và
--            DEFAULT (biểu thức) là hai thứ làm file gốc lỗi trên MariaDB / MySQL 5.7.
-- Tiền tệ  : VND (DECIMAL(14,0) - số nguyên, không có phần thập phân)
-- File 1/2 : Schema (bảng, ràng buộc, trigger, procedure, view)
--
-- LƯU Ý CHẠY FILE TRÊN WAMPSERVER:
--   * Cách 1 - phpMyAdmin: chọn tab "Import" (hoặc tab SQL) -> chọn file này.
--       phpMyAdmin hiểu lệnh DELIMITER nên import được trigger/procedure.
--   * Cách 2 - console MySQL của Wamp (root mặc định KHÔNG có mật khẩu):
--       mysql -u root --default-character-set=utf8mb4
--       mysql> source C:/wamp64/www/db/01_schema_wamp.sql
--     (dùng "source" và dấu / để khỏi vướng PowerShell, nơi toán tử < không dùng được)
--   * CHECK chỉ được MySQL 8.0.16+ và MariaDB 10.2+ kiểm tra thật; MySQL 5.7 đọc
--     nhưng bỏ qua -> khi đó PHP nên tự validate dữ liệu.
--   * Trong PHP nên kết nối bằng 127.0.0.1 thay vì localhost (xem db.php).
--
-- Luồng nghiệp vụ chính:
--   1. Cơ sở (properties) -> phòng (rooms)
--   2. Khách thuê (tenants) -> hợp đồng (contracts) + người ở cùng
--   3. Mỗi tháng nhập chỉ số điện/nước (meter_readings)
--   4. CALL generate_monthly_invoices(...)  -> hóa đơn DRAFT
--   5. CALL issue_invoices_for_period(...)  -> phát hành + xếp hàng gửi Zalo/Email
--   6. Ghi nhận thanh toán (payments) -> tự cập nhật trạng thái hóa đơn
--   7. CALL mark_overdue_invoices() + enqueue_overdue_reminders(...) nhắc nợ
--   8. Khách báo sửa chữa (maintenance_requests) + nhật ký xử lý
-- =====================================================================
 
-- Muốn chạy lại từ đầu khi đang thử nghiệm: bỏ comment dòng dưới (XÓA TOÀN BỘ dữ liệu cũ!)
-- DROP DATABASE IF EXISTS boarding_house;
 
-- utf8mb4_unicode_ci có trên MySQL 5.7/8.x và MariaDB; không phân biệt hoa/thường và dấu
-- (giống utf8mb4_0900_ai_ci của file gốc).
CREATE DATABASE IF NOT EXISTS boarding_house
    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE boarding_house;
SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
-- Nếu DB đã tồn tại với collation khác, CREATE DATABASE IF NOT EXISTS không đổi được -> ép lại ở đây
ALTER DATABASE boarding_house CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
 
-- =====================================================================
-- 1. BẢNG
-- =====================================================================
 
-- ---------- Người dùng hệ thống ----------
CREATE TABLE users (
    id            BIGINT       NOT NULL AUTO_INCREMENT PRIMARY KEY,
    full_name     VARCHAR(150) NOT NULL,
    email         VARCHAR(190) NOT NULL,
    phone         VARCHAR(20),
    password_hash VARCHAR(255) NOT NULL,
    role          ENUM('OWNER','MANAGER','STAFF') NOT NULL DEFAULT 'STAFF',
    is_active     TINYINT(1)   NOT NULL DEFAULT 1,
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_users_email (email)          -- collation _ci nên không phân biệt hoa thường
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
 
-- ---------- Cơ sở: dãy nhà trọ / tòa chung cư ----------
CREATE TABLE properties (
    id               BIGINT       NOT NULL AUTO_INCREMENT PRIMARY KEY,
    owner_id         BIGINT       NOT NULL,
    name             VARCHAR(150) NOT NULL,
    property_type    ENUM('BOARDING_HOUSE','APARTMENT_BUILDING') NOT NULL DEFAULT 'BOARDING_HOUSE',
    address          VARCHAR(255) NOT NULL,
    ward             VARCHAR(100),
    district         VARCHAR(100),
    city             VARCHAR(100),
    payment_due_days SMALLINT     NOT NULL DEFAULT 5,   -- hạn thanh toán = ngày phát hành + N ngày
    is_active        TINYINT(1)   NOT NULL DEFAULT 1,
    created_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_properties_owner FOREIGN KEY (owner_id) REFERENCES users(id),
    CONSTRAINT ck_properties_due_days CHECK (payment_due_days BETWEEN 0 AND 60)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
 
-- ---------- Phòng / căn hộ ----------
CREATE TABLE rooms (
    id            BIGINT        NOT NULL AUTO_INCREMENT PRIMARY KEY,
    property_id   BIGINT        NOT NULL,
    room_code     VARCHAR(30)   NOT NULL,
    floor_no      SMALLINT,
    area_m2       DECIMAL(6,2),
    base_rent     DECIMAL(14,0) NOT NULL,               -- giá niêm yết (hợp đồng có thể khác)
    max_occupants SMALLINT      NOT NULL DEFAULT 2,
    status        ENUM('AVAILABLE','OCCUPIED','RESERVED','MAINTENANCE') NOT NULL DEFAULT 'AVAILABLE',
    amenities     JSON          NULL,                   -- trigger trg_rooms_bi tự điền '[]' khi bỏ trống
    description   TEXT,
    created_at    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_rooms_code (property_id, room_code),
    KEY idx_rooms_property_status (property_id, status),
    CONSTRAINT fk_rooms_property FOREIGN KEY (property_id) REFERENCES properties(id),
    CONSTRAINT ck_rooms_area CHECK (area_m2 IS NULL OR area_m2 > 0),
    CONSTRAINT ck_rooms_rent CHECK (base_rent >= 0),
    CONSTRAINT ck_rooms_occupants CHECK (max_occupants > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
 
-- ---------- Khách thuê ----------
CREATE TABLE tenants (
    id                      BIGINT       NOT NULL AUTO_INCREMENT PRIMARY KEY,
    full_name               VARCHAR(150) NOT NULL,
    gender                  ENUM('MALE','FEMALE','OTHER'),
    date_of_birth           DATE,
    phone                   VARCHAR(20),
    email                   VARCHAR(190),
    zalo_id                 VARCHAR(100),               -- Zalo ID / SĐT Zalo để gửi hóa đơn
    national_id             VARCHAR(20),                -- CCCD/CMND
    permanent_address       VARCHAR(255),
    emergency_contact_name  VARCHAR(150),
    emergency_contact_phone VARCHAR(20),
    preferred_channel       ENUM('ZALO','EMAIL','SMS') NOT NULL DEFAULT 'ZALO',
    notes                   TEXT,
    created_at              DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at              DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_tenants_national_id (national_id),
    CONSTRAINT ck_tenants_contact CHECK (phone IS NOT NULL OR email IS NOT NULL OR zalo_id IS NOT NULL)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
 
-- ---------- Hợp đồng thuê ----------
-- (Chống trùng hợp đồng ACTIVE trên cùng phòng được thực hiện bằng trigger, xem mục 3.)
CREATE TABLE contracts (
    id                 BIGINT        NOT NULL AUTO_INCREMENT PRIMARY KEY,
    contract_no        VARCHAR(50)   NOT NULL,
    room_id            BIGINT        NOT NULL,
    start_date         DATE          NOT NULL,
    end_date           DATE,                            -- NULL = không xác định thời hạn
    monthly_rent       DECIMAL(14,0) NOT NULL,
    deposit_amount     DECIMAL(14,0) NOT NULL DEFAULT 0,
    deposit_status     ENUM('PENDING','HELD','REFUNDED','FORFEITED') NOT NULL DEFAULT 'PENDING',
    deposit_paid_date  DATE,
    status             ENUM('DRAFT','ACTIVE','EXPIRED','TERMINATED') NOT NULL DEFAULT 'DRAFT',
    signed_at          DATE,
    terminated_at      DATE,
    termination_reason VARCHAR(255),
    notes              TEXT,
    created_at         DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at         DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_contracts_no (contract_no),
    KEY idx_contracts_room_status (room_id, status),
    KEY idx_contracts_status_end (status, end_date),
    CONSTRAINT fk_contracts_room FOREIGN KEY (room_id) REFERENCES rooms(id),
    CONSTRAINT ck_contracts_dates CHECK (end_date IS NULL OR end_date > start_date),
    CONSTRAINT ck_contracts_rent CHECK (monthly_rent >= 0),
    CONSTRAINT ck_contracts_deposit CHECK (deposit_amount >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
 
-- ---------- Người ở trong hợp đồng (người đứng tên + người ở cùng) ----------
CREATE TABLE contract_tenants (
    contract_id   BIGINT     NOT NULL,
    tenant_id     BIGINT     NOT NULL,
    is_primary    TINYINT(1) NOT NULL DEFAULT 0,        -- người đứng tên hợp đồng
    move_in_date  DATE       NOT NULL,
    move_out_date DATE,
    -- Mỗi hợp đồng chỉ có 1 người đứng tên (cột sinh = NULL khi không phải primary, UNIQUE bỏ qua NULL)
    primary_flag  TINYINT GENERATED ALWAYS AS (IF(is_primary = 1, 1, NULL)) STORED,
    PRIMARY KEY (contract_id, tenant_id),
    UNIQUE KEY uq_contract_one_primary (contract_id, primary_flag),
    KEY idx_contract_tenants_tenant (tenant_id),
    CONSTRAINT fk_ct_contract FOREIGN KEY (contract_id) REFERENCES contracts(id) ON DELETE CASCADE,
    CONSTRAINT fk_ct_tenant   FOREIGN KEY (tenant_id)   REFERENCES tenants(id),
    CONSTRAINT ck_ct_dates CHECK (move_out_date IS NULL OR move_out_date >= move_in_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
 
-- ---------- Dịch vụ của từng cơ sở (điện, nước, internet, vệ sinh...) ----------
CREATE TABLE services (
    id           BIGINT        NOT NULL AUTO_INCREMENT PRIMARY KEY,
    property_id  BIGINT        NOT NULL,
    code         VARCHAR(30)   NOT NULL,
    name         VARCHAR(100)  NOT NULL,
    -- METERED: theo công tơ | FIXED_PER_ROOM: cố định/phòng | PER_PERSON: theo đầu người
    billing_type ENUM('METERED','FIXED_PER_ROOM','PER_PERSON') NOT NULL,
    unit_name    VARCHAR(30)   NOT NULL,                -- kWh, m3, tháng, người...
    unit_price   DECIMAL(14,0) NOT NULL,
    is_active    TINYINT(1)    NOT NULL DEFAULT 1,
    created_at   DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_services_code (property_id, code),
    CONSTRAINT fk_services_property FOREIGN KEY (property_id) REFERENCES properties(id) ON DELETE CASCADE,
    CONSTRAINT ck_services_price CHECK (unit_price >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
 
-- ---------- Dịch vụ áp dụng cho hợp đồng (có thể ghi đè đơn giá) ----------
CREATE TABLE contract_services (
    contract_id       BIGINT NOT NULL,
    service_id        BIGINT NOT NULL,
    custom_unit_price DECIMAL(14,0),                    -- NULL = dùng giá chung của dịch vụ
    PRIMARY KEY (contract_id, service_id),
    CONSTRAINT fk_cs_contract FOREIGN KEY (contract_id) REFERENCES contracts(id) ON DELETE CASCADE,
    CONSTRAINT fk_cs_service  FOREIGN KEY (service_id)  REFERENCES services(id),
    CONSTRAINT ck_cs_price CHECK (custom_unit_price IS NULL OR custom_unit_price >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
 
-- ---------- Chỉ số điện / nước hàng tháng ----------
-- previous_reading: để trống khi INSERT, trigger sẽ tự lấy từ chỉ số kỳ trước (hoặc 0).
CREATE TABLE meter_readings (
    id               BIGINT        NOT NULL AUTO_INCREMENT PRIMARY KEY,
    room_id          BIGINT        NOT NULL,
    service_id       BIGINT        NOT NULL,
    period           DATE          NOT NULL,            -- ngày 1 của tháng tính tiền
    previous_reading DECIMAL(12,2),
    current_reading  DECIMAL(12,2) NOT NULL,
    consumption      DECIMAL(12,2) GENERATED ALWAYS AS (current_reading - previous_reading) STORED,
    reading_date     DATE,                              -- trigger tự điền ngày hôm nay khi bỏ trống
    recorded_by      BIGINT,
    photo_url        VARCHAR(500),
    notes            VARCHAR(255),
    created_at       DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_meter_room_service_period (room_id, service_id, period),
    KEY idx_meter_service (service_id),
    CONSTRAINT fk_meter_room    FOREIGN KEY (room_id)     REFERENCES rooms(id),
    CONSTRAINT fk_meter_service FOREIGN KEY (service_id)  REFERENCES services(id),
    CONSTRAINT fk_meter_user    FOREIGN KEY (recorded_by) REFERENCES users(id),
    CONSTRAINT ck_meter_period  CHECK (DAYOFMONTH(period) = 1),
    CONSTRAINT ck_meter_prev    CHECK (previous_reading IS NULL OR previous_reading >= 0),
    CONSTRAINT ck_meter_cur     CHECK (current_reading >= 0 AND current_reading >= IFNULL(previous_reading, 0))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
 
-- ---------- Hóa đơn ----------
-- invoice_no được procedure generate_invoice gán ngay sau khi tạo (dạng INV-YYYYMM-00001),
-- vì MySQL không cho trigger/cột sinh đọc giá trị AUTO_INCREMENT của chính dòng đang chèn.
CREATE TABLE invoices (
    id              BIGINT        NOT NULL AUTO_INCREMENT PRIMARY KEY,
    invoice_no      VARCHAR(30),
    contract_id     BIGINT        NOT NULL,
    period          DATE          NOT NULL,
    issue_date      DATE,                               -- trigger trg_invoices_bi tự điền ngày hôm nay khi bỏ trống
    due_date        DATE          NOT NULL,
    subtotal        DECIMAL(14,0) NOT NULL DEFAULT 0,   -- tự cộng từ invoice_items
    discount_amount DECIMAL(14,0) NOT NULL DEFAULT 0,
    total_amount    DECIMAL(14,0) GENERATED ALWAYS AS (subtotal - discount_amount) STORED,
    paid_amount     DECIMAL(14,0) NOT NULL DEFAULT 0,   -- tự cộng từ payments
    balance         DECIMAL(14,0) GENERATED ALWAYS AS (subtotal - discount_amount - paid_amount) STORED,
    status          ENUM('DRAFT','ISSUED','PARTIAL','PAID','OVERDUE','CANCELLED') NOT NULL DEFAULT 'DRAFT',
    issued_at       DATETIME,
    note            VARCHAR(255),
    created_by      BIGINT,
    created_at      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    -- Mỗi hợp đồng chỉ có 1 hóa đơn hiệu lực mỗi kỳ (hóa đơn CANCELLED -> NULL nên không bị tính)
    active_flag     TINYINT GENERATED ALWAYS AS (IF(status = 'CANCELLED', NULL, 1)) STORED,
    UNIQUE KEY uq_invoice_no (invoice_no),
    UNIQUE KEY uq_invoice_contract_period (contract_id, period, active_flag),
    KEY idx_invoices_status_due (status, due_date),
    KEY idx_invoices_period (period),
    CONSTRAINT fk_invoices_contract FOREIGN KEY (contract_id) REFERENCES contracts(id),
    CONSTRAINT fk_invoices_user     FOREIGN KEY (created_by)  REFERENCES users(id),
    CONSTRAINT ck_invoices_period   CHECK (DAYOFMONTH(period) = 1),
    CONSTRAINT ck_invoices_dates    CHECK (due_date >= issue_date),
    CONSTRAINT ck_invoices_amounts  CHECK (discount_amount >= 0 AND paid_amount >= 0
                                           AND paid_amount <= subtotal - discount_amount)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
 
-- ---------- Chi tiết hóa đơn (snapshot đơn giá tại thời điểm lập) ----------
CREATE TABLE invoice_items (
    id               BIGINT        NOT NULL AUTO_INCREMENT PRIMARY KEY,
    invoice_id       BIGINT        NOT NULL,
    item_type        ENUM('RENT','SERVICE','OTHER') NOT NULL,
    service_id       BIGINT,
    description      VARCHAR(255)  NOT NULL,
    previous_reading DECIMAL(12,2),
    current_reading  DECIMAL(12,2),
    quantity         DECIMAL(12,2) NOT NULL DEFAULT 1,
    unit_name        VARCHAR(30),
    unit_price       DECIMAL(14,0) NOT NULL,            -- cho phép âm để điều chỉnh/giảm trừ
    amount           DECIMAL(14,0) GENERATED ALWAYS AS (ROUND(quantity * unit_price, 0)) STORED,
    KEY idx_invoice_items_invoice (invoice_id),
    CONSTRAINT fk_items_invoice FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE,
    CONSTRAINT fk_items_service FOREIGN KEY (service_id) REFERENCES services(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
 
-- ---------- Thanh toán ----------
CREATE TABLE payments (
    id             BIGINT        NOT NULL AUTO_INCREMENT PRIMARY KEY,
    invoice_id     BIGINT        NOT NULL,
    amount         DECIMAL(14,0) NOT NULL,
    method         ENUM('CASH','BANK_TRANSFER','MOMO','ZALOPAY','VNPAY','OTHER') NOT NULL DEFAULT 'CASH',
    paid_at        DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    reference_code VARCHAR(100),                        -- mã giao dịch ngân hàng / ví
    received_by    BIGINT,
    note           VARCHAR(255),
    created_at     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_payments_invoice (invoice_id),
    CONSTRAINT fk_payments_invoice FOREIGN KEY (invoice_id)  REFERENCES invoices(id),
    CONSTRAINT fk_payments_user    FOREIGN KEY (received_by) REFERENCES users(id),
    CONSTRAINT ck_payments_amount  CHECK (amount > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
 
-- ---------- Yêu cầu sửa chữa ----------
CREATE TABLE maintenance_requests (
    id             BIGINT        NOT NULL AUTO_INCREMENT PRIMARY KEY,
    room_id        BIGINT        NOT NULL,
    tenant_id      BIGINT,                              -- người báo (NULL nếu chủ trọ tự tạo)
    title          VARCHAR(200)  NOT NULL,
    description    TEXT,
    category       ENUM('ELECTRICAL','PLUMBING','AIR_CONDITIONER','FURNITURE','INTERNET','STRUCTURE','OTHER')
                   NOT NULL DEFAULT 'OTHER',
    priority       ENUM('LOW','MEDIUM','HIGH','URGENT') NOT NULL DEFAULT 'MEDIUM',
    status         ENUM('OPEN','ASSIGNED','IN_PROGRESS','COMPLETED','REJECTED','CANCELLED') NOT NULL DEFAULT 'OPEN',
    photo_urls     JSON          NULL,                  -- trigger trg_maintenance_bi tự điền '[]' khi bỏ trống
    reported_at    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    assigned_to    BIGINT,
    vendor_name    VARCHAR(150),                        -- thợ bên ngoài
    scheduled_at   DATETIME,
    completed_at   DATETIME,
    estimated_cost DECIMAL(14,0),
    actual_cost    DECIMAL(14,0),
    cost_bearer    ENUM('OWNER','TENANT') NOT NULL DEFAULT 'OWNER',
    created_at     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_maint_room (room_id),
    KEY idx_maint_status (status, priority),
    CONSTRAINT fk_maint_room   FOREIGN KEY (room_id)     REFERENCES rooms(id),
    CONSTRAINT fk_maint_tenant FOREIGN KEY (tenant_id)   REFERENCES tenants(id),
    CONSTRAINT fk_maint_user   FOREIGN KEY (assigned_to) REFERENCES users(id),
    CONSTRAINT ck_maint_cost   CHECK ((estimated_cost IS NULL OR estimated_cost >= 0)
                                  AND (actual_cost IS NULL OR actual_cost >= 0))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
 
CREATE TABLE maintenance_logs (
    id               BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    request_id       BIGINT NOT NULL,
    author_user_id   BIGINT,
    author_tenant_id BIGINT,
    old_status       ENUM('OPEN','ASSIGNED','IN_PROGRESS','COMPLETED','REJECTED','CANCELLED'),
    new_status       ENUM('OPEN','ASSIGNED','IN_PROGRESS','COMPLETED','REJECTED','CANCELLED'),
    message          TEXT,
    created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_maint_logs_request (request_id),
    CONSTRAINT fk_mlog_request FOREIGN KEY (request_id)       REFERENCES maintenance_requests(id) ON DELETE CASCADE,
    CONSTRAINT fk_mlog_user    FOREIGN KEY (author_user_id)   REFERENCES users(id),
    CONSTRAINT fk_mlog_tenant  FOREIGN KEY (author_tenant_id) REFERENCES tenants(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
 
-- ---------- Hàng đợi thông báo (Zalo / Email / SMS) - worker backend đọc và gửi ----------
CREATE TABLE notifications (
    id                     BIGINT       NOT NULL AUTO_INCREMENT PRIMARY KEY,
    tenant_id              BIGINT       NOT NULL,
    invoice_id             BIGINT,
    maintenance_request_id BIGINT,
    channel                ENUM('ZALO','EMAIL','SMS') NOT NULL,
    notif_type             ENUM('INVOICE','PAYMENT_REMINDER','CONTRACT_EXPIRY','MAINTENANCE_UPDATE','GENERAL') NOT NULL,
    recipient              VARCHAR(255) NOT NULL,       -- Zalo ID / email / SĐT
    subject                VARCHAR(255),
    body                   TEXT         NOT NULL,
    status                 ENUM('PENDING','SENT','FAILED') NOT NULL DEFAULT 'PENDING',
    attempts               SMALLINT     NOT NULL DEFAULT 0,
    error_message          VARCHAR(500),
    scheduled_at           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    sent_at                DATETIME,
    created_at             DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_notif_queue (status, scheduled_at),
    KEY idx_notif_invoice (invoice_id),
    CONSTRAINT fk_notif_tenant  FOREIGN KEY (tenant_id)              REFERENCES tenants(id),
    CONSTRAINT fk_notif_invoice FOREIGN KEY (invoice_id)             REFERENCES invoices(id) ON DELETE SET NULL,
    CONSTRAINT fk_notif_maint   FOREIGN KEY (maintenance_request_id) REFERENCES maintenance_requests(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
 
-- =====================================================================
-- 2. HÀM TIỆN ÍCH
-- =====================================================================
DELIMITER $$
 
-- Định dạng tiền VND: 1234567 -> '1.234.567 đ'
CREATE FUNCTION fmt_vnd(n DECIMAL(14,0)) RETURNS VARCHAR(40) CHARSET utf8mb4 COLLATE utf8mb4_unicode_ci
    DETERMINISTIC NO SQL
BEGIN
    RETURN CONCAT(REPLACE(FORMAT(n, 0, 'en_US'), ',', '.'), ' đ');
END$$
 
-- Địa chỉ nhận thông báo theo kênh ưa thích của khách
CREATE FUNCTION notification_recipient(p_channel VARCHAR(10), p_zalo VARCHAR(100),
                                       p_phone VARCHAR(20), p_email VARCHAR(190))
    RETURNS VARCHAR(255) CHARSET utf8mb4 COLLATE utf8mb4_unicode_ci
    DETERMINISTIC NO SQL
BEGIN
    RETURN CASE p_channel
             WHEN 'ZALO'  THEN COALESCE(p_zalo, p_phone)
             WHEN 'EMAIL' THEN p_email
             WHEN 'SMS'   THEN p_phone
           END;
END$$
 
-- =====================================================================
-- 3. PROCEDURE NỘI BỘ + TRIGGER
--    (Trigger MySQL không được sửa chính bảng đang kích hoạt nó, và không dùng
--     chung được nhiều sự kiện, nên logic dùng chung được đưa vào procedure.)
-- =====================================================================
 
-- ---- 3.0 Giá trị mặc định thay cho DEFAULT (biểu thức) -----------------------------
--      (MySQL 5.7 và MariaDB không cho DEFAULT (JSON_ARRAY()) / DEFAULT (CURRENT_DATE))
CREATE TRIGGER trg_rooms_bi BEFORE INSERT ON rooms FOR EACH ROW
BEGIN
    IF NEW.amenities IS NULL THEN
        SET NEW.amenities = JSON_ARRAY();
    END IF;
END$$
 
CREATE TRIGGER trg_maintenance_bi BEFORE INSERT ON maintenance_requests FOR EACH ROW
BEGIN
    IF NEW.photo_urls IS NULL THEN
        SET NEW.photo_urls = JSON_ARRAY();
    END IF;
END$$
 
CREATE TRIGGER trg_invoices_bi BEFORE INSERT ON invoices FOR EACH ROW
BEGIN
    IF NEW.issue_date IS NULL THEN
        SET NEW.issue_date = CURRENT_DATE;
    END IF;
END$$
 
-- ---- 3.1 Chống trùng hợp đồng ACTIVE chồng thời gian trên cùng phòng ----
CREATE PROCEDURE sp_check_contract_overlap(
    IN p_id BIGINT, IN p_room_id BIGINT, IN p_start DATE, IN p_end DATE, IN p_status VARCHAR(20))
BEGIN
    DECLARE v_lock BIGINT;
    DECLARE v_cnt  INT DEFAULT 0;
 
    IF p_status = 'ACTIVE' THEN
        -- Khóa dòng phòng để 2 giao dịch đồng thời không cùng lọt qua kiểm tra
        SELECT id INTO v_lock FROM rooms WHERE id = p_room_id FOR UPDATE;
 
        SELECT COUNT(*) INTO v_cnt
          FROM contracts c
         WHERE c.room_id = p_room_id
           AND c.status = 'ACTIVE'
           AND c.id <> IFNULL(p_id, 0)
           AND c.start_date <= IFNULL(p_end, '9999-12-31')
           AND IFNULL(c.end_date, '9999-12-31') >= p_start;
 
        IF v_cnt > 0 THEN
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'Phòng đã có hợp đồng ACTIVE trùng thời gian';
        END IF;
    END IF;
END$$
 
CREATE TRIGGER trg_contracts_bi BEFORE INSERT ON contracts FOR EACH ROW
BEGIN
    CALL sp_check_contract_overlap(NEW.id, NEW.room_id, NEW.start_date, NEW.end_date, NEW.status);
END$$
 
CREATE TRIGGER trg_contracts_bu BEFORE UPDATE ON contracts FOR EACH ROW
BEGIN
    CALL sp_check_contract_overlap(NEW.id, NEW.room_id, NEW.start_date, NEW.end_date, NEW.status);
END$$
 
-- ---- 3.2 Hợp đồng mới: đồng bộ trạng thái phòng + gắn dịch vụ mặc định của cơ sở ----
CREATE TRIGGER trg_contracts_ai AFTER INSERT ON contracts FOR EACH ROW
BEGIN
    IF NEW.status = 'ACTIVE' THEN
        UPDATE rooms SET status = 'OCCUPIED' WHERE id = NEW.room_id;
    END IF;
 
    INSERT INTO contract_services (contract_id, service_id)
    SELECT NEW.id, s.id
      FROM services s
      JOIN rooms r ON r.property_id = s.property_id
     WHERE r.id = NEW.room_id AND s.is_active = 1;
END$$
 
CREATE TRIGGER trg_contracts_au AFTER UPDATE ON contracts FOR EACH ROW
BEGIN
    IF NEW.status = 'ACTIVE' AND OLD.status <> 'ACTIVE' THEN
        UPDATE rooms SET status = 'OCCUPIED' WHERE id = NEW.room_id;
    ELSEIF OLD.status = 'ACTIVE' AND NEW.status <> 'ACTIVE' THEN
        UPDATE rooms SET status = 'AVAILABLE'
         WHERE id = NEW.room_id
           AND status = 'OCCUPIED'
           AND NOT EXISTS (SELECT 1 FROM contracts c
                            WHERE c.room_id = NEW.room_id AND c.status = 'ACTIVE' AND c.id <> NEW.id);
    END IF;
END$$
 
-- ---- 3.3 Chỉ số công tơ: kiểm tra dịch vụ + tự lấy chỉ số cũ từ kỳ trước ----
CREATE TRIGGER trg_meter_readings_bi BEFORE INSERT ON meter_readings FOR EACH ROW
BEGIN
    DECLARE v_type VARCHAR(20) DEFAULT NULL;
    DECLARE v_same TINYINT DEFAULT NULL;
    DECLARE v_prev DECIMAL(12,2) DEFAULT NULL;
 
    SELECT s.billing_type, (s.property_id = r.property_id)
      INTO v_type, v_same
      FROM services s
      JOIN rooms r ON r.id = NEW.room_id
     WHERE s.id = NEW.service_id;
 
    IF v_type IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Phòng hoặc dịch vụ không tồn tại';
    END IF;
    IF v_type <> 'METERED' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Dịch vụ này không tính theo công tơ';
    END IF;
    IF v_same = 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Dịch vụ không thuộc cơ sở của phòng';
    END IF;
 
    SET NEW.period = DATE_SUB(NEW.period, INTERVAL DAYOFMONTH(NEW.period) - 1 DAY);
    SET NEW.reading_date = IFNULL(NEW.reading_date, CURRENT_DATE);
 
    IF NEW.previous_reading IS NULL THEN
        SELECT mr.current_reading INTO v_prev
          FROM meter_readings mr
         WHERE mr.room_id = NEW.room_id AND mr.service_id = NEW.service_id AND mr.period < NEW.period
         ORDER BY mr.period DESC
         LIMIT 1;
        SET NEW.previous_reading = IFNULL(v_prev, 0);
    END IF;
END$$
 
CREATE TRIGGER trg_meter_readings_bu BEFORE UPDATE ON meter_readings FOR EACH ROW
BEGIN
    SET NEW.previous_reading = COALESCE(NEW.previous_reading, OLD.previous_reading);
    SET NEW.reading_date     = COALESCE(NEW.reading_date, OLD.reading_date);
END$$
 
-- ---- 3.4 Tự cộng tổng tiền hóa đơn khi thay đổi chi tiết ----
CREATE PROCEDURE sp_recalc_invoice(IN p_invoice_id BIGINT)
BEGIN
    UPDATE invoices
       SET subtotal = IFNULL((SELECT SUM(amount) FROM invoice_items WHERE invoice_id = p_invoice_id), 0)
     WHERE id = p_invoice_id;
END$$
 
CREATE TRIGGER trg_invoice_items_ai AFTER INSERT ON invoice_items FOR EACH ROW
BEGIN
    CALL sp_recalc_invoice(NEW.invoice_id);
END$$
 
CREATE TRIGGER trg_invoice_items_au AFTER UPDATE ON invoice_items FOR EACH ROW
BEGIN
    CALL sp_recalc_invoice(OLD.invoice_id);
    IF NEW.invoice_id <> OLD.invoice_id THEN
        CALL sp_recalc_invoice(NEW.invoice_id);
    END IF;
END$$
 
CREATE TRIGGER trg_invoice_items_ad AFTER DELETE ON invoice_items FOR EACH ROW
BEGIN
    CALL sp_recalc_invoice(OLD.invoice_id);
END$$
 
-- ---- 3.5 Thanh toán: kiểm tra + cập nhật paid_amount/status của hóa đơn ----
CREATE PROCEDURE sp_refresh_invoice_payment(IN p_invoice_id BIGINT)
BEGIN
    DECLARE v_paid DECIMAL(14,0);
 
    SELECT IFNULL(SUM(amount), 0) INTO v_paid FROM payments WHERE invoice_id = p_invoice_id;
 
    UPDATE invoices
       SET paid_amount = v_paid,
           status = CASE
                      WHEN v_paid > 0 AND v_paid >= subtotal - discount_amount THEN 'PAID'
                      WHEN status = 'DRAFT' AND v_paid = 0                     THEN 'DRAFT'
                      WHEN due_date < CURRENT_DATE                             THEN 'OVERDUE'
                      WHEN v_paid > 0                                          THEN 'PARTIAL'
                      ELSE 'ISSUED'
                    END
     WHERE id = p_invoice_id AND status <> 'CANCELLED';
END$$
 
CREATE TRIGGER trg_payments_bi BEFORE INSERT ON payments FOR EACH ROW
BEGIN
    DECLARE v_status  VARCHAR(20)   DEFAULT NULL;
    DECLARE v_balance DECIMAL(14,0) DEFAULT NULL;
 
    SELECT status, balance INTO v_status, v_balance
      FROM invoices WHERE id = NEW.invoice_id FOR UPDATE;   -- khóa hàng, tránh thanh toán trùng đồng thời
 
    IF v_status IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Không tìm thấy hóa đơn';
    END IF;
    IF v_status = 'CANCELLED' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Hóa đơn đã bị hủy, không thể ghi nhận thanh toán';
    END IF;
    IF NEW.amount > v_balance THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Số tiền thanh toán vượt quá số còn nợ của hóa đơn';
    END IF;
END$$
 
CREATE TRIGGER trg_payments_ai AFTER INSERT ON payments FOR EACH ROW
BEGIN
    CALL sp_refresh_invoice_payment(NEW.invoice_id);
END$$
 
CREATE TRIGGER trg_payments_au AFTER UPDATE ON payments FOR EACH ROW
BEGIN
    CALL sp_refresh_invoice_payment(OLD.invoice_id);
    IF NEW.invoice_id <> OLD.invoice_id THEN
        CALL sp_refresh_invoice_payment(NEW.invoice_id);
    END IF;
END$$
 
CREATE TRIGGER trg_payments_ad AFTER DELETE ON payments FOR EACH ROW
BEGIN
    CALL sp_refresh_invoice_payment(OLD.invoice_id);
END$$
 
-- ---- 3.6 Sửa chữa: tự ghi nhật ký khi đổi trạng thái, tự set completed_at ----
CREATE TRIGGER trg_maintenance_bu BEFORE UPDATE ON maintenance_requests FOR EACH ROW
BEGIN
    IF NEW.status <> OLD.status THEN
        IF NEW.status = 'COMPLETED' AND NEW.completed_at IS NULL THEN
            SET NEW.completed_at = NOW();
        END IF;
        INSERT INTO maintenance_logs (request_id, old_status, new_status, message)
        VALUES (OLD.id, OLD.status, NEW.status, 'Tự động ghi nhận thay đổi trạng thái');
    END IF;
END$$
 
-- =====================================================================
-- 4. PROCEDURE NGHIỆP VỤ
--    (Dùng PROCEDURE thay vì FUNCTION vì MySQL không cho function sửa bảng
--     đang được câu lệnh gọi đọc, và procedure trả được result set.)
-- =====================================================================
 
-- ---------------------------------------------------------------------
-- generate_invoice: lập hóa đơn (DRAFT) cho 1 hợp đồng trong 1 kỳ (tháng)
--   * Tiền phòng tính theo tỷ lệ ngày nếu hợp đồng bắt đầu/kết thúc giữa tháng
--   * METERED       : lấy từ meter_readings (thiếu chỉ số -> báo lỗi, không tạo gì)
--   * FIXED_PER_ROOM: số lượng = 1
--   * PER_PERSON    : số lượng = số người đang ở trong kỳ (tối thiểu 1)
--   p_issue_date = NULL -> hôm nay. p_invoice_id trả về id hóa đơn mới.
-- ---------------------------------------------------------------------
CREATE PROCEDURE generate_invoice(
    IN  p_contract_id BIGINT,
    IN  p_period      DATE,
    IN  p_issue_date  DATE,
    IN  p_created_by  BIGINT,
    OUT p_invoice_id  BIGINT
)
BEGIN
    DECLARE v_done INT DEFAULT 0;
    DECLARE v_cnt  INT DEFAULT 0;
    DECLARE v_invoice_id BIGINT DEFAULT NULL;
    DECLARE v_period_start, v_period_end, v_issue_date DATE;
    DECLARE v_days_in_month INT;
    DECLARE v_contract_no VARCHAR(50);
    DECLARE v_room_id, v_property_id BIGINT;
    DECLARE v_status VARCHAR(20);
    DECLARE v_start_date, v_end_date, v_terminated_at DATE;
    DECLARE v_monthly_rent DECIMAL(14,0);
    DECLARE v_room_code VARCHAR(30);
    DECLARE v_due_days INT;
    DECLARE v_from, v_to DATE;
    DECLARE v_days INT;
    DECLARE v_missing TEXT;
    DECLARE v_msg VARCHAR(128);
    DECLARE v_qty, v_prev, v_cur DECIMAL(12,2);
    DECLARE v_svc_id BIGINT;
    DECLARE v_svc_name VARCHAR(100);
    DECLARE v_svc_type VARCHAR(20);
    DECLARE v_svc_unit VARCHAR(30);
    DECLARE v_svc_price DECIMAL(14,0);
 
    DECLARE cur_svc CURSOR FOR
        SELECT sv.id, sv.name, sv.billing_type, sv.unit_name,
               COALESCE(cs.custom_unit_price, sv.unit_price)
          FROM contract_services cs
          JOIN services sv ON sv.id = cs.service_id
         WHERE cs.contract_id = p_contract_id
         ORDER BY sv.id;
 
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET v_done = 1;
 
    -- Có lỗi giữa chừng -> xóa hóa đơn dở dang (items xóa theo ON DELETE CASCADE) rồi ném lỗi tiếp
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        IF v_invoice_id IS NOT NULL THEN
            DELETE FROM invoices WHERE id = v_invoice_id;
        END IF;
        SET p_invoice_id = NULL;
        RESIGNAL;
    END;
 
    SET p_invoice_id   = NULL;
    SET v_issue_date   = COALESCE(p_issue_date, CURRENT_DATE);
    SET v_period_start = DATE_SUB(p_period, INTERVAL DAYOFMONTH(p_period) - 1 DAY);
    SET v_period_end   = LAST_DAY(p_period);
    SET v_days_in_month = DAY(v_period_end);
 
    -- Kiểm tra hợp đồng
    SELECT COUNT(*) INTO v_cnt FROM contracts WHERE id = p_contract_id;
    IF v_cnt = 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Không tìm thấy hợp đồng';
    END IF;
 
    SELECT contract_no, room_id, status, start_date, end_date, terminated_at, monthly_rent
      INTO v_contract_no, v_room_id, v_status, v_start_date, v_end_date, v_terminated_at, v_monthly_rent
      FROM contracts WHERE id = p_contract_id;
 
    IF v_status = 'DRAFT' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Hợp đồng chưa có hiệu lực (DRAFT)';
    END IF;
 
    SET v_from = GREATEST(v_start_date, v_period_start);
    SET v_to   = LEAST(IFNULL(v_end_date, v_period_end), v_period_end);
    IF v_terminated_at IS NOT NULL THEN
        SET v_to = LEAST(v_to, v_terminated_at);
    END IF;
    IF v_from > v_to THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Hợp đồng không có hiệu lực trong kỳ này';
    END IF;
 
    SELECT r.room_code, r.property_id INTO v_room_code, v_property_id FROM rooms r WHERE r.id = v_room_id;
    SELECT payment_due_days INTO v_due_days FROM properties WHERE id = v_property_id;
 
    SELECT COUNT(*) INTO v_cnt FROM invoices
     WHERE contract_id = p_contract_id AND period = v_period_start AND status <> 'CANCELLED';
    IF v_cnt > 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Hợp đồng đã có hóa đơn cho kỳ này';
    END IF;
 
    -- Kiểm tra đủ chỉ số công tơ trước khi tạo bất cứ thứ gì
    SELECT GROUP_CONCAT(sv.name ORDER BY sv.id SEPARATOR ', ') INTO v_missing
      FROM contract_services cs
      JOIN services sv ON sv.id = cs.service_id AND sv.billing_type = 'METERED'
      LEFT JOIN meter_readings mr
             ON mr.room_id = v_room_id AND mr.service_id = sv.id AND mr.period = v_period_start
     WHERE cs.contract_id = p_contract_id AND mr.id IS NULL;
    IF v_missing IS NOT NULL THEN
        SET v_msg = LEFT(CONCAT('Thiếu chỉ số ', v_missing, ' của phòng ', v_room_code,
                                ' kỳ ', DATE_FORMAT(v_period_start, '%m/%Y')), 128);
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = v_msg;
    END IF;
 
    -- Tạo hóa đơn + số hóa đơn
    INSERT INTO invoices (contract_id, period, issue_date, due_date, created_by)
    VALUES (p_contract_id, v_period_start, v_issue_date,
            DATE_ADD(v_issue_date, INTERVAL v_due_days DAY), p_created_by);
    SET v_invoice_id = LAST_INSERT_ID();
 
    UPDATE invoices
       SET invoice_no = CONCAT('INV-', DATE_FORMAT(v_period_start, '%Y%m'), '-', LPAD(v_invoice_id, 5, '0'))
     WHERE id = v_invoice_id;
 
    -- Tiền phòng
    SET v_days = DATEDIFF(v_to, v_from) + 1;
    IF v_days = v_days_in_month THEN
        INSERT INTO invoice_items (invoice_id, item_type, description, quantity, unit_name, unit_price)
        VALUES (v_invoice_id, 'RENT',
                CONCAT('Tiền thuê phòng ', v_room_code, ' tháng ', DATE_FORMAT(v_period_start, '%m/%Y')),
                1, 'tháng', v_monthly_rent);
    ELSE
        INSERT INTO invoice_items (invoice_id, item_type, description, quantity, unit_name, unit_price)
        VALUES (v_invoice_id, 'RENT',
                CONCAT('Tiền thuê phòng ', v_room_code, ' tháng ', DATE_FORMAT(v_period_start, '%m/%Y'),
                       ' (', v_days, '/', v_days_in_month, ' ngày)'),
                1, 'tháng', ROUND(v_monthly_rent * v_days / v_days_in_month, 0));
    END IF;
 
    -- Dịch vụ
    OPEN cur_svc;
    svc_loop: LOOP
        SET v_done = 0;
        FETCH cur_svc INTO v_svc_id, v_svc_name, v_svc_type, v_svc_unit, v_svc_price;
        IF v_done = 1 THEN
            LEAVE svc_loop;
        END IF;
 
        SET v_prev = NULL, v_cur = NULL;
 
        IF v_svc_type = 'METERED' THEN
            SELECT mr.previous_reading, mr.current_reading, mr.consumption
              INTO v_prev, v_cur, v_qty
              FROM meter_readings mr
             WHERE mr.room_id = v_room_id AND mr.service_id = v_svc_id AND mr.period = v_period_start;
        ELSEIF v_svc_type = 'PER_PERSON' THEN
            SELECT GREATEST(COUNT(*), 1) INTO v_qty
              FROM contract_tenants ct
             WHERE ct.contract_id = p_contract_id
               AND ct.move_in_date <= v_period_end
               AND (ct.move_out_date IS NULL OR ct.move_out_date >= v_period_start);
        ELSE
            SET v_qty = 1;
        END IF;
 
        INSERT INTO invoice_items (invoice_id, item_type, service_id, description,
                                   previous_reading, current_reading, quantity, unit_name, unit_price)
        VALUES (v_invoice_id, 'SERVICE', v_svc_id, v_svc_name,
                v_prev, v_cur, v_qty, v_svc_unit, v_svc_price);
    END LOOP;
    CLOSE cur_svc;
 
    SET p_invoice_id = v_invoice_id;
END$$
 
-- ---------------------------------------------------------------------
-- generate_monthly_invoices: lập hóa đơn hàng loạt cho mọi hợp đồng ACTIVE
--   p_property_id = NULL -> tất cả cơ sở; p_issue_date = NULL -> hôm nay.
--   Hợp đồng lỗi (vd thiếu chỉ số) được báo trong cột result và bỏ qua.
--   Trả về 1 result set: contract_id, room_code, invoice_id, result.
-- ---------------------------------------------------------------------
CREATE PROCEDURE generate_monthly_invoices(
    IN p_period      DATE,
    IN p_property_id BIGINT,
    IN p_issue_date  DATE
)
BEGIN
    DECLARE v_done INT DEFAULT 0;
    DECLARE v_start, v_end DATE;
    DECLARE v_cid BIGINT;
    DECLARE v_rcode VARCHAR(30);
 
    DECLARE cur CURSOR FOR SELECT cid, rcode FROM tmp_gen_contracts ORDER BY ord;
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET v_done = 1;
 
    SET v_start = DATE_SUB(p_period, INTERVAL DAYOFMONTH(p_period) - 1 DAY);
    SET v_end   = LAST_DAY(p_period);
 
    DROP TEMPORARY TABLE IF EXISTS tmp_gen_contracts;
    DROP TEMPORARY TABLE IF EXISTS tmp_invoice_results;
 
    -- Chụp danh sách hợp đồng cần lập hóa đơn trước khi bắt đầu thay đổi dữ liệu
    CREATE TEMPORARY TABLE tmp_gen_contracts (
        ord INT NOT NULL AUTO_INCREMENT PRIMARY KEY, cid BIGINT, rcode VARCHAR(30)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    INSERT INTO tmp_gen_contracts (cid, rcode)
    SELECT c.id, rm.room_code
      FROM contracts c
      JOIN rooms rm ON rm.id = c.room_id
     WHERE c.status = 'ACTIVE'
       AND c.start_date <= v_end
       AND (c.end_date IS NULL OR c.end_date >= v_start)
       AND (p_property_id IS NULL OR rm.property_id = p_property_id)
       AND NOT EXISTS (SELECT 1 FROM invoices i
                        WHERE i.contract_id = c.id AND i.period = v_start AND i.status <> 'CANCELLED')
     ORDER BY rm.property_id, rm.room_code;
 
    CREATE TEMPORARY TABLE tmp_invoice_results (
        contract_id BIGINT, room_code VARCHAR(30), invoice_id BIGINT, result VARCHAR(500)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
 
    OPEN cur;
    gen_loop: LOOP
        SET v_done = 0;
        FETCH cur INTO v_cid, v_rcode;
        IF v_done = 1 THEN
            LEAVE gen_loop;
        END IF;
 
        BEGIN
            DECLARE v_err VARCHAR(500) DEFAULT NULL;
            DECLARE v_inv BIGINT DEFAULT NULL;
            DECLARE CONTINUE HANDLER FOR SQLEXCEPTION
                GET DIAGNOSTICS CONDITION 1 v_err = MESSAGE_TEXT;
 
            CALL generate_invoice(v_cid, v_start, p_issue_date, NULL, v_inv);
 
            IF v_err IS NULL THEN
                INSERT INTO tmp_invoice_results VALUES (v_cid, v_rcode, v_inv, 'OK');
            ELSE
                INSERT INTO tmp_invoice_results VALUES (v_cid, v_rcode, NULL, CONCAT('LỖI: ', v_err));
            END IF;
        END;
    END LOOP;
    CLOSE cur;
 
    SELECT contract_id, room_code, invoice_id, result FROM tmp_invoice_results;
 
    DROP TEMPORARY TABLE tmp_invoice_results;
    DROP TEMPORARY TABLE tmp_gen_contracts;
END$$
 
-- ---------------------------------------------------------------------
-- issue_invoice: phát hành hóa đơn (DRAFT -> ISSUED) và xếp hàng gửi Zalo/Email
--   theo kênh ưa thích của người đứng tên hợp đồng.
--   p_notification_id = NULL nếu khách chưa có thông tin liên lạc cho kênh đó.
-- ---------------------------------------------------------------------
CREATE PROCEDURE issue_invoice(IN p_invoice_id BIGINT, OUT p_notification_id BIGINT)
BEGIN
    DECLARE v_cnt INT DEFAULT 0;
    DECLARE v_status VARCHAR(20);
    DECLARE v_contract_id BIGINT;
    DECLARE v_invoice_no VARCHAR(30);
    DECLARE v_period, v_due DATE;
    DECLARE v_total DECIMAL(14,0);
    DECLARE v_tenant_id BIGINT DEFAULT NULL;
    DECLARE v_tenant_name VARCHAR(150);
    DECLARE v_channel VARCHAR(10);
    DECLARE v_recipient VARCHAR(255) DEFAULT NULL;
    DECLARE v_room_code VARCHAR(30);
 
    SET p_notification_id = NULL;
 
    SELECT COUNT(*) INTO v_cnt FROM invoices WHERE id = p_invoice_id;
    IF v_cnt = 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Không tìm thấy hóa đơn';
    END IF;
 
    SELECT status, contract_id, invoice_no, period, due_date, total_amount
      INTO v_status, v_contract_id, v_invoice_no, v_period, v_due, v_total
      FROM invoices WHERE id = p_invoice_id FOR UPDATE;
 
    IF v_status <> 'DRAFT' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Chỉ phát hành được hóa đơn ở trạng thái DRAFT';
    END IF;
 
    UPDATE invoices SET status = 'ISSUED', issued_at = NOW() WHERE id = p_invoice_id;
 
    SELECT t.id, t.full_name, t.preferred_channel,
           notification_recipient(t.preferred_channel, t.zalo_id, t.phone, t.email)
      INTO v_tenant_id, v_tenant_name, v_channel, v_recipient
      FROM contract_tenants ct
      JOIN tenants t ON t.id = ct.tenant_id
     WHERE ct.contract_id = v_contract_id AND ct.is_primary = 1;
 
    SELECT r.room_code INTO v_room_code
      FROM contracts c JOIN rooms r ON r.id = c.room_id
     WHERE c.id = v_contract_id;
 
    IF v_tenant_id IS NOT NULL AND v_recipient IS NOT NULL THEN
        INSERT INTO notifications (tenant_id, invoice_id, channel, notif_type, recipient, subject, body)
        VALUES (v_tenant_id, p_invoice_id, v_channel, 'INVOICE', v_recipient,
                CONCAT('Hóa đơn tiền phòng ', v_room_code, ' - kỳ ', DATE_FORMAT(v_period, '%m/%Y')),
                CONCAT('Chào ', v_tenant_name, ', hóa đơn tiền phòng ', v_room_code,
                       ' kỳ ', DATE_FORMAT(v_period, '%m/%Y'),
                       ' (mã ', IFNULL(v_invoice_no, CONCAT('#', p_invoice_id)), '): ', fmt_vnd(v_total),
                       '. Hạn thanh toán: ', DATE_FORMAT(v_due, '%d/%m/%Y'), '.'));
        SET p_notification_id = LAST_INSERT_ID();
    END IF;
END$$
 
-- ---------------------------------------------------------------------
-- issue_invoices_for_period: phát hành hàng loạt mọi hóa đơn DRAFT của 1 kỳ
--   p_property_id = NULL -> tất cả cơ sở. Trả về số hóa đơn đã phát hành.
-- ---------------------------------------------------------------------
CREATE PROCEDURE issue_invoices_for_period(IN p_period DATE, IN p_property_id BIGINT)
BEGIN
    DECLARE v_done INT DEFAULT 0;
    DECLARE v_id BIGINT;
    DECLARE v_notif BIGINT;
    DECLARE v_issued INT DEFAULT 0;
    DECLARE v_start DATE;
 
    DECLARE cur CURSOR FOR SELECT id FROM tmp_issue_ids;
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET v_done = 1;
 
    SET v_start = DATE_SUB(p_period, INTERVAL DAYOFMONTH(p_period) - 1 DAY);
 
    DROP TEMPORARY TABLE IF EXISTS tmp_issue_ids;
    CREATE TEMPORARY TABLE tmp_issue_ids (id BIGINT PRIMARY KEY) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    INSERT INTO tmp_issue_ids
    SELECT i.id
      FROM invoices i
      JOIN contracts c ON c.id = i.contract_id
      JOIN rooms r     ON r.id = c.room_id
     WHERE i.status = 'DRAFT' AND i.period = v_start
       AND (p_property_id IS NULL OR r.property_id = p_property_id);
 
    OPEN cur;
    iss_loop: LOOP
        SET v_done = 0;
        FETCH cur INTO v_id;
        IF v_done = 1 THEN
            LEAVE iss_loop;
        END IF;
        CALL issue_invoice(v_id, v_notif);
        SET v_issued = v_issued + 1;
    END LOOP;
    CLOSE cur;
 
    DROP TEMPORARY TABLE tmp_issue_ids;
    SELECT v_issued AS issued_invoices;
END$$
 
-- ---------------------------------------------------------------------
-- mark_overdue_invoices: chuyển hóa đơn quá hạn -> OVERDUE (chạy cron hằng ngày)
-- ---------------------------------------------------------------------
CREATE PROCEDURE mark_overdue_invoices()
BEGIN
    UPDATE invoices SET status = 'OVERDUE'
     WHERE status IN ('ISSUED','PARTIAL') AND due_date < CURRENT_DATE;
    SELECT ROW_COUNT() AS overdue_invoices;
END$$
 
-- ---------------------------------------------------------------------
-- enqueue_overdue_reminders: tạo thông báo nhắc nợ cho hóa đơn OVERDUE
--   (không nhắc lại nếu đã nhắc trong p_min_days_between ngày gần đây; NULL -> 3 ngày)
-- ---------------------------------------------------------------------
CREATE PROCEDURE enqueue_overdue_reminders(IN p_min_days_between INT)
BEGIN
    DECLARE v_days INT;
    SET v_days = IFNULL(p_min_days_between, 3);
 
    INSERT INTO notifications (tenant_id, invoice_id, channel, notif_type, recipient, subject, body)
    SELECT t.id, i.id, t.preferred_channel, 'PAYMENT_REMINDER',
           notification_recipient(t.preferred_channel, t.zalo_id, t.phone, t.email),
           CONCAT('Nhắc thanh toán hóa đơn ', IFNULL(i.invoice_no, CONCAT('#', i.id))),
           CONCAT('Chào ', t.full_name, ', hóa đơn ', IFNULL(i.invoice_no, CONCAT('#', i.id)),
                  ' (phòng ', r.room_code, ', kỳ ', DATE_FORMAT(i.period, '%m/%Y'),
                  ') đã quá hạn ', DATEDIFF(CURRENT_DATE, i.due_date),
                  ' ngày. Số tiền còn nợ: ', fmt_vnd(i.balance), '. Vui lòng thanh toán sớm.')
      FROM invoices i
      JOIN contracts c         ON c.id = i.contract_id
      JOIN rooms r             ON r.id = c.room_id
      JOIN contract_tenants ct ON ct.contract_id = c.id AND ct.is_primary = 1
      JOIN tenants t           ON t.id = ct.tenant_id
     WHERE i.status = 'OVERDUE'
       AND notification_recipient(t.preferred_channel, t.zalo_id, t.phone, t.email) IS NOT NULL
       AND NOT EXISTS (SELECT 1 FROM notifications nt
                        WHERE nt.invoice_id = i.id
                          AND nt.notif_type = 'PAYMENT_REMINDER'
                          AND nt.created_at > NOW() - INTERVAL v_days DAY);
    SELECT ROW_COUNT() AS reminders_created;
END$$
 
-- ---------------------------------------------------------------------
-- expire_contracts: hợp đồng quá hạn -> EXPIRED (trigger trả phòng về AVAILABLE)
-- ---------------------------------------------------------------------
CREATE PROCEDURE expire_contracts()
BEGIN
    UPDATE contracts SET status = 'EXPIRED'
     WHERE status = 'ACTIVE' AND end_date < CURRENT_DATE;
    SELECT ROW_COUNT() AS expired_contracts;
END$$
 
DELIMITER ;
 
-- =====================================================================
-- 5. VIEW BÁO CÁO
-- =====================================================================
 
-- Tổng quan phòng: trống / đã thuê, khách thuê, thời hạn hợp đồng
CREATE VIEW v_room_overview AS
SELECT p.id   AS property_id,
       p.name AS property_name,
       r.id   AS room_id,
       r.room_code,
       r.floor_no,
       r.area_m2,
       r.base_rent,
       r.status AS room_status,
       c.id   AS contract_id,
       c.contract_no,
       c.start_date,
       c.end_date,
       DATEDIFF(c.end_date, CURRENT_DATE) AS days_to_expiry,
       t.full_name AS primary_tenant,
       t.phone     AS tenant_phone,
       (SELECT COUNT(*) FROM contract_tenants x
         WHERE x.contract_id = c.id AND x.move_out_date IS NULL) AS occupant_count
  FROM rooms r
  JOIN properties p             ON p.id = r.property_id
  LEFT JOIN contracts c         ON c.room_id = r.id AND c.status = 'ACTIVE'
  LEFT JOIN contract_tenants ct ON ct.contract_id = c.id AND ct.is_primary = 1
  LEFT JOIN tenants t           ON t.id = ct.tenant_id;
 
-- Công nợ quá hạn
CREATE VIEW v_overdue_debts AS
SELECT i.id AS invoice_id,
       i.invoice_no,
       p.name      AS property_name,
       r.room_code,
       t.full_name AS tenant_name,
       t.phone     AS tenant_phone,
       i.period,
       i.due_date,
       DATEDIFF(CURRENT_DATE, i.due_date) AS days_overdue,
       i.total_amount,
       i.paid_amount,
       i.balance
  FROM invoices i
  JOIN contracts c         ON c.id = i.contract_id
  JOIN rooms r             ON r.id = c.room_id
  JOIN properties p        ON p.id = r.property_id
  LEFT JOIN contract_tenants ct ON ct.contract_id = c.id AND ct.is_primary = 1
  LEFT JOIN tenants t      ON t.id = ct.tenant_id
 WHERE i.status IN ('ISSUED','PARTIAL','OVERDUE')
   AND i.balance > 0
   AND i.due_date < CURRENT_DATE;
 
-- Doanh thu theo tháng / cơ sở
CREATE VIEW v_monthly_revenue AS
SELECT p.id   AS property_id,
       p.name AS property_name,
       i.period,
       COUNT(*)            AS invoice_count,
       SUM(i.total_amount) AS billed,
       SUM(i.paid_amount)  AS collected,
       SUM(i.balance)      AS outstanding
  FROM invoices i
  JOIN contracts c  ON c.id = i.contract_id
  JOIN rooms r      ON r.id = c.room_id
  JOIN properties p ON p.id = r.property_id
 WHERE i.status NOT IN ('DRAFT','CANCELLED')
 GROUP BY p.id, p.name, i.period;
 
-- Hợp đồng sắp hết hạn (trong 60 ngày)
CREATE VIEW v_contracts_expiring AS
SELECT c.id AS contract_id, c.contract_no, p.name AS property_name, r.room_code,
       t.full_name AS primary_tenant, t.phone AS tenant_phone,
       c.end_date, DATEDIFF(c.end_date, CURRENT_DATE) AS days_left
  FROM contracts c
  JOIN rooms r      ON r.id = c.room_id
  JOIN properties p ON p.id = r.property_id
  LEFT JOIN contract_tenants ct ON ct.contract_id = c.id AND ct.is_primary = 1
  LEFT JOIN tenants t           ON t.id = ct.tenant_id
 WHERE c.status = 'ACTIVE'
   AND c.end_date IS NOT NULL
   AND c.end_date <= DATE_ADD(CURRENT_DATE, INTERVAL 60 DAY)
 ORDER BY c.end_date;
 
-- Yêu cầu sửa chữa chưa hoàn tất (ưu tiên cao xếp trước)
CREATE VIEW v_open_maintenance AS
SELECT m.id AS request_id, p.name AS property_name, r.room_code, t.full_name AS reported_by,
       m.title, m.category, m.priority, m.status, m.reported_at,
       u.full_name AS assigned_to_name, m.vendor_name
  FROM maintenance_requests m
  JOIN rooms r      ON r.id = m.room_id
  JOIN properties p ON p.id = r.property_id
  LEFT JOIN tenants t ON t.id = m.tenant_id
  LEFT JOIN users u   ON u.id = m.assigned_to
 WHERE m.status IN ('OPEN','ASSIGNED','IN_PROGRESS')
 ORDER BY m.priority DESC, m.reported_at;