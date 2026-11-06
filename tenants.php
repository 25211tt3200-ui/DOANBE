<?php
require __DIR__ . '/inc/bootstrap.php';
require_login();

if (is_post()) {
    if (!is_manager()) deny();
    $do = post('do');
    if ($do === 'save') {
        $id = (int)post('id');
        run(function () use ($id) {
            if (post('full_name') === '') throw new Exception('Vui lòng nhập họ tên.');
            if (nul(post('phone')) === null && nul(post('email')) === null && nul(post('zalo_id')) === null)
                throw new Exception('Cần ít nhất một thông tin liên lạc: SĐT, email hoặc Zalo.');
            $f = [post('full_name'), nul(post('gender')), nul(post('date_of_birth')), nul(post('phone')), nul(post('email')), nul(post('zalo_id')),
                  nul(post('national_id')), nul(post('permanent_address')), nul(post('emergency_contact_name')), nul(post('emergency_contact_phone')),
                  post('preferred_channel'), nul(post('notes'))];
            if ($id) {
                q('UPDATE tenants SET full_name=?, gender=?, date_of_birth=?, phone=?, email=?, zalo_id=?, national_id=?, permanent_address=?, emergency_contact_name=?, emergency_contact_phone=?, preferred_channel=?, notes=? WHERE id=?',
                  array_merge($f, [$id]));
            } else {
                q('INSERT INTO tenants (full_name, gender, date_of_birth, phone, email, zalo_id, national_id, permanent_address, emergency_contact_name, emergency_contact_phone, preferred_channel, notes) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)', $f);
            }
        }, 'Đã lưu khách thuê.', 'tenants.php');
    } elseif ($do === 'delete') {
        run(function () { q('DELETE FROM tenants WHERE id = ?', [(int)post('id')]); }, 'Đã xóa khách thuê.', 'tenants.php');
    }
}

$search = g('q');
$where = ''; $par = [];
if ($search !== '') {
    $where = 'WHERE t.full_name LIKE ? OR t.phone LIKE ? OR t.national_id LIKE ? OR t.email LIKE ?';
    $like = '%' . $search . '%';
    $par = [$like, $like, $like, $like];
}
$list = rows("SELECT t.*, (SELECT GROUP_CONCAT(r.room_code ORDER BY r.room_code SEPARATOR ', ')
      FROM contract_tenants ct JOIN contracts c ON c.id = ct.contract_id AND c.status = 'ACTIVE'
      JOIN rooms r ON r.id = c.room_id WHERE ct.tenant_id = t.id AND ct.move_out_date IS NULL) AS rooms
    FROM tenants t $where ORDER BY t.full_name LIMIT 300", $par);

