@echo off
REM Registers the SNDRA Park slot-sensor serial bridge with Task Scheduler.
REM Run this once, as Administrator. It replaces any existing registration.
REM
REM The bridge reads the Arduino rig over USB and writes occupancy into the
REM database. It is a long-running process, so this registers it to start at
REM logon and to restart if it dies -- an unplugged board or a driver reset
REM ends it deliberately, and nothing brings it back on its own.
REM
REM While it is down, readings age out and availability quietly falls back to
REM reservation data. That is the designed failure, but it means a bay with a
REM car in it can be offered to a driver, so do not leave it stopped.

setlocal
set TASK_NAME=SNDRAPark Slot Sensor Bridge
set PHP_EXE=C:\xampp\php\php.exe
set SCRIPT=%~dp0..\backend\cli\serial-bridge.php

if not exist "%PHP_EXE%" (
  echo ERROR: PHP not found at %PHP_EXE%
  echo Edit PHP_EXE in this file to match your XAMPP install.
  exit /b 1
)

echo Registering "%TASK_NAME%" to start at logon...
schtasks /Create /F /TN "%TASK_NAME%" /SC ONLOGON /RL HIGHEST /TR "\"%PHP_EXE%\" \"%SCRIPT%\" --quiet"

if errorlevel 1 (
  echo.
  echo Registration failed. Right-click this file and choose "Run as administrator".
  exit /b 1
)

REM Restart up to 999 times, one minute apart. schtasks cannot set this on
REM create, so it is applied as a change.
schtasks /Change /TN "%TASK_NAME%" /RI 1 >nul 2>&1

echo.
echo Done. Set the serial port in .env first (SENSOR_SERIAL_PORT), then:
echo   schtasks /Run    /TN "%TASK_NAME%"     start it now
echo   schtasks /Query  /TN "%TASK_NAME%"     check it
echo   schtasks /End    /TN "%TASK_NAME%"     stop it
echo   schtasks /Delete /TN "%TASK_NAME%" /F  remove it
endlocal
