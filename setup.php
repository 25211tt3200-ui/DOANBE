<?php
// Đặt mật khẩu lần đầu cho các tài khoản mẫu (password_hash còn là chuỗi giữ chỗ trong file seed).
// Trang này CHỈ chạm vào tài khoản chưa có mật khẩu thật. Xong việc hãy XÓA file này.
require __DIR__ . '/inc/bootstrap.php';

$msg = ''; $ok = false;
try {
    $pending = rows("SELECT id, full_name, email, role FROM users WHERE password_hash LIKE '%REPLACE_WITH_REAL%'");
    if (is_post()) {
        csrf_check();
        $pw = (string)($_POST['password'] ?? '');
        if (mb_strlen($pw) < 6) {
            $msg = 'Mật khẩu cần tối thiểu 6 ký tự.';
        } else {
            $n = q("UPDATE users SET password_hash = ? WHERE password_hash LIKE '%REPLACE_WITH_REAL%'", [password_hash($pw, PASSWORD_DEFAULT)])->rowCount();
            $ok = true; $msg = "Đã đặt mật khẩu cho $n tài khoản.";
            $pending = [];
        }
    }
} catch (Throwable $e) {
    $msg = 'Lỗi: ' . $e->getMessage();
    $pending = [];
}
?>
<!doctype html>
<html lang="vi"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Thiết lập · <?= e(APP_NAME) ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"></head>
<body class="bg-light"><div class="container py-5" style="max-width:560px">
<div class="card shadow-sm"><div class="card-body p-4">
  <h1 class="h4 mb-3">Thiết lập mật khẩu lần đầu</h1>
  <?php if ($msg): ?><div class="alert alert-<?= $ok ? 'success' : 'danger' ?>"><?= e($msg) ?></div><?php endif; ?>
  <?php if ($pending): ?>
    <p>Các tài khoản sau chưa có mật khẩu:</p>
    <ul><?php foreach ($pending as $u): ?><li><?= e($u['email']) ?> — <?= e($u['full_name']) ?> (<?= e(label('role', $u['role'])) ?>)</li><?php endforeach; ?></ul>
    <form method="post"><?= csrf_field() ?>
      <label class="form-label">Mật khẩu chung cho các tài khoản trên</label>
      <input type="password" name="password" class="form-control mb-3" minlength="6" required>
      <button class="btn btn-primary">Đặt mật khẩu</button>
    </form>
    <p class="text-muted small mt-3 mb-0">Sau khi đăng nhập, mỗi người nên tự đổi mật khẩu ở mục “Đổi mật khẩu”.</p>
  <?php elseif ($ok): ?>
    <a class="btn btn-primary" href="login.php">Đến trang đăng nhập</a>
    <p class="text-danger small mt-3 mb-0">Hãy xóa file <code>setup.php</code> khỏi thư mục dự án.</p>
  <?php elseif (!$msg): ?>
    <p class="mb-2">Không còn tài khoản nào cần đặt mật khẩu.</p>
    <a class="btn btn-primary" href="login.php">Đăng nhập</a>
    <p class="text-danger small mt-3 mb-0">Bạn có thể xóa file <code>setup.php</code>.</p>
  <?php endif; ?>
</div></div></div></body></html>
