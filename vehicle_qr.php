<?php
require_once __DIR__ . '/includes/auth.php';
$u = require_login();

$tid = tid();
$id = (int)($_GET['id'] ?? 0);
$v = q1('SELECT * FROM vehicles WHERE id = ? AND tenant_id = ?', [$id, $tid]);
if (!$v) { flash('车辆不存在', 'error'); redirect('vehicles.php'); }

// 重置令牌（管理员/财务）
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check(); require_manage(); require_write();
    exec_sql('UPDATE vehicles SET upload_token = ? WHERE id = ? AND tenant_id = ?', [gen_token(), $id, $tid]);
    flash('二维码已重置，旧二维码立即失效，请重新打印张贴');
    redirect('vehicle_qr.php?id=' . $id);
}

$uploadUrl = BASE_URL . '/h5/index.php?t=' . $v['upload_token'];

$pageTitle = '司机扫码二维码 - ' . $v['plate_no']; $active = 'vehicles';
require __DIR__ . '/includes/header.php';
?>
<div class="card">
  <div class="toolbar no-print">
    <a class="btn btn-gray" href="vehicles.php">← 返回列表</a>
    <button class="btn" onclick="window.print()">🖨️ 打印二维码</button>
    <?php if (can_manage()): ?>
    <form method="post" onsubmit="return confirm('重置后旧二维码立即失效，确认？')">
      <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
      <button class="btn btn-danger" type="submit">重置二维码（旧码失效）</button>
    </form>
    <?php endif; ?>
  </div>
  <div class="qr-print">
    <h2 style="font-size:22px"><?= e($v['plate_no']) ?></h2>
    <p style="color:var(--muted)"><?= e(trim($v['brand'] . ' ' . $v['model'])) ?></p>
    <div id="qrcode"></div>
    <p style="margin-top:8px">司机扫码自助上报</p>
    <p style="color:var(--muted);font-size:12px;margin-top:4px"><?= $v['fuel_type'] === '新能源' ? '（满电 / 零散补电 / 外部快充上报）' : '（加油金额 + 仪表里程拍照上传）' ?></p>
    <p class="no-print" style="color:var(--muted);font-size:12px;margin-top:14px;word-break:break-all"><?= e($uploadUrl) ?></p>
  </div>
</div>
<script src="assets/js/qrcode.min.js"></script>
<script>
var qr = qrcode(0, 'M');
qr.addData(<?= json_encode($uploadUrl) ?>);
qr.make();
document.getElementById('qrcode').innerHTML = qr.createSvgTag({cellSize: 6, margin: 2, scalable: true});
var svg = document.querySelector('#qrcode svg');
if (svg) { svg.setAttribute('width', '240'); svg.setAttribute('height', '240'); }
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>
