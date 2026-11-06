<?php
require __DIR__ . '/inc/bootstrap.php';
require_login();

if (is_post()) {
    if (!is_manager()) deny();
    $do = post('do');
    if ($do === 'save') {
        $id = (int)post('id');
        run(function () use ($id) {
            if (post('room_code') === '' || post('base_rent') === '') throw new Exception('Vui lòng nhập mã phòng và giá thuê.');
            $amen = array_values(array_filter(array_map('trim', explode(',', (string)post('amenities')))));
            $amenJson = json_encode($amen, JSON_UNESCAPED_UNICODE);
            if ($id) {
                $cur = row('SELECT status FROM rooms WHERE id = ?', [$id]);
                $status = ($cur && $cur['status'] === 'OCCUPIED') ? 'OCCUPIED' : post('status');
                q('UPDATE rooms SET room_code=?, floor_no=?, area_m2=?, base_rent=?, max_occupants=?, status=?, amenities=?, description=? WHERE id=?',
                  [post('room_code'), nul(post('floor_no')), nul(post('area_m2')), post('base_rent'), (int)post('max_occupants'), $status, $amenJson, nul(post('description')), $id]);
            } else {
                $status = post('status') === 'OCCUPIED' ? 'AVAILABLE' : post('status');
                q('INSERT INTO rooms (property_id, room_code, floor_no, area_m2, base_rent, max_occupants, status, amenities, description) VALUES (?,?,?,?,?,?,?,?,?)',
                  [(int)post('property_id'), post('room_code'), nul(post('floor_no')), nul(post('area_m2')), post('base_rent'), (int)post('max_occupants'), $status, $amenJson, nul(post('description'))]);
            }
        }, 'Đã lưu phòng.', 'rooms.php');
    } elseif ($do === 'delete') {
        run(function () { q('DELETE FROM rooms WHERE id = ?', [(int)post('id')]); }, 'Đã xóa phòng.', 'rooms.php');
    }
}

