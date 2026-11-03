<?php
require_once __DIR__ . '/includes/auth.php';
$u = require_login(); // 老板/财务/管理员均可查看

$tid  = tid();
$vid  = (int)($_GET['vehicle_id'] ?? 0);
$from = $_GET['from'] ?? date('Y-m-01');
$to   = $_GET['to']   ?? date('Y-m-d');

// ---------- CSV 导出 ----------
if (($_GET['export'] ?? '') === 'fuel') {
    $sql = 'SELECT f.fuel_date, v.plate_no, f.amount, f.price, f.liters, f.mileage, f.station, f.driver_name, IF(f.source="driver","司机扫码","后台录入") src, f.remark
            FROM fuel_records f JOIN vehicles v ON v.id=f.vehicle_id
            WHERE f.tenant_id = ? AND f.fuel_date >= ? AND f.fuel_date <= ?' . ($vid ? ' AND f.vehicle_id = ?' : '') . ' ORDER BY f.fuel_date';
    $params = [$tid, $from . ' 00:00:00', $to . ' 23:59:59'];
    if ($vid) $params[] = $vid;
    $rows = q($sql, $params);
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="fuel_' . $from . '_' . $to . '.csv"');
    echo "\xEF\xBB\xBF"; // BOM，Excel 识别中文
    $out = fopen('php://output', 'w');
    fputcsv($out, ['加油时间', '车牌', '金额(元)', '单价(元/升)', '油量(升)', '里程(km)', '加油站', '司机', '来源', '备注']);
    foreach ($rows as $r) fputcsv($out, $r);
    fclose($out);
    exit;
}

// ---------- 汇总数据 ----------
$vehicles = q('SELECT id, plate_no FROM vehicles WHERE tenant_id = ? ORDER BY plate_no', [$tid]);
$fWhere = 'f.tenant_id = ? AND f.fuel_date >= ? AND f.fuel_date <= ?';
$fParams = [$tid, $from . ' 00:00:00', $to . ' 23:59:59'];
if ($vid) { $fWhere .= ' AND f.vehicle_id = ?'; $fParams[] = $vid; }

// 每车统计：加油金额、油量、里程跨度、百公里油耗
$perVehicle = q(
    "SELECT v.id, v.plate_no,
            COALESCE(SUM(f.amount),0) fuel_amount, COALESCE(SUM(f.liters),0) fuel_liters,
            COUNT(f.id) fuel_times, MAX(f.mileage) max_km, MIN(f.mileage) min_km
     FROM vehicles v
     LEFT JOIN fuel_records f ON f.vehicle_id = v.id AND $fWhere
     WHERE v.tenant_id = ?" . ($vid ? ' AND v.id = ' . $vid : '') . "
     GROUP BY v.id ORDER BY fuel_amount DESC",
    array_merge($fParams, [$tid])
);

// 费用构成
$fuelSum   = (float)(q1("SELECT COALESCE(SUM(amount),0) s FROM fuel_records f WHERE $fWhere", $fParams)['s'] ?? 0);
$rWhere = 'tenant_id = ? AND report_date >= ? AND report_date <= ?' . ($vid ? ' AND vehicle_id = ?' : '');
$rParams = [$tid, $from, $to]; if ($vid) $rParams[] = $vid;
$repairSum = (float)(q1("SELECT COALESCE(SUM(cost),0) s FROM repair_records WHERE $rWhere", $rParams)['s'] ?? 0);
$mWhere = 'tenant_id = ? AND mt_date >= ? AND mt_date <= ?' . ($vid ? ' AND vehicle_id = ?' : '');
$mtSum   = (float)(q1("SELECT COALESCE(SUM(cost),0) s FROM maintenance_records WHERE $mWhere", $rParams)['s'] ?? 0);
$insSum  = (float)(q1('SELECT COALESCE(SUM(amount),0) s FROM insurance_records WHERE tenant_id = ? AND start_date >= ? AND start_date <= ?' . ($vid ? ' AND vehicle_id = ?' : ''), [$tid, $from, $to, ...($vid ? [$vid] : [])])['s'] ?? 0);

