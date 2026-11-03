<?php
require_once __DIR__ . '/includes/auth.php';
$u = require_login();

$tid = tid();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    require_write();
    $act = $_POST['act'] ?? '';
    $id = (int)($_POST['id'] ?? 0);
    if ($act === 'delete') {
        require_admin();
        exec_sql('DELETE FROM repair_records WHERE id = ? AND tenant_id = ?', [$id, $tid]);
        flash('维修记录已删除');
    } elseif ($act === 'status' && can_manage()) {
        $status = $_POST['status'] ?? '';
        if (in_array($status, ['待处理', '维修中', '已完成'], true)) {
            exec_sql('UPDATE repair_records SET status = ?' . ($status === '已完成' ? ', repair_date = COALESCE(repair_date, CURDATE())' : '') . ' WHERE id = ? AND tenant_id = ?', [$status, $id, $tid]);
            // 状态联动车辆
            if ($status === '已完成') {
                exec_sql("UPDATE vehicles v JOIN repair_records r ON r.vehicle_id=v.id AND r.id=? AND r.tenant_id=? SET v.status='在用' WHERE v.status='维修中'", [$id, $tid]);
            } else {
                exec_sql('UPDATE vehicles v JOIN repair_records r ON r.vehicle_id=v.id AND r.id=? AND r.tenant_id=? SET v.status=\'维修中\'', [$id, $tid]);
            }
            flash('维修状态已更新');
        }
    }
    redirect('repair.php');
}

$vid = (int)($_GET['vehicle_id'] ?? 0);
$st  = $_GET['status'] ?? '';
$where = ['r.tenant_id = ?']; $params = [$tid];
if ($vid) { $where[] = 'r.vehicle_id = ?'; $params[] = $vid; }
if (in_array($st, ['待处理', '维修中', '已完成'], true)) { $where[] = 'r.status = ?'; $params[] = $st; }
$whereSql = 'WHERE ' . implode(' AND ', $where);

$total = (int)(q1("SELECT COUNT(*) c FROM repair_records r $whereSql", $params)['c'] ?? 0);
$sum = q1("SELECT COALESCE(SUM(cost),0) s FROM repair_records r $whereSql", $params);
[$page, $pages, $off, $size] = paginate($total, (int)($_GET['page'] ?? 1));
$list = q("SELECT r.*, v.plate_no FROM repair_records r JOIN vehicles v ON v.id=r.vehicle_id $whereSql ORDER BY FIELD(r.status,'待处理','维修中','已完成'), r.report_date DESC LIMIT $size OFFSET $off", $params);
$vehicles = q('SELECT id, plate_no FROM vehicles WHERE tenant_id = ? ORDER BY plate_no', [$tid]);
$statusTag = ['待处理' => 'tag-red', '维修中' => 'tag-orange', '已完成' => 'tag-green'];

$pageTitle = '维修管理'; $active = 'repair';
require __DIR__ . '/includes/header.php';
?>
<div class="card">
  <div class="toolbar">
    <?php if (can_manage()): ?><a class="btn" href="repair_edit.php">＋ 新增报修</a><?php endif; ?>
    <form method="get">
      <select name="vehicle_id">
        <option value="0">全部车辆</option>
        <?php foreach ($vehicles as $x): ?><option value="<?= $x['id'] ?>" <?= $vid === (int)$x['id'] ? 'selected' : '' ?>><?= e($x['plate_no']) ?></option><?php endforeach; ?>
      </select>
      <select name="status">
        <option value="">全部状态</option>
        <?php foreach (['待处理','维修中','已完成'] as $s): ?><option <?= $st === $s ? 'selected' : '' ?>><?= $s ?></option><?php endforeach; ?>
      </select>
      <button class="btn btn-gray">筛选</button>
    </form>
    <span style="color:var(--muted)">合计维修费：¥<?= fmt_money($sum['s']) ?>，共 <?= $total ?> 条</span>
  </div>
  <div class="tbl-wrap"><table class="tbl">
    <tr><th>车牌</th><th>维修项目</th><th>报修日期</th><th>完成日期</th><th>费用(元)</th><th>维修厂</th><th>状态</th><th>操作</th></tr>
    <?php foreach ($list as $r): ?>
    <tr>
      <td><b><?= e($r['plate_no']) ?></b></td>
      <td style="white-space:normal;max-width:220px"><?= e($r['title']) ?></td>
      <td><?= e($r['report_date']) ?></td>
      <td><?= e((string)$r['repair_date']) ?></td>
      <td><?= fmt_money($r['cost']) ?></td>
      <td><?= e($r['vendor']) ?></td>
      <td><span class="tag <?= $statusTag[$r['status']] ?>"><?= e($r['status']) ?></span></td>
      <td style="white-space:normal">
        <?php if (can_manage()): ?>
        <a class="btn btn-gray btn-sm" href="repair_edit.php?id=<?= $r['id'] ?>">编辑</a>
        <form method="post" style="display:inline">
          <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
          <input type="hidden" name="id" value="<?= $r['id'] ?>">
          <input type="hidden" name="act" value="status">
          <?php if ($r['status'] === '待处理'): ?><button class="btn btn-sm" name="status" value="维修中">开始维修</button><?php endif; ?>
          <?php if ($r['status'] === '维修中'): ?><button class="btn btn-success btn-sm" name="status" value="已完成">完成</button><?php endif; ?>
        </form>
        <?php endif; ?>
        <?php if ($u['role'] === 'admin'): ?>
        <form method="post" style="display:inline" onsubmit="return confirm('确认删除该维修记录？')">
          <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
          <input type="hidden" name="id" value="<?= $r['id'] ?>">
          <button class="btn btn-danger btn-sm" name="act" value="delete">删除</button>
        </form>
        <?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
    <?php if (!$list): ?><tr><td colspan="8" style="color:var(--muted)">暂无维修记录</td></tr><?php endif; ?>
  </table></div>
  <div class="pager">
    <?php $qs = "&vehicle_id=$vid&status=" . urlencode($st); ?>
    <?php for ($i = 1; $i <= $pages; $i++): ?><?= $i === $page ? "<span class=\"cur\">$i</span>" : "<a href=\"?page=$i$qs\">$i</a>" ?><?php endfor; ?>
  </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
