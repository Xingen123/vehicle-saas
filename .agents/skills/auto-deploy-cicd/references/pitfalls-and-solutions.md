# CI/CD 自动化部署核心踩坑与解决方案汇总

本手册整理自阿里云 Linux + 宝塔面板 + GitHub Actions 实操过程中遇到的真实错误及其根本原因与规避方案。

---

### 1. GitHub Actions 打包报错：`tar: .: file changed as we read it`
- **现象**：执行 `tar -czf release.tar.gz .` 时流水线中断报错，提示文件在读取过程中被修改。
- **原因**：打包输出目标文件 `release.tar.gz` 存放在当前工作目录 `.` 中，当 tar 读取目录打包自身时产生冲突。
- **解决方案**：
  1. 将输出路径指定为当前目录之外的系统临时目录，例如 `-czf /tmp/project-release.tar.gz .`；
  2. 加上 `--warning=no-file-changed` 参数。

---

### 2. GitHub Actions SSH 认证报错：`Load key "/home/runner/.ssh/id_ed25519": error in libcrypto`
- **现象**：Runner 配置 SSH 私钥后，连接服务器时报 OpenSSL/libcrypto 解析错误。
- **原因**：
  1. 在 Windows 下复制生成的私钥含有 `\r\n` (CRLF) 换行符；
  2. GitHub Repository Secrets 粘贴时末尾缺少必需的标准换行符 `\n`。
- **解决方案**：
  在 workflow 的 SSH 配置步骤中通过管道清洗换行并补齐结尾：
  ```bash
  printf '%s\n' "$DEPLOY_SSH_KEY" | tr -d '\r' > ~/.ssh/id_ed25519
  echo "" >> ~/.ssh/id_ed25519
  chmod 600 ~/.ssh/id_ed25519
  ```

---

### 3. 宝塔面板部署报错：`chown/chmod: changing ownership of '.user.ini': Operation not permitted`
- **现象**：远程脚本执行 `chown -R www:www $DEPLOY_DIR` 或 `chmod -R 755 $DEPLOY_DIR` 时退出码为 1，在 `set -e` 模式下直接打断部署流水线。
- **原因**：宝塔面板为了防止跨站和恶意提权，对每个站点的 `.user.ini` 默认设置了 Linux 内核免疫属性 `chattr +i`，即便是 `root` 用户也无法直接 修改其权限或归属。
- **解决方案**：
  在自动化部署脚本中对 `chown` 与 `chmod` 加上静默容错保护：
  ```bash
  chown -R www:www "$DEPLOY_DIR" 2>/dev/null || true
  chmod -R 755 "$DEPLOY_DIR" 2>/dev/null || true
  ```

---

### 4. MySQL 8.0+ 备份报错：`Access denied; you need (at least one of) the PROCESS privilege(s)`
- **现象**：普通业务账号执行 `mysqldump` 时报错中断。
- **原因**：MySQL 8.0 起 `mysqldump` 默认尝试转储表空间信息（Tablespaces），该操作需要全局 `PROCESS` 权限。
- **解决方案**：
  在 `mysqldump` 命令参数中加入 `--no-tablespaces`：
  ```bash
  mysqldump --no-tablespaces -u"$DB_USER" -p"$DB_PASS" "$DB_NAME" | gzip > backup.sql.gz
  ```

---

### 5. 源码中遗留恶意外链与暗桩跳转
- **现象**：从第三方或模板市场下载的代码，其错误页（如 `404.html`）往往藏有十六进制/实体字符编码（如 `&#104;&#116;&#116;...`）的隐藏 JS 定时跳转，诱导搜索引擎或用户跳向恶意站点。
- **防范机制**：
  上线前进行全工程特征扫描：
  ```bash
  grep -rE "eval\(|base64_decode|&#[0-9]{3};|setInterval" .
  ```
