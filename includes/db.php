<?php
/**
 * 数据库连接
 *
 * 未安装检测：config.php 由 install.php 安装向导生成，
 * 缺失时把用户引导到安装向导，而不是抛出一片 PHP 致命错误。
 */
if (!is_file(dirname(__DIR__) . '/config.php')) {
    $vm_root        = dirname(__DIR__);
    $vm_hasInstall  = is_file($vm_root . '/install.php');

    // 命令行（定时任务等）下不输出 HTML
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, "[错误] 系统尚未安装：缺少 config.php，请先在浏览器访问 install.php 完成安装。\n");
        exit(1);
    }

    // 推导安装向导地址（兼容站点部署在子目录、以及从 h5/ 等子目录访问的情形）
    $vm_script = isset($_SERVER['SCRIPT_NAME']) ? str_replace('\\', '/', $_SERVER['SCRIPT_NAME']) : '/index.php';
    $vm_dir    = rtrim(dirname($vm_script), '/');
    if (preg_match('#/(h5|cron|includes)$#', $vm_dir)) {
        $vm_dir = preg_replace('#/(h5|cron|includes)$#', '', $vm_dir);
    }
    $vm_installUrl = $vm_dir . '/install.php';

    if (!headers_sent()) {
        header('HTTP/1.1 503 Service Unavailable');
        header('Content-Type: text/html; charset=UTF-8');
    }

    if ($vm_hasInstall) {
        $vm_title = '系统尚未安装';
        $vm_body  = '<p>检测到网站根目录缺少 <code>config.php</code>，该文件由安装向导在安装时自动生成。</p>'
                  . '<p>请运行安装向导完成安装，全程约 1 分钟。</p>'
                  . '<p style="text-align:center;margin-top:26px">'
                  . '<a class="btn" href="' . htmlspecialchars($vm_installUrl, ENT_QUOTES, 'UTF-8') . '">开始安装 →</a></p>'
                  . '<p class="tip">若按钮无反应，请手动访问：<code>' . htmlspecialchars($vm_installUrl, ENT_QUOTES, 'UTF-8') . '</code></p>';
        $vm_redirect = '<meta http-equiv="refresh" content="5;url=' . htmlspecialchars($vm_installUrl, ENT_QUOTES, 'UTF-8') . '">';
        $vm_note     = '5 秒后自动跳转到安装向导…';
    } else {
        $vm_title = '配置文件缺失';
        $vm_body  = '<p>网站根目录缺少 <code>config.php</code>，且安装向导 <code>install.php</code> 已不在。</p>'
                  . '<p>通常说明：安装完成后误删了配置文件，或上传时未包含它。</p>'
                  . '<p><b>处理办法：</b></p>'
                  . '<ol style="margin:6px 0 0;padding-left:22px;line-height:2">'
                  . '<li>若数据库中的数据还在 —— 重新上传包内的 <code>install.php</code> 后访问它；'
                  . '向导会提示「数据库已存在数据表」，此时请<b>不要</b>勾选清空，改用手工恢复（见下）；</li>'
                  . '<li>或手工新建 <code>config.php</code>（可直接从安装包解压后复制，但需自行填写数据库信息）。</li>'
                  . '</ol>';
        $vm_redirect = '';
        $vm_note     = '';
    }

    echo '<!DOCTYPE html><html lang="zh-CN"><head><meta charset="UTF-8">'
       . '<meta name="viewport" content="width=device-width, initial-scale=1">'
       . '<title>' . $vm_title . '</title>' . $vm_redirect
       . '<style>'
       . 'body{margin:0;padding:60px 16px;font:14px/1.8 -apple-system,BlinkMacSystemFont,"PingFang SC","Microsoft YaHei",sans-serif;'
       . 'background:#f1f5f9;color:#1f2937}'
       . '.box{max-width:640px;margin:0 auto;background:#fff;border:1px solid #e2e8f0;border-radius:14px;'
       . 'padding:32px 34px;box-shadow:0 1px 3px rgba(15,23,42,.05)}'
       . 'h1{margin:0 0 6px;font-size:20px}'
       . '.sub{margin:0 0 22px;color:#64748b;font-size:13px}'
       . 'code{background:#f1f5f9;padding:2px 7px;border-radius:5px;font-size:13px}'
       . '.btn{display:inline-block;height:42px;line-height:42px;padding:0 30px;border-radius:8px;'
       . 'background:#2563eb;color:#fff;font-weight:600;text-decoration:none}'
       . '.btn:hover{background:#1d4ed8}'
       . '.tip{margin-top:20px;color:#94a3b8;font-size:12px;text-align:center}'
       . '.note{margin-top:18px;padding-top:16px;border-top:1px solid #f1f5f9;color:#64748b;font-size:12px;text-align:center}'
       . '</style></head><body><div class="box">'
       . '<h1>⚠️ ' . $vm_title . '</h1>'
       . '<p class="sub">车辆管理系统 · 部署检查</p>'
       . $vm_body
       . ($vm_note !== '' ? '<div class="note">' . $vm_note . '</div>' : '')
       . '</div></body></html>';
    exit;
}

require_once dirname(__DIR__) . '/config.php';

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }
    return $pdo;
}

/** 查询多行 */
function q(string $sql, array $params = []): array
{
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st->fetchAll();
}

/** 查询单行 */
function q1(string $sql, array $params = []): ?array
{
    $st = db()->prepare($sql);
    $st->execute($params);
    $row = $st->fetch();
    return $row === false ? null : $row;
}

/** 执行写操作，返回受影响行数 */
function exec_sql(string $sql, array $params = []): int
{
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st->rowCount();
}

/** 获取最后插入 ID */
function last_id(): string
{
    return db()->lastInsertId();
}
