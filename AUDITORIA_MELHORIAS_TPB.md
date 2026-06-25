# Auditoria bibliografica e roadmap de melhorias graficas/analiticas — TPB

*Documento tecnico-cientifico. Vigilancia de Tristeza Parasitaria Bovina (Babesia bovis, Anaplasma marginale, Trypanosoma vivax) — diagnostico por PCR e LAMP, com dados climaticos NASA POWER (media de 90 dias pre-coleta). Desenho longitudinal por animal, 4 coletas/ano (1 por estacao) em 3 localidades. Plataforma web PHP + Chart.js.*

*Data: 25/06/2026.*

---

## 1. Resumo executivo

- A pagina atual ja cobre, com boa solidez metodologica, o nucleo classico de um estudo de hemoparasitas (prevalencia com IC95% Wilson, PCR vs LAMP com kappa/McNemar/ROC, coinfeccao, longitudinal com matriz de transicao). A literatura analoga confirma que essas sao exatamente as analises esperadas para o desenho [1,4,9,12,13].
- A maior fragilidade transversal nao e a falta de analises, e sim **rigor de incerteza e correcao para o desenho**: kappa muitas vezes reportado como ponto sem IC95% [7]; regressao logistica que ainda nao trata a correlacao intra-animal das medidas repetidas [33,34,38]; e o numero minusculo de fazendas (3), que exige decisoes deliberadas de modelagem [28,29,31].
- Ha um conjunto de melhorias **viaveis agora, sem dados novos**: eixo logaritmico no forest plot de OR [22,23], paleta color-blind safe Okabe-Ito em toda a plataforma [20], exibir IC95% do kappa no heatmap [7], lasagna plot longitudinal por animal [16,17], diagrama alluvial/Sankey das transicoes [26,27,30], e leitura biologica das celulas discordantes do McNemar (portadores subpatentes) [2,8].
- Decisao de modelagem fundamentada para o forest plot: com apenas 3 fazendas, **fazenda deve ser efeito FIXO** (a "regra dos <5 niveis" torna a variancia do efeito aleatorio mal estimada) e o **animal deve ser o efeito ALEATORIO** (muitos niveis, captura a correlacao das reamostragens) [29,31]. GEE com fazenda como cluster e desaconselhado (<=30 clusters infla erro tipo I e perde poder) [28].
- Novos graficos diretamente ancorados em papers analogos: forest plot de prevalencia por teste lado a lado (PCR | qPCR | LAMP) por agente/estacao [1,5], coinfeccao estratificada por estacao [13,15,16], e diagrama de nicho climatico (temperatura x umidade x prevalencia) ligado ao vetor *R. microplus* [17,18].
- Analises que **destravam apenas com mais ondas sazonais**: cross-correlation com lag por patogeno [25], decomposicao sazonal STL/MSTL [42,43], GLMM/GEE robustos e versao Bayesiana [29,38,40], modelo multi-estado de Markov formal (taxas, sojourn time, efeito do clima por transicao) [26,27,30], e mapas/projecoes de risco climatico no estilo MaxEnt [11,17,18].
- Honestidade sobre limitacoes: 3 fazendas e poucas ondas iniciais implicam baixo EPV (events per variable), IC largos, risco de separacao para T. vivax (raro) e impossibilidade de modelagem espacial preditiva real agora. O documento marca claramente o que e exploratorio vs confirmatorio e indica limiares aproximados de dados.

---

## 2. Metodologia da busca

A auditoria baseou-se em revisao dirigida da literatura organizada em quatro eixos tematicos, com priorizacao de:

- **Estudos analogos de dominio**: hemoparasitas bovinos / Tristeza Parasitaria Bovina, com enfase em trabalhos brasileiros e em comparacoes diretas PCR vs qPCR vs LAMP [1,3,4,5,6,7,8,9,12,13,14,15,44].
- **Integracao clima-epidemiologia em doencas por carrapatos**, com foco no vetor compartilhado *Rhipicephalus (Boophilus) microplus* e nos patogenos B. bovis / A. marginale [11,17,18,19,21,24,25,26].
- **Metodos estatisticos para dados longitudinais agrupados com poucos clusters** (GLMM, GEE, multi-estado, correcoes de pequena amostra) [28,29,30,31,32,33,34,35,36,37,38].
- **Boas praticas de visualizacao cientifica** (forest plots, ROC, heatmaps, UpSet, paletas CVD-safe, guias de reporte STARD e STROBE-Vet) [20,22,23,24,45,46,47,48,49,50,51,52].

Fontes consultadas: PubMed/MEDLINE (PMID), DOI de periodicos indexados, e agregador Consensus para itens sem PMID. Criterio de inclusao: relevancia direta ao desenho (agente, hospedeiro, pais, ou metodo identico/analogo) e verificabilidade da citacao (PMID/DOI/URL). Nenhuma referencia foi gerada sem identificador verificavel; a Secao 8 lista as 52 referencias usadas.

---

## 3. Estado da arte

### 3.a. Visualizacao e analise em epidemiologia de TPB

A literatura analoga estabelece um padrao consolidado de relato. Estudos que comparam tecnicas no **mesmo conjunto de amostras** mostram que a prevalencia detectada cresce monotonicamente com a sensibilidade analitica do teste: para T. vivax na Amazonia brasileira, PCR convencional = 4%, qPCR = 38% e LAMP = 83,3% [1]; para A. marginale, microscopia 6,07% < PCR 15,36% < LAMP 22,14% [4]; e o padrao "molecular eleva a prevalencia" repete-se na India (microscopia 48,11% vs PCR 73,58%) [14]. A revisao-roadmap de 2025 organiza essa hierarquia formalmente (microscopia < PCR/nPCR < RLB < qPCR duplex < LAMP-LFD < RPA-CRISPR-Cas12a) e enfatiza o problema dos **portadores cronicos subpatentes** de B. bovis, detectaveis so por DNA [2].

