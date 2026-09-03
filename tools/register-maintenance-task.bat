@echo off
REM Registers the SNDRA Park maintenance sweep with Windows Task Scheduler.
REM Run this once, as Administrator. It replaces any existing registration.
REM
REM The sweep releases no-show slots, issues the warnings that go with them,
REM and sends the reminder that warns a driver first. Without it, none of that
REM happens unless somebody happens to open a page.

setlocal
set TASK_NAME=SNDRAPark Maintenance Sweep
set PHP_EXE=C:\xampp\php\php.exe
set SCRIPT=%~dp0..\backend\cli\run-maintenance.php

if not exist "%PHP_EXE%" (
  echo ERROR: PHP not found at %PHP_EXE%
  echo Edit PHP_EXE in this file to match your XAMPP install.
  exit /b 1
)

echo Registering "%TASK_NAME%" to run every 5 minutes...
schtasks /Create /F /TN "%TASK_NAME%" /SC MINUTE /MO 5 /TR "\"%PHP_EXE%\" \"%SCRIPT%\" --quiet"

if errorlevel 1 (
  echo.
  echo Registration failed. Right-click this file and choose "Run as administrator".
  exit /b 1
)

echo.
echo Done. Useful commands:
echo   schtasks /Run    /TN "%TASK_NAME%"     run it now
echo   schtasks /Query  /TN "%TASK_NAME%"     check it
echo   schtasks /Delete /TN "%TASK_NAME%" /F  remove it
endlocal
