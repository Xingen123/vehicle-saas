<?php
require_once __DIR__ . '/functions.php';

if (session_status() === PHP_SESSION_NONE) {
    session_name('VMSESSID');
    session_start();
}

/**
 * 老库硬停摆：代码已是 SaaS 版但数据库未执行 upgrade_saas.sql 时，
 * 所有后台页面直接提示升级，避免租户字段缺失导致数据错乱或 500。
 */
function saas_guard(): void
{
    if (!saas_ready()) {
        http_response_code(503);
        echo '<!DOCTYPE html><html lang="zh-CN"><head><meta charset="UTF-8"><title>数据库待升级</title></head>
<body style="font-family:sans-serif;max-width:640px;margin:80px auto;padding:0 20px;line-height:1.8">
<h2>⚠️ 数据库待升级（SaaS 多租户）</h2>
<p>系统程序已升级为多租户 SaaS 版，但数据库尚未升级。<b>在升级完成前，系统已暂停服务，您的数据是安全的。</b></p>
<p>请管理员在 phpMyAdmin 中选中 <b>' . e(DB_NAME) . '</b> 数据库，导入执行程序包根目录下的 <b>upgrade_saas.sql</b>（幂等脚本，不影响现有数据），完成后刷新本页即可恢复使用。</p>
<p style="color:#64748b">升级后：原有数据自动归属租户「我的企业」，原账号正常登录；平台超管账号 superadmin / super123（请尽快修改）。</p>
</body></html>';
        exit;
    }
}

function current_user(): ?array
{
    if (empty($_SESSION['uid'])) return null;
    saas_guard();
    static $u = null;
    if ($u === null) {
        $u = q1('SELECT id, tenant_id, username, name, role, email FROM users WHERE id = ? AND status = 1', [$_SESSION['uid']]);
        if (!$u) { session_destroy(); return null; }
        // 所属企业被停用：强制下线
        if ((int)$u['tenant_id'] > 0) {
            $t = q1('SELECT status FROM tenants WHERE id = ?', [$u['tenant_id']]);
            if ($t && $t['status'] === '停用') { session_destroy(); return null; }
        }
    }
    return $u;
}

function require_login(): array
{
    $u = current_user();
    if (!$u) redirect('login.php');
    // 超管只进租户管理后台，不进入企业业务页面
    if ($u['role'] === 'super') {
        $page = basename($_SERVER['SCRIPT_NAME'] ?? '');
        if (!in_array($page, ['tenants.php', 'logout.php'], true)) {
            redirect('tenants.php');
        }
    }
    return $u;
}

function is_super(): bool
{
    $u = current_user();
    return $u && $u['role'] === 'super';
}

function require_super(): array
{
    $u = require_login();
    if ($u['role'] !== 'super') {
        http_response_code(403);
        exit('权限不足：该操作需要平台超级管理员。');
    }
    return $u;
}

/** 管理员+财务可执行增删改 */
function can_manage(): bool
{
    $u = current_user();
    return $u && in_array($u['role'], ['admin', 'finance'], true);
}

function require_manage(): array
{
    $u = require_login();
    if (!can_manage()) {
        http_response_code(403);
        exit('权限不足：该操作需要管理员或财务角色。');
    }
    return $u;
}

function require_admin(): array
{
    $u = require_login();
    if ($u['role'] !== 'admin') {
        http_response_code(403);
        exit('权限不足：该操作需要管理员角色。');
    }
    return $u;
}
