<?php
require_once __DIR__ . '/db.php';

/** HTML 转义输出 */
function e(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

/** 跳转 */
function redirect(string $url): void
{
    header('Location: ' . $url);
    exit;
}

/** 闪现消息 */
function flash(string $msg, string $type = 'success'): void
{
    $_SESSION['flash'][] = ['msg' => $msg, 'type' => $type];
}

function get_flashes(): array
{
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}

/** 当前登录用户所属租户ID（未登录/超管返回0） */
function tid(): int
{
    return (int)($_SESSION['tenant_id'] ?? 0);
}

/** 是否已升级为 SaaS 多租户结构（老库未执行 upgrade_saas.sql 时返回 false） */
function saas_ready(): bool
{
    return table_exists('tenants');
}

/** 读取指定租户的设置 */
function get_setting_for(int $tid, string $key, string $default = ''): string
{
    $row = q1('SELECT svalue FROM settings WHERE tenant_id = ? AND skey = ?', [$tid, $key]);
    return $row ? (string)$row['svalue'] : $default;
}

function set_setting_for(int $tid, string $key, string $value): void
{
    exec_sql('INSERT INTO settings (tenant_id, skey, svalue) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE svalue = VALUES(svalue)', [$tid, $key, $value]);
}

/** 读取当前租户设置（兼容旧调用） */
function get_setting(string $key, string $default = ''): string
{
    return get_setting_for(tid() ?: 1, $key, $default);
}

function set_setting(string $key, string $value): void
{
    set_setting_for(tid() ?: 1, $key, $value);
}

/** 为新租户写入一套默认设置 */
function seed_tenant_settings(int $tid): void
{
    $defaults = [
        'remind_days_insurance'   => '30',
        'remind_days_inspection'  => '30',
        'remind_days_maintenance' => '15',
        'remind_km_maintenance'   => '500',
        'slow_charge_price'       => '0.80',
        'notify_emails'           => '',
        'site_name'               => '车辆管理系统',
        'ai_api_base'             => 'https://api.moonshot.cn/v1',
        'ai_api_key'              => '',
        'ai_model'                => 'kimi-k2.6',
    ];
    foreach ($defaults as $k => $v) set_setting_for($tid, $k, $v);
}

/** 获取租户信息（带静态缓存） */
function current_tenant(): ?array
{
    static $cache = [];
    $id = tid();
    if ($id <= 0) return null;
    if (!array_key_exists($id, $cache)) {
        $cache[$id] = saas_ready() ? q1('SELECT * FROM tenants WHERE id = ?', [$id]) : null;
    }
    return $cache[$id];
}

/** 租户已过期（到期日早于今天） */
function tenant_expired(?array $t): bool
{
    return $t && $t['expires_at'] && $t['expires_at'] < date('Y-m-d');
}

/** 租户被停用 */
function tenant_blocked(?array $t): bool
{
    return $t && $t['status'] === '停用';
}

/** 租户只读（过期或停用）：可看数据，不能新增/修改/删除 */
function tenant_readonly(): bool
{
    $t = current_tenant();
    return tenant_expired($t) || tenant_blocked($t);
}

/**
 * 写操作前置检查：过期/停用租户禁止一切写操作。
 * 所有后台 POST 处理在 csrf_check() 之后调用。
 */
function require_write(): void
{
    if (tenant_readonly()) {
        http_response_code(403);
        exit('当前企业账号已到期或被停用，系统为只读状态，无法执行此操作。如需续期请联系平台方。');
    }
}

/** 租户已登记车辆数 */
function vehicles_used(int $tid): int
{
    return (int)(q1('SELECT COUNT(*) c FROM vehicles WHERE tenant_id = ?', [$tid])['c'] ?? 0);
}

/** 租户是否还能新增车辆（max_vehicles=0 表示不限） */
function tenant_can_add_vehicle(int $tid): bool
{
    $t = saas_ready() ? q1('SELECT max_vehicles FROM tenants WHERE id = ?', [$tid]) : null;
    if (!$t) return true;
    $max = (int)$t['max_vehicles'];
    return $max <= 0 || vehicles_used($tid) < $max;
}

/** 生成随机令牌 */
function gen_token(): string
{
    return bin2hex(random_bytes(16));
}

/** 金额格式化 */
function fmt_money($n): string
{
    return number_format((float)$n, 2);
}

/** 角色中文名 */
function role_name(string $role): string
{
    return ['super' => '超级管理员', 'admin' => '管理员', 'boss' => '老板', 'finance' => '财务'][$role] ?? $role;
}

/**
 * 计算某辆车的到期提醒集合（供仪表盘与定时邮件共用）
 * 返回数组，每项含 type/type_label/vehicle/ref_id/due_date/days_left/desc
 */
function collect_reminders(int $tid): array
{
    $dIns  = (int)get_setting_for($tid, 'remind_days_insurance', '30');
    $dInsp = (int)get_setting_for($tid, 'remind_days_inspection', '30');
    $dMt   = (int)get_setting_for($tid, 'remind_days_maintenance', '15');
    $kmMt  = (int)get_setting_for($tid, 'remind_km_maintenance', '500');
    $today = date('Y-m-d');
    $list  = [];

    // 保险到期（按每辆车最新一条保单）
    $rows = q(
        'SELECT i.*, v.plate_no FROM insurance_records i
         JOIN vehicles v ON v.id = i.vehicle_id
         WHERE v.tenant_id = ? AND i.id = (SELECT MAX(id) FROM insurance_records WHERE vehicle_id = i.vehicle_id)',
        [$tid]
    );
    foreach ($rows as $r) {
        $days = (int)((strtotime($r['end_date']) - strtotime($today)) / 86400);
        if ($days <= $dIns) {
            $list[] = [
                'type' => 'insurance', 'type_label' => '保险到期',
                'vehicle' => $r['plate_no'], 'ref_id' => $r['id'],
                'due_date' => $r['end_date'], 'days_left' => $days,
                'desc' => "{$r['ins_type']}（{$r['company']}）将于 {$r['end_date']} 到期",
            ];
        }
    }

    // 检车到期（按每辆车最新一条）
    $rows = q(
        'SELECT i.*, v.plate_no FROM inspection_records i
         JOIN vehicles v ON v.id = i.vehicle_id
         WHERE v.tenant_id = ? AND i.id = (SELECT MAX(id) FROM inspection_records WHERE vehicle_id = i.vehicle_id)',
        [$tid]
    );
    foreach ($rows as $r) {
        $days = (int)((strtotime($r['next_due_date']) - strtotime($today)) / 86400);
        if ($days <= $dInsp) {
            $list[] = [
                'type' => 'inspection', 'type_label' => '检车到期',
                'vehicle' => $r['plate_no'], 'ref_id' => $r['id'],
                'due_date' => $r['next_due_date'], 'days_left' => $days,
                'desc' => "应于 {$r['next_due_date']} 前完成年检",
            ];
        }
    }

    // 保养到期（按日期或里程，按每辆车最新一条）
    $rows = q(
        'SELECT m.*, v.plate_no, v.current_mileage FROM maintenance_records m
         JOIN vehicles v ON v.id = m.vehicle_id
         WHERE v.tenant_id = ? AND m.id = (SELECT MAX(id) FROM maintenance_records WHERE vehicle_id = m.vehicle_id)',
        [$tid]
    );
    foreach ($rows as $r) {
        $hit = false; $due = ''; $desc = ''; $days = 9999;
        if (!empty($r['next_date'])) {
            $days = (int)((strtotime($r['next_date']) - strtotime($today)) / 86400);
            if ($days <= $dMt) {
                $hit = true; $due = $r['next_date'];
                $desc = "按计划应于 {$r['next_date']} 前保养";
            }
        }
        if (!$hit && !empty($r['next_mileage'])) {
            $left = (int)$r['next_mileage'] - (int)$r['current_mileage'];
            if ($left <= $kmMt) {
                $hit = true; $due = '里程到期';
                $desc = "距下次保养里程仅剩 {$left} km（保养里程 {$r['next_mileage']} km）";
                $days = 0;
            }
        }
        if ($hit) {
            $list[] = [
                'type' => 'maintenance', 'type_label' => '保养到期',
                'vehicle' => $r['plate_no'], 'ref_id' => $r['id'],
                'due_date' => $due, 'days_left' => $days, 'desc' => $desc,
            ];
        }
    }

    // 按剩余天数排序
    usort($list, fn($a, $b) => $a['days_left'] <=> $b['days_left']);
    return $list;
}

/**
 * 安全保存上传的照片，返回相对文件名（如 2026/08/xxxx.jpg），失败返回 null
 */
function save_upload(array $file): ?string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) return null;
    if ($file['size'] > UPLOAD_MAX_SIZE) return null;

    $mime = '';
    if (class_exists('finfo')) {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime  = (string)$finfo->file($file['tmp_name']);
    } elseif (function_exists('mime_content_type')) {
        $mime = (string)@mime_content_type($file['tmp_name']);
    } else {
        $img = @getimagesize($file['tmp_name']);
        $mime = (string)($img['mime'] ?? '');
    }
    $extMap = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    if (!isset($extMap[$mime])) return null;

    $sub = date('Y/m');
    $dir = UPLOAD_DIR . '/' . $sub;
    if (!is_dir($dir)) mkdir($dir, 0755, true);

    $name = $sub . '/' . date('d_His_') . bin2hex(random_bytes(6)) . '.' . $extMap[$mime];
    if (!move_uploaded_file($file['tmp_name'], UPLOAD_DIR . '/' . $name)) return null;
    return $name;
}

