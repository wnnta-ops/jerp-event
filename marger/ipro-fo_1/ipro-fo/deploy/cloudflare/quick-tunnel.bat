@echo off
REM ============================================================
REM  Quick Tunnel Cloudflare - akses publik SEMENTARA (gratis, tanpa akun/kartu)
REM  Dobel-klik file ini. URL publik *.trycloudflare.com akan muncul di layar.
REM  Catatan: URL ACAK & berubah tiap dijalankan ulang. Untuk uji coba.
REM  Syarat: aplikasi sudah jalan di http://localhost:8000
REM ============================================================
title Cloudflare Quick Tunnel - Java Core System

where cloudflared >nul 2>nul
if errorlevel 1 (
  echo cloudflared belum terpasang. Memasang via winget...
  winget install --id Cloudflare.cloudflared -e --accept-source-agreements --accept-package-agreements
)

echo.
echo Membuka tunnel ke http://localhost:8000 ...
echo (Salin URL https://...trycloudflare.com yang muncul di bawah. Tekan Ctrl+C untuk berhenti.)
echo.
cloudflared tunnel --url http://localhost:8000
pause
