# ⏪ 线上版本一键秒级回滚指引 (Rollback Guide)

当生产环境突发紧急 Bug 时，系统支持在 **< 1 秒** 内将线上流量原子切换至历史稳定版本。

---

## ⚡ 方式一：GitHub 页面 1 键回滚（推荐，手机电脑皆可）

1. 进入项目的 GitHub 仓库主页；
2. 点击顶部导航栏的 **Actions**；
3. 左侧列表中点击选中 **Deploy to Server** 工作流；
4. 点击右侧的 **Run workflow** 下拉按钮：
   - **执行操作 (action)**：选择 `rollback`
   - **回滚目标版本 (rollback_version)**：
     - **直接留空**：自动回滚至**上一个发布的稳定版本**（最常用）
     - **手动指定**：填入特定 Commit SHA（例如 `cab5dec`）
5. 点击绿色 **Run workflow** 按钮启动回滚；
6. 约 5 秒内流水线执行完毕，线上瞬间完成无缝切换！

---

## ⚡ 方式二：命令行极速回滚（应急抢修，1 秒完成）

在本地终端运行一行命令直接通知服务器切换软链接：

```bash
# 自动回滚到上一个稳定版本
ssh root@<服务器IP> "DEPLOY_REMOTE_DIR=/www/wwwroot/<项目名> bash /www/wwwroot/<项目名>/scripts/rollback-remote.sh"
```

---

## 🛡️ 为什么回滚不会丢数据？

- 生产数据库、上传图片（`/shared/uploads/`）与日志独立保存在 `shared/` 目录；
- 版本切换仅通过 `ln -sfn` 改变软链接指向，属于 Linux 内核级原子操作，零停机且无丢数据风险。
