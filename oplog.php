<?php
/**
 * 系统日志：司机上传、后台补录、AI 识别等关键操作记录（仅管理员可见）
 */
require_once __DIR__ . '/includes/auth.php';
$u = require_admin();

$tid = tid();

// 确保日志表存在（老库首次访问自动建表；空动作不产生日志）
log_op($tid, '', '', '', '');

$ltype  = trim($_GET['ltype'] ?? '');
$kw     = trim($_GET['kw'] ?? '');
$from   = $_GET['from'] ?? '';
$to     = $_GET['to'] ?? '';

$where = ['tenant_id = ?']; $params = [$tid];
if ($ltype !== '') { $where[] = 'ltype = ?'; $params[] = $ltype; }
if ($from) { $where[] = 'created_at >= ?'; $params[] = $from . ' 00:00:00'; }
if ($to)   { $where[] = 'created_at <= ?'; $params[] = $to . ' 23:59:59'; }
if ($kw !== '') {
    $where[] = '(actor LIKE ? OR action LIKE ? OR detail LIKE ?)';
    $params[] = "%$kw%"; $params[] = "%$kw%"; $params[] = "%$kw%";
}
$whereSql = 'WHERE ' . implode(' AND ', $where);

$total = (int)(q1("SELECT COUNT(*) c FROM op_logs $whereSql", $params)['c'] ?? 0);
[$page, $pages, $off, $size] = paginate($total, (int)($_GET['page'] ?? 1), 30);
$list = q("SELECT * FROM op_logs $whereSql ORDER BY id DESC LIMIT $size OFFSET $off", $params);
$types = q('SELECT DISTINCT ltype FROM op_logs WHERE tenant_id = ? ORDER BY ltype', [$tid]);

$pageTitle = '系统日志'; $active = 'logs';
require __DIR__ . '/includes/header.php';
?>
<div class="card">
  <form method="get" class="toolbar" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
    <select name="ltype">
      <option value="">全部类型</option>
      <?php foreach ($types as $t): ?><option value="<?= e($t['ltype']) ?>" <?= $ltype === $t['ltype'] ? 'selected' : '' ?>><?= e($t['ltype']) ?></option><?php endforeach; ?>
    </select>
    <input type="date" name="from" value="<?= e($from) ?>">
    <span style="color:var(--muted)">至</span>
    <input type="date" name="to" value="<?= e($to) ?>">
    <input type="text" name="kw" value="<?= e($kw) ?>" placeholder="搜索操作人/内容" style="width:180px">
    <button class="btn" type="submit">查询</button>
    <span style="color:var(--muted);font-size:13px">共 <?= $total ?> 条</span>
  </form>
  <div class="tbl-wrap"><table class="tbl">
    <tr><th>时间</th><th>类型</th><th>操作人</th><th>操作</th><th>详情</th><th>IP</th></tr>
    <?php foreach ($list as $r): ?>
    <tr>
      <td style="white-space:nowrap"><?= e($r['created_at']) ?></td>
      <td><?php
        $badge = ['司机上传' => 'background:#eff6ff;color:#1d4ed8', '后台补录' => 'background:#f0fdf4;color:#15803d', 'AI识别' => 'background:#faf5ff;color:#7e22ce'];
        $sty = $badge[$r['ltype']] ?? 'background:#f1f5f9;color:#475569';
      ?><span style="padding:2px 8px;border-radius:10px;font-size:12px;<?= $sty ?>"><?= e($r['ltype']) ?></span></td>
      <td><?= e($r['actor']) ?></td>
      <td><?= e($r['action']) ?></td>
      <td style="word-break:break-all"><?= e($r['detail']) ?></td>
      <td style="color:var(--muted)"><?= e($r['ip']) ?></td>
    </tr>
    <?php endforeach; ?>
    <?php if (!$list): ?><tr><td colspan="6" style="text-align:center;color:var(--muted)">暂无日志</td></tr><?php endif; ?>
  </table></div>
  <?php if ($pages > 1): ?>
  <div class="pager">
    <?php
    $qs = function ($p) use ($ltype, $kw, $from, $to) {
        return 'oplog.php?' . http_build_query(['ltype' => $ltype, 'kw' => $kw, 'from' => $from, 'to' => $to, 'page' => $p]);
    };
    for ($i = 1; $i <= $pages; $i++): ?>
      <a class="<?= $i === $page ? 'on' : '' ?>" href="<?= e($qs($i)) ?>"><?= $i ?></a>
    <?php endfor; ?>
  </div>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
