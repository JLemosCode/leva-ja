# Gera o zip de instalação para Hostinger: app/ + public_html/ + database/
$ErrorActionPreference = "Stop"
$Root = Split-Path -Parent $PSScriptRoot
$Out = Join-Path $Root "dist\hostinger"
$Zip = Join-Path $Root "dist\leva-ja-hostinger.zip"

Write-Host "==> Build do frontend web..."
Push-Location (Join-Path $Root "frontend")
if (-not (Test-Path "node_modules")) { npm install }
npm run build
Pop-Location

Write-Host "==> Montando pacote..."
if (Test-Path $Out) { Remove-Item $Out -Recurse -Force }
New-Item -ItemType Directory -Path (Join-Path $Out "app\src") | Out-Null
New-Item -ItemType Directory -Path (Join-Path $Out "app\bin") | Out-Null
New-Item -ItemType Directory -Path (Join-Path $Out "public_html\api") | Out-Null
New-Item -ItemType Directory -Path (Join-Path $Out "database") | Out-Null

function Copy-Tree($From, $To) {
  New-Item -ItemType Directory -Force -Path $To | Out-Null
  Get-ChildItem -Force -Path $From | ForEach-Object {
    Copy-Item -Force -Recurse $_.FullName (Join-Path $To $_.Name)
  }
}

Copy-Tree (Join-Path $Root "backend\src") (Join-Path $Out "app\src")
Copy-Tree (Join-Path $Root "backend\bin") (Join-Path $Out "app\bin")
Copy-Item (Join-Path $Root "backend\.env.example") (Join-Path $Out "app\.env.example")
Copy-Tree (Join-Path $Root "backend\public") (Join-Path $Out "public_html\api")
Copy-Tree (Join-Path $Root "frontend\dist") (Join-Path $Out "public_html")
Copy-Item (Join-Path $Root "backend\database\schema.sql") (Join-Path $Out "database\schema.sql")
Copy-Item (Join-Path $Root "deploy\hostinger\INSTALL.md") (Join-Path $Out "INSTALL.md")
if (Test-Path $Zip) { Remove-Item $Zip -Force }
Compress-Archive -Path (Join-Path $Out "*") -DestinationPath $Zip

Write-Host ""
Write-Host "Pacote pronto:"
Write-Host "  pasta: $Out"
Write-Host "  zip:   $Zip"
Write-Host ""
Write-Host "Envie app/ para /home/USUARIO/app/ e public_html/ para o document root."
