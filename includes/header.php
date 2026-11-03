<?php
if (!function_exists('current_user')) require_once __DIR__ . '/auth.php';
$u = current_user();
$pageTitle = $pageTitle ?? '';
$active = $active ?? '';
$isSuper = $u && $u['role'] === 'super';
$tenant = $isSuper ? null : current_tenant();
$siteName = $isSuper
    ? (defined('PLATFORM_NAME') ? PLATFORM_NAME : '车辆管理系统')
    : get_setting('site_name', '车辆管理系统');

if ($isSuper) {
    $nav = [
        ['tenants.php', 'tenants', '🏢', '租户管理'],
    ];
} else {
    $nav = [
        ['index.php',       'dashboard',    '📊', '控制台'],
        ['vehicles.php',    'vehicles',     '🚗', '车辆管理'],
        ['insurance.php',   'insurance',    '🛡️', '保险记录'],
        ['inspection.php',  'inspection',   '🔍', '检车记录'],
        ['maintenance.php', 'maintenance',  '🔧', '保养记录'],
        ['fuel.php',        'fuel',         '⛽', '加油记录'],
        ['charge.php',      'charge',       '🔋', '充电记录'],
        ['repair.php',      'repair',       '🛠️', '维修管理'],
        ['reports.php',     'reports',      '📈', '统计报表'],
    ];
    if ($u && $u['role'] === 'admin') {
        $nav[] = ['users.php',    'users',    '👥', '用户管理'];
        $nav[] = ['oplog.php',    'logs',     '📋', '系统日志'];
        $nav[] = ['settings.php', 'settings', '⚙️', '系统设置'];
    }
}

// 租户套餐/到期状态横幅
$planBanner = '';
if ($tenant) {
    $used = vehicles_used((int)$tenant['id']);
    $max  = (int)$tenant['max_vehicles'];
    $maxTxt = $max > 0 ? "{$used}/{$max} 辆" : "{$used} 辆（不限量）";
    $expTxt = $tenant['expires_at'] ? $tenant['expires_at'] . ' 到期' : '长期有效';
    if (tenant_expired($tenant)) {
        $planBanner = '<div class="flash flash-error">⛔ <b>企业账号已到期（' . e($tenant['expires_at']) . '）</b>：系统已进入只读状态，可查看和导出数据，但无法新增或修改。如需续期请联系平台方。</div>';
    } elseif ($tenant['expires_at'] && (strtotime($tenant['expires_at']) - time()) <= 7 * 86400) {
        $planBanner = '<div class="flash flash-error" style="background:#fff7ed;border-color:#fdba74;color:#9a3412">⏰ 企业账号将于 <b>' . e($tenant['expires_at']) . '</b> 到期，到期后将转为只读，请提前联系平台方续期。</div>';
    } elseif ($max > 0 && $used >= $max) {
        $planBanner = '<div class="flash flash-error" style="background:#fff7ed;border-color:#fdba74;color:#9a3412">🚗 车辆数已达套餐上限（' . $maxTxt . '），无法继续新增车辆。如需扩容请联系平台方。</div>';
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($pageTitle) ?> - <?= e($siteName) ?></title>
<link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<div class="layout">
  <aside class="sidebar" id="sidebar">
    <div class="logo">🚛 <?= e($siteName) ?></div>
    <?php if ($tenant): ?>
    <div style="padding:8px 16px;font-size:12px;color:#94a3b8;border-bottom:1px solid rgba(255,255,255,.08)">
      <?= e($tenant['name']) ?><br><?= e($tenant['plan_name']) ?> · <?= e($expTxt) ?>
    </div>
    <?php endif; ?>
    <nav>
      <?php foreach ($nav as $n): ?>
      <a href="<?= $n[0] ?>" class="<?= $active === $n[1] ? 'active' : '' ?>"><span class="ico"><?= $n[2] ?></span><?= $n[3] ?></a>
      <?php endforeach; ?>
    </nav>
  </aside>
  <div class="main">
    <div class="topbar">
      <div>
        <button class="menu-toggle" onclick="document.getElementById('sidebar').classList.toggle('open')">☰</button>
        <b><?= e($pageTitle) ?></b>
      </div>
      <div class="who">
        <b><?= e($u['name'] ?? '') ?></b>（<?= e(role_name($u['role'] ?? '')) ?>）
        &nbsp;<a href="logout.php">退出</a>
      </div>
    </div>
    <?= $planBanner ?>
    <?php foreach (get_flashes() as $f): ?>
    <div class="flash flash-<?= e($f['type']) ?>"><?= e($f['msg']) ?></div>
    <?php endforeach; ?>
    <?php if ($u && $u['role'] === 'admin' && db_needs_upgrade()): ?>
    <div class="flash flash-error">
      ⚠️ <b>检测到数据库结构未升级</b>：程序已更新到含「充电记录」功能的版本，但数据库缺少对应表和字段，
      充电记录、统计报表等功能暂不可用。<br>
      请在 phpMyAdmin 中选中 vehicle_mgr 库，执行程序包根目录下的 <b>upgrade_ev.sql</b> 升级脚本（不影响现有数据），完成后本提示自动消失。
    </div>
    <?php endif; ?>
