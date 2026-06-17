# 🗄️ Migrações SQL

> ⚠️ **Atenção (segurança):** este documento usa apenas **placeholders** (`SUA_SENHA_LOCAL`).
> Substitua pelo valor real ao executar manualmente. Nunca commite senhas em texto plano.


Como aplicar as migrações cientificas no banco de dados.

## TL;DR (uma linha)

No PowerShell, dentro da pasta do projeto:

```
aplicar_migracoes.bat
```

O script faz tudo sozinho.

## Pré-requisitos

1. Docker rodando: `docker compose up -d`
2. Container `tpb_db` saudável: `docker compose ps` mostra `healthy`

## O que o script faz

1. Verifica que o banco está no ar.
2. Aplica `sql/02_qa_db.sql`:
   - Marca animais simulados/sentinelas com `simulacao=1`
   - Adiciona coluna `dados_climaticos.fonte` (NASA_POWER, INMET, etc.)
   - Cria view `v_dados_cientificos` (filtra simulados automaticamente)
   - Cria view `v_prevalencia_resumo` (agrega por fazenda/estação)
3. Aplica `sql/05_corrigir_view_dedup.sql` — versão corrigida da view (deduplica clima).
4. Aplica `sql/05_clima_pixel_cache.sql` e `sql/06_clima_semanal_cache.sql` (caches).
5. Imprime relatório de conferência.

## Estrutura atual de `sql/`

| Arquivo | Função |
|---|---|
| `02_qa_db.sql` | Views científicas e flag de simulação (recriação idempotente) |
| `05_clima_pixel_cache.sql` | Tabela de cache climático em grade fina |
| `05_corrigir_view_dedup.sql` | Versão deduplicada da view `v_dados_cientificos` |
| `06_clima_semanal_cache.sql` | Cache de média semanal pré-coleta |
| `99_verificacao.sql` | Query de sanity-check (UNION de 6 SELECTs) |

> Migrações one-time (apaga Fazenda Sorriso, repopula animais_completos)
> foram arquivadas após aplicação. Caso precise re-rodar, consulte o histórico no Git.

## Solução de problemas

### "container tpb_db não está rodando"
```
docker compose up -d
```
Aguarde ~10s e rode novamente.

### "Access denied"
A senha do MariaDB local está errada. Recriar volume:
```
docker compose down -v
docker compose up -d
```

### Aplicar manualmente

```
docker compose exec -T db mariadb -u tpb_dev -pSUA_SENHA_LOCAL u112348440_ifrj_crj_lgm < sql\02_qa_db.sql
```

### Verificar

```
docker compose exec db mariadb -u tpb_dev -pSUA_SENHA_LOCAL u112348440_ifrj_crj_lgm -e \
  "SELECT COUNT(*) AS girolando FROM animais WHERE raca='Girolando'; SELECT COUNT(*) AS na_view FROM v_dados_cientificos;"
```

## Rollback

As migrações são **idempotentes** mas não reversíveis. Para reverter, recarregue o dump:

```
docker compose down -v
docker compose up -d --build
```
