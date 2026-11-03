# 一键秒级原子版本回滚实战指南 (Zero-Downtime Rollback Guide)

当线上发生意外 Bug、数据不兼容或紧急事故时，无需重新拉取代码打包，只需触发回滚，系统将在 **< 1 秒** 内将线上流量原子切换至上一个稳定版本。

---

## 方式一：GitHub Actions 网页端一键点击回滚（推荐）

1. 打开项目的 GitHub 仓库页面；
2. 点击顶部导航栏的 **Actions** 标签；
3. 在左侧工作流列表中点击 **Deploy to Server**；
4. 点击右侧的 **Run workflow** 下拉按钮：
   - **执行操作 (action)**：选择 `rollback`
   - **回滚目标版本 (rollback_version)**：
     - 若**留空**：系统将自动回滚至**上一个发布的稳定版本**；
     - 若**指定**：可填入历史 Commit SHA（例如 `cab5dec`）或版本目录名。
5. 点击绿色的 **Run workflow** 按钮启动回滚！
6. 流水线连接服务器在 5 秒内完成软链接原子重定向并刷新 PHP OPCache，线上业务恢复。

---

## 方式二：命令行极速回滚（应急抢修，1 秒完成）

在本地终端或直接在服务器端运行：

```bash
# 自动回滚到上一个稳定版本
ssh root@47.100.85.127 "DEPLOY_REMOTE_DIR=/www/wwwroot/vehicle-saas bash /www/wwwroot/vehicle-saas/scripts/rollback-remote.sh"

# 或回滚至指定 SHA
ssh root@47.100.85.127 "DEPLOY_REMOTE_DIR=/www/wwwroot/vehicle-saas bash /www/wwwroot/vehicle-saas/scripts/rollback-remote.sh cab5dec"
```

---

## 回滚背后的技术原理（为什么不会丢数据？）

1. **持久化数据隔离存储**：
   - 数据库、用户上传的文件（`/shared/uploads/`）和配置（`/shared/config.php`）独立存放在 `shared/` 目录下。
   - 每个构建版本通过软链接指向 `shared/` 目录。
2. **原子切换无缝平滑**：
   - 切换命令使用 `ln -sfn releases/xxx current_tmp && mv -Tf current_tmp current`。
   - Linux 内核保证 `rename` 系统调用的绝对原子性，没有任何一个用户请求会遭遇“目录不存在”或 404 中间态。
3. **版本保留策略**：
   - 默认保留最近 5-10 个构建版本，既确保充足的回滚历史，又防止磁盘空间无节制膨胀。
