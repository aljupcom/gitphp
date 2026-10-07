# Starts MySQL + WAMP Apache for GitPHP.
# The Apache config pairs php8apache2_4.dll with php8ts.dll from the SAME PHP
# version directory (php8.4.15). Mixing versions crashes the Apache child.
$env:PATH = "C:\wamp64\bin\php\php8.4.15;" + $env:PATH

# --- MySQL first (the app is useless without it) ---
if (-not (Get-NetTCPConnection -LocalPort 3306 -State Listen -ErrorAction SilentlyContinue)) {
    $mysqld = "C:\wamp64\bin\mysql\mysql8.4.7\bin\mysqld.exe"
    $myIni  = "C:/wamp64/bin/mysql/mysql8.4.7/my.ini"
    Start-Process -FilePath $mysqld -ArgumentList "--defaults-file=$myIni" -WindowStyle Hidden
    Write-Host "MySQL starting..."
    Start-Sleep -Seconds 6
    if (Get-NetTCPConnection -LocalPort 3306 -State Listen -ErrorAction SilentlyContinue) {
        Write-Host "MySQL running on port 3306"
    } else {
        Write-Host "WARNING: MySQL did not come up — check C:\wamp64\bin\mysql\mysql8.4.7\data\*.err"
    }
} else {
    Write-Host "MySQL already running on port 3306"
}

# --- Apache ---
$httpd = "C:\wamp64\bin\apache\apache2.4.65\bin\httpd.exe"
$conf  = Join-Path $PSScriptRoot "apache-gitphp.conf"

if (Get-NetTCPConnection -LocalPort 8080 -State Listen -ErrorAction SilentlyContinue) {
    Write-Host "Port 8080 already in use — stop the existing server first."
    exit 1
}

Start-Process -FilePath $httpd -ArgumentList "-f", $conf -WindowStyle Hidden
Start-Sleep -Seconds 2

$proc = Get-Process httpd -ErrorAction SilentlyContinue
if ($proc) {
    Write-Host "Apache (GitPHP) running: PID $($proc.Id -join ', ') -> http://localhost:8080"
} else {
    Write-Host "Apache failed to start. Check storage\httpd-error.log"
    exit 1
}
