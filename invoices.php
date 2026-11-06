<?php
require __DIR__ . '/inc/bootstrap.php';
require_login();

$props = [];
foreach (rows('SELECT id, name FROM properties ORDER BY id') as $p) $props[$p['id']] = $p['name'];

$results = null; $resultTitle = '';

/* ---------------- Công cụ lập / phát hành hàng loạt ---------------- */
if (is_post()) {
    if (!is_manager()) deny();
    $do = post('do');
    $period = ym_to_period(post('ym'));
    $ppid = post('property_id') !== '' ? (int)post('property_id') : null;
    try {
        if ($do === 'generate') {
            $results = call_proc('CALL generate_monthly_invoices(?,?,?)', [$period, $ppid, post('issue_date') !== '' ? post('issue_date') : null]);
            $resultTitle = 'Kết quả lập hóa đơn kỳ ' . per($period);
            $ok = 0; foreach ($results as $r) if ($r['result'] === 'OK') $ok++;
            flash($ok ? 'success' : 'warning', "Đã lập $ok hóa đơn nháp" . (count($results) - $ok ? ', ' . (count($results) - $ok) . ' hợp đồng bị bỏ qua (xem chi tiết bên dưới).' : '.'));
            if (!$results) flash('info', 'Không có hợp đồng nào cần lập hóa đơn trong kỳ này (đã có hóa đơn hoặc không hiệu lực).');
        } elseif ($do === 'issue_all') {
            $r = call_proc('CALL issue_invoices_for_period(?,?)', [$period, $ppid]);
            flash('success', 'Đã phát hành ' . (int)($r[0]['issued_invoices'] ?? 0) . ' hóa đơn và xếp hàng gửi thông báo cho khách.');
            redirect('invoices.php?ym=' . substr($period, 0, 7));
        } elseif ($do === 'overdue') {
            $a = call_proc('CALL mark_overdue_invoices()');
            $b = call_proc('CALL enqueue_overdue_reminders(?)', [(int)post('min_days', 3)]);
            flash('success', 'Đã chuyển ' . (int)($a[0]['overdue_invoices'] ?? 0) . ' hóa đơn sang quá hạn; tạo ' . (int)($b[0]['reminders_created'] ?? 0) . ' thông báo nhắc nợ.');
            redirect('invoices.php');
        } elseif ($do === 'expire') {
            $a = call_proc('CALL expire_contracts()');
            flash('success', 'Đã chuyển ' . (int)($a[0]['expired_contracts'] ?? 0) . ' hợp đồng sang hết hạn.');
            redirect('invoices.php');
        } elseif ($do === 'one') {
            q('CALL generate_invoice(?,?,?,?,@new_invoice)', [(int)post('contract_id'), $period, post('issue_date') !== '' ? post('issue_date') : null, user()['id']]);
            $nid = (int)val('SELECT @new_invoice');
            flash('success', 'Đã lập hóa đơn nháp.');
            redirect('invoice.php?id=' . $nid);
        }
    } catch (Throwable $e) {
        flash('danger', err_text($e));
    }
}

