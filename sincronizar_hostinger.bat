@echo off
setlocal EnableDelayedExpansion
chcp 65001 > nul

REM ============================================================
REM sincronizar_hostinger.bat
REM Sincronizacao bidirecional com Hostinger usando WinSCP
REM
REM USO:
REM   sincronizar_hostinger.bat download
REM   sincronizar_hostinger.bat upload
REM   sincronizar_hostinger.bat use-backup <nome_arquivo.sql>
REM   sincronizar_hostinger.bat list
REM ============================================================

set PROJ=%~dp0
set ENV=%PROJ%sync_hostinger.env
set BACKUPDIR=%PROJ%backups_sql
set ATUAL=%PROJ%u112348440_ifrj_crj_lgm.sql
set LOG=%BACKUPDIR%\sync_log.txt

REM ============================================================
REM Detectar WinSCP automaticamente em multiplas localizacoes
REM Usa subrotinas para evitar conflito de parens com paths "Program Files (x86)"
REM ============================================================
set "WINSCP="
call :tentar_path "C:\Program Files (x86)\WinSCP\WinSCP.com"
call :tentar_path "C:\Program Files\WinSCP\WinSCP.com"
call :tentar_path "%LOCALAPPDATA%\Programs\WinSCP\WinSCP.com"
call :tentar_path "%USERPROFILE%\scoop\apps\winscp\current\WinSCP.com"
if "%WINSCP%"=="" call :tentar_via_path

if "%WINSCP%"=="" goto :winscp_nao_encontrado
echo WinSCP encontrado em: %WINSCP%
goto :winscp_ok

:tentar_path
if defined WINSCP exit /b 0
if exist %1 set "WINSCP=%~1"
exit /b 0

:tentar_via_path
for /f "delims=" %%P in ('where WinSCP.com 2^>nul') do if not defined WINSCP set "WINSCP=%%P"
exit /b 0

:winscp_nao_encontrado
echo.
echo ============================================================
echo  ERRO: WinSCP nao encontrado em nenhuma localizacao comum.
echo ============================================================
echo Locais verificados:
echo   - C:\Program Files (x86)\WinSCP\WinSCP.com
echo   - C:\Program Files\WinSCP\WinSCP.com
echo   - %LOCALAPPDATA%\Programs\WinSCP\WinSCP.com
echo   - PATH do sistema
echo.
echo COMO INSTALAR ^(escolha uma opcao^):
echo   1^) Via winget ^(Windows 10/11^):  winget install martinprikryl.winscp
echo   2^) Via chocolatey:               choco install winscp
echo   3^) Manual:                       https://winscp.net/eng/download.php
echo.
echo Apos instalar, feche e reabra o terminal e rode novamente.
echo ============================================================
exit /b 1

:winscp_ok

if not exist "%ENV%" (
    echo ERRO: arquivo de credenciais nao encontrado: %ENV%
    echo Veja SYNC_HOSTINGER.md para criar.
    exit /b 1
)

REM Carregar credenciais
for /f "usebackq tokens=1,* delims==" %%a in ("%ENV%") do (
    set "%%a=%%b"
)

