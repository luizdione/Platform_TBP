# 🧠 Memória do Projeto — Diagnóstico TPB (Tristeza Parasitária Bovina)

> Documento de memória para o agente **professor_tpb** orquestrar análises de dados e
> melhorias gráficas/estatísticas no futuro. Mantenha este arquivo atualizado a cada
> mudança relevante (nova tabela, novo gráfico, novo achado de dado, novo commit).
> **Última atualização:** 2026-07-07.

> **Estado atual (2026-07-07):** `relatorio_cientifico.php` + `processa_formulario.php` (commits
> até `47f075f`) **publicados na Hostinger** pelo usuário. **Fazenda IFRJ Pinheiral (local 6)
> resolvida:** coletas registradas via `forms.php` (data 2026-02-23, estação Verão) + carga
> climática manual rodada → passa a constar em `v_dados_cientificos`.
>
> **Pendentes de upload na Hostinger (não publicados):**
> 1. `relatorio_cientifico.php` — remoção do portão de senha (commit `465b72a`).
> 2. `forms.php` + `api_clima.php` + `processa_formulario.php` — **auto-cálculo climático** na
>    aba "Dados Climáticos" (NASA POWER 90 dias + estação predominante). Ver §7 último commit.

---

## 1. Visão geral

- **Tema:** vigilância epidemiológica de **Tristeza Parasitária Bovina (TPB)** — hemoparasitas em bovinos:
  - **Babesia bovis** (vetor: carrapato *Rhipicephalus (Boophilus) microplus*)
  - **Anaplasma marginale** (mesmo vetor + mecânico)
  - **Trypanosoma vivax** (transmissão mecânica)
- **Diagnóstico molecular:** PCR convencional + **PCR-LAMP** (alvos *B. bovis*: 18S rRNA e CytB mitocondrial).
- **Clima:** NASA POWER (T2M, PRECTOTCORR, RH2M) — médias de **90 dias antes da coleta**.
- **Desenho do estudo:** **4 coletas por ano (1 por estação) × 3 localidades**, **longitudinal** (mesmos animais reamostrados). ⇒ Muitos dados acumulando ao longo dos anos.
- **Instituição:** IFRJ — LGM (Laboratório de Genética Molecular), Campus Pinheiral / parcerias.
- **Site público:** `https://www.ifrj-crj-geneticamolecular.online/aplicativos/diagnostico_tpb/`
  - Página científica: `relatorio_cientifico.php` (**acesso livre, sem senha** desde 2026-06-26 — ver §12).

## 2. Repositórios e branches

| Repo | Branch de trabalho | Conteúdo |
|---|---|---|
| `luizdione/app_tpb` | `claude/wizardly-shannon-jvsat7` | App web PHP completo (o que importa para análises) |
| `luizdione/diagnostic_bovine_parasitic_sadness` | `claude/wizardly-shannon-jvsat7` | Praticamente vazio (LICENSE) |

