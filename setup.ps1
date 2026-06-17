Write-Host "=========================================================" -ForegroundColor Gold
Write-Host "   Initializing Cloud-Based Linux Lab Platform Setup     " -ForegroundColor Gold
Write-Host "=========================================================" -ForegroundColor Gold

# 1. Create storage directories on the host
Write-Host "[*] Creating host storage directories..." -ForegroundColor Cyan
$isoDir = "C:\var\lib\linux-lab\isos"
$homeDir = "C:\var\lib\linux-lab\homes"
if (!(Test-Path $isoDir)) { New-Item -ItemType Directory -Force -Path $isoDir | Out-Null }
if (!(Test-Path $homeDir)) { New-Item -ItemType Directory -Force -Path $homeDir | Out-Null }
Write-Host "[+] Host directories created successfully." -ForegroundColor Green

# 2. Build Linux Lab Environment Images
Write-Host "[*] Building lab environment Docker images..." -ForegroundColor Cyan

Write-Host "    -> Building Ubuntu 22.04 LTS..." -ForegroundColor Yellow
docker build -t lab-ubuntu ./images/ubuntu-22.04

Write-Host "[+] student lab environment image built." -ForegroundColor Green

# 3. Start Core Management Platform
Write-Host "[*] Starting the platform services..." -ForegroundColor Cyan
docker-compose up -d --build

Write-Host ""
Write-Host "=========================================================" -ForegroundColor Gold
Write-Host "   Setup Complete! Linux Lab Platform is Active.         " -ForegroundColor Gold
Write-Host "=========================================================" -ForegroundColor Gold
Write-Host "Access the dashboard here: http://localhost" -ForegroundColor Green
Write-Host "Default Credentials:" -ForegroundColor Green
Write-Host "   - Admin: admin / admin123" -ForegroundColor Green
Write-Host "   - User:  testuser / testuser123" -ForegroundColor Green
Write-Host "=========================================================" -ForegroundColor Gold