/** 照片完整 URL */
function photo_url(string $name): string
{
    return BASE_URL . '/uploads/' . $name;
}

/** 更新车辆当前里程（只增不减） */
function touch_mileage(int $vehicleId, int $mileage): void
{
    if ($mileage > 0) {
        exec_sql('UPDATE vehicles SET current_mileage = GREATEST(current_mileage, ?) WHERE id = ?', [$mileage, $vehicleId]);
    }
}

/** CSRF */
function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
    return $_SESSION['csrf'];
}

function csrf_check(): void
{
    if (($_POST['csrf'] ?? '') !== ($_SESSION['csrf'] ?? null)) {
        http_response_code(403);
        exit('非法请求（CSRF 校验失败），请返回重试。');
    }
}

/** 判断数据表是否存在 */
function table_exists(string $table): bool
{
    static $cache = [];
    if (!array_key_exists($table, $cache)) {
        // SHOW 语句不支持预处理占位符，改用 information_schema
        $cache[$table] = (bool)q1(
            'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [$table]
        );
    }
    return $cache[$table];
}

/** 检测数据库是否缺少新能源功能（老库未执行 upgrade_ev.sql） */
function db_needs_upgrade(): bool
{
    return !table_exists('charge_records');
}

/**
 * 满电周期核算（方案1：整车累计里程分段核算）
 * 新增一条「满电」记录后调用：与该车最近一次满电记录配对生成周期。
 * 返回：['ok'=>bool, 'msg'=>string]
 */