A concordancia entre testes e relatada via **kappa de Cohen com classificacao de Landis & Koch**, e — criticamente — a literatura mostra que o kappa **nao e fixo**: para T. brucei na Gambia, o kappa LAMP vs PCR cai de 0,95 (IC 0,64-1,00) em alta parasitemia para 0,34 (IC 0,19-0,49) em sangue de campo [7]. McNemar e o teste padrao para amostras pareadas (PCR vs LAMP), reportado junto da prevalencia com IC [5], e as celulas discordantes (LAMP+/PCR-) sao interpretadas como achado biologico — portadores de baixa carga — e nao como erro [8].

Prevalencia e tipicamente apresentada **por localidade separadamente, nao agregada**, dada a forte heterogeneidade espacial do status epidemiologico [10,11]. Estudos brasileiros vinculam alta prevalencia + infeccao em animais jovens ao conceito de **estabilidade/instabilidade enzootica** [9,44], que pode ser operacionalizado quantitativamente pela taxa de inoculacao h a partir de prevalencia idade-especifica (limiar h<0,005 = instabilidade, risco de surto) [12]. Coinfeccao e regra, nao excecao: >50% dos positivos em coinfeccao em estudos do Quenia e de Uganda [15,16], e com **picos sazonais distintos** (T. vivax maior no outono) em estudo brasileiro alinhado ao desenho de 4 coletas/ano [13]. O UpSet plot e a tecnica de referencia para conjuntos interseccionais [49,50].

### 3.b. Integracao clima-epidemiologia em doencas por carrapatos

O vetor *R. microplus* — comum a B. bovis e A. marginale — e o elo mecanistico entre clima e infeccao. Modelagem de nicho ecologico (MaxEnt) com variaveis bioclimaticas projeta aumento de adequabilidade do vetor sob cenarios RCP 4.5/8.5 nas principais areas de producao bovina [17]; estudos recentes em diferentes regioes (China, Vietna, Africa do Sul) repetem o achado e identificam como variaveis dominantes a **amplitude termica diurna/anual, a precipitacao do trimestre seco e a densidade de gado** — preditores mais informativos que medias simples [18,21,24]. Trabalhos com 6 algoritmos em ensemble (biomod2) reportam validacao por AUC, True Skill Statistic e Boyce Index [19,24].

A biologia do vetor define **defasagens (lags) plausiveis** entre clima e infeccao: estudo brasileiro em Goias mostra ~5 geracoes/ano de R. microplus, moduladas por temperatura, umidade e deficit de saturacao, com geracao mais longa em jul-out [26]; limiares termicos de estabelecimento (~+2 a +2,75 graus C no outono/inverno) sao reportados para regioes temperadas [25]. Metodologicamente, a relacao clima->doenca e melhor modelada com **lag explicito**: ARIMAX em 20 anos de dados no Quenia encontrou lags patogeno-especificos (anaplasmose lag de 6 meses; babesiose sem associacao) [21], e Distributed Lag Non-linear Models (DLNM) em arcabouco Bayesiano (leishmaniose, Brasil, mesmas 3 variaveis climaticas) geram superficies exposicao-lag-resposta a partir de **anomalias climaticas** [22-eixo]. (Ver referencias [21] e seguintes na Secao 8.) Revisoes de alto impacto sobre clima e carrapatos reforcam a necessidade de quantificar incerteza e separar o efeito do clima de confundidores [27-eixo]. Correlacao positiva chuva-doenca em TBD bovina (Uganda, R2=0,56) com ciclos sazonais e bienais sustenta examinar periodicidade plurianual [25-clima].

---

## 4. Auditoria dos graficos/analises ATUAIS

