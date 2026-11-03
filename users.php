<?php
require_once __DIR__ . '/includes/auth.php';
$u = require_admin();
$tid = tid();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    require_write();
    $act = $_POST['act'] ?? '';
    $id = (int)($_POST['id'] ?? 0);

    if ($act === 'add') {
        $username = trim($_POST['username'] ?? '');
        $name = trim($_POST['name'] ?? '');
        $role = in_array($_POST['role'] ?? '', ['admin', 'boss', 'finance'], true) ? $_POST['role'] : 'finance';
        $email = trim($_POST['email'] ?? '');
        $pass = (string)($_POST['password'] ?? '');
        if ($username === '' || $name === '' || strlen($pass) < 6) {
            flash('用户名、姓名必填，密码至少 6 位', 'error');
        } elseif (q1('SELECT id FROM users WHERE username = ?', [$username])) {
            // 登录名全平台唯一（登录无需选企业，避免歧义）
            flash('用户名已存在（登录名全平台唯一，请换一个）', 'error');
        } else {
            exec_sql('INSERT INTO users (tenant_id, username, password, name, role, email) VALUES (?,?,?,?,?,?)',
                [$tid, $username, password_hash($pass, PASSWORD_DEFAULT), $name, $role, $email]);
            flash('用户已添加');
        }
    } elseif ($act === 'edit') {
        $name = trim($_POST['name'] ?? '');
        $role = in_array($_POST['role'] ?? '', ['admin', 'boss', 'finance'], true) ? $_POST['role'] : 'finance';
        $email = trim($_POST['email'] ?? '');
        $pass = (string)($_POST['password'] ?? '');
        if ($id === (int)$u['id'] && $role !== 'admin') {
            flash('不能降级自己的管理员权限', 'error');
        } else {
            exec_sql('UPDATE users SET name=?, role=?, email=? WHERE id=? AND tenant_id=?', [$name, $role, $email, $id, $tid]);
            if ($pass !== '') {
                if (strlen($pass) < 6) flash('密码至少 6 位，未修改', 'error');
                else { exec_sql('UPDATE users SET password=? WHERE id=? AND tenant_id=?', [password_hash($pass, PASSWORD_DEFAULT), $id, $tid]); flash('用户信息及密码已更新'); }
            } else {
                flash('用户信息已更新');
            }
        }
    } elseif ($act === 'toggle') {
        if ($id === (int)$u['id']) flash('不能停用自己', 'error');
        else { exec_sql('UPDATE users SET status = 1 - status WHERE id = ? AND tenant_id = ?', [$id, $tid]); flash('状态已切换'); }
    }
    redirect('users.php');
}

$list = q('SELECT * FROM users WHERE tenant_id = ? ORDER BY id', [$tid]);
$pageTitle = '用户管理'; $active = 'users';
require __DIR__ . '/includes/header.php';
?>
<div class="card">
  <h3>新增用户</h3>
  <form method="post" class="toolbar">
    <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
    <input type="hidden" name="act" value="add">
    <input type="text" name="username" placeholder="登录名*" required>
    <input type="text" name="name" placeholder="姓名*" required>
    <select name="role"><option value="finance">财务</option><option value="boss">老板</option><option value="admin">管理员</option></select>
    <input type="email" name="email" placeholder="邮箱（收提醒）">
    <input type="password" name="password" placeholder="初始密码（≥6位）*" required>
    <button class="btn">添加</button>
  </form>
</div>
<div class="card">
  <h3>用户列表</h3>
  <div class="tbl-wrap"><table class="tbl">
    <tr><th>登录名</th><th>姓名</th><th>角色</th><th>邮箱</th><th>状态</th><th>操作</th></tr>
    <?php foreach ($list as $x): ?>
    <tr>
      <td><?= e($x['username']) ?></td>
      <td><?= e($x['name']) ?></td>
      <td><span class="tag tag-blue"><?= e(role_name($x['role'])) ?></span></td>
      <td><?= e($x['email']) ?></td>
      <td><?= $x['status'] ? '<span class="tag tag-green">启用</span>' : '<span class="tag tag-gray">停用</span>' ?></td>
      <td>
        <form method="post" class="toolbar" style="margin:0">
          <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
          <input type="hidden" name="act" value="edit">
          <input type="hidden" name="id" value="<?= $x['id'] ?>">
          <input type="text" name="name" value="<?= e($x['name']) ?>" style="width:80px">
          <select name="role" style="width:auto">
            <?php foreach (['admin' => '管理员', 'boss' => '老板', 'finance' => '财务'] as $k => $n): ?>
            <option value="<?= $k ?>" <?= $x['role'] === $k ? 'selected' : '' ?>><?= $n ?></option>
            <?php endforeach; ?>
          </select>
          <input type="email" name="email" value="<?= e($x['email']) ?>" style="width:160px">
          <input type="password" name="password" placeholder="留空不改密码" style="width:120px">
          <button class="btn btn-sm">保存</button>
        </form>
        <form method="post" style="display:inline" onsubmit="return confirm('确认切换该用户状态？')">
          <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
          <input type="hidden" name="act" value="toggle">
          <input type="hidden" name="id" value="<?= $x['id'] ?>">
          <button class="btn btn-gray btn-sm"><?= $x['status'] ? '停用' : '启用' ?></button>
        </form>
      </td>
    </tr>
    <?php endforeach; ?>
  </table></div>
  <p style="color:var(--muted);font-size:13px;margin-top:10px">角色说明：<b>管理员</b>全部权限（含用户/设置/删除）；<b>财务</b>可录入和编辑各类记录；<b>老板</b>查看全部数据与报表（只读）。</p>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
