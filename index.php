<?php
require __DIR__ . '/inc/bootstrap.php';
require_login();

$rc = ['AVAILABLE' => 0, 'OCCUPIED' => 0, 'RESERVED' => 0, 'MAINTENANCE' => 0];
foreach (rows('SELECT room_status AS s, COUNT(*) AS c FROM v_room_overview GROUP BY room_status') as $r) $rc[$r['s']] = (int)$r['c'];
$totalRooms = array_sum($rc);
$occPct = $totalRooms ? round($rc['OCCUPIED'] * 100 / $totalRooms) : 0;

$debt      = row('SELECT COALESCE(SUM(balance), 0) AS s, COUNT(*) AS c FROM v_overdue_debts');
$expiring  = (int)val('SELECT COUNT(*) FROM v_contracts_expiring');
$openM     = (int)val("SELECT COUNT(*) FROM maintenance_requests WHERE status IN ('OPEN','ASSIGNED','IN_PROGRESS')");
$pendingN  = (int)val("SELECT COUNT(*) FROM notifications WHERE status = 'PENDING'");
$draftInv  = (int)val("SELECT COUNT(*) FROM invoices WHERE status = 'DRAFT'");
$rev = array_reverse(rows('SELECT period, SUM(billed) AS billed, SUM(collected) AS collected, SUM(outstanding) AS outstanding FROM v_monthly_revenue GROUP BY period ORDER BY period DESC LIMIT 6'));
$debts = rows('SELECT * FROM v_overdue_debts ORDER BY days_overdue DESC LIMIT 8');
$exp   = rows('SELECT * FROM v_contracts_expiring LIMIT 6');
$maint = rows('SELECT * FROM v_open_maintenance LIMIT 6');

$labels = []; $billed = []; $coll = [];
foreach ($rev as $r) { $labels[] = per($r['period']); $billed[] = (float)$r['billed']; $coll[] = (float)$r['collected']; }

function stat_card($icon, $color, $label, $value, $link = null, $sub = '') {
    $h = '<div class="col-6 col-xl-3"><div class="card stat h-100" style="border-left-color:var(--bs-' . $color . ')"><div class="card-body py-3">'
       . '<div class="d-flex justify-content-between"><div><div class="text-muted small">' . e($label) . '</div><div class="h4 mb-0">' . $value . '</div>'
       . ($sub ? '<div class="small text-muted">' . $sub . '</div>' : '') . '</div><i class="bi ' . $icon . ' fs-2 text-' . $color . ' opacity-50"></i></div>'
       . ($link ? '<a class="stretched-link" href="' . e($link) . '"></a>' : '') . '</div></div></div>';
    return $h;
}

render_header('Tổng quan');
?>
<div class="row g-3 mb-4">
  <?= stat_card('bi-door-open', 'primary', 'Tỷ lệ lấp đầy', $occPct . '%', 'rooms.php', $rc['OCCUPIED'] . '/' . $totalRooms . ' phòng đang thuê · ' . $rc['AVAILABLE'] . ' trống') ?>
  <?= stat_card('bi-exclamation-octagon', 'danger', 'Công nợ quá hạn', e(vnd($debt['s'])), 'reports.php?r=debts', (int)$debt['c'] . ' hóa đơn') ?>
  <?= stat_card('bi-calendar-x', 'warning', 'HĐ sắp hết hạn (60 ngày)', $expiring, 'reports.php?r=expiring') ?>
  <?= stat_card('bi-tools', 'info', 'Sửa chữa chưa xong', $openM, 'maintenance.php') ?>
  <?= stat_card('bi-receipt', 'secondary', 'Hóa đơn nháp chưa phát hành', $draftInv, 'invoices.php?status=DRAFT') ?>
  <?= stat_card('bi-bell', 'success', 'Thông báo chờ gửi', $pendingN, 'notifications.php') ?>
  <?= stat_card('bi-wrench-adjustable', 'secondary', 'Phòng đang bảo trì', $rc['MAINTENANCE'], 'rooms.php?status=MAINTENANCE') ?>
  <?= stat_card('bi-bookmark-check', 'info', 'Phòng đã đặt cọc', $rc['RESERVED'], 'rooms.php?status=RESERVED') ?>
