# stop-public.ps1
# Takes "Java Core System" offline: turns Funnel off and stops php artisan serve on 8000.
#   powershell -ExecutionPolicy Bypass -File stop-public.ps1

$ErrorActionPreference = 'Continue'
$Port = 8000

$Ts = 'C:\Program Files\Tailscale\tailscale.exe'
if (-not (Test-Path $Ts)) { $c = Get-Command tailscale -ErrorAction SilentlyContinue; if ($c) { $Ts = $c.Source } }

Write-Output "Turning Funnel off..."
& $Ts funnel --https=443 off 2>&1
& $Ts serve reset 2>&1 | Out-Null

try {
    $p = (Get-NetTCPConnection -LocalPort $Port -State Listen -ErrorAction Stop | Select-Object -First 1).OwningProcess
    if ($p) { Stop-Process -Id $p -Force; Write-Output "Stopped php serve (pid $p)" }
} catch { Write-Output "No php serve found on port $Port" }

Write-Output "Public access stopped."
