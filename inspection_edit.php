<?php
require_once __DIR__ . '/includes/auth.php';
$u = require_manage();

$tid = tid();
$id = (int)($_GET['id'] ?? 0);
$r = $id ? q1('SELECT * FROM inspection_records WHERE id = ? AND tenant_id = ?', [$id, $tid]) : null;
if ($id && !$r) { flash('记录不存在', 'error'); redirect('inspection.php'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    require_write();
    $data = [
        'vehicle_id'    => (int)($_POST['vehicle_id'] ?? 0),
        'inspect_date'  => $_POST['inspect_date'] ?? '',
        'next_due_date' => $_POST['next_due_date'] ?? '',
        'result'        => $_POST['result'] ?? '合格',
        'station'       => trim($_POST['station'] ?? ''),
        'amount'        => (float)($_POST['amount'] ?? 0),
        'remark'        => trim($_POST['remark'] ?? ''),
    ];
    if (!$data['vehicle_id'] || !$data['inspect_date'] || !$data['next_due_date']) {
        flash('车辆、检车日期、下次到期日必填', 'error');
    } elseif ($id) {
        exec_sql('UPDATE inspection_records SET vehicle_id=?,inspect_date=?,next_due_date=?,result=?,station=?,amount=?,remark=? WHERE id=? AND tenant_id=?',
            array_merge(array_values($data), [$id, $tid]));
        flash('检车记录已更新'); redirect('inspection.php');
    } else {
        exec_sql('INSERT INTO inspection_records (tenant_id,vehicle_id,inspect_date,next_due_date,result,station,amount,remark,created_by) VALUES (?,?,?,?,?,?,?,?,?)',
            array_merge([$tid], array_values($data), [$u['id']]));
        flash('检车记录已添加'); redirect('inspection.php');
    }
    $r = array_merge($r ?? [], $data);
}

$vehicles = q('SELECT id, plate_no FROM vehicles WHERE tenant_id = ? ORDER BY plate_no', [$tid]);
$pageTitle = $id ? '编辑检车记录' : '新增检车记录'; $active = 'inspection';
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
      <div class="form-item"><label>检车日期 <span class="req">*</span></label><input type="date" name="inspect_date" value="<?= e($r['inspect_date'] ?? date('Y-m-d')) ?>" required></div>
      <div class="form-item"><label>下次检车到期日 <span class="req">*</span></label><input type="date" name="next_due_date" value="<?= e($r['next_due_date'] ?? '') ?>" required></div>
      <div class="form-item"><label>检车结果</label>
        <select name="result">
          <option <?= ($r['result'] ?? '合格') === '合格' ? 'selected' : '' ?>>合格</option>
          <option <?= ($r['result'] ?? '') === '不合格' ? 'selected' : '' ?>>不合格</option>
        </select>
      </div>
      <div class="form-item"><label>检测站</label><input type="text" name="station" value="<?= e($r['station'] ?? '') ?>"></div>
      <div class="form-item"><label>费用(元)</label><input type="number" step="0.01" min="0" name="amount" value="<?= e((string)($r['amount'] ?? '')) ?>"></div>
      <div class="form-item" style="grid-column:1/-1"><label>备注</label><textarea name="remark"><?= e($r['remark'] ?? '') ?></textarea></div>
    </div>
    <div style="margin-top:16px">
      <button class="btn" type="submit">保存</button>
      <a class="btn btn-gray" href="inspection.php">返回</a>
    </div>
  </form>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
