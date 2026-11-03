<?php
require_once __DIR__ . '/includes/auth.php';
$u = require_login();

// 数据库未执行新能源升级脚本时给出引导，避免 500 报错
if (db_needs_upgrade()) {
    $pageTitle = '充电记录'; $active = 'charge';
    require __DIR__ . '/includes/header.php';
    echo '<div class="card"><h3>⚠️ 数据库待升级</h3><p style="color:var(--muted)">「充电记录」需要升级数据库结构后使用。请管理员在 phpMyAdmin 中对 vehicle_mgr 库执行程序包根目录下的 <b>upgrade_ev.sql</b>（不影响现有数据），升级后本功能自动可用。</p></div>';
    require __DIR__ . '/includes/footer.php';
    exit;
}

$tid = tid();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    require_write();
    $act = $_POST['act'] ?? '';
    if ($act === 'delete') {
        require_admin();
        $id = (int)$_POST['id'];
        $dr = q1('SELECT r.*, v.plate_no FROM charge_records r JOIN vehicles v ON v.id=r.vehicle_id WHERE r.id = ? AND r.tenant_id = ?', [$id, $tid]);
        // 删除满电记录时同步删除其参与生成的周期，避免脏数据
        exec_sql('DELETE FROM charge_cycles WHERE tenant_id = ? AND (start_record_id = ? OR end_record_id = ?)', [$tid, $id, $id]);
        exec_sql('DELETE FROM charge_records WHERE id = ? AND tenant_id = ?', [$id, $tid]);
        if ($dr) {
            log_op($tid, '后台补录', $u['username'] ?? '管理员', '删除充电记录',
                "车牌 {$dr['plate_no']}，类型 {$dr['charge_type']}，里程 {$dr['mileage']} km" . ($dr['amount'] !== null ? "，金额 {$dr['amount']} 元" : ''));
        }
        flash('充电记录已删除（关联周期已同步删除）');
    } elseif ($act === 'recalc') {
        require_manage();
        $n = recalc_cycles((int)$_POST['vehicle_id']);
        flash($n > 0 ? "已重算 $n 个待核算周期" : '没有待重算的周期', $n > 0 ? 'success' : 'error');
    }
    redirect('charge.php?' . ($_POST['back'] ?? ''));
}

$vid  = (int)($_GET['vehicle_id'] ?? 0);
$type = $_GET['type'] ?? '';
$tab  = $_GET['tab'] ?? 'records';

$where = ['c.tenant_id = ?']; $params = [$tid];
if ($vid) { $where[] = 'c.vehicle_id = ?'; $params[] = $vid; }
if (in_array($type, ['满电', '零散补电', '外部快充'], true)) { $where[] = 'c.charge_type = ?'; $params[] = $type; }
$whereSql = 'WHERE ' . implode(' AND ', $where);

$total = (int)(q1("SELECT COUNT(*) c FROM charge_records c $whereSql", $params)['c'] ?? 0);
[$page, $pages, $off, $size] = paginate($total, (int)($_GET['page'] ?? 1));
$list = q("SELECT c.*, v.plate_no FROM charge_records c JOIN vehicles v ON v.id=c.vehicle_id $whereSql ORDER BY c.charge_time DESC, c.id DESC LIMIT $size OFFSET $off", $params);

// 满电周期列表
$cWhere = 'WHERE cc.tenant_id = ?';
$cParams = [$tid];
if ($vid) { $cWhere .= ' AND cc.vehicle_id = ?'; $cParams[] = $vid; }
$cycles = q("SELECT cc.*, v.plate_no, v.energy_consumption FROM charge_cycles cc JOIN vehicles v ON v.id=cc.vehicle_id $cWhere ORDER BY cc.end_time DESC LIMIT 50", $cParams);

