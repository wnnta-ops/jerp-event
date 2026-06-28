@echo off
chcp 65001 >nul
title Java Residence - Pintu Publik NUP (Tailscale Funnel)
cd /d "C:\Users\SPV ACC MUP\Documents\Claude\FO\ipro-fo"

set "PHP=C:\Users\SPV ACC MUP\AppData\Local\Microsoft\WinGet\Packages\PHP.PHP.8.4_Microsoft.Winget.Source_8wekyb3d8bbwe\php.exe"
set "TS=C:\Program Files\Tailscale\tailscale.exe"

echo ============================================================
echo    JAVA RESIDENCE - Mengaktifkan PINTU PUBLIK NUP
echo ============================================================
echo.
echo [1/2] Memastikan web server lokal jalan (port 8000)...
tasklist /FI "IMAGENAME eq php.exe" | find /I "php.exe" >nul
if errorlevel 1 (
    start "Java Core - Web Server" /min "%PHP%" artisan serve --host=127.0.0.1 --port=8000
    timeout /t 3 /nobreak >nul
) else (
    echo     Web server sudah berjalan.
)

echo [2/2] Mengaktifkan Tailscale Funnel ke :8000...
"%TS%" funnel --bg 8000

echo.
echo ============================================================
echo  PINTU PUBLIK AKTIF:
echo    https://java-residence.tail212f65.ts.net/nup/create
echo.
echo  Bagikan URL itu ke customer. Tanpa login, tanpa halaman peringatan.
echo  Data pendaftaran otomatis masuk ke Java Core System.
echo ============================================================
echo.
echo  (Funnel berjalan sebagai background service Tailscale dan
echo   otomatis aktif lagi saat PC dinyalakan. Jalankan ulang skrip
echo   ini jika sewaktu-waktu mati.)
echo.
pause
