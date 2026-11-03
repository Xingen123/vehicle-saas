# 🚀 auto-deploy-cicd 极简实战操作指引

> **专为开发者与 AI Agent 打造的标准化部署技能**：只需一个新域名，即可全自动完成 Web 站点建站、SSL 证书配置、GitHub Actions 自动更新以及**零停机秒级原子回滚**。

---

## 📌 目录直达

- [指引一：新项目首次上线（新域名一键建站）](#-指引一新项目首次上线新域名一键建站)
- [指引二：日常开发更新（代码推送自动发布）](#-指引二日常开发更新代码推送自动发布)
- [指引三：线上故障回滚（网页一键秒级降级）](#-指引三线上故障回滚网页一键秒级降级)
- [指引四：未来更换服务器（4 步无缝迁移）](#-指引四未来更换服务器4-步无缝迁移)

---

## 🟢 指引一：新项目首次上线（新域名一键建站）

当您开发完一个新项目，并想挂在新的二级域名上时：

### 第 1 步：解析域名（耗时 20 秒）
- 打开阿里云 / 腾讯云 DNS 控制台，为您的域名添加一条 **A 记录**：
  - **主机记录**：填写二级前缀（如 `shop` 或 `demo`）
  - **记录值**：填写服务器公网 IP（如 `47.100.85.127`）

### 第 2 步：向 Agent 发送部署指令（耗时 5 秒）
在 Antigravity 对话框中直接输入以下指令：
```text
使用 auto-deploy-cicd 技能，帮我用新域名 shop.moxunlink.cn 部署当前项目到服务器，项目标识名为 shop-saas。
```

### 第 3 步：坐等 Agent 自动化执行（耗时约 1 分钟）
Agent 将全自动完成以下全部底座搭建：
- [x] 生成专属 SSH 部署秘钥并写入服务器
- [x] 在 GitHub 仓库注入 5 项部署 Secrets
- [x] 自动创建 Nginx 虚拟主机与申请 Let's Encrypt SSL（开启强制 HTTPS）
- [x] 自动在项目注入 `.github/workflows/deploy.yml` 与部署/回滚脚本
- [x] 触发首次 GitHub Actions 构建打包与部署上线

### 第 4 步：验证访问
浏览器打开 `https://shop.moxunlink.cn`，站点已开启绿锁安全运行！

---

## 🔵 指引二：日常开发更新（代码推送自动发布）

项目完成首次上线后，后续日常迭代**完全无需登录服务器**：

### 第 1 步：本地写代码并提交
在本地修改代码后，执行常规 Git 命令推送：
```bash
git add .
git commit -m "feat: 更新功能特性"
git push origin main
```

### 第 2 步：全自动更新生效
1. GitHub Actions 自动感应并触发构建流水线；
2. 约 20~30 秒内自动测试、打包并增量同步至服务器；
3. 自动原子切换版本并刷新 PHP OPCache，线上即刻呈现最新代码。

---

## 🔴 指引三：线上故障回滚（网页一键秒级降级）

若最新提交上线后突发紧急 Bug，无需重新打包，**< 1 秒**即可完成原子回滚：

### 网页端一键回滚（推荐，手机电脑皆可操作）：

1. 打开项目的 GitHub 仓库页面；
2. 点击顶部 **Actions** 菜单；
3. 在左侧工作流列表中点击 **Deploy to Server**；
4. 点击右侧展开 **Run workflow** 下拉面板：
   - **执行操作 (action)**：切换选择 `rollback`
   - **回滚目标版本 (rollback_version)**：**留空**（默认直接回滚至**上一个稳定版本**；也可输入指定 Commit SHA 如 `cab5dec`）
5. 点击绿色 **Run workflow** 按钮；
6. 服务器将在 5 秒内指令生效，原子重定向软链接，业务瞬间恢复！

### 终端应急回滚命令（耗时 < 1 秒）：
```bash
ssh root@<服务器IP> "DEPLOY_REMOTE_DIR=/www/wwwroot/<项目名> bash /www/wwwroot/<项目名>/scripts/rollback-remote.sh"
```

---

## 🟣 指引四：未来更换服务器（4 步无缝迁移）

当未来更换到腾讯云、华为云、AWS 或重装操作系统时：

### 第 1 步：在新机器执行底座初始化（1 分钟）
登录新服务器终端，直接粘贴执行初始化命令：
```bash
curl -fsSL https://raw.githubusercontent.com/Xingen123/agent-skills/main/skills/auto-deploy-cicd/resources/init-new-server.sh | bash -s -- "ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIMzrv5UUKw1Q9IBpu1VxWYS/qc9KkgVfdfc6aAqwhBg github-actions-vehicle-saas"
```

### 第 2 步：修改 DNS 解析（30 秒）
在云控制台将域名的 A 记录修改为**新服务器公网 IP**。

### 第 3 步：更新 GitHub Secret（30 秒）
打开项目的 GitHub 仓库 -> **Settings** -> **Secrets and variables** -> **Actions**：
- 点击编辑 `DEPLOY_HOST`，将其值修改为**新服务器公网 IP**。

### 第 4 步：触发一次部署（瞬间就绪）
在 GitHub Actions 页面点击 **Run workflow**（或本地执行 `git push`）。
- 流水线自动连接新服务器，自动建立版本归档与软链接，新服务器即刻接管线上流量！

---

## 📚 技能资源索引

- 自动化建站脚本：[`resources/auto-setup-vhost.sh`](./resources/auto-setup-vhost.sh)
- 新机初始化底座：[`resources/init-new-server.sh`](./resources/init-new-server.sh)
- 远程部署脚本：[`resources/deploy-remote.sh`](./resources/deploy-remote.sh)
- 远程回滚脚本：[`resources/rollback-remote.sh`](./resources/rollback-remote.sh)
- Actions 流水线模板：[`resources/deploy-workflow.yml`](./resources/deploy-workflow.yml)
- 生产安全加固规则：[`resources/nginx-security.conf`](./resources/nginx-security.conf)
- MySQL 自动灾备脚本：[`resources/backup-mysql.sh`](./resources/backup-mysql.sh)
