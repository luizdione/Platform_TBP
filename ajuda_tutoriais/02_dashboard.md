# 📈 Como usar o Dashboard

O **dashboard.php** é a tela principal de análise. Reúne em uma única página todas as
visualizações epidemiológicas e climáticas do estudo.

## Seções

1. **Resumo executivo** — totais de animais, coletas, resultados PCR/LAMP.
2. **Mapa interativo (Leaflet)** — locais georreferenciados com camadas climáticas.
3. **Prevalência por espécie/patógeno** — gráficos de barras com IC95%.
4. **Sazonalidade** — distribuição de positivos por estação do ano.
5. **Correlação climática** — coeficiente de Spearman entre prevalência e
   variáveis climáticas (T2M, PRECTOTCORR, RH2M).
6. **Comparação PCR vs LAMP** — concordância (kappa de Cohen) por alvo molecular.

## Filtros disponíveis

- **Local** — uma ou mais fazendas.
- **Período** — datas de coleta.
- **Patógeno** — filtra resultados por espécie alvo.
- **Cientifico=1** (URL) — exclui automaticamente registros com `simulacao=1` e
  sentinelas `CLIMA*`. Use para análises de publicação.

## Boas práticas

- Verifique sempre o filtro **"somente reais"** antes de extrair gráficos.
- Use o **Relatório Científico** (`relatorio_cientifico.php`) para tabelas
  prontas para publicação — ele já filtra simulações automaticamente.
- Exportações estão em **Manutenção → Backup**.

## Camadas climáticas no mapa

- **Pixel cache** (`clima_pixel_cache`) — grade fina (0.5° NASA POWER) para colorir o mapa.
- **Semanal cache** (`clima_semanal_cache`) — média de 7 dias antes da coleta por local.

Quando faltam dados climáticos para uma coleta, use **Manutenção → Clima → Carga NASA POWER**
para preencher (média de 90 dias antes da coleta).