/* ---------------- Bộ lọc ---------------- */
$fym = g('ym'); $fp = g('property_id'); $fs = g('status'); $fq = g('q');
$where = []; $par = [];
if ($fym !== '') { $where[] = 'i.period = ?'; $par[] = ym_to_period($fym); }
if ($fp !== '')  { $where[] = 'p.id = ?'; $par[] = (int)$fp; }
if ($fs !== '')  { $where[] = 'i.status = ?'; $par[] = $fs; }
if ($fq !== '')  { $where[] = '(r.room_code LIKE ? OR t.full_name LIKE ? OR i.invoice_no LIKE ?)'; $l = '%' . $fq . '%'; array_push($par, $l, $l, $l); }
$list = rows('SELECT i.*, r.room_code, p.name AS pname, t.full_name AS tenant
    FROM invoices i JOIN contracts c ON c.id = i.contract_id JOIN rooms r ON r.id = c.room_id JOIN properties p ON p.id = r.property_id
    LEFT JOIN contract_tenants ct ON ct.contract_id = c.id AND ct.is_primary = 1 LEFT JOIN tenants t ON t.id = ct.tenant_id'
    . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY i.period DESC, p.id, r.room_code, i.id DESC LIMIT 300', $par);
$sumBill = $sumPaid = $sumBal = 0;
foreach ($list as $i) if ($i['status'] !== 'CANCELLED' && $i['status'] !== 'DRAFT') { $sumBill += $i['total_amount']; $sumPaid += $i['paid_amount']; $sumBal += $i['balance']; }

$activeContracts = [];
if (is_manager()) {
    foreach (rows("SELECT c.id, c.contract_no, r.room_code, p.name AS pname FROM contracts c JOIN rooms r ON r.id = c.room_id JOIN properties p ON p.id = r.property_id WHERE c.status = 'ACTIVE' ORDER BY p.id, r.room_code") as $c)
        $activeContracts[$c['id']] = $c['pname'] . ' - ' . $c['room_code'] . ' (' . $c['contract_no'] . ')';
}
$defYm = $fym !== '' ? $fym : date('Y-m');

render_header('Hóa đơn');
?>
<?php if (is_manager()): ?>
<div class="card mb-4 no-print"><div class="card-header" role="button" data-bs-toggle="collapse" data-bs-target="#tools"><i class="bi bi-tools"></i> Công cụ lập &amp; phát hành hóa đơn (bấm để mở/đóng)</div>
<div class="collapse <?= ($results !== null || $fym !== '') ? 'show' : '' ?>" id="tools"><div class="card-body">
  <div class="row g-4">
    <div class="col-lg-6"><h6>1. Lập hóa đơn hàng loạt (nháp)</h6>
      <form method="post" class="row g-2"><?= csrf_field() ?><input type="hidden" name="do" value="generate">
        <div class="col-md-4"><label class="form-label small mb-0">Kỳ (tháng)</label><input type="month" name="ym" class="form-control form-control-sm" value="<?= e($defYm) ?>" required></div>
        <div class="col-md-4"><label class="form-label small mb-0">Cơ sở</label><?= select('property_id', $props, '', 'class="form-select-sm"', 'Tất cả') ?></div>
        <div class="col-md-4"><label class="form-label small mb-0">Ngày phát hành</label><input type="date" name="issue_date" class="form-control form-control-sm" value="<?= date('Y-m-d') ?>"></div>
        <div class="col-12"><button class="btn btn-sm btn-primary">Lập hóa đơn</button>
          <span class="small text-muted">Cần nhập đủ chỉ số điện/nước của kỳ trước (<a href="meters.php">Chỉ số</a>).</span></div></form></div>
    <div class="col-lg-6"><h6>2. Phát hành &amp; gửi thông báo (Zalo/Email)</h6>
      <form method="post" class="row g-2" <?= confirm_attr('Phát hành mọi hóa đơn nháp của kỳ này?') ?>><?= csrf_field() ?><input type="hidden" name="do" value="issue_all">
        <div class="col-md-5"><label class="form-label small mb-0">Kỳ (tháng)</label><input type="month" name="ym" class="form-control form-control-sm" value="<?= e($defYm) ?>" required></div>
        <div class="col-md-5"><label class="form-label small mb-0">Cơ sở</label><?= select('property_id', $props, '', 'class="form-select-sm"', 'Tất cả') ?></div>
        <div class="col-12"><button class="btn btn-sm btn-success">Phát hành hóa đơn nháp</button></div></form></div>
    <div class="col-lg-6"><h6>3. Lập hóa đơn cho 1 hợp đồng</h6>
      <form method="post" class="row g-2"><?= csrf_field() ?><input type="hidden" name="do" value="one">
        <div class="col-md-6"><?= select('contract_id', $activeContracts, '', 'class="form-select-sm" required', '— Chọn hợp đồng —') ?></div>
        <div class="col-md-3"><input type="month" name="ym" class="form-control form-control-sm" value="<?= e($defYm) ?>" required></div>
        <div class="col-md-3"><input type="date" name="issue_date" class="form-control form-control-sm" value="<?= date('Y-m-d') ?>"></div>
        <div class="col-12"><button class="btn btn-sm btn-outline-primary">Lập hóa đơn</button></div></form></div>
    <div class="col-lg-6"><h6>4. Việc hằng ngày</h6>
      <form method="post" class="d-inline"><?= csrf_field() ?><input type="hidden" name="do" value="overdue"><input type="hidden" name="min_days" value="3">
        <button class="btn btn-sm btn-outline-danger">Cập nhật quá hạn + tạo nhắc nợ</button></form>
      <form method="post" class="d-inline" <?= confirm_attr('Chuyển các hợp đồng đã quá ngày kết thúc sang “Hết hạn”?') ?>><?= csrf_field() ?><input type="hidden" name="do" value="expire">
        <button class="btn btn-sm btn-outline-secondary">Chuyển HĐ quá hạn sang “Hết hạn”</button></form>
      <div class="small text-muted mt-1">Có thể tự động hóa bằng <code>cron_daily.php</code> (xem README).</div></div>
  </div>
  <?php if ($results): ?>
    <hr><h6><?= e($resultTitle) ?></h6>
    <table class="table table-sm"><thead><tr><th>Phòng</th><th>Kết quả</th><th></th></tr></thead><tbody>
    <?php foreach ($results as $r): ?><tr class="<?= $r['result'] === 'OK' ? '' : 'table-warning' ?>">
      <td><?= e($r['room_code']) ?></td><td><?= e($r['result']) ?></td>
      <td><?php if ($r['invoice_id']): ?><a href="invoice.php?id=<?= (int)$r['invoice_id'] ?>">Xem hóa đơn</a><?php endif; ?></td></tr>
    <?php endforeach; ?></tbody></table>
  <?php endif; ?>
</div></div></div>
<?php endif; ?>

<form class="row g-2 mb-3 no-print" method="get">
  <div class="col-md-2"><input type="month" name="ym" class="form-control" value="<?= e($fym) ?>"></div>
  <div class="col-md-3"><?= select('property_id', $props, $fp, '', 'Tất cả cơ sở') ?></div>
  <div class="col-md-2"><?= select('status', options('invoice'), $fs, '', 'Mọi trạng thái') ?></div>
  <div class="col-md-3"><input name="q" class="form-control" placeholder="Phòng / khách / số hóa đơn" value="<?= e($fq) ?>"></div>
  <div class="col-auto"><button class="btn btn-outline-primary">Lọc</button> <a class="btn btn-link" href="invoices.php">Xóa lọc</a></div>
</form>

<div class="card"><div class="table-responsive"><table class="table table-hover mb-0">
<thead class="table-light"><tr><th>Số HĐ</th><th>Kỳ</th><th>Cơ sở / Phòng</th><th>Khách</th><th>Hạn TT</th><th class="num">Tổng</th><th class="num">Đã trả</th><th class="num">Còn nợ</th><th>Trạng thái</th></tr></thead><tbody>
<?php foreach ($list as $i): ?>
<tr>
  <td><a class="fw-semibold" href="invoice.php?id=<?= (int)$i['id'] ?>"><?= e($i['invoice_no'] ?: '#' . $i['id']) ?></a></td>
  <td><?= per($i['period']) ?></td><td><?= e($i['pname']) ?> / <strong><?= e($i['room_code']) ?></strong></td><td><?= e($i['tenant']) ?></td>
  <td><?= d($i['due_date']) ?></td><td class="num"><?= vnd($i['total_amount']) ?></td><td class="num"><?= vnd($i['paid_amount']) ?></td>
  <td class="num <?= $i['balance'] > 0 && $i['status'] === 'OVERDUE' ? 'text-danger fw-semibold' : '' ?>"><?= vnd($i['balance']) ?></td><td><?= badge('invoice', $i['status']) ?></td>
</tr>
<?php endforeach; if (!$list) echo '<tr><td colspan="9" class="text-center text-muted py-4">Không có hóa đơn nào.</td></tr>'; ?>
</tbody>
<?php if ($list): ?><tfoot class="table-light"><tr><th colspan="5" class="text-end">Cộng (không tính nháp/đã hủy)</th><th class="num"><?= vnd($sumBill) ?></th><th class="num"><?= vnd($sumPaid) ?></th><th class="num"><?= vnd($sumBal) ?></th><th></th></tr></tfoot><?php endif; ?>
</table></div></div>
<?php render_footer();
