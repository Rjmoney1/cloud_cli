$inputDir = "d:\Devlopers\cloud_cli\built_images"
if (!(Test-Path $inputDir)) {
    Write-Error "Images folder not found at $inputDir. Please make sure the built_images directory exists."
    exit
}

Write-Host "=========================================================" -ForegroundColor Gold
Write-Host "   Loading Cloud-Based Linux Lab Platform Images         " -ForegroundColor Gold
Write-Host "=========================================================" -ForegroundColor Gold

$files = Get-ChildItem -Path $inputDir -Filter *.tar

foreach ($file in $files) {
    Write-Host "[*] Loading image from $($file.FullName)..." -ForegroundColor Cyan
    docker load -i $file.FullName
    Write-Host "[+] Loaded $($file.BaseName) successfully." -ForegroundColor Green
}

Write-Host "=========================================================" -ForegroundColor Gold
Write-Host "   All images loaded successfully!                        " -ForegroundColor Gold
Write-Host "=========================================================" -ForegroundColor Gold