function create_charge_cycle(int $vehicleId, int $endRecordId): array
{
    $end = q1('SELECT * FROM charge_records WHERE id = ? AND charge_type = "满电"', [$endRecordId]);
    if (!$end) return ['ok' => false, 'msg' => '满电记录不存在'];

    // 已核算过则不重复
    if (q1('SELECT id FROM charge_cycles WHERE end_record_id = ?', [$endRecordId])) {
        return ['ok' => true, 'msg' => '该满电记录已生成过周期'];
    }

    $start = q1(
        'SELECT * FROM charge_records WHERE vehicle_id = ? AND charge_type = "满电" AND id < ? ORDER BY id DESC LIMIT 1',
        [$vehicleId, $endRecordId]
    );
    if (!$start) return ['ok' => true, 'msg' => '首个满电记录，作为周期起点，暂不生成周期'];

    if ((int)$end['mileage'] <= (int)$start['mileage']) {
        return ['ok' => false, 'msg' => '本次满电里程不大于上次满电里程，未生成周期（请检查里程是否填错）'];
    }

    $v = q1('SELECT tenant_id, energy_consumption FROM vehicles WHERE id = ?', [$vehicleId]);
    $consumption = (float)($v['energy_consumption'] ?? 0);
    $vTid = (int)($v['tenant_id'] ?? 1);
    $distance = (int)$end['mileage'] - (int)$start['mileage'];

    // 周期内的外部快充真实度数，从估算电量中扣除避免重复计费
    // 时间范围 + ID 边界双重限定，兼容同秒提交与后台补录
    $ext = q1(
        'SELECT COALESCE(SUM(kwh),0) k FROM charge_records
         WHERE vehicle_id = ? AND charge_type = "外部快充"
           AND charge_time >= ? AND charge_time <= ? AND id > ? AND id < ?',
        [$vehicleId, $start['charge_time'], $end['charge_time'], $start['id'], $endRecordId]
    );
    $extKwh = (float)($ext['k'] ?? 0);

    // 用车辆所属租户的慢充电价（H5 司机端无登录态，不能依赖会话租户）
    $slowPrice = (float)get_setting_for($vTid, 'slow_charge_price', '0.80');

    if ($consumption > 0) {
        $estKwh  = round($distance / 100 * $consumption, 2);
        $slowKwh = max(0, round($estKwh - $extKwh, 2));
        $slowCost = round($slowKwh * $slowPrice, 2);
    } else {
        // 未预设百公里电耗：仅记里程，电量费用留空，待补参数后可重算
        $estKwh = $slowKwh = $slowCost = null;
    }

    exec_sql(
        'INSERT INTO charge_cycles (tenant_id,vehicle_id,start_record_id,end_record_id,start_mileage,end_mileage,distance,est_kwh,ext_kwh,slow_kwh,slow_price,slow_cost,start_time,end_time)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
        [$vTid, $vehicleId, $start['id'], $endRecordId, $start['mileage'], $end['mileage'],
         $distance, $estKwh, $extKwh, $slowKwh, $slowPrice, $slowCost,
         $start['charge_time'], $end['charge_time']]
    );

    return $consumption > 0
        ? ['ok' => true, 'msg' => "已生成满电周期：行驶 {$distance} km，估算总电量 {$estKwh} 度，其中厂区慢充 {$slowKwh} 度、电费 {$slowCost} 元"]
        : ['ok' => true, 'msg' => "已生成满电周期：行驶 {$distance} km（未预设百公里电耗，电量费用待补参数后可在充电页重算）"];
}

