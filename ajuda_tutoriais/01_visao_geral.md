# 📊 Visão Geral da Plataforma

A plataforma **Diagnóstico TPB** é um sistema web para gestão e análise de dados epidemiológicos
da Tristeza Parasitária Bovina (TPB), focada em três municípios do Rio de Janeiro:
**Seropédica (Pesagro)**, **Pinheiral (IFRJ)** e **Japeri (Quintas Barbosa)**.

## Hemoparasitas estudados

| Patógeno | Doença |
|---|---|
| *Babesia bovis* | Babesiose (alvo principal LAMP) |
| *Babesia bigemina* | Babesiose |
| *Anaplasma marginale* | Anaplasmose |
| *Trypanosoma vivax* | Tripanossomose |

## Módulos principais

- **Dashboard** — visualização integrada de coletas, prevalências e correlações climáticas.
- **Formulários** — entrada de locais, animais, coletas, resultados PCR/LAMP e exames clínicos.
- **Variáveis climáticas** — captura NASA POWER (T2M, PRECTOTCORR, RH2M) com fallback INMET.
- **Pesquisa de animais** — busca, filtro e exportação.
- **Relatório científico** — Tabela 1 (caracterização) e prevalência com IC95% (Wilson).
- **Manutenção** — integridade do banco, backups, funções, estrutura e ajuda (esta aba).

## Métodos diagnósticos

- **qPCR** (referência) para *B. bovis*, *B. bigemina*, *A. marginale*, *T. vivax*.
- **PCR-LAMP** (em padronização) para *B. bovis*, alvos 18S rRNA e citocromo B.
- **16S rRNA mitocondrial bovino** como controle interno em todas as análises.

## Tipos de usuário

- **Admin** — acesso completo, manutenção, backup, sincronizações.
- **Leitor** — visualização de dashboard e relatórios; sem edição.

> Para dúvidas operacionais, consulte as demais abas desta seção de ajuda.