- **Cópia local do usuário (Windows):** `C:\Users\luizd\OneDrive\Data_Science\Projetos\HTML_LGM\public_html\aplicativos\diagnostico_tpb\`
- Fluxo: editar no repo → push GitHub → o usuário faz `git pull` (atualiza OneDrive) → deploy Hostinger.

## 3. Banco de dados (MySQL — Hostinger)

- **DB:** `u112348440_ifrj_crj_lgm` (host phpMyAdmin: `auth-db887.hstgr.io`).
- **Tabelas/views principais:**
  - `animais` — cadastro base do animal (`id`, `local_id`, `identificacao`, sexo, raça, idade…). **NÃO tem `codigo_animal` nem `data_coleta`.**
  - `coletas` — **eventos de coleta**, keyed por `animal_id` (`id`, `animal_id`, `local_id` [às vezes NULL!], `data_coleta`, `estacao`, `tipo_amostra`…). **É a base do pipeline científico.**
  - `dados_climaticos` — clima por coleta. Keyed por `coleta_id` + `local_id`. **Colunas reais:** `temperatura_media/minima/maxima`, `pluviometria_total`, `pluviometria_media`, `umidade_media/minima/maxima`, `pressao_atmosferica`, `velocidade_vento`, `data_coleta`, `data_inicio_periodo`, `data_fim_periodo`, `fonte`/`fonte_dados`, `status`. ⚠️ **Não existe `pluviometria_mm`, `umidade_relativa` nem `data_registro`** (nomes antigos que causaram bug — ver §7).
  - `animais_completos` — **consolidado** (provável view) com `codigo_animal`, `data_coleta`, `local_id`, `sexo`, `em_lactacao`, `idade_meses_na_coleta`, `pcr_babesia/anaplasma/trypanosoma`, `lamp_babesia_18s/cytb`, `simulacao`. Fonte de *fallback* do relatório.
  - `v_dados_cientificos` — **view científica oficial** usada pelo relatório. Já: exclui `simulacao=1`, exclui códigos `CLIMA*`/`CLIMA_REF*`, calcula `estacao`/idade, faz **JOIN com `dados_climaticos`** (pode duplicar linhas). Colunas: `codigo_animal`, `data_coleta`, `fazenda`, `municipio`, `estacao`, `sexo`, `em_lactacao`, `idade_meses_na_coleta`, `temperatura_media`, `pluviometria_total`, `umidade_media`, e os 5 flags PCR/LAMP. **Definição em migration `.sql` NÃO versionada (gitignored) — não está no repo.**
  - `resultados_lamp` — tempos de detecção e intensidade do LAMP (`tempo_deteccao_18s_min`, `intensidade_sinal_18s`, idem cytb).
  - `locais` — `id`, `nome`, `municipio`, `ativo`, `latitude`, `longitude`.
  - `estatisticas_climaticas` — tabela auxiliar do caminho **legado** (pode não existir/estar em desuso).
- **Locais conhecidos:** `5` = Fazenda Pesagro (Seropédica) · `6` = **Fazenda IFRJ Pinheiral** (Pinheiral) · `8` = Fazenda Quintas Barbosa (Japeri) · `20` = Sorriso (simulado/demonstração).
- **Pipeline de dados (CRÍTICO entender):**
  ```
  formulário → animais + coletas → carga clima NASA (api_clima_pendentes.php, lê FROM coletas)
            → dados_climaticos → v_dados_cientificos → relatorio_cientifico.php
  ```
  Uma fazenda só aparece nas análises se tiver linhas em **`coletas`** (e, dependendo do JOIN da view, também em `dados_climaticos`).

## 4. Arquivos-chave do app

| Arquivo | Papel |
|---|---|
| `relatorio_cientifico.php` | **Relatório científico** (tabelas + ~14 gráficos Chart.js/canvas + biblioteca estatística). Foco das melhorias. |
| `dashboard.php` | Dashboard interativo público (mapas Leaflet, correlações). |
| `forms.php` | Entrada de dados (abas: Locais, **Coletas/Amostras**, PCR-LAMP, Clima). |
| `processa_formulario.php` | Processa submissões do `forms.php` (INSERT animais/coletas/resultados; import clima legado). |
| `manutencao.php` | Manutenção, QA, carga de clima, sync Hostinger. |
| `api_clima_pendentes.php` | **Carga de clima oficial (NASA POWER)** — lê coletas sem clima e busca. ✅ funciona. |
| `api_clima*.php` | APIs de clima diversas (grid, semanal, mapa). |
| `api_correlacao.php` | Correlação clima×diagnóstico (usa `animais_completos`, `?cientifico=1`). |
| `ajuda_tutoriais/09_filtro_cientifico.md` | Doc do filtro científico (simulacao/CLIMA, view). |
| `AUDITORIA_MELHORIAS_TPB.md` | **Roadmap** de melhorias gráficas/analíticas com bibliografia (ver §8). |
| `static/js/chart.umd.min.js` | Chart.js v4. `static/js/leaflet.js` + `static/css/leaflet.css`. |

- **Filtro científico:** view `v_dados_cientificos` (recomendado) · helper `filtroCientifico()` + `?cientifico=1` · view agregada `v_prevalencia_resumo`.
- **Config/segredos (gitignored):** `config.php` (getDBConnection, verificarSenhaAjuda, setSecurityHeaders, isDevAmbiente, filtroCientifico), `sync_hostinger.env`, etc.

## 5. Deploy (importante!)

- **GitHub** é o canal confiável: tudo vai para a branch de trabalho.
- **Hostinger** é deploy **manual do usuário**: `sincronizar_hostinger.bat` (WinSCP, sobe `*.php`) **ou** Gerenciador de Arquivos web. Sobe só `.php` (os `.md` ficam só no git).
- ⚠️ **O ambiente remoto do Claude Code NÃO alcança a Hostinger** (proxy retorna **403** para `*.hstgr.io` e para o site público). Portanto: **não dá para fazer deploy nem ler o banco de produção a partir daqui.** Diagnóstico de banco é feito **passando SQL para o usuário rodar no phpMyAdmin**.
- **Não tocar no banco de produção** sem necessidade; preferir correções no app + SQL revisado pelo usuário.

## 6. Biblioteca estatística (PHP puro) — em `relatorio_cientifico.php`

Validada com **45 testes** contra valores de referência (ver §9). Funções disponíveis:
- Distribuições: `normCdf`, `normSf`, `chiSqSf`, `studentTwoSided`, `betai`, `gammp`/`gammq` (Numerical Recipes).
- Testes: `wilson95` (IC proporção), `spearmanInfer` (ρ → p + IC95% Fisher z), `chiSquareTest` (independência r×c), `mcnemarTest` (pareado: exato <25 / Yates ≥25, **travado em zero p/ |b−c|≤1**), `kappaCI` (κ de Cohen + IC95%), AUC Hanley–McNeil.
- Modelagem: `logisticFit` (regressão logística IRLS multivariável, padronizada → OR por +1 DP, com **guarda de convergência/separação** ⇒ retorna null se diverge), `olsWeighted` (tendência ponderada), `matInverse`.
- Formatação: `niceMax` (teto de eixo agradável), `fmt_p`/`fmt_pclause` (p-valor HTML-safe), `fmt_pct`/`fmt_ci`/`fmt_num`, `notaCorr`.

## 7. Histórico de versionamento (commits desta linha de trabalho)

| Hash | Data | Resumo |
|---|---|---|
| `f2aace3` | 2026-06-17 | Versiona app (gitignore allowlist/denylist; exclui segredos/dumps). |
| `3622bf0` | 2026-06-25 | **Relatório:** estatística inferencial + integração clima→epidemiologia (logística multivariável + forest plot g13 + Spearman por patógeno) + correções gráficas (eixos dinâmicos g1/g2/g4-6, histograma g10a com bins compartilhados, McNemar/κ-CI/χ² sazonal, IC da AUC). |
| `0ab8511` | 2026-06-25 | **Correção (revisão adversarial):** McNemar trava `|b−c|−1` em zero (p=1 quando b=c); regressão logística ganha guarda de separação/convergência (evitava OR=∞ → `json_encode` falho → JS quebrado). |
| `ef2e1d9` | 2026-06-25 | Adiciona `AUDITORIA_MELHORIAS_TPB.md` (bibliografia + roadmap). |
| `4ff79e2` | 2026-06-25 | Gráficos de clima (g4-g6): eixo Y travado em ≤100% com folga (reta de tendência recortada). |
| `47f075f` | 2026-06-26 | Corrige import de clima legado (`pluviometria_mm`→`pluviometria_total`, `umidade_relativa`→`umidade_media`, `data_registro`→`data_coleta`) + try/catch no `processa_formulario.php`. |
| `6538fd9` | 2026-06-26 | **Roadmap item 2:** IC95% do κ no heatmap g12 — nova `$matrizKci` (via `kappaCI()`), 2ª linha `[lo; hi]` por célula + tooltip por hover (hit-test `_g12geo`). Não toca Tabela 10. |
| `549da03` | 2026-06-26 | **Roadmap item 1:** paleta **Okabe-Ito** (CVD-safe) — `$OKABE_ITO`/`$cores` (g1, g2) + `cmapEstado` (g9a, cinza p/ "coleta única"); helper `corBorda()` escurece o amarelo `#F0E442`→`#B59E00` (borda/linha) p/ contraste. |
| `e23e46b` | 2026-06-26 | **Roadmap item 3:** novo **lasagna plot g9c** (linha=animal, coluna=onda estação×ano, cor=status). `$sqlCo` ganha `MAX(estacao)`; `$animais` guarda estacao/ano/fazenda (helper `estacaoDeData()` p/ fallback); render canvas custom `renderLasagna()` + tooltip. ⚠️ `$sqlCo` alimenta g8 e g12 — re-testados, intactos. |
| `12c69b2` | 2026-06-26 | **Ajustes visuais:** largura das barras g7a/g9a/g9b (`maxBarThickness` 56/64/56 + `categoryPercentage` 0.6); **g11 ROC** reformulado (1 dataset por critério, cor Okabe-Ito + `pointStyle` distinto + legenda; removido `rocLabelPlugin`; nota explicativa; canvas 440px); **g4/g5/g6** teto Y abre folga até 115% só quando o pico+IC95% encosta em 100 (whisker superior aparece inteiro), prevalência baixa mantém teto justo, trend recortada. |
| `465b72a` | 2026-07-02 | **Relatório sem senha:** remove o portão `$_SESSION['relatorio_auth']` (acesso livre para colaborador). ⚠️ pendente de upload. |
| `4010bb3` | 2026-07-07 | **Carga em série ganha estação predominante.** `api_clima_pendentes.php`: novas `estacaoDoMesSH()`/`estacaoPredominante()`; detecta em runtime a coluna `dados_climaticos.estacao_predominante` (`SHOW COLUMNS`) e, se existir, grava a estação predominante dos 90 dias no upsert (INSERT/placeholders/UPDATE montados dinamicamente). Sem a coluna, grava o clima normalmente e retorna `aviso_estacao` com o `ALTER` sugerido. **Não** toca `coletas.estacao`. Validado: `php -l` + teste de paridade placeholders×params (15/15 e 16/16). ⚠️ pendente de upload; coluna opcional via `ALTER`. |
| `cff7b31` | 2026-07-07 | **Auto-cálculo climático (aba Dados Climáticos do `forms.php`).** (1) `api_clima.php`: `calcular_variacoes` com fonte **NASA** agora busca de verdade (nova `buscarNASACompleto()` — T2M/MIN/MAX, PRECTOTCORR, RH2M dos 90 dias) em vez de cair em simulado; adiciona `estacaoPredominante()` (contagem dia-a-dia, HS) e devolve `estacao_predominante`+distribuição. (2) `forms.php`: NASA vira fonte padrão; **auto-dispara** o cálculo (debounce) ao ter local+data; badge **"Estação predominante (90 dias)"** client-side instantâneo (`classificarEstacaoPredominante()`); campo precipitação passa a guardar o **total acumulado**; hidden `estacao` no POST; **fix de bug latente** — `getElementById('data_registro')` (inexistente) lançava TypeError na linha ~1353 e **interrompia todo o bloco `<script>`** → agora null-safe. (3) `processa_formulario.php`: estação da coleta fictícia passa a ser a **predominante dos 90 dias** (HS, `estacaoPredominante90Dias()`) em vez do trimestre errado (Jan-Mar=Verão); respeita o `estacao` do POST. Validado: `php -l` nos 3, `node --check` do bloco JS, teste unitário de estação (6/6), render Playwright do badge (85/91 Verão). ⚠️ pendente de upload. |