/**
 * 对某车所有"电量为空"的周期重算（补录百公里电耗后使用）
 */
function recalc_cycles(int $vehicleId): int
{
    $v = q1('SELECT tenant_id, energy_consumption FROM vehicles WHERE id = ?', [$vehicleId]);
    $consumption = (float)($v['energy_consumption'] ?? 0);
    if ($consumption <= 0) return 0;
    $vTid = (int)($v['tenant_id'] ?? 1);
    $n = 0;
    foreach (q('SELECT * FROM charge_cycles WHERE vehicle_id = ? AND est_kwh IS NULL', [$vehicleId]) as $c) {
        $estKwh = round($c['distance'] / 100 * $consumption, 2);
        $slowKwh = max(0, round($estKwh - (float)$c['ext_kwh'], 2));
        $slowCost = round($slowKwh * (float)get_setting_for($vTid, 'slow_charge_price', '0.80'), 2);
        exec_sql('UPDATE charge_cycles SET est_kwh=?, slow_kwh=?, slow_cost=? WHERE id=?', [$estKwh, $slowKwh, $slowCost, $c['id']]);
        $n++;
    }
    return $n;
}

/** 简单分页 */
function paginate(int $total, int $page, int $size = 15): array
{
    $pages = max(1, (int)ceil($total / $size));
    $page  = min(max(1, $page), $pages);
    return [$page, $pages, ($page - 1) * $size, $size];
}


