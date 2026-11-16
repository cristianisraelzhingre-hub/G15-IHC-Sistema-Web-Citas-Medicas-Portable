@echo off
setlocal
cd /d "%~dp0"
title MediCita - Sistema de citas medicas
set "PHP_BIN=%~dp0php\php.exe"
set "PHP_INI=%~dp0php\php.ini"

if not exist "%PHP_BIN%" (
    echo No se encontro el ejecutable de PHP en la carpeta php.
    echo Verifica que hayas extraido TODO el contenido del archivo ZIP.
    pause
    exit /b 1
)

if not exist "%PHP_INI%" (
    echo No se encontro php.ini en la carpeta php.
    pause
    exit /b 1
)

echo ===================================================
echo        MEDICITA - SISTEMA DE CITAS MEDICAS
echo ===================================================
echo Iniciando servidor local en http://127.0.0.1:8080
echo Para detenerlo, cierra la ventana del servidor.
echo.

start "MediCita - Servidor local" "%PHP_BIN%" -c "%PHP_INI%" -S 127.0.0.1:8080 -t "%~dp0"
timeout /t 2 /nobreak >nul
start "" "http://127.0.0.1:8080"

echo El navegador se abrira en http://127.0.0.1:8080
echo Puedes cerrar esta ventana cuando termines de revisar.
echo ===================================================
endlocal
