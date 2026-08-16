$ErrorActionPreference = 'SilentlyContinue'

Get-Process nginx -ErrorAction SilentlyContinue | Stop-Process -Force
Remove-Item (Join-Path $env:TEMP 'minis3-nginx-dev.pid') -Force -ErrorAction SilentlyContinue
Get-Process php-cgi -ErrorAction SilentlyContinue | Stop-Process -Force
Get-Process php -ErrorAction SilentlyContinue | Stop-Process -Force
Write-Host 'Dev server stopped.'
