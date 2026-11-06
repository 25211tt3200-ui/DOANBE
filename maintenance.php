<?php
require __DIR__ . '/inc/bootstrap.php';
require_login();

$id   = (int)g('id');
$mode = $id ? 'view' : (isset($_GET['new']) ? 'new' : 'list');

/* ---------------- Ghi dữ liệu (mọi nhân viên đều được xử lý yêu cầu sửa chữa) ---------------- */
if (is_post()) {
    $do = post('do');
    if ($do === 'create') {
        run(function () {
            if (!(int)post('room_id') || post('title') === '') throw new Exception('Vui lòng chọn phòng và nhập tiêu đề.');
            q('INSERT INTO maintenance_requests (room_id, tenant_id, title, description, category, priority, cost_bearer) VALUES (?,?,?,?,?,?,?)',
              [(int)post('room_id'), (int)post('tenant_id') ?: null, post('title'), nul(post('description')), post('category'), post('priority'), post('cost_bearer')]);
            return 'maintenance.php?id=' . insert_id();
        }, 'Đã tạo yêu cầu sửa chữa.', 'maintenance.php');
        $mode = 'new';
    } elseif ($do === 'update') {
        $rid = (int)post('id');
        run(function () use ($rid) {
            $old = row('SELECT m.status, m.title, m.tenant_id FROM maintenance_requests m WHERE m.id = ?', [$rid]);
            if (!$old) throw new Exception('Không tìm thấy yêu cầu.');
            q('UPDATE maintenance_requests SET status=?, priority=?, assigned_to=?, vendor_name=?, scheduled_at=?, estimated_cost=?, actual_cost=?, cost_bearer=? WHERE id=?',
              [post('status'), post('priority'), (int)post('assigned_to') ?: null, nul(post('vendor_name')), dtl_to_sql(post('scheduled_at')),
               nul(post('estimated_cost')), nul(post('actual_cost')), post('cost_bearer'), $rid]);
            if ($old['status'] !== post('status') && $old['tenant_id']) {
                $n = queue_notification((int)$old['tenant_id'], 'MAINTENANCE_UPDATE', 'Cập nhật yêu cầu sửa chữa',
                    'Yêu cầu sửa chữa “' . $old['title'] . '” của bạn đã chuyển sang trạng thái: ' . label('mstatus', post('status')) . '.', null, $rid);
                if ($n) flash('info', 'Đã xếp hàng thông báo cập nhật cho khách báo sửa chữa.');
            }
        }, 'Đã cập nhật yêu cầu.', 'maintenance.php?id=' . $rid);
    } elseif ($do === 'log') {
        $rid = (int)post('id');
        run(function () use ($rid) {
            if (post('message') === '') throw new Exception('Vui lòng nhập nội dung ghi chú.');
            q('INSERT INTO maintenance_logs (request_id, author_user_id, message) VALUES (?,?,?)', [$rid, user()['id'], post('message')]);
        }, 'Đã thêm ghi chú.', 'maintenance.php?id=' . $rid);
    } elseif ($do === 'delete') {
        if (!is_manager()) deny();
        run(function () { q('DELETE FROM maintenance_requests WHERE id = ?', [(int)post('id')]); }, 'Đã xóa yêu cầu.', 'maintenance.php');
    }
}

$staff = [];
foreach (rows('SELECT id, full_name FROM users WHERE is_active = 1 ORDER BY full_name') as $u) $staff[$u['id']] = $u['full_name'];
$title = 'Yêu cầu sửa chữa';

