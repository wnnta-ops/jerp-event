# start-public.ps1
# Makes "Java Core System" publicly reachable via Tailscale Funnel.
# Runs automatically at Windows login (launched hidden by start-public.vbs in the Startup folder).
# Safe to run manually anytime:  powershell -ExecutionPolicy Bypass -File start-public.ps1

$ErrorActionPreference = 'Continue'

$AppDir = 'C:\Users\SPV ACC MUP\Documents\Claude\FO\ipro-fo'
$Port   = 8000

# Resolve php.exe (WinGet path first, then PATH)
$Php = 'C:\Users\SPV ACC MUP\AppData\Local\Microsoft\WinGet\Packages\PHP.PHP.8.4_Microsoft.Winget.Source_8wekyb3d8bbwe\php.exe'
if (-not (Test-Path $Php)) { $c = Get-Command php -ErrorAction SilentlyContinue; if ($c) { $Php = $c.Source } }

# Resolve tailscale.exe
$Ts = 'C:\Program Files\Tailscale\tailscale.exe'
if (-not (Test-Path $Ts)) { $c = Get-Command tailscale -ErrorAction SilentlyContinue; if ($c) { $Ts = $c.Source } }

$Log = Join-Path $AppDir 'storage\logs\public-access.log'
function Log($m) { ("{0}  {1}" -f (Get-Date -Format 'yyyy-MM-dd HH:mm:ss'), $m) | Out-File -FilePath $Log -Append -Encoding utf8 }

Log "=== start-public run ==="
Log ("php={0}" -f $Php)
Log ("tailscale={0}" -f $Ts)

# 1) Server PHP (:$Port) dikelola SEPENUHNYA oleh keepalive.ps1 (single-instance, auto-restart).
#    start-public TIDAK lagi menjalankan php agar tidak berebut port 8000 dengan keepalive
#    (penyebab 502 setelah PC sleep/restart). Di sini cukup memastikan Tailscale Funnel siap.
Log "php serve dikelola keepalive.ps1 (start-public tidak start php)"

# 2) Wait for the Tailscale service to be ready (it may still be starting at login)
for ($i = 0; $i -lt 15; $i++) {
    & $Ts status > $null 2>&1
    if ($LASTEXITCODE -eq 0) { break }
    Start-Sleep -Seconds 2
}

# 3) Re-assert Tailscale Funnel -> 127.0.0.1:8000 (clears stale IPv4 ingress registration)
try { & $Ts serve reset  2>&1 | ForEach-Object { Log "reset: $_" } }  catch { Log "reset error: $_" }
try { & $Ts funnel --bg $Port 2>&1 | ForEach-Object { Log "funnel: $_" } } catch { Log "funnel error: $_" }

Log "=== done ==="