| Item do projeto | Avaliacao | O que melhorar |
|---|---|---|
| **Prevalencia por fazenda/estacao com IC95% Wilson** | Bom. Wilson e a escolha correta para proporcoes com n pequeno; o relato por fazenda (nao agregado) esta alinhado a heterogeneidade espacial [10,11]. | Mostrar a distribuicao subjacente: exibir o n por barra, garantir que os whiskers reflitam a **assimetria** do IC de Wilson (nao barras de erro simetricas), e sobrepor strip/dot plot onde fizer sentido [46]. |
| **Comparacao PCR vs LAMP (sens/espec/kappa/McNemar/ROC)** | Muito bom; e o nucleo do estudo e segue o modelo da literatura [1,4,5]. | (a) Reportar sens/espec/AUC **sempre com IC95%** (item subreportado no STARD) [47]; (b) ROC com banda de IC95% (bootstrap) e ponto de corte de Youden; (c) interpretar as celulas discordantes do McNemar como portadores subpatentes [2,8]. |
| **Coinfeccao (UpSet-like)** | Bom; UpSet escala melhor que Venn para 3 patogenos [49,50]. | Ordenar intersecoes por tamanho, conjuntos marginais a esquerda, n no topo das barras, alt-text/aria-label para acessibilidade [50,51], e **estratificar por estacao** [13]. |
| **Analise longitudinal (estados + matriz de transicao)** | Bom ponto de partida e diferencial do estudo (mesmos animais). | A matriz numerica e descritiva; complementar com **lasagna plot** [16,17] e **alluvial/Sankey** [26,27,30], e elevar a um modelo multi-estado de Markov formal quando houver ondas suficientes [30,32,37]. |
| **Tempo de deteccao LAMP** | Util; e proxy de carga parasitaria. | Substituir barra de media por box/violino + pontos (barras escondem a distribuicao) [46]; usar o tempo de deteccao como **estratificador de carga** para o kappa [7]. |
| **Heatmap kappa 5x5** | Bom; concentra as concordancias num so painel. | **Adicionar IC95% do kappa** em cada celula (tooltip/anotacao) — hoje provavelmente so ponto [7]; ordenar linhas/colunas por gradiente de limite de deteccao [2]; rampa de cor sequencial CVD-safe (viridis/cividis) com valores rotulados [20]. |
| **Correlacao clima x prevalencia (Spearman + tendencia)** | Adequado como exploracao inicial [25-clima]. | Spearman ignora **defasagem**; evoluir para cross-correlation com lag por patogeno [21,25]. Reportar IC. |
| **Regressao logistica multivariavel clima->infeccao (forest plot de OR)** | Existe e e central, mas com a especificacao errada para o desenho. | (a) Tratar a **correlacao intra-animal** (efeito aleatorio de animal) [33,34,38]; (b) **fazenda como efeito FIXO** (regra <5 niveis) [29,31], nao GEE com fazenda como cluster [28]; (c) eixo X **logaritmico** com linha em OR=1 [22,23]; (d) correcao de Firth se houver separacao (T. vivax) [36]; (e) incluir estacao como fator categorico [24]. |
| **Heatmap temporal de clima semanal** | Bom descritivo. | Anotar **limiares biologicos do vetor** (faixa favoravel ao desenvolvimento de larvas; inibicao em baixa T) e marcar as semanas das 4 coletas [25,26]; rampa divergente se usar anomalia. |

---

## 5. Melhorias e novos graficos VIAVEIS AGORA

Todos abaixo usam apenas dados ja disponiveis (4 estacoes x 3 fazendas, PCR/LAMP, clima 90d, longitudinal).

### 5.1. Forest plot de OR com eixo logaritmico (melhoria)
- **O que**: trocar o eixo X do forest plot atual para escala log, linha de nulidade em OR=1, ponto estimado visivel (marcador dimensionado por n/peso), rotulo "OR (IC95%)" ao lado de cada linha.
- **Por que**: para medidas de razao (OR/RR) o eixo deve ser logaritmico para que 0,5 e 2 fiquem equidistantes do nulo e os IC simetricos; escala linear e classificada como falha de interpretabilidade [22,23].
- **Como (Chart.js)**: configurar `scales.x.type:'logarithmic'`; desenhar a linha em OR=1 via plugin `annotation` (`type:'line', scaleID:'x', value:1`). Cada preditor = um dataset tipo `scatter` com a barra de IC desenhada por um plugin custom (segmento de `x_low` a `x_high` no canvas via `ctx.moveTo/lineTo` no hook `afterDatasetsDraw`).

### 5.2. Paleta Okabe-Ito global (melhoria)
- **O que**: array global de cores CVD-safe (`#E69F00, #56B4E9, #009E73, #F0E442, #0072B2, #D55E00, #CC79A7`), mapeado de forma consistente: 1 cor por patogeno, cores distintas (nunca vermelho/verde adjacentes) para PCR vs LAMP e para as 3 fazendas. Reforcar com forma de marcador e rotulo direto.
- **Por que**: paleta de referencia da Nature para distinguibilidade em protanopia/deuteranopia/tritanopia; nunca codificar informacao so por cor [20].
- **Como (PHP/Chart.js)**: centralizar `const OKABE_ITO = [...]` e uma funcao `colorFor(categoria)`; injetar via PHP no template. Setar `pointStyle` (`'circle','triangle','rect'`) por categoria.

### 5.3. IC95% do kappa no heatmap + estratificacao (melhoria)
- **O que**: exibir o IC95% do kappa em cada celula (tooltip/anotacao) e gerar versoes do heatmap **por estacao** e/ou **por faixa de carga** (usando o tempo de deteccao LAMP ja medido como proxy).
- **Por que**: a concordancia entre testes depende da carga parasitaria e pode despencar (0,95 -> 0,34) [7]; reportar so o ponto omite essa variabilidade.
- **Como (PHP)**: variancia do kappa pela formula de Fleiss/Cohen a partir das tabelas 2x2 ja geradas; `kappa_se = sqrt(var)`, IC = `kappa +/- 1.96*se`. No Chart.js (matriz, mesmo motor do heatmap atual), expor no `tooltip.callbacks.label`.

### 5.4. Tabela 2x2 do McNemar como achado biologico (melhoria)
- **O que**: ao lado do p-valor, exibir explicitamente as celulas discordantes b (LAMP+/PCR-) e c (PCR+/LAMP-), com anotacao interpretativa ("LAMP+/PCR- = deteccao de baixa carga / portador subpatente").
- **Por que**: discordancias LAMP+/PCR- representam portadores cronicos subpatentes de B. bovis, nao erro [2,8].
- **Como (PHP)**: ja se tem a tabela pareada; renderizar uma mini-matriz 2x2 HTML/canvas com b e c destacados e legenda fixa.

