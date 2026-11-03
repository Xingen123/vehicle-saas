<?php
/**
 * 司机扫码充电上报页（免登录，凭车辆二维码令牌访问）
 * 三种类型：
 *  - 满电：只填当前里程（周期核算依据）
 *  - 零散补电：备注台账，不参与电量核算
 *  - 外部快充：按电表真实度数/金额录入，拍照凭证
 */
require_once dirname(__DIR__) . '/includes/functions.php';

if (session_status() === PHP_SESSION_NONE) { session_name('VMH5SESSID'); session_start(); }

$token = preg_replace('/[^a-f0-9]/', '', $_GET['t'] ?? $_POST['t'] ?? '');
$v = $token ? q1("SELECT * FROM vehicles WHERE upload_token = ? AND status != '停用'", [$token]) : null;

// 租户状态检查：企业被停用或已到期时，司机端停止接收上报
$tenantMsg = '';
if ($v && saas_ready()) {
    $t = q1('SELECT * FROM tenants WHERE id = ?', [$v['tenant_id']]);
    if ($t && $t['status'] === '停用') $tenantMsg = '所属企业账号已停用，无法提交，请联系管理人员';
    elseif ($t && $t['expires_at'] && $t['expires_at'] < date('Y-m-d')) $tenantMsg = '所属企业账号已到期，无法提交，请联系管理人员续期';
    if ($tenantMsg) $v = null;
}

// 数据库未升级（老库无充电表）时给出提示，避免报错
if ($v && db_needs_upgrade()) {
    $v = null;
    $upgradeTip = true;
}

$done = false; $doneMsg = ''; $error = '';

