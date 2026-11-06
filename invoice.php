<?php
require __DIR__ . '/inc/bootstrap.php';
require_login();

$id = (int)g('id');
$self = 'invoice.php?id=' . $id;

if (is_post()) {
    $do = post('do');
    // Ghi nhận thanh toán: mọi nhân viên được làm. Các thao tác còn lại cần quyền quản lý.
    if ($do !== 'pay' && !is_manager()) deny();

    if ($do === 'pay') {
        run(function () use ($id) {
            $amount = (int)post('amount');
            if ($amount <= 0) throw new Exception('Số tiền thanh toán phải lớn hơn 0.');
            q('INSERT INTO payments (invoice_id, amount, method, paid_at, reference_code, received_by, note) VALUES (?,?,?,?,?,?,?)',
              [$id, $amount, post('method'), dtl_to_sql(post('paid_at')) ?: date('Y-m-d H:i:s'), nul(post('reference_code')), user()['id'], nul(post('note'))]);
        }, 'Đã ghi nhận thanh toán.', $self);
    } elseif ($do === 'del_payment') {
        run(function () use ($id) { q('DELETE FROM payments WHERE id = ? AND invoice_id = ?', [(int)post('payment_id'), $id]); }, 'Đã xóa khoản thanh toán.', $self);
    } elseif ($do === 'issue') {
        run(function () use ($id) {
            q('CALL issue_invoice(?, @nid)', [$id]);
            $n = val('SELECT @nid');
            flash($n ? 'info' : 'warning', $n ? 'Đã xếp hàng thông báo hóa đơn cho khách.' : 'Khách chưa có thông tin liên lạc phù hợp nên chưa tạo được thông báo.');
        }, 'Đã phát hành hóa đơn.', $self);
    } elseif ($do === 'cancel') {
        run(function () use ($id) {
            $inv = row('SELECT paid_amount, status FROM invoices WHERE id = ?', [$id]);
            if (!$inv) throw new Exception('Không tìm thấy hóa đơn.');
            if ((float)$inv['paid_amount'] > 0) throw new Exception('Hóa đơn đã có thanh toán, hãy xóa các khoản thanh toán trước khi hủy.');
            q("UPDATE invoices SET status = 'CANCELLED' WHERE id = ?", [$id]);
        }, 'Đã hủy hóa đơn. Có thể lập lại hóa đơn mới cho kỳ này.', $self);
    } elseif ($do === 'adjust') {
        run(function () use ($id) {
            q('UPDATE invoices SET discount_amount = ?, due_date = ?, note = ? WHERE id = ?', [(int)post('discount_amount'), post('due_date'), nul(post('note')), $id]);
        }, 'Đã cập nhật hóa đơn.', $self);
    } elseif ($do === 'add_item') {
        run(function () use ($id) {
            if (post('description') === '') throw new Exception('Vui lòng nhập nội dung khoản thu.');
            q("INSERT INTO invoice_items (invoice_id, item_type, description, quantity, unit_name, unit_price) VALUES (?, 'OTHER', ?, ?, ?, ?)",
              [$id, post('description'), post('quantity') !== '' ? post('quantity') : 1, nul(post('unit_name')), (int)post('unit_price')]);
        }, 'Đã thêm khoản thu.', $self);
    } elseif ($do === 'del_item') {
        run(function () use ($id) { q('DELETE FROM invoice_items WHERE id = ? AND invoice_id = ?', [(int)post('item_id'), $id]); }, 'Đã xóa khoản thu.', $self);
    } elseif ($do === 'resend') {
        run(function () use ($id) {
            $i = row('SELECT i.invoice_no, i.period, i.due_date, i.total_amount, i.balance, r.room_code, t.id AS tid, t.full_name
                      FROM invoices i JOIN contracts c ON c.id = i.contract_id JOIN rooms r ON r.id = c.room_id
                      JOIN contract_tenants ct ON ct.contract_id = c.id AND ct.is_primary = 1 JOIN tenants t ON t.id = ct.tenant_id WHERE i.id = ?', [$id]);
            if (!$i) throw new Exception('Không tìm thấy hóa đơn.');
            $n = queue_notification((int)$i['tid'], 'INVOICE', 'Hóa đơn tiền phòng ' . $i['room_code'] . ' - kỳ ' . per($i['period']),
                'Chào ' . $i['full_name'] . ', hóa đơn tiền phòng ' . $i['room_code'] . ' kỳ ' . per($i['period']) . ' (mã ' . $i['invoice_no'] . '): ' . vnd($i['total_amount']) .
                ', còn nợ ' . vnd($i['balance']) . '. Hạn thanh toán: ' . d($i['due_date']) . '.', $id);
            if (!$n) throw new Exception('Khách chưa có thông tin liên lạc phù hợp với kênh nhận thông báo.');
        }, 'Đã xếp hàng gửi lại thông báo.', $self);
    }
}

$inv = row('SELECT i.*, c.contract_no, c.id AS cid, r.room_code, p.name AS pname, t.full_name AS tenant, t.phone AS tphone
            FROM invoices i JOIN contracts c ON c.id = i.contract_id JOIN rooms r ON r.id = c.room_id JOIN properties p ON p.id = r.property_id
            LEFT JOIN contract_tenants ct ON ct.contract_id = c.id AND ct.is_primary = 1 LEFT JOIN tenants t ON t.id = ct.tenant_id WHERE i.id = ?', [$id]);
if (!$inv) { http_response_code(404); render_header('Không tìm thấy', 'invoices.php'); echo '<div class="alert alert-warning">Không tìm thấy hóa đơn.</div>'; render_footer(); exit; }
$items = rows('SELECT * FROM invoice_items WHERE invoice_id = ? ORDER BY id', [$id]);
$pays  = rows('SELECT py.*, u.full_name AS receiver FROM payments py LEFT JOIN users u ON u.id = py.received_by WHERE py.invoice_id = ? ORDER BY py.paid_at', [$id]);
$notifs = rows('SELECT * FROM notifications WHERE invoice_id = ? ORDER BY id DESC', [$id]);

$st = $inv['status'];
$canPay = in_array($st, ['ISSUED', 'PARTIAL', 'OVERDUE'], true) && (float)$inv['balance'] > 0;
$editable = !in_array($st, ['PAID', 'CANCELLED'], true);

render_header('Hóa đơn ' . ($inv['invoice_no'] ?: '#' . $id), 'invoices.php');
?>
<div class="mb-3 d-flex flex-wrap gap-2 align-items-center no-print">
  <a class="btn btn-sm btn-outline-secondary" href="invoices.php?ym=<?= e(substr($inv['period'], 0, 7)) ?>"><i class="bi bi-arrow-left"></i> Danh sách</a>
  <?= badge('invoice', $st) ?>
  <button class="btn btn-sm btn-outline-secondary" onclick="window.print()"><i class="bi bi-printer"></i> In</button>
  <?php if (is_manager()): ?>
    <?php if ($st === 'DRAFT'): ?>
      <form method="post" class="d-inline"><?= csrf_field() ?><input type="hidden" name="do" value="issue"><button class="btn btn-sm btn-success">Phát hành &amp; gửi thông báo</button></form>
    <?php elseif ($st !== 'CANCELLED'): ?>
      <form method="post" class="d-inline"><?= csrf_field() ?><input type="hidden" name="do" value="resend"><button class="btn btn-sm btn-outline-primary">Gửi lại thông báo</button></form>
    <?php endif; ?>
    <?php if ($st !== 'CANCELLED'): ?>
      <form method="post" class="d-inline" <?= confirm_attr('Hủy hóa đơn này?') ?>><?= csrf_field() ?><input type="hidden" name="do" value="cancel"><button class="btn btn-sm btn-outline-danger">Hủy hóa đơn</button></form>
    <?php endif; ?>
  <?php endif; ?>
</div>

<div class="card mb-3"><div class="card-body">
  <div class="d-flex justify-content-between flex-wrap">
    <div><div class="h5 mb-1">HÓA ĐƠN TIỀN PHÒNG THÁNG <?= per($inv['period']) ?></div>
      <div class="text-muted">Số: <?= e($inv['invoice_no']) ?> · Lập ngày <?= d($inv['issue_date']) ?> · Hạn thanh toán <strong><?= d($inv['due_date']) ?></strong></div></div>
    <div class="text-end"><?= e($inv['pname']) ?><br>Phòng <strong><?= e($inv['room_code']) ?></strong></div>
  </div>
  <hr>
  <div class="mb-3">Khách thuê: <strong><?= e($inv['tenant']) ?></strong> <?= $inv['tphone'] ? '· ' . e($inv['tphone']) : '' ?> · Hợp đồng <a href="contracts.php?id=<?= (int)$inv['cid'] ?>"><?= e($inv['contract_no']) ?></a></div>

  <div class="table-responsive"><table class="table table-sm">
    <thead><tr><th>Khoản mục</th><th class="num">Chỉ số cũ</th><th class="num">Chỉ số mới</th><th class="num">Số lượng</th><th>ĐVT</th><th class="num">Đơn giá</th><th class="num">Thành tiền</th><?php if (is_manager() && $editable): ?><th class="no-print"></th><?php endif; ?></tr></thead><tbody>
    <?php foreach ($items as $it): ?><tr>
      <td><?= e($it['description']) ?></td>
      <td class="num"><?= $it['previous_reading'] !== null ? e(num($it['previous_reading'], 2)) : '' ?></td>
      <td class="num"><?= $it['current_reading'] !== null ? e(num($it['current_reading'], 2)) : '' ?></td>
      <td class="num"><?= e(num($it['quantity'], 2)) ?></td><td><?= e($it['unit_name']) ?></td>
      <td class="num"><?= vnd($it['unit_price']) ?></td><td class="num"><?= vnd($it['amount']) ?></td>
      <?php if (is_manager() && $editable): ?><td class="no-print text-end">
        <?php if ($st === 'DRAFT' || $it['item_type'] === 'OTHER'): ?>
        <form method="post" class="d-inline" <?= confirm_attr('Xóa khoản này?') ?>><?= csrf_field() ?><input type="hidden" name="do" value="del_item"><input type="hidden" name="item_id" value="<?= (int)$it['id'] ?>"><button class="btn btn-sm btn-link text-danger p-0"><i class="bi bi-x-lg"></i></button></form>
        <?php endif; ?></td><?php endif; ?></tr>
    <?php endforeach; ?></tbody>
    <tfoot>
      <tr><td colspan="6" class="text-end">Tạm tính</td><td class="num"><?= vnd($inv['subtotal']) ?></td><?php if (is_manager() && $editable): ?><td class="no-print"></td><?php endif; ?></tr>
      <?php if ($inv['discount_amount'] > 0): ?><tr><td colspan="6" class="text-end">Giảm trừ</td><td class="num">− <?= vnd($inv['discount_amount']) ?></td><?php if (is_manager() && $editable): ?><td class="no-print"></td><?php endif; ?></tr><?php endif; ?>
      <tr class="fw-bold"><td colspan="6" class="text-end">TỔNG CỘNG</td><td class="num"><?= vnd($inv['total_amount']) ?></td><?php if (is_manager() && $editable): ?><td class="no-print"></td><?php endif; ?></tr>
      <tr><td colspan="6" class="text-end">Đã thanh toán</td><td class="num"><?= vnd($inv['paid_amount']) ?></td><?php if (is_manager() && $editable): ?><td class="no-print"></td><?php endif; ?></tr>
      <tr class="fw-bold <?= $inv['balance'] > 0 ? 'text-danger' : 'text-success' ?>"><td colspan="6" class="text-end">CÒN NỢ</td><td class="num"><?= vnd($inv['balance']) ?></td><?php if (is_manager() && $editable): ?><td class="no-print"></td><?php endif; ?></tr>
    </tfoot></table></div>
  <?php if ($inv['note']): ?><div class="text-muted">Ghi chú: <?= e($inv['note']) ?></div><?php endif; ?>
</div></div>

<div class="row g-3 no-print">
  <div class="col-lg-6"><div class="card h-100"><div class="card-header">Thanh toán</div>
    <div class="table-responsive"><table class="table table-sm mb-0"><thead><tr><th>Ngày</th><th>Hình thức</th><th class="num">Số tiền</th><th>Mã GD / Ghi chú</th><th></th></tr></thead><tbody>
    <?php foreach ($pays as $py): ?><tr>
      <td><?= dt($py['paid_at']) ?></td><td><?= badge('method', $py['method']) ?></td><td class="num"><?= vnd($py['amount']) ?></td>
      <td class="small"><?= e($py['reference_code']) ?> <?= e($py['note']) ?><div class="text-muted"><?= e($py['receiver']) ?></div></td>
      <td class="text-end"><?php if (is_manager()): ?><form method="post" class="d-inline" <?= confirm_attr('Xóa khoản thanh toán này?') ?>><?= csrf_field() ?><input type="hidden" name="do" value="del_payment"><input type="hidden" name="payment_id" value="<?= (int)$py['id'] ?>"><button class="btn btn-sm btn-link text-danger p-0"><i class="bi bi-trash"></i></button></form><?php endif; ?></td></tr>
    <?php endforeach; if (!$pays) echo '<tr><td colspan="5" class="text-center text-muted py-3">Chưa có thanh toán.</td></tr>'; ?></tbody></table></div>
    <?php if ($canPay): ?>
    <div class="card-footer"><form method="post" class="row g-2"><?= csrf_field() ?><input type="hidden" name="do" value="pay">
      <div class="col-6"><label class="form-label small mb-0">Số tiền (tối đa <?= vnd($inv['balance']) ?>)</label><input type="number" min="1" max="<?= (int)$inv['balance'] ?>" name="amount" class="form-control form-control-sm" value="<?= (int)$inv['balance'] ?>" required></div>
      <div class="col-6"><label class="form-label small mb-0">Hình thức</label><?= select('method', options('method'), 'CASH', 'class="form-select-sm"') ?></div>
      <div class="col-6"><label class="form-label small mb-0">Thời điểm</label><input type="datetime-local" name="paid_at" class="form-control form-control-sm" value="<?= date('Y-m-d\TH:i') ?>"></div>
      <div class="col-6"><label class="form-label small mb-0">Mã giao dịch</label><input name="reference_code" class="form-control form-control-sm"></div>
      <div class="col-12"><input name="note" class="form-control form-control-sm" placeholder="Ghi chú"></div>
      <div class="col-12"><button class="btn btn-sm btn-success">Ghi nhận thanh toán</button></div></form></div>
    <?php elseif ($st === 'DRAFT'): ?><div class="card-footer text-muted small">Hãy phát hành hóa đơn trước khi ghi nhận thanh toán.</div><?php endif; ?>
  </div></div>

  <?php if (is_manager() && $editable): ?>
  <div class="col-lg-6"><div class="card h-100"><div class="card-header">Điều chỉnh hóa đơn</div><div class="card-body">
    <form method="post" class="row g-2 mb-3"><?= csrf_field() ?><input type="hidden" name="do" value="adjust">
      <div class="col-md-4"><label class="form-label small mb-0">Giảm trừ (đ)</label><input type="number" min="0" name="discount_amount" class="form-control form-control-sm" value="<?= (int)$inv['discount_amount'] ?>"></div>
      <div class="col-md-4"><label class="form-label small mb-0">Hạn thanh toán</label><input type="date" name="due_date" class="form-control form-control-sm" value="<?= e($inv['due_date']) ?>" required></div>
      <div class="col-md-4"><label class="form-label small mb-0">Ghi chú</label><input name="note" class="form-control form-control-sm" value="<?= e($inv['note']) ?>"></div>
      <div class="col-12"><button class="btn btn-sm btn-primary">Lưu</button></div></form>
    <h6 class="small text-uppercase text-muted">Thêm khoản thu khác (nhập số âm để giảm trừ)</h6>
    <form method="post" class="row g-2"><?= csrf_field() ?><input type="hidden" name="do" value="add_item">
      <div class="col-12"><input name="description" class="form-control form-control-sm" placeholder="Nội dung (vd: Phí giữ xe, Đền bù hư hỏng)" required></div>
      <div class="col-4"><input type="number" step="0.01" name="quantity" class="form-control form-control-sm" value="1" placeholder="SL"></div>
      <div class="col-4"><input name="unit_name" class="form-control form-control-sm" placeholder="ĐVT"></div>
      <div class="col-4"><input type="number" name="unit_price" class="form-control form-control-sm" placeholder="Đơn giá" required></div>
      <div class="col-12"><button class="btn btn-sm btn-outline-primary">Thêm khoản</button></div></form>
  </div></div></div>
  <?php endif; ?>

  <?php if ($notifs): ?>
  <div class="col-12"><div class="card"><div class="card-header">Thông báo đã xếp hàng cho hóa đơn này</div>
    <div class="table-responsive"><table class="table table-sm mb-0"><thead><tr><th>Lúc</th><th>Loại</th><th>Kênh</th><th>Người nhận</th><th>Trạng thái</th></tr></thead><tbody>
    <?php foreach ($notifs as $n): ?><tr><td><?= dt($n['created_at']) ?></td><td><?= badge('ntype', $n['notif_type']) ?></td><td><?= badge('channel', $n['channel']) ?></td><td><?= e($n['recipient']) ?></td><td><?= badge('notif', $n['status']) ?></td></tr><?php endforeach; ?>
    </tbody></table></div></div></div>
  <?php endif; ?>
</div>
<?php render_footer();
