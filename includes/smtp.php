<?php
/**
 * 轻量 SMTP 客户端（不依赖任何第三方库）
 * 支持：ssl://465、STARTTLS 587、明文 25，AUTH LOGIN 认证，UTF-8 邮件
 */
class SmtpClient
{
    private $sock;
    private $host;
    private $port;
    private $secure;
    public  $lastError = '';

    public function __construct(string $host, int $port, string $secure = 'ssl')
    {
        $this->host   = $host;
        $this->port   = $port;
        $this->secure = $secure;
    }

    private function read(): string
    {
        $data = '';
        while (($line = fgets($this->sock, 515)) !== false) {
            $data .= $line;
            // 最后一条响应行第4个字符是空格
            if (strlen($line) >= 4 && $line[3] === ' ') break;
        }
        return $data;
    }

    private function cmd(string $c, array $expect): bool
    {
        fwrite($this->sock, $c . "\r\n");
        $resp = $this->read();
        foreach ($expect as $code) {
            if (strpos($resp, (string)$code) === 0) return true;
        }
        $this->lastError = trim("$c => $resp");
        return false;
    }

    public function connect(): bool
    {
        $remote = ($this->secure === 'ssl' ? 'ssl://' : '') . $this->host . ':' . $this->port;
        $errno = 0;
        $errstr = '';
        try {
            // 优先 stream_socket_client（兼容性更好）；失败再退 fsockopen
            $ctx = stream_context_create(['ssl' => ['verify_peer' => false, 'verify_peer_name' => false]]);
            $sock = @stream_socket_client($remote, $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $ctx);
            if (!$sock) {
                $sock = @fsockopen($remote, $errno, $errstr, 15);
            }
            $this->sock = $sock ?: false;
        } catch (\Throwable $e) {
            // 个别定制 PHP 环境对引用传参有限制，兜底为可读错误而非 500
            $this->lastError = '连接异常：' . $e->getMessage();
            return false;
        }
        if (!$this->sock) { $this->lastError = "连接失败：$errstr ($errno)"; return false; }
        stream_set_timeout($this->sock, 15);

        if (strpos($this->read(), '220') !== 0) { $this->lastError = 'SMTP 服务未就绪'; return false; }

        $ehlo = gethostname() ?: 'localhost';
        if (!$this->cmd("EHLO $ehlo", [250])) return false;

        if ($this->secure === 'tls') {
            if (!$this->cmd('STARTTLS', [220])) return false;
            if (!stream_socket_enable_crypto($this->sock, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                $this->lastError = 'STARTTLS 加密协商失败'; return false;
            }
            if (!$this->cmd("EHLO $ehlo", [250])) return false;
        }
        return true;
    }

    public function auth(string $user, string $pass): bool
    {
        if (!$this->cmd('AUTH LOGIN', [334])) return false;
        if (!$this->cmd(base64_encode($user), [334])) return false;
        return $this->cmd(base64_encode($pass), [235]);
    }

    private function encodeHeader(string $s): string
    {
        return '=?UTF-8?B?' . base64_encode($s) . '?=';
    }

    public function send(string $from, string $fromName, array $to, string $subject, string $htmlBody): bool
    {
        if (!$this->cmd('MAIL FROM:<' . $from . '>', [250])) return false;
        foreach ($to as $addr) {
            if (!$this->cmd('RCPT TO:<' . $addr . '>', [250, 251])) return false;
        }
        if (!$this->cmd('DATA', [354])) return false;

        $headers = [
            'From: ' . $this->encodeHeader($fromName) . ' <' . $from . '>',
            'To: ' . implode(', ', $to),
            'Subject: ' . $this->encodeHeader($subject),
            'MIME-Version: 1.0',
            'Content-Type: text/html; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
            'Date: ' . date('r'),
        ];
        $body = chunk_split(base64_encode($htmlBody));
        fwrite($this->sock, implode("\r\n", $headers) . "\r\n\r\n" . $body . "\r\n.\r\n");
        $resp = $this->read();
        if (strpos($resp, '250') !== 0) { $this->lastError = trim('DATA => ' . $resp); return false; }
        return true;
    }

    public function quit(): void
    {
        if ($this->sock) { @fwrite($this->sock, "QUIT\r\n"); fclose($this->sock); }
    }
}

/**
 * 发送提醒邮件的便捷封装。返回 [成功数, 错误信息]
 */
function send_notify_email(array $recipients, string $subject, string $html): array
{
    if (!SMTP_USER || !SMTP_PASS) return [0, 'SMTP 未配置（请在 config.php 中填写发件邮箱）'];
    if (!$recipients) return [0, '未配置收件邮箱（系统设置 → 提醒接收邮箱）'];

    try {
        $smtp = new SmtpClient(SMTP_HOST, SMTP_PORT, SMTP_SECURE);
        if (!$smtp->connect()) return [0, $smtp->lastError];
        if (!$smtp->auth(SMTP_USER, SMTP_PASS)) { $e = $smtp->lastError; $smtp->quit(); return [0, 'SMTP 认证失败：' . $e]; }

        $ok = $smtp->send(SMTP_FROM ?: SMTP_USER, SMTP_FROM_NAME, $recipients, $subject, $html);
        $err = $ok ? '' : $smtp->lastError;
        $smtp->quit();
        return [$ok ? count($recipients) : 0, $err];
    } catch (\Throwable $e) {
        return [0, '发送异常：' . $e->getMessage()];
    }
}
