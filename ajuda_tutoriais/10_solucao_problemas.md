# 🆘 Solução de problemas comuns

## A plataforma local não abre (http://localhost:8085)

1. Docker Desktop está rodando? (ícone de baleia na bandeja).
2. Subir contêineres:
   ```
   docker compose up -d
   ```
3. Verificar status:
   ```
   docker compose ps
   ```
   Os dois (`web` e `db`) devem estar "running" e `db` deve estar `healthy`.

## Banner amarelo "DEV" não aparece

- Confirme `TPB_AMBIENTE=desenvolvimento` no `docker-compose.yml`.
- Reinicie:
  ```
  docker compose down
  docker compose up -d --build
  ```

## Erro "auth_central.php not found"

- Verifique se a pasta `_stubs/templates/` existe.
- Verifique se o `docker-compose.yml` monta os três caminhos:
  ```yaml
  - ./_stubs/templates:/templates:ro
  - ./_stubs/templates:/var/templates:ro
  - ./_stubs/templates:/var/www/templates:ro
  ```

## Senha do manutenção não funciona (DEV)

A senha de operações DEV avançadas fica armazenada apenas como **hash**
em `config_local.php → getSenhaManutencaoDev()` (NUNCA é exibida).
Caso tenha esquecido a senha, gere um novo hash localmente:
```
php -r "echo password_hash('NOVA_SENHA', PASSWORD_DEFAULT);"
```
e substitua o valor da função. Não compartilhe a senha em texto plano —
nem em commits, nem em documentos.

## "shell_exec retorna vazio" no manutenção

O PHP pode estar com `disable_functions=shell_exec`. Verifique:
```
docker compose exec web cat /usr/local/etc/php/php.ini | grep disable
```
Edite e reinicie: `docker compose restart web`.

## .bat não executa ("'M' não é reconhecido")

Causa: line endings LF em vez de CRLF. Abra no Notepad++ e use
**Edit → EOL Conversion → Windows (CR LF)**.

## Resultados PCR aparecem zerados

A tabela `resultados_pcr` está vazia até o término do procedimento de qPCR.
Os resultados LAMP já populam `resultados_lamp` por outro fluxo.

## Mapa do dashboard sem cores climáticas

- Verifique se os caches estão populados:
  - `SELECT COUNT(*) FROM clima_pixel_cache;`
  - `SELECT COUNT(*) FROM clima_semanal_cache;`
- Rode a carga em **Manutenção → Clima → Carga NASA POWER**.

## "Erro HTTP 500" em qualquer página

Veja os logs:
```
docker compose logs --tail 50 web
```
Erros de PHP aparecem ali. Os logs persistidos do projeto ficam em `logs/`.

## Como restaurar um backup antigo

```
sincronizar_hostinger.bat list
sincronizar_hostinger.bat use-backup u112_2026-05-08_14-32.sql
docker compose down -v
docker compose up -d --build
```

## Onde pedir ajuda

- Veja os logs em `logs/` para erros recentes.
- Consulte o **README.md** no GitHub: https://github.com/luizdione/app_tpb
- E-mail do mantenedor: luiz.dione.melo@gmail.com
