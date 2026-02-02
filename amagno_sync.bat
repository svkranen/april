@echo off
setlocal

rem --- Konfiguration ---
set SHARE=\\172.30.74.146\FileService
set USER=TECHINFRA\svc_amagno
set PASS=HIER_DEIN_PASSWORT
set ROOT=C:\inetpub\amagno_nev_interface
set PHP=C:\PHP\php.exe

rem --- Logging ---
set LOG=%ROOT%\var\log\sync_task.log

echo ================================================== >> "%LOG%"
echo %date% %time% START >> "%LOG%"

cd /d "%ROOT%" >> "%LOG%" 2>&1

rem Optional: alte Verbindung löschen (hilft bei "multiple connections" / falschen Sessions)
net use %SHARE% /delete /y >> "%LOG%" 2>&1

rem Verbindung mit Credentials herstellen
net use %SHARE% /user:%USER% %PASS% /persistent:no >> "%LOG%" 2>&1
if errorlevel 1 (
  echo %date% %time% ERROR net use failed >> "%LOG%"
  exit /b 1
)

rem Test
dir "%SHARE%\Amagno\Livesystem" >> "%LOG%" 2>&1
if errorlevel 1 (
  echo %date% %time% ERROR dir failed >> "%LOG%"
  exit /b 1
)

rem Symfony Command
"%PHP%" bin\console amagno:sync --all-connections >> "%LOG%" 2>&1
set RC=%ERRORLEVEL%

rem Optional: Verbindung wieder trennen
net use %SHARE% /delete /y >> "%LOG%" 2>&1

echo %date% %time% END rc=%RC% >> "%LOG%"
exit /b %RC%