## 8. Gráficos atuais do relatório (mapa rápido)

- **g1** prevalência por fazenda × parasita (barras + IC95% Wilson, eixo Y dinâmico).
- **g2** prevalência por estação (linha, eixo Y dinâmico) + **χ² sazonal**.
- **g3** rosca: nº animais por fazenda.
- **g4/g5/g6** clima × prevalência (scatter: pontos ∝ n, IC Wilson, **reta de tendência OLS recortada**, ρ+IC+p; eixo Y dinâmico com **folga até 115%** quando o pico+IC encosta em 100 — whisker superior visível; prevalência baixa = teto justo <100). Função JS `corrChart()` (l.~2904).
- **g7a/g7b** comparação PCR vs LAMP (positivos por método; concordância) + **McNemar + κ com IC95%**. g7a com `maxBarThickness:56`.
- **g8** coinfecção (UpSet-like, B. bovis × A. marginale × T. vivax). ⚠️ cores hardcoded próprias (não usa `$cores`; ainda não-Okabe-Ito).
- **g9a/g9b** estados longitudinais (g9a usa `cmapEstado` Okabe-Ito, `maxBarThickness:64`) + distribuição de amostras por animal (`maxBarThickness:56`). Barras estreitadas via `categoryPercentage:0.6`.
- **g9c** **lasagna plot** longitudinal por animal (canvas custom): linha=animal (agrupado por fazenda, ordenado por nº ondas+), coluna=onda (estação×ano), cor=status (negativo/B.bovis/A.marginale/T.vivax/coinfecção/ausente, Okabe-Ito). Tooltip por hover. **Preliminar** — adensa a cada coleta. Dados via `$lasanhaRows`/`$lasanhaCols`.
- **g10a/g10b** tempo de detecção LAMP (histograma **bins compartilhados**) + intensidade.
- **g11** curva ROC dos critérios LAMP (AUC empírica + IC Hanley–McNeil). **1 dataset por critério** (só 18S/só CytB/OR/AND) com cor Okabe-Ito + forma distinta + legenda (sem rótulos inline sobrepostos); diagonal tracejada = acaso; nota explicativa (X=1−espec., Y=sens., canto sup-esq = melhor).
- **g12** heatmap κ 5×5 (canvas custom) — **cada célula mostra κ + IC95% `[lo; hi]`** (`$matrizKci` via `kappaCI()`); tooltip por hover.
- **g13** **forest plot** dos OR do modelo logístico clima→infecção (canvas custom, escala log, linha OR=1).
- **heatmap_temporal** clima semanal por fazenda (canvas custom, NASA, auto-carga).

