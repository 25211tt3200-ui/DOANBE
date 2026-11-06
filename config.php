<?php
// Cấu hình kết nối cho WampServer (root mặc định KHÔNG có mật khẩu).
// Dùng 127.0.0.1 thay vì localhost để tránh lỗi kết nối qua named pipe/IPv6 trên Windows.
const DB_HOST = '127.0.0.1';
const DB_PORT = 3306;
const DB_NAME = 'boarding_house';
const DB_USER = 'root';
const DB_PASS = '';

const APP_NAME = 'Quản lý nhà trọ';
const APP_TZ   = 'Asia/Ho_Chi_Minh';
