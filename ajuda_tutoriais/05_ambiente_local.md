# 🐳 Ambiente de desenvolvimento local (Docker)

> ⚠️ **Atenção (segurança):** este tutorial usa apenas **placeholders** (`SUA_SENHA_LOCAL`, `<host>`, etc.).
> As credenciais reais ficam **somente** em `config_local.php` e `docker-compose.yml`,
> ambos no `.gitignore` e bloqueados pelo `.htaccess`. **Nunca** cole senhas reais
> aqui ou em qualquer documento.


Rodar uma cópia da plataforma no seu PC, em paralelo ao Hostinger, sem afetar produção.

## Topologia

```
HOSTINGER (produção)              PC LOCAL (desenvolvimento + análises)
────────────────────              ─────────────────────────────────────
auth-db887.hstgr.io               Docker Desktop:
  • PHP 8.x                         • web    (PHP 8.2 + Apache)  → http://localhost:8085
  • MariaDB                         • db     (MariaDB 11.4)      → localhost:3307
                                    • pma    (phpMyAdmin)        → http://localhost:8081
                                  Python (analise_python/) ← lê v_dados_cientificos
```

O mesmo código PHP roda nos dois lados — `config.php` detecta o ambiente.

## Pré-requisito

Instalar **Docker Desktop** para Windows: https://www.docker.com/products/docker-desktop/

## Subir o ambiente (primeira vez)

No PowerShell, dentro da pasta `diagnostico_tpb`:

```
docker compose up -d --build
```

Aguarde 3–5 minutos (build + import do dump). Confirme:

- http://localhost:8085/dashboard.php
- http://localhost:8085/manutencao.php
- http://localhost:8081/ (phpMyAdmin — login `tpb_dev` / senha `SUA_SENHA_LOCAL`)

## Aplicar migrações no banco local

```
aplicar_migracoes.bat
```

O script aplica `sql/00_limpar_e_padronizar.sql` e `sql/02_qa_db.sql`, idempotentes.

## Atualizar com dump fresco do Hostinger

```
sincronizar_hostinger.bat download    REM Baixa novo dump com timestamp
docker compose down -v
docker compose up -d --build
aplicar_migracoes.bat
```

## Comandos úteis

```
docker compose ps                                                    # ver o que está no ar
docker compose logs -f web                                           # logs do Apache/PHP
docker compose exec web bash                                         # shell dentro do PHP
docker compose exec db mariadb -u tpb_dev -pSUA_SENHA_LOCAL u112348440_ifrj_crj_lgm
docker compose down                                                  # parar (mantém dados)
docker compose down -v                                               # parar e zerar o banco
docker compose restart web                                           # reiniciar só o PHP
```

## Pipeline Python

Separado do Docker, roda no PC:

```
conda activate datascience
conda env update -n datascience -f analise_python\environment.yml   # 1ª vez
copy analise_python\.env.example analise_python\.env                # 1ª vez

cd analise_python
python src\01_qa.py          # gera codebook.xlsx, flags_qa.xlsx, relatorio_qa.pdf
```

Saídas em `analise_python\outputs\`.

## Banner DEV

Em ambiente local aparece um banner amarelo **"AMBIENTE DEV LOCAL"** no topo das
páginas, garantia visual de que você não está em produção. Em produção (Hostinger),
o banner não aparece.

## Não subir para o Hostinger

Os seguintes nunca devem ir para produção:

- `docker-compose.yml`, `docker/Dockerfile`
- `config_local.php`
- `analise_python/`
- `*.bat`, `*.yml`, `_stubs/`

O `.htaccess` já bloqueia esses caminhos com `RedirectMatch 403` por segurança.
