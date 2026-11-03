<?php
/**
 * 司机扫码入口菜单页（免登录，凭车辆二维码令牌访问）
 * 按车辆能源类型显示「加油上报」或「充电上报」入口
 */
require_once dirname(__DIR__) . '/includes/functions.php';

$token = preg_replace('/[^a-f0-9]/', '', $_GET['t'] ?? '');
$v = $token ? q1("SELECT * FROM vehicles WHERE upload_token = ? AND status != '停用'", [$token]) : null;

// 租户状态检查：企业被停用或已到期时，司机端停止服务
$tenantMsg = '';
if ($v && saas_ready()) {
    $t = q1('SELECT * FROM tenants WHERE id = ?', [$v['tenant_id']]);
    if ($t && $t['status'] === '停用') $tenantMsg = '所属企业账号已停用，请联系管理人员';
    elseif ($t && $t['expires_at'] && $t['expires_at'] < date('Y-m-d')) $tenantMsg = '所属企业账号已到期，请联系管理人员续期';
    if ($tenantMsg) $v = null;
}

$siteName = $v ? get_setting_for((int)$v['tenant_id'], 'site_name', '车辆管理系统') : (defined('PLATFORM_NAME') ? PLATFORM_NAME : '车辆管理系统');
$isEV = $v && $v['fuel_type'] === '新能源';
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
<title>司机自助上报 - <?= e($siteName) ?></title>
<link rel="stylesheet" href="../assets/css/style.css">
<style>
.menu-btn { display: block; background: #fff; border-radius: 12px; margin: 14px; padding: 24px 18px; text-align: center; color: var(--text); box-shadow: 0 1px 4px rgba(0,0,0,.08); }
.menu-btn .ico { font-size: 40px; }
.menu-btn b { display: block; font-size: 17px; margin-top: 8px; }
.menu-btn span { color: var(--muted); font-size: 13px; }
</style>
</head>
<body>
<div class="h5-body">
<?php if (!$v): ?>
  <div class="h5-ok">
    <div class="ico">⚠️</div>
    <h2><?= $tenantMsg ? e($tenantMsg) : '二维码无效或已失效' ?></h2>
    <?php if (!$tenantMsg): ?><p style="color:var(--muted);margin-top:10px">请联系管理人员重新获取车辆二维码</p><?php endif; ?>
  </div>
<?php else: ?>
  <div class="h5-head">
    <h1>🚗 <?= e($v['plate_no']) ?></h1>
    <p><?= e(trim($v['brand'] . ' ' . $v['model'])) ?>　<?= e($v['fuel_type']) ?>　当前里程 <?= number_format($v['current_mileage']) ?> km</p>
  </div>
  <?php if ($isEV): ?>
  <a class="menu-btn" href="charge.php?t=<?= e($token) ?>">
    <div class="ico">🔋</div>
    <b>充电上报</b>
    <span>充满电 / 零散补电 / 外部快充登记</span>
  </a>
  <?php else: ?>
  <a class="menu-btn" href="upload.php?t=<?= e($token) ?>">
    <div class="ico">⛽</div>
    <b>加油上报</b>
    <span>拍照上传加油金额凭证 + 仪表里程</span>
  </a>
  <?php endif; ?>
  <p style="text-align:center;color:var(--muted);font-size:12px;margin-top:24px"><?= e($siteName) ?></p>
<?php endif; ?>
</div>
</body>
</html>
