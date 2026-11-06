<?php
require __DIR__ . '/inc/bootstrap.php';
require_login();

if (is_post()) {
    run(function () {
        $u = row('SELECT password_hash FROM users WHERE id = ?', [user()['id']]);
        if (!$u || !password_verify((string)post('current'), $u['password_hash'])) throw new Exception('Mật khẩu hiện tại không đúng.');
        if (mb_strlen((string)post('new')) < 6) throw new Exception('Mật khẩu mới cần tối thiểu 6 ký tự.');
        if (post('new') !== post('confirm')) throw new Exception('Mật khẩu nhập lại không khớp.');
        q('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash((string)post('new'), PASSWORD_DEFAULT), user()['id']]);
    }, 'Đã đổi mật khẩu.', 'profile.php');
}

render_header('Đổi mật khẩu');
?>
<div class="card" style="max-width:480px"><div class="card-body">
<form method="post"><?= csrf_field() ?>
  <div class="mb-3"><label class="form-label">Mật khẩu hiện tại</label><input type="password" name="current" class="form-control" required></div>
  <div class="mb-3"><label class="form-label">Mật khẩu mới</label><input type="password" name="new" class="form-control" minlength="6" required autocomplete="new-password"></div>
  <div class="mb-3"><label class="form-label">Nhập lại mật khẩu mới</label><input type="password" name="confirm" class="form-control" minlength="6" required autocomplete="new-password"></div>
  <button class="btn btn-primary">Đổi mật khẩu</button>
</form></div></div>
<?php render_footer();
