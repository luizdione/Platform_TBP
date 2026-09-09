@echo off
setlocal EnableDelayedExpansion
chcp 65001 > nul

REM ============================================================
REM aplicar_migracoes.bat
REM Aplica todas migracoes SQL no banco local (Docker MariaDB)
REM ============================================================

echo.
echo ============================================================
echo  APLICANDO MIGRACOES SQL NO BANCO LOCAL (DEV)
echo ============================================================
echo.

docker compose ps --status running 2>nul | findstr tpb_db >nul
if errorlevel 1 (
    echo ERRO: container tpb_db nao esta rodando.
    echo    docker compose up -d
    pause
    exit /b 1
)

echo [1/6] Aguardando MariaDB ficar pronto...
docker compose exec -T db sh -c "until mariadb -u tpb_dev -ptpb_dev_pwd -e 'SELECT 1' u112348440_ifrj_crj_lgm > /dev/null 2>&1; do sleep 1; done; echo OK"

echo.
echo [2/6] sql\00_limpar_e_padronizar.sql ...
docker compose exec -T db mariadb -u tpb_dev -ptpb_dev_pwd u112348440_ifrj_crj_lgm < sql\00_limpar_e_padronizar.sql
if errorlevel 1 ( pause & exit /b 3 )

echo.
echo [3/6] sql\02_qa_db.sql ...
docker compose exec -T db mariadb -u tpb_dev -ptpb_dev_pwd u112348440_ifrj_crj_lgm < sql\02_qa_db.sql
if errorlevel 1 ( pause & exit /b 4 )

echo.
echo [4/6] sql\04_repopular_animais_completos.sql (reconstroi a tabela)...
docker compose exec -T db mariadb -u tpb_dev -ptpb_dev_pwd u112348440_ifrj_crj_lgm < sql\04_repopular_animais_completos.sql
if errorlevel 1 ( pause & exit /b 5 )

echo.
echo [5/6] sql\03_corrigir_view.sql (recria view com COALESCE)...
docker compose exec -T db mariadb -u tpb_dev -ptpb_dev_pwd u112348440_ifrj_crj_lgm < sql\03_corrigir_view.sql
if errorlevel 1 ( pause & exit /b 6 )

echo.
echo [5b/6] sql\05_clima_pixel_cache.sql (cache pixels NASA)...
docker compose exec -T db mariadb -u tpb_dev -ptpb_dev_pwd u112348440_ifrj_crj_lgm < sql\05_clima_pixel_cache.sql
if errorlevel 1 ( pause & exit /b 7 )

echo [5c/6] sql\06_clima_semanal_cache.sql (cache semanal)...
docker compose exec -T db mariadb -u tpb_dev -ptpb_dev_pwd u112348440_ifrj_crj_lgm < sql\06_clima_semanal_cache.sql
if errorlevel 1 ( pause & exit /b 8 )

echo [6/6] Verificacao final:
docker compose exec -T db mariadb -u tpb_dev -ptpb_dev_pwd u112348440_ifrj_crj_lgm < sql\99_verificacao.sql

echo.
echo ============================================================
echo  CONCLUIDO. Recarregue:
echo    http://localhost:8085/relatorio_cientifico.php
echo  Se vazio, abra:
echo    http://localhost:8085/diagnostico_view.php
echo ============================================================
pause
