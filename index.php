<?php
require_once __DIR__ . '/includes/auth.php';
$u = require_login();

$pageTitle = '控制台';
$active = 'dashboard';
$tid = tid();

// 统计卡片
$vehicleCount = (int)(q1('SELECT COUNT(*) c FROM vehicles WHERE tenant_id = ?', [$tid])['c'] ?? 0);
$month = date('Y-m');
$fuelMonth = q1("SELECT COALESCE(SUM(amount),0) s, COALESCE(SUM(liters),0) l FROM fuel_records WHERE tenant_id = ? AND DATE_FORMAT(fuel_date,'%Y-%m') = ?", [$tid, $month]);
$repairMonth = q1("SELECT COALESCE(SUM(cost),0) s FROM repair_records WHERE tenant_id = ? AND DATE_FORMAT(report_date,'%Y-%m') = ?", [$tid, $month]);
$repairPending = (int)(q1("SELECT COUNT(*) c FROM repair_records WHERE tenant_id = ? AND status != '已完成'", [$tid])['c'] ?? 0);

$reminders = collect_reminders($tid);

// 近6个月费用（加油+维修+保险+检车）
$chartLabels = []; $chartFuel = []; $chartRepair = [];
for ($i = 5; $i >= 0; $i--) {
    $m = date('Y-m', strtotime("-$i month"));
    $chartLabels[] = date('y年n月', strtotime($m . '-01'));
    $chartFuel[]   = (float)(q1("SELECT COALESCE(SUM(amount),0) s FROM fuel_records WHERE tenant_id = ? AND DATE_FORMAT(fuel_date,'%Y-%m') = ?", [$tid, $m])['s'] ?? 0);
    $chartRepair[] = (float)(q1("SELECT COALESCE(SUM(cost),0) s FROM repair_records WHERE tenant_id = ? AND DATE_FORMAT(report_date,'%Y-%m') = ?", [$tid, $m])['s'] ?? 0);
}

// 最近加油记录
$recentFuel = q('SELECT f.*, v.plate_no FROM fuel_records f JOIN vehicles v ON v.id=f.vehicle_id WHERE f.tenant_id = ? ORDER BY f.id DESC LIMIT 8', [$tid]);

require __DIR__ . '/includes/header.php';
?>
<div class="stat-grid">
  <div class="stat"><div class="lbl">车辆总数</div><div class="num"><?= $vehicleCount ?></div></div>
  <div class="stat"><div class="lbl">本月加油金额</div><div class="num">¥<?= fmt_money($fuelMonth['s'] ?? 0) ?></div></div>
  <div class="stat"><div class="lbl">本月维修费用</div><div class="num">¥<?= fmt_money($repairMonth['s'] ?? 0) ?></div></div>
  <div class="stat"><div class="lbl">待处理维修</div><div class="num" style="color:var(--warning)"><?= $repairPending ?></div></div>
  <div class="stat"><div class="lbl">到期提醒</div><div class="num" style="color:var(--danger)"><?= count($reminders) ?></div></div>
</div>

<div class="card">
  <h3>⏰ 到期提醒（保险 / 检车 / 保养）</h3>
  <?php if (!$reminders): ?>
    <p style="color:var(--muted)">暂无临近到期事项 🎉</p>
  <?php else: ?>
  <div class="tbl-wrap"><table class="tbl">
    <tr><th>紧急程度</th><th>类型</th><th>车牌</th><th>到期日</th><th>说明</th></tr>
    <?php foreach ($reminders as $r):
      $d = $r['days_left'];
      $tag = $d < 0 ? ['已过期', 'tag-red'] : ($d <= 7 ? ['剩 ' . $d . ' 天', 'tag-red'] : ($d <= 15 ? ['剩 ' . $d . ' 天', 'tag-orange'] : ['剩 ' . $d . ' 天', 'tag-blue']));
      if ($r['type'] === 'maintenance' && $r['due_date'] === '里程到期') $tag = ['里程到期', 'tag-orange'];
    ?>
    <tr>
      <td><span class="tag <?= $tag[1] ?>"><?= $tag[0] ?></span></td>
      <td><?= e($r['type_label']) ?></td>
      <td><b><?= e($r['vehicle']) ?></b></td>
      <td><?= e($r['due_date']) ?></td>
      <td><?= e($r['desc']) ?></td>
    </tr>
    <?php endforeach; ?>
  </table></div>
  <?php endif; ?>
</div>

<div class="card">
  <h3>📈 近 6 个月费用走势</h3>
  <canvas id="costChart" height="90"></canvas>
</div>

<div class="card">
  <h3>⛽ 最近加油记录</h3>
  <div class="tbl-wrap"><table class="tbl">
    <tr><th>时间</th><th>车牌</th><th>金额(元)</th><th>油量(升)</th><th>里程(km)</th><th>来源</th></tr>
    <?php foreach ($recentFuel as $f): ?>
    <tr>
      <td><?= e($f['fuel_date']) ?></td>
      <td><?= e($f['plate_no']) ?></td>
      <td><?= fmt_money($f['amount']) ?></td>
      <td><?= e((string)$f['liters']) ?></td>
      <td><?= e((string)$f['mileage']) ?></td>
      <td><?= $f['source'] === 'driver' ? '<span class="tag tag-green">司机扫码</span>' : '<span class="tag tag-gray">后台录入</span>' ?></td>
    </tr>
    <?php endforeach; ?>
    <?php if (!$recentFuel): ?><tr><td colspan="6" style="color:var(--muted)">暂无记录</td></tr><?php endif; ?>
  </table></div>
</div>

<script src="assets/js/chart.umd.min.js"></script>
<script>
new Chart(document.getElementById('costChart'), {
  type: 'bar',
  data: {
    labels: <?= json_encode($chartLabels, JSON_UNESCAPED_UNICODE) ?>,
    datasets: [
      { label: '加油费(元)', data: <?= json_encode($chartFuel) ?>, backgroundColor: '#2563eb' },
      { label: '维修费(元)', data: <?= json_encode($chartRepair) ?>, backgroundColor: '#f59e0b' }
    ]
  },
  options: { responsive: true, scales: { y: { beginAtZero: true } } }
});
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>
