<?php
require __DIR__ . '/inc/bootstrap.php';
require_login();

if (is_post() && post('do') === 'pay') {
    $iid = (int)post('invoice_id');
    run(function () use ($iid) {
        $amount = (int)post('amount');
        if (!$iid) throw new Exception('Hãy chọn hóa đơn.');
        if ($amount <= 0) throw new Exception('Số tiền thanh toán phải lớn hơn 0.');
        q('INSERT INTO payments (invoice_id, amount, method, paid_at, reference_code, received_by, note) VALUES (?,?,?,?,?,?,?)',
          [$iid, $amount, post('method'), dtl_to_sql(post('paid_at')) ?: date('Y-m-d H:i:s'), nul(post('reference_code')), user()['id'], nul(post('note'))]);
    }, 'Đã ghi nhận thanh toán.', 'payments.php');
}

// Hóa đơn còn nợ để chọn
$open = [];
foreach (rows("SELECT i.id, i.invoice_no, i.period, i.balance, r.room_code, t.full_name
    FROM invoices i JOIN contracts c ON c.id = i.contract_id JOIN rooms r ON r.id = c.room_id
    LEFT JOIN contract_tenants ct ON ct.contract_id = c.id AND ct.is_primary = 1 LEFT JOIN tenants t ON t.id = ct.tenant_id
    WHERE i.status IN ('ISSUED','PARTIAL','OVERDUE') AND i.balance > 0 ORDER BY r.room_code, i.period") as $i)
    $open[$i['id']] = ['label' => $i['room_code'] . ' · ' . $i['full_name'] . ' · kỳ ' . per($i['period']) . ' · còn ' . vnd($i['balance']), 'balance' => (int)$i['balance']];
$pre = (int)g('invoice_id');

$from = g('from', date('Y-m-01')); $to = g('to', date('Y-m-d')); $fm = g('method');
$where = ['DATE(py.paid_at) BETWEEN ? AND ?']; $par = [$from, $to];
if ($fm !== '') { $where[] = 'py.method = ?'; $par[] = $fm; }
$list = rows('SELECT py.*, i.invoice_no, i.id AS iid, r.room_code, t.full_name AS tenant, u.full_name AS receiver
    FROM payments py JOIN invoices i ON i.id = py.invoice_id JOIN contracts c ON c.id = i.contract_id JOIN rooms r ON r.id = c.room_id
    LEFT JOIN contract_tenants ct ON ct.contract_id = c.id AND ct.is_primary = 1 LEFT JOIN tenants t ON t.id = ct.tenant_id
    LEFT JOIN users u ON u.id = py.received_by
    WHERE ' . implode(' AND ', $where) . ' ORDER BY py.paid_at DESC, py.id DESC LIMIT 500', $par);
$total = 0; $byMethod = [];
foreach ($list as $p) { $total += $p['amount']; $byMethod[$p['method']] = ($byMethod[$p['method']] ?? 0) + $p['amount']; }

render_header('Thanh toán');
?>
<div class="card mb-4"><div class="card-header">Ghi nhận thanh toán</div><div class="card-body">
<?php if (!$open): ?><div class="text-muted">Hiện không có hóa đơn nào còn nợ.</div>
<?php else: ?>
<form method="post" class="row g-2 align-items-end"><?= csrf_field() ?><input type="hidden" name="do" value="pay">
  <div class="col-lg-5"><label class="form-label small mb-0">Hóa đơn</label>
    <select name="invoice_id" id="inv" class="form-select form-select-sm" required><option value="">— Chọn hóa đơn còn nợ —</option>
    <?php foreach ($open as $k => $o): ?><option value="<?= (int)$k ?>" data-bal="<?= $o['balance'] ?>" <?= $pre === (int)$k ? 'selected' : '' ?>><?= e($o['label']) ?></option><?php endforeach; ?></select></div>
  <div class="col-lg-2"><label class="form-label small mb-0">Số tiền (đ)</label><input type="number" min="1" name="amount" id="amt" class="form-control form-control-sm" required></div>
  <div class="col-lg-2"><label class="form-label small mb-0">Hình thức</label><?= select('method', options('method'), 'CASH', 'class="form-select-sm"') ?></div>
  <div class="col-lg-3"><label class="form-label small mb-0">Thời điểm</label><input type="datetime-local" name="paid_at" class="form-control form-control-sm" value="<?= date('Y-m-d\TH:i') ?>"></div>
  <div class="col-lg-3"><input name="reference_code" class="form-control form-control-sm" placeholder="Mã giao dịch (nếu có)"></div>
  <div class="col-lg-6"><input name="note" class="form-control form-control-sm" placeholder="Ghi chú"></div>
  <div class="col-auto"><button class="btn btn-sm btn-success">Ghi nhận</button></div>
</form>
<?php add_js("(function(){var s=document.getElementById('inv'),a=document.getElementById('amt');function f(){var o=s.options[s.selectedIndex];if(o&&o.dataset.bal){a.value=o.dataset.bal;a.max=o.dataset.bal;}}s.addEventListener('change',f);f();})();"); ?>
<?php endif; ?></div></div>

<form class="row g-2 mb-3 no-print" method="get">
  <div class="col-md-2"><input type="date" name="from" class="form-control" value="<?= e($from) ?>"></div>
  <div class="col-md-2"><input type="date" name="to" class="form-control" value="<?= e($to) ?>"></div>
  <div class="col-md-3"><?= select('method', options('method'), $fm, '', 'Mọi hình thức') ?></div>
  <div class="col-auto"><button class="btn btn-outline-primary">Lọc</button></div>
</form>
<div class="row g-3 mb-3">
  <div class="col-md-3"><div class="card stat"><div class="card-body py-2"><div class="text-muted small">Tổng thu trong khoảng</div><div class="h5 mb-0"><?= vnd($total) ?></div></div></div></div>
  <?php foreach ($byMethod as $m => $amt): ?><div class="col-md-2"><div class="card"><div class="card-body py-2"><div class="small"><?= badge('method', $m) ?></div><div class="fw-semibold"><?= vnd($amt) ?></div></div></div></div><?php endforeach; ?>
</div>
<div class="card"><div class="table-responsive"><table class="table table-hover mb-0">
<thead class="table-light"><tr><th>Thời điểm</th><th>Phòng</th><th>Khách</th><th>Hóa đơn</th><th>Hình thức</th><th class="num">Số tiền</th><th>Mã GD</th><th>Người nhận</th></tr></thead><tbody>
<?php foreach ($list as $p): ?><tr>
  <td><?= dt($p['paid_at']) ?></td><td><strong><?= e($p['room_code']) ?></strong></td><td><?= e($p['tenant']) ?></td>
  <td><a href="invoice.php?id=<?= (int)$p['iid'] ?>"><?= e($p['invoice_no']) ?></a></td><td><?= badge('method', $p['method']) ?></td>
  <td class="num fw-semibold"><?= vnd($p['amount']) ?></td><td><?= e($p['reference_code']) ?></td><td><?= e($p['receiver']) ?></td></tr>
<?php endforeach; if (!$list) echo '<tr><td colspan="8" class="text-center text-muted py-4">Không có thanh toán trong khoảng này.</td></tr>'; ?></tbody></table></div></div>
<?php render_footer();
