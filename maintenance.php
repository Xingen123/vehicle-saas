<?php
require_once __DIR__ . '/includes/auth.php';
$u = require_login();

$tid = tid();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check(); require_admin(); require_write();
    exec_sql('DELETE FROM maintenance_records WHERE id = ? AND tenant_id = ?', [(int)$_POST['id'], $tid]);
    flash('保养记录已删除');
    redirect('maintenance.php');
}

$vid = (int)($_GET['vehicle_id'] ?? 0);
$where = 'WHERE m.tenant_id = ?';
$params = [$tid];
if ($vid) { $where .= ' AND m.vehicle_id = ?'; $params[] = $vid; }
$total = (int)(q1("SELECT COUNT(*) c FROM maintenance_records m $where", $params)['c'] ?? 0);
[$page, $pages, $off, $size] = paginate($total, (int)($_GET['page'] ?? 1));
$list = q("SELECT m.*, v.plate_no, v.current_mileage FROM maintenance_records m JOIN vehicles v ON v.id=m.vehicle_id $where ORDER BY m.mt_date DESC, m.id DESC LIMIT $size OFFSET $off", $params);
$vehicles = q('SELECT id, plate_no FROM vehicles WHERE tenant_id = ? ORDER BY plate_no', [$tid]);
$today = date('Y-m-d');

$pageTitle = '保养记录'; $active = 'maintenance';
require __DIR__ . '/includes/header.php';
?>
<div class="card">
  <div class="toolbar">
    <?php if (can_manage()): ?><a class="btn" href="maintenance_edit.php">＋ 新增保养记录</a><?php endif; ?>
    <form method="get">
      <select name="vehicle_id">
        <option value="0">全部车辆</option>
        <?php foreach ($vehicles as $x): ?><option value="<?= $x['id'] ?>" <?= $vid === (int)$x['id'] ? 'selected' : '' ?>><?= e($x['plate_no']) ?></option><?php endforeach; ?>
      </select>
      <button class="btn btn-gray">筛选</button>
    </form>
  </div>
  <div class="tbl-wrap"><table class="tbl">
    <tr><th>车牌</th><th>保养日期</th><th>保养里程</th><th>保养项目</th><th>费用(元)</th><th>下次日期</th><th>下次里程</th><th>提醒状态</th><th>操作</th></tr>
    <?php foreach ($list as $r):
      $warn = '';
      if ($r['next_date']) {
        $d = (int)((strtotime($r['next_date']) - strtotime($today)) / 86400);
        if ($d < 0) $warn = '<span class="tag tag-red">日期已过 ' . (-$d) . ' 天</span>';
        elseif ($d <= 15) $warn = '<span class="tag tag-orange">剩 ' . $d . ' 天</span>';
      }
      if (!$warn && $r['next_mileage']) {
        $left = (int)$r['next_mileage'] - (int)$r['current_mileage'];
        if ($left <= 500) $warn = '<span class="tag tag-orange">里程剩 ' . $left . ' km</span>';
      }
      if (!$warn) $warn = '<span class="tag tag-green">正常</span>';
    ?>
    <tr>
      <td><b><?= e($r['plate_no']) ?></b></td>
      <td><?= e($r['mt_date']) ?></td>
      <td><?= number_format($r['mileage']) ?> km</td>
      <td style="white-space:normal;max-width:200px"><?= e($r['items']) ?></td>
      <td><?= fmt_money($r['cost']) ?></td>
      <td><?= e((string)$r['next_date']) ?></td>
      <td><?= $r['next_mileage'] ? number_format($r['next_mileage']) . ' km' : '' ?></td>
      <td><?= $warn ?></td>
      <td>
        <?php if (can_manage()): ?><a class="btn btn-gray btn-sm" href="maintenance_edit.php?id=<?= $r['id'] ?>">编辑</a><?php endif; ?>
        <?php if ($u['role'] === 'admin'): ?>
        <form method="post" style="display:inline" onsubmit="return confirm('确认删除该保养记录？')">
          <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
          <input type="hidden" name="id" value="<?= $r['id'] ?>">
          <button class="btn btn-danger btn-sm">删除</button>
        </form>
        <?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
    <?php if (!$list): ?><tr><td colspan="9" style="color:var(--muted)">暂无保养记录</td></tr><?php endif; ?>
  </table></div>
  <div class="pager"><?php for ($i = 1; $i <= $pages; $i++): ?><?= $i === $page ? "<span class=\"cur\">$i</span>" : "<a href=\"?page=$i&vehicle_id=$vid\">$i</a>" ?><?php endfor; ?></div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