// 充电费用：外部快充真实金额 + 满电周期核算的厂区慢充电费
// 老库未执行 upgrade_ev.sql 时跳过该板块，避免报错
$hasCharge = !db_needs_upgrade();
$chWhere = 'tenant_id = ? AND charge_time >= ? AND charge_time <= ? AND charge_type = "外部快充"' . ($vid ? ' AND vehicle_id = ?' : '');
$chParams = [$tid, $from . ' 00:00:00', $to . ' 23:59:59']; if ($vid) $chParams[] = $vid;
$cyWhere = 'tenant_id = ? AND end_time >= ? AND end_time <= ?' . ($vid ? ' AND vehicle_id = ?' : '');
// 与 vehicles 联表时需带表别名，避免 tenant_id 两表同名列歧义报错
$cyWhereCc = 'cc.tenant_id = ? AND cc.end_time >= ? AND cc.end_time <= ?' . ($vid ? ' AND cc.vehicle_id = ?' : '');
if ($hasCharge) {
    $fastSum = (float)(q1("SELECT COALESCE(SUM(amount),0) s FROM charge_records WHERE $chWhere", $chParams)['s'] ?? 0);
    $slowSum = (float)(q1("SELECT COALESCE(SUM(slow_cost),0) s FROM charge_cycles WHERE $cyWhere", $chParams)['s'] ?? 0);
} else {
    $fastSum = $slowSum = 0.0;
}
$chargeSum = $fastSum + $slowSum;

// 月度趋势（区间内）
$trend = q(
    "SELECT DATE_FORMAT(fuel_date,'%Y-%m') m, SUM(amount) s FROM fuel_records f WHERE $fWhere GROUP BY m ORDER BY m",
    $fParams
);

$pageTitle = '统计报表'; $active = 'reports';
require __DIR__ . '/includes/header.php';
?>
<div class="card">
  <form method="get" class="toolbar">
    <select name="vehicle_id">
      <option value="0">全部车辆</option>
      <?php foreach ($vehicles as $x): ?><option value="<?= $x['id'] ?>" <?= $vid === (int)$x['id'] ? 'selected' : '' ?>><?= e($x['plate_no']) ?></option><?php endforeach; ?>
    </select>
    <input type="date" name="from" value="<?= e($from) ?>"> 至
    <input type="date" name="to" value="<?= e($to) ?>">
    <button class="btn">查询</button>
    <a class="btn btn-gray" href="?export=fuel&vehicle_id=<?= $vid ?>&from=<?= e($from) ?>&to=<?= e($to) ?>">⬇ 导出加油明细 CSV</a>
  </form>
</div>

<div class="stat-grid">
  <div class="stat"><div class="lbl">加油费</div><div class="num">¥<?= fmt_money($fuelSum) ?></div></div>
  <div class="stat"><div class="lbl">维修费</div><div class="num">¥<?= fmt_money($repairSum) ?></div></div>
  <div class="stat"><div class="lbl">保养费</div><div class="num">¥<?= fmt_money($mtSum) ?></div></div>
  <div class="stat"><div class="lbl">保险费(期间新购)</div><div class="num">¥<?= fmt_money($insSum) ?></div></div>
  <div class="stat"><div class="lbl">充电费(慢充核算+快充)</div><div class="num">¥<?= fmt_money($chargeSum) ?></div></div>
  <div class="stat"><div class="lbl">合计</div><div class="num" style="color:var(--primary)">¥<?= fmt_money($fuelSum + $repairSum + $mtSum + $insSum + $chargeSum) ?></div></div>
</div>

<div class="card">
  <h3>🚗 车辆油耗与费用明细（按加油金额排序）</h3>
  <div class="tbl-wrap"><table class="tbl">
    <tr><th>车牌</th><th>加油次数</th><th>加油金额(元)</th><th>油量(升)</th><th>行驶里程(km)</th><th>百公里油耗(L)</th><th>每公里成本(元)</th></tr>
    <?php foreach ($perVehicle as $r):
      $span = (int)$r['max_km'] - (int)$r['min_km'];
      $cons = ($span > 0 && $r['fuel_liters'] > 0) ? round($r['fuel_liters'] / $span * 100, 2) : null;
      $perKm = ($span > 0 && $r['fuel_amount'] > 0) ? round($r['fuel_amount'] / $span, 2) : null;
    ?>
    <tr>
      <td><b><?= e($r['plate_no']) ?></b></td>
      <td><?= $r['fuel_times'] ?></td>
      <td><?= fmt_money($r['fuel_amount']) ?></td>
      <td><?= fmt_money($r['fuel_liters']) ?></td>
      <td><?= $span > 0 ? number_format($span) : '<span style="color:var(--muted)">数据不足</span>' ?></td>
      <td><?= $cons !== null ? $cons : '-' ?></td>
      <td><?= $perKm !== null ? $perKm : '-' ?></td>
    </tr>
    <?php endforeach; ?>
    <?php if (!$perVehicle): ?><tr><td colspan="7" style="color:var(--muted)">暂无数据</td></tr><?php endif; ?>
  </table></div>
  <p style="color:var(--muted);font-size:12px;margin-top:8px">注：行驶里程 = 区间内最大仪表里程 − 最小仪表里程；油耗仅供参考（未剔除加油未满箱等因素）。</p>
