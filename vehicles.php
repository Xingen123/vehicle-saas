<?php
require_once __DIR__ . '/includes/auth.php';
$u = require_login();

$tid = tid();

// 删除 / 停用操作（仅管理员）
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    require_admin();
    require_write();
    $id = (int)($_POST['id'] ?? 0);
    $act = $_POST['act'] ?? '';
    if ($act === 'delete') {
        exec_sql('DELETE FROM vehicles WHERE id = ? AND tenant_id = ?', [$id, $tid]);
        flash('车辆已删除（关联记录一并删除）');
    } elseif ($act === 'toggle') {
        exec_sql("UPDATE vehicles SET status = IF(status='停用','在用','停用') WHERE id = ? AND tenant_id = ?", [$id, $tid]);
        flash('状态已更新');
    } elseif ($act === 'regen') {
        exec_sql('UPDATE vehicles SET upload_token = ? WHERE id = ? AND tenant_id = ?', [gen_token(), $id, $tid]);
        flash('二维码令牌已重置，旧二维码即刻失效');
    }
    redirect('vehicles.php');
}

$kw = trim($_GET['kw'] ?? '');
$where = 'WHERE tenant_id = ?'; $params = [$tid];
if ($kw !== '') { $where .= ' AND (plate_no LIKE ? OR brand LIKE ? OR model LIKE ?)'; $params[] = "%$kw%"; $params[] = "%$kw%"; $params[] = "%$kw%"; }
$total = (int)(q1("SELECT COUNT(*) c FROM vehicles $where", $params)['c'] ?? 0);
[$page, $pages, $off, $size] = paginate($total, (int)($_GET['page'] ?? 1));
$list = q("SELECT * FROM vehicles $where ORDER BY id DESC LIMIT $size OFFSET $off", $params);

// 各车最新保险/检车到期日
$insMap = $inspMap = [];
foreach (q('SELECT vehicle_id, MAX(end_date) d FROM insurance_records WHERE tenant_id = ? GROUP BY vehicle_id', [$tid]) as $r) $insMap[$r['vehicle_id']] = $r['d'];
foreach (q('SELECT vehicle_id, MAX(next_due_date) d FROM inspection_records WHERE tenant_id = ? GROUP BY vehicle_id', [$tid]) as $r) $inspMap[$r['vehicle_id']] = $r['d'];

$pageTitle = '车辆管理'; $active = 'vehicles';
require __DIR__ . '/includes/header.php';
?>
<div class="card">
  <div class="toolbar">
    <?php if (can_manage()): ?><a class="btn" href="vehicle_edit.php">＋ 新增车辆</a><?php endif; ?>
    <form method="get">
      <input type="text" name="kw" placeholder="车牌 / 品牌 / 型号" value="<?= e($kw) ?>">
      <button class="btn btn-gray" type="submit">搜索</button>
    </form>
  </div>
  <div class="tbl-wrap"><table class="tbl">
    <tr><th>车牌号</th><th>品牌/型号</th><th>当前里程</th><th>保险到期</th><th>检车到期</th><th>状态</th><th>操作</th></tr>
    <?php foreach ($list as $v):
      $today = date('Y-m-d');
      $insD = $insMap[$v['id']] ?? ''; $inspD = $inspMap[$v['id']] ?? '';
    ?>
    <tr>
      <td><b><?= e($v['plate_no']) ?></b></td>
      <td><?= e(trim($v['brand'] . ' ' . $v['model'])) ?></td>
      <td><?= number_format($v['current_mileage']) ?> km</td>
      <td><?= $insD ? ($insD < $today ? '<span class="tag tag-red">' . e($insD) . ' 已过期</span>' : e($insD)) : '<span style="color:var(--muted)">未录入</span>' ?></td>
      <td><?= $inspD ? ($inspD < $today ? '<span class="tag tag-red">' . e($inspD) . ' 已过期</span>' : e($inspD)) : '<span style="color:var(--muted)">未录入</span>' ?></td>
      <td><span class="tag <?= $v['status'] === '在用' ? 'tag-green' : ($v['status'] === '维修中' ? 'tag-orange' : 'tag-gray') ?>"><?= e($v['status']) ?></span></td>
      <td>
        <?php if (can_manage()): ?><a class="btn btn-gray btn-sm" href="vehicle_edit.php?id=<?= $v['id'] ?>">编辑</a><?php endif; ?>
        <a class="btn btn-gray btn-sm" href="vehicle_qr.php?id=<?= $v['id'] ?>">司机二维码</a>
        <?php if ($u['role'] === 'admin'): ?>
        <form method="post" style="display:inline" onsubmit="return confirm('确认操作？')">
          <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
          <input type="hidden" name="id" value="<?= $v['id'] ?>">
          <button class="btn btn-gray btn-sm" name="act" value="toggle"><?= $v['status'] === '停用' ? '启用' : '停用' ?></button>
          <button class="btn btn-danger btn-sm" name="act" value="delete" onclick="return confirm('删除将同时删除该车的所有记录，确认？')">删除</button>
        </form>
        <?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
    <?php if (!$list): ?><tr><td colspan="7" style="color:var(--muted)">暂无车辆，点击"新增车辆"开始</td></tr><?php endif; ?>
  </table></div>
  <div class="pager">
    <?php for ($i = 1; $i <= $pages; $i++): ?>
      <?php if ($i === $page): ?><span class="cur"><?= $i ?></span><?php else: ?><a href="?page=<?= $i ?>&kw=<?= urlencode($kw) ?>"><?= $i ?></a><?php endif; ?>
    <?php endfor; ?>
  </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