if ($mode === 'list') {
    $fs = g('status', 'open'); $fp = g('priority'); $fprop = g('property_id');
    $where = []; $par = [];
    if ($fs === 'open') $where[] = "m.status IN ('OPEN','ASSIGNED','IN_PROGRESS')";
    elseif ($fs !== 'all' && $fs !== '') { $where[] = 'm.status = ?'; $par[] = $fs; }
    if ($fp !== '') { $where[] = 'm.priority = ?'; $par[] = $fp; }
    if ($fprop !== '') { $where[] = 'r.property_id = ?'; $par[] = (int)$fprop; }
    $list = rows('SELECT m.*, r.room_code, p.name AS pname, t.full_name AS reporter, u.full_name AS assignee
        FROM maintenance_requests m JOIN rooms r ON r.id = m.room_id JOIN properties p ON p.id = r.property_id
        LEFT JOIN tenants t ON t.id = m.tenant_id LEFT JOIN users u ON u.id = m.assigned_to'
        . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY m.priority DESC, m.reported_at DESC LIMIT 300', $par);
    $props = [];
    foreach (rows('SELECT id, name FROM properties ORDER BY id') as $p) $props[$p['id']] = $p['name'];
    $statusOpts = ['open' => 'Đang xử lý (chưa hoàn tất)', 'all' => 'Tất cả'] + options('mstatus');
} elseif ($mode === 'new') {
    $title = 'Tạo yêu cầu sửa chữa';
    $roomOpts = [];
    foreach (rows('SELECT r.id, r.room_code, p.name AS pname FROM rooms r JOIN properties p ON p.id = r.property_id ORDER BY p.id, r.room_code') as $r) $roomOpts[$r['id']] = $r['pname'] . ' - ' . $r['room_code'];
    $tenantOpts = [];
    foreach (rows('SELECT id, full_name, phone FROM tenants ORDER BY full_name') as $t) $tenantOpts[$t['id']] = $t['full_name'] . ($t['phone'] ? ' · ' . $t['phone'] : '');
} else {
    $m = row('SELECT m.*, r.room_code, p.name AS pname, t.full_name AS reporter, t.phone AS rphone
              FROM maintenance_requests m JOIN rooms r ON r.id = m.room_id JOIN properties p ON p.id = r.property_id LEFT JOIN tenants t ON t.id = m.tenant_id WHERE m.id = ?', [$id]);
    if (!$m) { http_response_code(404); render_header('Không tìm thấy', 'maintenance.php'); echo '<div class="alert alert-warning">Không tìm thấy yêu cầu.</div>'; render_footer(); exit; }
    $title = 'Yêu cầu #' . $id . ': ' . $m['title'];
    $logs = rows('SELECT l.*, u.full_name AS author FROM maintenance_logs l LEFT JOIN users u ON u.id = l.author_user_id WHERE l.request_id = ? ORDER BY l.created_at DESC, l.id DESC', [$id]);
}

render_header($title, 'maintenance.php');

if ($mode === 'list'): ?>
<form class="row g-2 mb-3 no-print" method="get">
  <div class="col-md-3"><?= select('status', $statusOpts, $fs) ?></div>
  <div class="col-md-2"><?= select('priority', options('priority'), $fp, '', 'Mọi mức ưu tiên') ?></div>
  <div class="col-md-3"><?= select('property_id', $props, $fprop, '', 'Tất cả cơ sở') ?></div>
  <div class="col-auto"><button class="btn btn-outline-primary">Lọc</button></div>
  <div class="col-auto ms-auto"><a class="btn btn-primary" href="maintenance.php?new=1"><i class="bi bi-plus-lg"></i> Tạo yêu cầu</a></div>
</form>
<div class="card"><div class="table-responsive"><table class="table table-hover mb-0">
<thead class="table-light"><tr><th>#</th><th>Phòng</th><th>Nội dung</th><th>Loại</th><th>Ưu tiên</th><th>Trạng thái</th><th>Người báo</th><th>Phụ trách</th><th>Báo lúc</th></tr></thead><tbody>
<?php foreach ($list as $r): ?><tr>
  <td><a href="maintenance.php?id=<?= (int)$r['id'] ?>"><?= (int)$r['id'] ?></a></td>
  <td><?= e($r['pname']) ?> / <strong><?= e($r['room_code']) ?></strong></td>
  <td><a class="fw-semibold text-decoration-none" href="maintenance.php?id=<?= (int)$r['id'] ?>"><?= e($r['title']) ?></a></td>
  <td><?= badge('category', $r['category']) ?></td><td><?= badge('priority', $r['priority']) ?></td><td><?= badge('mstatus', $r['status']) ?></td>
  <td><?= e($r['reporter']) ?></td><td><?= e($r['assignee'] ?: $r['vendor_name']) ?></td><td><?= dt($r['reported_at']) ?></td></tr>
<?php endforeach; if (!$list) echo '<tr><td colspan="9" class="text-center text-muted py-4">Không có yêu cầu nào.</td></tr>'; ?></tbody></table></div></div>

<?php elseif ($mode === 'new'): ?>
<form method="post" class="card"><div class="card-body row g-3"><?= csrf_field() ?><input type="hidden" name="do" value="create">
  <div class="col-md-4"><label class="form-label">Phòng *</label><?= select('room_id', $roomOpts, old('room_id'), 'required', '— Chọn phòng —') ?></div>
  <div class="col-md-4"><label class="form-label">Người báo (nếu là khách)</label><?= select('tenant_id', $tenantOpts, old('tenant_id'), '', '— Chủ trọ tự tạo —') ?></div>
  <div class="col-md-4"><label class="form-label">Chi phí do</label><?= select('cost_bearer', options('bearer'), old('cost_bearer', 'OWNER')) ?></div>
  <div class="col-md-8"><label class="form-label">Tiêu đề *</label><input name="title" class="form-control" required value="<?= e(old('title')) ?>"></div>
  <div class="col-md-2"><label class="form-label">Loại</label><?= select('category', options('category'), old('category', 'OTHER')) ?></div>
  <div class="col-md-2"><label class="form-label">Ưu tiên</label><?= select('priority', options('priority'), old('priority', 'MEDIUM')) ?></div>
  <div class="col-12"><label class="form-label">Mô tả</label><textarea name="description" class="form-control" rows="3"><?= e(old('description')) ?></textarea></div>
  <div class="col-12"><button class="btn btn-success">Tạo yêu cầu</button> <a class="btn btn-outline-secondary" href="maintenance.php">Hủy</a></div>
</div></form>

<?php else: ?>
<div class="mb-3"><a class="btn btn-sm btn-outline-secondary" href="maintenance.php"><i class="bi bi-arrow-left"></i> Danh sách</a>
  <?= badge('mstatus', $m['status']) ?> <?= badge('priority', $m['priority']) ?> <?= badge('category', $m['category']) ?></div>
<div class="row g-3">
  <div class="col-lg-5"><div class="card h-100"><div class="card-header">Thông tin</div><div class="card-body">
    <dl class="row mb-0">
      <dt class="col-4">Phòng</dt><dd class="col-8"><?= e($m['pname']) ?> / <strong><?= e($m['room_code']) ?></strong></dd>
      <dt class="col-4">Người báo</dt><dd class="col-8"><?= $m['reporter'] ? e($m['reporter']) . ' · ' . e($m['rphone']) : 'Chủ trọ tự tạo' ?></dd>
      <dt class="col-4">Báo lúc</dt><dd class="col-8"><?= dt($m['reported_at']) ?></dd>
      <?php if ($m['completed_at']): ?><dt class="col-4">Hoàn tất</dt><dd class="col-8"><?= dt($m['completed_at']) ?></dd><?php endif; ?>
      <dt class="col-4">Mô tả</dt><dd class="col-8"><?= nl2br(e($m['description'])) ?></dd>
    </dl></div></div></div>
  <div class="col-lg-7"><div class="card h-100"><div class="card-header">Xử lý</div><div class="card-body">
    <form method="post" class="row g-2"><?= csrf_field() ?><input type="hidden" name="do" value="update"><input type="hidden" name="id" value="<?= $id ?>">
      <div class="col-md-4"><label class="form-label small mb-0">Trạng thái</label><?= select('status', options('mstatus'), $m['status'], 'class="form-select-sm"') ?></div>
      <div class="col-md-4"><label class="form-label small mb-0">Ưu tiên</label><?= select('priority', options('priority'), $m['priority'], 'class="form-select-sm"') ?></div>
      <div class="col-md-4"><label class="form-label small mb-0">Phụ trách</label><?= select('assigned_to', $staff, $m['assigned_to'], 'class="form-select-sm"', '—') ?></div>
      <div class="col-md-6"><label class="form-label small mb-0">Thợ bên ngoài</label><input name="vendor_name" class="form-control form-control-sm" value="<?= e($m['vendor_name']) ?>"></div>
      <div class="col-md-6"><label class="form-label small mb-0">Hẹn xử lý lúc</label><input type="datetime-local" name="scheduled_at" class="form-control form-control-sm" value="<?= $m['scheduled_at'] ? e(date('Y-m-d\TH:i', strtotime($m['scheduled_at']))) : '' ?>"></div>
      <div class="col-md-4"><label class="form-label small mb-0">Chi phí dự kiến</label><input type="number" min="0" name="estimated_cost" class="form-control form-control-sm" value="<?= e($m['estimated_cost']) ?>"></div>
      <div class="col-md-4"><label class="form-label small mb-0">Chi phí thực tế</label><input type="number" min="0" name="actual_cost" class="form-control form-control-sm" value="<?= e($m['actual_cost']) ?>"></div>
      <div class="col-md-4"><label class="form-label small mb-0">Chi phí do</label><?= select('cost_bearer', options('bearer'), $m['cost_bearer'], 'class="form-select-sm"') ?></div>
      <div class="col-12"><button class="btn btn-sm btn-primary">Lưu</button>
        <span class="small text-muted ms-2">Đổi trạng thái sẽ tự ghi nhật ký và nhắn khách đã báo.</span></div>
    </form></div></div></div>

  <div class="col-12"><div class="card"><div class="card-header">Nhật ký xử lý</div>
    <div class="card-body">
      <form method="post" class="row g-2 mb-3"><?= csrf_field() ?><input type="hidden" name="do" value="log"><input type="hidden" name="id" value="<?= $id ?>">
        <div class="col"><input name="message" class="form-control form-control-sm" placeholder="Ghi chú tiến độ..." required></div>
        <div class="col-auto"><button class="btn btn-sm btn-outline-primary">Thêm ghi chú</button></div></form>
      <ul class="list-group list-group-flush">
      <?php foreach ($logs as $l): ?><li class="list-group-item px-0">
        <span class="text-muted small"><?= dt($l['created_at']) ?> · <?= e($l['author'] ?: 'Hệ thống') ?></span><br>
        <?php if ($l['old_status'] && $l['new_status']): ?><?= badge('mstatus', $l['old_status']) ?> <i class="bi bi-arrow-right"></i> <?= badge('mstatus', $l['new_status']) ?><?php endif; ?>
        <?= e($l['message']) ?></li>
      <?php endforeach; if (!$logs) echo '<li class="list-group-item px-0 text-muted">Chưa có nhật ký.</li>'; ?></ul>
    </div></div></div>

  <?php if (is_manager()): ?>
  <div class="col-12"><form method="post" <?= confirm_attr('Xóa yêu cầu sửa chữa này?') ?>><?= csrf_field() ?><input type="hidden" name="do" value="delete"><input type="hidden" name="id" value="<?= $id ?>"><button class="btn btn-sm btn-outline-danger">Xóa yêu cầu</button></form></div>
  <?php endif; ?>
</div>
<?php endif;
render_footer();
