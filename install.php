<?php
/**
 * 爱微圈 · 车辆管理系统（SaaS 多租户版）—— 安装向导
 *
 * 单文件安装器：环境检测 → 数据库 → 站点与账号 → 完成
 * 安装完成后会在同目录写入 install.lock，向导自动锁定。
 *
 * 兼容 PHP 7.4+，仅使用 PDO，不依赖任何第三方库。
 */

@ini_set('display_errors', '0');
error_reporting(E_ALL);

if (function_exists('date_default_timezone_set')) {
    date_default_timezone_set('Asia/Shanghai');
}
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

define('ROOT_DIR',    __DIR__);
define('LOCK_FILE',   ROOT_DIR . '/install.lock');
define('CONFIG_FILE', ROOT_DIR . '/config.php');
define('SQL_FILE',    ROOT_DIR . '/install.sql');
define('MIN_PHP',     '7.4');

$step   = isset($_POST['step']) ? (int)$_POST['step'] : 1;
$errors = array();
$done   = false;

/* ============================================================
 * 工具
 * ============================================================ */

function h($s)
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function post_val($k, $default = '')
{
    return isset($_POST[$k]) ? trim((string)$_POST[$k]) : $default;
}

function cfg_default($key, $default = '')
{
    return isset($_SESSION['install_cfg'][$key]) ? $_SESSION['install_cfg'][$key] : $default;
}

