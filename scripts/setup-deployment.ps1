[CmdletBinding()]
param(
    [string]$Repo,
    [string]$ServerHost,
    [string]$ServerPort = '22',
    [string]$ServerUser = 'root',
    [string]$RemoteDir = '/www/wwwroot/vehicle-saas',
    [string]$KeyPath
)

$ErrorActionPreference = 'Stop'

Write-Host "==========================================================" -ForegroundColor Cyan
Write-Host " 🚀 车辆管理系统 (Vehicle SaaS) - GitHub 自动部署配置向导" -ForegroundColor Cyan
Write-Host "==========================================================" -ForegroundColor Cyan
Write-Host ""

# 1. 检查必要命令
if (-not (Get-Command git -ErrorAction SilentlyContinue)) {
    throw "未检测到 git 命令，请先安装 Git。"
}

# 2. 交互式获取参数
if ([string]::IsNullOrWhiteSpace($Repo)) {
    $Repo = Read-Host "请输入 GitHub 仓库名 (例如 Xingen123/vehicle-saas)"
}

if ([string]::IsNullOrWhiteSpace($ServerHost)) {
    $ServerHost = Read-Host "请输入服务器公网 IP 或域名 [默认 47.100.85.127]"
    if ([string]::IsNullOrWhiteSpace($ServerHost)) { $ServerHost = "47.100.85.127" }
}

if ([string]::IsNullOrWhiteSpace($ServerPort)) {
    $ServerPort = Read-Host "请输入 SSH 端口 [默认 22]"
    if ([string]::IsNullOrWhiteSpace($ServerPort)) { $ServerPort = "22" }
}

if ([string]::IsNullOrWhiteSpace($ServerUser)) {
    $ServerUser = Read-Host "请输入 SSH 登录用户名 [默认 root]"
    if ([string]::IsNullOrWhiteSpace($ServerUser)) { $ServerUser = "root" }
}

if ([string]::IsNullOrWhiteSpace($RemoteDir)) {
    $RemoteDir = Read-Host "请输入服务器部署目录 [默认 /www/wwwroot/vehicle-saas]"
    if ([string]::IsNullOrWhiteSpace($RemoteDir)) { $RemoteDir = "/www/wwwroot/vehicle-saas" }
}

if ([string]::IsNullOrWhiteSpace($KeyPath)) {
    $KeyPath = Read-Host "请输入用于连接服务器的 SSH 私钥路径 (例如 C:\Users\xxx\.ssh\id_ed25519 或 .pem 文件)"
}

if (-not (Test-Path $KeyPath)) {
    throw "私钥文件不存在: $KeyPath"
}
$privateKeyContent = Get-Content -Path $KeyPath -Raw

# 3. 关联并推送远程仓库
Write-Host "`n📦 [1/3] 关联 GitHub 远程仓库..." -ForegroundColor Yellow
$currentRemote = git remote get-url origin 2>$null
$targetRemote = "git@github.com:${Repo}.git"

if ($LASTEXITCODE -eq 0 -and $currentRemote) {
    git remote set-url origin $targetRemote
} else {
    git remote add origin $targetRemote
}

# 4. 配置 GitHub Secrets
Write-Host "`n🔐 [2/3] 配置 GitHub Secrets..." -ForegroundColor Yellow
if (Get-Command gh -ErrorAction SilentlyContinue) {
    # 检查 gh 登录态
    gh auth status 2>$null
    if ($LASTEXITCODE -ne 0) {
        Write-Host "检测到 GitHub CLI 未登录，正在启动登录授权..." -ForegroundColor Cyan
        gh auth login --web -h github.com
    }

    Write-Host "正在向仓库 $Repo 设置部署密钥..." -ForegroundColor Green
    $ServerHost | gh secret set DEPLOY_HOST --repo $Repo
    $ServerPort | gh secret set DEPLOY_PORT --repo $Repo
    $ServerUser | gh secret set DEPLOY_USER --repo $Repo
    $RemoteDir  | gh secret set DEPLOY_REMOTE_DIR --repo $Repo
    $privateKeyContent | gh secret set DEPLOY_SSH_KEY --repo $Repo

    Write-Host "✅ GitHub Secrets 配置完毕！" -ForegroundColor Green
} else {
    Write-Host "⚠️ 本机未安装 GitHub CLI (gh)，请前往 GitHub 仓库手动配置以下 Secrets：" -ForegroundColor Yellow
    Write-Host "   进入仓库 -> Settings -> Secrets and variables -> Actions -> New repository secret"
    Write-Host "   - DEPLOY_HOST: $ServerHost"
    Write-Host "   - DEPLOY_PORT: $ServerPort"
    Write-Host "   - DEPLOY_USER: $ServerUser"
    Write-Host "   - DEPLOY_REMOTE_DIR: $RemoteDir"
    Write-Host "   - DEPLOY_SSH_KEY: [您的私钥内容]"
}

# 5. 推送代码
Write-Host "`n🚀 [3/3] 推送最新代码到 main 分支并触发自动部署..." -ForegroundColor Yellow
Write-Host "正在执行: git push -u origin main" -ForegroundColor Cyan
git push -u origin main

Write-Host "`n🎉 配置完成！您可以在 GitHub 仓库的 'Actions' 标签页查看部署实时进度。" -ForegroundColor Green