/** 当前租户是否已启用 AI 识别（配置了 API Key 即启用） */
function ai_enabled(int $tid): bool
{
    return trim(get_setting_for($tid, 'ai_api_key', '')) !== '';
}

/**
 * AI 视觉识别：从照片中提取结构化数值
 * @param int    $tid     租户ID（读取该租户的 AI 配置）
 * @param string $imgPath 图片绝对路径
 * @param string $kind    receipt_fuel（加油凭证→金额）| mileage（仪表盘→里程）| receipt_charge（充电凭证→金额+度数）
 * @return array ['ok'=>bool, 'fields'=>array, 'msg'=>string]
 */
function ai_vision_recognize(int $tid, string $imgPath, string $kind): array
{
    $base  = rtrim(get_setting_for($tid, 'ai_api_base', 'https://api.moonshot.cn/v1'), '/');
    $key   = trim(get_setting_for($tid, 'ai_api_key', ''));
    $model = trim(get_setting_for($tid, 'ai_model', 'kimi-k2.6'));
    if ($key === '' || $base === '' || $model === '') {
        return ['ok' => false, 'fields' => [], 'msg' => 'AI识别未配置'];
    }

    // 压缩图片（最长边 1024、JPEG 80），降低流量与识别费用；失败则退回原图
    $dataUrl = null;
    $info = @getimagesize($imgPath);
    if ($info && function_exists('imagecreatefromjpeg')) {
        $src = null;
        switch ($info[2]) {
            case IMAGETYPE_JPEG: $src = @imagecreatefromjpeg($imgPath); break;
            case IMAGETYPE_PNG:  $src = @imagecreatefrompng($imgPath); break;
            case IMAGETYPE_WEBP: $src = function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($imgPath) : null; break;
        }
        if ($src) {
            $w = imagesx($src); $h = imagesy($src);
            $scale = min(1, 1024 / max($w, $h));
            $nw = max(1, (int)round($w * $scale));
            $nh = max(1, (int)round($h * $scale));
            $dst = imagecreatetruecolor($nw, $nh);
            imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
            ob_start(); imagejpeg($dst, null, 80); $bin = ob_get_clean();
            imagedestroy($dst); imagedestroy($src);
            if ($bin) $dataUrl = 'data:image/jpeg;base64,' . base64_encode($bin);
        }
    }
    if (!$dataUrl) {
        $bin = @file_get_contents($imgPath);
        if (!$bin) return ['ok' => false, 'fields' => [], 'msg' => '照片读取失败'];
        $mime = ($info && !empty($info['mime'])) ? $info['mime'] : 'image/jpeg';
        $dataUrl = 'data:' . $mime . ';base64,' . base64_encode($bin);
    }

    $prompts = [
        'receipt_fuel'   => '这是一张加油小票或付款凭证照片。请识别本次加油的总金额（人民币元）、单价（元/升）和加油量（升）。只输出JSON：{"amount":数字或null,"price":数字或null,"liters":数字或null}；某项无法确定就填null。',
        'mileage'        => '这是一张汽车仪表盘照片。请识别车辆总里程表（ODO）的读数，单位公里，整数。注意不要小计里程TRIP。只输出JSON：{"mileage":整数}；无法确定则输出{"mileage":null}。',
        'receipt_charge' => '这是一张充电订单或电表凭证照片。请识别充电总金额（人民币元）和充电度数（千瓦时kWh）。只输出JSON：{"amount":数字或null,"kwh":数字或null}。',
    ];
    $prompt = $prompts[$kind] ?? $prompts['receipt_fuel'];

    // 注意：不要传 temperature/top_p——Kimi k2.6 等新模型对采样参数有固定值限制，指定了会直接报错；
    // thinking=disabled 关闭思考模式，凭证识别这类简单任务无需推理，速度更快、费用更低（不识别的平台会忽略该参数）
    $payload = [
        'model'      => $model,
        'max_tokens' => 4096,
        'thinking'   => ['type' => 'disabled'],
        'messages'   => [[
            'role'    => 'user',
            'content' => [
                ['type' => 'image_url', 'image_url' => ['url' => $dataUrl]],
                ['type' => 'text', 'text' => $prompt],
            ],
        ]],
    ];

    $ch = curl_init($base . '/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Authorization: Bearer ' . $key],
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT        => 90,
    ]);
    $resp = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $cerr = curl_error($ch);
    curl_close($ch);
    if ($resp === false) {
        return ['ok' => false, 'fields' => [], 'msg' => '识别失败', 'detail' => 'curl: ' . $cerr];
    }
    if ($code !== 200) {
        $apiErr = (string)(json_decode((string)$resp, true)['error']['message'] ?? '');
        return ['ok' => false, 'fields' => [], 'msg' => '识别失败', 'detail' => 'HTTP ' . $code . ($apiErr !== '' ? ' ' . $apiErr : '')];
    }

    $json = json_decode((string)$resp, true);
    $text = (string)($json['choices'][0]['message']['content'] ?? '');
    $text = trim(preg_replace('/^```(?:json)?\s*|\s*```$/m', '', $text));
    if (preg_match('/\{.*\}/s', $text, $m)) $text = $m[0];
    $data = json_decode($text, true);
    if (!is_array($data)) {
        return ['ok' => false, 'fields' => [], 'msg' => '识别失败', 'detail' => 'AI回复解析失败: ' . mb_substr(trim((string)($json['choices'][0]['message']['content'] ?? '')), 0, 100)];
    }

    // 数值校验与清洗（过滤明显不合理的结果）
    $fields = [];
    if (isset($data['amount']) && is_numeric($data['amount']) && $data['amount'] > 0 && $data['amount'] < 100000) {
        $fields['amount'] = round((float)$data['amount'], 2);
    }
    if (isset($data['price']) && is_numeric($data['price']) && $data['price'] > 0 && $data['price'] < 100) {
        $fields['price'] = round((float)$data['price'], 2);
    }
    if (isset($data['liters']) && is_numeric($data['liters']) && $data['liters'] > 0 && $data['liters'] < 3000) {
        $fields['liters'] = round((float)$data['liters'], 2);
    }
    if (isset($data['mileage']) && is_numeric($data['mileage']) && $data['mileage'] > 0 && $data['mileage'] < 2000000) {
        $fields['mileage'] = (int)$data['mileage'];
    }
    if (isset($data['kwh']) && is_numeric($data['kwh']) && $data['kwh'] > 0 && $data['kwh'] < 10000) {
        $fields['kwh'] = round((float)$data['kwh'], 2);
    }
    if (!$fields) return ['ok' => false, 'fields' => [], 'msg' => '识别失败', 'detail' => 'AI回复无数值: ' . $text];
    return ['ok' => true, 'fields' => $fields, 'msg' => 'ok'];
}


