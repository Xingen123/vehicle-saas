<?php
require_once __DIR__ . '/includes/auth.php';
$u = require_manage();

$tid = tid();
$id = (int)($_GET['id'] ?? 0);
$r = $id ? q1('SELECT * FROM maintenance_records WHERE id = ? AND tenant_id = ?', [$id, $tid]) : null;
if ($id && !$r) { flash('记录不存在', 'error'); redirect('maintenance.php'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    require_write();
    $data = [
        'vehicle_id'   => (int)($_POST['vehicle_id'] ?? 0),
        'mt_date'      => $_POST['mt_date'] ?? '',
        'mileage'      => max(0, (int)($_POST['mileage'] ?? 0)),
        'items'        => trim($_POST['items'] ?? ''),
        'cost'         => (float)($_POST['cost'] ?? 0),
        'vendor'       => trim($_POST['vendor'] ?? ''),
        'next_date'    => $_POST['next_date'] ?: null,
        'next_mileage' => $_POST['next_mileage'] !== '' ? (int)$_POST['next_mileage'] : null,
        'remark'       => trim($_POST['remark'] ?? ''),
    ];
    if (!$data['vehicle_id'] || !$data['mt_date'] || $data['items'] === '') {
        flash('车辆、保养日期、保养项目必填', 'error');
    } else {
        if ($id) {
            exec_sql('UPDATE maintenance_records SET vehicle_id=?,mt_date=?,mileage=?,items=?,cost=?,vendor=?,next_date=?,next_mileage=?,remark=? WHERE id=? AND tenant_id=?',
                array_merge(array_values($data), [$id, $tid]));
            flash('保养记录已更新');
        } else {
            exec_sql('INSERT INTO maintenance_records (tenant_id,vehicle_id,mt_date,mileage,items,cost,vendor,next_date,next_mileage,remark,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?)',
                array_merge([$tid], array_values($data), [$u['id']]));
            flash('保养记录已添加');
        }
        touch_mileage($data['vehicle_id'], $data['mileage']);
        redirect('maintenance.php');
    }
    $r = array_merge($r ?? [], $data);
}

$vehicles = q('SELECT id, plate_no, current_mileage FROM vehicles WHERE tenant_id = ? ORDER BY plate_no', [$tid]);
$pageTitle = $id ? '编辑保养记录' : '新增保养记录'; $active = 'maintenance';
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
          <?php foreach ($vehicles as $x): ?><option value="<?= $x['id'] ?>" <?= (int)($r['vehicle_id'] ?? 0) === (int)$x['id'] ? 'selected' : '' ?>><?= e($x['plate_no']) ?>（当前 <?= number_format($x['current_mileage']) ?> km）</option><?php endforeach; ?>
        </select>
      </div>
      <div class="form-item"><label>保养日期 <span class="req">*</span></label><input type="date" name="mt_date" value="<?= e($r['mt_date'] ?? date('Y-m-d')) ?>" required></div>
      <div class="form-item"><label>保养时里程(km)</label><input type="number" min="0" name="mileage" value="<?= e((string)($r['mileage'] ?? '')) ?>"></div>
      <div class="form-item"><label>保养项目 <span class="req">*</span></label><input type="text" name="items" value="<?= e($r['items'] ?? '') ?>" placeholder="如：更换机油、机滤" required></div>
      <div class="form-item"><label>费用(元)</label><input type="number" step="0.01" min="0" name="cost" value="<?= e((string)($r['cost'] ?? '')) ?>"></div>
      <div class="form-item"><label>保养单位</label><input type="text" name="vendor" value="<?= e($r['vendor'] ?? '') ?>"></div>
      <div class="form-item"><label>下次保养日期</label><input type="date" name="next_date" value="<?= e((string)($r['next_date'] ?? '')) ?>"></div>
      <div class="form-item"><label>下次保养里程(km)</label><input type="number" min="0" name="next_mileage" value="<?= e((string)($r['next_mileage'] ?? '')) ?>" placeholder="到达该里程前提醒"></div>
      <div class="form-item" style="grid-column:1/-1"><label>备注</label><textarea name="remark"><?= e($r['remark'] ?? '') ?></textarea></div>
    </div>
    <p style="color:var(--muted);margin-top:10px;font-size:13px">提示：填写"下次保养日期"或"下次保养里程"后，系统会在临近时自动提醒并发送邮件。</p>
    <div style="margin-top:16px">
      <button class="btn" type="submit">保存</button>
      <a class="btn btn-gray" href="maintenance.php">返回</a>
    </div>
  </form>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
