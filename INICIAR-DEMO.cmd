@echo off
setlocal
cd /d "%~dp0"
set "taskPhp=%NEXO_PHP%"
if defined taskPhp goto check
for /f "delims=" %%I in ('where php 2^>nul') do if not defined taskPhp set "taskPhp=%%I"
if defined taskPhp goto check
if exist "C:\xampp\php\php.exe" set "taskPhp=C:\xampp\php\php.exe"
if defined taskPhp goto check
set "taskPhp=%CD%\storage\php\php.exe"
if exist "%taskPhp%" goto configure
echo Descargando PHP portable desde php.net dentro de la carpeta del proyecto...
if not exist "storage\php" mkdir "storage\php"
curl.exe --fail --location --silent --show-error "https://www.php.net/~windows/releases/php-8.4.27-1-nts-Win32-vs17-x64.zip" --output "storage\php.zip"
if errorlevel 1 goto failed
certutil.exe -hashfile "storage\php.zip" SHA256 | find.exe /I "dbae154a0a89791f36c3d48e68bae797878333e7d6cb5721e74617de59b63ab7" >nul
if errorlevel 1 goto failed
tar.exe -xf "storage\php.zip" -C "storage\php"
if errorlevel 1 goto failed
:configure
echo extension_dir="%CD%\storage\php\ext">"storage\php\php.ini"
echo extension=pdo_sqlite>>"storage\php\php.ini"
echo extension=sqlite3>>"storage\php\php.ini"
echo extension=mbstring>>"storage\php\php.ini"
echo date.timezone=UTC>>"storage\php\php.ini"
echo display_errors=Off>>"storage\php\php.ini"
echo log_errors=On>>"storage\php\php.ini"
:check
"%taskPhp%" scripts\check-demo.php
if errorlevel 1 goto failed
"%taskPhp%" scripts\setup-demo.php
if errorlevel 1 goto failed
if not defined NEXO_PORT set "NEXO_PORT=8765"
set "APP_URL=http://127.0.0.1:%NEXO_PORT%"
echo.
echo Abre %APP_URL%/cursos.html
echo Alumno: alumno@nexo.test   Clave: NexoAlumno2026!
echo Admin:  admin@nexo.test    Clave: NexoAdmin2026!   (panel: %APP_URL%/admin.html)
echo Manten esta ventana abierta. Deten el servidor con Ctrl+C.
"%taskPhp%" -S "127.0.0.1:%NEXO_PORT%" -t public router.php
goto :eof
:failed
echo.
echo No se pudo iniciar. Revisa README.md e instala PHP 8.2+ con pdo_sqlite y mbstring.
echo La descarga portable requiere curl.exe, certutil.exe y tar.exe de Windows.
pause
exit /b 1