</div>

<div class="row g-3 mb-3">
  <div class="col-lg-7"><div class="card h-100"><div class="card-header">Doanh thu 6 tháng gần nhất</div><div class="card-body">
    <?php if ($labels): ?><canvas id="rev" height="110"></canvas><?php else: ?><div class="text-muted">Chưa có hóa đơn nào được phát hành.</div><?php endif; ?></div></div></div>
  <div class="col-lg-5"><div class="card h-100"><div class="card-header d-flex justify-content-between">Công nợ quá hạn <a class="small" href="reports.php?r=debts">Xem tất cả</a></div>
    <div class="table-responsive"><table class="table table-sm mb-0"><tbody>
    <?php foreach ($debts as $d): ?><tr>
      <td><a href="invoice.php?id=<?= (int)$d['invoice_id'] ?>"><strong><?= e($d['room_code']) ?></strong></a> <span class="text-muted small"><?= e($d['tenant_name']) ?></span></td>
      <td class="small text-danger"><?= (int)$d['days_overdue'] ?> ngày</td><td class="num"><?= vnd($d['balance']) ?></td></tr>
    <?php endforeach; if (!$debts) echo '<tr><td class="text-muted text-center py-3">Không có công nợ quá hạn 🎉</td></tr>'; ?></tbody></table></div></div></div>
</div>

<div class="row g-3">
  <div class="col-lg-6"><div class="card h-100"><div class="card-header d-flex justify-content-between">Hợp đồng sắp hết hạn <a class="small" href="reports.php?r=expiring">Xem tất cả</a></div>
    <div class="table-responsive"><table class="table table-sm mb-0"><tbody>
    <?php foreach ($exp as $c): ?><tr>
      <td><a href="contracts.php?id=<?= (int)$c['contract_id'] ?>"><?= e($c['contract_no']) ?></a></td><td><strong><?= e($c['room_code']) ?></strong> <span class="text-muted small"><?= e($c['primary_tenant']) ?></span></td>
      <td class="small"><?= d($c['end_date']) ?></td><td class="num small <?= $c['days_left'] < 0 ? 'text-danger' : '' ?>"><?= (int)$c['days_left'] ?> ngày</td></tr>
    <?php endforeach; if (!$exp) echo '<tr><td class="text-muted text-center py-3">Không có hợp đồng nào sắp hết hạn.</td></tr>'; ?></tbody></table></div></div></div>
  <div class="col-lg-6"><div class="card h-100"><div class="card-header d-flex justify-content-between">Sửa chữa cần xử lý <a class="small" href="maintenance.php">Xem tất cả</a></div>
    <div class="table-responsive"><table class="table table-sm mb-0"><tbody>
    <?php foreach ($maint as $m): ?><tr>
      <td><a href="maintenance.php?id=<?= (int)$m['request_id'] ?>"><?= e($m['title']) ?></a><div class="small text-muted"><?= e($m['property_name']) ?> / <?= e($m['room_code']) ?></div></td>
      <td><?= badge('priority', $m['priority']) ?></td><td><?= badge('mstatus', $m['status']) ?></td></tr>
    <?php endforeach; if (!$maint) echo '<tr><td class="text-muted text-center py-3">Không có yêu cầu sửa chữa đang mở.</td></tr>'; ?></tbody></table></div></div></div>
</div>

<?php if ($labels): ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
<?php add_js('new Chart(document.getElementById("rev"),{type:"bar",data:{labels:' . json_encode($labels) . ',datasets:[{label:"Đã lập",data:' . json_encode($billed) . ',backgroundColor:"#0d6efd"},{label:"Đã thu",data:' . json_encode($coll) . ',backgroundColor:"#198754"}]},options:{responsive:true,scales:{y:{ticks:{callback:function(v){return v.toLocaleString("vi-VN")}}}}}});'); ?>
<?php endif;
render_footer();
