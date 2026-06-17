# 📝 Como preencher os formulários

Os formulários (`forms.php`) são a única porta de entrada de dados. Toda a estrutura
do banco depende da consistência destes registros.

## Ordem de cadastro recomendada

1. **Local (fazenda)** — antes de qualquer animal.
2. **Animal** — vinculado a um local existente.
3. **Coleta** — vinculada a um animal (cria a data de referência).
4. **Resultado PCR** e **Resultado LAMP** — vinculados à coleta.
5. **Exame clínico** — opcional, vinculado ao animal/coleta.

## Local (fazenda)

- **Nome** e **município** são obrigatórios.
- **Latitude/longitude** em decimais (ex.: `-22.7531`, `-43.6856`). Quando preenchidos,
  o sistema dispara a captura automática de dados climáticos NASA POWER em background.
- Os três locais ativos do estudo são: **Pesagro/Seropédica**, **IFRJ Pinheiral** e
  **Quintas Barbosa/Japeri**.

## Animal

- **Brinco** — identificador único por fazenda. Use o padrão **PH-XXX** (Pinheiral),
  **QB-XX** (Quintas Barbosa) ou o número da Pesagro (27XX/29XX).
- **Raça** — padronizada como `Girolando` para todos os animais reais do estudo.
  Detalhes de composição (HP×GIR) ficam em `composicao_genetica` quando aplicável.
- **Sexo, data de nascimento, lactação** — preencher quando houver registro.

## Coleta

- **Data** no formato `dd/mm/aaaa` (input HTML auto-formata).
- **Tubo/observações** — opcional; útil para rastreamento de bancada.
- Uma coleta pode gerar múltiplos resultados (PCR + LAMP, ou repetições).

## Resultado PCR / LAMP

- **Alvo molecular** — *Babesia bovis*, *B. bigemina*, *A. marginale*, *T. vivax*
  ou 16S (controle).
- **Resultado** — `positivo` / `negativo` / `inconclusivo`.
- **Ct** (PCR) ou **tempo de amplificação** (LAMP) — quando disponível.
- **Analista** e **data da corrida** — rastreabilidade laboratorial.

## Boas práticas

- **Sempre preencher coordenadas** ao cadastrar uma fazenda — sem isso, as análises
  climáticas ficam vazias.
- **Use `simulacao=1`** ao cadastrar dados fictícios de demonstração (ex.: Fazenda
  Sorriso). O filtro científico exclui automaticamente esses registros.
- **Não delete** animais ou coletas pelo phpMyAdmin — use o módulo de manutenção,
  que registra log de exclusões.
