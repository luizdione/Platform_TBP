@echo off
setlocal EnableDelayedExpansion
chcp 65001 > nul

REM ============================================================
REM sincronizar_github.bat
REM Atualiza /github/ com versao SANITIZADA do projeto e
REM faz commit/push para luizdione/app_tpb
REM
REM USO:
REM   sincronizar_github.bat "Mensagem do commit"
REM ============================================================

set PROJ=%~dp0
set GHDIR=%PROJ%github
set REPO=https://github.com/luizdione/app_tpb.git
set MSG=%~1
if "%MSG%"=="" set MSG=chore: sync local snapshot %DATE% %TIME%

echo.
echo ============================================================
echo  SYNC GitHub: %REPO%
echo  Mensagem: %MSG%
echo ============================================================
echo.

REM Verifica git
where git >nul 2>nul
if errorlevel 1 (
    echo ERRO: git nao instalado. Instale em https://git-scm.com/download/win
    exit /b 1
)

REM Inicializa repo na primeira vez
if not exist "%GHDIR%\.git" (
    echo Inicializando repositorio em %GHDIR% ...
    if not exist "%GHDIR%" mkdir "%GHDIR%"
    cd /d "%GHDIR%"
    git init -b main
    git remote add origin %REPO%
    git config user.name "Luiz Dione"
    git config user.email "luiz.dione.melo@gmail.com"
)

REM ============================================================
REM 1) Copiar arquivos PERMITIDOS para /github/
REM ============================================================
echo [1/5] Copiando arquivos para %GHDIR% ...

REM PHP da raiz - EXCLUI explicitamente os com credenciais/locais
REM (NAO sobem: config.php, config_hostinger.php, config_local.php,
REM             sync_endpoint.php, sync_hostinger_apply.php, backup.php)
robocopy "%PROJ%" "%GHDIR%" *.php ^
  /XF config.php config_hostinger.php config_local.php ^
      sync_endpoint.php sync_hostinger_apply.php ^
  /NFL /NDL /NJH /NJS /NP > nul

REM Pastas inteiras
robocopy "%PROJ%sql"              "%GHDIR%\sql"              /MIR /XF *.sql /NFL /NDL /NJH /NJS /NP > nul
robocopy "%PROJ%analise_python"   "%GHDIR%\analise_python"   /MIR /XD .venv outputs __pycache__ /XF .env *.pyc /NFL /NDL /NJH /NJS /NP > nul
robocopy "%PROJ%ajuda_tutoriais"  "%GHDIR%\ajuda_tutoriais"  /MIR /NFL /NDL /NJH /NJS /NP > nul
robocopy "%PROJ%static"           "%GHDIR%\static"           /MIR /NFL /NDL /NJH /NJS /NP > nul
robocopy "%PROJ%_stubs"           "%GHDIR%\_stubs"           /MIR /NFL /NDL /NJH /NJS /NP > nul

REM Arquivos especificos auxiliares (NAO subir Dockerfile/docker-compose/htaccess/.env)
copy /Y "%PROJ%aplicar_migracoes.bat" "%GHDIR%\aplicar_migracoes.bat" > nul
copy /Y "%PROJ%limpar_diretorio.bat"  "%GHDIR%\limpar_diretorio.bat"  > nul
copy /Y "%PROJ%limpar_venv.bat"       "%GHDIR%\limpar_venv.bat"       > nul

REM ============================================================
REM 2) Sanitizar PHP/conteudo: remove TUDO que tem credencial
REM ============================================================
echo [2/5] Sanitizando arquivos sensiveis ...

REM Apaga arquivos PHP com credenciais
del /F /Q "%GHDIR%\config.php"                2>nul
del /F /Q "%GHDIR%\config_hostinger.php"      2>nul
del /F /Q "%GHDIR%\config_local.php"          2>nul
del /F /Q "%GHDIR%\sync_endpoint.php"         2>nul
del /F /Q "%GHDIR%\sync_hostinger_apply.php"  2>nul

