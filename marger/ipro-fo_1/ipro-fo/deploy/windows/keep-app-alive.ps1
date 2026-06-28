# keep-app-alive.ps1 — pastikan `php artisan serve` selalu hidup di 127.0.0.1:8000.
# Dipanggil otomatis oleh scheduled task 'JavaCore-App' saat login. Restart sendiri bila app mati.
$ErrorActionPreference = 'SilentlyContinue'

$app = 'C:\Users\SPV ACC MUP\Documents\Claude\FO\ipro-fo'
# php hasil install WinGet (per-user); fallback ke PATH bila path berubah.
$php = 'C:\Users\SPV ACC MUP\AppData\Local\Microsoft\WinGet\Packages\PHP.PHP.8.4_Microsoft.Winget.Source_8wekyb3d8bbwe\php.exe'
if (-not (Test-Path $php)) { $php = (Get-Command php -ErrorAction SilentlyContinue).Source }
if (-not $php) { $php = 'php' }

while ($true) {
    $up = Get-NetTCPConnection -LocalPort 8000 -State Listen -ErrorAction SilentlyContinue
    if (-not $up) {
        Start-Process -FilePath $php -ArgumentList 'artisan', 'serve', '--host=127.0.0.1', '--port=8000' `
            -WorkingDirectory $app -WindowStyle Hidden
    }
    Start-Sleep -Seconds 20
}
