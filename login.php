<?php
require __DIR__ . '/inc/bootstrap.php';
if (user()) redirect('index.php');

$err = '';
if (is_post()) {
    csrf_check();
    $email = trim((string)($_POST['email'] ?? ''));
    $pass  = (string)($_POST['password'] ?? '');
    try {
        $u = row('SELECT * FROM users WHERE email = ?', [$email]);
        if (!$u || !$u['is_active']) {
            $err = 'Sai email hoặc mật khẩu.';
        } elseif (strpos($u['password_hash'], 'REPLACE_WITH_REAL') !== false) {
            $err = 'Tài khoản chưa được đặt mật khẩu. Hãy mở trang setup.php để đặt mật khẩu lần đầu.';
        } elseif (!password_verify($pass, $u['password_hash'])) {
            $err = 'Sai email hoặc mật khẩu.';
        } else {
            session_regenerate_id(true);
            $_SESSION['user'] = ['id' => (int)$u['id'], 'name' => $u['full_name'], 'role' => $u['role']];
            redirect('index.php');
        }
    } catch (Throwable $e) {
        $err = 'Không kết nối được cơ sở dữ liệu: ' . $e->getMessage();
    }
}
?>
<!doctype html>
<html lang="vi">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Đăng nhập · <?= e(APP_NAME) ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light d-flex align-items-center" style="min-height:100vh">
<div class="container" style="max-width:420px">
  <div class="card shadow-sm">
    <div class="card-body p-4">
      <h1 class="h4 text-center mb-4"><?= e(APP_NAME) ?></h1>
      <?php if ($err): ?><div class="alert alert-danger"><?= e($err) ?></div><?php endif; ?>
      <form method="post">
        <?= csrf_field() ?>
        <div class="mb-3"><label class="form-label">Email</label>
          <input type="email" name="email" class="form-control" value="<?= e($_POST['email'] ?? '') ?>" required autofocus></div>
        <div class="mb-3"><label class="form-label">Mật khẩu</label>
          <input type="password" name="password" class="form-control" required></div>
        <button class="btn btn-primary w-100">Đăng nhập</button>
      </form>
    </div>
  </div>
</div>
</body>
</html>
