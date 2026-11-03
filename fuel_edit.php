<?php
require_once __DIR__ . '/includes/auth.php';
$u = require_manage();

$tid = tid();
$id = (int)($_GET['id'] ?? 0);
$r = $id ? q1('SELECT * FROM fuel_records WHERE id = ? AND tenant_id = ?', [$id, $tid]) : null;
if ($id && !$r) { flash('记录不存在', 'error'); redirect('fuel.php'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    require_write();
    $data = [
        'vehicle_id' => (int)($_POST['vehicle_id'] ?? 0),
        'fuel_date'  => str_replace('T', ' ', ($_POST['fuel_date'] ?? '')) . (strlen($_POST['fuel_date'] ?? '') === 16 ? ':00' : ''),
        'mileage'    => max(0, (int)($_POST['mileage'] ?? 0)),
        'amount'     => (float)($_POST['amount'] ?? 0),
        'price'      => $_POST['price'] !== '' ? (float)$_POST['price'] : null,
        'liters'     => $_POST['liters'] !== '' ? (float)$_POST['liters'] : null,
        'station'    => trim($_POST['station'] ?? ''),
        'driver_name'=> trim($_POST['driver_name'] ?? ''),
        'remark'     => trim($_POST['remark'] ?? ''),
    ];
    // 自动补算：金额/单价/油量 知二求一
    if ($data['amount'] > 0 && $data['price'] && !$data['liters']) $data['liters'] = round($data['amount'] / $data['price'], 2);
    if ($data['amount'] > 0 && $data['liters'] && !$data['price']) $data['price'] = round($data['amount'] / $data['liters'], 2);

    if (!$data['vehicle_id'] || !$data['fuel_date'] || $data['amount'] <= 0) {
        flash('车辆、加油时间、金额必填', 'error');
    } else {
        $receipt = save_upload($_FILES['receipt_photo'] ?? []);
        $mileagePhoto = save_upload($_FILES['mileage_photo'] ?? []);
        if ($id) {
            $sets = 'vehicle_id=?,fuel_date=?,mileage=?,amount=?,price=?,liters=?,station=?,driver_name=?,remark=?';
            $vals = array_values($data);
            if ($receipt) { $sets .= ',receipt_photo=?'; $vals[] = $receipt; }
            if ($mileagePhoto) { $sets .= ',mileage_photo=?'; $vals[] = $mileagePhoto; }
            $vals[] = $id;
            $vals[] = $tid;
            exec_sql("UPDATE fuel_records SET $sets WHERE id=? AND tenant_id=?", $vals);
            flash('加油记录已更新');
            $plate = q1('SELECT plate_no FROM vehicles WHERE id = ?', [$data['vehicle_id']])['plate_no'] ?? '';
            log_op($tid, '后台补录', $u['username'] ?? '管理员', '修改加油记录', "ID {$id}，车牌 {$plate}，金额 {$data['amount']} 元，里程 {$data['mileage']} km");
        } else {
            exec_sql('INSERT INTO fuel_records (tenant_id,vehicle_id,fuel_date,mileage,amount,price,liters,station,driver_name,remark,receipt_photo,mileage_photo,source) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,\'admin\')',
                array_merge([$tid], array_values($data), [(string)$receipt, (string)$mileagePhoto]));
            flash('加油记录已添加');
            $plate = q1('SELECT plate_no FROM vehicles WHERE id = ?', [$data['vehicle_id']])['plate_no'] ?? '';
            log_op($tid, '后台补录', $u['username'] ?? '管理员', '补录加油记录', "车牌 {$plate}，金额 {$data['amount']} 元，里程 {$data['mileage']} km，时间 {$data['fuel_date']}");
        }
        touch_mileage($data['vehicle_id'], $data['mileage']);
        redirect('fuel.php');
    }
    $r = array_merge($r ?? [], $data);
}

$vehicles = q('SELECT id, plate_no, current_mileage FROM vehicles WHERE tenant_id = ? ORDER BY plate_no', [$tid]);
$dtValue = !empty($r['fuel_date']) ? str_replace(' ', 'T', substr($r['fuel_date'], 0, 16)) : date('Y-m-d\TH:i');
$pageTitle = $id ? '编辑加油记录' : '补录加油记录'; $active = 'fuel';
require __DIR__ . '/includes/header.php';
?>
<div class="card">
  <h3><?= e($pageTitle) ?></h3>
  <form method="post" enctype="multipart/form-data">
    <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
    <div class="form-grid">
      <div class="form-item"><label>车辆 <span class="req">*</span></label>
        <select name="vehicle_id" required>
          <option value="">请选择</option>
          <?php foreach ($vehicles as $x): ?><option value="<?= $x['id'] ?>" <?= (int)($r['vehicle_id'] ?? 0) === (int)$x['id'] ? 'selected' : '' ?>><?= e($x['plate_no']) ?>（当前 <?= number_format($x['current_mileage']) ?> km）</option><?php endforeach; ?>
        </select>
      </div>
      <div class="form-item"><label>加油时间 <span class="req">*</span></label><input type="datetime-local" name="fuel_date" value="<?= e($dtValue) ?>" required></div>
      <div class="form-item"><label>加油金额(元) <span class="req">*</span></label><input type="number" step="0.01" min="0" name="amount" value="<?= e((string)($r['amount'] ?? '')) ?>" required></div>
      <div class="form-item"><label>单价(元/升)</label><input type="number" step="0.01" min="0" name="price" value="<?= e((string)($r['price'] ?? '')) ?>"></div>
      <div class="form-item"><label>油量(升)</label><input type="number" step="0.01" min="0" name="liters" value="<?= e((string)($r['liters'] ?? '')) ?>"></div>
      <div class="form-item"><label>仪表里程(km)</label><input type="number" min="0" name="mileage" value="<?= e((string)($r['mileage'] ?? '')) ?>"></div>
      <div class="form-item"><label>加油站</label><input type="text" name="station" value="<?= e($r['station'] ?? '') ?>"></div>
      <div class="form-item"><label>司机姓名</label><input type="text" name="driver_name" value="<?= e($r['driver_name'] ?? '') ?>"></div>
      <div class="form-item"><label>金额凭证照片</label>
        <?php if (!empty($r['receipt_photo'])): ?><img class="thumb" src="uploads/<?= e($r['receipt_photo']) ?>"><br><?php endif; ?>
        <input type="file" name="receipt_photo" accept="image/*">
      </div>
      <div class="form-item"><label>仪表里程照片</label>
        <?php if (!empty($r['mileage_photo'])): ?><img class="thumb" src="uploads/<?= e($r['mileage_photo']) ?>"><br><?php endif; ?>
        <input type="file" name="mileage_photo" accept="image/*">
      </div>
      <div class="form-item" style="grid-column:1/-1"><label>备注</label><textarea name="remark"><?= e($r['remark'] ?? '') ?></textarea></div>
    </div>
    <div style="margin-top:16px">
      <button class="btn" type="submit">保存</button>
      <a class="btn btn-gray" href="fuel.php">返回</a>
    </div>
  </form>
</div>
<script>
// 里程防输错：小于该车当前里程时二次确认（补录历史数据可确认通过）
var VMILEAGE = <?= json_encode(array_map('intval', array_column($vehicles, 'current_mileage', 'id'))) ?>;
document.querySelector('form').addEventListener('submit', function (ev) {
  var sel = document.querySelector('select[name=vehicle_id]');
  var mInput = document.querySelector('input[name=mileage]');
  var m = parseInt(mInput.value || '0', 10);
  var cur = VMILEAGE[sel.value] || 0;
  if (m > 0 && m < cur && !confirm('填写的里程 ' + m.toLocaleString() + ' km 小于该车当前里程 ' + cur.toLocaleString() + ' km。\n如为补录历史记录请点击"确定"，否则请"取消"后核对仪表盘。')) {
    ev.preventDefault();
  }
});
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>
