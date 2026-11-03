<?php
require_once __DIR__ . '/includes/auth.php';

saas_guard();

if (current_user()) redirect('index.php');

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = (string)($_POST['password'] ?? '');
    $row = q1('SELECT * FROM users WHERE username = ? AND status = 1', [$username]);
    if ($row && password_verify($password, $row['password'])) {
        $tid = (int)$row['tenant_id'];
        if ($row['role'] === 'super') {
            // 平台超管：无租户上下文
            session_regenerate_id(true);
            $_SESSION['uid'] = $row['id'];
            $_SESSION['tenant_id'] = 0;
            redirect('tenants.php');
        }
        $t = q1('SELECT * FROM tenants WHERE id = ?', [$tid]);
        if (!$t) {
            $error = '账号所属企业不存在，请联系平台方';
        } elseif ($t['status'] === '停用') {
            $error = '所属企业账号已被停用，请联系平台方';
        } else {
            session_regenerate_id(true);
            $_SESSION['uid'] = $row['id'];
            $_SESSION['tenant_id'] = $tid;
            redirect('index.php');
        }
    } else {
        $error = '用户名或密码错误';
    }
}
$siteName = defined('PLATFORM_NAME') ? PLATFORM_NAME : '车辆管理系统';
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>登录 - <?= e($siteName) ?></title>
<link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<div class="login-wrap">
  <div class="login-box">
    <h1>🚛 <?= e($siteName) ?></h1>
    <?php if ($error): ?><div class="flash flash-error"><?= e($error) ?></div><?php endif; ?>
    <form method="post">
      <div class="form-item"><label>用户名</label><input type="text" name="username" required autofocus></div>
      <div class="form-item"><label>密码</label><input type="password" name="password" required></div>
      <button class="btn btn-block" type="submit">登 录</button>
    </form>
  </div>
</div>
</body>
</html>