/** 检测当前访问的站点根地址（自动适配 https / 子目录） */
function detect_base_url()
{
    $https = (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off')
        || (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443)
        || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https');
    $scheme = $https ? 'https' : 'http';
    $host   = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost';
    $dir    = str_replace('\\', '/', dirname(isset($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : '/'));
    $dir    = rtrim($dir, '/');
    if ($dir === '.' || $dir === '/') {
        $dir = '';
    }
    return $scheme . '://' . $host . $dir;
}

/* ============================================================
 * 环境检测
 * ============================================================ */

function env_checks()
{
    $checks = array();

    $checks[] = array(
        'name'  => 'PHP 版本 ≥ ' . MIN_PHP,
        'need'  => '当前 ' . PHP_VERSION,
        'ok'    => version_compare(PHP_VERSION, MIN_PHP, '>='),
        'hard'  => true,
        'hint'  => '请升级 PHP 版本后重试',
    );

    $exts = array(
        'pdo_mysql' => array(true,  '数据库连接（必需）'),
        'json'      => array(true,  'JSON 处理（必需）'),
        'mbstring'  => array(true,  '中文字符串处理（必需）'),
        'fileinfo'  => array(true,  '上传照片类型校验（必需）'),
        'curl'      => array(false, 'AI 凭证识别（可选）'),
        'openssl'   => array(false, '邮件 SSL 加密（可选，发提醒邮件用）'),
    );
    foreach ($exts as $ext => $meta) {
        $checks[] = array(
            'name' => $ext . ' 扩展',
            'need' => $meta[1],
            'ok'   => extension_loaded($ext),
            'hard' => $meta[0],
            'hint' => $meta[0] ? '请在 php.ini 中开启 ' . $ext . ' 扩展' : '不影响安装，相关功能可在后台关闭',
        );
    }

    $dirs = array(
        ROOT_DIR          => '根目录（写入配置文件）',
        ROOT_DIR . '/uploads' => '上传目录',
        ROOT_DIR . '/logs'    => '日志目录',
    );
    foreach ($dirs as $dir => $label) {
        $exists = is_dir($dir);
        if (!$exists) {
            @mkdir($dir, 0755, true);
            $exists = is_dir($dir);
        }
        $writable = $exists && is_writable($dir);
        $checks[] = array(
            'name' => $label . '可写',
            'need' => str_replace(ROOT_DIR, '.', $dir),
            'ok'   => $writable,
            'hard' => true,
            'hint' => '请给该目录写权限：chmod -R 755 ' . str_replace(ROOT_DIR, '.', $dir),
        );
    }

    $checks[] = array(
        'name' => 'install.sql 存在',
        'need' => '数据库结构脚本',
        'ok'   => is_file(SQL_FILE),
        'hard' => true,
        'hint' => '请确认安装包解压完整，install.sql 需与本文件同目录',
    );

    return $checks;
}

/* ============================================================
 * 数据库
 * ============================================================ */

function db_connect($host, $port, $user, $pass, $dbname = '', $charset = 'utf8mb4')
{
    $dsn = 'mysql:host=' . $host . ';port=' . $port;
    if ($dbname !== '') {
        $dsn .= ';dbname=' . $dbname;
    }
    if ($charset !== '') {
        $dsn .= ';charset=' . $charset;
    }
    return new PDO($dsn, $user, $pass, array(
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
        PDO::ATTR_TIMEOUT            => 8,
    ));
}

function db_exists(PDO $pdo, $dbname)
{
    $st = $pdo->prepare('SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?');
    $st->execute(array($dbname));
    return (bool)$st->fetchColumn();
}

function db_table_count(PDO $pdo, $dbname)
{
    $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = ?');
    $st->execute(array($dbname));
    return (int)$st->fetchColumn();
}

/**
 * 逐条执行 SQL 文件（按 phpMyAdmin 导出的「行尾分号」格式解析）
 * 返回 array(执行成功条数, 错误信息数组)
 */
function run_sql_file(PDO $pdo, $file)
{
    $raw = file_get_contents($file);
    if ($raw === false) {
        return array(0, array('无法读取 ' . basename($file)));
    }
    $raw   = str_replace(array("\r\n", "\r"), "\n", $raw);
    $lines = explode("\n", $raw);

    $buffer = '';
    $ok     = 0;
    $errs   = array();

    foreach ($lines as $line) {
        $trim = trim($line);

        if ($trim === '') {
            continue;
        }
        // 跳过纯注释行（-- 或 #）；/* ... */ 块内不处理
        if (substr($trim, 0, 2) === '--' || substr($trim, 0, 1) === '#') {
            continue;
        }

        $buffer .= $line . "\n";

        if (substr($trim, -1) !== ';') {
            continue;
        }

        $sql = trim($buffer);
        $buffer = '';

        if ($sql === '' || $sql === ';') {
            continue;
        }

        try {
            $pdo->exec($sql);
            $ok++;
        } catch (PDOException $e) {
            $errs[] = '第 ' . $ok . ' 条附近执行失败：' . $e->getMessage();
        }
    }

    if (trim($buffer) !== '') {
        try {
            $pdo->exec(trim($buffer));
            $ok++;
        } catch (PDOException $e) {
            $errs[] = '末尾语句执行失败：' . $e->getMessage();
        }
    }

    return array($ok, $errs);
}

/* ============================================================
 * 生成 config.php
 * ============================================================ */

function php_str($v)
{
    return "'" . str_replace(array('\\', "'"), array('\\\\', "\\'"), (string)$v) . "'";
}

function build_config(array $c)
{
    $out  = "<?php\n";
    $out .= "/**\n";
    $out .= " * 系统配置文件 —— 由安装向导自动生成\n";
    $out .= " * 生成时间：" . date('Y-m-d H:i:s') . "\n";
    $out .= " */\n\n";

    $out .= "// ---------- 数据库配置 ----------\n";
    $out .= "define('DB_HOST', " . php_str($c['db_host']) . ");\n";
    $out .= "define('DB_PORT', " . php_str($c['db_port']) . ");\n";
    $out .= "define('DB_NAME', " . php_str($c['db_name']) . ");\n";
    $out .= "define('DB_USER', " . php_str($c['db_user']) . ");\n";
    $out .= "define('DB_PASS', " . php_str($c['db_pass']) . ");\n";
    $out .= "define('DB_CHARSET', 'utf8mb4');\n\n";

    $out .= "// ---------- 站点配置 ----------\n";
    $out .= "// 平台名称（登录页、超管后台显示；各企业内显示各自设置的站点名）\n";
    $out .= "define('PLATFORM_NAME', " . php_str($c['platform_name']) . ");\n\n";
    $out .= "// 站点的完整访问地址（不带结尾斜杠），用于生成司机扫码链接与邮件中的链接\n";
    $out .= "define('BASE_URL', " . php_str($c['base_url']) . ");\n\n";
    $out .= "// 上传文件目录（默认无需修改）\n";
    $out .= "define('UPLOAD_DIR', __DIR__ . '/uploads');\n";
    $out .= "define('UPLOAD_MAX_SIZE', 10 * 1024 * 1024); // 单张照片最大 10MB\n\n";

    $out .= "// ---------- 邮件(SMTP)配置，用于到期提醒推送 ----------\n";
    $out .= "// QQ 邮箱：smtp.qq.com / 465 / ssl，密码填「授权码」而非登录密码\n";
    $out .= "define('SMTP_HOST', " . php_str($c['smtp_host']) . ");\n";
    $out .= "define('SMTP_PORT', " . (int)$c['smtp_port'] . ");\n";
    $out .= "define('SMTP_SECURE', " . php_str($c['smtp_secure']) . ");\n";
    $out .= "define('SMTP_USER', " . php_str($c['smtp_user']) . ");\n";
    $out .= "define('SMTP_PASS', " . php_str($c['smtp_pass']) . ");\n";
    $out .= "define('SMTP_FROM', " . php_str($c['smtp_from']) . ");\n";
    $out .= "define('SMTP_FROM_NAME', " . php_str($c['smtp_from_name']) . ");\n\n";

    $out .= "date_default_timezone_set('Asia/Shanghai');\n";
    $out .= "if (function_exists('mb_internal_encoding')) {\n";
    $out .= "    mb_internal_encoding('UTF-8');\n";
    $out .= "}\n";

    return $out;
}

/* ============================================================
 * 流程控制
 * ============================================================ */

$locked      = is_file(LOCK_FILE);
$has_config  = is_file(CONFIG_FILE);
$overwrite   = post_val('allow_overwrite') === '1';
$checks      = env_checks();
$hard_failed = false;
foreach ($checks as $c) {
    if ($c['hard'] && !$c['ok']) {
        $hard_failed = true;
    }
}

if (!$locked && $step >= 2 && $has_config && !$overwrite) {
    $errors[] = '检测到本目录已存在 config.php（可能已安装过）。若确实要重新安装，请勾选下方确认后继续。';
    $step = 1;
}

/* ---------- Step 2 → 3：建库 + 导入 ---------- */
if (!$locked && $step === 2 && !$hard_failed) {
    $db = array(
        'db_host' => post_val('db_host', '127.0.0.1'),
        'db_port' => post_val('db_port', '3306'),
        'db_name' => post_val('db_name'),
        'db_user' => post_val('db_user', 'root'),
        'db_pass' => isset($_POST['db_pass']) ? (string)$_POST['db_pass'] : '',
        'wipe'    => post_val('wipe') === '1',
    );

    if ($db['db_host'] === '' || $db['db_port'] === '' || $db['db_name'] === '' || $db['db_user'] === '') {
        $errors[] = '数据库地址、端口、库名、用户名均为必填项。';
    } elseif (!preg_match('/^[A-Za-z0-9_]+$/', $db['db_name'])) {
        $errors[] = '数据库名只能包含字母、数字和下划线。';
    } else {
        try {
            $pdo = db_connect($db['db_host'], $db['db_port'], $db['db_user'], $db['db_pass']);

            if (!db_exists($pdo, $db['db_name'])) {
                $pdo->exec('CREATE DATABASE `' . $db['db_name'] . '` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
            } elseif (db_table_count($pdo, $db['db_name']) > 0) {
                if (!$db['wipe']) {
                    throw new RuntimeException(
                        '数据库「' . $db['db_name'] . '」中已存在数据表。请换一个库名，'
                        . '或勾选「清空该库后重新安装」（会删除该库全部数据）。'
                    );
                }
                $pdo->exec('DROP DATABASE `' . $db['db_name'] . '`');
                $pdo->exec('CREATE DATABASE `' . $db['db_name'] . '` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
            }

            $pdo = db_connect($db['db_host'], $db['db_port'], $db['db_user'], $db['db_pass'], $db['db_name']);
            $pdo->exec('SET NAMES utf8mb4');

            list($cnt, $sqlErrs) = run_sql_file($pdo, SQL_FILE);

            if ($sqlErrs) {
                $errors[] = '数据库结构导入未全部成功：' . implode(' / ', array_slice($sqlErrs, 0, 3));
            }
            if ($cnt < 10) {
                $errors[] = 'SQL 执行条数异常（仅 ' . $cnt . ' 条），请检查 install.sql 是否完整。';
            }

            if (!$errors) {
                $tables = db_table_count($pdo, $db['db_name']);
                $_SESSION['install_cfg'] = array(
                    'db_host' => $db['db_host'],
                    'db_port' => $db['db_port'],
                    'db_name' => $db['db_name'],
                    'db_user' => $db['db_user'],
                    'db_pass' => $db['db_pass'],
                    'sql_ok'  => $cnt,
                    'tables'  => $tables,
                );
                $step = 3;
            }
        } catch (RuntimeException $e) {
            $errors[] = $e->getMessage();
        } catch (PDOException $e) {
            $errors[] = '数据库连接失败：' . $e->getMessage() . '（请检查地址、端口、账号密码，以及数据库服务是否已启动）';
        }
    }

    if ($errors) {
        $step = 2;
    }
}

/* ---------- Step 3 → 完成：写账号 + 生成配置 ---------- */
if (!$locked && $step === 3 && !empty($_SESSION['install_cfg'])) {
    $c = $_SESSION['install_cfg'];

    $base_url   = post_val('base_url', detect_base_url());
    $plat_name  = post_val('platform_name', '爱微圈·车辆管理平台');
    $site_name  = post_val('site_name', '爱微圈车辆管理系统');
    $tenant     = post_val('tenant_name', '我的企业');

    $su_user = post_val('su_user', 'superadmin');
    $su_pass = isset($_POST['su_pass']) ? (string)$_POST['su_pass'] : '';
    $ad_user = post_val('admin_user', 'admin');
    $ad_pass = isset($_POST['admin_pass']) ? (string)$_POST['admin_pass'] : '';

    $smtp_host   = post_val('smtp_host');
    $smtp_port   = post_val('smtp_port', '465');
    $smtp_secure = post_val('smtp_secure', 'ssl');
    $smtp_user   = post_val('smtp_user');
    $smtp_pass   = isset($_POST['smtp_pass']) ? (string)$_POST['smtp_pass'] : '';
    $smtp_from   = post_val('smtp_from');

    if ($base_url === '')                        { $errors[] = '站点访问地址不能为空。'; }
    if (!preg_match('#^https?://#i', $base_url)) { $errors[] = '站点访问地址需以 http:// 或 https:// 开头。'; }
    if ($plat_name === '')                       { $errors[] = '平台名称不能为空。'; }
    if ($su_user === '' || $ad_user === '')      { $errors[] = '管理员登录名不能为空。'; }
    if (strlen($su_pass) < 6)                    { $errors[] = '平台超管密码至少 6 位。'; }
    if (strlen($ad_pass) < 6)                    { $errors[] = '企业管理员密码至少 6 位。'; }
    if ($su_user === $ad_user)                   { $errors[] = '平台超管与企业管理员的登录名不能相同。'; }
    if ($smtp_port !== '' && (!ctype_digit($smtp_port) || (int)$smtp_port < 1 || (int)$smtp_port > 65535)) {
        $errors[] = 'SMTP 端口需为 1-65535 之间的数字。';
    }

    if (!$errors) {
        try {
            $pdo = db_connect($c['db_host'], $c['db_port'], $c['db_user'], $c['db_pass'], $c['db_name']);
            $pdo->exec('SET NAMES utf8mb4');

            // 1) 平台超管
            $st = $pdo->prepare("UPDATE users SET username = ?, password = ?, status = 1 WHERE role = 'super'");
            $st->execute(array($su_user, password_hash($su_pass, PASSWORD_DEFAULT)));
            if ($st->rowCount() === 0) {
                $pdo->prepare("INSERT INTO users (tenant_id, username, password, name, role, email, status) VALUES (0,?,?,'平台超管','super','',1)")
                    ->execute(array($su_user, password_hash($su_pass, PASSWORD_DEFAULT)));
            }

            // 2) 企业管理员（默认企业 id=1）
            $st = $pdo->prepare("UPDATE users SET username = ?, password = ?, status = 1 WHERE role = 'admin' AND tenant_id = 1");
            $st->execute(array($ad_user, password_hash($ad_pass, PASSWORD_DEFAULT)));
            if ($st->rowCount() === 0) {
                $pdo->prepare("INSERT INTO users (tenant_id, username, password, name, role, email, status) VALUES (1,?,?,'系统管理员','admin','',1)")
                    ->execute(array($ad_user, password_hash($ad_pass, PASSWORD_DEFAULT)));
            }

            // 3) 企业名称
            $pdo->prepare('UPDATE tenants SET name = ? WHERE id = 1')->execute(array($tenant));

            // 4) 站点名称
            $st = $pdo->prepare("UPDATE settings SET svalue = ? WHERE tenant_id = 1 AND skey = 'site_name'");
            $st->execute(array($site_name));
            if ($st->rowCount() === 0) {
                $pdo->prepare("INSERT INTO settings (tenant_id, skey, svalue) VALUES (1,'site_name',?)")->execute(array($site_name));
            }

            // 5) 生成 config.php
            $cfg = array(
                'db_host'        => $c['db_host'],
                'db_port'        => $c['db_port'],
                'db_name'        => $c['db_name'],
                'db_user'        => $c['db_user'],
                'db_pass'        => $c['db_pass'],
                'platform_name'  => $plat_name,
                'base_url'       => rtrim($base_url, '/'),
                'smtp_host'      => $smtp_host !== '' ? $smtp_host : 'smtp.qq.com',
                'smtp_port'      => $smtp_port !== '' ? (int)$smtp_port : 465,
                'smtp_secure'    => $smtp_secure,
                'smtp_user'      => $smtp_user,
                'smtp_pass'      => $smtp_pass,
                'smtp_from'      => $smtp_from !== '' ? $smtp_from : $smtp_user,
                'smtp_from_name' => $site_name !== '' ? $site_name : $plat_name,
            );

            if (@file_put_contents(CONFIG_FILE, build_config($cfg)) === false) {
                throw new RuntimeException('无法写入 config.php，请检查根目录写权限。');
            }
            @chmod(CONFIG_FILE, 0644);

            // 6) 加锁
            $lockBody = "installed_at=" . date('Y-m-d H:i:s') . "\n"
                      . "base_url=" . $cfg['base_url'] . "\n"
                      . "db=" . $cfg['db_name'] . "\n"
                      . "删除本文件可重新运行安装向导。\n";
            if (@file_put_contents(LOCK_FILE, $lockBody) === false) {
                throw new RuntimeException('无法写入 install.lock，请检查根目录写权限。');
            }

            $_SESSION['install_done'] = array(
                'base_url'   => $cfg['base_url'],
                'su_user'    => $su_user,
                'ad_user'    => $ad_user,
                'db_name'    => $cfg['db_name'],
                'sql_ok'     => $c['sql_ok'],
                'tables'     => $c['tables'],
            );
            unset($_SESSION['install_cfg']);
            $done = true;
        } catch (RuntimeException $e) {
            $errors[] = $e->getMessage();
        } catch (PDOException $e) {
            $errors[] = '写入数据库失败：' . $e->getMessage();
        }
    }

    if ($errors) {
        $step = 3;
    }
}

$finished = isset($_SESSION['install_done']);
if ($locked && isset($_SESSION['install_done'])) {
    $finished = true;
}
$result = $finished ? $_SESSION['install_done'] : array();
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>安装向导 - 车辆管理系统</title>
<style>
  * { box-sizing: border-box; }
  body {
    margin: 0; padding: 40px 16px 64px;
    font: 14px/1.7 -apple-system, BlinkMacSystemFont, "PingFang SC", "Microsoft YaHei", sans-serif;
    color: #1f2937; background: #f1f5f9;
  }
  .wrap { max-width: 760px; margin: 0 auto; }
  .brand { text-align: center; margin-bottom: 26px; }
  .brand h1 { margin: 0 0 6px; font-size: 22px; font-weight: 600; letter-spacing: .5px; }
  .brand p { margin: 0; color: #64748b; font-size: 13px; }
  .steps { display: flex; justify-content: center; gap: 6px; margin-bottom: 22px; flex-wrap: wrap; }
  .steps span {
    font-size: 12px; color: #94a3b8; padding: 5px 12px; border-radius: 99px;
    background: #fff; border: 1px solid #e2e8f0;
  }
  .steps span.on { color: #fff; background: #2563eb; border-color: #2563eb; font-weight: 600; }
  .steps span.pass { color: #047857; background: #ecfdf5; border-color: #a7f3d0; }
  .card {
    background: #fff; border: 1px solid #e2e8f0; border-radius: 14px;
    padding: 26px 28px; box-shadow: 0 1px 3px rgba(15,23,42,.05);
  }
  .card h2 { margin: 0 0 4px; font-size: 17px; }
  .card .sub { margin: 0 0 20px; color: #64748b; font-size: 13px; }
  .row { display: flex; gap: 14px; flex-wrap: wrap; }
  .row > .col { flex: 1 1 200px; }
  .field { margin-bottom: 16px; }
  .field label { display: block; margin-bottom: 6px; font-size: 13px; font-weight: 600; color: #334155; }
  .field .tip { display: block; margin-top: 5px; font-size: 12px; color: #94a3b8; font-weight: 400; }
  input[type=text], input[type=password], input[type=number] {
    width: 100%; height: 40px; padding: 0 12px; font-size: 14px; color: #0f172a;
    border: 1px solid #cbd5e1; border-radius: 8px; background: #fff; outline: none;
    font-family: inherit;
  }
  input:focus { border-color: #2563eb; box-shadow: 0 0 0 3px rgba(37,99,235,.12); }
  .check { display: flex; align-items: center; gap: 8px; font-size: 13px; color: #334155; margin: 4px 0 16px; }
  .check input { width: 16px; height: 16px; margin: 0; }
  table.checks { width: 100%; border-collapse: collapse; font-size: 13px; }
  table.checks td { padding: 10px 8px; border-bottom: 1px solid #f1f5f9; vertical-align: top; }
  table.checks tr:last-child td { border-bottom: 0; }
  .badge { display: inline-block; min-width: 62px; text-align: center; font-size: 12px; font-weight: 600;
           padding: 2px 8px; border-radius: 99px; }
  .badge.ok { color: #047857; background: #ecfdf5; }
  .badge.no { color: #b91c1c; background: #fef2f2; }
  .badge.opt { color: #92400e; background: #fffbeb; }
  .cname { font-weight: 600; color: #0f172a; }
  .cneed { color: #94a3b8; font-size: 12px; }
  .chint { color: #b91c1c; font-size: 12px; margin-top: 3px; }
  .btn {
    display: inline-block; height: 42px; line-height: 42px; padding: 0 26px;
    border: 0; border-radius: 8px; font-size: 14px; font-weight: 600; font-family: inherit;
    color: #fff; background: #2563eb; cursor: pointer; text-decoration: none; text-align: center;
  }
  .btn:hover { background: #1d4ed8; }
  .btn.ghost { color: #475569; background: #f1f5f9; }
  .btn.ghost:hover { background: #e2e8f0; }
  .btn[disabled] { background: #cbd5e1; cursor: not-allowed; }
  .actions { margin-top: 22px; display: flex; gap: 10px; align-items: center; }
  .msg { padding: 12px 14px; border-radius: 9px; font-size: 13px; margin-bottom: 18px; }
  .msg.err { color: #991b1b; background: #fef2f2; border: 1px solid #fecaca; }
  .msg.info { color: #1e40af; background: #eff6ff; border: 1px solid #bfdbfe; }
  .msg ul { margin: 6px 0 0; padding-left: 18px; }
  details.smtp { margin: 4px 0 16px; border: 1px solid #e2e8f0; border-radius: 9px; padding: 12px 14px; background: #f8fafc; }
  details.smtp summary { cursor: pointer; font-size: 13px; font-weight: 600; color: #334155; }
  details.smtp[open] summary { margin-bottom: 14px; }
  .done-ico { width: 56px; height: 56px; line-height: 56px; border-radius: 50%; background: #ecfdf5;
              color: #059669; font-size: 30px; text-align: center; margin: 0 auto 14px; }
  .kv { width: 100%; border-collapse: collapse; font-size: 13px; margin: 6px 0 18px; }
  .kv td { padding: 9px 8px; border-bottom: 1px solid #f1f5f9; }
  .kv td:first-child { width: 130px; color: #64748b; }
  .kv code { background: #f1f5f9; padding: 2px 7px; border-radius: 5px; font-size: 13px; }
  .warnbox { background: #fffbeb; border: 1px solid #fde68a; color: #92400e;
             padding: 12px 14px; border-radius: 9px; font-size: 13px; margin-bottom: 18px; }
  .foot { text-align: center; color: #94a3b8; font-size: 12px; margin-top: 22px; }
  code.cmd { display: block; background: #0f172a; color: #e2e8f0; padding: 10px 12px;
             border-radius: 7px; font-size: 12px; margin-top: 6px; overflow-x: auto; white-space: pre; }
</style>
</head>
<body>
<div class="wrap">

  <div class="brand">
    <h1>🚛 车辆管理系统</h1>
    <p>SaaS 多租户版 · 安装向导</p>
  </div>

  <div class="steps">
    <?php
    $labels = array(1 => '环境检测', 2 => '数据库', 3 => '站点与账号', 4 => '完成');
    $cur = $finished ? 4 : $step;
    foreach ($labels as $n => $lb) {
        $cls = $n === $cur ? 'on' : ($n < $cur ? 'pass' : '');
        echo '<span class="' . $cls . '">' . $n . '. ' . h($lb) . '</span>';
    }
    ?>
  </div>

<?php if ($locked || $finished): ?>
  <?php if ($finished): ?>
  <div class="card">
    <div class="done-ico">✓</div>
    <h2 style="text-align:center">安装完成</h2>
    <p class="sub" style="text-align:center">系统已就绪，请立即登录并修改初始密码。</p>

    <table class="kv">
      <tr><td>后台地址</td><td><code><?= h($result['base_url']) ?>/login.php</code></td></tr>
      <tr><td>平台超管</td><td><code><?= h($result['su_user']) ?></code>　（仅用于开通企业，登录后进入「租户管理」）</td></tr>
      <tr><td>企业管理员</td><td><code><?= h($result['ad_user']) ?></code>　（日常业务后台）</td></tr>
      <tr><td>数据库</td><td><code><?= h($result['db_name']) ?></code>　共导入 <?= (int)$result['sql_ok'] ?> 条语句 / <?= (int)$result['tables'] ?> 张表</td></tr>
    </table>

    <div class="warnbox">
      <b>请务必完成以下收尾：</b>
      <div style="margin-top:6px">1. <b>删除本安装文件</b>（install.php），避免被他人重复执行；</div>
      <div>2. 立即登录后台，在「用户管理」中修改两个账号的密码；</div>
      <div>3. 如需到期邮件提醒，配置服务器定时任务：</div>
      <code class="cmd">0 8 * * * <?= h(PHP_BINDIR . '/php') ?> <?= h(ROOT_DIR) ?>/cron/remind.php &gt;&gt; <?= h(ROOT_DIR) ?>/logs/remind.log 2&gt;&amp;1</code>
    </div>

    <div class="actions">
      <a class="btn" href="<?= h($result['base_url']) ?>/login.php" target="_blank">进入系统登录</a>
    </div>
  </div>
  <?php else: ?>
  <div class="card">
    <h2>系统已安装</h2>
    <p class="sub">检测到 install.lock，安装向导已锁定。</p>
    <div class="msg info">
      如需重新安装，请先删除网站根目录下的 <b>install.lock</b>（以及 <b>config.php</b>）后刷新本页。
      <br>注意：重新安装不会自动清除旧数据库，向导会要求你确认库名。
    </div>
    <div class="actions">
      <a class="btn" href="login.php">前往登录页</a>
    </div>
  </div>
  <?php endif; ?>

<?php else: ?>

  <?php if ($errors): ?>
  <div class="msg err">
    <b>请修正以下问题：</b>
    <ul>
      <?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?>
    </ul>
  </div>
  <?php endif; ?>

  <?php if ($step === 1): ?>
  <div class="card">
    <h2>第 1 步 · 环境检测</h2>
    <p class="sub">确认服务器环境满足运行要求，标「必需」的项目必须全部通过。</p>
    <table class="checks">
      <?php foreach ($checks as $c): ?>
      <tr>
        <td style="width:34%">
          <div class="cname"><?= h($c['name']) ?></div>
        </td>
        <td style="width:34%"><span class="cneed"><?= h($c['need']) ?></span></td>
        <td style="width:16%">
          <?php if ($c['ok']): ?>
            <span class="badge ok">通过</span>
          <?php elseif ($c['hard']): ?>
            <span class="badge no">必需</span>
          <?php else: ?>
            <span class="badge opt">可选</span>
          <?php endif; ?>
        </td>
        <td>
          <?php if (!$c['ok']): ?>
            <div class="chint"><?= h($c['hint']) ?></div>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
    </table>

    <form method="post" style="margin-top:22px">
      <input type="hidden" name="step" value="2">
      <?php if ($hard_failed): ?>
        <div class="msg err" style="margin:0">存在未通过的必需项，请先解决后刷新本页重新检测。</div>
        <div class="actions"><button class="btn ghost" type="button" onclick="location.reload()">重新检测</button></div>
      <?php else: ?>
        <?php if ($has_config): ?>
          <label class="check">
            <input type="checkbox" name="allow_overwrite" value="1">
            本目录已有 config.php，我确认要覆盖它并重新安装
          </label>
        <?php endif; ?>
        <div class="actions">
          <button class="btn" type="submit">开始安装 →</button>
        </div>
      <?php endif; ?>
    </form>
  </div>

  <?php elseif ($step === 2): ?>
  <div class="card">
    <h2>第 2 步 · 数据库配置</h2>
    <p class="sub">填写 MySQL 连接信息。数据库不存在时会自动创建。</p>
    <form method="post">
      <input type="hidden" name="step" value="2">
      <input type="hidden" name="allow_overwrite" value="<?= $overwrite ? '1' : '0' ?>">
      <div class="row">
        <div class="col field">
          <label>数据库地址</label>
          <input type="text" name="db_host" value="<?= h(post_val('db_host', '127.0.0.1')) ?>" required>
          <span class="tip">本机数据库填 127.0.0.1，不要填 localhost</span>
        </div>
        <div class="col field" style="flex:0 1 140px">
          <label>端口</label>
          <input type="text" name="db_port" value="<?= h(post_val('db_port', '3306')) ?>" required>
        </div>
      </div>
      <div class="field">
        <label>数据库名</label>
        <input type="text" name="db_name" value="<?= h(post_val('db_name')) ?>" placeholder="例如 vehicle_mgr" required>
        <span class="tip">仅字母、数字、下划线</span>
      </div>
      <div class="row">
        <div class="col field">
          <label>数据库用户名</label>
          <input type="text" name="db_user" value="<?= h(post_val('db_user', 'root')) ?>" required>
        </div>
        <div class="col field">
          <label>数据库密码</label>
          <input type="password" name="db_pass" value="<?= h(isset($_POST['db_pass']) ? $_POST['db_pass'] : '') ?>">
        </div>
      </div>
      <label class="check">
        <input type="checkbox" name="wipe" value="1">
        若该库已有数据表，允许<span style="color:#b91c1c;font-weight:600">清空该库</span>后重新安装
      </label>
      <div class="actions">
        <button class="btn" type="submit">测试连接并导入 →</button>
        <a class="btn ghost" href="?">返回上一步</a>
      </div>
    </form>
  </div>

  <?php elseif ($step === 3): ?>
  <div class="card">
    <h2>第 3 步 · 站点与账号</h2>
    <p class="sub">数据库已就绪（导入 <?= (int)cfg_default('sql_ok') ?> 条语句，<?= (int)cfg_default('tables') ?> 张表）。最后设置站点信息与管理账号。</p>
    <form method="post">
      <input type="hidden" name="step" value="3">
      <div class="field">
        <label>站点访问地址</label>
        <input type="text" name="base_url" value="<?= h(post_val('base_url', detect_base_url())) ?>" required>
        <span class="tip">用于生成司机扫码链接与提醒邮件中的链接，带子目录时请一并写全（不要以 / 结尾）</span>
      </div>
      <div class="row">
        <div class="col field">
          <label>平台名称</label>
          <input type="text" name="platform_name" value="<?= h(post_val('platform_name', '爱微圈·车辆管理平台')) ?>" required>
          <span class="tip">登录页与平台超管后台显示</span>
        </div>
        <div class="col field">
          <label>企业站点名</label>
          <input type="text" name="site_name" value="<?= h(post_val('site_name', '爱微圈车辆管理系统')) ?>" required>
          <span class="tip">企业内各页面显示，可后台再改</span>
        </div>
      </div>
      <div class="field">
        <label>默认企业名称</label>
        <input type="text" name="tenant_name" value="<?= h(post_val('tenant_name', '我的企业')) ?>">
        <span class="tip">系统内置的第一家企业，后续可在「租户管理」中开通更多</span>
      </div>

      <div class="row">
        <div class="col field">
          <label>平台超管登录名</label>
          <input type="text" name="su_user" value="<?= h(post_val('su_user', 'superadmin')) ?>" required>
        </div>
        <div class="col field">
          <label>平台超管密码</label>
          <input type="password" name="su_pass" value="" placeholder="至少 6 位" required>
        </div>
      </div>
      <div class="row">
        <div class="col field">
          <label>企业管理员登录名</label>
          <input type="text" name="admin_user" value="<?= h(post_val('admin_user', 'admin')) ?>" required>
        </div>
        <div class="col field">
          <label>企业管理员密码</label>
          <input type="password" name="admin_pass" value="" placeholder="至少 6 位" required>
        </div>
      </div>

      <details class="smtp">
        <summary>邮件提醒设置（可选，稍后也可在 config.php 中修改）</summary>
        <div class="row">
          <div class="col field">
            <label>SMTP 服务器</label>
            <input type="text" name="smtp_host" value="<?= h(post_val('smtp_host')) ?>" placeholder="smtp.qq.com">
          </div>
          <div class="col field" style="flex:0 1 130px">
            <label>端口</label>
            <input type="text" name="smtp_port" value="<?= h(post_val('smtp_port', '465')) ?>">
          </div>
          <div class="col field" style="flex:0 1 130px">
            <label>加密</label>
            <input type="text" name="smtp_secure" value="<?= h(post_val('smtp_secure', 'ssl')) ?>" placeholder="ssl / tls / 空">
          </div>
        </div>
        <div class="row">
          <div class="col field">
            <label>发件邮箱账号</label>
            <input type="text" name="smtp_user" value="<?= h(post_val('smtp_user')) ?>" placeholder="xxx@qq.com">
          </div>
          <div class="col field">
            <label>邮箱授权码</label>
            <input type="password" name="smtp_pass" value="<?= h(isset($_POST['smtp_pass']) ? $_POST['smtp_pass'] : '') ?>">
          </div>
        </div>
        <div class="field" style="margin-bottom:4px">
          <label>发件地址</label>
          <input type="text" name="smtp_from" value="<?= h(post_val('smtp_from')) ?>" placeholder="留空则同发件账号">
          <span class="tip">QQ/163 邮箱需使用「授权码」，不是登录密码</span>
        </div>
      </details>

      <div class="actions">
        <button class="btn" type="submit">完成安装 ✓</button>
      </div>
    </form>
  </div>
  <?php endif; ?>

<?php endif; ?>

  <div class="foot">车辆管理系统 SaaS 多租户版 · 安装完成后请删除本文件</div>
</div>
</body>
</html>
