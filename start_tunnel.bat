@echo off
title Bestlink College of the Philippines - Cloudflare Public Tunnel
color 0A

echo ===============================================================================
echo   Bestlink College of the Philippines - Co-Curricular SMS Public Deployment
echo   Instant Zero-Config Cloudflare Tunnel for Remote Access & Evaluation
echo ===============================================================================
echo.

if not exist "%~dp0cloudflared.exe" (
    color 0C
    echo [ERROR] cloudflared.exe was not found in:
    echo %~dp0
    echo Please make sure cloudflared.exe is placed in the project root directory.
    echo.
    pause
    exit /b 1
)

echo [INFO] Detected cloudflared binary.
echo [INFO] Starting Cloudflare Edge Tunnel for local Apache server...
echo [INFO] Target Local Service: http://localhost:80
echo.
echo ===============================================================================
echo  INSTRUCTIONS:
echo  1. Look for the line below that says:
echo     "Your quick Tunnel has been created! Visit it at: https://xxxx.trycloudflare.com"
echo  2. Copy that https://...trycloudflare.com URL and paste it into any browser or phone!
echo  3. To access this project, append /bcp if using default XAMPP htdocs:
echo     Example: https://xxxx.trycloudflare.com/bcp
echo     Diagnostics: https://xxxx.trycloudflare.com/bcp/db_check.php
echo.
echo  Press Ctrl+C at any time to stop the public tunnel.
echo ===============================================================================
echo.

"%~dp0cloudflared.exe" tunnel --url http://localhost:80

pause
