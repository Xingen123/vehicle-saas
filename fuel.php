<?php
require_once __DIR__ . '/includes/auth.php';
$u = require_login();

$tid = tid();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check(); require_admin(); require_write();
    $delId = (int)$_POST['id'];
    $dr = q1('SELECT f.*, v.plate_no FROM fuel_records f JOIN vehicles v ON v.id=f.vehicle_id WHERE f.id = ? AND f.tenant_id = ?', [$delId, $tid]);
    exec_sql('DELETE FROM fuel_records WHERE id = ? AND tenant_id = ?', [$delId, $tid]);
    if ($dr) {
        log_op($tid, '后台补录', $u['username'] ?? '管理员', '删除加油记录',
            "车牌 {$dr['plate_no']}，金额 {$dr['amount']} 元，里程 {$dr['mileage']} km，时间 {$dr['fuel_date']}");
    }
    flash('加油记录已删除');
    redirect('fuel.php');
}

$vid = (int)($_GET['vehicle_id'] ?? 0);
$from = $_GET['from'] ?? '';
$to = $_GET['to'] ?? '';
$where = ['f.tenant_id = ?']; $params = [$tid];
if ($vid) { $where[] = 'f.vehicle_id = ?'; $params[] = $vid; }
if ($from) { $where[] = 'f.fuel_date >= ?'; $params[] = $from . ' 00:00:00'; }
if ($to)   { $where[] = 'f.fuel_date <= ?'; $params[] = $to . ' 23:59:59'; }
$whereSql = 'WHERE ' . implode(' AND ', $where);

$total = (int)(q1("SELECT COUNT(*) c FROM fuel_records f $whereSql", $params)['c'] ?? 0);
$sum = q1("SELECT COALESCE(SUM(amount),0) s, COALESCE(SUM(liters),0) l FROM fuel_records f $whereSql", $params);
[$page, $pages, $off, $size] = paginate($total, (int)($_GET['page'] ?? 1));
$list = q("SELECT f.*, v.plate_no FROM fuel_records f JOIN vehicles v ON v.id=f.vehicle_id $whereSql ORDER BY f.fuel_date DESC, f.id DESC LIMIT $size OFFSET $off", $params);
$vehicles = q('SELECT id, plate_no FROM vehicles WHERE tenant_id = ? ORDER BY plate_no', [$tid]);

$pageTitle = '加油记录'; $active = 'fuel';
require __DIR__ . '/includes/header.php';
?>
<div class="card">
  <div class="toolbar">
    <?php if (can_manage()): ?><a class="btn" href="fuel_edit.php">＋ 后台补录</a><?php endif; ?>
    <form method="get">
      <select name="vehicle_id">
        <option value="0">全部车辆</option>
        <?php foreach ($vehicles as $x): ?><option value="<?= $x['id'] ?>" <?= $vid === (int)$x['id'] ? 'selected' : '' ?>><?= e($x['plate_no']) ?></option><?php endforeach; ?>
      </select>
      <input type="date" name="from" value="<?= e($from) ?>"> 至
      <input type="date" name="to" value="<?= e($to) ?>">
      <button class="btn btn-gray">筛选</button>
    </form>
    <span style="color:var(--muted)">合计：¥<?= fmt_money($sum['s']) ?> / <?= fmt_money($sum['l']) ?> 升，共 <?= $total ?> 条</span>
  </div>
  <div class="tbl-wrap"><table class="tbl">
    <tr><th>时间</th><th>车牌</th><th>金额(元)</th><th>单价</th><th>油量(升)</th><th>里程(km)</th><th>加油站</th><th>司机</th><th>照片</th><th>来源</th><th>操作</th></tr>
    <?php foreach ($list as $r): ?>
    <tr>
      <td><?= e($r['fuel_date']) ?></td>
      <td><b><?= e($r['plate_no']) ?></b></td>
      <td><?= fmt_money($r['amount']) ?></td>
      <td><?= e((string)$r['price']) ?></td>
      <td><?= e((string)$r['liters']) ?></td>
      <td><?= number_format($r['mileage']) ?></td>
      <td><?= e($r['station']) ?></td>
      <td><?= e($r['driver_name']) ?></td>
      <td style="white-space:nowrap">
        <?php if ($r['receipt_photo']): ?><a class="photo-link" href="uploads/<?= e($r['receipt_photo']) ?>" target="_blank" rel="noopener" data-tip="查看凭证" title="查看凭证"><svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg></a><?php endif; ?>
        <?php if ($r['mileage_photo']): ?><a class="photo-link mile" href="uploads/<?= e($r['mileage_photo']) ?>" target="_blank" rel="noopener" data-tip="查看仪表" title="查看仪表"><svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m12 14 4-4"/><path d="M3.34 19a10 10 0 1 1 17.32 0"/></svg></a><?php endif; ?>
        <?php if (!$r['receipt_photo'] && !$r['mileage_photo']): ?><span style="color:var(--muted)">—</span><?php endif; ?>
      </td>
      <td><?= $r['source'] === 'driver' ? '<span class="tag tag-green">司机扫码</span>' : '<span class="tag tag-gray">后台录入</span>' ?></td>
      <td>
        <?php if (can_manage()): ?><a class="btn btn-gray btn-sm" href="fuel_edit.php?id=<?= $r['id'] ?>">编辑</a><?php endif; ?>
        <?php if ($u['role'] === 'admin'): ?>
        <form method="post" style="display:inline" onsubmit="return confirm('确认删除该加油记录？')">
          <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
          <input type="hidden" name="id" value="<?= $r['id'] ?>">
          <button class="btn btn-danger btn-sm">删除</button>
        </form>
        <?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
    <?php if (!$list): ?><tr><td colspan="11" style="color:var(--muted)">暂无加油记录</td></tr><?php endif; ?>
  </table></div>
  <div class="pager">
    <?php $qs = "&vehicle_id=$vid&from=" . urlencode($from) . "&to=" . urlencode($to); ?>
    <?php for ($i = 1; $i <= $pages; $i++): ?><?= $i === $page ? "<span class=\"cur\">$i</span>" : "<a href=\"?page=$i$qs\">$i</a>" ?><?php endfor; ?>
  </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