REM Apaga arquivos de ambiente Docker/credenciais
del /F /Q "%GHDIR%\.htaccess"                 2>nul
del /F /Q "%GHDIR%\Dockerfile"                2>nul
del /F /Q "%GHDIR%\docker-compose.yml"        2>nul
del /F /Q "%GHDIR%\sync_hostinger.env"        2>nul
del /F /Q "%GHDIR%\sync_hostinger.env.example" 2>nul
del /F /Q "%GHDIR%\SYNC_HOSTINGER.md"         2>nul
del /F /Q "%GHDIR%\sincronizar_hostinger.bat" 2>nul

REM Apaga TODOS os arquivos .sql (dumps, migrations com dados)
del /F /Q "%GHDIR%\*.sql"                     2>nul
del /F /Q "%GHDIR%ᄣ48440_*.sql"          2>nul

REM Apaga pastas que nao devem subir
if exist "%GHDIR%\backups_sql"  rmdir /S /Q "%GHDIR%\backups_sql"
if exist "%GHDIR%\logs"         rmdir /S /Q "%GHDIR%\logs"
if exist "%GHDIR%\obsoletos"    rmdir /S /Q "%GHDIR%\obsoletos"
if exist "%GHDIR%\docker"       rmdir /S /Q "%GHDIR%\docker"
if exist "%GHDIR%\.idea"        rmdir /S /Q "%GHDIR%\.idea"
if exist "%GHDIR%\analise_python\.env"        del /F /Q "%GHDIR%\analise_python\.env"
if exist "%GHDIR%\analise_python\outputs"     rmdir /S /Q "%GHDIR%\analise_python\outputs"
if exist "%GHDIR%\analise_python\__pycache__" rmdir /S /Q "%GHDIR%\analise_python\__pycache__"

REM Apaga arquivos temporarios (Word lock files, etc.)
del /F /Q "%GHDIR%\~$*"           2>nul
del /F /Q "%GHDIR%\*.rar"         2>nul
del /F /Q "%GHDIR%\*.zip"         2>nul
del /F /Q "%GHDIR%\*.exe"         2>nul
del /F /Q "%GHDIR%\WinSCP*"       2>nul

REM ============================================================
REM 3) Garantir presenca de .gitignore, README.md e LICENSE.md
REM ============================================================
echo [3/5] Atualizando .gitignore, README.md e LICENSE.md ...

REM Copia versoes versionadas da raiz, se existirem
if exist "%PROJ%github_template\.gitignore" copy /Y "%PROJ%github_template\.gitignore" "%GHDIR%\.gitignore" > nul
if exist "%PROJ%github_template\README.md"  copy /Y "%PROJ%github_template\README.md"  "%GHDIR%\README.md"  > nul
if exist "%PROJ%github_template\LICENSE.md" copy /Y "%PROJ%github_template\LICENSE.md" "%GHDIR%\LICENSE.md" > nul

