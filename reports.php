<?php
require __DIR__ . '/inc/bootstrap.php';
require_login();

// Mỗi báo cáo: tiêu đề, câu SQL (lấy từ các view), danh sách cột [tên => [nhãn, kiểu]]
$reports = [
    'rooms' => ['Tổng quan phòng',
        'SELECT * FROM v_room_overview ORDER BY property_id, room_code',
        ['property_name' => ['Cơ sở', 'text'], 'room_code' => ['Phòng', 'text'], 'floor_no' => ['Tầng', 'int'], 'area_m2' => ['m²', 'int'], 'base_rent' => ['Giá niêm yết', 'money'],
         'room_status' => ['Trạng thái', 'b:room'], 'contract_no' => ['Hợp đồng', 'text'], 'primary_tenant' => ['Người đứng tên', 'text'], 'tenant_phone' => ['SĐT', 'text'],
         'end_date' => ['Hết hạn', 'date'], 'days_to_expiry' => ['Còn (ngày)', 'int'], 'occupant_count' => ['Số người', 'int']]],
    'debts' => ['Công nợ quá hạn',
        'SELECT * FROM v_overdue_debts ORDER BY days_overdue DESC',
        ['invoice_no' => ['Số HĐ', 'text'], 'property_name' => ['Cơ sở', 'text'], 'room_code' => ['Phòng', 'text'], 'tenant_name' => ['Khách', 'text'], 'tenant_phone' => ['SĐT', 'text'],
         'period' => ['Kỳ', 'period'], 'due_date' => ['Hạn TT', 'date'], 'days_overdue' => ['Quá hạn (ngày)', 'int'], 'total_amount' => ['Tổng', 'money'], 'paid_amount' => ['Đã trả', 'money'], 'balance' => ['Còn nợ', 'money']]],
    'revenue' => ['Doanh thu theo tháng',
        'SELECT * FROM v_monthly_revenue ORDER BY period DESC, property_id',
        ['property_name' => ['Cơ sở', 'text'], 'period' => ['Kỳ', 'period'], 'invoice_count' => ['Số hóa đơn', 'int'], 'billed' => ['Đã lập', 'money'], 'collected' => ['Đã thu', 'money'], 'outstanding' => ['Còn phải thu', 'money']]],
    'expiring' => ['Hợp đồng sắp hết hạn (60 ngày)',
        'SELECT * FROM v_contracts_expiring',
        ['contract_no' => ['Hợp đồng', 'text'], 'property_name' => ['Cơ sở', 'text'], 'room_code' => ['Phòng', 'text'], 'primary_tenant' => ['Người đứng tên', 'text'], 'tenant_phone' => ['SĐT', 'text'], 'end_date' => ['Hết hạn', 'date'], 'days_left' => ['Còn (ngày)', 'int']]],
    'maintenance' => ['Sửa chữa chưa hoàn tất',
        'SELECT * FROM v_open_maintenance',
        ['request_id' => ['#', 'int'], 'property_name' => ['Cơ sở', 'text'], 'room_code' => ['Phòng', 'text'], 'reported_by' => ['Người báo', 'text'], 'title' => ['Nội dung', 'text'], 'category' => ['Loại', 'b:category'],
         'priority' => ['Ưu tiên', 'b:priority'], 'status' => ['Trạng thái', 'b:mstatus'], 'reported_at' => ['Báo lúc', 'datetime'], 'assigned_to_name' => ['Phụ trách', 'text'], 'vendor_name' => ['Thợ ngoài', 'text']]],
];
$key = g('r', 'rooms');
if (!isset($reports[$key])) $key = 'rooms';
[$rTitle, $sql, $cols] = $reports[$key];
$data = rows($sql);

// Xuất CSV (UTF-8 có BOM để Excel đọc đúng tiếng Việt)
if (g('csv') === '1') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="bao-cao-' . $key . '-' . date('Ymd') . '.csv"');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    fputcsv($out, array_map(fn($c) => $c[0], array_values($cols)));
    foreach ($data as $r) {
        $line = [];
        foreach ($cols as $name => $def) $line[] = $r[$name] ?? '';
        fputcsv($out, $line);
    }
    fclose($out);
    exit;
}

function cell($type, $v) {
    if ($v === null || $v === '') return '';
    if ($type === 'money')    return '<span class="num d-block">' . e(vnd($v)) . '</span>';
    if ($type === 'int')      return '<span class="num d-block">' . e(num($v)) . '</span>';
    if ($type === 'date')     return e(d($v));
    if ($type === 'datetime') return e(dt($v));
    if ($type === 'period')   return e(per($v));
    if (strncmp($type, 'b:', 2) === 0) return badge(substr($type, 2), $v);
    return e($v);
}

$chartLabels = $chartBilled = $chartCollected = [];
if ($key === 'revenue') {
    $agg = [];
    foreach ($data as $r) {
        $p = substr($r['period'], 0, 7);
        if (!isset($agg[$p])) $agg[$p] = [0, 0];
        $agg[$p][0] += $r['billed']; $agg[$p][1] += $r['collected'];
    }
    ksort($agg);
    foreach ($agg as $p => $v) { $chartLabels[] = substr($p, 5, 2) . '/' . substr($p, 0, 4); $chartBilled[] = (float)$v[0]; $chartCollected[] = (float)$v[1]; }
}

render_header('Báo cáo');
?>
<ul class="nav nav-pills mb-3 no-print">
  <?php foreach ($reports as $k => $r): ?><li class="nav-item"><a class="nav-link <?= $k === $key ? 'active' : '' ?>" href="reports.php?r=<?= e($k) ?>"><?= e($r[0]) ?></a></li><?php endforeach; ?>
</ul>
<div class="d-flex justify-content-between align-items-center mb-2">
  <h2 class="h5 mb-0"><?= e($rTitle) ?> <span class="text-muted small">(<?= count($data) ?> dòng)</span></h2>
  <span class="no-print"><a class="btn btn-sm btn-outline-success" href="reports.php?r=<?= e($key) ?>&csv=1"><i class="bi bi-filetype-csv"></i> Xuất CSV</a>
  <button class="btn btn-sm btn-outline-secondary" onclick="window.print()"><i class="bi bi-printer"></i> In</button></span>
</div>

<?php if ($key === 'revenue' && $chartLabels): ?>
<div class="card mb-3"><div class="card-body"><canvas id="rev" height="90"></canvas></div></div>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
<?php add_js('new Chart(document.getElementById("rev"),{type:"bar",data:{labels:' . json_encode($chartLabels) . ',datasets:[{label:"Đã lập",data:' . json_encode($chartBilled) . ',backgroundColor:"#0d6efd"},{label:"Đã thu",data:' . json_encode($chartCollected) . ',backgroundColor:"#198754"}]},options:{responsive:true,scales:{y:{ticks:{callback:function(v){return v.toLocaleString("vi-VN")}}}}}});'); ?>
<?php endif; ?>

<div class="card"><div class="table-responsive"><table class="table table-hover table-sm mb-0">
<thead class="table-light"><tr><?php foreach ($cols as $c): ?><th><?= e($c[0]) ?></th><?php endforeach; ?></tr></thead><tbody>
<?php foreach ($data as $r): ?><tr><?php foreach ($cols as $name => $def): ?><td><?= cell($def[1], $r[$name] ?? null) ?></td><?php endforeach; ?></tr>
<?php endforeach; if (!$data) echo '<tr><td colspan="' . count($cols) . '" class="text-center text-muted py-4">Không có dữ liệu.</td></tr>'; ?></tbody></table></div></div>
<?php render_footer();