> **Paleta:** `$OKABE_ITO`/`$cores` (l.~911) = `['#E69F00','#56B4E9','#009E73','#F0E442','#0072B2','#D55E00','#CC79A7']` (CVD-safe). Helper `corBorda()` escurece o amarelo `#F0E442`→`#B59E00` para borda/linha. Aplicada em g1/g2/g9a/g9c. **Pendente:** g8 ainda usa cores próprias.
> **Harness de validação no scratchpad:** `render.sh` (mock PDO→`out.html`, 0 warnings) + `shoot.mjs` (Playwright; ids incluem g7a/g8/g9a/g9b/g9c/g11/g12). Mock `mock_config.php`: o branch do `$sqlCo` (`AS pcr_anaplasma` + `codigo_animal`) devolve `estacao` por linha; dataset = 3 fazendas × 20 animais × 4 estações (≥2 ondas/animal p/ o lasagna). Nota: nesse mock a prevalência é alta (~50-100%), útil para exercitar a folga >100% dos g4-g6.

## 9. Problemas conhecidos, convenções e gotchas

- **Prevalência baixa** (TPB costuma ser <30%): nunca fixar eixo Y em 100 — usar `niceMax`/teto dinâmico.
- **Colinearidade climática:** com poucos timepoints (4 estações), temperatura/pluviometria/umidade têm ranks quase idênticos ⇒ **ρ de Spearman iguais** nos 3 gráficos (não é bug). O **modelo logístico multivariável** é a leitura correta para separar efeitos.
- **EPV baixo no início:** logística é exploratória; pode haver **separação** (especialmente *T. vivax*, raro) ⇒ a função retorna null e a seção mostra "modelo não estimado" (futuro: correção de Firth).
- **Fazenda some das análises:** se a fazenda não tem linhas em **`coletas`** (e/ou sem clima), ela **não aparece** na view. Foi o caso de **Pinheiral** (animais em `animais_completos` mas 0 em `coletas`; resolvido registrando coletas pelo `forms.php`). Diagnóstico padrão: comparar `locais` × `v_dados_cientificos` (GROUP BY fazenda) × `coletas` (via `animal_id`) × `dados_climaticos`.
- **`coletas.local_id` pode ser NULL** — vincular sempre por `animal_id → animais.local_id` (o pipeline usa `COALESCE(a.local_id, c.local_id)`).
- **Duas semânticas de `estacao` (não confundir):** (a) na **aba Coletas/Amostras** a estação é a do **evento** (mês da data de coleta, mapeamento HS de mês único, JS l.~1421). (b) na **aba Dados Climáticos** a estação é a **predominante da janela de 90 dias** (exposição climática) — contada dia-a-dia (`estacaoPredominante`/`estacaoPredominante90Dias`, HS). São conceitos diferentes e propositais. O relatório lê `coletas.estacao`; a coleta fictícia "CLIMA-MANUAL" criada pela aba de clima grava a predominante.
- **Mapeamento sazonal HS canônico:** Verão=12,1,2 · Outono=3,4,5 · Inverno=6,7,8 · Primavera=9,10,11. (O bloco antigo em `processa_formulario.php` usava trimestres — Jan-Mar=Verão — **errado p/ HS**; corrigido.)
- **`api_clima.php?action=calcular_variacoes&fonte=nasa`** agora busca NASA de verdade (antes caía sempre em simulado). Fontes: `nasa` (real), `banco` (usa `dados_climaticos` já gravado), `simulado` (fallback). `inmet` ainda não implementado → simulado.
- **Coluna opcional `dados_climaticos.estacao_predominante`:** guarda a estação predominante da janela de 90 dias (metadado do clima; **não** confundir com `coletas.estacao`, que é a estação do evento). A **carga em série** (`api_clima_pendentes.php`) já grava essa coluna **se ela existir** (detecção via `SHOW COLUMNS` em runtime; sem a coluna, o clima é gravado e a estação é pulada, com aviso no JSON). Para ativar: `ALTER TABLE dados_climaticos ADD COLUMN estacao_predominante VARCHAR(20) NULL;` e reprocessar (a carga usa `ON DUPLICATE KEY UPDATE`, então rodar de novo popula os registros existentes). A carga **não** altera `coletas.estacao`.
- **Datas plurianuais:** já há coletas em **2026** (ex.: Pinheiral 2026-02-23). Qualquer filtro de ano fixo em 2025 na view excluiria 2026 — atenção ao revisar a definição da view.
- **Fragilidade da view:** o `JOIN` com `dados_climaticos` pode apagar fazendas sem clima. Melhoria pendente: trocar por **`LEFT JOIN`** (precisa do texto da view via Exportar do phpMyAdmin). Itens sem clima ficam fora só da *correlação climática*.
- **Sem banco no ambiente Claude:** validar PHP com `php -l`; renderizar com **mock PDO** (`config.php` falso + `FakePDO`) + `php` CLI; conferir gráficos com **Playwright** (Chromium em `/opt/pw-browsers/chromium-1194/...`, usar proxy). Math validada por testes unitários isolados.
- **Segurança:** links de phpMyAdmin / Gerenciador de Arquivos compartilhados são sessões temporárias → recomendar **rotacionar credenciais** após uso. Nunca commitar segredos (gitignore já bloqueia `config*.php`, `*.env`, dumps `*.sql`).
- **Identidade do modelo / co-autoria:** commits terminam com `Co-Authored-By: Claude Opus 4.8` + `Claude-Session`. Não colocar o identificador do modelo em código/PR/commits além disso.

