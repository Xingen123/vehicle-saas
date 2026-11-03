<?php
/**
 * 平台超管后台 —— 租户（企业）管理
 * 仅 role=super 可访问：开通企业、调整套餐、停用/启用、重置企业管理员密码
 * 不提供删除租户功能（避免误删企业数据），停用即可切断访问
 */
require_once __DIR__ . '/includes/auth.php';
$u = require_super();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $act = $_POST['act'] ?? '';

    if ($act === 'create') {
        // 开通新企业 + 初始管理员账号
        $name     = trim($_POST['name'] ?? '');
        $contact  = trim($_POST['contact_name'] ?? '');
        $phone    = trim($_POST['contact_phone'] ?? '');
        $plan     = trim($_POST['plan_name'] ?? '试用版') ?: '试用版';
        $maxV     = max(0, (int)($_POST['max_vehicles'] ?? 5));
        $expires  = $_POST['expires_at'] ?: null;
        $username = trim($_POST['username'] ?? '');
        $adminName= trim($_POST['admin_name'] ?? '') ?: '管理员';
        $pass     = (string)($_POST['password'] ?? '');

        if ($name === '' || $username === '' || strlen($pass) < 6) {
            flash('企业名称、管理员登录名必填，初始密码至少 6 位', 'error');
        } elseif (q1('SELECT id FROM users WHERE username = ?', [$username])) {
            flash('管理员登录名已被占用（全平台唯一），请换一个', 'error');
        } else {
            exec_sql('INSERT INTO tenants (name, contact_name, contact_phone, plan_name, max_vehicles, expires_at, status) VALUES (?,?,?,?,?,?,\'正常\')',
                [$name, $contact, $phone, $plan, $maxV, $expires]);
            $newTid = (int)last_id();
            exec_sql('INSERT INTO users (tenant_id, username, password, name, role, email) VALUES (?,?,?,?,\'admin\',\'\')',
                [$newTid, $username, password_hash($pass, PASSWORD_DEFAULT), $adminName]);
            seed_tenant_settings($newTid);
            flash("企业「{$name}」已开通，管理员账号 {$username} 可登录");
        }
        redirect('tenants.php');
    }

    if ($act === 'edit') {
        $id      = (int)($_POST['id'] ?? 0);
        $name    = trim($_POST['name'] ?? '');
        $contact = trim($_POST['contact_name'] ?? '');
        $phone   = trim($_POST['contact_phone'] ?? '');
        $plan    = trim($_POST['plan_name'] ?? '试用版') ?: '试用版';
        $maxV    = max(0, (int)($_POST['max_vehicles'] ?? 0));
        $expires = $_POST['expires_at'] ?: null;
        $remark  = trim($_POST['remark'] ?? '');
        if ($id <= 0 || $name === '') {
            flash('企业名称必填', 'error');
        } else {
            exec_sql('UPDATE tenants SET name=?, contact_name=?, contact_phone=?, plan_name=?, max_vehicles=?, expires_at=?, remark=? WHERE id=?',
                [$name, $contact, $phone, $plan, $maxV, $expires, $remark, $id]);
            flash('企业信息已保存');
        }
        redirect('tenants.php');
    }

    if ($act === 'toggle') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id === 1) {
            flash('默认企业（id=1）不可停用', 'error');
        } else {
            exec_sql("UPDATE tenants SET status = IF(status='正常','停用','正常') WHERE id = ?", [$id]);
            flash('企业状态已切换（停用后该企业所有用户立即无法登录）');
        }
        redirect('tenants.php');
    }

    if ($act === 'reset_pwd') {
        $id   = (int)($_POST['id'] ?? 0);       // 租户ID
        $pass = (string)($_POST['password'] ?? '');
        if (strlen($pass) < 6) {
            flash('新密码至少 6 位', 'error');
        } else {
            $admin = q1("SELECT id, username FROM users WHERE tenant_id = ? AND role = 'admin' ORDER BY id LIMIT 1", [$id]);
            if (!$admin) {
                flash('该企业没有管理员账号', 'error');
            } else {
                exec_sql('UPDATE users SET password = ? WHERE id = ?', [password_hash($pass, PASSWORD_DEFAULT), $admin['id']]);
                flash("已重置企业管理员 {$admin['username']} 的密码");
            }
        }
        redirect('tenants.php');
    }
}

