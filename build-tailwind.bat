@echo off
cd /d "%~dp0"
tools\tailwindcss.exe -i .\frontend\css\tailwind.input.css -o .\assets\css\tailwind.css -c .\tailwind.config.js --minify
