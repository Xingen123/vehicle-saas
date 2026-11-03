# 🌐 新域名一键全自动建站指引 (Domain Quickstart)

---

## ⚡ 极速操作流程（共 3 步）

```
[第 1 步：解析域名] ──> [第 2 步：发送指令给 Agent] ──> [第 3 步：验证访问]
   (耗时 20 秒)               (耗时 5 秒)                  (搞定上线！)
```

### 第 1 步：配置 DNS 解析
1. 打开云厂商（阿里云 / 腾讯云等）的域名解析控制台；
2. 找到主域名（如 `moxunlink.cn`），添加一条 **A 记录**：
   - **主机记录**：`shop`（您期望的二级前缀）
   - **记录类型**：`A`
   - **记录值**：`47.100.85.127`（服务器公网 IP）
   - **TTL**：默认即可

### 第 2 步：向 Agent 发送一句指令
在 Antigravity 聊天框直接发送：
> “使用 `auto-deploy-cicd` 技能，帮我用新域名 `shop.moxunlink.cn` 部署当前项目到服务器，项目标识名为 `shop-saas`。”

**Agent 全自动执行过程**：
- [x] 在服务器创建原子目录结构 (`releases/`, `shared/`, `current`)
- [x] 生成 Nginx 虚拟主机并应用安全加固规则
- [x] 自动申请 Let's Encrypt SSL 免费证书并开启强制 HTTPS
- [x] 自动配置 GitHub 部署 Secrets 与 Actions 工作流
- [x] 首次自动打包部署

### 第 3 步：验证访问
浏览器打开 `https://shop.moxunlink.cn`，即刻访问上线站点！