// 编辑模式
$editId = (int)($_GET['edit'] ?? 0);
$editRow = $editId ? q1('SELECT * FROM tenants WHERE id = ?', [$editId]) : null;

// 租户列表 + 用量统计
$list = q(
    'SELECT t.*,
            (SELECT COUNT(*) FROM vehicles v WHERE v.tenant_id = t.id) vehicle_count,
            (SELECT COUNT(*) FROM users u2 WHERE u2.tenant_id = t.id) user_count,
            (SELECT u3.username FROM users u3 WHERE u3.tenant_id = t.id AND u3.role = \'admin\' ORDER BY u3.id LIMIT 1) admin_username
     FROM tenants t ORDER BY t.id'
);
$today = date('Y-m-d');

$pageTitle = '租户管理'; $active = 'tenants';
require __DIR__ . '/includes/header.php';
?>

<?php if ($editRow): ?>
<div class="card">
  <h3>编辑企业：<?= e($editRow['name']) ?></h3>
  <form method="post">
    <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
    <input type="hidden" name="act" value="edit">
    <input type="hidden" name="id" value="<?= $editRow['id'] ?>">
    <div class="form-grid">
      <div class="form-item"><label>企业名称 <span class="req">*</span></label><input type="text" name="name" value="<?= e($editRow['name']) ?>" required></div>
      <div class="form-item"><label>联系人</label><input type="text" name="contact_name" value="<?= e($editRow['contact_name']) ?>"></div>
      <div class="form-item"><label>联系电话</label><input type="text" name="contact_phone" value="<?= e($editRow['contact_phone']) ?>"></div>
      <div class="form-item"><label>套餐名称</label>
        <select name="plan_name">
          <?php foreach (['试用版','标准版','旗舰版','正式版'] as $p): ?>
          <option <?= $editRow['plan_name'] === $p ? 'selected' : '' ?>><?= $p ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-item"><label>车辆数上限（0=不限）</label><input type="number" min="0" name="max_vehicles" value="<?= (int)$editRow['max_vehicles'] ?>"></div>
      <div class="form-item"><label>到期日（留空=长期有效）</label><input type="date" name="expires_at" value="<?= e((string)$editRow['expires_at']) ?>"></div>
      <div class="form-item" style="grid-column:1/-1"><label>备注</label><textarea name="remark"><?= e($editRow['remark']) ?></textarea></div>
    </div>
    <div style="margin-top:16px">
      <button class="btn" type="submit">保存</button>
      <a class="btn btn-gray" href="tenants.php">取消</a>
    </div>
  </form>
</div>
<?php else: ?>
<div class="card">
  <h3>＋ 开通新企业</h3>
  <form method="post">
    <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
    <input type="hidden" name="act" value="create">
    <div class="form-grid">
      <div class="form-item"><label>企业名称 <span class="req">*</span></label><input type="text" name="name" required placeholder="如：哈尔滨XX物流有限公司"></div>
      <div class="form-item"><label>联系人</label><input type="text" name="contact_name"></div>
      <div class="form-item"><label>联系电话</label><input type="text" name="contact_phone"></div>
      <div class="form-item"><label>套餐</label>
        <select name="plan_name">
          <option>试用版</option><option>标准版</option><option>旗舰版</option><option>正式版</option>
        </select>
      </div>
      <div class="form-item"><label>车辆数上限（0=不限）</label><input type="number" min="0" name="max_vehicles" value="5"></div>
      <div class="form-item"><label>到期日（留空=长期有效）</label><input type="date" name="expires_at" value="<?= date('Y-m-d', strtotime('+30 days')) ?>"></div>
      <div class="form-item"><label>管理员登录名 <span class="req">*</span>（全平台唯一）</label><input type="text" name="username" required placeholder="企业的登录账号"></div>
      <div class="form-item"><label>管理员姓名</label><input type="text" name="admin_name" placeholder="默认：管理员"></div>
      <div class="form-item"><label>初始密码 <span class="req">*</span>（≥6位）</label><input type="text" name="password" required minlength="6" placeholder="告知企业后请其尽快修改"></div>
    </div>
    <div style="margin-top:16px"><button class="btn" type="submit">开通企业</button></div>
  </form>
</div>
<?php endif; ?>

<div class="card">
  <h3>企业列表（共 <?= count($list) ?> 家）</h3>
  <div class="tbl-wrap"><table class="tbl">
    <tr><th>ID</th><th>企业名称</th><th>联系人</th><th>套餐</th><th>车辆用量</th><th>用户数</th><th>到期日</th><th>状态</th><th>管理员</th><th>操作</th></tr>
    <?php foreach ($list as $t):
      $expired = $t['expires_at'] && $t['expires_at'] < $today;
      $nearExp = $t['expires_at'] && !$expired && (strtotime($t['expires_at']) - time()) <= 7 * 86400;
      $overLimit = (int)$t['max_vehicles'] > 0 && (int)$t['vehicle_count'] >= (int)$t['max_vehicles'];
    ?>
    <tr>
      <td><?= $t['id'] ?></td>
      <td><b><?= e($t['name']) ?></b><?php if ($t['remark']): ?><br><span style="color:var(--muted);font-size:12px"><?= e($t['remark']) ?></span><?php endif; ?></td>
      <td><?= e($t['contact_name']) ?><br><span style="color:var(--muted);font-size:12px"><?= e($t['contact_phone']) ?></span></td>
      <td><span class="tag tag-blue"><?= e($t['plan_name']) ?></span></td>
      <td><?= $t['vehicle_count'] ?><?= (int)$t['max_vehicles'] > 0 ? '/' . (int)$t['max_vehicles'] : '' ?>
        <?php if ($overLimit): ?><span class="tag tag-orange">已满</span><?php endif; ?></td>
      <td><?= $t['user_count'] ?></td>
      <td>
        <?php if (!$t['expires_at']): ?><span style="color:var(--muted)">长期</span>
        <?php elseif ($expired): ?><span class="tag tag-red"><?= e($t['expires_at']) ?> 已到期</span>
        <?php elseif ($nearExp): ?><span class="tag tag-orange"><?= e($t['expires_at']) ?></span>
        <?php else: ?><?= e($t['expires_at']) ?><?php endif; ?>
      </td>
      <td><?= $t['status'] === '正常' ? '<span class="tag tag-green">正常</span>' : '<span class="tag tag-gray">停用</span>' ?></td>
      <td><?= e((string)$t['admin_username']) ?></td>
      <td style="white-space:normal;min-width:200px">
        <a class="btn btn-gray btn-sm" href="tenants.php?edit=<?= $t['id'] ?>">编辑</a>
        <?php if ((int)$t['id'] !== 1): ?>
        <form method="post" style="display:inline" onsubmit="return confirm('确认切换该企业状态？停用后其所有用户立即无法登录。')">
          <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
          <input type="hidden" name="act" value="toggle">
          <input type="hidden" name="id" value="<?= $t['id'] ?>">
          <button class="btn btn-gray btn-sm"><?= $t['status'] === '正常' ? '停用' : '启用' ?></button>
        </form>
        <?php endif; ?>
        <form method="post" style="display:inline" onsubmit="return confirm('确认为该企业管理员重置密码？')">
          <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
          <input type="hidden" name="act" value="reset_pwd">
          <input type="hidden" name="id" value="<?= $t['id'] ?>">
          <input type="text" name="password" placeholder="新密码" style="width:80px" required minlength="6">
          <button class="btn btn-danger btn-sm">重置密码</button>
        </form>
      </td>
    </tr>
    <?php endforeach; ?>
  </table></div>
  <p style="color:var(--muted);font-size:13px;margin-top:10px">
    说明：到期企业自动转为<b>只读</b>（可查看导出，不能新增修改）；停用企业所有账号<b>立即无法登录</b>。
    企业数据不可删除，停用即可。超管账号本身请在数据库中直接修改密码（users 表 superadmin 行）。
  </p>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