REM Cria .gitignore minimo se ainda nao existir
if not exist "%GHDIR%\.gitignore" (
    > "%GHDIR%\.gitignore" echo # Credenciais e ambiente local
    >> "%GHDIR%\.gitignore" echo config.php
    >> "%GHDIR%\.gitignore" echo config_hostinger.php
    >> "%GHDIR%\.gitignore" echo config_local.php
    >> "%GHDIR%\.gitignore" echo .htaccess
    >> "%GHDIR%\.gitignore" echo .env
    >> "%GHDIR%\.gitignore" echo *.env
    >> "%GHDIR%\.gitignore" echo sync_hostinger.env
    >> "%GHDIR%\.gitignore" echo sync_hostinger.env.example
    >> "%GHDIR%\.gitignore" echo.
    >> "%GHDIR%\.gitignore" echo # Docker / infra
    >> "%GHDIR%\.gitignore" echo Dockerfile
    >> "%GHDIR%\.gitignore" echo docker-compose.yml
    >> "%GHDIR%\.gitignore" echo docker/
    >> "%GHDIR%\.gitignore" echo.
    >> "%GHDIR%\.gitignore" echo # Sincronizacao Hostinger
    >> "%GHDIR%\.gitignore" echo sincronizar_hostinger.bat
    >> "%GHDIR%\.gitignore" echo sync_endpoint.php
    >> "%GHDIR%\.gitignore" echo sync_hostinger_apply.php
    >> "%GHDIR%\.gitignore" echo SYNC_HOSTINGER.md
    >> "%GHDIR%\.gitignore" echo.
    >> "%GHDIR%\.gitignore" echo # Dados / SQL
    >> "%GHDIR%\.gitignore" echo *.sql
    >> "%GHDIR%\.gitignore" echo backups_sql/
    >> "%GHDIR%\.gitignore" echo u112348440_*.sql
    >> "%GHDIR%\.gitignore" echo.
    >> "%GHDIR%\.gitignore" echo # Logs e temporarios
    >> "%GHDIR%\.gitignore" echo logs/
    >> "%GHDIR%\.gitignore" echo *.log
    >> "%GHDIR%\.gitignore" echo *.tmp
    >> "%GHDIR%\.gitignore" echo ~$*
    >> "%GHDIR%\.gitignore" echo.
    >> "%GHDIR%\.gitignore" echo # Obsoletos e IDE
    >> "%GHDIR%\.gitignore" echo obsoletos/
    >> "%GHDIR%\.gitignore" echo .idea/
    >> "%GHDIR%\.gitignore" echo .vscode/
    >> "%GHDIR%\.gitignore" echo.
    >> "%GHDIR%\.gitignore" echo # Binarios grandes
    >> "%GHDIR%\.gitignore" echo *.rar
    >> "%GHDIR%\.gitignore" echo *.zip
    >> "%GHDIR%\.gitignore" echo *.exe
    >> "%GHDIR%\.gitignore" echo WinSCP*
    >> "%GHDIR%\.gitignore" echo.
    >> "%GHDIR%\.gitignore" echo # Python
    >> "%GHDIR%\.gitignore" echo __pycache__/
    >> "%GHDIR%\.gitignore" echo *.pyc
    >> "%GHDIR%\.gitignore" echo analise_python/outputs/
    >> "%GHDIR%\.gitignore" echo .venv/
)

REM ============================================================
REM 4) Verificacao final de seguranca
REM ============================================================
echo [4/5] Verificacao de seguranca (procurando credenciais residuais) ...
findstr /S /I /M "tpb_dev_pwd HOSTINGER_DB_PASS HOSTINGER_SFTP_PASS datascience2026 u112348440_ifrj_pass" "%GHDIR%\*.php" "%GHDIR%\*.yml" "%GHDIR%\*.bat" "%GHDIR%\*.env" 2>nul > "%TEMP%\gh_leak.txt"
for %%A in ("%TEMP%\gh_leak.txt") do set SIZE=%%~zA
if !SIZE! gtr 0 (
    echo.
    echo *** ALERTA: arquivos abaixo podem conter credenciais. NAO COMMITAREI. ***
    type "%TEMP%\gh_leak.txt"
    del "%TEMP%\gh_leak.txt"
    echo.
    echo Revise e remova as credenciais antes de rodar de novo.
    exit /b 2
)
del "%TEMP%\gh_leak.txt" 2>nul

REM Garante que arquivos proibidos NAO estao no diretorio
set FORBIDDEN=config.php config_hostinger.php config_local.php .htaccess Dockerfile docker-compose.yml sincronizar_hostinger.bat sync_endpoint.php sync_hostinger.env sync_hostinger.env.example SYNC_HOSTINGER.md sync_hostinger_apply.php
for %%F in (%FORBIDDEN%) do (
    if exist "%GHDIR%\%%F" (
        echo *** ALERTA: arquivo proibido %%F encontrado em %GHDIR%. Abortando. ***
        exit /b 4
    )
)

REM ============================================================
REM 5) git commit + push
REM ============================================================
echo [5/5] git add + commit + push ...
cd /d "%GHDIR%"

git add -A
git status --short
echo.

git commit -m "%MSG%"
if errorlevel 1 (
    echo Nada a commitar.
    exit /b 0
)

git push -u origin main
if errorlevel 1 (
    echo Erro no push. Verifique credenciais ^(PAT^) e o repositorio remoto.
    exit /b 3
)

echo.
echo ============================================================
echo  Sync GitHub concluido com sucesso.
echo  Veja em https://github.com/luizdione/app_tpb
echo ============================================================
exit /b 0