REM URL-encode a senha SFTP (necessario quando contem @, #, :, etc.)
REM Usa PowerShell ja que a logica de string em batch falha com chars especiais
for /f "usebackq delims=" %%i in (`powershell -NoProfile -Command "[uri]::EscapeDataString([Environment]::GetEnvironmentVariable('HOSTINGER_SFTP_PASS','Process'))"`) do set "HOSTINGER_SFTP_PASS_ENC=%%i"
if "%HOSTINGER_SFTP_PASS_ENC%"=="" set "HOSTINGER_SFTP_PASS_ENC=%HOSTINGER_SFTP_PASS%"

if not exist "%BACKUPDIR%" mkdir "%BACKUPDIR%"

set ACTION=%1
if "%ACTION%"=="" (
    echo Use: sincronizar_hostinger.bat [download^|upload^|use-backup ^<file^>^|list]
    exit /b 1
)

REM ============================================================
if /I "%ACTION%"=="download" goto :DOWNLOAD
if /I "%ACTION%"=="upload"   goto :UPLOAD
if /I "%ACTION%"=="use-backup" goto :USE_BACKUP
if /I "%ACTION%"=="list"     goto :LIST
echo Acao desconhecida: %ACTION%
exit /b 1

REM ============================================================
:DOWNLOAD
REM Faz mysqldump via SSH e baixa o arquivo
echo [%DATE% %TIME%] DOWNLOAD iniciado >> "%LOG%"

REM Timestamp
for /f "tokens=2 delims==" %%a in ('wmic OS Get localdatetime /value') do set DT=%%a
set TS=%DT:~0,4%-%DT:~4,2%-%DT:~6,2%_%DT:~8,2%-%DT:~10,2%
set OUTFILE=u112_%TS%.sql

echo Gerando dump remoto e baixando para %OUTFILE% ...

REM Script WinSCP em arquivo temporario
> "%TEMP%\winscp_dl.txt" (
    echo open sftp://%HOSTINGER_SFTP_USER%:%HOSTINGER_SFTP_PASS_ENC%@%HOSTINGER_SFTP_HOST%:%HOSTINGER_SFTP_PORT% -hostkey="*"
    echo call mysqldump --single-transaction -h %HOSTINGER_DB_HOST% -u %HOSTINGER_DB_USER% -p%HOSTINGER_DB_PASS% %HOSTINGER_DB_NAME% ^> /tmp/dump_tpb.sql
    echo get /tmp/dump_tpb.sql "%BACKUPDIR%\%OUTFILE%"
    echo call rm /tmp/dump_tpb.sql
    echo exit
)

"%WINSCP%" /script="%TEMP%\winscp_dl.txt" /log="%BACKUPDIR%\winscp_dl.log"
del "%TEMP%\winscp_dl.txt"

if exist "%BACKUPDIR%\%OUTFILE%" (
    echo Backup salvo: %BACKUPDIR%\%OUTFILE%
    echo [%DATE% %TIME%] DOWNLOAD ok: %OUTFILE% >> "%LOG%"
    echo.
    echo Atualizando symlink "atual" ...
    if exist "%ATUAL%" del /F /Q "%ATUAL%"
    mklink "%ATUAL%" "%BACKUPDIR%\%OUTFILE%"
    echo Pronto. Para recarregar Docker:
    echo   docker compose down -v ^&^& docker compose up -d --build
) else (
    echo FALHA no download. Veja %BACKUPDIR%\winscp_dl.log
    echo [%DATE% %TIME%] DOWNLOAD FALHOU >> "%LOG%"
)
exit /b 0

REM ============================================================
:UPLOAD
echo [%DATE% %TIME%] UPLOAD iniciado >> "%LOG%"
echo Subindo arquivos PHP/SQL/MD para o Hostinger ...
echo (config_hostinger.php, config_local.php, docker, analise_python, *.bat NAO sobem)
echo.

> "%TEMP%\winscp_up.txt" (
    echo open sftp://%HOSTINGER_SFTP_USER%:%HOSTINGER_SFTP_PASS_ENC%@%HOSTINGER_SFTP_HOST%:%HOSTINGER_SFTP_PORT% -hostkey="*"
    echo option batch continue
    echo option confirm off
    echo option transfer binary
    echo lcd "%PROJ:~0,-1%"
    echo cd "%HOSTINGER_SFTP_PATH%"
    REM Upload explicito por tipo - exclui configs sensiveis do servidor
    echo put -nopreservetime -filemask^="^|config_hostinger.php^;config_local.php^;sync_hostinger.env" *.php
    echo put -nopreservetime *.md
    echo put -nopreservetime *.html
    echo put -nopreservetime .htaccess
    REM put de diretorios eh recursivo automaticamente - NAO usar -r (nao existe)
    echo put -nopreservetime sql
    echo put -nopreservetime static
    echo close
    echo exit
)

"%WINSCP%" /script="%TEMP%\winscp_up.txt" /log="%BACKUPDIR%\winscp_up.log"
del "%TEMP%\winscp_up.txt"

echo [%DATE% %TIME%] UPLOAD finalizado (ver winscp_up.log) >> "%LOG%"
echo.
echo Concluido. Confira em:
echo   https://www.ifrj-crj-geneticamolecular.online/aplicativos/diagnostico_tpb/index.php
echo   https://www.ifrj-crj-geneticamolecular.online/aplicativos/diagnostico_tpb/relatorio_cientifico.php
echo   https://www.ifrj-crj-geneticamolecular.online/aplicativos/diagnostico_tpb/dashboard.php
exit /b 0

REM ============================================================
:USE_BACKUP
set TARGET=%2
if "%TARGET%"=="" (
    echo Use: sincronizar_hostinger.bat use-backup ^<arquivo.sql^>
    echo Veja arquivos disponiveis com: sincronizar_hostinger.bat list
    exit /b 1
)
if not exist "%BACKUPDIR%\%TARGET%" (
    echo ERRO: arquivo nao encontrado: %BACKUPDIR%\%TARGET%
    exit /b 1
)
if exist "%ATUAL%" del /F /Q "%ATUAL%"
mklink "%ATUAL%" "%BACKUPDIR%\%TARGET%"
echo Symlink atualizado. Recarregue o Docker:
echo   docker compose down -v
echo   docker compose up -d --build
echo [%DATE% %TIME%] USE-BACKUP %TARGET% >> "%LOG%"
exit /b 0

REM ============================================================
:LIST
echo Backups SQL disponiveis em %BACKUPDIR%:
echo.
dir /B /O-D "%BACKUPDIR%\u112_*.sql" 2>nul
echo.
echo Symlink atual aponta para:
fsutil reparsepoint query "%ATUAL%" 2>nul | findstr /B "Substitute"
exit /b 0
