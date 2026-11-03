<?php
/**
 * 到期提醒邮件定时脚本（命令行运行，多租户版）
 * 用法：php /www/wwwroot/vehicle-system/cron/remind.php
 * 建议 crontab 每天 8:00 执行一次：
 *   0 8 * * * /usr/bin/php /www/wwwroot/vehicle-system/cron/remind.php >> /www/wwwroot/vehicle-system/logs/remind.log 2>&1
 *
 * 每个状态正常的企业独立核算、独立发送：
 *  - 业务提醒：保险/检车/保养到期（收件人=该企业设置的邮箱+该企业用户邮箱）
 *  - 套餐到期提醒：到期前 7 天内提醒一次（rtype=tenant_expire）
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('仅允许命令行执行'); }

require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/smtp.php';

$log = function (string $msg) { echo '[' . date('Y-m-d H:i:s') . "] $msg\n"; };

if (!saas_ready()) {
    $log('数据库未升级为多租户结构，请先在 phpMyAdmin 中执行 upgrade_saas.sql');
    exit(1);
}

$tenants = q("SELECT * FROM tenants WHERE status = '正常' ORDER BY id");
if (!$tenants) { $log('无正常状态的企业，退出'); exit(0); }

$totalSent = 0;

foreach ($tenants as $t) {
    $tid = (int)$t['id'];
    $tname = $t['name'];

    // 收件人：该企业设置中的邮箱 + 该企业启用用户的邮箱
    $emails = array_filter(array_map('trim', preg_split('/[,，;\s]+/', get_setting_for($tid, 'notify_emails'))));
    foreach (q('SELECT email FROM users WHERE tenant_id = ? AND status = 1 AND email != ""', [$tid]) as $r) $emails[] = $r['email'];
    $emails = array_values(array_unique($emails));

    // ---------- 套餐到期提醒（到期前 7 天内，仅提醒一次） ----------
    if ($t['expires_at']) {
        $daysLeft = (int)((strtotime($t['expires_at']) - strtotime(date('Y-m-d'))) / 86400);
        if ($daysLeft >= 0 && $daysLeft <= 7 && $emails) {
            $sent = q1('SELECT id FROM reminder_log WHERE tenant_id = ? AND rtype = ? AND ref_id = ? AND due_key = ?',
                [$tid, 'tenant_expire', $tid, $t['expires_at']]);
            if (!$sent) {
                $siteName = get_setting_for($tid, 'site_name', '车辆管理系统');
                $html = '<div style="max-width:680px;font-family:PingFang SC,Microsoft YaHei,sans-serif">
  <h2 style="color:#1e293b">' . e($siteName) . ' · 服务到期提醒</h2>
  <p>尊敬的用户：</p>
  <p>贵企业「<b>' . e($tname) . '</b>」的车辆管理服务将于 <b style="color:#dc2626">' . e($t['expires_at']) . '</b> 到期（剩余 ' . $daysLeft . ' 天）。</p>
  <p>到期后系统将转为<b>只读状态</b>（可查看和导出历史数据，但无法新增或修改记录，司机扫码上报也将暂停）。为避免影响使用，请提前联系平台方续期。</p>
  <p style="color:#64748b;margin-top:16px">登录系统：<a href="' . BASE_URL . '">' . BASE_URL . '</a></p>
  <p style="color:#94a3b8;font-size:12px">本邮件由系统自动发送，请勿直接回复。</p>
</div>';
                [$ok, $err] = send_notify_email($emails, "【{$siteName}】服务将于 {$t['expires_at']} 到期，请及时续期", $html);
                if ($ok > 0) {
                    exec_sql('INSERT IGNORE INTO reminder_log (tenant_id, rtype, ref_id, due_key) VALUES (?, ?, ?, ?)',
                        [$tid, 'tenant_expire', $tid, $t['expires_at']]);
                    $log("[{$tname}] 套餐到期提醒已发送（{$t['expires_at']} 到期）");
                    $totalSent++;
                } else {
                    $log("[{$tname}] 套餐到期提醒发送失败：$err");
                }
            }
        }
    }

    // ---------- 业务到期提醒 ----------
    if (!$emails) { $log("[{$tname}] 未配置收件邮箱，跳过业务提醒"); continue; }

    $reminders = collect_reminders($tid);
    if (!$reminders) { $log("[{$tname}] 今日无到期事项"); continue; }

    // 过滤掉已发送过的（同记录、同到期日只发一次）
    $toSend = [];
    foreach ($reminders as $r) {
        $dueKey = $r['due_date'] === '里程到期' ? date('Y-m-d') : $r['due_date'];
        // 里程类提醒每 3 天重复提醒一次
        if ($r['due_date'] === '里程到期') {
            $dueKey = date('Y-m-d', strtotime('today') - (strtotime('today') / 86400 % 3) * 86400);
        }
        $sent = q1('SELECT id FROM reminder_log WHERE tenant_id = ? AND rtype = ? AND ref_id = ? AND due_key = ?',
            [$tid, $r['type'], $r['ref_id'], $dueKey]);
        if (!$sent) {
            $r['_due_key'] = $dueKey;
            $toSend[] = $r;
        }
    }

    if (!$toSend) { $log("[{$tname}] 到期事项均已提醒过，无新增"); continue; }

    // 组装邮件
    $siteName = get_setting_for($tid, 'site_name', '车辆管理系统');
    $rows = '';
    foreach ($toSend as $r) {
        $color = $r['days_left'] < 0 ? '#dc2626' : ($r['days_left'] <= 7 ? '#d97706' : '#2563eb');
        $left = $r['due_date'] === '里程到期' ? '里程到期' : ($r['days_left'] < 0 ? '已过期 ' . (-$r['days_left']) . ' 天' : '剩余 ' . $r['days_left'] . ' 天');
        $rows .= '<tr>
      <td style="padding:8px;border:1px solid #e2e8f0"><b>' . e($r['vehicle']) . '</b></td>
      <td style="padding:8px;border:1px solid #e2e8f0">' . e($r['type_label']) . '</td>
      <td style="padding:8px;border:1px solid #e2e8f0">' . e($r['due_date']) . '</td>
      <td style="padding:8px;border:1px solid #e2e8f0;color:' . $color . ';font-weight:bold">' . e($left) . '</td>
      <td style="padding:8px;border:1px solid #e2e8f0">' . e($r['desc']) . '</td>
    </tr>';
    }
    $html = '<div style="max-width:680px;font-family:PingFang SC,Microsoft YaHei,sans-serif">
  <h2 style="color:#1e293b">' . e($siteName) . ' · 到期提醒</h2>
  <p style="color:#64748b">截至 ' . date('Y-m-d') . '，共有 <b style="color:#dc2626">' . count($toSend) . '</b> 项事项需要处理：</p>
  <table style="border-collapse:collapse;width:100%;font-size:14px">
    <tr style="background:#f8fafc">
      <th style="padding:8px;border:1px solid #e2e8f0">车牌</th><th style="padding:8px;border:1px solid #e2e8f0">类型</th>
      <th style="padding:8px;border:1px solid #e2e8f0">到期日</th><th style="padding:8px;border:1px solid #e2e8f0">紧急程度</th>
      <th style="padding:8px;border:1px solid #e2e8f0">说明</th>
    </tr>' . $rows . '
  </table>
  <p style="color:#64748b;margin-top:16px">请登录系统处理：<a href="' . BASE_URL . '">' . BASE_URL . '</a></p>
  <p style="color:#94a3b8;font-size:12px">本邮件由系统自动发送，请勿直接回复。</p>
</div>';

    [$ok, $err] = send_notify_email($emails, "【{$siteName}】到期提醒：" . count($toSend) . " 项事项待处理（" . date('Y-m-d') . '）', $html);

    if ($ok > 0) {
        $st = db()->prepare('INSERT IGNORE INTO reminder_log (tenant_id, rtype, ref_id, due_key) VALUES (?, ?, ?, ?)');
        foreach ($toSend as $r) $st->execute([$tid, $r['type'], $r['ref_id'], $r['_due_key']]);
        $log("[{$tname}] 已发送提醒邮件给 " . count($emails) . " 人，含 " . count($toSend) . " 项事项");
        $totalSent++;
    } else {
        $log("[{$tname}] 发送失败：$err");
    }
}

$log("全部企业处理完毕，共发送 {$totalSent} 封邮件");