if ($v && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $formKey = trim($_POST['form_key'] ?? '');
    if ($formKey === '' || $formKey === ($_SESSION['last_charge_key'] ?? '')) {
        $error = '请勿重复提交';
    } else {
        $type = in_array($_POST['charge_type'] ?? '', ['满电', '零散补电', '外部快充'], true) ? $_POST['charge_type'] : '满电';
        $mileage = max(0, (int)($_POST['mileage'] ?? 0));
        $station = trim($_POST['station'] ?? '');
        $driver  = trim($_POST['driver_name'] ?? '');
        $remark  = trim($_POST['remark'] ?? '');
        $kwh = $price = $amount = null;

        if ($type === '外部快充') {
            $kwh    = $_POST['kwh'] !== '' ? (float)$_POST['kwh'] : null;
            $price  = $_POST['price'] !== '' ? (float)$_POST['price'] : null;
            $amount = $_POST['amount'] !== '' ? (float)$_POST['amount'] : null;
            if ($amount && $price && !$kwh) $kwh = round($amount / $price, 2);
            if ($amount && $kwh && !$price) $price = round($amount / $kwh, 2);
            if ($kwh && $price && !$amount) $amount = round($kwh * $price, 2);
        }

        $receipt = save_upload($_FILES['receipt_photo'] ?? []);
        $mileagePhoto = save_upload($_FILES['mileage_photo'] ?? []);

        if ($mileage <= 0) {
            $error = '请填写仪表里程';
        } elseif ($type === '外部快充' && !$kwh && !$amount) {
            $error = '外部快充请按电表填写真实度数或金额';
        } elseif ($type === '外部快充' && !$receipt) {
            $error = '外部快充请拍摄充电凭证照片';
        } elseif ($mileage < (int)$v['current_mileage']) {
            $error = '填写的里程小于车辆当前里程（' . number_format($v['current_mileage']) . ' km），请核对仪表盘';
        } elseif ($mileage - (int)$v['current_mileage'] > 50000) {
            $error = '填写的里程比当前里程超出 5 万 km，疑似多输了数字，请核对仪表盘';
        } else {
            $pdo = db();
            $pdo->beginTransaction();
            exec_sql(
                'INSERT INTO charge_records (tenant_id,vehicle_id,charge_time,charge_type,mileage,kwh,price,amount,station,receipt_photo,mileage_photo,source,driver_name,remark) VALUES (?,?,NOW(),?,?,?,?,?,?,?,?,\'driver\',?,?)',
                [$v['tenant_id'], $v['id'], $type, $mileage, $kwh, $price, $amount, $station, (string)$receipt, (string)$mileagePhoto, $driver, $remark]
            );
            $newId = (int)last_id(); // 注意：须在 UPDATE（touch_mileage）之前取，否则会被清零
            $done = true;
            $doneMsg = match ($type) {
                '满电'     => '满电记录已提交。',
                '外部快充' => '外部快充已按真实度数登记。',
                default    => '零散补电已备注（不参与电量核算）。',
            };
            if ($type === '满电') {
                $res = create_charge_cycle($v['id'], $newId);
                if (!$res['ok']) {
                    // 配对失败（多为里程填错）：整条回滚，避免污染后续周期配对
                    $pdo->rollBack();
                    $done = false;
                    $error = $res['msg'] . '，本条记录未保存，请核对后重新提交';
                } else {
                    $doneMsg = $res['msg'];
                }
            }
            if ($done) {
                touch_mileage($v['id'], $mileage);
                $pdo->commit();
                $_SESSION['last_charge_key'] = $formKey;
                log_op((int)$v['tenant_id'], '司机上传', $driver !== '' ? $driver : '司机', '充电上报 成功',
                    "车牌 {$v['plate_no']}，类型 {$type}，里程 {$mileage} km"
                    . ($kwh !== null ? "，度数 {$kwh} 度" : '')
                    . ($amount !== null ? "，金额 {$amount} 元" : ''));
            }
        }
        if (!$done && $error !== '' && $error !== '请勿重复提交') {
            log_op((int)$v['tenant_id'], '司机上传', $driver !== '' ? $driver : '司机', '充电上报 失败', "车牌 {$v['plate_no']}，原因：{$error}");
        }
    }
}
$siteName = $v ? get_setting_for((int)$v['tenant_id'], 'site_name', '车辆管理系统') : (defined('PLATFORM_NAME') ? PLATFORM_NAME : '车辆管理系统');
$aiEnabled = $v ? ai_enabled((int)$v['tenant_id']) : false;
$formKey = bin2hex(random_bytes(8));
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
<title>充电上报 - <?= e($siteName) ?></title>
<link rel="stylesheet" href="../assets/css/style.css">
<style>
.type-pick { display: flex; gap: 8px; margin: 12px 0 4px; }
.type-pick label { flex: 1; border: 2px solid var(--border); border-radius: 10px; padding: 12px 4px; text-align: center; font-size: 14px; cursor: pointer; margin: 0 !important; font-weight: 600; }
.type-pick input { display: none; }
.type-pick input:checked + span { color: var(--primary); }
.type-pick label:has(input:checked) { border-color: var(--primary); background: #eff6ff; }
</style>
</head>
<body>
<div class="h5-body">
<?php if (!$v): ?>
  <div class="h5-ok">
    <div class="ico">⚠️</div>
    <?php if ($tenantMsg): ?>
    <h2><?= e($tenantMsg) ?></h2>
    <?php elseif (!empty($upgradeTip)): ?>
    <h2>功能升级中</h2>
    <p style="color:var(--muted);margin-top:10px">充电上报功能正在升级，请稍后再试或联系管理人员</p>
    <?php else: ?>
    <h2>二维码无效或已失效</h2>
    <p style="color:var(--muted);margin-top:10px">请联系管理人员重新获取车辆二维码</p>
    <?php endif; ?>
  </div>
<?php elseif ($done): ?>
  <div class="h5-ok">
    <div class="ico">✅</div>
    <h2>提交成功</h2>
    <p style="color:var(--muted);margin-top:10px"><?= e($doneMsg) ?></p>
    <p style="margin-top:20px"><a class="btn btn-gray" href="charge.php?t=<?= e($token) ?>">再传一笔</a></p>
  </div>
<?php else: ?>
  <div class="h5-head">
    <h1>🔋 充电上报</h1>
    <p><?= e($v['plate_no']) ?> <?= e(trim($v['brand'] . ' ' . $v['model'])) ?>　当前里程 <?= number_format($v['current_mileage']) ?> km</p>
  </div>
  <div class="h5-card">
    <?php if ($error): ?><div class="flash flash-error"><?= e($error) ?></div><?php endif; ?>
    <form method="post" enctype="multipart/form-data" onsubmit="return checkForm()">
      <input type="hidden" name="t" value="<?= e($token) ?>">
      <input type="hidden" name="form_key" value="<?= $formKey ?>">

      <label>充电类型 <span class="req">*</span></label>
      <div class="type-pick">
        <label><input type="radio" name="charge_type" value="满电" checked onchange="toggleType()"><span>✅ 已充满</span></label>
        <label><input type="radio" name="charge_type" value="零散补电" onchange="toggleType()"><span>⏱ 零散补电</span></label>
        <label><input type="radio" name="charge_type" value="外部快充" onchange="toggleType()"><span>⚡ 外部快充</span></label>
      </div>
      <p id="type_hint" style="color:var(--muted);font-size:12px;margin-bottom:6px">车辆已完全充满：只需填写当前里程，系统自动核算周期电量。</p>

      <label>仪表里程（km）<span class="req">*</span></label>
      <input type="number" name="mileage" id="mileage" min="0" inputmode="numeric" placeholder="仪表盘上显示的公里数" required>
      <p style="color:var(--muted);font-size:12px;margin-top:4px">📌 本车当前里程 <b><?= number_format($v['current_mileage']) ?> km</b>，请仔细核对仪表盘后填写</p>

      <div class="fast-only" style="display:none">
        <label>真实度数（kWh，按电表）<span class="req">*</span></label>
        <input type="number" name="kwh" id="kwh" step="0.01" min="0" inputmode="decimal" placeholder="充电桩/电表读数">
        <label>单价（元/度）</label>
        <input type="number" name="price" step="0.01" min="0" inputmode="decimal" placeholder="如 1.20">
        <label>金额（元）</label>
        <input type="number" name="amount" id="amount" step="0.01" min="0" inputmode="decimal" placeholder="如 45.60">
        <label>拍摄充电凭证 <span class="req">*</span></label>
        <div class="photo-pick" onclick="document.getElementById('receipt_photo').click()">
          <span id="receipt_tip">📷 点击拍照或从相册选择</span>
          <img id="receipt_preview" style="display:none">
        </div>
        <input type="file" name="receipt_photo" id="receipt_photo" accept="image/*" style="display:none" onchange="pickPhoto(this,'receipt_preview','receipt_tip','receipt_charge')">
        <p class="ai-tip" id="ai_receipt_charge"></p>
      </div>

      <label>充电点（选填）</label>
      <input type="text" name="station" placeholder="如 厂区3号桩 / XX快充站">

      <label class="slow-only">备注（选填）</label>
      <input type="text" name="remark" class="slow-only" placeholder="零散补电可说明：临时补电30分钟">

      <label>司机姓名（选填）</label>
      <input type="text" name="driver_name" placeholder="您的姓名">

      <div style="margin-top:18px">
        <button class="btn btn-block" type="submit" id="submitBtn">提交</button>
      </div>
    </form>
  </div>
  <div class="upload-mask" id="uploadMask">
    <div class="upload-mask-box">
      <div class="spinner"></div>
      <p>正在上传，请稍候…</p>
      <p class="sub">请勿重复点击或关闭页面</p>
    </div>
  </div>
  <p style="text-align:center;color:var(--muted);font-size:12px"><?= e($siteName) ?></p>
<?php endif; ?>
</div>
<script>
function toggleType() {
  var t = document.querySelector('input[name=charge_type]:checked').value;
  var isFast = (t === '外部快充');
  document.querySelectorAll('.fast-only').forEach(function (el) { el.style.display = isFast ? 'block' : 'none'; });
  var hint = {
    '满电': '车辆已完全充满：只需填写当前里程，系统自动核算周期电量。',
    '零散补电': '中途临时充电（未充满）：仅备注台账，不参与电量核算。',
    '外部快充': '外部快充站充电：请按电表填写真实度数，并拍摄凭证。'
  };
  document.getElementById('type_hint').innerText = hint[t] || '';
}
/* ---- 照片本地压缩：最长边 1600px、JPEG 75%。低端机/弱网下把 5-12MB 原图压到几百 KB 再上传，
   避免长时间无反馈；任何一步失败都自动退回上传原图，保证可用 ---- */
var pickSeq = {};
function pickPhoto(input, imgId, tipId, aiKind) {
  var file = input.files && input.files[0];
  if (!file) return;
  var seq = (pickSeq[input.id] = (pickSeq[input.id] || 0) + 1);
  var tip = document.getElementById(tipId);
  var imgEl = document.getElementById(imgId);
  tip.style.display = 'block';
  tip.textContent = '⏳ 正在处理照片…';
  imgEl.style.display = 'none';
  function showPreview(blob, note) {
    imgEl.src = URL.createObjectURL(blob);
    imgEl.style.display = 'block';
    tip.style.display = 'block';
    tip.style.fontSize = '12px';
    tip.textContent = note;
    if (aiKind) aiRecognize(input, aiKind);
  }
  function useOriginal() { showPreview(file, '✅ 照片已选择'); }
  var objUrl = URL.createObjectURL(file);
  var img = new Image();
  img.onload = function () {
    if (pickSeq[input.id] !== seq) { URL.revokeObjectURL(objUrl); return; }
    try {
      var MAX = 1600;
      var w = img.naturalWidth || img.width, h = img.naturalHeight || img.height;
      if (!w || !h) { URL.revokeObjectURL(objUrl); useOriginal(); return; }
      var scale = Math.min(1, MAX / Math.max(w, h));
      var canvas = document.createElement('canvas');
      canvas.width = Math.max(1, Math.round(w * scale));
      canvas.height = Math.max(1, Math.round(h * scale));
      canvas.getContext('2d').drawImage(img, 0, 0, canvas.width, canvas.height);
      var done = function (blob) {
        URL.revokeObjectURL(objUrl);
        if (!blob) { useOriginal(); return; }
        var f;
        try { f = new File([blob], 'photo.jpg', { type: 'image/jpeg' }); }
        catch (e) { f = blob; }
        var replaced = false;
        try {
          var dt = new DataTransfer();
          dt.items.add(f);
          input.files = dt.files;
          replaced = true;
        } catch (e) { replaced = false; }
        if (replaced) {
          showPreview(blob, '✅ 照片已压缩至约 ' + Math.max(1, Math.round(blob.size / 1024)) + ' KB，上传更快');
        } else {
          useOriginal();
        }
      };
      if (canvas.toBlob) {
        canvas.toBlob(done, 'image/jpeg', 0.75);
      } else {
        try {
          var dataUrl = canvas.toDataURL('image/jpeg', 0.75);
          var parts = dataUrl.split(',');
          var bstr = atob(parts[1]), n = bstr.length;
          var u8 = new Uint8Array(n);
          while (n--) { u8[n] = bstr.charCodeAt(n); }
          done(new Blob([u8], { type: 'image/jpeg' }));
        } catch (e2) { done(null); }
      }
    } catch (e) { URL.revokeObjectURL(objUrl); useOriginal(); }
  };
  img.onerror = function () { URL.revokeObjectURL(objUrl); useOriginal(); };
  img.src = objUrl;
}
/* ---- AI 识别：照片选定后自动识别并预填对应字段，司机可核对修改 ---- */
var AI_ENABLED = <?= $aiEnabled ? 'true' : 'false' ?>;
function aiRecognize(input, kind) {
  if (!AI_ENABLED) return;
  var file = input.files && input.files[0];
  if (!file) return;
  var st = document.getElementById('ai_' + kind);
  if (!st) return;
  st.style.display = 'block';
  st.style.color = '#b45309';
  st.textContent = '🤖 AI 识别中，请稍候…';
  var fd = new FormData();
  fd.append('t', '<?= e($token) ?>');
  fd.append('kind', kind);
  fd.append('photo', file, 'photo.jpg');
  var xhr = new XMLHttpRequest();
  xhr.open('POST', 'ai_ocr.php');
  xhr.timeout = 120000;
  xhr.onload = function () {
    var res = null;
    try { res = JSON.parse(xhr.responseText); } catch (e) {}
    if (res && res.ok && res.fields) {
      var filled = 0;
      for (var k in res.fields) {
        if (!res.fields.hasOwnProperty(k)) continue;
        var el = document.getElementById(k);
        if (el && !el.value) {
          el.value = res.fields[k];
          if (el.className.indexOf('ai-filled') < 0) el.className += (el.className ? ' ' : '') + 'ai-filled';
          filled++;
        }
      }
      st.style.color = '#15803d';
      st.textContent = filled > 0
        ? '✅ AI 已识别并填入（绿色框），请核对确认，不对可直接修改'
        : '✅ AI 识别完成，未覆盖您已填写的内容';
    } else {
      st.style.color = '#b91c1c';
      st.textContent = '❌ 识别失败，请手动填写';
    }
  };
  xhr.onerror = xhr.ontimeout = function () {
    st.style.color = '#b91c1c';
    st.textContent = '❌ 识别失败，请手动填写';
  };
  xhr.send(fd);
}
function startUpload() {
  var btn = document.getElementById('submitBtn');
  if (btn) { btn.disabled = true; btn.textContent = '正在上传，请稍候…'; }
  var mask = document.getElementById('uploadMask');
  if (mask) { mask.style.display = 'flex'; }
}
var CUR_MILEAGE = <?= (int)$v['current_mileage'] ?>;
function checkForm() {
  var t = document.querySelector('input[name=charge_type]:checked').value;
  var m = parseInt(document.getElementById('mileage').value, 10);
  if (isNaN(m) || m <= 0) { alert('请填写仪表里程'); return false; }
  if (m < CUR_MILEAGE) { alert('填写的里程小于本车当前里程 ' + CUR_MILEAGE.toLocaleString() + ' km，请核对仪表盘'); return false; }
  if (m - CUR_MILEAGE > 50000) { alert('填写的里程比当前里程超出 5 万 km，疑似多输了数字，请核对仪表盘'); return false; }
  if (m - CUR_MILEAGE > 1000) {
    if (!confirm('本次填写里程 ' + m.toLocaleString() + ' km，较当前里程增加了 ' + (m - CUR_MILEAGE).toLocaleString() + ' km，请确认无误后点"确定"提交')) return false;
  }
  if (t === '外部快充' && !document.getElementById('receipt_photo').files.length) {
    alert('外部快充请拍摄或从相册选择充电凭证照片'); return false;
  }
  startUpload();
  return true;
}
toggleType();
</script>
</body>
</html>
