---
name: auto-deploy-cicd
description: >-
  自动化部署 Web 项目到 Linux 服务器（支持阿里云/宝塔/Nginx/PHP/Node等），并通过 GitHub Actions 实现代码推送（git push）后全自动测试、打包、增量更新部署与 SSL/HTTPS 配置，同时支持零停机一键原子版本回滚。当用户需要为新项目配置服务器部署、实现 GitHub 自动更新、版本回滚或加固生产服务器安全时使用。
---

# Web 项目自动化 CI/CD 部署与一键回滚技能手册 (Auto-Deploy & Rollback)

本技能提供从零到一将 Web 项目部署到远程 Linux 服务器、并通过 GitHub Actions 实现 `git push` 全自动安全构建与增量更新，以及**零停机一键秒级版本回滚**的标准化流程。

---

## 核心架构设计

```
/www/wwwroot/<项目>/
├── current -> releases/20261103_cab5dec   # 当前运行版本的原子软链接
├── releases/                             # 多版本物理归档（保留最近 5-10 个版本）
│   ├── 20261103_cab5dec/
│   ├── 20261103_c8e8169/
│   └── 20261103_cbed2da/
└── shared/                               # 永久持久化数据（跨版本继承）
    ├── config.php / .env
    ├── uploads/
    └── logs/
```

- 完整人类友好使用说明请参考：[README.md](./README.md)
- 新域名一分钟部署手册：[references/domain-quickstart.md](./references/domain-quickstart.md)
- 一键秒级回滚实战指南：[references/rollback-guide.md](./references/rollback-guide.md)

---

## 标准化执行步骤 (Step-by-Step)

### 第一阶段：新域名建站与底层基础设施
1. 用户提供新二级域名（如 `app.moxunlink.cn`）并添加 A 记录解析至服务器 IP。
2. 调用自动化建站脚本：[resources/auto-setup-vhost.sh](./resources/auto-setup-vhost.sh)
   - 自动生成原子发布目录结构（`releases/`, `shared/`, `current`）；
   - 自动生成 Nginx 配置并申请 Let's Encrypt SSL 证书；
   - 自动启用强制 HTTPS 与生产安全拦截规则。

---

### 第二阶段：生成专用 SSH 部署密钥对
1. 本地生成专属部署密钥（无密码交互）：
   ```powershell
   ssh-keygen -t ed25519 -C "github-actions-<project-name>" -f "$env:USERPROFILE\.ssh\id_ed25519_deploy" -N '""'
   ```
2. 将公钥内容追加写入服务器 `/root/.ssh/authorized_keys`，并配置安全权限：
   ```bash
   mkdir -p ~/.ssh && chmod 700 ~/.ssh
   echo "<公钥内容>" >> ~/.ssh/authorized_keys && chmod 600 ~/.ssh/authorized_keys
   ```

---

### 第三阶段：配置 GitHub Secrets
在 GitHub 仓库添加 5 个密钥：
- `DEPLOY_HOST`: 服务器 IP
- `DEPLOY_PORT`: 22
- `DEPLOY_USER`: root
- `DEPLOY_REMOTE_DIR`: `/www/wwwroot/<项目名>`
- `DEPLOY_SSH_KEY`: 专用 ed25519 私钥字符串（需保留结尾换行）

---

### 第四阶段：注入 CI/CD 与远程部署/回滚脚本
1. 在项目仓库放入 Actions 配置文件：
   - 模板：[resources/deploy-workflow.yml](./resources/deploy-workflow.yml) -> `.github/workflows/deploy.yml`
   - 支持常规 `push` 自动部署，以及在 Actions 界面选择 `rollback` 手动触发回滚。
2. 在项目仓库放入远程激活与回滚脚本：
   - 模板：[resources/deploy-remote.sh](./resources/deploy-remote.sh) -> `scripts/deploy-remote.sh`
   - 模板：[resources/rollback-remote.sh](./resources/rollback-remote.sh) -> `scripts/rollback-remote.sh`

---

### 第五阶段：Web 生产安全加固
在 Nginx 配置中应用加固规则：
- 模板：[resources/nginx-security.conf](./resources/nginx-security.conf)
- 阻断 `.sql`、`.env`、`config.php`、`install.php` 外部直接访问（强制返回 404）。
- 上传目录 `/uploads/` 禁止解析执行任何 PHP 脚本。

---

### 第六阶段：数据灾备与定时任务
1. 配置每日 MySQL 数据库自动备份：
   - 脚本：[resources/backup-mysql.sh](./resources/backup-mysql.sh)
   - 添加至服务器 Crontab，每天凌晨 3:00 自动执行并保留 30 天。
2. 配置日常业务提醒或巡检 Cron（如每日 8:00 执行）。

---

## 踩坑避坑与故障排查 (References)

- [部署常见报错与底层原因解析](./references/pitfalls-and-solutions.md)
- [生产环境安全上线检查清单](./references/security-checklist.md)
