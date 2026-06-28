@echo off
REM ============================================================
REM  Java Core System - pasang AUTO-START app (selalu hidup saat login)
REM  Dobel-klik file ini. Akan otomatis minta izin Administrator (UAC).
REM ============================================================
title Pasang Auto-Start Java Core System

REM --- cek apakah sudah Administrator ---
net session >nul 2>&1
if %errorlevel% NEQ 0 (
    echo Meminta izin Administrator... klik "Yes" pada jendela UAC.
    powershell -NoProfile -Command "Start-Process -FilePath '%~f0' -Verb RunAs"
    exit /b
)

REM --- sudah Administrator ---
echo Memasang auto-start "php artisan serve" saat login...
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0install-autostart.ps1"
echo.
echo Tekan tombol apa saja untuk menutup.
pause >nul
