# Create folder for images
$outputDir = "d:\Devlopers\cloud_cli\built_images"
if (!(Test-Path $outputDir)) { New-Item -ItemType Directory -Force -Path $outputDir | Out-Null }

Write-Host "=========================================================" -ForegroundColor Gold
Write-Host "   Saving Cloud-Based Linux Lab Platform Images          " -ForegroundColor Gold
Write-Host "=========================================================" -ForegroundColor Gold

# List of all images including platform dependencies
$images = @(
    "lab-ubuntu",
    "lab-kali",
    "lab-docker",
    "lab-java",
    "lab-mysql",
    "lab-nginx",
    "mysql:8.0",
    "nginx:alpine"
)

foreach ($img in $images) {
    # Replace colons with dashes to avoid invalid filename paths
    $filename = $img.Replace(":", "-") + ".tar"
    $outputPath = Join-Path $outputDir $filename
    
    Write-Host "[*] Saving $img to $outputPath..." -ForegroundColor Cyan
    docker save -o $outputPath $img
    Write-Host "[+] Saved $img successfully." -ForegroundColor Green
}

Write-Host "=========================================================" -ForegroundColor Gold
Write-Host "   All images saved to $outputDir                        " -ForegroundColor Gold
Write-Host "=========================================================" -ForegroundColor Gold
