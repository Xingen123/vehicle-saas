<?php
require_once __DIR__ . '/includes/auth.php';
$u = require_login();

$tid = tid();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check(); require_admin(); require_write();
    exec_sql('DELETE FROM insurance_records WHERE id = ? AND tenant_id = ?', [(int)$_POST['id'], $tid]);
    flash('保险记录已删除');
    redirect('insurance.php');
}

$vid = (int)($_GET['vehicle_id'] ?? 0);
$where = 'WHERE i.tenant_id = ?';
$params = [$tid];
if ($vid) { $where .= ' AND i.vehicle_id = ?'; $params[] = $vid; }
$total = (int)(q1("SELECT COUNT(*) c FROM insurance_records i $where", $params)['c'] ?? 0);
[$page, $pages, $off, $size] = paginate($total, (int)($_GET['page'] ?? 1));
$list = q("SELECT i.*, v.plate_no FROM insurance_records i JOIN vehicles v ON v.id=i.vehicle_id $where ORDER BY i.end_date DESC LIMIT $size OFFSET $off", $params);
$vehicles = q('SELECT id, plate_no FROM vehicles WHERE tenant_id = ? ORDER BY plate_no', [$tid]);
$today = date('Y-m-d');

$pageTitle = '保险记录'; $active = 'insurance';
require __DIR__ . '/includes/header.php';
?>
<div class="card">
  <div class="toolbar">
    <?php if (can_manage()): ?><a class="btn" href="insurance_edit.php">＋ 新增保险记录</a><?php endif; ?>
    <form method="get">
      <select name="vehicle_id">
        <option value="0">全部车辆</option>
        <?php foreach ($vehicles as $x): ?><option value="<?= $x['id'] ?>" <?= $vid === (int)$x['id'] ? 'selected' : '' ?>><?= e($x['plate_no']) ?></option><?php endforeach; ?>
      </select>
      <button class="btn btn-gray">筛选</button>
    </form>
  </div>
  <div class="tbl-wrap"><table class="tbl">
    <tr><th>车牌</th><th>险种</th><th>保险公司</th><th>保单号</th><th>保险期间</th><th>保费(元)</th><th>状态</th><th>操作</th></tr>
    <?php foreach ($list as $r):
      $days = (int)((strtotime($r['end_date']) - strtotime($today)) / 86400);
    ?>
    <tr>
      <td><b><?= e($r['plate_no']) ?></b></td>
      <td><?= e($r['ins_type']) ?></td>
      <td><?= e($r['company']) ?></td>
      <td><?= e($r['policy_no']) ?></td>
      <td><?= e($r['start_date']) ?> ~ <?= e($r['end_date']) ?></td>
      <td><?= fmt_money($r['amount']) ?></td>
      <td>
        <?php if ($days < 0): ?><span class="tag tag-red">已过期 <?= -$days ?> 天</span>
        <?php elseif ($days <= 30): ?><span class="tag tag-orange">剩 <?= $days ?> 天</span>
        <?php else: ?><span class="tag tag-green">有效</span><?php endif; ?>
      </td>
      <td>
        <?php if (can_manage()): ?><a class="btn btn-gray btn-sm" href="insurance_edit.php?id=<?= $r['id'] ?>">编辑</a><?php endif; ?>
        <?php if ($u['role'] === 'admin'): ?>
        <form method="post" style="display:inline" onsubmit="return confirm('确认删除该保险记录？')">
          <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
          <input type="hidden" name="id" value="<?= $r['id'] ?>">
          <button class="btn btn-danger btn-sm">删除</button>
        </form>
        <?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
    <?php if (!$list): ?><tr><td colspan="8" style="color:var(--muted)">暂无保险记录</td></tr><?php endif; ?>
  </table></div>
  <div class="pager"><?php for ($i = 1; $i <= $pages; $i++): ?><?= $i === $page ? "<span class=\"cur\">$i</span>" : "<a href=\"?page=$i&vehicle_id=$vid\">$i</a>" ?><?php endfor; ?></div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
