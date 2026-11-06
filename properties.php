<?php
require __DIR__ . '/inc/bootstrap.php';
require_manager();

if (is_post() && post('do') === 'save') {
    $id = (int)post('id');
    run(function () use ($id) {
        if (post('name') === '' || post('address') === '') throw new Exception('Vui lòng nhập tên và địa chỉ cơ sở.');
        $f = [post('name'), post('property_type'), post('address'), nul(post('ward')), nul(post('district')), nul(post('city')),
              (int)post('payment_due_days'), post('is_active') ? 1 : 0];
        if ($id) {
            q('UPDATE properties SET name=?, property_type=?, address=?, ward=?, district=?, city=?, payment_due_days=?, is_active=? WHERE id=?',
              array_merge($f, [$id]));
        } else {
            q('INSERT INTO properties (name, property_type, address, ward, district, city, payment_due_days, is_active, owner_id) VALUES (?,?,?,?,?,?,?,?,?)',
              array_merge($f, [(int)post('owner_id')]));
        }
    }, 'Đã lưu cơ sở.', 'properties.php');
}

$edit = g('edit') !== '' ? row('SELECT * FROM properties WHERE id = ?', [(int)g('edit')]) : null;
$showForm = isset($_GET['new']) || $edit || is_post();
$owners = [];
foreach (rows("SELECT id, full_name FROM users WHERE role IN ('OWNER','MANAGER') AND is_active = 1 ORDER BY id") as $o) $owners[$o['id']] = $o['full_name'];

$list = rows('SELECT p.*, u.full_name AS owner_name,
    (SELECT COUNT(*) FROM rooms r WHERE r.property_id = p.id) AS room_count,
    (SELECT COUNT(*) FROM rooms r WHERE r.property_id = p.id AND r.status = "OCCUPIED") AS occupied
  FROM properties p JOIN users u ON u.id = p.owner_id ORDER BY p.id');

render_header('Cơ sở (nhà trọ / chung cư)');
?>
<div class="mb-3"><a class="btn btn-primary" href="properties.php?new=1"><i class="bi bi-plus-lg"></i> Thêm cơ sở</a></div>

<?php if ($showForm): ?>
<div class="card mb-4"><div class="card-header"><?= $edit ? 'Sửa cơ sở' : 'Thêm cơ sở' ?></div>
<div class="card-body">
<form method="post" class="row g-3">
  <?= csrf_field() ?><input type="hidden" name="do" value="save"><input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
  <div class="col-md-6"><label class="form-label">Tên cơ sở *</label>
    <input name="name" class="form-control" required value="<?= e(old('name', $edit['name'] ?? '')) ?>"></div>
  <div class="col-md-3"><label class="form-label">Loại hình</label>
    <?= select('property_type', options('ptype'), old('property_type', $edit['property_type'] ?? 'BOARDING_HOUSE')) ?></div>
  <div class="col-md-3"><label class="form-label">Hạn thanh toán (ngày sau phát hành)</label>
    <input type="number" min="0" max="60" name="payment_due_days" class="form-control" value="<?= e(old('payment_due_days', $edit['payment_due_days'] ?? 5)) ?>"></div>
  <div class="col-12"><label class="form-label">Địa chỉ *</label>
    <input name="address" class="form-control" required value="<?= e(old('address', $edit['address'] ?? '')) ?>"></div>
  <div class="col-md-4"><label class="form-label">Phường/Xã</label><input name="ward" class="form-control" value="<?= e(old('ward', $edit['ward'] ?? '')) ?>"></div>
  <div class="col-md-4"><label class="form-label">Quận/Huyện</label><input name="district" class="form-control" value="<?= e(old('district', $edit['district'] ?? '')) ?>"></div>
  <div class="col-md-4"><label class="form-label">Tỉnh/Thành phố</label><input name="city" class="form-control" value="<?= e(old('city', $edit['city'] ?? '')) ?>"></div>
  <?php if (!$edit): ?>
  <div class="col-md-4"><label class="form-label">Chủ sở hữu</label><?= select('owner_id', $owners, old('owner_id', user()['id'])) ?></div>
  <?php endif; ?>
  <div class="col-md-4 d-flex align-items-end"><div class="form-check">
    <input type="checkbox" class="form-check-input" name="is_active" value="1" id="act" <?= (is_post() ? post('is_active') : ($edit['is_active'] ?? 1)) ? 'checked' : '' ?>>
    <label for="act" class="form-check-label">Đang hoạt động</label></div></div>
  <div class="col-12"><button class="btn btn-success">Lưu</button> <a class="btn btn-outline-secondary" href="properties.php">Hủy</a></div>
</form></div></div>
<?php endif; ?>

<div class="card"><div class="table-responsive"><table class="table table-hover mb-0">
  <thead class="table-light"><tr><th>Tên</th><th>Loại</th><th>Địa chỉ</th><th class="num">Phòng (thuê/tổng)</th><th class="num">Hạn TT</th><th>Chủ</th><th>Trạng thái</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($list as $p): ?>
    <tr>
      <td class="fw-semibold"><?= e($p['name']) ?></td>
      <td><?= badge('ptype', $p['property_type']) ?></td>
      <td><?= e(implode(', ', array_filter([$p['address'], $p['ward'], $p['district'], $p['city']]))) ?></td>
      <td class="num"><?= (int)$p['occupied'] ?>/<?= (int)$p['room_count'] ?></td>
      <td class="num"><?= (int)$p['payment_due_days'] ?> ngày</td>
      <td><?= e($p['owner_name']) ?></td>
      <td><?= $p['is_active'] ? '<span class="badge text-bg-success">Hoạt động</span>' : '<span class="badge text-bg-secondary">Ngưng</span>' ?></td>
      <td class="text-end"><a class="btn btn-sm btn-outline-primary" href="properties.php?edit=<?= (int)$p['id'] ?>">Sửa</a></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table></div></div>
<?php render_footer();