/**
 * AI 配置连通性测试：生成一张带数字的小图让模型识别，验证 密钥/地址/模型/视觉能力 是否可用
 * @return array [bool $ok, string $msg]
 */
function ai_test_connection(int $tid): array
{
    $base  = rtrim(get_setting_for($tid, 'ai_api_base', ''), '/');
    $key   = trim(get_setting_for($tid, 'ai_api_key', ''));
    $model = trim(get_setting_for($tid, 'ai_model', ''));
    if ($key === '')   return [false, '未填写 API Key（留空表示关闭 AI 识别）'];
    if ($base === '')  return [false, '未填写 AI 接口地址'];
    if ($model === '') return [false, '未填写 AI 模型'];

    // 生成测试图（白底黑字三位数字 886），验证模型的图片识别能力
    if (!function_exists('imagecreatetruecolor')) {
        return [false, '服务器 PHP 缺少 GD 扩展，无法生成测试图片，请安装 php-gd'];
    }
    $im = imagecreatetruecolor(200, 80);
    $bg = imagecolorallocate($im, 255, 255, 255);
    $fg = imagecolorallocate($im, 0, 0, 0);
    imagefill($im, 0, 0, $bg);
    imagestring($im, 5, 70, 30, '886', $fg);
    ob_start(); imagejpeg($im, null, 85); $bin = ob_get_clean();
    imagedestroy($im);
    if (!$bin) return [false, '测试图片生成失败'];
    $dataUrl = 'data:image/jpeg;base64,' . base64_encode($bin);

    $payload = [
        'model'      => $model,
        'max_tokens' => 64,
        'thinking'   => ['type' => 'disabled'],
        'messages'   => [[
            'role'    => 'user',
            'content' => [
                ['type' => 'image_url', 'image_url' => ['url' => $dataUrl]],
                ['type' => 'text', 'text' => '图片里是三位数字，请只回复这三个数字。'],
            ],
        ]],
    ];

    $ch = curl_init($base . '/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Authorization: Bearer ' . $key],
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT        => 60,
    ]);
    $resp = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $cerr = curl_error($ch);
    curl_close($ch);

    if ($resp === false) {
        return [false, '无法连接 AI 接口：' . $cerr . '。请检查接口地址是否正确、服务器能否访问外网、防火墙/安全组是否放行'];
    }
    $json = json_decode((string)$resp, true);
    if ($code === 401 || $code === 403) {
        return [false, 'API Key 无效或没有权限（HTTP ' . $code . '），请检查 Key 是否复制完整、账户是否有余额'];
    }
    if ($code === 404) {
        return [false, '接口地址或模型不存在（HTTP 404），请检查接口地址是否以 /v1 结尾、模型名称是否正确'];
    }
    if ($code !== 200) {
        $apiErr = (string)($json['error']['message'] ?? '');
        return [false, 'AI 接口返回错误（HTTP ' . $code . '）' . ($apiErr !== '' ? '：' . $apiErr : '')];
    }
    $text = trim((string)($json['choices'][0]['message']['content'] ?? ''));
    if (strpos($text, '886') !== false) {
        return [true, '连接成功，模型图片识别正常（测试图数字 886，模型回复：' . $text . '）。司机端 AI 识别已可用'];
    }
    if ($text !== '') {
        return [false, '接口能连通，但模型回复「' . mb_substr($text, 0, 30) . '」，未识别出测试数字——该模型可能不支持图片识别，请更换视觉模型（如 kimi-k2.6、kimi-k3）'];
    }
    return [false, '接口返回内容为空，请检查模型名称是否正确'];
}


