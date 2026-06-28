@echo off
chcp 65001 >nul
title Java Core System - STOP ONLINE
echo Menghentikan akses online...
echo.

REM Matikan tunnel Cloudflare (membuat software OFFLINE dari internet).
taskkill /IM cloudflared.exe /F >nul 2>&1
if %errorlevel%==0 (echo  - Tunnel Cloudflare dimatikan.) else (echo  - Tunnel sudah tidak berjalan.)

REM Matikan jendela web server lokal (opsional).
taskkill /FI "WINDOWTITLE eq Java Core - Web Server*" /F >nul 2>&1
if %errorlevel%==0 (echo  - Web server lokal dimatikan.) else (echo  - Web server lokal tidak ditemukan/sudah mati.)

echo.
echo Selesai. Software tidak lagi dapat diakses dari internet.
pause