</div>

<div class="card">
  <h3>🔋 新能源单车能耗（按满电周期核算）</h3>
  <?php if (!$hasCharge): ?>
  <p style="color:var(--muted)">数据库未升级：请管理员在 phpMyAdmin 中对 vehicle_mgr 库执行程序包根目录下的 <b>upgrade_ev.sql</b>，升级后本板块自动可用。</p>
  <?php else: ?>
  <div class="tbl-wrap"><table class="tbl">
    <tr><th>车牌</th><th>满电周期数</th><th>周期总里程(km)</th><th>估算总电量(度)</th><th>外部快充(度)</th><th>厂区慢充(度)</th><th>慢充电费(元)</th><th>快充电费(元)</th><th>每公里电费(元)</th><th>实际电耗(kWh/100km)</th></tr>
    <?php
    $fastByVeh = [];
    foreach (q("SELECT vehicle_id, SUM(amount) s FROM charge_records WHERE $chWhere GROUP BY vehicle_id", $chParams) as $x) $fastByVeh[$x['vehicle_id']] = (float)$x['s'];
    // 重新带 vehicle_id 查（供快充金额匹配）
    $evStatsFull = q(
        "SELECT v.id, v.plate_no, v.energy_consumption,
                COUNT(cc.id) cycles, COALESCE(SUM(cc.distance),0) distance,
                COALESCE(SUM(cc.est_kwh),0) est_kwh, COALESCE(SUM(cc.ext_kwh),0) ext_kwh,
                COALESCE(SUM(cc.slow_kwh),0) slow_kwh, COALESCE(SUM(cc.slow_cost),0) slow_cost
         FROM vehicles v
         JOIN charge_cycles cc ON cc.vehicle_id = v.id AND $cyWhereCc
         WHERE v.tenant_id = ?" . ($vid ? ' AND v.id = ' . $vid : '') . "
         GROUP BY v.id ORDER BY distance DESC",
        array_merge($chParams, [$tid])
    );
    foreach ($evStatsFull as $r):
      $fast = $fastByVeh[$r['id']] ?? 0;
      $perKm = $r['distance'] > 0 ? round(($r['slow_cost'] + $fast) / $r['distance'], 3) : null;
      $realCons = ($r['distance'] > 0 && $r['est_kwh'] > 0) ? round(($r['ext_kwh'] + $r['slow_kwh']) / $r['distance'] * 100, 2) : null;
    ?>
    <tr>
      <td><b><?= e($r['plate_no']) ?></b></td>
      <td><?= $r['cycles'] ?></td>
      <td><?= number_format($r['distance']) ?></td>
      <td><?= fmt_money($r['est_kwh']) ?></td>
      <td><?= fmt_money($r['ext_kwh']) ?></td>
      <td><?= fmt_money($r['slow_kwh']) ?></td>
      <td><?= fmt_money($r['slow_cost']) ?></td>
      <td><?= fmt_money($fast) ?></td>
      <td><?= $perKm !== null ? $perKm : '-' ?></td>
      <td><?= $realCons !== null ? $realCons . ($r['energy_consumption'] ? ' <span style="color:var(--muted)">(预设 ' . e((string)$r['energy_consumption']) . ')</span>' : '') : '-' ?></td>
    </tr>
    <?php endforeach; ?>
    <?php if (!$evStatsFull): ?><tr><td colspan="10" style="color:var(--muted)">区间内暂无已核算的满电周期</td></tr><?php endif; ?>
  </table></div>
  <p style="color:var(--muted);font-size:12px;margin-top:8px">注：周期电量按"里程差 × 预设百公里电耗"估算，外部快充按真实度数计入并从慢充估算中扣除，避免中途拔枪误差与重复计费。</p>
  <?php endif; ?>
</div>

<div class="card">
  <h3>📈 月度加油金额趋势</h3>
  <?php if ($trend): ?>
  <canvas id="fuelTrend" height="80"></canvas>
  <?php else: ?><p style="color:var(--muted)">区间内暂无加油记录</p><?php endif; ?>
</div>

<script src="assets/js/chart.umd.min.js"></script>
<script>
<?php if ($trend): ?>
new Chart(document.getElementById('fuelTrend'), {
  type: 'line',
  data: {
    labels: <?= json_encode(array_column($trend, 'm')) ?>,
    datasets: [{ label: '加油金额(元)', data: <?= json_encode(array_map('floatval', array_column($trend, 's'))) ?>, borderColor: '#2563eb', backgroundColor: 'rgba(37,99,235,.12)', fill: true, tension: .3 }]
  },
  options: { responsive: true, scales: { y: { beginAtZero: true } } }
});
<?php endif; ?>
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>