/**
 * 操作日志：记录司机上传、后台补录、AI 识别等关键操作
 * 首次调用自动建表（幂等），老库无需手动迁移；日志失败不影响业务
 */
function log_op(int $tid, string $ltype, string $actor, string $action, string $detail = ''): void
{
    static $ready = null;
    if ($ready === null) {
        $ready = table_exists('op_logs');
        if (!$ready) {
            try {
                exec_sql("CREATE TABLE IF NOT EXISTS `op_logs` (
                    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `tenant_id` INT UNSIGNED NOT NULL DEFAULT 1,
                    `ltype` VARCHAR(20) NOT NULL DEFAULT '' COMMENT '日志类型',
                    `actor` VARCHAR(50) NOT NULL DEFAULT '' COMMENT '操作人',
                    `action` VARCHAR(50) NOT NULL DEFAULT '' COMMENT '操作',
                    `detail` VARCHAR(500) NOT NULL DEFAULT '' COMMENT '详情',
                    `ip` VARCHAR(45) NOT NULL DEFAULT '',
                    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    KEY `idx_tenant` (`tenant_id`, `created_at`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
                $ready = table_exists('op_logs');
            } catch (Throwable $e) {
                $ready = false;
            }
        }
    }
    if (!$ready) return;
    if ($action === '') return; // 空动作：仅用于触发自动建表，不产生日志
    try {
        exec_sql(
            'INSERT INTO op_logs (tenant_id, ltype, actor, action, detail, ip) VALUES (?, ?, ?, ?, ?, ?)',
            [
                $tid,
                mb_substr($ltype, 0, 20),
                mb_substr($actor, 0, 50),
                mb_substr($action, 0, 50),
                mb_substr($detail, 0, 500),
                mb_substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45),
            ]
        );
    } catch (Throwable $e) {
        // 日志写入失败不影响业务
    }
}
