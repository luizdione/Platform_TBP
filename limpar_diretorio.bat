@echo off
REM ============================================================
REM limpar_diretorio.bat
REM Organiza o diretorio movendo arquivos para:
REM   - obsoletos/    (.md antigos, .sql aplicados, .php legados, binarios)
REM   - logs/         (.log, .txt de sync/manutencao)
REM Nunca APAGA conteudo — apenas move para arquivamento.
REM
REM USO:
REM   limpar_diretorio.bat            (modo interativo)
REM   limpar_diretorio.bat /quiet     (sem confirmacoes)
REM ============================================================
setlocal EnableDelayedExpansion
chcp 65001 > nul

set PROJ=%~dp0
set OBS=%PROJ%obsoletos
set LOGS=%PROJ%logs
set QUIET=0
if /I "%~1"=="/quiet" set QUIET=1

echo.
echo ============================================================
echo  ORGANIZACAO DO DIRETORIO diagnostico_tpb
echo  Politica: MOVER arquivos obsoletos para /obsoletos/
echo  Politica: CENTRALIZAR logs em /logs/
echo ============================================================
echo.

if %QUIET%==0 (
    set /p RESP=Confirma a organizacao do diretorio? [s/N]:
    if /I not "!RESP!"=="s" (
        echo Cancelado.
        exit /b 0
    )
)

REM Cria subpastas de obsoletos se nao existirem
if not exist "%OBS%"                       mkdir "%OBS%"
if not exist "%OBS%\md_antigos"            mkdir "%OBS%\md_antigos"
if not exist "%OBS%\sql_aplicados"         mkdir "%OBS%\sql_aplicados"
if not exist "%OBS%\php_legados"           mkdir "%OBS%\php_legados"
if not exist "%OBS%\binarios"              mkdir "%OBS%\binarios"
if not exist "%OBS%\tmp_lock"              mkdir "%OBS%\tmp_lock"
if not exist "%LOGS%"                      mkdir "%LOGS%"

REM ============================================================
REM [1/6] Mover .md obsoletos (refatorados em ajuda_tutoriais/)
REM ============================================================
echo [1/6] Movendo .md antigos para obsoletos\md_antigos\ ...
for %%F in (AMBIENTE_LOCAL.md COMO_ABRIR_LOCAL.md COMO_APLICAR_MIGRACOES.md ^
            CORRECAO_AUTH.md MAPA_ARQUIVOS.md MELHORIAS_CORRELACAO.md ^
            PATCH_CIENTIFICO.md PLANO_OTIMIZACAO.md PROXIMOS_PASSOS.md ^
            SYNC_HOSTINGER.md medicamentos_tpb.md README_PRECARREGAR_DADOS.md) do (
    if exist "%PROJ%%%F" (
        move /Y "%PROJ%%%F" "%OBS%\md_antigos\" > nul
        echo   ^> %%F
    )
)

REM ============================================================
REM [2/6] Mover .sql aplicados/substituidos
REM ============================================================
echo [2/6] Movendo SQL aplicados para obsoletos\sql_aplicados\ ...
if exist "%PROJ%database_setup.sql" (
    move /Y "%PROJ%database_setup.sql" "%OBS%\sql_aplicados\" > nul
    echo   ^> database_setup.sql
)
for %%F in (00_limpar_e_padronizar.sql 03_corrigir_view.sql 04_repopular_animais_completos.sql ^
            adicionar_controle_16s.sql) do (
    if exist "%PROJ%sql\%%F" (
        move /Y "%PROJ%sql\%%F" "%OBS%\sql_aplicados\" > nul
        echo   ^> sql\%%F
    )
)

REM ============================================================
REM [3/6] Mover PHP legados sem referencia ativa
REM ============================================================
echo [3/6] Movendo PHP legados para obsoletos\php_legados\ ...
for %%F in (api.php api_infeccao.php diagnostico_view.php ^
            manutencao_all.php api_teste_fluxo_clima.php dados_simulados.php ^
            limpar_dados_artificiais.php padronizar_colunas_animais.php ^
            debug_correlacao.php verificar_dados.php test_api_animal.php ^
            testar_tabelas.php migrar_amplificacao_16s.php ^
            gerar_sql_dados_climaticos.php corrigir_views.php ^
            pre_carregar_dados_climaticos.php install_database.php limpar_cache.php) do (
    if exist "%PROJ%%%F" (
        move /Y "%PROJ%%%F" "%OBS%\php_legados\" > nul
        echo   ^> %%F
    )
)

REM ============================================================
REM [4/6] Mover binarios grandes / instaladores
REM ============================================================
echo [4/6] Movendo binarios para obsoletos\binarios\ ...
for %%F in (WinSCP-6.3.5-Setup.exe diagnostico_tpb_backup.rar) do (
    if exist "%PROJ%%%F" (
        move /Y "%PROJ%%%F" "%OBS%\binarios\" > nul
        echo   ^> %%F
    )
)
for %%E in (rar zip 7z msi exe) do (
    for %%F in ("%PROJ%*.%%E") do (
        if exist "%%F" (
            move /Y "%%F" "%OBS%\binarios\" > nul
            echo   ^> %%~nxF
        )
    )
)

REM ============================================================
REM [5/6] Mover lock files do Word/Office
REM ============================================================
echo [5/6] Movendo arquivos temporarios (lock files Office) ...
for %%F in ("%PROJ%~$*.txt" "%PROJ%~$*.docx" "%PROJ%~$*.xlsx") do (
    if exist "%%F" (
        move /Y "%%F" "%OBS%\tmp_lock\" > nul
        echo   ^> %%~nxF
    )
)

REM ============================================================
REM [6/6] Centralizar logs em logs/
REM ============================================================
echo [6/6] Centralizando logs em logs\ ...
for %%F in (sync_apply_log.txt sync_log.txt winscp_up.log) do (
    if exist "%PROJ%backups_sql\%%F" (
        move /Y "%PROJ%backups_sql\%%F" "%LOGS%\" > nul
        echo   ^> backups_sql\%%F
    )
)
REM Move qualquer .log da raiz para logs/
for %%F in ("%PROJ%*.log") do (
    if exist "%%F" (
        move /Y "%%F" "%LOGS%\" > nul
        echo   ^> %%~nxF
    )
)

REM Limpa caches Python
if exist "%PROJ%analise_python\__pycache__"     rmdir /S /Q "%PROJ%analise_python\__pycache__"
if exist "%PROJ%analise_python\src\__pycache__" rmdir /S /Q "%PROJ%analise_python\src\__pycache__"
if exist "%PROJ%analise_python\.venv"           rmdir /S /Q "%PROJ%analise_python\.venv"

REM ============================================================
REM Relatorio final
REM ============================================================
echo.
echo ============================================================
echo  ORGANIZACAO CONCLUIDA
echo ============================================================
echo.
echo Conteudo da raiz (PHP):
dir /B "%PROJ%*.php" 2>nul | find /C ".php"
echo arquivo^(s^) PHP na raiz.
echo.
echo Conteudo de obsoletos\:
for /D %%D in ("%OBS%\*") do (
    set CNT=0
    for %%F in ("%%D\*") do set /a CNT+=1
    echo   %%~nxD\: !CNT! arquivo^(s^)
)
echo.
echo Conteudo de logs\:
dir /B "%LOGS%\*" 2>nul | find /C /V ""
echo arquivo^(s^) em logs\.
echo.
if %QUIET%==0 pause
exit /b 0
