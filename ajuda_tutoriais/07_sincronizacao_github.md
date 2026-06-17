# 🐙 Sincronização com GitHub

Versionamento do código no repositório público
[**luizdione/app_tpb**](https://github.com/luizdione/app_tpb).

## O que sobe

- PHP da raiz **sanitizados** (sem credenciais)
- `.htaccess` (versão pública)
- Documentação `ajuda_tutoriais/*.md`
- `sql/` (estrutura, sem dados)
- `analise_python/` (sem `outputs/` com dados reais)
- `static/` (CSS, JS, imagens públicas)
- `README.md`, `LICENSE`
- `.gitignore`

## O que NUNCA sobe

- `config.php`, `config_hostinger.php`, `config_local.php` (credenciais)
- `.htaccess` (versão de produção)
- `Dockerfile`, `docker-compose.yml`, pasta `docker/`
- `sincronizar_hostinger.bat`, `sync_endpoint.php`, `sync_hostinger_apply.php`
- `sync_hostinger.env`, `sync_hostinger.env.example`, `SYNC_HOSTINGER.md`
- `*.sql` (dumps, migrations não-públicas)
- `backups_sql/` (dumps com dados reais)
- `obsoletos/`, `_stubs/` (artefatos locais)
- `logs/`, `.idea/`
- Qualquer `*.env`, `*.log`

A proteção é garantida em **três camadas**:
1. `.gitignore` no diretório `github/` exclui esses arquivos do repo.
2. `sincronizar_github.bat` copia explicitamente só o que pode subir.
3. Validação pós-cópia procura strings de credenciais antes do push.

## Pré-requisitos

1. Instalar **Git for Windows**: https://git-scm.com/download/win
2. Configurar `user.name` e `user.email`:
   ```
   git config --global user.name "Luiz Dione"
   git config --global user.email "luiz.dione.melo@gmail.com"
   ```
3. Personal Access Token do GitHub (PAT):
   - GitHub → Settings → Developer settings → Personal access tokens →
     Tokens (classic) → Generate new → permissão `repo`
   - Cole no prompt do git quando pedir senha (uma vez; é cacheado).

## Uso diário

```
sincronizar_github.bat "feat: nova aba de ajuda em manutencao"
```

O script:
1. Sincroniza a pasta `github/` com a versão pública sanitizada do projeto.
2. Valida que nenhum arquivo proibido foi incluído.
3. Faz `git add . && git commit -m "MENSAGEM" && git push origin main`.

## Primeira execução (configuração inicial)

Se o repositório está vazio, rode uma vez:

```
cd github
git init -b main
git remote add origin https://github.com/luizdione/app_tpb.git
git add .
git commit -m "initial commit"
git push -u origin main
```

A partir daí, basta `sincronizar_github.bat "mensagem"`.

## Verificação manual

Antes de aceitar um push grande, abra:

- https://github.com/luizdione/app_tpb/tree/main
- Confira que `config.php`, `*.sql`, `Dockerfile` não estão lá.
