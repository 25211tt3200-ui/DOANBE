<?php
require __DIR__ . '/inc/bootstrap.php';
require_login();

if (is_post()) {
    $do = post('do');
    if ($do === 'mark') {
        $nid = (int)post('id'); $to = post('status');
        run(function () use ($nid, $to) {
            if ($to === 'SENT')        q("UPDATE notifications SET status='SENT', sent_at=NOW(), attempts=attempts+1, error_message=NULL WHERE id=?", [$nid]);
            elseif ($to === 'FAILED')  q("UPDATE notifications SET status='FAILED', attempts=attempts+1, error_message='Đánh dấu lỗi thủ công' WHERE id=?", [$nid]);
            elseif ($to === 'PENDING') q("UPDATE notifications SET status='PENDING', error_message=NULL, scheduled_at=NOW() WHERE id=?", [$nid]);
        }, 'Đã cập nhật trạng thái thông báo.', 'notifications.php');
    } elseif ($do === 'delete') {
        if (!is_manager()) deny();
        run(function () { q('DELETE FROM notifications WHERE id = ?', [(int)post('id')]); }, 'Đã xóa thông báo.', 'notifications.php');
    } elseif ($do === 'expiry') {
        if (!is_manager()) deny();
        run(function () {
            $n = 0;
            foreach (rows('SELECT v.contract_id, v.room_code, v.end_date, v.days_left, ct.tenant_id, t.full_name
                           FROM v_contracts_expiring v JOIN contract_tenants ct ON ct.contract_id = v.contract_id AND ct.is_primary = 1
                           JOIN tenants t ON t.id = ct.tenant_id') as $c) {
                $dup = val("SELECT COUNT(*) FROM notifications WHERE tenant_id = ? AND notif_type = 'CONTRACT_EXPIRY' AND created_at > NOW() - INTERVAL 7 DAY", [$c['tenant_id']]);
                if ($dup) continue;
                $id = queue_notification((int)$c['tenant_id'], 'CONTRACT_EXPIRY', 'Hợp đồng thuê phòng ' . $c['room_code'] . ' sắp hết hạn',
                    'Chào ' . $c['full_name'] . ', hợp đồng thuê phòng ' . $c['room_code'] . ' sẽ hết hạn vào ' . d($c['end_date']) . ' (còn ' . (int)$c['days_left'] . ' ngày). Vui lòng liên hệ chủ trọ nếu muốn gia hạn.');
                if ($id) $n++;
            }
            flash('info', "Đã tạo $n thông báo nhắc hết hạn hợp đồng (bỏ qua khách đã được nhắc trong 7 ngày qua).");
        }, 'Hoàn tất.', 'notifications.php');
    } elseif ($do === 'general') {
        if (!is_manager()) deny();
        run(function () {
            if (post('subject') === '' || post('body') === '') throw new Exception('Vui lòng nhập tiêu đề và nội dung.');
            $target = post('tenant_id');
            $ids = $target === 'ALL'
                ? array_column(rows("SELECT DISTINCT ct.tenant_id FROM contract_tenants ct JOIN contracts c ON c.id = ct.contract_id AND c.status = 'ACTIVE' WHERE ct.is_primary = 1"), 'tenant_id')
                : [(int)$target];
            $n = 0;
            foreach ($ids as $tid) if (queue_notification((int)$tid, 'GENERAL', post('subject'), post('body'))) $n++;
            if (!$n) throw new Exception('Không có khách nào có thông tin liên lạc phù hợp để nhận thông báo.');
            flash('info', "Đã xếp hàng $n thông báo.");
        }, 'Hoàn tất.', 'notifications.php');
    }
}

$fs = g('status', 'PENDING'); $ft = g('type');
$where = []; $par = [];
if ($fs !== '' && $fs !== 'all') { $where[] = 'n.status = ?'; $par[] = $fs; }
if ($ft !== '') { $where[] = 'n.notif_type = ?'; $par[] = $ft; }
$list = rows('SELECT n.*, t.full_name FROM notifications n JOIN tenants t ON t.id = n.tenant_id'
    . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY n.id DESC LIMIT 300', $par);
$tenantOpts = ['ALL' => '★ Tất cả khách đang thuê'];
foreach (rows('SELECT id, full_name FROM tenants ORDER BY full_name') as $t) $tenantOpts[$t['id']] = $t['full_name'];

render_header('Thông báo (Zalo / Email / SMS)');
?>
<div class="alert alert-info small no-print">Đây là <strong>hàng đợi thông báo</strong>. Việc gửi thật qua Zalo OA/Email/SMS do một tiến trình gửi bên ngoài đảm nhiệm, đọc các dòng “Chờ gửi” rồi cập nhật trạng thái. Bạn cũng có thể đánh dấu thủ công sau khi tự gửi.</div>

<?php if (is_manager()): ?>
<div class="row g-3 mb-4 no-print">
  <div class="col-lg-7"><div class="card h-100"><div class="card-header">Gửi thông báo chung</div><div class="card-body">
    <form method="post" class="row g-2"><?= csrf_field() ?><input type="hidden" name="do" value="general">
      <div class="col-md-5"><?= select('tenant_id', $tenantOpts, 'ALL', 'class="form-select-sm"') ?></div>
      <div class="col-md-7"><input name="subject" class="form-control form-control-sm" placeholder="Tiêu đề" required></div>
      <div class="col-12"><textarea name="body" class="form-control form-control-sm" rows="2" placeholder="Nội dung (vd: thông báo cắt điện, họp cư dân...)" required></textarea></div>
      <div class="col-12"><button class="btn btn-sm btn-primary">Xếp hàng gửi</button></div></form></div></div></div>
  <div class="col-lg-5"><div class="card h-100"><div class="card-header">Nhắc hết hạn hợp đồng</div><div class="card-body">
    <p class="small text-muted">Tạo thông báo cho người đứng tên các hợp đồng sẽ hết hạn trong 60 ngày tới.</p>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="expiry"><button class="btn btn-sm btn-outline-warning">Tạo nhắc hết hạn</button></form></div></div></div>
</div>
<?php endif; ?>

<form class="row g-2 mb-3 no-print" method="get">
  <div class="col-md-3"><?= select('status', ['all' => 'Mọi trạng thái'] + options('notif'), $fs) ?></div>
  <div class="col-md-3"><?= select('type', options('ntype'), $ft, '', 'Mọi loại') ?></div>
  <div class="col-auto"><button class="btn btn-outline-primary">Lọc</button></div>
</form>
<div class="card"><div class="table-responsive"><table class="table table-hover mb-0 align-middle">
<thead class="table-light"><tr><th>Tạo lúc</th><th>Khách</th><th>Loại</th><th>Kênh → Người nhận</th><th>Nội dung</th><th>Trạng thái</th><th></th></tr></thead><tbody>
<?php foreach ($list as $n): ?><tr>
  <td class="text-nowrap"><?= dt($n['created_at']) ?></td><td><?= e($n['full_name']) ?></td><td><?= badge('ntype', $n['notif_type']) ?></td>
  <td><?= badge('channel', $n['channel']) ?> <span class="small"><?= e($n['recipient']) ?></span></td>
  <td style="max-width:380px"><div class="fw-semibold small"><?= e($n['subject']) ?></div><div class="small text-muted"><?= e($n['body']) ?></div>
    <?php if ($n['invoice_id']): ?><a class="small" href="invoice.php?id=<?= (int)$n['invoice_id'] ?>">Xem hóa đơn</a><?php endif; ?></td>
  <td><?= badge('notif', $n['status']) ?><?php if ($n['sent_at']): ?><div class="small text-muted"><?= dt($n['sent_at']) ?></div><?php endif; ?>
    <?php if ($n['error_message']): ?><div class="small text-danger"><?= e($n['error_message']) ?></div><?php endif; ?></td>
  <td class="text-end text-nowrap">
    <?php if ($n['status'] !== 'SENT'): ?><form method="post" class="d-inline"><?= csrf_field() ?><input type="hidden" name="do" value="mark"><input type="hidden" name="id" value="<?= (int)$n['id'] ?>"><input type="hidden" name="status" value="SENT"><button class="btn btn-sm btn-outline-success" title="Đánh dấu đã gửi"><i class="bi bi-check2"></i></button></form><?php endif; ?>
    <?php if ($n['status'] === 'PENDING'): ?><form method="post" class="d-inline"><?= csrf_field() ?><input type="hidden" name="do" value="mark"><input type="hidden" name="id" value="<?= (int)$n['id'] ?>"><input type="hidden" name="status" value="FAILED"><button class="btn btn-sm btn-outline-danger" title="Đánh dấu lỗi"><i class="bi bi-x"></i></button></form><?php endif; ?>
    <?php if ($n['status'] === 'FAILED'): ?><form method="post" class="d-inline"><?= csrf_field() ?><input type="hidden" name="do" value="mark"><input type="hidden" name="id" value="<?= (int)$n['id'] ?>"><input type="hidden" name="status" value="PENDING"><button class="btn btn-sm btn-outline-secondary" title="Xếp hàng lại"><i class="bi bi-arrow-repeat"></i></button></form><?php endif; ?>
    <?php if (is_manager()): ?><form method="post" class="d-inline" <?= confirm_attr('Xóa thông báo này?') ?>><?= csrf_field() ?><input type="hidden" name="do" value="delete"><input type="hidden" name="id" value="<?= (int)$n['id'] ?>"><button class="btn btn-sm btn-outline-secondary"><i class="bi bi-trash"></i></button></form><?php endif; ?>
  </td></tr>
<?php endforeach; if (!$list) echo '<tr><td colspan="7" class="text-center text-muted py-4">Không có thông báo nào.</td></tr>'; ?></tbody></table></div></div>
<?php render_footer();
