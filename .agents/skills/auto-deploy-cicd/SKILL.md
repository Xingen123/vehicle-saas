---
name: auto-deploy-cicd
description: >-
  自动化部署 Web 项目到 Linux 服务器（支持阿里云/宝塔/Nginx/PHP/Node等），并通过 GitHub Actions 实现代码推送（git push）后全自动测试、打包、增量更新部署与 SSL/HTTPS 配置。当用户需要为新项目配置服务器部署、实现 GitHub 自动更新、排查 CI/CD 流水线或加固生产服务器安全时使用。
---

# Web 项目自动化 CI/CD 部署技能手册 (Auto-Deploy CI/CD)

本技能提供从零到一将 Web 项目部署到远程 Linux 服务器、并通过 GitHub Actions 实现 `git push` 全自动安全构建与增量更新部署的标准化流程。

---

## 核心流程全景

```
[本地开发代码] --(git push)--> [GitHub 仓库] 
                                    | (触发 GitHub Actions)
                                    v
                              [语法检查 & 质量校验]
                                    | (排除敏感与持久化文件)
                                    v
                              [打制品包 /tmp/release.tar.gz]
                                    | (基于 ed25519 部署私钥)
                                    v
                         [SCP/SSH 交付至远程 Linux 服务器]
                                    | (执行 deploy-remote.sh)
                                    v
                     [临时保护生产配置 -> 增量解压覆盖 -> 权限自愈 -> 服务热重载]
```

---

## 标准化执行步骤 (Step-by-Step)

### 第一阶段：服务器环境与站点底座就绪
1. **域名解析**：在阿里云/腾讯云 DNS 控制台添加 A 记录，将二级域名（如 `app.example.com`）解析至目标服务器公网 IP。
2. **环境依赖**：根据项目语言版本安装运行环境（如 PHP 8.2+、Node.js、MySQL 8.0+、Nginx）。
3. **创建站点与数据库**：
   - 网站根目录推荐设置为 `/www/wwwroot/<project-name>`。
   - 创建专用 MySQL 数据库与独立密码，导入初始 `install.sql`。
4. **申请 SSL 证书**：
   - 申请 Let's Encrypt 免费证书，并开启“强制 HTTPS”（HTTP 301 重定向至 443）。

---

### 第二阶段：生成专用 SSH 部署密钥对
为了保障主账号安全，严禁使用个人主私钥，必须为每个项目/服务器生成专用部署密钥对：
1. **本地生成密钥（无密码交互）**：
   ```powershell
   ssh-keygen -t ed25519 -C "github-actions-<project-name>" -f "$env:USERPROFILE\.ssh\id_ed25519_deploy" -N '""'
   ```
2. **公钥写入服务器受信任列表**：
   在服务器 `/root/.ssh/authorized_keys` 中追加公钥内容，并确保权限安全：
   ```bash
   mkdir -p ~/.ssh && chmod 700 ~/.ssh
   echo "<id_ed25519_deploy.pub 的内容>" >> ~/.ssh/authorized_keys
   chmod 600 ~/.ssh/authorized_keys
   ```

---

### 第三阶段：配置 GitHub Repository Secrets
在 GitHub 仓库的 **Settings -> Secrets and variables -> Actions** 下添加 5 个密钥：

| Secret 键名 | 建议取值示例 | 作用与注意点 |
| :--- | :--- | :--- |
| `DEPLOY_HOST` | `47.100.85.127` | 服务器公网 IP 地址 |
| `DEPLOY_PORT` | `22` | SSH 端口（非标准端口填写实际值） |
| `DEPLOY_USER` | `root` | 登录服务器的用户 |
| `DEPLOY_REMOTE_DIR` | `/www/wwwroot/vehicle-saas` | 服务器端项目发布根目录 |
| `DEPLOY_SSH_KEY` | `-----BEGIN OPENSSH PRIVATE KEY-----...` | 专用部署私钥文本（末尾需保留换行） |

---

### 第四阶段：交付 CI/CD 流水线与远程激活脚本
1. **创建 GitHub Actions 配置文件**：
   在项目根目录下创建 `.github/workflows/deploy.yml`。
   > 完整通用模板见：[deploy-workflow.yml](./resources/deploy-workflow.yml)
   > 
   > **关键打包排除规则**：
   > ```bash
   > tar --warning=no-file-changed \
   >     --exclude='.git' --exclude='.github' \
   >     --exclude='config.php' --exclude='.env' \
   >     --exclude='uploads/*' --exclude='logs/*' \
   >     -czf /tmp/project-release.tar.gz .
   > ```
2. **创建远程激活脚本**：
   在项目根目录下创建 `scripts/deploy-remote.sh`。
   > 完整通用模板见：[deploy-remote.sh](./resources/deploy-remote.sh)
   > 
   > **关键权限容错机制**：
   > 对宝塔面板等带有 `.user.ini`（`chattr +i`）免疫锁的环境，`chown` 与 `chmod` 必须带有 `2>/dev/null || true`，防止退出码打断部署。

---

### 第五阶段：Web 服务器生产安全加固
在 Nginx 配置中（如宝塔 `extension/<domain>/security.conf`）引入安全拦截规则：
> 完整安全规则见：[nginx-security.conf](./resources/nginx-security.conf)
- **拦截敏感文件**：阻断 `.sql`、`.env`、`config.php`、`install.php` 外部直接访问（强制返回 404）。
- **静态目录防执行**：禁止 `/uploads/` 目录解析任何 `.php`、`.sh` 等脚本，杜绝 WebShell。
- **隐藏暗桩清理**：检查并清理源码工程中的恶意外链（如 404 跳转）。

---

### 第六阶段：数据灾备与日常 Cron 自动化
1. **配置数据库定时自动备份**：
   > 备份脚本见：[backup-mysql.sh](./resources/backup-mysql.sh)
   在服务器 Crontab 中添加每日凌晨 3 点自动 mysqldump 备份并保留 30 天：
   ```bash
   0 3 * * * /bin/bash /www/backup/backup_vehicle.sh >> /www/backup/database/backup.log 2>&1
   ```
2. **配置业务定时提醒任务**：
   若项目有每日提醒或到期巡检脚本（如 `cron/remind.php`），在 Crontab 中添加每日早晨 8:00 执行。

---

## 踩坑避坑与故障排查 (References)

- [部署常见报错与底层原因解析](./references/pitfalls-and-solutions.md)
  涵盖：`tar 自身读取死锁`、`SSH 私钥 Windows 换行符报错`、`宝塔 .user.ini 只读报错`、`MySQL 8.0 no-tablespaces 权限不足`等。
- [生产环境安全上线检查清单](./references/security-checklist.md)
  上线前后逐项打勾核对，确保站点无安全隐患。
