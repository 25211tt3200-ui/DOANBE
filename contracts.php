<?php
require __DIR__ . '/inc/bootstrap.php';
require_login();

$id   = (int)g('id');
$mode = $id ? 'view' : (isset($_GET['new']) ? 'new' : 'list');
if ($mode === 'new' && !is_manager()) deny();

/* ---------------- Xử lý thao tác ghi ---------------- */
if (is_post()) {
    if (!is_manager()) deny();
    $do   = post('do');
    $cid  = (int)post('id');
    $self = 'contracts.php?id=' . $cid;

    if ($do === 'create') {
        $room = (int)post('room_id'); $primary = (int)post('primary_tenant');
        $extras = array_values(array_unique(array_filter(array_map('intval', (array)post('extra', [])))));
        $extras = array_values(array_diff($extras, [$primary]));
        run(function () use ($room, $primary, $extras) {
            if (!$room || !$primary) throw new Exception('Hãy chọn phòng và người đứng tên hợp đồng.');
            if (post('contract_no') === '' || post('start_date') === '' || post('monthly_rent') === '') throw new Exception('Vui lòng nhập số hợp đồng, ngày bắt đầu và giá thuê.');
            $max = (int)val('SELECT max_occupants FROM rooms WHERE id = ?', [$room]);
            if (1 + count($extras) > $max) throw new Exception("Phòng này chỉ cho tối đa $max người ở.");
            return 'contracts.php?id=' . tx(function () use ($room, $primary, $extras) {
                q('INSERT INTO contracts (contract_no, room_id, start_date, end_date, monthly_rent, deposit_amount, deposit_status, deposit_paid_date, status, signed_at, notes)
                   VALUES (?,?,?,?,?,?,?,?,?,?,?)',
                  [post('contract_no'), $room, post('start_date'), nul(post('end_date')), post('monthly_rent'), (int)post('deposit_amount'),
                   post('deposit_status'), nul(post('deposit_paid_date')), post('status'), nul(post('signed_at')), nul(post('notes'))]);
                $new = insert_id();
                q('INSERT INTO contract_tenants (contract_id, tenant_id, is_primary, move_in_date) VALUES (?,?,1,?)', [$new, $primary, post('start_date')]);
                foreach ($extras as $t) q('INSERT INTO contract_tenants (contract_id, tenant_id, is_primary, move_in_date) VALUES (?,?,0,?)', [$new, $t, post('start_date')]);
                return $new;
            });
        }, 'Đã tạo hợp đồng.', 'contracts.php');
        $mode = 'new';
    } elseif ($do === 'update') {
        run(function () use ($cid) {
            q('UPDATE contracts SET end_date=?, monthly_rent=?, deposit_amount=?, deposit_status=?, deposit_paid_date=?, signed_at=?, notes=? WHERE id=?',
              [nul(post('end_date')), post('monthly_rent'), (int)post('deposit_amount'), post('deposit_status'), nul(post('deposit_paid_date')), nul(post('signed_at')), nul(post('notes')), $cid]);
        }, 'Đã cập nhật hợp đồng.', $self);
    } elseif ($do === 'activate') {
        run(function () use ($cid) {
            q("UPDATE contracts SET status='ACTIVE' WHERE id=? AND status='DRAFT'", [$cid]);
        }, 'Đã kích hoạt hợp đồng, phòng chuyển sang “Đang thuê”.', $self);
    } elseif ($do === 'terminate') {
        run(function () use ($cid) {
            tx(function () use ($cid) {
                $at = post('terminated_at') ?: date('Y-m-d');
                $n = q("UPDATE contracts SET status='TERMINATED', terminated_at=?, termination_reason=?, deposit_status=? WHERE id=? AND status='ACTIVE'",
                       [$at, nul(post('reason')), post('deposit_status'), $cid])->rowCount();
                if (!$n) throw new Exception('Chỉ chấm dứt được hợp đồng đang hiệu lực.');
                q('UPDATE contract_tenants SET move_out_date = GREATEST(move_in_date, CAST(? AS DATE)) WHERE contract_id=? AND move_out_date IS NULL', [$at, $cid]);
            });
        }, 'Đã chấm dứt hợp đồng, phòng được trả về trạng thái trống.', $self);
    } elseif ($do === 'delete') {
        run(function () use ($cid) {
            q("DELETE FROM contracts WHERE id=? AND status='DRAFT'", [$cid]);
        }, 'Đã xóa hợp đồng nháp.', 'contracts.php');
    } elseif ($do === 'add_tenant') {
        run(function () use ($cid) {
            $tid = (int)post('tenant_id');
            if (!$tid) throw new Exception('Hãy chọn khách thuê.');
            $max = (int)val('SELECT r.max_occupants FROM contracts c JOIN rooms r ON r.id = c.room_id WHERE c.id = ?', [$cid]);
            $cnt = (int)val('SELECT COUNT(*) FROM contract_tenants WHERE contract_id = ? AND move_out_date IS NULL', [$cid]);
            if ($cnt >= $max) throw new Exception("Phòng chỉ cho tối đa $max người ở.");
            q('INSERT INTO contract_tenants (contract_id, tenant_id, is_primary, move_in_date) VALUES (?,?,0,?)
               ON DUPLICATE KEY UPDATE move_in_date = VALUES(move_in_date), move_out_date = NULL', [$cid, $tid, post('move_in_date') ?: date('Y-m-d')]);
        }, 'Đã thêm người ở cùng.', $self);
    } elseif ($do === 'move_out') {
        run(function () use ($cid) {
            q('UPDATE contract_tenants SET move_out_date = GREATEST(move_in_date, CAST(? AS DATE)) WHERE contract_id=? AND tenant_id=? AND is_primary=0',
              [post('move_out_date') ?: date('Y-m-d'), $cid, (int)post('tenant_id')]);
        }, 'Đã ghi nhận khách chuyển đi.', $self);
    } elseif ($do === 'save_services') {
        run(function () use ($cid) {
            foreach ((array)post('custom', []) as $sid => $price) {
                q('UPDATE contract_services SET custom_unit_price = ? WHERE contract_id = ? AND service_id = ?', [nul($price), $cid, (int)$sid]);
            }
        }, 'Đã lưu đơn giá riêng.', $self);
    } elseif ($do === 'add_service') {
        run(function () use ($cid) {
            q('INSERT IGNORE INTO contract_services (contract_id, service_id) VALUES (?,?)', [$cid, (int)post('service_id')]);
        }, 'Đã thêm dịch vụ vào hợp đồng.', $self);
    } elseif ($do === 'del_service') {
        run(function () use ($cid) {
            q('DELETE FROM contract_services WHERE contract_id=? AND service_id=?', [$cid, (int)post('service_id')]);
        }, 'Đã gỡ dịch vụ khỏi hợp đồng.', $self);
    }
}

/* ---------------- Dữ liệu hiển thị ---------------- */
$title = 'Hợp đồng';
if ($mode === 'list') {
    $fs = g('status'); $fp = g('property_id');
    $where = []; $par = [];
    if ($fs !== '') { $where[] = 'c.status = ?'; $par[] = $fs; }
    if ($fp !== '') { $where[] = 'r.property_id = ?'; $par[] = (int)$fp; }
    $list = rows('SELECT c.*, r.room_code, p.name AS pname, t.full_name AS primary_tenant, DATEDIFF(c.end_date, CURRENT_DATE) AS days_left
        FROM contracts c JOIN rooms r ON r.id = c.room_id JOIN properties p ON p.id = r.property_id
        LEFT JOIN contract_tenants ct ON ct.contract_id = c.id AND ct.is_primary = 1 LEFT JOIN tenants t ON t.id = ct.tenant_id'
        . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY c.id DESC', $par);
    $props = [];
    foreach (rows('SELECT id, name FROM properties ORDER BY id') as $p) $props[$p['id']] = $p['name'];
} elseif ($mode === 'new') {
    $title = 'Tạo hợp đồng mới';
    $roomRows = rows("SELECT r.id, r.room_code, r.base_rent, r.max_occupants, r.status, p.name AS pname FROM rooms r JOIN properties p ON p.id = r.property_id
                      WHERE r.status <> 'MAINTENANCE' ORDER BY p.id, r.room_code");
    $tenantOpts = [];
    foreach (rows('SELECT id, full_name, phone FROM tenants ORDER BY full_name') as $t) $tenantOpts[$t['id']] = $t['full_name'] . ($t['phone'] ? ' · ' . $t['phone'] : '');
    $nextNo = 'HD-' . date('Y') . '-' . sprintf('%03d', (int)val('SELECT COUNT(*) FROM contracts') + 1);
} else {
    $c = row('SELECT c.*, r.room_code, r.max_occupants, r.base_rent, r.property_id, p.name AS pname
              FROM contracts c JOIN rooms r ON r.id = c.room_id JOIN properties p ON p.id = r.property_id WHERE c.id = ?', [$id]);
    if (!$c) { http_response_code(404); render_header('Không tìm thấy'); echo '<div class="alert alert-warning">Không tìm thấy hợp đồng.</div>'; render_footer(); exit; }
    $title = 'Hợp đồng ' . $c['contract_no'];
    $people = rows('SELECT ct.*, t.full_name, t.phone FROM contract_tenants ct JOIN tenants t ON t.id = ct.tenant_id WHERE ct.contract_id = ? ORDER BY ct.is_primary DESC, ct.move_in_date', [$id]);
    $svcs = rows('SELECT cs.*, s.name, s.billing_type, s.unit_name, s.unit_price FROM contract_services cs JOIN services s ON s.id = cs.service_id WHERE cs.contract_id = ? ORDER BY s.id', [$id]);
    $svcAvail = [];
    foreach (rows('SELECT id, name FROM services WHERE property_id = ? AND is_active = 1 AND id NOT IN (SELECT service_id FROM contract_services WHERE contract_id = ?) ORDER BY id', [$c['property_id'], $id]) as $s) $svcAvail[$s['id']] = $s['name'];
    $tenantOpts = [];
    foreach (rows('SELECT id, full_name, phone FROM tenants ORDER BY full_name') as $t) $tenantOpts[$t['id']] = $t['full_name'] . ($t['phone'] ? ' · ' . $t['phone'] : '');
    $invs = rows('SELECT id, invoice_no, period, due_date, total_amount, paid_amount, balance, status FROM invoices WHERE contract_id = ? ORDER BY period DESC', [$id]);
    $activeCount = 0; foreach ($people as $pp) if ($pp['move_out_date'] === null) $activeCount++;
}

render_header($title, 'contracts.php');

/* ======================= DANH SÁCH ======================= */
if ($mode === 'list'): ?>
<form class="row g-2 mb-3 no-print" method="get">
  <div class="col-md-3"><?= select('property_id', $props, $fp, '', 'Tất cả cơ sở') ?></div>
  <div class="col-md-3"><?= select('status', options('contract'), $fs, '', 'Tất cả trạng thái') ?></div>
  <div class="col-auto"><button class="btn btn-outline-primary">Lọc</button></div>
  <?php if (is_manager()): ?><div class="col-auto ms-auto"><a class="btn btn-primary" href="contracts.php?new=1"><i class="bi bi-plus-lg"></i> Tạo hợp đồng</a></div><?php endif; ?>
</form>
<div class="card"><div class="table-responsive"><table class="table table-hover mb-0">
<thead class="table-light"><tr><th>Số HĐ</th><th>Cơ sở / Phòng</th><th>Người đứng tên</th><th>Thời hạn</th><th class="num">Giá thuê</th><th class="num">Tiền cọc</th><th>Cọc</th><th>Trạng thái</th></tr></thead><tbody>
<?php foreach ($list as $r): ?>
<tr>
  <td><a class="fw-semibold" href="contracts.php?id=<?= (int)$r['id'] ?>"><?= e($r['contract_no']) ?></a></td>
  <td><?= e($r['pname']) ?> / <strong><?= e($r['room_code']) ?></strong></td><td><?= e($r['primary_tenant']) ?></td>
  <td><?= d($r['start_date']) ?> → <?= $r['end_date'] ? d($r['end_date']) : 'không thời hạn' ?>
    <?php if ($r['status'] === 'ACTIVE' && $r['days_left'] !== null && $r['days_left'] <= 60): ?><div class="small text-danger">còn <?= (int)$r['days_left'] ?> ngày</div><?php endif; ?></td>
  <td class="num"><?= vnd($r['monthly_rent']) ?></td><td class="num"><?= vnd($r['deposit_amount']) ?></td>
  <td><?= badge('deposit', $r['deposit_status']) ?></td><td><?= badge('contract', $r['status']) ?></td>
</tr>
<?php endforeach; if (!$list) echo '<tr><td colspan="8" class="text-center text-muted py-4">Không có hợp đồng nào.</td></tr>'; ?>
</tbody></table></div></div>

<?php /* ======================= TẠO MỚI ======================= */
elseif ($mode === 'new'): ?>
<form method="post" class="card"><div class="card-body row g-3">
  <?= csrf_field() ?><input type="hidden" name="do" value="create">
  <div class="col-md-3"><label class="form-label">Số hợp đồng *</label><input name="contract_no" class="form-control" required value="<?= e(old('contract_no', $nextNo)) ?>"></div>
  <div class="col-md-5"><label class="form-label">Phòng *</label>
    <select name="room_id" id="room" class="form-select" required><option value="">— Chọn phòng —</option>
    <?php foreach ($roomRows as $r): ?><option value="<?= (int)$r['id'] ?>" data-rent="<?= (int)$r['base_rent'] ?>" <?= (string)old('room_id') === (string)$r['id'] ? 'selected' : '' ?>>
      <?= e($r['pname'] . ' - ' . $r['room_code'] . ' (' . num($r['base_rent']) . ' đ, tối đa ' . $r['max_occupants'] . ' người)' . ($r['status'] === 'OCCUPIED' ? ' [đang có người thuê]' : '')) ?></option><?php endforeach; ?></select></div>
  <div class="col-md-4"><label class="form-label">Trạng thái hợp đồng</label><?= select('status', ['ACTIVE' => 'Có hiệu lực ngay', 'DRAFT' => 'Nháp (chưa hiệu lực)'], old('status', 'ACTIVE')) ?></div>
  <div class="col-md-4"><label class="form-label">Người đứng tên *</label><?= select('primary_tenant', $tenantOpts, old('primary_tenant'), 'required', '— Chọn khách —') ?>
    <div class="form-text">Chưa có khách? <a href="tenants.php?new=1">Thêm khách thuê</a> trước.</div></div>
  <div class="col-md-8"><label class="form-label">Người ở cùng (giữ Ctrl để chọn nhiều)</label>
    <select name="extra[]" class="form-select" multiple size="4"><?php foreach ($tenantOpts as $k => $v): ?><option value="<?= (int)$k ?>"><?= e($v) ?></option><?php endforeach; ?></select></div>
  <div class="col-md-3"><label class="form-label">Ngày bắt đầu *</label><input type="date" name="start_date" class="form-control" required value="<?= e(old('start_date', date('Y-m-d'))) ?>"></div>
  <div class="col-md-3"><label class="form-label">Ngày kết thúc</label><input type="date" name="end_date" class="form-control" value="<?= e(old('end_date')) ?>"><div class="form-text">Để trống = không thời hạn</div></div>
  <div class="col-md-3"><label class="form-label">Giá thuê (đ/tháng) *</label><input type="number" min="0" name="monthly_rent" id="rent" class="form-control" required value="<?= e(old('monthly_rent')) ?>"></div>
  <div class="col-md-3"><label class="form-label">Ngày ký</label><input type="date" name="signed_at" class="form-control" value="<?= e(old('signed_at', date('Y-m-d'))) ?>"></div>
  <div class="col-md-3"><label class="form-label">Tiền cọc (đ)</label><input type="number" min="0" name="deposit_amount" id="dep" class="form-control" value="<?= e(old('deposit_amount', 0)) ?>"></div>
  <div class="col-md-3"><label class="form-label">Tình trạng cọc</label><?= select('deposit_status', options('deposit'), old('deposit_status', 'HELD')) ?></div>
  <div class="col-md-3"><label class="form-label">Ngày nhận cọc</label><input type="date" name="deposit_paid_date" class="form-control" value="<?= e(old('deposit_paid_date', date('Y-m-d'))) ?>"></div>
  <div class="col-12"><label class="form-label">Ghi chú</label><textarea name="notes" class="form-control" rows="2"><?= e(old('notes')) ?></textarea></div>
  <div class="col-12"><button class="btn btn-success">Tạo hợp đồng</button> <a class="btn btn-outline-secondary" href="contracts.php">Hủy</a>
    <span class="text-muted small ms-2">Hệ thống tự gắn các dịch vụ đang áp dụng của cơ sở vào hợp đồng.</span></div>
</div></form>
<?php add_js("document.getElementById('room').addEventListener('change',function(){var o=this.options[this.selectedIndex];var r=document.getElementById('rent'),d=document.getElementById('dep');if(o&&o.dataset.rent){if(!r.value||r.dataset.auto==='1'){r.value=o.dataset.rent;r.dataset.auto='1';}if(!d.value||d.value==='0'||d.dataset.auto==='1'){d.value=o.dataset.rent;d.dataset.auto='1';}}});
document.getElementById('rent').addEventListener('input',function(){this.dataset.auto='0'});document.getElementById('dep').addEventListener('input',function(){this.dataset.auto='0'});"); ?>

<?php /* ======================= CHI TIẾT ======================= */
else: ?>
<div class="mb-3 d-flex flex-wrap gap-2 align-items-center">
  <a class="btn btn-sm btn-outline-secondary" href="contracts.php"><i class="bi bi-arrow-left"></i> Danh sách</a>
  <?= badge('contract', $c['status']) ?> <?= badge('deposit', $c['deposit_status']) ?>
  <?php if (is_manager() && $c['status'] === 'DRAFT'): ?>
    <form method="post" class="d-inline"><?= csrf_field() ?><input type="hidden" name="do" value="activate"><input type="hidden" name="id" value="<?= $id ?>"><button class="btn btn-sm btn-success">Kích hoạt hợp đồng</button></form>
    <form method="post" class="d-inline" <?= confirm_attr('Xóa hợp đồng nháp này?') ?>><?= csrf_field() ?><input type="hidden" name="do" value="delete"><input type="hidden" name="id" value="<?= $id ?>"><button class="btn btn-sm btn-outline-danger">Xóa nháp</button></form>
  <?php endif; ?>
</div>

<div class="row g-3">
  <div class="col-lg-6"><div class="card h-100"><div class="card-header">Thông tin hợp đồng</div><div class="card-body">
    <dl class="row mb-0">
      <dt class="col-5">Cơ sở / Phòng</dt><dd class="col-7"><?= e($c['pname']) ?> / <strong><?= e($c['room_code']) ?></strong></dd>
      <dt class="col-5">Thời hạn</dt><dd class="col-7"><?= d($c['start_date']) ?> → <?= $c['end_date'] ? d($c['end_date']) : 'không thời hạn' ?></dd>
      <dt class="col-5">Giá thuê</dt><dd class="col-7"><?= vnd($c['monthly_rent']) ?>/tháng <span class="text-muted small">(niêm yết <?= vnd($c['base_rent']) ?>)</span></dd>
      <dt class="col-5">Tiền cọc</dt><dd class="col-7"><?= vnd($c['deposit_amount']) ?> · nhận <?= d($c['deposit_paid_date']) ?></dd>
      <dt class="col-5">Ngày ký</dt><dd class="col-7"><?= d($c['signed_at']) ?></dd>
      <?php if ($c['terminated_at']): ?><dt class="col-5">Chấm dứt</dt><dd class="col-7"><?= d($c['terminated_at']) ?> — <?= e($c['termination_reason']) ?></dd><?php endif; ?>
      <?php if ($c['notes']): ?><dt class="col-5">Ghi chú</dt><dd class="col-7"><?= nl2br(e($c['notes'])) ?></dd><?php endif; ?>
    </dl></div></div></div>

  <?php if (is_manager() && $c['status'] !== 'TERMINATED' && $c['status'] !== 'EXPIRED'): ?>
  <div class="col-lg-6"><div class="card h-100"><div class="card-header">Cập nhật / Gia hạn</div><div class="card-body">
    <form method="post" class="row g-2">
      <?= csrf_field() ?><input type="hidden" name="do" value="update"><input type="hidden" name="id" value="<?= $id ?>">
      <div class="col-6"><label class="form-label small mb-0">Ngày kết thúc</label><input type="date" name="end_date" class="form-control form-control-sm" value="<?= e(old('end_date', $c['end_date'])) ?>"></div>
      <div class="col-6"><label class="form-label small mb-0">Giá thuê</label><input type="number" min="0" name="monthly_rent" class="form-control form-control-sm" value="<?= e(old('monthly_rent', $c['monthly_rent'])) ?>"></div>
      <div class="col-6"><label class="form-label small mb-0">Tiền cọc</label><input type="number" min="0" name="deposit_amount" class="form-control form-control-sm" value="<?= e(old('deposit_amount', $c['deposit_amount'])) ?>"></div>
      <div class="col-6"><label class="form-label small mb-0">Tình trạng cọc</label><?= select('deposit_status', options('deposit'), old('deposit_status', $c['deposit_status']), 'class="form-select-sm"') ?></div>
      <div class="col-6"><label class="form-label small mb-0">Ngày nhận cọc</label><input type="date" name="deposit_paid_date" class="form-control form-control-sm" value="<?= e(old('deposit_paid_date', $c['deposit_paid_date'])) ?>"></div>
      <div class="col-6"><label class="form-label small mb-0">Ngày ký</label><input type="date" name="signed_at" class="form-control form-control-sm" value="<?= e(old('signed_at', $c['signed_at'])) ?>"></div>
      <div class="col-12"><label class="form-label small mb-0">Ghi chú</label><input name="notes" class="form-control form-control-sm" value="<?= e(old('notes', $c['notes'])) ?>"></div>
      <div class="col-12"><button class="btn btn-sm btn-primary">Lưu thay đổi</button></div>
    </form></div></div></div>
  <?php endif; ?>

  <div class="col-12"><div class="card"><div class="card-header">Người ở (<?= $activeCount ?>/<?= (int)$c['max_occupants'] ?>)</div>
    <div class="table-responsive"><table class="table table-sm mb-0">
      <thead><tr><th>Họ tên</th><th>SĐT</th><th>Vai trò</th><th>Vào ở</th><th>Chuyển đi</th><?php if (is_manager()): ?><th></th><?php endif; ?></tr></thead><tbody>
      <?php foreach ($people as $pp): ?><tr>
        <td><?= e($pp['full_name']) ?></td><td><?= e($pp['phone']) ?></td>
        <td><?= $pp['is_primary'] ? '<span class="badge text-bg-primary">Đứng tên</span>' : 'Ở cùng' ?></td>
        <td><?= d($pp['move_in_date']) ?></td><td><?= d($pp['move_out_date']) ?></td>
        <?php if (is_manager()): ?><td class="text-end"><?php if (!$pp['is_primary'] && $pp['move_out_date'] === null): ?>
          <form method="post" class="d-inline" <?= confirm_attr('Ghi nhận ' . $pp['full_name'] . ' chuyển đi hôm nay?') ?>><?= csrf_field() ?><input type="hidden" name="do" value="move_out"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="tenant_id" value="<?= (int)$pp['tenant_id'] ?>"><button class="btn btn-sm btn-outline-secondary">Chuyển đi</button></form>
        <?php endif; ?></td><?php endif; ?></tr>
      <?php endforeach; ?></tbody></table></div>
    <?php if (is_manager() && $c['status'] !== 'TERMINATED' && $c['status'] !== 'EXPIRED'): ?>
    <div class="card-footer"><form method="post" class="row g-2 align-items-end">
      <?= csrf_field() ?><input type="hidden" name="do" value="add_tenant"><input type="hidden" name="id" value="<?= $id ?>">
      <div class="col-md-5"><label class="form-label small mb-0">Thêm người ở cùng</label><?= select('tenant_id', $tenantOpts, '', 'class="form-select-sm"', '— Chọn khách —') ?></div>
      <div class="col-md-3"><label class="form-label small mb-0">Ngày vào ở</label><input type="date" name="move_in_date" class="form-control form-control-sm" value="<?= date('Y-m-d') ?>"></div>
      <div class="col-auto"><button class="btn btn-sm btn-outline-primary">Thêm</button></div></form></div>
    <?php endif; ?></div></div>

  <div class="col-12"><div class="card"><div class="card-header">Dịch vụ áp dụng &amp; đơn giá riêng</div>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="save_services"><input type="hidden" name="id" value="<?= $id ?>">
    <div class="table-responsive"><table class="table table-sm mb-0">
      <thead><tr><th>Dịch vụ</th><th>Cách tính</th><th class="num">Giá chung</th><th style="width:200px">Giá riêng cho HĐ này</th><?php if (is_manager()): ?><th></th><?php endif; ?></tr></thead><tbody>
      <?php foreach ($svcs as $s): ?><tr>
        <td><?= e($s['name']) ?></td><td><?= badge('billing', $s['billing_type']) ?></td>
        <td class="num"><?= vnd($s['unit_price']) ?>/<?= e($s['unit_name']) ?></td>
        <td><?php if (is_manager()): ?><input type="number" min="0" class="form-control form-control-sm" name="custom[<?= (int)$s['service_id'] ?>]" placeholder="dùng giá chung" value="<?= e($s['custom_unit_price']) ?>">
            <?php else: echo $s['custom_unit_price'] !== null ? vnd($s['custom_unit_price']) : '—'; endif; ?></td>
        <?php if (is_manager()): ?><td class="text-end"><button type="submit" form="del<?= (int)$s['service_id'] ?>" class="btn btn-sm btn-outline-danger" title="Gỡ khỏi hợp đồng"><i class="bi bi-x-lg"></i></button></td><?php endif; ?>
      </tr><?php endforeach; if (!$svcs) echo '<tr><td colspan="5" class="text-muted text-center py-3">Chưa có dịch vụ.</td></tr>'; ?></tbody></table></div>
    <?php if (is_manager() && $svcs): ?><div class="card-footer"><button class="btn btn-sm btn-primary">Lưu đơn giá riêng</button></div><?php endif; ?></form>
    <?php if (is_manager()): foreach ($svcs as $s): ?>
      <form method="post" id="del<?= (int)$s['service_id'] ?>"><?= csrf_field() ?><input type="hidden" name="do" value="del_service"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="service_id" value="<?= (int)$s['service_id'] ?>"></form>
    <?php endforeach; if ($svcAvail): ?>
      <div class="card-footer"><form method="post" class="row g-2 align-items-end"><?= csrf_field() ?><input type="hidden" name="do" value="add_service"><input type="hidden" name="id" value="<?= $id ?>">
        <div class="col-md-4"><label class="form-label small mb-0">Thêm dịch vụ</label><?= select('service_id', $svcAvail, '', 'class="form-select-sm"') ?></div>
        <div class="col-auto"><button class="btn btn-sm btn-outline-primary">Thêm</button></div></form></div>
    <?php endif; endif; ?></div></div>

  <div class="col-12"><div class="card"><div class="card-header">Hóa đơn của hợp đồng</div>
    <div class="table-responsive"><table class="table table-sm mb-0">
      <thead><tr><th>Số HĐ</th><th>Kỳ</th><th>Hạn TT</th><th class="num">Tổng</th><th class="num">Đã trả</th><th class="num">Còn nợ</th><th>Trạng thái</th></tr></thead><tbody>
      <?php foreach ($invs as $i): ?><tr>
        <td><a href="invoice.php?id=<?= (int)$i['id'] ?>"><?= e($i['invoice_no']) ?></a></td><td><?= per($i['period']) ?></td><td><?= d($i['due_date']) ?></td>
        <td class="num"><?= vnd($i['total_amount']) ?></td><td class="num"><?= vnd($i['paid_amount']) ?></td><td class="num"><?= vnd($i['balance']) ?></td><td><?= badge('invoice', $i['status']) ?></td></tr>
      <?php endforeach; if (!$invs) echo '<tr><td colspan="7" class="text-muted text-center py-3">Chưa có hóa đơn.</td></tr>'; ?></tbody></table></div></div></div>

  <?php if (is_manager() && $c['status'] === 'ACTIVE'): ?>
  <div class="col-12"><div class="card border-danger"><div class="card-header text-danger">Chấm dứt hợp đồng</div><div class="card-body">
    <form method="post" class="row g-2 align-items-end" <?= confirm_attr('Chấm dứt hợp đồng này? Phòng sẽ trở về trạng thái trống.') ?>>
      <?= csrf_field() ?><input type="hidden" name="do" value="terminate"><input type="hidden" name="id" value="<?= $id ?>">
      <div class="col-md-3"><label class="form-label small mb-0">Ngày chấm dứt</label><input type="date" name="terminated_at" class="form-control form-control-sm" value="<?= date('Y-m-d') ?>"></div>
      <div class="col-md-4"><label class="form-label small mb-0">Lý do</label><input name="reason" class="form-control form-control-sm"></div>
      <div class="col-md-3"><label class="form-label small mb-0">Xử lý tiền cọc</label><?= select('deposit_status', ['REFUNDED' => 'Hoàn cọc', 'FORFEITED' => 'Tịch thu cọc', 'HELD' => 'Tiếp tục giữ'], 'REFUNDED', 'class="form-select-sm"') ?></div>
      <div class="col-auto"><button class="btn btn-sm btn-danger">Chấm dứt</button></div>
    </form></div></div></div>
  <?php endif; ?>
</div>
<?php endif;
render_footer();