### 5.5. Forest plot de prevalencia por teste, lado a lado (novo)
- **O que**: para cada agente, prevalencia estimada por CADA metodo (PCR | qPCR se houver | LAMP) com barras de IC95% Wilson no mesmo eixo, facetado por estacao.
- **Por que**: transforma a comparacao PCR vs LAMP de tabela em figura comparativa, tornando visivel o ganho de sensibilidade do LAMP — exatamente como T. vivax/Amazonia e T. evansi reportam [1,5].
- **Como (Chart.js)**: barras agrupadas (`type:'bar'`, datasets = testes) com whiskers Wilson via plugin custom; small multiples por estacao (4 canvas).

### 5.6. Coinfeccao estratificada por estacao + barra mono vs poli (melhoria)
- **O que**: UpSet-like em 4 facetas (uma por estacao) + barra empilhada da fracao de positivos que sao mono-infeccao vs co-infeccao (2+ agentes) por estacao/fazenda.
- **Por que**: coinfeccoes tem picos sazonais distintos [13] e >50% dos positivos sao coinfeccao [15,16].
- **Como (PHP/Chart.js)**: agregar perfis de positividade por estacao; `type:'bar', stacked:true`. Manter ordenacao por tamanho da intersecao e n no topo [49,50].

### 5.7. Lasagna plot longitudinal por animal (novo)
- **O que**: matriz onde cada **linha = um animal**, cada **coluna = uma onda** (estacao x ano), cor da celula = status (negativo / B. bovis / A. marginale / T. vivax / coinfeccao / ausente).
- **Por que**: visualizacao canonica para desfecho longitudinal binario/categorico com perdas; supera o spaghetti plot e revela animais cronicamente positivos vs transitorios [16,17].
- **Como (Chart.js)**: reusar o motor de matriz do heatmap kappa (plugin `chartjs-chart-matrix` ou desenho canvas direto); permitir ordenar linhas por n de positivos / fazenda / clustering. Celulas sem coleta = categoria "ausente" (cinza neutro).

### 5.8. Diagrama alluvial/Sankey das transicoes (novo)
- **O que**: fitas de largura proporcional ao n de animais migrando entre estados (negativo <-> positivo por patogeno) entre estacoes consecutivas; estratificavel por fazenda e por estacao seca/chuvosa.
- **Por que**: complementa a matriz numerica comunicando simultaneamente probabilidade e variabilidade do destino futuro [26,27,30].
- **Como (Chart.js)**: plugin `chartjs-chart-sankey` (datasets `{from, to, flow}`), ou D3 embutido. Insumo = pares (estado_t, estado_t+1) por animal — mesmo dado da matriz atual, agora como contagem de fluxos.

### 5.9. Diagrama de nicho climatico (temperatura x umidade x prevalencia) (novo)
- **O que**: scatter T media 90d (X) x umidade/deficit de saturacao (Y); cada ponto = uma coleta/localidade; tamanho/cor = prevalencia; sobrepor a "zona de adequabilidade" do R. microplus da literatura e seta de deslocamento sob +2 graus C.
- **Por que**: conecta visualmente os dados ao quadro de nicho/mudanca climatica sem rodar MaxEnt [17,18,25].
- **Como (Chart.js)**: `type:'bubble'` (r = prevalencia); faixa de adequabilidade via plugin `annotation` (`type:'box'`); seta via plugin custom.

### 5.10. Variaveis climaticas mecanisticas no forest plot (melhoria)
- **O que**: adicionar como preditores o **deficit de saturacao** (funcao de T e UR) e a **amplitude termica** / precipitacao do periodo seco, e incluir estacao como fator categorico; comparar AUC do modelo atual vs enriquecido.
- **Por que**: deficit de saturacao liga-se a sobrevivencia da larva [26]; amplitude termica e precipitacao seca sao variaveis dominantes para o vetor [18,24]; estacao e preditor independente [24].
- **Como (PHP)**: derivar deficit de saturacao das series diarias NASA POWER (`es = 0.6108*exp(17.27*T/(T+237.3))`, `VPD = es*(1 - UR/100)`); recalcular janelas para o trimestre seco; rodar modelo e atualizar OR.

### 5.11. Spaghetti com highlight + small multiples por fazenda (melhoria)
- **O que**: trajetorias por animal em cinza de fundo, destacando em cor a media por fazenda e animais atipicos (persistentemente positivos); 3 paineis (um por fazenda).
- **Por que**: resolve o over-plotting que cresce com as ondas (front-and-back / brolgar) [28-vis,29-vis,30-vis]. (Ver [16,17] e refs de small multiples na Secao 8.)
- **Como (Chart.js)**: datasets cinza de baixa opacidade no fundo + datasets coloridos por cima; `pointRadius:0` nas linhas de fundo.

### 5.12. Legendas padronizadas STROBE-Vet (melhoria de processo)
- **O que**: template de legenda reutilizavel declarando, por grafico: n e nivel de agrupamento (animal/fazenda/estacao), definicao de "positivo", metodo de IC (Wilson), modelo estatistico, janela climatica (90d) e periodo.
- **Por que**: alinha a plataforma ao reporte exigido em estudos observacionais veterinarios com dados agrupados/longitudinais [48,52].
- **Como (PHP)**: funcao `legendaPadrao($meta)` que monta a nota de rodape a partir de metadados ja existentes.

---

## 6. Analises/graficos que DESTRAVAM COM MAIS DADOS

A regra geral: o desenho de 4 coletas/ano so produz inferencia sazonal/temporal estavel apos varios ciclos. Limiares aproximados abaixo.

