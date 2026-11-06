<?php
require __DIR__ . '/inc/bootstrap.php';
require_owner();

if (is_post() && post('do') === 'save') {
    $id = (int)post('id');
    run(function () use ($id) {
        if (post('full_name') === '' || post('email') === '') throw new Exception('Vui lòng nhập họ tên và email.');
        $pw = (string)post('password');
        if ($id) {
            if ($id === user()['id'] && (post('role') !== 'OWNER' || !post('is_active'))) throw new Exception('Bạn không thể tự hạ quyền hoặc khóa chính mình.');
            q('UPDATE users SET full_name=?, email=?, phone=?, role=?, is_active=? WHERE id=?', [post('full_name'), post('email'), nul(post('phone')), post('role'), post('is_active') ? 1 : 0, $id]);
            if ($pw !== '') {
                if (mb_strlen($pw) < 6) throw new Exception('Mật khẩu mới cần tối thiểu 6 ký tự.');
                q('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($pw, PASSWORD_DEFAULT), $id]);
            }
        } else {
            if (mb_strlen($pw) < 6) throw new Exception('Mật khẩu cần tối thiểu 6 ký tự.');
            q('INSERT INTO users (full_name, email, phone, password_hash, role, is_active) VALUES (?,?,?,?,?,?)',
              [post('full_name'), post('email'), nul(post('phone')), password_hash($pw, PASSWORD_DEFAULT), post('role'), post('is_active') ? 1 : 0]);
        }
    }, 'Đã lưu người dùng.', 'users.php');
}

$edit = g('edit') !== '' ? row('SELECT * FROM users WHERE id = ?', [(int)g('edit')]) : null;
$showForm = isset($_GET['new']) || $edit || is_post();
$list = rows('SELECT * FROM users ORDER BY id');

render_header('Người dùng hệ thống');
?>
<div class="mb-3"><a class="btn btn-primary" href="users.php?new=1"><i class="bi bi-plus-lg"></i> Thêm người dùng</a></div>
<div class="alert alert-info small">Chủ trọ: toàn quyền · Quản lý: toàn quyền trừ quản lý người dùng · Nhân viên: nhập chỉ số, ghi nhận thanh toán, xử lý sửa chữa, xem dữ liệu.</div>

<?php if ($showForm): ?>
<div class="card mb-4"><div class="card-header"><?= $edit ? 'Sửa người dùng' : 'Thêm người dùng' ?></div><div class="card-body">
<form method="post" class="row g-3"><?= csrf_field() ?><input type="hidden" name="do" value="save"><input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
  <div class="col-md-4"><label class="form-label">Họ tên *</label><input name="full_name" class="form-control" required value="<?= e(old('full_name', $edit['full_name'] ?? '')) ?>"></div>
  <div class="col-md-4"><label class="form-label">Email (đăng nhập) *</label><input type="email" name="email" class="form-control" required value="<?= e(old('email', $edit['email'] ?? '')) ?>"></div>
  <div class="col-md-4"><label class="form-label">SĐT</label><input name="phone" class="form-control" value="<?= e(old('phone', $edit['phone'] ?? '')) ?>"></div>
  <div class="col-md-4"><label class="form-label">Vai trò</label><?= select('role', options('role'), old('role', $edit['role'] ?? 'STAFF')) ?></div>
  <div class="col-md-4"><label class="form-label"><?= $edit ? 'Mật khẩu mới (để trống = giữ nguyên)' : 'Mật khẩu *' ?></label><input type="password" name="password" class="form-control" minlength="6" <?= $edit ? '' : 'required' ?> autocomplete="new-password"></div>
  <div class="col-md-4 d-flex align-items-end"><div class="form-check"><input type="checkbox" class="form-check-input" id="ac" name="is_active" value="1" <?= (is_post() ? post('is_active') : ($edit['is_active'] ?? 1)) ? 'checked' : '' ?>><label for="ac" class="form-check-label">Đang hoạt động</label></div></div>
  <div class="col-12"><button class="btn btn-success">Lưu</button> <a class="btn btn-outline-secondary" href="users.php">Hủy</a></div>
</form></div></div>
<?php endif; ?>

<div class="card"><div class="table-responsive"><table class="table table-hover mb-0">
<thead class="table-light"><tr><th>Họ tên</th><th>Email</th><th>SĐT</th><th>Vai trò</th><th>Trạng thái</th><th>Tạo lúc</th><th></th></tr></thead><tbody>
<?php foreach ($list as $u): ?><tr>
  <td class="fw-semibold"><?= e($u['full_name']) ?></td><td><?= e($u['email']) ?></td><td><?= e($u['phone']) ?></td><td><?= badge('role', $u['role']) ?></td>
  <td><?= $u['is_active'] ? '<span class="badge text-bg-success">Hoạt động</span>' : '<span class="badge text-bg-secondary">Khóa</span>' ?>
    <?php if (strpos($u['password_hash'], 'REPLACE_WITH_REAL') !== false): ?><span class="badge text-bg-warning">Chưa đặt mật khẩu</span><?php endif; ?></td>
  <td><?= dt($u['created_at']) ?></td><td class="text-end"><a class="btn btn-sm btn-outline-primary" href="users.php?edit=<?= (int)$u['id'] ?>">Sửa</a></td></tr>
<?php endforeach; ?></tbody></table></div></div>
<?php render_footer();
