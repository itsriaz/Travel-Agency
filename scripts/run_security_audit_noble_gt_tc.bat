@echo off
setlocal

set "BASE_DIR=C:\xampp\htdocs\Travel-Agency"
set "SCRIPT=%BASE_DIR%\scripts\security_audit_noble_gt_tc.ps1"
set "OUTPUT=%BASE_DIR%\storage\noble_security_audit.txt"

echo Running live security audit for noble.gt.tc...
echo.

powershell -ExecutionPolicy Bypass -File "%SCRIPT%" > "%OUTPUT%"
set "EXITCODE=%ERRORLEVEL%"

echo Audit report saved to:
echo %OUTPUT%
echo.

type "%OUTPUT%"
echo.

if not "%EXITCODE%"=="0" (
    echo Audit completed with warnings or failures. Review the report above.
) else (
    echo Audit completed successfully.
)

echo.
pause
exit /b %EXITCODE%
