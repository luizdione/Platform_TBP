@echo off
REM ============================================================
REM limpar_venv.bat - remove .venv duplicados do projeto
REM ============================================================
echo Removendo .venv internos do projeto...
echo.

set PROJ=C:\Users\luizd\OneDrive\Data_Science\Projetos\HTML_LGM\public_html\aplicativos\diagnostico_tpb

if exist "%PROJ%\analise_python\.venv" (
    echo Removendo analise_python\.venv ...
    rmdir /S /Q "%PROJ%\analise_python\.venv"
)
if exist "%PROJ%\logs\.venv" (
    echo Removendo logs\.venv ...
    rmdir /S /Q "%PROJ%\logs\.venv"
)
if exist "%PROJ%\analise_python\__pycache__" (
    rmdir /S /Q "%PROJ%\analise_python\__pycache__"
)
if exist "%PROJ%\analise_python\src\__pycache__" (
    rmdir /S /Q "%PROJ%\analise_python\src\__pycache__"
)

echo.
echo Concluido. Use o ambiente conda 'datascience' do miniforge3:
echo   conda activate datascience
echo   conda env update -f analise_python\environment.yml
echo.
pause
