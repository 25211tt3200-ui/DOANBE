<?php
require __DIR__ . '/inc/bootstrap.php';
require_login();

$props = [];
foreach (rows('SELECT id, name FROM properties WHERE is_active = 1 ORDER BY id') as $p) $props[$p['id']] = $p['name'];
$pid    = (int)g('property_id', (string)(array_key_first($props) ?? 0));
$period = ym_to_period(g('ym', date('Y-m')));
$ym     = substr($period, 0, 7);
$self   = 'meters.php?property_id=' . $pid . '&ym=' . $ym;

/* Dịch vụ tính theo công tơ của cơ sở */
$svcs = $pid ? rows("SELECT id, name, unit_name FROM services WHERE property_id = ? AND billing_type = 'METERED' AND is_active = 1 ORDER BY id", [$pid]) : [];

/* Phòng có hợp đồng hiệu lực trong kỳ */
$rooms = $pid ? rows("SELECT r.id AS room_id, r.room_code, c.id AS contract_id, t.full_name
    FROM rooms r JOIN contracts c ON c.room_id = r.id AND c.status = 'ACTIVE' AND c.start_date <= LAST_DAY(?) AND (c.end_date IS NULL OR c.end_date >= ?)
    LEFT JOIN contract_tenants ct ON ct.contract_id = c.id AND ct.is_primary = 1 LEFT JOIN tenants t ON t.id = ct.tenant_id
    WHERE r.property_id = ? ORDER BY r.room_code", [$period, $period, $pid]) : [];

/* ---------------- Lưu chỉ số ---------------- */
if (is_post() && post('do') === 'save') {
    $svcName = []; foreach ($svcs as $s) $svcName[$s['id']] = $s['name'];
    $roomCode = []; foreach ($rooms as $r) $roomCode[$r['room_id']] = $r['room_code'];
    $uid = user()['id']; $saved = 0; $errs = [];
    foreach ((array)post('cur', []) as $rid => $arr) {
        foreach ((array)$arr as $sid => $v) {
            $v = trim((string)$v);
            if ($v === '') continue;
            $rid = (int)$rid; $sid = (int)$sid;
            if (!isset($roomCode[$rid]) || !isset($svcName[$sid])) continue;
            $label = $roomCode[$rid] . ' - ' . $svcName[$sid];
            try {
                if (!is_numeric($v) || (float)$v < 0) throw new Exception('chỉ số không hợp lệ');
                $ex = row('SELECT id, previous_reading, current_reading FROM meter_readings WHERE room_id=? AND service_id=? AND period=?', [$rid, $sid, $period]);
                if ($ex) {
                    if ((float)$v < (float)$ex['previous_reading']) throw new Exception('chỉ số mới (' . $v . ') nhỏ hơn chỉ số cũ (' . $ex['previous_reading'] . ')');
                    if ((float)$ex['current_reading'] != (float)$v) {
                        q('UPDATE meter_readings SET current_reading = ?, recorded_by = ? WHERE id = ?', [$v, $uid, $ex['id']]);
                        $saved++;
                    }
                } else {
                    $prevIn = isset($_POST['prev'][$rid][$sid]) ? nul($_POST['prev'][$rid][$sid]) : null;
                    if ($prevIn !== null && !is_numeric($prevIn)) throw new Exception('chỉ số cũ không hợp lệ');
                    $last = val('SELECT current_reading FROM meter_readings WHERE room_id=? AND service_id=? AND period<? ORDER BY period DESC LIMIT 1', [$rid, $sid, $period]);
                    $eff = $prevIn !== null ? (float)$prevIn : (float)($last ?? 0);
                    if ((float)$v < $eff) throw new Exception('chỉ số mới (' . $v . ') nhỏ hơn chỉ số cũ (' . $eff . ')');
                    q('INSERT INTO meter_readings (room_id, service_id, period, previous_reading, current_reading, reading_date, recorded_by) VALUES (?,?,?,?,?,CURRENT_DATE,?)',
                      [$rid, $sid, $period, $prevIn, $v, $uid]);
                    $saved++;
                }
            } catch (Throwable $e) {
                $errs[] = $label . ': ' . err_text($e);
            }
        }
    }
    if ($saved) flash('success', "Đã lưu $saved chỉ số.");
    foreach ($errs as $m) flash('danger', $m);
    if (!$saved && !$errs) flash('info', 'Không có chỉ số nào thay đổi.');
    redirect($self);
}

/* ---------------- Dữ liệu hiển thị ---------------- */
$exist = []; $prevMap = []; $invMap = [];
if ($pid && $svcs && $rooms) {
    foreach (rows('SELECT mr.* FROM meter_readings mr JOIN rooms r ON r.id = mr.room_id WHERE r.property_id = ? AND mr.period = ?', [$pid, $period]) as $m) $exist[$m['room_id']][$m['service_id']] = $m;
    foreach (rows('SELECT mr.room_id, mr.service_id, mr.current_reading FROM meter_readings mr JOIN rooms r ON r.id = mr.room_id
                   WHERE r.property_id = ? AND mr.period = (SELECT MAX(m2.period) FROM meter_readings m2 WHERE m2.room_id = mr.room_id AND m2.service_id = mr.service_id AND m2.period < ?)', [$pid, $period]) as $m)
        $prevMap[$m['room_id']][$m['service_id']] = $m['current_reading'];
    foreach (rows("SELECT id, contract_id, status FROM invoices WHERE period = ? AND status <> 'CANCELLED'", [$period]) as $i) $invMap[$i['contract_id']] = $i;
}

render_header('Nhập chỉ số điện / nước');
?>
<form class="row g-2 mb-3 no-print" method="get">
  <div class="col-md-4"><?= select('property_id', $props, $pid) ?></div>
  <div class="col-md-3"><input type="month" name="ym" class="form-control" value="<?= e($ym) ?>"></div>
  <div class="col-auto"><button class="btn btn-outline-primary">Xem kỳ</button></div>
  <div class="col-auto ms-auto"><a class="btn btn-outline-success" href="invoices.php?ym=<?= e($ym) ?>">Đến lập hóa đơn kỳ <?= per($period) ?> <i class="bi bi-arrow-right"></i></a></div>
</form>

<?php if (!$svcs): ?>
  <div class="alert alert-warning">Cơ sở này chưa có dịch vụ tính theo công tơ. Thêm ở mục <a href="services.php?property_id=<?= $pid ?>">Dịch vụ</a>.</div>
<?php elseif (!$rooms): ?>
  <div class="alert alert-info">Không có phòng nào có hợp đồng hiệu lực trong kỳ <?= per($period) ?>.</div>
<?php else: ?>
<form method="post" action="<?= e($self) ?>">
  <?= csrf_field() ?><input type="hidden" name="do" value="save">
  <div class="card"><div class="table-responsive"><table class="table table-bordered table-sm align-middle mb-0 text-center">
    <thead class="table-light">
      <tr><th rowspan="2" class="text-start">Phòng</th><th rowspan="2" class="text-start">Khách</th>
        <?php foreach ($svcs as $s): ?><th colspan="3"><?= e($s['name']) ?> (<?= e($s['unit_name']) ?>)</th><?php endforeach; ?></tr>
      <tr><?php foreach ($svcs as $s): ?><th>Cũ</th><th>Mới</th><th>Dùng</th><?php endforeach; ?></tr>
    </thead><tbody>
    <?php foreach ($rooms as $r):
        $locked = isset($invMap[$r['contract_id']]); ?>
      <tr>
        <td class="text-start fw-semibold"><?= e($r['room_code']) ?>
          <?php if ($locked): ?><div><a class="small" href="invoice.php?id=<?= (int)$invMap[$r['contract_id']]['id'] ?>"><?= badge('invoice', $invMap[$r['contract_id']]['status']) ?></a></div><?php endif; ?></td>
        <td class="text-start"><?= e($r['full_name']) ?></td>
        <?php foreach ($svcs as $s):
            $rid = (int)$r['room_id']; $sid = (int)$s['id'];
            $ex = $exist[$rid][$sid] ?? null; $k = $rid . '-' . $sid;
            $hasPrior = isset($prevMap[$rid][$sid]);
            $pv = $ex ? $ex['previous_reading'] : ($prevMap[$rid][$sid] ?? null); ?>
          <td style="min-width:90px">
            <?php if ($ex || $hasPrior): ?><span data-p="<?= $k ?>" data-v="<?= e($pv) ?>"><?= e(num($pv, 2)) ?></span>
            <?php else: ?><input type="number" step="0.01" min="0" class="form-control form-control-sm" data-p="<?= $k ?>" name="prev[<?= $rid ?>][<?= $sid ?>]" placeholder="0" <?= $locked ? 'disabled' : '' ?>><?php endif; ?></td>
          <td style="min-width:110px"><input type="number" step="0.01" min="0" class="form-control form-control-sm" data-c="<?= $k ?>" name="cur[<?= $rid ?>][<?= $sid ?>]"
              value="<?= e($ex ? $ex['current_reading'] : '') ?>" <?= $locked ? 'disabled title="Đã có hóa đơn kỳ này, không sửa được chỉ số"' : '' ?>></td>
          <td class="fw-semibold" data-u="<?= $k ?>"><?= $ex ? e(num($ex['consumption'], 2)) : '' ?></td>
        <?php endforeach; ?>
      </tr>
    <?php endforeach; ?>
    </tbody></table></div>
    <div class="card-footer d-flex justify-content-between align-items-center">
      <span class="text-muted small">Chỉ số cũ tự lấy từ kỳ trước. Phòng đã có hóa đơn trong kỳ sẽ bị khóa (hủy hóa đơn nếu cần sửa).</span>
      <button class="btn btn-success"><i class="bi bi-save"></i> Lưu chỉ số</button>
    </div></div>
</form>
<?php add_js("(function(){
  function val(el){ if(!el) return 0; var v = el.tagName==='INPUT' ? el.value : el.getAttribute('data-v'); return parseFloat(v)||0; }
  document.querySelectorAll('input[data-c]').forEach(function(c){
    var k=c.getAttribute('data-c'), p=document.querySelector('[data-p=\"'+k+'\"]'), u=document.querySelector('[data-u=\"'+k+'\"]');
    function upd(){ if(c.value===''){u.textContent='';return;} var d=parseFloat(c.value)-val(p); u.textContent=d.toLocaleString('vi-VN',{maximumFractionDigits:2}); u.className='fw-semibold '+(d<0?'text-danger':''); }
    c.addEventListener('input',upd); if(p&&p.tagName==='INPUT') p.addEventListener('input',upd);
  });
})();"); ?>
<?php endif;
render_footer();
