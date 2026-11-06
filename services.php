<?php
require __DIR__ . '/inc/bootstrap.php';
require_manager();

$props = [];
foreach (rows('SELECT id, name FROM properties ORDER BY id') as $p) $props[$p['id']] = $p['name'];
$pid = (int)g('property_id', (string)(array_key_first($props) ?? 0));
$self = 'services.php?property_id=' . $pid;

if (is_post()) {
    $do = post('do');
    if ($do === 'save') {
        $id = (int)post('id');
        run(function () use ($id, $pid) {
            if (post('code') === '' || post('name') === '' || post('unit_name') === '') throw new Exception('Vui lòng nhập mã, tên và đơn vị tính.');
            if ($id) {
                q('UPDATE services SET code=?, name=?, billing_type=?, unit_name=?, unit_price=?, is_active=? WHERE id=?',
                  [strtoupper(post('code')), post('name'), post('billing_type'), post('unit_name'), post('unit_price'), post('is_active') ? 1 : 0, $id]);
            } else {
                q('INSERT INTO services (property_id, code, name, billing_type, unit_name, unit_price, is_active) VALUES (?,?,?,?,?,?,?)',
                  [$pid, strtoupper(post('code')), post('name'), post('billing_type'), post('unit_name'), post('unit_price'), post('is_active') ? 1 : 0]);
            }
        }, 'Đã lưu dịch vụ.', $self);
    } elseif ($do === 'apply') {
        run(function () use ($pid) {
            $n = q("INSERT IGNORE INTO contract_services (contract_id, service_id)
                    SELECT c.id, ? FROM contracts c JOIN rooms r ON r.id = c.room_id
                    WHERE r.property_id = ? AND c.status = 'ACTIVE'", [(int)post('id'), $pid])->rowCount();
            flash('info', "Đã gắn dịch vụ vào $n hợp đồng đang hiệu lực chưa có dịch vụ này.");
        }, 'Hoàn tất.', $self);
    } elseif ($do === 'delete') {
        run(function () { q('DELETE FROM services WHERE id = ?', [(int)post('id')]); }, 'Đã xóa dịch vụ.', $self);
    }
}

$edit = g('edit') !== '' ? row('SELECT * FROM services WHERE id = ?', [(int)g('edit')]) : null;
$showForm = isset($_GET['new']) || $edit || is_post();
$list = $pid ? rows('SELECT s.*, (SELECT COUNT(*) FROM contract_services cs JOIN contracts c ON c.id = cs.contract_id AND c.status = "ACTIVE" WHERE cs.service_id = s.id) AS used
                     FROM services s WHERE s.property_id = ? ORDER BY s.id', [$pid]) : [];

render_header('Dịch vụ & đơn giá');
?>
<form class="row g-2 mb-3 no-print" method="get">
  <div class="col-md-4"><?= select('property_id', $props, $pid, 'onchange="this.form.submit()"') ?></div>
  <div class="col-auto ms-auto"><a class="btn btn-primary" href="<?= e($self) ?>&new=1"><i class="bi bi-plus-lg"></i> Thêm dịch vụ</a></div>
</form>
<div class="alert alert-info py-2 small">Đổi đơn giá chỉ ảnh hưởng hóa đơn lập sau đó; hóa đơn cũ giữ nguyên đơn giá đã chốt. Dịch vụ mới thêm cần bấm “Gắn vào HĐ đang thuê” để áp dụng cho hợp đồng hiện có.</div>

<?php if ($showForm && $pid): ?>
<div class="card mb-4"><div class="card-header"><?= $edit ? 'Sửa dịch vụ' : 'Thêm dịch vụ cho ' . e($props[$pid] ?? '') ?></div><div class="card-body">
<form method="post" class="row g-3">
  <?= csrf_field() ?><input type="hidden" name="do" value="save"><input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
  <div class="col-md-2"><label class="form-label">Mã *</label><input name="code" class="form-control" required value="<?= e(old('code', $edit['code'] ?? '')) ?>"></div>
  <div class="col-md-3"><label class="form-label">Tên *</label><input name="name" class="form-control" required value="<?= e(old('name', $edit['name'] ?? '')) ?>"></div>
  <div class="col-md-3"><label class="form-label">Cách tính</label><?= select('billing_type', options('billing'), old('billing_type', $edit['billing_type'] ?? 'METERED')) ?></div>
  <div class="col-md-2"><label class="form-label">Đơn vị *</label><input name="unit_name" class="form-control" required placeholder="kWh, m3, tháng..." value="<?= e(old('unit_name', $edit['unit_name'] ?? '')) ?>"></div>
  <div class="col-md-2"><label class="form-label">Đơn giá (đ) *</label><input type="number" min="0" name="unit_price" class="form-control" required value="<?= e(old('unit_price', $edit['unit_price'] ?? '')) ?>"></div>
  <div class="col-md-3"><div class="form-check mt-2"><input type="checkbox" class="form-check-input" id="ac" name="is_active" value="1" <?= (is_post() ? post('is_active') : ($edit['is_active'] ?? 1)) ? 'checked' : '' ?>><label for="ac" class="form-check-label">Đang áp dụng</label></div></div>
  <div class="col-12"><button class="btn btn-success">Lưu</button> <a class="btn btn-outline-secondary" href="<?= e($self) ?>">Hủy</a></div>
</form></div></div>
<?php endif; ?>

<div class="card"><div class="table-responsive"><table class="table table-hover mb-0">
<thead class="table-light"><tr><th>Mã</th><th>Tên</th><th>Cách tính</th><th class="num">Đơn giá</th><th>Đơn vị</th><th class="num">HĐ đang dùng</th><th>Trạng thái</th><th></th></tr></thead><tbody>
<?php foreach ($list as $s): ?>
<tr>
  <td><code><?= e($s['code']) ?></code></td><td class="fw-semibold"><?= e($s['name']) ?></td><td><?= badge('billing', $s['billing_type']) ?></td>
  <td class="num"><?= vnd($s['unit_price']) ?></td><td><?= e($s['unit_name']) ?></td><td class="num"><?= (int)$s['used'] ?></td>
  <td><?= $s['is_active'] ? '<span class="badge text-bg-success">Áp dụng</span>' : '<span class="badge text-bg-secondary">Ngưng</span>' ?></td>
  <td class="text-end text-nowrap">
    <a class="btn btn-sm btn-outline-primary" href="<?= e($self) ?>&edit=<?= (int)$s['id'] ?>">Sửa</a>
    <form method="post" class="d-inline"><?= csrf_field() ?><input type="hidden" name="do" value="apply"><input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
      <button class="btn btn-sm btn-outline-secondary" title="Thêm dịch vụ này vào mọi hợp đồng đang hiệu lực của cơ sở">Gắn vào HĐ đang thuê</button></form>
    <form method="post" class="d-inline" <?= confirm_attr('Xóa dịch vụ ' . $s['name'] . '?') ?>><?= csrf_field() ?><input type="hidden" name="do" value="delete"><input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
      <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button></form>
  </td>
</tr>
<?php endforeach; if (!$list) echo '<tr><td colspan="8" class="text-center text-muted py-4">Cơ sở này chưa có dịch vụ.</td></tr>'; ?>
</tbody></table></div></div>
<?php render_footer();