## 10. Roadmap de melhorias (ver `AUDITORIA_MELHORIAS_TPB.md` para detalhes + citações)

**Viáveis agora (com 4 estações × 3 fazendas):** paleta CVD-safe (Okabe-Ito); IC95% do κ **dentro do heatmap** g12; tabela 2×2 do McNemar como achado biológico (portador subpatente); **lasagna plot** longitudinal por animal; **Sankey/alluvial** das transições; diagrama de **nicho climático** (T×UR×prevalência); **déficit de saturação (VPD)** como preditor mecânico; box/violino para tempo LAMP; forest plot de prevalência por teste; spaghetti + small multiples; legendas STROBE-Vet.

**Destravam com mais coletas (vários ciclos):** cross-correlation com **lag** por patógeno; **STL/MSTL** (decomposição sazonal); **GLMM/GEE** (fazenda fixa / animal aleatório); **Markov multi-estado**; **estabilidade enzoótica** (taxa de inoculação h, prevalência por idade); mapas de risco; projeções climáticas (MaxEnt/ensemble); correção de Firth; ROC com banda IC95%.

## 11. Como o professor_tpb deve trabalhar (protocolo)

1. **Ler esta memória primeiro** + `AUDITORIA_MELHORIAS_TPB.md` quando o pedido for de melhoria.
2. Trabalhar SEMPRE na branch `claude/wizardly-shannon-jvsat7`; commitar com mensagens claras e atualizar a tabela §7 + esta memória.
3. **Validar antes de versionar:** `php -l`; quando mexer em estatística, escrever/rodar testes; quando mexer em gráfico, renderizar com mock + screenshot.
4. **Banco:** nunca assumir nomes de coluna — confirmar via `SHOW COLUMNS`/`information_schema`; entregar SQL para o usuário rodar (o ambiente não alcança a Hostinger).
5. **Deploy:** lembrar o usuário que a Hostinger é manual (push → pull → `sincronizar_hostinger.bat`/Gerenciador).
6. Ser honesto sobre limitações estatísticas (poucas fazendas, EPV baixo, colinearidade) e não superinterpretar.

## 12. Registro de acesso
- **2026-06-26:** `relatorio_cientifico.php` passou a ser de **ACESSO LIVRE (sem senha)** a pedido
  do responsável — o portão `$_SESSION['relatorio_auth']` (login bcrypt + rate limit + tela de senha)
  e o link "Sair" foram **removidos**. O `.htaccess` não tem Basic Auth. Para **reativar** a senha no
  futuro, restaurar o bloco de autenticação no topo do arquivo (histórico do Git em commits ≤ `74a7729`).
