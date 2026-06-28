@echo off
chcp 65001 >nul
REM ============================================================
REM  Ringkas Percakapan Claude - dijalankan harian via Task Scheduler
REM  GRATIS / offline / tanpa API. Edit path di bawah bila pindah folder.
REM ============================================================
set "PHP=C:\Users\SPV ACC MUP\AppData\Local\Microsoft\WinGet\Packages\PHP.PHP.8.4_Microsoft.Winget.Source_8wekyb3d8bbwe\php.exe"
set "SCRIPT=C:\Users\SPV ACC MUP\Documents\Claude\FO\ipro-fo\scripts\ringkas-percakapan.php"
set "LOG=C:\Users\SPV ACC MUP\Documents\Claude\FO\ipro-fo\docs\Riwayat-Percakapan\_run.log"

echo [%date% %time%] mulai >> "%LOG%"
"%PHP%" "%SCRIPT%" >> "%LOG%" 2>&1
echo [%date% %time%] selesai (exit %errorlevel%) >> "%LOG%"
