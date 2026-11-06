<?php
// Việc chạy hằng ngày (chỉ chạy bằng dòng lệnh):
//   C:\wamp64\bin\php\php8.x.x\php.exe C:\wamp64\www\quanly\cron_daily.php
// Đặt lịch bằng Windows Task Scheduler (mỗi ngày ~07:00).
if (php_sapi_name() !== 'cli') { http_response_code(403); exit('Chỉ chạy bằng dòng lệnh.'); }
require __DIR__ . '/inc/bootstrap.php';

$a = call_proc('CALL expire_contracts()');
echo 'Hợp đồng chuyển sang hết hạn: ' . (int)($a[0]['expired_contracts'] ?? 0) . PHP_EOL;
$b = call_proc('CALL mark_overdue_invoices()');
echo 'Hóa đơn chuyển sang quá hạn: ' . (int)($b[0]['overdue_invoices'] ?? 0) . PHP_EOL;
$c = call_proc('CALL enqueue_overdue_reminders(3)');
echo 'Thông báo nhắc nợ đã tạo: ' . (int)($c[0]['reminders_created'] ?? 0) . PHP_EOL;
