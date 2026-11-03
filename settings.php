<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/smtp.php';
$u = require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    require_write();
    if (($_POST['act'] ?? '') === 'test_mail') {
        $emails = array_filter(array_map('trim', preg_split('/[,，;\s]+/', $_POST['notify_emails'] ?? '')));
        [$ok, $err] = send_notify_email($emails, '【车辆管理系统】测试邮件', '<p>这是一封测试邮件，说明 SMTP 配置正确，到期提醒将发送到此邮箱。</p>');
        flash($ok > 0 ? '测试邮件发送成功，请查收' : '发送失败：' . $err, $ok > 0 ? 'success' : 'error');
    } else {
        set_setting('site_name', trim($_POST['site_name'] ?? '车辆管理系统'));
        set_setting('remind_days_insurance', (string)max(1, (int)$_POST['remind_days_insurance']));
        set_setting('remind_days_inspection', (string)max(1, (int)$_POST['remind_days_inspection']));
        set_setting('remind_days_maintenance', (string)max(1, (int)$_POST['remind_days_maintenance']));
        set_setting('remind_km_maintenance', (string)max(0, (int)$_POST['remind_km_maintenance']));
        set_setting('slow_charge_price', (string)max(0, (float)$_POST['slow_charge_price']));
        set_setting('notify_emails', trim($_POST['notify_emails'] ?? ''));
        set_setting('ai_api_base', rtrim(trim($_POST['ai_api_base'] ?? ''), '/'));
        set_setting('ai_api_key', trim($_POST['ai_api_key'] ?? ''));
        set_setting('ai_model', trim($_POST['ai_model'] ?? ''));
        if (($_POST['act'] ?? '') === 'test_ai') {
            [$aiOk, $aiMsg] = ai_test_connection(tid() ?: 1);
            flash('设置已保存。AI 连接测试' . ($aiOk ? '通过 ✅：' : '未通过 ❌：') . $aiMsg, $aiOk ? 'success' : 'error');
        } else {
            flash('设置已保存');
        }
    }
    redirect('settings.php');
}

$pageTitle = '系统设置'; $active = 'settings';
require __DIR__ . '/includes/header.php';
?>
<div class="card">
  <h3>提醒与基础设置</h3>
  <form method="post">
    <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
    <div class="form-grid">
      <div class="form-item"><label>系统名称</label><input type="text" name="site_name" value="<?= e(get_setting('site_name', '车辆管理系统')) ?>"></div>
      <div class="form-item"><label>保险提前提醒（天）</label><input type="number" min="1" name="remind_days_insurance" value="<?= e(get_setting('remind_days_insurance', '30')) ?>"></div>
      <div class="form-item"><label>检车提前提醒（天）</label><input type="number" min="1" name="remind_days_inspection" value="<?= e(get_setting('remind_days_inspection', '30')) ?>"></div>
      <div class="form-item"><label>保养提前提醒（天）</label><input type="number" min="1" name="remind_days_maintenance" value="<?= e(get_setting('remind_days_maintenance', '15')) ?>"></div>
      <div class="form-item"><label>保养里程提醒阈值（km）</label><input type="number" min="0" name="remind_km_maintenance" value="<?= e(get_setting('remind_km_maintenance', '500')) ?>"></div>
      <div class="form-item"><label>厂区慢充电价（元/度）</label><input type="number" step="0.01" min="0" name="slow_charge_price" value="<?= e(get_setting('slow_charge_price', '0.80')) ?>"></div>
      <div class="form-item" style="grid-column:1/-1">
        <label>提醒接收邮箱（多个用逗号分隔）</label>
        <input type="text" name="notify_emails" value="<?= e(get_setting('notify_emails')) ?>" placeholder="boss@example.com, finance@example.com">
      </div>
    </div>
    <h3 style="margin-top:26px">AI 凭证识别（选填）</h3>
    <p style="color:var(--muted);font-size:13px;margin:6px 0 12px">
      配置后，司机端上传凭证/仪表照片时将自动识别金额、里程、度数并预填，司机确认修改后提交；识别不了会提示"识别失败，请手动填写"。
      留空 API Key 即关闭该功能。接口需兼容 OpenAI 视觉对话格式，例如 Kimi（月之暗面）：API 地址 <code>https://api.moonshot.cn/v1</code>，模型 <code>kimi-k2.6</code>（旧的 moonshot-v1 系列已下线）。
    </p>
    <div class="form-grid">
      <div class="form-item"><label>AI 接口地址</label><input type="text" name="ai_api_base" value="<?= e(get_setting('ai_api_base', 'https://api.moonshot.cn/v1')) ?>" placeholder="https://api.moonshot.cn/v1"></div>
      <div class="form-item"><label>AI 模型</label><input type="text" name="ai_model" value="<?= e(get_setting('ai_model', 'kimi-k2.6')) ?>" placeholder="kimi-k2.6"></div>
      <div class="form-item" style="grid-column:1/-1"><label>AI API Key</label><input type="text" name="ai_api_key" value="<?= e(get_setting('ai_api_key')) ?>" placeholder="sk-...（留空则关闭 AI 识别）" autocomplete="off"></div>
    </div>
    <p style="font-size:13px;margin-top:8px">
      当前状态：<?= ai_enabled(tid() ?: 1) ? '<b style="color:#15803d">已配置 API Key，AI 识别已启用</b>' : '<b style="color:#b45309">未配置 API Key，AI 识别已关闭</b>' ?>
      &nbsp;<button class="btn btn-gray" type="submit" name="act" value="test_ai" formnovalidate style="padding:4px 14px;font-size:13px">测试 AI 连接</button>
      <span style="color:var(--muted)">（点击后会先保存设置，再让 AI 识别一张测试图并报告结果）</span>
    </p>
    <div style="margin-top:16px">
      <button class="btn" type="submit">保存设置</button>
      <button class="btn btn-gray" type="submit" name="act" value="test_mail" formnovalidate>发送测试邮件</button>
    </div>
    <p style="color:var(--muted);font-size:13px;margin-top:12px">
      SMTP 发件配置在 <code>config.php</code> 中修改；定时邮件需在服务器 crontab 中添加：<br>
      <code>0 8 * * * /usr/bin/php <?= e(dirname(__FILE__)) ?>/cron/remind.php >> <?= e(dirname(__FILE__)) ?>/logs/remind.log 2>&1</code>
    </p>
  </form>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