$vehicles = q("SELECT id, plate_no FROM vehicles WHERE tenant_id = ? AND fuel_type = '新能源' ORDER BY plate_no", [$tid]);
$typeTag = ['满电' => 'tag-green', '零散补电' => 'tag-gray', '外部快充' => 'tag-blue'];
$qs = "vehicle_id=$vid&type=" . urlencode($type);

$pageTitle = '充电记录'; $active = 'charge';
require __DIR__ . '/includes/header.php';
?>
<div class="card">
  <div class="toolbar">
    <a class="btn" href="charge.php?tab=records&<?= $qs ?>" style="<?= $tab === 'records' ? '' : 'background:#fff;color:var(--text);border-color:var(--border)' ?>">充电台账</a>
    <a class="btn" href="charge.php?tab=cycles&<?= $qs ?>" style="<?= $tab === 'cycles' ? '' : 'background:#fff;color:var(--text);border-color:var(--border)' ?>">满电周期核算</a>
    <?php if (can_manage()): ?><a class="btn btn-success" href="charge_edit.php">＋ 新增充电记录</a><?php endif; ?>
    <form method="get">
      <input type="hidden" name="tab" value="<?= e($tab) ?>">
      <select name="vehicle_id">
        <option value="0">全部新能源车辆</option>
        <?php foreach ($vehicles as $x): ?><option value="<?= $x['id'] ?>" <?= $vid === (int)$x['id'] ? 'selected' : '' ?>><?= e($x['plate_no']) ?></option><?php endforeach; ?>
      </select>
      <?php if ($tab === 'records'): ?>
      <select name="type">
        <option value="">全部类型</option>
        <?php foreach (['满电','零散补电','外部快充'] as $t): ?><option <?= $type === $t ? 'selected' : '' ?>><?= $t ?></option><?php endforeach; ?>
      </select>
      <?php endif; ?>
      <button class="btn btn-gray">筛选</button>
    </form>
  </div>

<?php if ($tab === 'records'): ?>
  <div class="tbl-wrap"><table class="tbl">
    <tr><th>时间</th><th>车牌</th><th>类型</th><th>里程(km)</th><th>度数</th><th>金额(元)</th><th>充电点</th><th>司机</th><th>照片</th><th>备注</th><th>操作</th></tr>
    <?php foreach ($list as $r): ?>
    <tr>
      <td><?= e($r['charge_time']) ?></td>
      <td><b><?= e($r['plate_no']) ?></b></td>
      <td><span class="tag <?= $typeTag[$r['charge_type']] ?>"><?= e($r['charge_type']) ?></span></td>
      <td><?= number_format($r['mileage']) ?></td>
      <td><?= $r['kwh'] !== null ? e((string)$r['kwh']) : '<span style="color:var(--muted)">—</span>' ?></td>
      <td><?= $r['amount'] !== null ? fmt_money($r['amount']) : '<span style="color:var(--muted)">—</span>' ?></td>
      <td><?= e($r['station']) ?></td>
      <td><?= e($r['driver_name']) ?></td>
      <td style="white-space:nowrap">
        <?php if ($r['receipt_photo']): ?><a class="photo-link" href="uploads/<?= e($r['receipt_photo']) ?>" target="_blank" rel="noopener" data-tip="查看凭证" title="查看凭证"><svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg></a><?php endif; ?>
        <?php if ($r['mileage_photo']): ?><a class="photo-link mile" href="uploads/<?= e($r['mileage_photo']) ?>" target="_blank" rel="noopener" data-tip="查看仪表" title="查看仪表"><svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m12 14 4-4"/><path d="M3.34 19a10 10 0 1 1 17.32 0"/></svg></a><?php endif; ?>
        <?php if (!$r['receipt_photo'] && !$r['mileage_photo']): ?><span style="color:var(--muted)">—</span><?php endif; ?>
      </td>
      <td style="white-space:normal;max-width:160px"><?= e($r['remark']) ?></td>
      <td>
        <?php if (can_manage()): ?><a class="btn btn-gray btn-sm" href="charge_edit.php?id=<?= $r['id'] ?>">编辑</a><?php endif; ?>
        <?php if ($u['role'] === 'admin'): ?>
        <form method="post" style="display:inline" onsubmit="return confirm('删除满电记录将同步删除关联周期，确认？')">
          <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
          <input type="hidden" name="back" value="<?= e("tab=records&$qs") ?>">
          <input type="hidden" name="id" value="<?= $r['id'] ?>">
          <button class="btn btn-danger btn-sm" name="act" value="delete">删除</button>
        </form>
        <?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
    <?php if (!$list): ?><tr><td colspan="11" style="color:var(--muted)">暂无充电记录。零散补电仅作台账备注，不参与电量核算。</td></tr><?php endif; ?>
  </table></div>
  <div class="pager">
    <?php for ($i = 1; $i <= $pages; $i++): ?><?= $i === $page ? "<span class=\"cur\">$i</span>" : "<a href=\"?tab=records&page=$i&$qs\">$i</a>" ?><?php endfor; ?>
  </div>

