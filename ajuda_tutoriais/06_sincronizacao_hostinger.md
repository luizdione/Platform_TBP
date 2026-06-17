# ☁️ Sincronização com Hostinger

Pipeline para baixar dumps de produção e subir alterações de código, sem risco de
sobrescrever a versão "boa" do banco local nem subir credenciais para o servidor.

## Política de segurança

- **Download nunca sobrescreve** — cada chamada cria `u112_AAAA-MM-DD_HH-mm.sql`
  em `backups_sql/`.
- **O symlink `u112348440_ifrj_crj_lgm.sql`** aponta para o backup ativo (default: o último).
- **Upload exclui automaticamente** arquivos sensíveis e locais:
  `config_hostinger.php`, `config_local.php`, `_stubs/`, `docker/`,
  `analise_python/`, `*.bat`, `*.yml`, `backups_sql/`.
- **Logs** em `backups_sql/sync_log.txt`.

## Configurar credenciais (uma vez)

> ⚠️ **Atenção (segurança):** este tutorial usa apenas **placeholders**.
> NUNCA cole senhas reais aqui ou em qualquer documento Markdown.
> As senhas ficam **somente** em `sync_hostinger.env`, que **NÃO sobe** para
> o GitHub (`.gitignore`) nem para o Hostinger (`.htaccess` bloqueia `.env`).

Crie `sync_hostinger.env` na raiz do projeto a partir do template
`sync_hostinger.env.example`. A estrutura é a seguinte (substitua os valores
`<...>` pelas credenciais reais obtidas no hPanel):

```ini
HOSTINGER_SFTP_HOST=<host-ssh>
HOSTINGER_SFTP_PORT=<porta>
HOSTINGER_SFTP_USER=<usuario-ssh>
HOSTINGER_SFTP_PASS=<senha-ssh>
HOSTINGER_SFTP_PATH=<caminho-no-servidor>

HOSTINGER_DB_HOST=<host-mysql>
HOSTINGER_DB_NAME=<nome-banco>
HOSTINGER_DB_USER=<usuario-mysql>
HOSTINGER_DB_PASS=<senha-mysql>
```

**Onde encontrar:**

- Credenciais SSH: hPanel → Avançado → SSH Access.
- Credenciais MySQL: hPanel → Bancos.

**Boas práticas:**

- Use uma senha forte (mínimo 16 caracteres) e única por ambiente.
- Troque a senha caso alguém tenha acessado o arquivo `.env` indevidamente.
- Considere usar **chave SSH** em vez de senha (mais seguro).

Instale também **WinSCP**: https://winscp.net/

## Uso diário

```
sincronizar_hostinger.bat download                                       REM novo dump SQL com timestamp
sincronizar_hostinger.bat upload                                         REM sobe PHP/SQL atualizados
sincronizar_hostinger.bat use-backup u112_2026-05-08_14-32.sql           REM ativa backup antigo
sincronizar_hostinger.bat list                                           REM lista backups
```

## Recuperação de erro humano

Cenário: alguém em produção lançou dado ruim. Você tem backup de ontem.

```
sincronizar_hostinger.bat list
sincronizar_hostinger.bat use-backup u112_2026-05-22_07-00.sql
docker compose down -v
docker compose up -d --build
```

Banco LOCAL volta ao estado de ontem. Restauração do Hostinger é **manual** pelo
phpMyAdmin de lá — o script nunca apaga dados de produção.
