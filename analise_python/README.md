# Pipeline Cientifico — Diagnostico TPB

Camada Python do projeto. Le o banco MariaDB (local Docker ou Hostinger via SSH),
gera figuras 300 dpi, tabelas XLSX/LaTeX e relatorios PDF para publicacao.

## Setup (ambiente conda existente)

Voce ja tem o conda env **datascience** no miniforge3. Use ele:

```
conda activate datascience
conda env update -n datascience -f environment.yml      # 1a vez ou apos mudancas
copy .env.example .env                                   # 1a vez (ajustar credenciais SSH)
```

NAO criar `.venv` dentro deste projeto. O ambiente conda eh global no seu PC.

## Modos de execucao

### 1. OFFLINE (sem Docker, sem Hostinger)
Le direto o dump `u112348440_ifrj_crj_lgm.sql` via SQLite em memoria.

```
conda activate datascience
python src/offline_runner.py
```

### 2. LOCAL (Docker rodando)
Le do MariaDB local na porta 3307. Antes garanta `docker compose up -d`.

```
conda activate datascience
python src/01_qa.py
```

### 3. SSH (producao Hostinger)
Edite `.env` com credenciais e `TPB_DB_MODE=ssh`. ATENCAO: read-only.

## Scripts

| Arquivo                | Funcao                                            |
|------------------------|---------------------------------------------------|
| `src/db.py`            | Conexao SQLAlchemy (local/ssh)                    |
| `src/offline_runner.py`| Roda QA contra dump SQL via SQLite                |
| `src/01_qa.py`         | Fase 1: flag simulados, kappa Cohen, codebook     |
| `src/02_descritiva.py` | (Fase 2) Tabela 1 amostra (a fazer)               |
| `src/03_prevalencia.py`| (Fase 2) Prevalencia + IC95% Wilson (a fazer)     |
| `src/04_logit.py`      | (Fase 2) Regressao logistica + OR (a fazer)       |
| `src/05_figuras.py`    | (Fase 3) 5 figuras publication-ready (a fazer)    |

## Saidas

Tudo em `outputs/`:
- `codebook.xlsx` — dicionario de variaveis
- `flags_qa.xlsx` — animais simulados, divergencias PCR vs LAMP, outliers clima
- `relatorio_qa.pdf` — sumario executivo do QA
- `log_qa.txt` — log da execucao

## Limpeza de .venv duplicados

Se aparecerem `.venv` no projeto (gerados por engano), rode no Windows
o `limpar_venv.bat` na raiz do projeto.