<?php else: ?>
  <p style="color:var(--muted);font-size:13px;margin-bottom:10px">
    核算规则：相邻两次「满电」构成一个周期，周期电量 = (满电里程M2 − 满电里程M1) ÷ 100 × 百公里电耗；
    周期内的外部快充按真实度数单独计费并从估算电量中扣除，剩余部分按厂区慢充电价（<?= e(get_setting('slow_charge_price', '0.80')) ?> 元/度）计厂区电费。
  </p>
  <?php if (can_manage()): ?>
  <form method="post" style="margin-bottom:10px" onsubmit="return confirm('将按车辆档案中的百公里电耗重算所有待核算周期，确认？')">
    <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
    <input type="hidden" name="back" value="<?= e("tab=cycles&$qs") ?>">
    <input type="hidden" name="vehicle_id" value="<?= $vid ?>">
    <button class="btn btn-gray btn-sm" name="act" value="recalc">🔄 重算待核算周期</button>
    <span style="color:var(--muted);font-size:12px">（电量为空的周期出现在未预设百公里电耗时，补录电耗后点此重算）</span>
  </form>
  <?php endif; ?>
  <div class="tbl-wrap"><table class="tbl">
    <tr><th>车牌</th><th>周期起点(满电)</th><th>周期终点(满电)</th><th>里程 M1→M2</th><th>行驶(km)</th><th>估算总电量(度)</th><th>外部快充(度)</th><th>厂区慢充(度)</th><th>慢充电费(元)</th><th>每公里成本(元)</th></tr>
    <?php foreach ($cycles as $c): ?>
    <tr>
      <td><b><?= e($c['plate_no']) ?></b></td>
      <td><?= e($c['start_time']) ?></td>
      <td><?= e($c['end_time']) ?></td>
      <td><?= number_format($c['start_mileage']) ?> → <?= number_format($c['end_mileage']) ?></td>
      <td><b><?= number_format($c['distance']) ?></b></td>
      <td><?= $c['est_kwh'] !== null ? e((string)$c['est_kwh']) : '<span class="tag tag-orange">待补电耗</span>' ?></td>
      <td><?= e((string)$c['ext_kwh']) ?></td>
      <td><?= $c['slow_kwh'] !== null ? e((string)$c['slow_kwh']) : '—' ?></td>
      <td><?= $c['slow_cost'] !== null ? fmt_money($c['slow_cost']) : '—' ?></td>
      <td><?= $c['slow_cost'] !== null && $c['distance'] > 0 ? number_format($c['slow_cost'] / $c['distance'], 3) : '—' ?></td>
    </tr>
    <?php endforeach; ?>
    <?php if (!$cycles): ?><tr><td colspan="10" style="color:var(--muted)">暂无周期：同一车辆累计两条「满电」记录后自动生成</td></tr><?php endif; ?>
  </table></div>
<?php endif; ?>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