| Analise | Por que (citacao) | Limiar aproximado de dados |
|---|---|---|
| **Cross-correlation clima->prevalencia com lag por patogeno** | Cada patogeno tem lag proprio (anaplasmose ~6 meses; babesiose sem associacao) [21]; biologia do vetor sustenta lags [26]. | >=8-12 pontos temporais por localidade para CCF estavel; com menos, apresentar como exploratorio com bandas de significancia (+/-1,96/sqrt(n)) e IC largos. |
| **Curva de OR cumulativo por lag (forest plot defasado)** | Versao simplificada de DLNM, identifica visualmente o lag de maior efeito [21]. | Alguns ciclos para OR estaveis; abaixo disso, rotular "exploratorio" com IC95% explicitamente largos. |
| **DLNM (exposicao-lag-resposta)** | Capta relacao nao-linear e defasada que a logistica linear nao ve; template Brasil, mesmas 3 variaveis [22-eixo, 23-eixo]. | Serie mensal/semanal mais longa ou varias localidades-anos; com 4 coletas/ano por poucos anos, aproximar com splines no modelo logistico atual. |
| **Decomposicao sazonal STL/MSTL + teste por GLMM** | Separa tendencia de sazonalidade; template veterinario combina STL (visual) + GLMM (inferencia) [41,42,43]. Incerteza propagada via UASTL [43-vis], coerente com Wilson. | >=2-3 ciclos sazonais completos (>=8-16 ondas); o componente sazonal anual exige >=2 anos. |
| **GLMM/GEE robusto para clima->infeccao** | Necessario para correlacao intra-animal; fazenda fixa, animal aleatorio [29,31,33,34,38]; quadratura adaptativa + bootstrap/Kenward-Roger para IC [35]. | Numero suficiente de coletas repetidas por animal para estimar a variancia do efeito de animal (idealmente >=3-4 ondas/animal). |
| **Versao Bayesiana hierarquica (prioris fracamente informativas)** | Estabiliza estimacao com pouquissimos clusters e eventos raros (T. vivax) [29]. | Recomendavel apos acumular mais ondas; requer backend R (brms/Stan ou rstanarm). |
| **Modelo multi-estado de Markov formal** (taxas de transicao, sojourn time, HR de clima por transicao) | Extrai o maximo do diferencial (mesmos animais); clima como covariavel tempo-dependente [30,32,37]; acoplar ao Sankey [30]. | >=3-4 ondas/animal para transicoes estaveis; com poucas, usar modelo tempo-homogeneo. |
| **Analise formal de estabilidade enzootica (taxa de inoculacao h)** | Classifica cada localidade como estavel/instavel (limiar h<0,005) [12]; casa com "quase estabilidade" brasileira [9,44]. | Idade de cada animal cruzada com status ao longo das coletas; idealmente sorologia (ELISA) alem de PCR/LAMP. |
| **Mapa de risco por localidade ligado a clima (precursor MaxEnt)** | Modelagem espacial baseada em clima e padrao na area [11,17,18]; mapa municipal de T. vivax em Goias [- (Bastos)]. | Mapa coropletico/bolhas viavel ja com 3 pontos; modelo de adequabilidade preditivo real exige **muitas mais localidades** + variaveis bioclimaticas adicionais. |
| **Projecoes climaticas (ensemble RCP/CMIP6)** | +14% de area adequada para R. microplus na Africa do Sul; ganho/perda de area (%) como metrica de impacto [17,19,24]. | So apos transicao para modelagem espacial (muitas localidades); validar com AUC/TSS/Boyce [19,24]. |
| **ThreadStates / clustering de trajetorias** | Clusteriza animais por trajetoria e associa a features (fazenda, clima, idade) [- (Wang)]. | Muitas ondas + muitos animais; ferramenta de fase avancada. |

---

## 7. Roadmap priorizado

| Item | Tipo | Viabilidade | Impacto | Esforco |
|---|---|---|---|---|
| Forest plot OR em eixo log + linha OR=1 + ponto estimado | melhoria | agora | alto | baixo |
| Paleta Okabe-Ito global (CVD-safe) | melhoria | agora | alto | baixo |
| IC95% do kappa no heatmap 5x5 | melhoria | agora | alto | baixo |
| McNemar: celulas discordantes como achado biologico | melhoria | agora | medio | baixo |
| Legendas padronizadas STROBE-Vet | processo | agora | medio | baixo |
| Coinfeccao estratificada por estacao + mono/poli | melhoria | agora | alto | medio |
| Forest plot de prevalencia por teste (PCR\|qPCR\|LAMP) | novo | agora | alto | medio |
| Lasagna plot longitudinal por animal | novo | agora | alto | medio |
| Diagrama alluvial/Sankey das transicoes | novo | agora | alto | medio |
| Diagrama de nicho climatico (T x UR x prevalencia) | novo | agora | medio | medio |
| Variaveis mecanisticas (deficit saturacao, amplitude, estacao) | melhoria | agora | alto | medio |
| Spaghetti com highlight + small multiples | melhoria | agora | medio | medio |
| Tempo de deteccao LAMP: box/violino + pontos | melhoria | agora | medio | baixo |
| Prevalencia: distribuicao + n por barra + whiskers assimetricos | melhoria | agora | medio | baixo |
| GLMM: fazenda FIXA + animal ALEATORIO no forest plot | reespecificacao | agora* | alto | medio-alto |
| Correcao de Firth + checagem de separacao | melhoria | curto prazo | medio | medio |
| ROC com banda IC95% (bootstrap) + AUC com IC + Youden | melhoria | curto prazo | alto | medio |
| Estabilidade enzootica: taxa de inoculacao h + prevalencia por idade | nova analise | curto prazo | alto | alto |
| Cross-correlation clima->prevalencia com lag | nova analise | curto prazo** | alto | medio |
| Curva de OR cumulativo por lag | novo | curto prazo** | medio | medio |
| Modelo multi-estado de Markov formal | nova analise | com mais dados | alto | alto |
| Decomposicao STL/MSTL + GLMM | nova analise | com mais dados | alto | alto |
| DLNM (exposicao-lag-resposta) | nova analise | com mais dados | alto | alto |
| GLMM Bayesiano hierarquico | nova analise | com mais dados | medio | alto |
| Mapa de risco por localidade (precursor) | nova analise | com mais dados | medio | alto |
| Projecoes climaticas ensemble (MaxEnt/biomod2) | nova analise | com mais dados | medio | muito alto |

