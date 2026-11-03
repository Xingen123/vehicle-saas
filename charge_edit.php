<?php
require_once __DIR__ . '/includes/auth.php';
$u = require_manage();

if (db_needs_upgrade()) {
    flash('数据库未升级：请先在 phpMyAdmin 中执行 upgrade_ev.sql', 'error');
    redirect('charge.php');
}

$tid = tid();
$id = (int)($_GET['id'] ?? 0);
$r = $id ? q1('SELECT * FROM charge_records WHERE id = ? AND tenant_id = ?', [$id, $tid]) : null;
if ($id && !$r) { flash('记录不存在', 'error'); redirect('charge.php'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    require_write();
    $type = in_array($_POST['charge_type'] ?? '', ['满电', '零散补电', '外部快充'], true) ? $_POST['charge_type'] : '满电';
    $data = [
        'vehicle_id'  => (int)($_POST['vehicle_id'] ?? 0),
        'charge_time' => str_replace('T', ' ', ($_POST['charge_time'] ?? '')) . (strlen($_POST['charge_time'] ?? '') === 16 ? ':00' : ''),
        'charge_type' => $type,
        'mileage'     => max(0, (int)($_POST['mileage'] ?? 0)),
        'kwh'         => $type === '外部快充' && $_POST['kwh'] !== '' ? (float)$_POST['kwh'] : null,
        'price'       => $type === '外部快充' && $_POST['price'] !== '' ? (float)$_POST['price'] : null,
        'amount'      => $type === '外部快充' && $_POST['amount'] !== '' ? (float)$_POST['amount'] : null,
        'station'     => trim($_POST['station'] ?? ''),
        'driver_name' => trim($_POST['driver_name'] ?? ''),
        'remark'      => trim($_POST['remark'] ?? ''),
    ];
    // 外部快充：度数/金额/单价 知二求一
    if ($type === '外部快充') {
        if ($data['amount'] && $data['price'] && !$data['kwh']) $data['kwh'] = round($data['amount'] / $data['price'], 2);
        if ($data['amount'] && $data['kwh'] && !$data['price']) $data['price'] = round($data['amount'] / $data['kwh'], 2);
        if ($data['kwh'] && $data['price'] && !$data['amount']) $data['amount'] = round($data['kwh'] * $data['price'], 2);
    }

    if (!$data['vehicle_id'] || !$data['charge_time'] || $data['mileage'] <= 0) {
        flash('车辆、充电时间、仪表里程必填', 'error');
    } elseif ($type === '外部快充' && !$data['kwh'] && !$data['amount']) {
        flash('外部快充需按电表录入真实度数或金额', 'error');
    } else {
        $receipt = save_upload($_FILES['receipt_photo'] ?? []);
        $mileagePhoto = save_upload($_FILES['mileage_photo'] ?? []);
        if ($id) {
            // 编辑不触发周期重算；若类型被改为满电/从满电改走，提示管理员用删除重录保证周期正确
            $sets = 'vehicle_id=?,charge_time=?,charge_type=?,mileage=?,kwh=?,price=?,amount=?,station=?,driver_name=?,remark=?';
            $vals = array_values($data);
            if ($receipt) { $sets .= ',receipt_photo=?'; $vals[] = $receipt; }
            if ($mileagePhoto) { $sets .= ',mileage_photo=?'; $vals[] = $mileagePhoto; }
            $vals[] = $id;
            $vals[] = $tid;
            exec_sql("UPDATE charge_records SET $sets WHERE id=? AND tenant_id=?", $vals);
            flash('充电记录已更新（注意：编辑不会重算已生成的满电周期）');
            $plate = q1('SELECT plate_no FROM vehicles WHERE id = ?', [$data['vehicle_id']])['plate_no'] ?? '';
            log_op($tid, '后台补录', $u['username'] ?? '管理员', '修改充电记录', "ID {$id}，车牌 {$plate}，类型 {$type}，里程 {$data['mileage']} km");
        } else {
            exec_sql(
                'INSERT INTO charge_records (tenant_id,vehicle_id,charge_time,charge_type,mileage,kwh,price,amount,station,driver_name,remark,receipt_photo,mileage_photo,source) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,\'admin\')',
                array_merge([$tid], array_values($data), [(string)$receipt, (string)$mileagePhoto])
            );
            $newId = (int)last_id();
            flash('充电记录已添加');
            $plate = q1('SELECT plate_no FROM vehicles WHERE id = ?', [$data['vehicle_id']])['plate_no'] ?? '';
            log_op($tid, '后台补录', $u['username'] ?? '管理员', '补录充电记录',
                "车牌 {$plate}，类型 {$type}，里程 {$data['mileage']} km"
                . ($data['kwh'] !== null ? "，度数 {$data['kwh']} 度" : '')
                . ($data['amount'] !== null ? "，金额 {$data['amount']} 元" : ''));
            if ($type === '满电') {
                $res = create_charge_cycle($data['vehicle_id'], $newId);
                flash($res['msg'], $res['ok'] ? 'success' : 'error');
            }
        }
        touch_mileage($data['vehicle_id'], $data['mileage']);
        redirect('charge.php');
    }
    $r = array_merge($r ?? [], $data);
}

$vehicles = q("SELECT id, plate_no, current_mileage, energy_consumption FROM vehicles WHERE tenant_id = ? AND fuel_type = '新能源' ORDER BY plate_no", [$tid]);
$dtValue = !empty($r['charge_time']) ? str_replace(' ', 'T', substr($r['charge_time'], 0, 16)) : date('Y-m-d\TH:i');
$pageTitle = $id ? '编辑充电记录' : '新增充电记录'; $active = 'charge';
require __DIR__ . '/includes/header.php';
?>
<div class="card">
  <h3><?= e($pageTitle) ?></h3>
  <form method="post" enctype="multipart/form-data">
    <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
    <div class="form-grid">
      <div class="form-item"><label>车辆（新能源）<span class="req">*</span></label>
        <select name="vehicle_id" required>
          <option value="">请选择</option>
          <?php foreach ($vehicles as $x): ?><option value="<?= $x['id'] ?>" <?= (int)($r['vehicle_id'] ?? 0) === (int)$x['id'] ? 'selected' : '' ?>><?= e($x['plate_no']) ?>（当前 <?= number_format($x['current_mileage']) ?> km<?= $x['energy_consumption'] ? '，电耗 ' . $x['energy_consumption'] : '，⚠未设电耗' ?>）</option><?php endforeach; ?>
        </select>
      </div>
      <div class="form-item"><label>充电类型 <span class="req">*</span></label>
        <select name="charge_type" id="charge_type" onchange="toggleType()">
          <?php foreach (['满电','零散补电','外部快充'] as $t): ?><option <?= ($r['charge_type'] ?? '满电') === $t ? 'selected' : '' ?>><?= $t ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="form-item"><label>充电时间 <span class="req">*</span></label><input type="datetime-local" name="charge_time" value="<?= e($dtValue) ?>" required></div>
      <div class="form-item"><label>仪表里程(km) <span class="req">*</span></label><input type="number" min="0" name="mileage" value="<?= e((string)($r['mileage'] ?? '')) ?>" required></div>
      <div class="form-item fast-only"><label>真实度数(kWh) <span class="req">*</span></label><input type="number" step="0.01" min="0" name="kwh" value="<?= e((string)($r['kwh'] ?? '')) ?>" placeholder="按电表/充电桩读数"></div>
      <div class="form-item fast-only"><label>单价(元/度)</label><input type="number" step="0.01" min="0" name="price" value="<?= e((string)($r['price'] ?? '')) ?>"></div>
      <div class="form-item fast-only"><label>金额(元)</label><input type="number" step="0.01" min="0" name="amount" value="<?= e((string)($r['amount'] ?? '')) ?>"></div>
      <div class="form-item"><label>充电点</label><input type="text" name="station" value="<?= e($r['station'] ?? '') ?>" placeholder="如 厂区3号桩 / XX快充站"></div>
      <div class="form-item"><label>司机姓名</label><input type="text" name="driver_name" value="<?= e($r['driver_name'] ?? '') ?>"></div>
      <div class="form-item"><label>凭证照片</label>
        <?php if (!empty($r['receipt_photo'])): ?><img class="thumb" src="uploads/<?= e($r['receipt_photo']) ?>"><br><?php endif; ?>
        <input type="file" name="receipt_photo" accept="image/*">
      </div>
      <div class="form-item"><label>仪表里程照片</label>
        <?php if (!empty($r['mileage_photo'])): ?><img class="thumb" src="uploads/<?= e($r['mileage_photo']) ?>"><br><?php endif; ?>
        <input type="file" name="mileage_photo" accept="image/*">
      </div>
      <div class="form-item" style="grid-column:1/-1"><label>备注</label><textarea name="remark" placeholder="零散补电可在此说明，如：临时补电30分钟"><?= e($r['remark'] ?? '') ?></textarea></div>
    </div>
    <p style="color:var(--muted);margin-top:10px;font-size:13px">
      说明：「满电」只需填里程，系统自动与上次满电配对核算周期电量；「零散补电」仅作台账备注不参与核算；「外部快充」按电表真实度数/金额录入并单独计费。
    </p>
    <div style="margin-top:16px">
      <button class="btn" type="submit">保存</button>
      <a class="btn btn-gray" href="charge.php">返回</a>
    </div>
  </form>
</div>
<script>
function toggleType() {
  var isFast = document.getElementById('charge_type').value === '外部快充';
  document.querySelectorAll('.fast-only').forEach(function (el) { el.style.display = isFast ? '' : 'none'; });
}
toggleType();

// 里程防输错：小于该车当前里程时二次确认（补录历史数据可确认通过）
var VMILEAGE = <?= json_encode(array_map('intval', array_column($vehicles, 'current_mileage', 'id'))) ?>;
document.querySelector('form').addEventListener('submit', function (ev) {
  var sel = document.querySelector('select[name=vehicle_id]');
  var m = parseInt(document.querySelector('input[name=mileage]').value || '0', 10);
  var cur = VMILEAGE[sel.value] || 0;
  if (m > 0 && m < cur && !confirm('填写的里程 ' + m.toLocaleString() + ' km 小于该车当前里程 ' + cur.toLocaleString() + ' km。\n如为补录历史记录请点击"确定"，否则请"取消"后核对仪表盘。')) {
    ev.preventDefault();
  }
});
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>
