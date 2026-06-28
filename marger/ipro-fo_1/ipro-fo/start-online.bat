@echo off
chcp 65001 >nul
title Java Core System - AKSES ONLINE
cd /d "C:\Users\SPV ACC MUP\Documents\Claude\FO\ipro-fo"

set "PHP=C:\Users\SPV ACC MUP\AppData\Local\Microsoft\WinGet\Packages\PHP.PHP.8.4_Microsoft.Winget.Source_8wekyb3d8bbwe\php.exe"
set "CLOUDFLARED=C:\Program Files (x86)\cloudflared\cloudflared.exe"

echo ============================================================
echo    JAVA CORE SYSTEM  -  Mengaktifkan AKSES ONLINE
echo ============================================================
echo.
echo [1/2] Menjalankan server lokal (php artisan serve port 8000)...

REM Jalankan web server di jendela tersendiri (minimized).
start "Java Core - Web Server" /min "%PHP%" artisan serve --host=127.0.0.1 --port=8000

REM Tunggu server siap.
timeout /t 3 /nobreak >nul

echo [2/2] Membuka tunnel publik via Cloudflare...
echo.
echo  ^>^> Cari baris  https://xxxxx.trycloudflare.com  di bawah ini.
echo  ^>^> Itulah URL untuk diakses dari mana saja (HP/laptop/kantor lain).
echo  ^>^> JANGAN tutup jendela ini selama ingin software tetap ONLINE.
echo  ^>^> Tekan Ctrl+C lalu tutup untuk mematikan tunnel.
echo.
echo ------------------------------------------------------------
"%CLOUDFLARED%" tunnel --no-autoupdate --url http://localhost:8000

echo.
echo Tunnel berhenti. Software tidak lagi online.
pause
