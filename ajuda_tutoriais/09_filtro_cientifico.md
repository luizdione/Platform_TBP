# 🔬 Filtro científico — separando dados reais de simulações

O banco contém dados reais e fictícios (demonstração, sentinelas climáticas).
Toda análise para publicação deve **excluir os dados simulados**.

## Mecanismos disponíveis

### 1. View `v_dados_cientificos` (recomendado)

```sql
SELECT * FROM v_dados_cientificos WHERE local_nome = 'IFRJ Pinheiral';
```

A view já:
- Exclui registros com `animais.simulacao = 1`
- Exclui códigos `CLIMA*` e `CLIMA_REF*` (sentinelas climáticas)
- Inclui estação calculada, idade em meses na coleta, JOIN com clima

É a forma **mais segura** — impossível "esquecer" o filtro.

### 2. Helper PHP `filtroCientifico()`

Em `config.php`:

```php
$cien = filtroCientifico();
$query = "SELECT ... FROM animais_completos ac
          WHERE 1=1 {$cien['sql_where']}";
```

Quando a URL traz `?cientifico=1`, o helper retorna fragmento SQL que exclui
simulados. Caso contrário, retorna string vazia.

### 3. View agregada `v_prevalencia_resumo`

Já agrega por fazenda × estação × patógeno × método (PCR/LAMP), pronto para tabelas.

## Quando usar cada um

- **Tabela 1, prevalência, regressão** → use `v_dados_cientificos` ou `v_prevalencia_resumo`.
- **API legada que consulta `animais_completos` direto** → adicione `?cientifico=1`.
- **Dashboard interativo (público)** → mostra todos por padrão; checkbox alterna.

## Por que isso existe

O dump do Hostinger contém:
- **55 animais reais** (Pinheiral, Pesagro, Quintas Barbosa)
- **40 animais simulados** (Fazenda Sorriso, removidos do banco local mas
  podem voltar se um dump fresco for importado)
- **2 sentinelas `CLIMA-MANUAL-*`** para anexar dados climáticos sem coleta real

Os dois últimos grupos são úteis para o sistema operacional, mas inviabilizam
análises estatísticas se misturados aos dados reais. O filtro garante separação.