$edit = g('edit') !== '' ? row('SELECT * FROM tenants WHERE id = ?', [(int)g('edit')]) : null;
$showForm = is_manager() && (isset($_GET['new']) || $edit || is_post());
$history = $edit ? rows('SELECT c.id, c.contract_no, c.status, c.start_date, c.end_date, r.room_code, p.name AS pname, ct.is_primary
    FROM contract_tenants ct JOIN contracts c ON c.id = ct.contract_id JOIN rooms r ON r.id = c.room_id JOIN properties p ON p.id = r.property_id
    WHERE ct.tenant_id = ? ORDER BY c.start_date DESC', [$edit['id']]) : [];

render_header('Khách thuê');
?>
<form class="row g-2 mb-3 no-print" method="get">
  <div class="col-md-4"><input name="q" class="form-control" placeholder="Tìm theo tên, SĐT, CCCD, email" value="<?= e($search) ?>"></div>
  <div class="col-auto"><button class="btn btn-outline-primary">Tìm</button></div>
  <?php if (is_manager()): ?><div class="col-auto ms-auto"><a class="btn btn-primary" href="tenants.php?new=1"><i class="bi bi-plus-lg"></i> Thêm khách</a></div><?php endif; ?>
</form>

<?php if ($showForm): ?>
<div class="card mb-4"><div class="card-header"><?= $edit ? 'Sửa khách thuê' : 'Thêm khách thuê' ?></div><div class="card-body">
<form method="post" class="row g-3">
  <?= csrf_field() ?><input type="hidden" name="do" value="save"><input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
  <div class="col-md-4"><label class="form-label">Họ tên *</label><input name="full_name" class="form-control" required value="<?= e(old('full_name', $edit['full_name'] ?? '')) ?>"></div>
  <div class="col-md-2"><label class="form-label">Giới tính</label><?= select('gender', options('gender'), old('gender', $edit['gender'] ?? ''), '', '—') ?></div>
  <div class="col-md-3"><label class="form-label">Ngày sinh</label><input type="date" name="date_of_birth" class="form-control" value="<?= e(old('date_of_birth', $edit['date_of_birth'] ?? '')) ?>"></div>
  <div class="col-md-3"><label class="form-label">CCCD/CMND</label><input name="national_id" class="form-control" value="<?= e(old('national_id', $edit['national_id'] ?? '')) ?>"></div>
  <div class="col-md-3"><label class="form-label">SĐT</label><input name="phone" class="form-control" value="<?= e(old('phone', $edit['phone'] ?? '')) ?>"></div>
  <div class="col-md-3"><label class="form-label">Email</label><input type="email" name="email" class="form-control" value="<?= e(old('email', $edit['email'] ?? '')) ?>"></div>
  <div class="col-md-3"><label class="form-label">Zalo (ID/SĐT)</label><input name="zalo_id" class="form-control" value="<?= e(old('zalo_id', $edit['zalo_id'] ?? '')) ?>"></div>
  <div class="col-md-3"><label class="form-label">Kênh nhận thông báo</label><?= select('preferred_channel', options('channel'), old('preferred_channel', $edit['preferred_channel'] ?? 'ZALO')) ?></div>
  <div class="col-12"><label class="form-label">Địa chỉ thường trú</label><input name="permanent_address" class="form-control" value="<?= e(old('permanent_address', $edit['permanent_address'] ?? '')) ?>"></div>
  <div class="col-md-6"><label class="form-label">Người liên hệ khẩn cấp</label><input name="emergency_contact_name" class="form-control" value="<?= e(old('emergency_contact_name', $edit['emergency_contact_name'] ?? '')) ?>"></div>
  <div class="col-md-6"><label class="form-label">SĐT khẩn cấp</label><input name="emergency_contact_phone" class="form-control" value="<?= e(old('emergency_contact_phone', $edit['emergency_contact_phone'] ?? '')) ?>"></div>
  <div class="col-12"><label class="form-label">Ghi chú</label><textarea name="notes" class="form-control" rows="2"><?= e(old('notes', $edit['notes'] ?? '')) ?></textarea></div>
  <div class="col-12"><button class="btn btn-success">Lưu</button> <a class="btn btn-outline-secondary" href="tenants.php">Hủy</a></div>
</form>
<?php if ($history): ?>
  <hr><h6>Lịch sử thuê</h6>
  <table class="table table-sm"><thead><tr><th>Hợp đồng</th><th>Cơ sở / Phòng</th><th>Thời hạn</th><th>Vai trò</th><th>Trạng thái</th></tr></thead><tbody>
  <?php foreach ($history as $h): ?><tr>
    <td><a href="contracts.php?id=<?= (int)$h['id'] ?>"><?= e($h['contract_no']) ?></a></td>
    <td><?= e($h['pname']) ?> / <?= e($h['room_code']) ?></td>
    <td><?= d($h['start_date']) ?> → <?= d($h['end_date']) ?></td>
    <td><?= $h['is_primary'] ? 'Đứng tên' : 'Ở cùng' ?></td><td><?= badge('contract', $h['status']) ?></td></tr>
  <?php endforeach; ?></tbody></table>
<?php endif; ?>
</div></div>
<?php endif; ?>

<div class="card"><div class="table-responsive"><table class="table table-hover mb-0">
<thead class="table-light"><tr><th>Họ tên</th><th>SĐT</th><th>Email</th><th>Zalo</th><th>Kênh</th><th>CCCD</th><th>Đang ở phòng</th><?php if (is_manager()): ?><th></th><?php endif; ?></tr></thead>
<tbody>
<?php foreach ($list as $t): ?>
<tr>
  <td class="fw-semibold"><?= e($t['full_name']) ?></td><td><?= e($t['phone']) ?></td><td><?= e($t['email']) ?></td><td><?= e($t['zalo_id']) ?></td>
  <td><?= badge('channel', $t['preferred_channel']) ?></td><td><?= e($t['national_id']) ?></td>
  <td><?= $t['rooms'] ? '<span class="badge text-bg-primary">' . e($t['rooms']) . '</span>' : '<span class="text-muted">—</span>' ?></td>
  <?php if (is_manager()): ?><td class="text-end text-nowrap">
    <a class="btn btn-sm btn-outline-primary" href="tenants.php?edit=<?= (int)$t['id'] ?>">Sửa</a>
    <form method="post" class="d-inline" <?= confirm_attr('Xóa khách ' . $t['full_name'] . '?') ?>>
      <?= csrf_field() ?><input type="hidden" name="do" value="delete"><input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
      <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button></form></td><?php endif; ?>
</tr>
<?php endforeach; if (!$list) echo '<tr><td colspan="8" class="text-center text-muted py-4">Không có khách thuê nào.</td></tr>'; ?>
</tbody></table></div></div>
<?php render_footer();
