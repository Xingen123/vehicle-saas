<?php
require_once __DIR__ . '/includes/auth.php';
$u = require_manage();

$tid = tid();
$id = (int)($_GET['id'] ?? 0);
$r = $id ? q1('SELECT * FROM insurance_records WHERE id = ? AND tenant_id = ?', [$id, $tid]) : null;
if ($id && !$r) { flash('记录不存在', 'error'); redirect('insurance.php'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    require_write();
    $data = [
        'vehicle_id' => (int)($_POST['vehicle_id'] ?? 0),
        'ins_type'   => $_POST['ins_type'] ?? '交强险',
        'company'    => trim($_POST['company'] ?? ''),
        'policy_no'  => trim($_POST['policy_no'] ?? ''),
        'start_date' => $_POST['start_date'] ?? '',
        'end_date'   => $_POST['end_date'] ?? '',
        'amount'     => (float)($_POST['amount'] ?? 0),
        'remark'     => trim($_POST['remark'] ?? ''),
    ];
    if (!$data['vehicle_id'] || !$data['start_date'] || !$data['end_date']) {
        flash('车辆、保险起期、止期必填', 'error');
    } elseif ($id) {
        exec_sql('UPDATE insurance_records SET vehicle_id=?,ins_type=?,company=?,policy_no=?,start_date=?,end_date=?,amount=?,remark=? WHERE id=? AND tenant_id=?',
            array_merge(array_values($data), [$id, $tid]));
        flash('保险记录已更新'); redirect('insurance.php');
    } else {
        exec_sql('INSERT INTO insurance_records (tenant_id,vehicle_id,ins_type,company,policy_no,start_date,end_date,amount,remark,created_by) VALUES (?,?,?,?,?,?,?,?,?,?)',
            array_merge([$tid], array_values($data), [$u['id']]));
        flash('保险记录已添加'); redirect('insurance.php');
    }
    $r = array_merge($r ?? [], $data);
}

$vehicles = q('SELECT id, plate_no FROM vehicles WHERE tenant_id = ? ORDER BY plate_no', [$tid]);
$pageTitle = $id ? '编辑保险记录' : '新增保险记录'; $active = 'insurance';
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
      <div class="form-item"><label>险种</label>
        <select name="ins_type">
          <?php foreach (['交强险','商业险','承运人责任险','其他'] as $t): ?><option <?= ($r['ins_type'] ?? '') === $t ? 'selected' : '' ?>><?= $t ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="form-item"><label>保险公司</label><input type="text" name="company" value="<?= e($r['company'] ?? '') ?>"></div>
      <div class="form-item"><label>保单号</label><input type="text" name="policy_no" value="<?= e($r['policy_no'] ?? '') ?>"></div>
      <div class="form-item"><label>保险起期 <span class="req">*</span></label><input type="date" name="start_date" value="<?= e($r['start_date'] ?? '') ?>" required></div>
      <div class="form-item"><label>保险止期 <span class="req">*</span></label><input type="date" name="end_date" value="<?= e($r['end_date'] ?? '') ?>" required></div>
      <div class="form-item"><label>保费(元)</label><input type="number" step="0.01" min="0" name="amount" value="<?= e((string)($r['amount'] ?? '')) ?>"></div>
      <div class="form-item" style="grid-column:1/-1"><label>备注</label><textarea name="remark"><?= e($r['remark'] ?? '') ?></textarea></div>
    </div>
    <div style="margin-top:16px">
      <button class="btn" type="submit">保存</button>
      <a class="btn btn-gray" href="insurance.php">返回</a>
    </div>
  </form>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
