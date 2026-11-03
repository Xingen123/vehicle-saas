<?php
require_once __DIR__ . '/includes/auth.php';
$u = require_manage();

$tid = tid();
$id = (int)($_GET['id'] ?? 0);
$r = $id ? q1('SELECT * FROM repair_records WHERE id = ? AND tenant_id = ?', [$id, $tid]) : null;
if ($id && !$r) { flash('记录不存在', 'error'); redirect('repair.php'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    require_write();
    $data = [
        'vehicle_id'  => (int)($_POST['vehicle_id'] ?? 0),
        'report_date' => $_POST['report_date'] ?? '',
        'repair_date' => $_POST['repair_date'] ?: null,
        'mileage'     => $_POST['mileage'] !== '' ? (int)$_POST['mileage'] : null,
        'title'       => trim($_POST['title'] ?? ''),
        'description' => trim($_POST['description'] ?? ''),
        'cost'        => (float)($_POST['cost'] ?? 0),
        'vendor'      => trim($_POST['vendor'] ?? ''),
        'status'      => in_array($_POST['status'] ?? '', ['待处理','维修中','已完成'], true) ? $_POST['status'] : '待处理',
        'remark'      => trim($_POST['remark'] ?? ''),
    ];
    if (!$data['vehicle_id'] || !$data['report_date'] || $data['title'] === '') {
        flash('车辆、报修日期、维修项目必填', 'error');
    } else {
        if ($id) {
            exec_sql('UPDATE repair_records SET vehicle_id=?,report_date=?,repair_date=?,mileage=?,title=?,description=?,cost=?,vendor=?,status=?,remark=? WHERE id=? AND tenant_id=?',
                array_merge(array_values($data), [$id, $tid]));
            flash('维修记录已更新');
        } else {
            exec_sql('INSERT INTO repair_records (tenant_id,vehicle_id,report_date,repair_date,mileage,title,description,cost,vendor,status,remark,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)',
                array_merge([$tid], array_values($data), [$u['id']]));
            flash('报修已登记');
        }
        // 车辆状态联动
        if ($data['status'] === '维修中') {
            exec_sql("UPDATE vehicles SET status='维修中' WHERE id=? AND tenant_id=?", [$data['vehicle_id'], $tid]);
        }
        if ($data['mileage']) touch_mileage($data['vehicle_id'], $data['mileage']);
        redirect('repair.php');
    }
    $r = array_merge($r ?? [], $data);
}

$vehicles = q('SELECT id, plate_no FROM vehicles WHERE tenant_id = ? ORDER BY plate_no', [$tid]);
$pageTitle = $id ? '编辑维修记录' : '新增报修'; $active = 'repair';
require __DIR__ . '/includes/header.php';
?>
<div class="card">
  <h3><?= e($pageTitle) ?></h3>
  <form method="post">
    <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
    <div class="form-grid">
      <div class="form-item"><label>车辆 <span class="req">*</span></label>
        <select name="vehicle_id" required>
          <option value="">请选择</option>
          <?php foreach ($vehicles as $x): ?><option value="<?= $x['id'] ?>" <?= (int)($r['vehicle_id'] ?? 0) === (int)$x['id'] ? 'selected' : '' ?>><?= e($x['plate_no']) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="form-item"><label>维修项目 <span class="req">*</span></label><input type="text" name="title" value="<?= e($r['title'] ?? '') ?>" placeholder="如：更换刹车片" required></div>
      <div class="form-item"><label>报修日期 <span class="req">*</span></label><input type="date" name="report_date" value="<?= e($r['report_date'] ?? date('Y-m-d')) ?>" required></div>
      <div class="form-item"><label>完成日期</label><input type="date" name="repair_date" value="<?= e((string)($r['repair_date'] ?? '')) ?>"></div>
      <div class="form-item"><label>维修时里程(km)</label><input type="number" min="0" name="mileage" value="<?= e((string)($r['mileage'] ?? '')) ?>"></div>
      <div class="form-item"><label>维修费用(元)</label><input type="number" step="0.01" min="0" name="cost" value="<?= e((string)($r['cost'] ?? '')) ?>"></div>
      <div class="form-item"><label>维修厂</label><input type="text" name="vendor" value="<?= e($r['vendor'] ?? '') ?>"></div>
      <div class="form-item"><label>状态</label>
        <select name="status">
          <?php foreach (['待处理','维修中','已完成'] as $s): ?><option <?= ($r['status'] ?? '待处理') === $s ? 'selected' : '' ?>><?= $s ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="form-item" style="grid-column:1/-1"><label>故障描述</label><textarea name="description"><?= e($r['description'] ?? '') ?></textarea></div>
      <div class="form-item" style="grid-column:1/-1"><label>备注</label><textarea name="remark"><?= e($r['remark'] ?? '') ?></textarea></div>
    </div>
    <div style="margin-top:16px">
      <button class="btn" type="submit">保存</button>
      <a class="btn btn-gray" href="repair.php">返回</a>
    </div>
  </form>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
