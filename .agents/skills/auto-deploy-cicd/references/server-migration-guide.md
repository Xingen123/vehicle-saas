# 更换新服务器完整迁移与适配指南 (Server Migration & Portability Guide)

很多开发者担心：“这套 CI/CD 是不是绑定了我现在的服务器？”
**答案是：完全不会绑定。整套技能是 100% 架构解耦与参数化的。**

当您更换服务器（例如从阿里云换到腾讯云、华为云、AWS，或者从宝塔换到原生 Ubuntu/CentOS）时，只需简单 4 步即可无缝迁移。

---

## 🚀 更换服务器 4 步无缝迁移流程

### 第一步：在新服务器执行极速底座初始化（1 分钟）
登录您的新服务器终端，运行以下命令（传入您的专属 SSH 部署公钥）：
```bash
curl -fsSL https://raw.githubusercontent.com/Xingen123/agent-skills/main/skills/auto-deploy-cicd/resources/init-new-server.sh | bash -s -- "ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIMzrv5UUKw1Q9IBpu1VxWYS/qc9KkgVfdfc6aAqwhBg github-actions-vehicle-saas"
```
该脚本会自动：
1. 将部署公钥写入 `~/.ssh/authorized_keys`；
2. 安装 `git`、`curl`、`tar`、`socat`、`acme.sh`；
3. 初始化发布目录与权限。

---

### 第二步：修改 DNS 解析指向新服务器 IP（30 秒）
登录云解析控制台（阿里云/腾讯云），将域名的 **A 记录** 改为新服务器的公网 IP。

---

### 第三步：更新 GitHub 仓库的 Secret（30 秒）
打开 GitHub 仓库 -> **Settings** -> **Secrets and variables** -> **Actions**：
- 将 `DEPLOY_HOST` 的值修改为**新服务器的公网 IP**；
- （若 SSH 端口有变化，修改 `DEPLOY_PORT`；若部署目录有变化，修改 `DEPLOY_REMOTE_DIR`）。

---

### 第四步：触发一次部署（瞬间生效）
1. 在 GitHub Actions 页面点击 **Run workflow**，或者在本地推送一个新 commit：
   ```bash
   git commit --allow-empty -m "chore: migrate to new server"
   git push origin main
   ```
2. GitHub Actions 将自动连接**新服务器**，自动执行语法检查、远程解压归档、建立软链接与权限自愈；
3. 部署成功，新服务器即刻正式接管线上业务！

---

## 💡 为什么这套技能能自适应任何新服务器？

| 核心组件 | 如何实现多服务器自适应 |
| :--- | :--- |
| **流水线逻辑** | 完全通过 GitHub Secrets 参数化驱动，代码中没有写死任何 IP、密钥或端口。 |
| **操作系统兼容** | 部署脚本内置智能嗅探：自动识别 `Ubuntu (www-data)`、`CentOS (nginx)`、`宝塔 (www)` 等不同 Linux 发行版的 Web 用户与服务名。 |
| **宝塔免疫锁兼容** | 遇到 `.user.ini`（`chattr +i`）自动静默容错，无论新服务器是否装有宝塔面板，均能平滑运行。 |
| **PHP 运行时自适应** | 自动探测 `php-fpm`、`php8.2-fpm`、`php82-php-fpm`、`php7.4-fpm` 并智能热重载，无需手动修改服务名称。 |
