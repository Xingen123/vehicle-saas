# 🚀 auto-deploy-cicd：Web 项目全自动部署与秒级回滚技能使用说明

> 本技能为 Antigravity Agent 定制技能，旨在让开发者仅凭一个新域名即可完成整套 Web 站点的自动化部署、GitHub Actions CI/CD 流水线搭建、SSL 证书自动签发以及**一键秒级版本回滚**。

---

## ✨ 核心特性

- 🌐 **新域名一键全自动建站**：只需提供二级域名，自动完成 Nginx 虚拟主机、Let's Encrypt 证书签发与强制 HTTPS 配置。
- 🔄 **Git Push 自动更新**：提交代码到 `main` 分支后，GitHub Actions 自动构建打包，并增量部署到云服务器。
- ⏪ **零停机秒级原子回滚**：采用现代云原生 `releases/` 多版本归档与 `current` 软链接机制，支持在 GitHub Actions 界面一键回滚或命令行秒级切换版本。
- 🛡️ **生产安全硬加固**：自带敏感文件拦截（禁止下载 `.sql`/`.env`/`config.php` 等）、静态上传目录脚本执行阻断、宝塔 `.user.ini`（`chattr +i`）免疫锁自愈容错。
- 💾 **全自动数据灾备**：兼容 MySQL 8.0+ 的每日定时备份归档，自动清理 30 天前历史数据。

---

## 📖 极速上手：新项目部署流程

### 场景一：给 Agent 一个新域名，全自动部署新项目

当您有一个新开发的项目和新解析的域名（如 `demo.moxunlink.cn`）：

1. **解析域名**：在阿里云/腾讯云 DNS 控制台添加一条 A 记录指向服务器 IP；
2. **在 Antigravity 发起对话**：
   > “使用 `auto-deploy-cicd` 技能，帮我用新域名 `demo.moxunlink.cn` 部署当前项目到服务器，项目名为 `demo-app`。”
3. **Agent 执行的全部操作**：
   - 自动生成专属 SSH 部署私钥并写入服务器；
   - 自动在 GitHub 仓库添加 5 个 Deployment Secrets；
   - 自动在服务器上配置 Nginx 站点底座与申请 SSL 证书；
   - 自动注入 `.github/workflows/deploy.yml` 与 `scripts/deploy-remote.sh`；
   - 推送代码触发首次部署上线！

---

## ⏪ 一键回滚使用指南

当线上最新发布遇到 Bug 或需要紧急降级时：

### 网页端一键操作（无需登录服务器）：
1. 进入 GitHub 仓库页面 -> 点击 **Actions**；
2. 在左侧选中 **Deploy to Server** 工作流 -> 点击右侧 **Run workflow** 下拉菜单；
3. 将操作切换为 `rollback`（回滚），版本号留空则默认回滚到**上一个版本**，或填入指定 commit SHA；
4. 点击绿色按钮运行，**5 秒内线上自动恢复至稳定版本**。

### 命令行应急操作（耗时 < 1 秒）：
```bash
ssh root@<服务器IP> "bash /www/wwwroot/<项目名>/scripts/rollback-remote.sh"
```

---

## 📂 技能资产索引

- 自动化建站脚本：[`resources/auto-setup-vhost.sh`](./resources/auto-setup-vhost.sh)
- Actions 流水线模板：[`resources/deploy-workflow.yml`](./resources/deploy-workflow.yml)
- 远程部署脚本：[`resources/deploy-remote.sh`](./resources/deploy-remote.sh)
- 远程回滚脚本：[`resources/rollback-remote.sh`](./resources/rollback-remote.sh)
- Nginx 安全规则：[`resources/nginx-security.conf`](./resources/nginx-security.conf)
- MySQL 定时灾备：[`resources/backup-mysql.sh`](./resources/backup-mysql.sh)
- 新域名部署指南：[`references/domain-quickstart.md`](./references/domain-quickstart.md)
- 版本回滚实战指南：[`references/rollback-guide.md`](./references/rollback-guide.md)
- 踩坑避坑汇总：[`references/pitfalls-and-solutions.md`](./references/pitfalls-and-solutions.md)
