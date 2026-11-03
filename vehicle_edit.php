<?php
require_once __DIR__ . '/includes/auth.php';
$u = require_manage();

$tid = tid();
$id = (int)($_GET['id'] ?? 0);
$v = $id ? q1('SELECT * FROM vehicles WHERE id = ? AND tenant_id = ?', [$id, $tid]) : null;
if ($id && !$v) { flash('车辆不存在', 'error'); redirect('vehicles.php'); }

// 老库未执行 upgrade_ev.sql 时，vehicles 表没有 energy_consumption 列：降级为不显示、不写入该字段
$hasEC = !db_needs_upgrade();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    require_write();
    $data = [
        'plate_no'  => strtoupper(trim($_POST['plate_no'] ?? '')),
        'brand'     => trim($_POST['brand'] ?? ''),
        'model'     => trim($_POST['model'] ?? ''),
        'color'     => trim($_POST['color'] ?? ''),
        'vin'       => strtoupper(trim($_POST['vin'] ?? '')),
        'engine_no' => trim($_POST['engine_no'] ?? ''),
        'fuel_type' => trim($_POST['fuel_type'] ?? '汽油'),
        'register_date' => $_POST['register_date'] ?: null,
        'purchase_date' => $_POST['purchase_date'] ?: null,
        'current_mileage' => max(0, (int)($_POST['current_mileage'] ?? 0)),
        'remark'    => trim($_POST['remark'] ?? ''),
    ];
    if ($hasEC) {
        $data['energy_consumption'] = ($_POST['energy_consumption'] ?? '') !== '' ? (float)$_POST['energy_consumption'] : null;
    }
    if ($data['plate_no'] === '') {
        flash('车牌号不能为空', 'error');
    } else {
        $dup = q1('SELECT id FROM vehicles WHERE tenant_id = ? AND plate_no = ? AND id != ?', [$tid, $data['plate_no'], $id]);
        if ($dup) {
            flash('该车牌号已存在', 'error');
        } elseif (!$id && !tenant_can_add_vehicle($tid)) {
            $t = current_tenant();
            flash('车辆数已达套餐上限（' . (int)$t['max_vehicles'] . ' 辆），无法继续新增。如需扩容请联系平台方。', 'error');
        } elseif ($id) {
            $ecSet = $hasEC ? ',energy_consumption=?' : '';
            exec_sql("UPDATE vehicles SET plate_no=?,brand=?,model=?,color=?,vin=?,engine_no=?,fuel_type=?,register_date=?,purchase_date=?,current_mileage=?,remark=?$ecSet WHERE id=? AND tenant_id=?",
                array_merge(array_values($data), [$id, $tid]));
            // 补录百公里电耗后，自动重算该车未核算的满电周期
            if ($hasEC && !empty($data['energy_consumption']) && ($n = recalc_cycles($id)) > 0) {
                flash("已按新电耗重算 {$n} 个满电周期");
            }
            flash('车辆信息已更新');
            redirect('vehicles.php');
        } else {
            $ecCol = $hasEC ? ',energy_consumption' : '';
            $ecPh  = $hasEC ? ',?' : '';
            exec_sql("INSERT INTO vehicles (tenant_id,plate_no,brand,model,color,vin,engine_no,fuel_type,register_date,purchase_date,current_mileage,remark{$ecCol},upload_token) VALUES (?,?,?,?,?,?,?,?,?,?,?,?{$ecPh},?)",
                array_merge([$tid], array_values($data), [gen_token()]));
            flash('车辆已添加，可在列表中生成司机扫码二维码');
            redirect('vehicles.php');
        }
    }
    $v = array_merge($v ?? [], $data);
}

$pageTitle = $id ? '编辑车辆' : '新增车辆'; $active = 'vehicles';
require __DIR__ . '/includes/header.php';
?>
<div class="card">
  <h3><?= e($pageTitle) ?></h3>
  <form method="post">
    <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
    <div class="form-grid">
      <div class="form-item"><label>车牌号 <span class="req">*</span></label><input type="text" name="plate_no" value="<?= e($v['plate_no'] ?? '') ?>" required placeholder="如：黑A12345"></div>
      <div class="form-item"><label>品牌</label><input type="text" name="brand" value="<?= e($v['brand'] ?? '') ?>"></div>
      <div class="form-item"><label>型号</label><input type="text" name="model" value="<?= e($v['model'] ?? '') ?>"></div>
      <div class="form-item"><label>颜色</label><input type="text" name="color" value="<?= e($v['color'] ?? '') ?>"></div>
      <div class="form-item"><label>车架号(VIN)</label><input type="text" name="vin" value="<?= e($v['vin'] ?? '') ?>"></div>
      <div class="form-item"><label>发动机号</label><input type="text" name="engine_no" value="<?= e($v['engine_no'] ?? '') ?>"></div>
      <div class="form-item"><label>燃油类型</label>
        <select name="fuel_type">
          <?php foreach (['汽油','柴油','新能源','CNG','LNG'] as $f): ?>
          <option <?= ($v['fuel_type'] ?? '汽油') === $f ? 'selected' : '' ?>><?= $f ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php if ($hasEC): ?>
      <div class="form-item"><label>百公里电耗(kWh/100km，新能源必填)</label><input type="number" step="0.1" min="0" name="energy_consumption" value="<?= e((string)($v['energy_consumption'] ?? '')) ?>" placeholder="如 18.5，用于满电周期电量核算"></div>
      <?php endif; ?>
      <div class="form-item"><label>注册登记日期</label><input type="date" name="register_date" value="<?= e($v['register_date'] ?? '') ?>"></div>
      <div class="form-item"><label>购置日期</label><input type="date" name="purchase_date" value="<?= e($v['purchase_date'] ?? '') ?>"></div>
      <div class="form-item"><label>当前里程(km)</label><input type="number" name="current_mileage" min="0" value="<?= e((string)($v['current_mileage'] ?? 0)) ?>"></div>
      <div class="form-item" style="grid-column:1/-1"><label>备注</label><textarea name="remark"><?= e($v['remark'] ?? '') ?></textarea></div>
    </div>
    <div style="margin-top:16px">
      <button class="btn" type="submit">保存</button>
      <a class="btn btn-gray" href="vehicles.php">返回</a>
    </div>
  </form>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
