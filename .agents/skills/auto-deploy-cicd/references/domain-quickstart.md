# 新域名一键全自动部署实战指南 (Domain Quickstart)

当您拥有一个新域名（例如 `shop.moxunlink.cn`）并需要将新项目发布至服务器时，遵循本指南即可实现一键建站。

---

### 第一步：云服务商 DNS 解析（20 秒）
1. 打开阿里云 / 腾讯云 / 华为云 DNS 控制台；
2. 找到您的主域名（如 `moxunlink.cn`），添加一条 **A 记录**：
   - **主机记录**：`shop`（或者您想要的二级域名前缀）
   - **记录类型**：`A`
   - **记录值**：服务器公网 IP（例如 `47.100.85.127`）
   - **TTL**：默认（10 分钟）

---

### 第二步：向 Agent 发起一键部署指令
在 Antigravity 中向 AI Agent 发送一句话：

> *“帮我用新域名 `shop.moxunlink.cn` 部署当前项目到服务器，项目标识名为 `shop-saas`，启用 PHP 8.2 与自动回滚支持。”*

Agent 将全自动执行以下操作：
1. **调用初始化脚本**：
   ```bash
   bash auto-setup-vhost.sh shop.moxunlink.cn shop-saas 82
   ```
2. **自动完成底座**：
   - 自动在 `/www/wwwroot/shop-saas/` 建立 `releases/`、`shared/` 与 `current` 软链接目录架构；
   - 自动配置 Nginx 虚拟主机规则与安全加固规则（禁止访问 `.sql`、阻断 uploads 目录执行脚本）；
   - 自动调用 Let's Encrypt 签发 SSL 证书并开启强制 HTTPS；
3. **交付 GitHub 自动化流水线**：
   - 自动生成 `.github/workflows/deploy.yml` 与 `scripts/deploy-remote.sh`、`scripts/rollback-remote.sh`；
   - 配置 GitHub Secrets，以后只要在本地执行 `git push`，新版本即刻全自动发布上线！

---

### 第三步：验证访问
访问 `https://shop.moxunlink.cn`，页面自动开启 HTTPS 并展示部署成功欢迎页或登录界面。
