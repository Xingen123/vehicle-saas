<?php
/**
 * 司机端 AI 识别接口（免登录，凭车辆二维码令牌访问）
 * POST: t=车辆token, kind=receipt_fuel|mileage|receipt_charge, photo=图片文件
 * 返回 JSON: {"ok":1,"fields":{"amount":300.00}} 或 {"ok":0,"msg":"识别失败"}
 */
require_once dirname(__DIR__) . '/includes/functions.php';

header('Content-Type: application/json; charset=utf-8');

function ai_fail(string $msg): void
{
    echo json_encode(['ok' => 0, 'msg' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') ai_fail('请求方式无效');

$token = preg_replace('/[^a-f0-9]/', '', $_POST['t'] ?? '');
$kind  = $_POST['kind'] ?? '';

$v = $token ? q1("SELECT * FROM vehicles WHERE upload_token = ? AND status != '停用'", [$token]) : null;
if (!$v) ai_fail('车辆信息无效');

if (!in_array($kind, ['receipt_fuel', 'mileage', 'receipt_charge'], true)) ai_fail('识别类型无效');

// 租户停用/到期时不提供识别服务
if (saas_ready()) {
    $t = q1('SELECT * FROM tenants WHERE id = ?', [$v['tenant_id']]);
    if ($t && $t['status'] === '停用') ai_fail('企业账号已停用');
    if ($t && $t['expires_at'] && $t['expires_at'] < date('Y-m-d')) ai_fail('企业账号已到期');
}

if (!ai_enabled((int)$v['tenant_id'])) ai_fail('AI识别未配置，请联系管理人员在系统设置中填写 API Key');

$file = $_FILES['photo'] ?? [];
if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !@getimagesize($file['tmp_name'])) {
    ai_fail('照片无效，请重新拍摄');
}

$kindNames = ['receipt_fuel' => '加油凭证', 'mileage' => '仪表里程', 'receipt_charge' => '充电凭证'];
$t0 = microtime(true);
$res = ai_vision_recognize((int)$v['tenant_id'], $file['tmp_name'], $kind);
$cost = round(microtime(true) - $t0, 1);

if ($res['ok']) {
    $kv = [];
    foreach ($res['fields'] as $k => $val) $kv[] = $k . '=' . $val;
    log_op((int)$v['tenant_id'], 'AI识别', $v['plate_no'], ($kindNames[$kind] ?? $kind) . ' 成功', '结果: ' . implode(' ', $kv) . "，耗时 {$cost}s");
} else {
    log_op((int)$v['tenant_id'], 'AI识别', $v['plate_no'], ($kindNames[$kind] ?? $kind) . ' 失败', ($res['detail'] ?? $res['msg'] ?? '') . "，耗时 {$cost}s");
}
unset($res['detail']); // 失败详情只进日志，不暴露给司机端
echo json_encode($res, JSON_UNESCAPED_UNICODE);