\* Reespecificacao usa dados existentes, mas a variancia do efeito de animal e os IC ficam confiaveis so com >=3-4 ondas/animal.
\** Viavel agora como exploratorio (IC largos, rotulo explicito); confirmatorio so com >=8-12 ondas.

### Nota honesta sobre limitacoes
- **Apenas 3 fazendas**: impede tratar fazenda como efeito aleatorio (regra <5 niveis) [29,31] e desaconselha GEE com fazenda como cluster (<=30 clusters: erro tipo I inflado, baixo poder) [28]. Modelagem espacial preditiva (MaxEnt) nao e valida com 3 pontos.
- **Poucas ondas no inicio = baixo EPV**: OR/kappa/correlacoes com IC largos; risco de **separacao** para T. vivax (raro), exigindo Firth [36]. Toda serie temporal/sazonal e exploratoria ate >=2 anos completos.
- **Perdas longitudinais (animais nao reamostrados)**: preferir GLMM (premissa MAR) a GEE (assume MCAR) [33]; sempre exibir curva de retencao de animais por onda.
- **Discordancia entre testes nao e "erro"**: refletem limites de deteccao distintos e portadores subpatentes; deve ser comunicado como achado biologico [2,8].

---

## 8. Referencias

1. Comparative Analysis of PCR, Real-time PCR and LAMP Techniques in the Diagnosis of Trypanosoma vivax Infection in Naturally Infected Buffaloes and Cattle in the Brazilian Amazon. Pakistan Veterinary Journal, 2024. URL: https://consensus.app/papers/details/212c519868cb55ada75974d283a0d352/
2. A roadmap in detecting frequently reported bovine babesiosis: From blood smear to CRISPR. Vet Parasitol, 2025. PMID 41344285; DOI 10.1016/j.vetpar.2025.110662
3. Detection of Babesia bovis using loop-mediated isothermal amplification (LAMP) with improved thermostability, sensitivity and alternative visualization methods. Sci Rep, 2023. PMID 36725982; DOI 10.1038/s41598-023-29066-1
4. Loop-mediated isothermal amplification assay for the rapid detection of Anaplasma marginale in cattle based on major surface protein 5 gene. J Vector Borne Dis, 2023. PMID 37026219; DOI 10.4103/0972-9062.353249
5. Enhanced detection of Trypanosoma evansi in Cattle: Superior performance of LAMP compared to PCR and CATT/T. evansi test. J Assoc Med Sci, 2025. URL: https://consensus.app/papers/details/85b09700baa156cbaa50a0d2ae3968a5/
6. Development of a loop-mediated isothermal amplification (LAMP) assay for the detection of Anaplasma marginale (msp1b) compared to qPCR. Exp Appl Acarol, 2018. URL: https://consensus.app/papers/details/e1f0af99f54d55d3a3cd94090ccc589e/ (relacionado PMID 30197015)
7. Comparison of loop-mediated isothermal amplification (LAMP) and PCR for the diagnosis of infection with Trypanosoma brucei ssp. in equids in The Gambia. PLoS ONE, 2020. URL: https://consensus.app/papers/details/a6243022661a51bab2a912926e18f523/
8. Development and validation of direct dry loop mediated isothermal amplification for diagnosis of Trypanosoma evansi. Vet Parasitol, 2018. PMID 30197015; DOI 10.1016/j.vetpar.2018.08.009
9. Prevalence and risk factors associated with Babesia bovis infection in Crioula Lageana cattle. J Infect Dev Ctries, 2023. PMID 38252736; DOI 10.3855/jidc.18052
10. Risks of cattle babesiosis (Babesia bovis) outbreaks in a semi-arid region of Argentina. Prev Vet Med, 2019. PMID 31442710; DOI 10.1016/j.prevetmed.2019.104747
11. Babesiosis as a potential threat for bovine production in China. Parasit Vectors, 2021. PMID 34493328; DOI 10.1186/s13071-021-04948-3
12. Risks of cattle babesiosis outbreaks — taxa de inoculacao / estabilidade enzootica (Morel et al., Argentina), 2019. PMID 31442710; DOI 10.1016/j.prevetmed.2019.104747
13. The seasonality as a relevant aspect to be considered for differential diagnosis of Trypanosoma vivax infection and co-infections in female cattle. Comp Immunol Microbiol Infect Dis, 2024. PMID 38653007; DOI 10.1016/j.cimid.2024.102180
14. Molecular characterization and prevalence of bovine hemoprotozoan and rickettsial organism from Bhubaneswar, Eastern India. Anim Biotechnol, 2022. PMID 36169089; DOI 10.1080/10495398.2022.2125403
15. Molecular epidemiology of Babesia species, Theileria parva, and Anaplasma marginale infecting cattle in Central and Eastern Uganda. Ticks Tick Borne Dis, 2018. PMID 30017724; DOI 10.1016/j.ttbdis.2018.06.012
16. Molecular detection and characterization of Babesia bovis, Babesia bigemina, Theileria species and Anaplasma marginale isolated from cattle in Kenya. Parasit Vectors, 2015. PMID 26420543; DOI 10.1186/s13071-015-1106-9
17. Climate change implications for the distribution of the babesiosis and anaplasmosis tick vector, Rhipicephalus (Boophilus) microplus. Vet Res, 2020. PMID 32546223; DOI 10.1186/s13567-020-00802-z (PMC7298856)
18. Habitat suitability and distribution dynamics of Rhipicephalus microplus under climate change in Yunnan Province, China. Acta Trop, 2025. PMID 40816668; DOI 10.1016/j.actatropica.2025.107784
19. Modelling habitat suitability of the invasive tick Rhipicephalus microplus in West Africa. Transbound Emerg Dis, 2022. PMID 34985810; DOI 10.1111/tbed.14449
20. Points of view: Color blindness (paleta Okabe-Ito / Wong B.). Nat Methods, 2011. PMID 21774112; DOI 10.1038/nmeth.1618
21. Associations between monthly rainfall and mortality in cattle due to East Coast fever, anaplasmosis and babesiosis (Laikipia, Kenya; ARIMAX). Parasitology, 2020;147(14). PMID 32907657; PMC10317715
22. Forest Plots: Linear or Logarithmic Scale? J Adolesc Health, 2017. PMID 29061236; DOI 10.1016/j.jadohealth.2017.07.025
23. Differential Treatment Effects of Subgroup Analyses in Phase 3 Oncology Trials (auditoria de forest plots). JAMA Netw Open, 2024. PMID 38546648; DOI 10.1001/jamanetworkopen.2024.3379
24. The current status and predicted climate-driven range expansion of Rhipicephalus microplus in northern Vietnam. Acta Trop, 2025. PMID 40617273; DOI 10.1016/j.actatropica.2025.107732
25. Assessment of habitat suitability for the cattle tick Rhipicephalus (Boophilus) microplus in temperate areas. Res Vet Sci, 2022. PMID 35803002; DOI 10.1016/j.rvsc.2022.04.020
26. Annual number of generations and biology of non-parasitic phase of Rhipicephalus microplus in irrigated and non-irrigated pasture in a tropical region (Brazil). Vet Parasitol, 2024. PMID 39116548; DOI 10.1016/j.vetpar.2024.110278
27. Impact of weather extremes on the spatiotemporal dynamics of visceral leishmaniasis in Brazil (DLNM em arcabouco Bayesiano espaco-temporal). PLoS Negl Trop Dis, 2025. PMID 40720386; DOI 10.1371/journal.pntd.0013209
28. Cluster randomised trials with a binary outcome and a small number of clusters: comparison of individual and cluster level analysis method. BMC Med Res Methodol, 2022. DOI 10.1186/s12874-022-01641-6 (Thompson JA et al.)
29. Estimating relative risks in multicenter studies with a small number of centers — which methods to use? A simulation study. Trials, 2017 (Pedroza C et al.). URL: https://consensus.app/papers/details/5a4711c8e9ed5a36bb044962515f975f/
30. Analyzing longitudinal binary data in clinical studies. Contemp Clin Trials, 2022 (Li Y et al.). URL: https://consensus.app/papers/details/5962e044f64159d1bfa2a5b5ed66386f/
31. Should I use fixed effects or random effects when I have fewer than five levels of a grouping factor in a mixed-effects model? PeerJ, 2022 (Gomes DGE). PMC8784019; preprint DOI 10.1101/2021.04.11.439357
32. Beyond ANOVA and MANOVA for repeated measures: Advantages of generalized estimating equations and generalized linear mixed models. Eur J Neurosci, 2022 (de Melo MB et al.). URL: https://consensus.app/papers/details/59cdb2af3ee05672a3d0ca0e6f26365c/
33. Analyzing longitudinal binary data (GLMM vs GEE sob MAR) — Li Y et al., Contemp Clin Trials, 2022. URL: https://consensus.app/papers/details/5962e044f64159d1bfa2a5b5ed66386f/
34. Beyond ANOVA and MANOVA for repeated measures — de Melo MB et al., Eur J Neurosci, 2022. URL: https://consensus.app/papers/details/59cdb2af3ee05672a3d0ca0e6f26365c/
35. Generalized linear mixed models: a practical guide for ecology and evolution. Trends Ecol Evol, 2009 (Bolker BM et al.). PMID 19185386; DOI 10.1016/j.tree.2008.10.008
36. An investigation of penalization and data augmentation to improve convergence of generalized estimating equations for clustered binary outcomes. BMC Med Res Methodol, 2022 (Geroldinger A et al.). PMID 35681120; DOI 10.1186/s12874-022-01641-6
37. Transitions Between Preexposure Prophylaxis Eligibility States and HIV Infection: A Multistate Model Analysis. Am J Epidemiol, 2022 (Meireles P et al.). PMID 34718381; DOI 10.1093/aje/kwab260
38. Methods and processes of developing the STROBE-Vet statement. Prev Vet Med, 2016. PMID 27836042; DOI 10.1016/j.prevetmed.2016.09.005
39. Dynamic AFP-Defined Disease State Transitions Using the Multi-State Markov Model + Sankey diagrams. J Hepatocell Carcinoma, 2026 (Song Q et al.). URL: https://consensus.app/papers/details/555c791b158f5dbcb42ed616223d554e/
40. Multistate Model of Chronic Wounds, Amputations, and Mortality (covariaveis tempo-dependentes). Ann Surg, 2025 (Choi JS et al.). PMID 40396228; DOI 10.1097/SLA.0000000000006761
41. Using seasonal-trend decomposition based on loess (STL) to explore temporal patterns of pneumonic lesions in finishing pigs. Prev Vet Med, 2012 (Sanchez-Vazquez M et al.). URL: https://consensus.app/papers/details/7074ed6ea0bf51fa90a148f75928b317/
42. MSTL: A Seasonal-Trend Decomposition Algorithm for Time Series with Multiple Seasonal Patterns. Int J Oper Res, 2021 (Bandara K et al.). URL: https://consensus.app/papers/details/ee283be5b3e054e9912d20259177bc88/
43. Uncertainty-Aware Seasonal-Trend Decomposition Based on Loess (UASTL). IEEE TVCG, 2024 (Krake T et al.). URL: https://consensus.app/papers/details/83af1880493a546189e3024298e4c4c0/
44. Prevalence of Babesia, Anaplasma and Theileria in cattle in the Campos de Lages region (multiplex-PCR), enzootic instability e sazonalidade. Parasite Epidemiol Control, 2019 (Vieira LL et al.). PMID 31528739; DOI 10.1016/j.parepi.2019.e00114
45. Lasagna plots: a saucy alternative to spaghetti plots. Epidemiology, 2010 (Swihart B et al.). URL: https://consensus.app/papers/details/03cc8bfb1bdb5f658f74ae9fa4f193dd/
46. Lasagna plots to visualize results in surgical studies. Int J Surg, 2017 (Jalalzadeh H et al.). PMID 28578083; DOI 10.1016/j.ijsu.2017.05.063
47. Reporting diagnostic accuracy studies: some improvements after 10 years of STARD. Radiology, 2014 (Korevaar). PMID 25350641; DOI 10.1148/radiol.14141160
48. Explanation and Elaboration Document for the STROBE-Vet Statement. J Vet Intern Med, 2016. PMID 27859752; DOI 10.1111/jvim.14592; PMC5115190
49. UpSet: Visualization of Intersecting Sets. IEEE TVCG, 2014 (Lex A et al.). DOI 10.1109/TVCG.2014.2346248
50. UpSetR: an R package for the visualization of intersecting sets and their properties. Bioinformatics, 2017 (Conway JR et al.). DOI 10.1093/bioinformatics/btx364
51. Accessible Text Descriptions for UpSet Plots (AccessViz Workshop), 2024. URL: https://consensus.app/papers/details/ed0c583f35735b62a1f076863a5c7aea/
52. Beyond bar and line graphs: time for a new data presentation paradigm. PLoS Biol, 2015 (Weissgerber TL et al.). PMID 25901488; DOI 10.1371/journal.pbio.1002128

