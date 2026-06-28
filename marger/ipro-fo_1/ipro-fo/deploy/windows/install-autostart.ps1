# install-autostart.ps1 — daftarkan Java Core System (app) auto-start saat login + restart bila mati.
# Dipanggil oleh install-autostart.bat (yang otomatis minta Administrator). Lapor status sebenarnya.
$ErrorActionPreference = 'Stop'
$script = 'C:\Users\SPV ACC MUP\Documents\Claude\FO\ipro-fo\deploy\windows\keep-app-alive.ps1'

try {
    $action  = New-ScheduledTaskAction -Execute 'powershell.exe' -Argument ('-NoProfile -WindowStyle Hidden -ExecutionPolicy Bypass -File "{0}"' -f $script)
    $trigger = New-ScheduledTaskTrigger -AtLogOn
    $settings = New-ScheduledTaskSettingsSet -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries `
        -StartWhenAvailable -ExecutionTimeLimit ([TimeSpan]::Zero) -RestartCount 3 -RestartInterval (New-TimeSpan -Minutes 1)
    $principal = New-ScheduledTaskPrincipal -UserId ("{0}\{1}" -f $env:USERDOMAIN, $env:USERNAME) -LogonType Interactive -RunLevel Limited

    Register-ScheduledTask -TaskName 'JavaCore-App' -Action $action -Trigger $trigger -Settings $settings -Principal $principal -Force | Out-Null
    Start-ScheduledTask -TaskName 'JavaCore-App' -ErrorAction SilentlyContinue

    Write-Host ""
    Write-Host "BERHASIL: task 'JavaCore-App' terpasang & berjalan." -ForegroundColor Green
    Write-Host "  - Auto-start 'php artisan serve' (127.0.0.1:8000) setiap login."
    Write-Host "  - Restart otomatis dalam 20 detik bila app mati."
    Get-ScheduledTask -TaskName 'JavaCore-App' | Select-Object TaskName, State | Format-Table -AutoSize
}
catch {
    Write-Host ""
    Write-Host ("GAGAL memasang task: {0}" -f $_.Exception.Message) -ForegroundColor Red
    Write-Host "Solusi: klik-kanan install-autostart.bat -> Run as administrator (atau biarkan UAC muncul -> Yes)." -ForegroundColor Yellow
}
