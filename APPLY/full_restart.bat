@echo off
echo ========================================
echo COMPLETE LARAVEL RESTART
echo ========================================
echo.

cd backend

echo [1/5] Killing any existing PHP processes...
taskkill /F /IM php.exe 2>nul
timeout /t 2 /nobreak >nul
echo.

echo [2/5] Clearing all Laravel caches...
call php artisan config:clear
call php artisan route:clear  
call php artisan cache:clear
call php artisan view:clear
echo.

echo [3/5] Deleting bootstrap cache files...
if exist bootstrap\cache\config.php del /F /Q bootstrap\cache\config.php
if exist bootstrap\cache\routes-v7.php del /F /Q bootstrap\cache\routes-v7.php
if exist bootstrap\cache\services.php del /F /Q bootstrap\cache\services.php
echo.

echo [4/5] Checking routes...
call php artisan route:list --path=api
echo.

echo [5/5] Starting Laravel server on http://127.0.0.1:8000...
echo Press Ctrl+C to stop the server
echo.
call php artisan serve --host=127.0.0.1 --port=8000