*Referencias complementares citadas no texto (visualizacao longitudinal e clima):*
- ggalluvial: Layered Grammar for Alluvial Plots. JOSS, 2020 (Brunson J). URL: https://consensus.app/papers/details/45e2de01e88159f7a7b91ed719554230/
- Overview of Sankey flow diagrams: Focusing on symptom trajectories. J Geriatr Oncol, 2022 (Otto E et al.). URL: https://consensus.app/papers/details/a6fc258291b45c9cb82f5add4e192915/
- ThreadStates: State-based Visual Analysis of Disease Progression. IEEE TVCG, 2021 (Wang Q et al.). URL: https://consensus.app/papers/details/fb65762d6b69590fafcf849b8b5da3d3/
- brolgar: An R package to BRowse Over Longitudinal Data Graphically and Analytically. R Journal, 2020 (Tierney NJ et al.). URL: https://consensus.app/papers/details/32a3ae78a97d51289f6a7773020b8f3a/
- Speaking Stata: Front-and-back plots to ease spaghetti and paella problems. Stata Journal, 2021 (Cox N). URL: https://consensus.app/papers/details/80aea62ff50456aaaa4365c111e20e77/
- Epidemiological and molecular identification of Trypanosoma vivax diagnosed in cattle during outbreaks in central Brazil (mapa municipal Goias). Parasitology, 2020 (Bastos et al.). PMID 32624014; DOI 10.1017/S0031182020001006
- Modeling the spatial distribution and environmental factors of dominant ixodid tick species parasitizing cattle in northwest Ethiopia (MaxEnt + QGIS). Vet Parasitol, 2025. PMID 40020387; DOI 10.1016/j.vetpar.2025.110436
- Predicting tick distributions in a changing climate: An ensemble approach for South Africa (biomod2, CMIP6). Vet Parasitol, 2025. PMID 40609146; DOI 10.1016/j.vetpar.2025.110528
- Association between environmental factors and dengue incidence in Lao PDR (DLNM dois estagios, quasi-Poisson). BMC Public Health, 2023 (Sugeno et al.). DOI 10.1186/s12889-023-15453-w
- The Impacts of Climate Change on Ticks and Tick-Borne Disease Risk. Annu Rev Entomol, 2021 (Gilbert L). DOI 10.1146/annurev-ento-120120-042111
- Understanding the Association between Cattle Tick-borne Diseases and Climate variability in Mbarara District, Western Uganda, 2023 (Komutunga et al.). URL: https://consensus.app/papers/details/69f311f64cb955f4bdada0a7112e0122