$props = [];
foreach (rows('SELECT id, name FROM properties ORDER BY id') as $p) $props[$p['id']] = $p['name'];
$fp = g('property_id'); $fs = g('status');
$where = []; $par = [];
if ($fp !== '') { $where[] = 'property_id = ?'; $par[] = (int)$fp; }
if ($fs !== '') { $where[] = 'room_status = ?'; $par[] = $fs; }
$list = rows('SELECT * FROM v_room_overview' . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY property_id, room_code', $par);

$edit = g('edit') !== '' ? row('SELECT * FROM rooms WHERE id = ?', [(int)g('edit')]) : null;
$showForm = is_manager() && (isset($_GET['new']) || $edit || is_post());
$editAmen = $edit && $edit['amenities'] ? implode(', ', (array)json_decode($edit['amenities'], true)) : '';

render_header('Phòng');
?>
<form class="row g-2 mb-3 no-print" method="get">
  <div class="col-md-3"><?= select('property_id', $props, $fp, '', 'Tất cả cơ sở') ?></div>
  <div class="col-md-3"><?= select('status', options('room'), $fs, '', 'Tất cả trạng thái') ?></div>
  <div class="col-auto"><button class="btn btn-outline-primary">Lọc</button></div>
  <?php if (is_manager()): ?><div class="col-auto ms-auto"><a class="btn btn-primary" href="rooms.php?new=1"><i class="bi bi-plus-lg"></i> Thêm phòng</a></div><?php endif; ?>
</form>

<?php if ($showForm): ?>
<div class="card mb-4"><div class="card-header"><?= $edit ? 'Sửa phòng ' . e($edit['room_code']) : 'Thêm phòng' ?></div><div class="card-body">
<form method="post" class="row g-3">
  <?= csrf_field() ?><input type="hidden" name="do" value="save"><input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
  <div class="col-md-4"><label class="form-label">Cơ sở *</label>
    <?php if ($edit): ?><input type="hidden" name="property_id" value="<?= (int)$edit['property_id'] ?>"><input class="form-control" disabled value="<?= e($props[$edit['property_id']] ?? '') ?>">
    <?php else: echo select('property_id', $props, old('property_id', $fp), 'required'); endif; ?></div>
  <div class="col-md-2"><label class="form-label">Mã phòng *</label><input name="room_code" class="form-control" required value="<?= e(old('room_code', $edit['room_code'] ?? '')) ?>"></div>
  <div class="col-md-2"><label class="form-label">Tầng</label><input type="number" name="floor_no" class="form-control" value="<?= e(old('floor_no', $edit['floor_no'] ?? '')) ?>"></div>
  <div class="col-md-2"><label class="form-label">Diện tích (m²)</label><input type="number" step="0.01" min="0" name="area_m2" class="form-control" value="<?= e(old('area_m2', $edit['area_m2'] ?? '')) ?>"></div>
  <div class="col-md-2"><label class="form-label">Số người tối đa</label><input type="number" min="1" name="max_occupants" class="form-control" value="<?= e(old('max_occupants', $edit['max_occupants'] ?? 2)) ?>"></div>
  <div class="col-md-3"><label class="form-label">Giá niêm yết (đ/tháng) *</label><input type="number" min="0" name="base_rent" class="form-control" required value="<?= e(old('base_rent', $edit['base_rent'] ?? '')) ?>"></div>
  <div class="col-md-3"><label class="form-label">Trạng thái</label>
    <?php if ($edit && $edit['status'] === 'OCCUPIED'): ?>
      <input class="form-control" disabled value="Đang thuê (đổi bằng hợp đồng)"><input type="hidden" name="status" value="OCCUPIED">
    <?php else: echo select('status', ['AVAILABLE' => 'Trống', 'RESERVED' => 'Đã đặt cọc', 'MAINTENANCE' => 'Bảo trì'], old('status', $edit['status'] ?? 'AVAILABLE')); endif; ?></div>
  <div class="col-md-6"><label class="form-label">Tiện nghi (cách nhau bằng dấu phẩy)</label>
    <input name="amenities" class="form-control" placeholder="Máy lạnh, Ban công, ..." value="<?= e(old('amenities', $editAmen)) ?>"></div>
  <div class="col-12"><label class="form-label">Mô tả</label><textarea name="description" class="form-control" rows="2"><?= e(old('description', $edit['description'] ?? '')) ?></textarea></div>
  <div class="col-12"><button class="btn btn-success">Lưu</button> <a class="btn btn-outline-secondary" href="rooms.php">Hủy</a></div>
</form></div></div>
<?php endif; ?>

<div class="card"><div class="table-responsive"><table class="table table-hover mb-0">
<thead class="table-light"><tr><th>Cơ sở</th><th>Phòng</th><th class="num">Tầng</th><th class="num">m²</th><th class="num">Giá niêm yết</th><th>Trạng thái</th><th>Khách đứng tên</th><th>SĐT</th><th>Hợp đồng</th><th class="num">Người ở</th><?php if (is_manager()): ?><th></th><?php endif; ?></tr></thead>
<tbody>
<?php foreach ($list as $r): ?>
<tr>
  <td><?= e($r['property_name']) ?></td>
  <td class="fw-semibold"><?= e($r['room_code']) ?></td>
  <td class="num"><?= e($r['floor_no']) ?></td>
  <td class="num"><?= e($r['area_m2'] !== null ? num($r['area_m2'], 0) : '') ?></td>
  <td class="num"><?= vnd($r['base_rent']) ?></td>
  <td><?= badge('room', $r['room_status']) ?></td>
  <td><?= e($r['primary_tenant']) ?></td>
  <td><?= e($r['tenant_phone']) ?></td>
  <td><?php if ($r['contract_id']): ?><a href="contracts.php?id=<?= (int)$r['contract_id'] ?>"><?= e($r['contract_no']) ?></a>
      <div class="small <?= ($r['days_to_expiry'] !== null && $r['days_to_expiry'] <= 60) ? 'text-danger' : 'text-muted' ?>">
        đến <?= d($r['end_date']) ?><?= $r['days_to_expiry'] !== null ? ' (' . (int)$r['days_to_expiry'] . ' ngày)' : '' ?></div><?php endif; ?></td>
  <td class="num"><?= $r['contract_id'] ? (int)$r['occupant_count'] : '' ?></td>
  <?php if (is_manager()): ?>
  <td class="text-end text-nowrap">
    <a class="btn btn-sm btn-outline-primary" href="rooms.php?edit=<?= (int)$r['room_id'] ?>">Sửa</a>
    <?php if (!$r['contract_id']): ?>
    <form method="post" class="d-inline" <?= confirm_attr('Xóa phòng ' . $r['room_code'] . '?') ?>>
      <?= csrf_field() ?><input type="hidden" name="do" value="delete"><input type="hidden" name="id" value="<?= (int)$r['room_id'] ?>">
      <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button></form>
    <?php endif; ?>
  </td>
  <?php endif; ?>
</tr>
<?php endforeach; if (!$list) echo '<tr><td colspan="11" class="text-center text-muted py-4">Chưa có phòng nào.</td></tr>'; ?>
</tbody></table></div></div>
<?php render_footer();
