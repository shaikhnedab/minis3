$ErrorActionPreference = 'Stop'

# Everything is derived from this script's location, so the project folder
# can live anywhere - a normal folder or WSL (\\wsl.localhost\...).
$proj = $PSScriptRoot
$root = $proj

# nginx on Windows cannot use UNC paths; map the WSL share to a drive letter.
if ($proj.StartsWith('\\')) {
    $parts = $proj.TrimStart('\').Split('\')
    if ($parts.Count -lt 3) { Write-Error "Unexpected UNC path: $proj"; exit 1 }
    $share = '\\' + $parts[0] + '\' + $parts[1]
    $rest = ($parts[2..($parts.Count - 1)] -join '\')
    $drive = $null
    Get-PSDrive -PSProvider FileSystem -ErrorAction SilentlyContinue | ForEach-Object {
        if ($_.DisplayRoot -and $_.DisplayRoot -eq $share) { $drive = $_.Name }
    }
    if (-not $drive) {
        foreach ($l in 'W', 'X', 'Y', 'Z', 'V', 'U') {
            if (-not (Test-Path "$($l):")) { $drive = $l; break }
        }
        if (-not $drive) { Write-Error 'No free drive letter to map the WSL share.'; exit 1 }
        net use "$($drive):" $share /persistent:no | Out-Null
        if ($LASTEXITCODE -ne 0) { Write-Error "Could not map $share to $($drive):"; exit 1 }
        Start-Sleep -Milliseconds 800
        Write-Host "Mapped $share to $($drive):"
    }
    $root = "$($drive):\" + $rest
}
$rootFwd = $root.Replace('\', '/')

$phpDir = (Get-ChildItem "$env:LOCALAPPDATA\Microsoft\WinGet\Packages\PHP.PHP.8.1_*" -Directory -ErrorAction SilentlyContinue | Select-Object -First 1 -ExpandProperty FullName)
if (-not $phpDir) { Write-Error 'PHP not found'; exit 1 }
$phpCgi = Join-Path $phpDir 'php-cgi.exe'
$nginxRoot = (Get-ChildItem "$env:LOCALAPPDATA\Microsoft\WinGet\Packages\nginxinc.nginx_*\nginx-*\nginx.exe" -ErrorAction SilentlyContinue | Select-Object -First 1 -ExpandProperty FullName)
if (-not $nginxRoot) { Write-Error 'nginx not found'; exit 1 }
$nginxRoot = Split-Path $nginxRoot
$nginxExe = Join-Path $nginxRoot 'nginx.exe'

$err = Join-Path $env:TEMP 'php-cgi-err.log'

Get-Process php-cgi -ErrorAction SilentlyContinue | Stop-Process -Force
Get-Process nginx -ErrorAction SilentlyContinue | Stop-Process -Force
Start-Sleep -Milliseconds 500

# Render the nginx config and fastcgi params into %TEMP% with real paths.
$cfgPath = Join-Path $env:TEMP 'minis3-nginx-dev.conf'
$tempFwd = $env:TEMP.Replace('\', '/')
(Get-Content (Join-Path $proj 'nginx-dev.conf') -Raw) `
    .Replace('__MINIS3_ROOT__', $rootFwd) `
    .Replace('__TEMP__', $tempFwd) | Set-Content -Path $cfgPath -Encoding ASCII
Copy-Item (Join-Path $proj 'nginx-dev-fastcgi_params') (Join-Path $env:TEMP 'minis3-nginx-dev-fastcgi_params') -Force

$cgiArgs = @(
    '-b', '127.0.0.1:9123',
    '-d', "extension_dir=$phpDir\ext",
    '-d', 'extension=pdo_sqlite',
    '-d', 'extension=sqlite3',
    '-d', 'upload_max_filesize=10G',
    '-d', 'post_max_size=10245M',
    '-d', 'max_file_uploads=100',
    '-d', "error_log=$err",
    '-d', 'max_execution_time=0'
)
1..3 | ForEach-Object {
    Start-Process -FilePath $phpCgi -ArgumentList $cgiArgs -WindowStyle Hidden -RedirectStandardError "$err" -RedirectStandardOutput "$env:TEMP\php-cgi-out.log"
}

Start-Process -FilePath $nginxExe -ArgumentList '-p', "$nginxRoot\", '-c', "$cfgPath" -WindowStyle Hidden

Start-Sleep -Seconds 2
try {
    $r = Invoke-WebRequest -Uri 'http://127.0.0.1:8765/' -UseBasicParsing -TimeoutSec 5
    Write-Host "Server up: http://127.0.0.1:8765/ ($($r.StatusCode))"
} catch {
    Write-Host "Server up: http://127.0.0.1:8765/ (expected 403 on /)"
}
