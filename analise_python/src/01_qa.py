"""
01_qa.py — Quality Assurance do banco TPB.

Executa quatro tarefas, conforme PLANO_OTIMIZACAO.md §3 (Fase 1):

  (i)   Identifica e flagueia animais simulados / sentinelas / fazendas fictícias.
  (ii)  Detecta divergências PCR vs LAMP em B. bovis e calcula κ de Cohen com IC95%.
  (iii) Reporta outliers climáticos (z-score > 3 em temperatura/pluviometria/umidade).
  (iv)  Gera Codebook.xlsx com toda variável, tipo, unidade, range esperado, fonte, regra.

Saídas (em outputs/):
  - relatorio_qa.pdf
  - codebook.xlsx
  - flags_qa.xlsx        (uma aba por verificação)
  - log_qa.txt

Uso:
    cd analise_python
    cp .env.example .env   # primeira vez
    pip install -r requirements.txt
    python src/01_qa.py
"""
from __future__ import annotations

import os
import sys
import json
import textwrap
from datetime import datetime
from pathlib import Path

import numpy as np
import pandas as pd

# Garantir import local mesmo se rodar de subpasta
sys.path.insert(0, str(Path(__file__).resolve().parent))
from db import get_engine  # noqa: E402

ROOT = Path(__file__).resolve().parent.parent
OUTPUT = ROOT / os.getenv("TPB_OUTPUT_DIR", "outputs")
OUTPUT.mkdir(parents=True, exist_ok=True)

LOG_LINES: list[str] = []


def log(msg: str) -> None:
    line = f"[{datetime.now().strftime('%Y-%m-%d %H:%M:%S')}] {msg}"
    print(line)
    LOG_LINES.append(line)


# ============================================================
# (i) Flag de simulados, sentinelas, fazendas fictícias
# ============================================================
def carregar_dados(engine) -> dict[str, pd.DataFrame]:
    log("Lendo tabelas do banco…")
    queries = {
        "locais": "SELECT * FROM locais",
        "animais": "SELECT * FROM animais",
        "coletas": "SELECT * FROM coletas",
        "resultados_pcr": "SELECT * FROM resultados_pcr",
        "resultados_lamp": "SELECT * FROM resultados_lamp",
        "dados_climaticos": "SELECT * FROM dados_climaticos",
        "animais_completos": "SELECT * FROM animais_completos",
    }
    out = {}
    for k, q in queries.items():
        try:
            out[k] = pd.read_sql(q, engine)
            log(f"  {k}: {len(out[k])} linhas")
        except Exception as e:
            log(f"  AVISO: {k} indisponível ({e}); continuando.")
            out[k] = pd.DataFrame()
    return out


def identificar_sinteticos(df_animais: pd.DataFrame, df_locais: pd.DataFrame) -> pd.DataFrame:
    """Retorna DataFrame com flags por motivo de exclusão científica."""
    a = df_animais.copy()
    if a.empty:
        log("  AVISO: tabela animais vazia — pulando identificação de sintéticos.")
        return a, []
    for col in ("observacoes", "raca", "identificacao", "local_id"):
        if col not in a.columns:
            a[col] = ""
    a["observacoes"] = a["observacoes"].fillna("").astype(str)
    a["raca"] = a["raca"].fillna("").astype(str)
    a["identificacao"] = a["identificacao"].fillna("").astype(str)

    a["flag_dados_simulados"] = a["observacoes"].str.contains("Dados simulados", case=False, na=False)
    a["flag_clima_manual"] = a["identificacao"].str.contains("CLIMA[-_]MANUAL", regex=True)
    a["flag_clima_ref"] = a["identificacao"].str.contains("CLIMA_REF", regex=True)
    a["flag_sentinela"] = a["raca"].str.lower().eq("sentinela")
    a["flag_pre_carga"] = a["observacoes"].str.contains("PRÉ-CARGA|fictício|fictic", case=False, regex=True)

    # Localização fictícia: cruza com tabela de locais
    fic = df_locais.assign(
        _fic=df_locais.get("descricao", "").fillna("").astype(str).str.contains(
            "fictíc|demonstr|ilustrativo", case=False, regex=True
        )
    )
    fic_ids = fic.loc[fic["_fic"], "id"].tolist()
    a["flag_local_ficticio"] = a["local_id"].isin(fic_ids)

    flag_cols = [c for c in a.columns if c.startswith("flag_")]
    a["excluir_da_analise"] = a[flag_cols].any(axis=1)
    return a, flag_cols


# ============================================================
# (ii) Concordância PCR vs LAMP — κ de Cohen com IC95%
# ============================================================
def cohen_kappa_with_ci(y1: pd.Series, y2: pd.Series, alpha: float = 0.05):
    """κ de Cohen com IC95% via fórmula assintótica (Fleiss 1969)."""
    s = pd.DataFrame({"a": y1.values, "b": y2.values}).dropna()
    if len(s) < 5:
        return dict(kappa=np.nan, ic_lower=np.nan, ic_upper=np.nan, n=len(s))

    s = s.astype(int)
    cats = sorted(set(s["a"].unique()) | set(s["b"].unique()))
    table = pd.crosstab(s["a"], s["b"]).reindex(index=cats, columns=cats, fill_value=0)
    n = table.values.sum()
    po = np.trace(table.values) / n
    pe = np.sum(table.sum(axis=0).values * table.sum(axis=1).values) / (n ** 2)
    if pe == 1:
        return dict(kappa=np.nan, ic_lower=np.nan, ic_upper=np.nan, n=int(n))
    kappa = (po - pe) / (1 - pe)
    se = np.sqrt(po * (1 - po) / (n * (1 - pe) ** 2))
    z = 1.959963984540054  # Φ⁻¹(0.975)
    return dict(
        kappa=round(float(kappa), 3),
        ic_lower=round(float(kappa - z * se), 3),
        ic_upper=round(float(kappa + z * se), 3),
        n=int(n),
    )


def divergencias_pcr_lamp(df_ac: pd.DataFrame) -> tuple[pd.DataFrame, dict]:
    """Compara PCR Babesia vs LAMP-18S e LAMP-CytB."""
    if df_ac.empty:
        return pd.DataFrame(), {}
    cols = ["pcr_babesia", "lamp_babesia_18s", "lamp_babesia_cytb"]
    d = df_ac.copy()
    for c in cols:
        if c not in d.columns:
            d[c] = 0
        d[c] = d[c].fillna(0).astype(int)

    d["divergente_18s"] = (d["pcr_babesia"] != d["lamp_babesia_18s"]).astype(int)
    d["divergente_cytb"] = (d["pcr_babesia"] != d["lamp_babesia_cytb"]).astype(int)
    d["divergente_18s_cytb"] = (d["lamp_babesia_18s"] != d["lamp_babesia_cytb"]).astype(int)

    # κ Cohen para os 3 pares
    kappas = {
        "PCR x LAMP-18S": cohen_kappa_with_ci(d["pcr_babesia"], d["lamp_babesia_18s"]),
        "PCR x LAMP-CytB": cohen_kappa_with_ci(d["pcr_babesia"], d["lamp_babesia_cytb"]),
        "LAMP-18S x LAMP-CytB": cohen_kappa_with_ci(d["lamp_babesia_18s"], d["lamp_babesia_cytb"]),
    }

    divergentes = d.loc[d[["divergente_18s", "divergente_cytb", "divergente_18s_cytb"]].any(axis=1)]
    keep_cols = [c for c in [
        "id", "codigo_animal", "data_coleta", "local_id",
        "pcr_babesia", "lamp_babesia_18s", "lamp_babesia_cytb",
        "divergente_18s", "divergente_cytb", "divergente_18s_cytb"
    ] if c in divergentes.columns]
    return divergentes[keep_cols].copy(), kappas


# ============================================================
# (iii) Outliers climáticos
# ============================================================
def outliers_clima(df_clima: pd.DataFrame, z_thresh: float = 3.0) -> pd.DataFrame:
    if df_clima.empty:
        return pd.DataFrame()
    cols_alvo = ["temperatura_media", "pluviometria_total", "umidade_media",
                 "temperatura_minima", "temperatura_maxima"]
    d = df_clima.copy()
    flags = []
    for c in cols_alvo:
        if c not in d.columns:
            continue
        x = pd.to_numeric(d[c], errors="coerce")
        mu, sd = x.mean(), x.std(ddof=0)
        if sd is None or np.isnan(sd) or sd == 0:
            continue
        d[f"z_{c}"] = (x - mu) / sd
        d[f"out_{c}"] = (d[f"z_{c}"].abs() > z_thresh).astype(int)
        flags.append(f"out_{c}")
    if not flags:
        return pd.DataFrame()
    d["outlier_qualquer"] = d[flags].any(axis=1).astype(int)
    return d.loc[d["outlier_qualquer"] == 1].copy()


# ============================================================
# (iv) Codebook.xlsx
# ============================================================
CODEBOOK = [
    # tabela, variavel, tipo, unidade, range, fonte, regra de validação
    ("animais", "id", "INT (PK)", "—", "1+", "auto-increment", "obrigatório"),
    ("animais", "identificacao", "VARCHAR(50)", "—", "—", "operador", "único por fazenda"),
    ("animais", "nome_animal", "VARCHAR(100)", "—", "—", "operador", "opcional"),
    ("animais", "local_id", "INT (FK locais)", "—", "1+", "operador", "obrigatório, FK válida"),
    ("animais", "raca", "VARCHAR(50)", "—", "{Girolando, Sentinela}", "operador", "padronizado para Girolando"),
    ("animais", "composicao_genetica", "VARCHAR(60)", "—", "fração HP/GIR ou GIR", "histórico", "preserva proporção mestiça"),
    ("animais", "idade_meses", "INT", "meses", "0–360", "operador", "≥0; 0 = recém-nascido"),
    ("animais", "sexo", "ENUM", "—", "{Macho, Fêmea}", "operador", "obrigatório"),
    ("animais", "data_nascimento", "DATE", "AAAA-MM-DD", "1990-01-01–hoje", "operador", "anterior a data_coleta"),
    ("animais", "peso_kg", "DECIMAL(6,2)", "kg", "20–1200", "operador", "razoabilidade bovina"),
    ("animais", "obito", "TINYINT", "0/1", "{0,1}", "operador", "1 = óbito registrado"),
    ("animais", "carrapaticida_pouron", "TINYINT", "0/1", "{0,1}", "operador", "Pour On <45 dias"),
    ("animais", "carrapaticida_pulverizacao", "TINYINT", "0/1", "{0,1}", "operador", "pulverização <45 dias"),
    ("animais", "carrapaticida_injetavel", "TINYINT", "0/1", "{0,1}", "operador", "injetável <45 dias"),
    ("animais", "simulacao", "TINYINT", "0/1", "{0,1}", "QA", "1 = excluído de análise"),

    ("locais", "nome", "VARCHAR(100)", "—", "—", "operador", "obrigatório"),
    ("locais", "municipio", "VARCHAR(100)", "—", "RJ", "operador", "obrigatório"),
    ("locais", "latitude", "DECIMAL(10,8)", "graus decimais", "-90 a 90", "GPS", "validar range"),
    ("locais", "longitude", "DECIMAL(11,8)", "graus decimais", "-180 a 180", "GPS", "validar range"),
    ("locais", "ativo", "TINYINT", "0/1", "{0,1}", "operador", "1 = em coleta"),

    ("coletas", "data_coleta", "DATE", "AAAA-MM-DD", "2025–2026", "operador", "trimestral"),
    ("coletas", "estacao", "ENUM", "—", "{Verão,Outono,Inverno,Primavera}", "derivado", "calculado de data_coleta"),
    ("coletas", "tipo_amostra", "ENUM", "—", "{Sangue, Soro, Tecido}", "operador", "default Sangue"),
    ("coletas", "volume_ml", "DECIMAL(5,2)", "mL", "0.5–50", "operador", "validar > 0"),
    ("coletas", "responsavel", "VARCHAR(100)", "—", "—", "operador", "rastreabilidade"),
    ("coletas", "temperatura_animal", "DECIMAL(4,2)", "°C", "35–43", "operador", "fisiológico bovino"),

    ("resultados_pcr", "babesia_bovis", "ENUM", "—", "{Positivo,Negativo,Inconclusivo}", "qPCR", "alvo gene 18S"),
    ("resultados_pcr", "babesia_bigemina", "ENUM", "—", "{Positivo,Negativo,Inconclusivo}", "qPCR", ""),
    ("resultados_pcr", "anaplasma_marginale", "ENUM", "—", "{Positivo,Negativo,Inconclusivo}", "qPCR", ""),
    ("resultados_pcr", "trypanosoma_vivax", "ENUM", "—", "{Positivo,Negativo,Inconclusivo}", "qPCR", ""),
    ("resultados_pcr", "ct_babesia", "DECIMAL(5,2)", "ciclos", "10–40", "qPCR", "Cycle threshold"),
    ("resultados_pcr", "ct_bigemina", "DECIMAL(5,2)", "ciclos", "10–40", "qPCR", ""),
    ("resultados_pcr", "ct_anaplasma", "DECIMAL(5,2)", "ciclos", "10–40", "qPCR", ""),
    ("resultados_pcr", "ct_trypanosoma", "DECIMAL(5,2)", "ciclos", "10–40", "qPCR", ""),
    ("resultados_pcr", "qualidade_dna", "ENUM", "—", "{Ótima,Boa,Regular,Ruim}", "espectrofotômetro", ""),
    ("resultados_pcr", "concentracao_dna", "DECIMAL(8,2)", "ng/μL", "5–500", "espectrofotômetro", "validar >0"),
    ("resultados_pcr", "amplificacao_16s_bovino", "TINYINT", "0/1", "{0,1}", "qPCR", "controle interno (deve ser 1)"),

    ("resultados_lamp", "babesia_18s_lamp", "ENUM", "—", "{Positivo,Negativo,Inconclusivo}", "PCR-LAMP", "alvo 18S B. bovis"),
    ("resultados_lamp", "babesia_cytb_lamp", "ENUM", "—", "{Positivo,Negativo,Inconclusivo}", "PCR-LAMP", "alvo CytB B. bovis"),
    ("resultados_lamp", "tempo_deteccao_18s_min", "INT", "min", "5–60", "PCR-LAMP", ""),
    ("resultados_lamp", "tempo_deteccao_cytb_min", "INT", "min", "5–60", "PCR-LAMP", ""),
    ("resultados_lamp", "intensidade_sinal_18s", "ENUM", "—", "{Forte,Médio,Fraco}", "operador", ""),
    ("resultados_lamp", "intensidade_sinal_cytb", "ENUM", "—", "{Forte,Médio,Fraco}", "operador", ""),
    ("resultados_lamp", "temperatura_reacao", "DECIMAL(4,2)", "°C", "60–68", "termobloco", "tipicamente 65"),
    ("resultados_lamp", "custo_teste", "DECIMAL(8,2)", "R$", "5–500", "operador", ""),

    ("dados_climaticos", "temperatura_media", "DECIMAL(5,2)", "°C", "0–45", "NASA POWER (T2M)", "média do período"),
    ("dados_climaticos", "temperatura_minima", "DECIMAL(5,2)", "°C", "-5–40", "NASA POWER", ""),
    ("dados_climaticos", "temperatura_maxima", "DECIMAL(5,2)", "°C", "5–50", "NASA POWER", ""),
    ("dados_climaticos", "pluviometria_total", "DECIMAL(8,2)", "mm", "0–2000", "NASA POWER (PRECTOTCORR)", "soma do período"),
    ("dados_climaticos", "pluviometria_media", "DECIMAL(8,2)", "mm/dia", "0–100", "derivado", ""),
    ("dados_climaticos", "umidade_media", "DECIMAL(5,2)", "%", "20–100", "NASA POWER (RH2M)", ""),
    ("dados_climaticos", "umidade_minima", "DECIMAL(5,2)", "%", "10–100", "NASA POWER", ""),
    ("dados_climaticos", "umidade_maxima", "DECIMAL(5,2)", "%", "20–100", "NASA POWER", ""),
    ("dados_climaticos", "fonte", "ENUM", "—", "{NASA_POWER,INMET,OPEN_METEO,manual,desconhecida}", "QA", "rastreabilidade"),
]


def gerar_codebook(path: Path) -> None:
    df = pd.DataFrame(CODEBOOK, columns=[
        "tabela", "variavel", "tipo", "unidade", "range_esperado", "fonte", "regra_validacao"
    ])
    with pd.ExcelWriter(path, engine="xlsxwriter") as wr:
        df.to_excel(wr, sheet_name="codebook", index=False)
        ws = wr.sheets["codebook"]
        wb = wr.book
        header = wb.add_format({"bold": True, "bg_color": "#1F4E78", "color": "white", "align": "left"})
        for col_idx, col in enumerate(df.columns):
            ws.write(0, col_idx, col, header)
            largura = max(12, min(40, df[col].astype(str).map(len).max() if not df.empty else 12))
            ws.set_column(col_idx, col_idx, largura)
        ws.freeze_panes(1, 0)
    log(f"Codebook escrito em {path}")


# ============================================================
# Relatório PDF (resumo executivo)
# ============================================================
def gerar_pdf(path: Path, totais: dict, sinteticos_n: int, kappas: dict,
              outliers_n: int, n_excluidos_por_motivo: dict, divergencias_n: int) -> None:
    from reportlab.lib.pagesizes import A4
    from reportlab.lib.styles import getSampleStyleSheet, ParagraphStyle
    from reportlab.lib.units import cm
    from reportlab.lib import colors
    from reportlab.platypus import (
        SimpleDocTemplate, Paragraph, Spacer, Table, TableStyle, PageBreak
    )

    doc = SimpleDocTemplate(str(path), pagesize=A4,
                            leftMargin=2*cm, rightMargin=2*cm,
                            topMargin=2*cm, bottomMargin=2*cm)
    styles = getSampleStyleSheet()
    styles.add(ParagraphStyle(name="H1", fontSize=16, leading=20, spaceAfter=12,
                              textColor=colors.HexColor("#1F4E78"), fontName="Helvetica-Bold"))
    styles.add(ParagraphStyle(name="H2", fontSize=12, leading=16, spaceAfter=8,
                              textColor=colors.HexColor("#1F4E78"), fontName="Helvetica-Bold"))
    body = styles["BodyText"]

    story = []
    story.append(Paragraph("Relatório de Quality Assurance — Diagnóstico TPB", styles["H1"]))
    story.append(Paragraph(f"Gerado em {datetime.now().strftime('%d/%m/%Y %H:%M')}", body))
    story.append(Spacer(1, 0.5*cm))

    story.append(Paragraph("1. Inventário de tabelas", styles["H2"]))
    t = [["Tabela", "Linhas"]] + [[k, v] for k, v in totais.items()]
    story.append(Table(t, hAlign="LEFT",
                       style=TableStyle([
                           ("BACKGROUND", (0,0), (-1,0), colors.HexColor("#1F4E78")),
                           ("TEXTCOLOR", (0,0), (-1,0), colors.white),
                           ("GRID", (0,0), (-1,-1), 0.4, colors.grey),
                           ("FONTNAME", (0,0), (-1,0), "Helvetica-Bold"),
                       ])))
    story.append(Spacer(1, 0.5*cm))

    story.append(Paragraph("2. Animais flaggeados como sintéticos / sentinelas", styles["H2"]))
    story.append(Paragraph(
        f"Total de animais marcados para exclusão da análise científica: <b>{sinteticos_n}</b>",
        body))
    if n_excluidos_por_motivo:
        t2 = [["Motivo", "Animais"]] + [[k, v] for k, v in n_excluidos_por_motivo.items()]
        story.append(Table(t2, hAlign="LEFT",
                           style=TableStyle([
                               ("BACKGROUND", (0,0), (-1,0), colors.HexColor("#1F4E78")),
                               ("TEXTCOLOR", (0,0), (-1,0), colors.white),
                               ("GRID", (0,0), (-1,-1), 0.4, colors.grey),
                           ])))
    story.append(Spacer(1, 0.5*cm))

    story.append(Paragraph("3. Concordância PCR vs LAMP (κ de Cohen, IC95%)", styles["H2"]))
    if kappas:
        rows = [["Comparação", "n", "κ", "IC95%"]]
        for nome, k in kappas.items():
            ic = f"[{k['ic_lower']}, {k['ic_upper']}]" if not pd.isna(k.get("kappa", np.nan)) else "—"
            rows.append([nome, k["n"], k.get("kappa", "—"), ic])
        story.append(Table(rows, hAlign="LEFT",
                           style=TableStyle([
                               ("BACKGROUND", (0,0), (-1,0), colors.HexColor("#1F4E78")),
                               ("TEXTCOLOR", (0,0), (-1,0), colors.white),
                               ("GRID", (0,0), (-1,-1), 0.4, colors.grey),
                           ])))
        story.append(Spacer(1, 0.3*cm))
        story.append(Paragraph(
            "<i>Interpretação Landis & Koch (1977): &lt;0 ruim, 0–0.20 leve, "
            "0.21–0.40 razoável, 0.41–0.60 moderada, 0.61–0.80 substancial, "
            "0.81–1.00 quase perfeita.</i>", body))
    else:
        story.append(Paragraph("Sem dados suficientes para κ.", body))
    story.append(Spacer(1, 0.5*cm))

    story.append(Paragraph(f"4. Divergências PCR×LAMP detectadas: <b>{divergencias_n}</b>", styles["H2"]))
    story.append(Paragraph(
        "Detalhe completo está em <b>flags_qa.xlsx</b>, aba <i>divergencias_pcr_lamp</i>.", body))
    story.append(Spacer(1, 0.5*cm))

    story.append(Paragraph(f"5. Outliers climáticos (z &gt; 3): <b>{outliers_n}</b>", styles["H2"]))
    story.append(Paragraph(
        "Detalhe em <b>flags_qa.xlsx</b>, aba <i>outliers_clima</i>.", body))
    story.append(Spacer(1, 0.5*cm))

    story.append(Paragraph("6. Próximos passos", styles["H2"]))
    story.append(Paragraph(textwrap.dedent("""
        • Aplicar <b>00_limpar_e_padronizar.sql</b> e <b>02_qa_db.sql</b> no banco DEV se ainda não fez.<br/>
        • Revisar <b>flags_qa.xlsx</b> com o coordenador antes de progredir para Fase 2 (logit).<br/>
        • Conferir o codebook (<b>codebook.xlsx</b>) com a equipe de bancada e ajustar regras se necessário.<br/>
        • Quando houver mais resultados PCR lançados, rodar este script novamente para acompanhar a evolução do κ.
    """), body))

    doc.build(story)
    log(f"PDF gerado em {path}")


# ============================================================
# Main
# ============================================================
def main() -> int:
    log("=" * 60)
    log("01_qa.py — Diagnóstico TPB | Fase 1 Quality Assurance")
    log("=" * 60)

    try:
        engine = get_engine()
    except Exception as e:
        log(f"FALHA ao conectar ao banco: {e}")
        log("Verifique se o Docker está rodando (docker compose up -d) e .env está preenchido.")
        return 1

    dfs = carregar_dados(engine)
    totais = {k: len(v) for k, v in dfs.items()}

    # (i)
    log("\n(i) Identificando animais sintéticos / sentinelas / fazendas fictícias…")
    flags_animais, flag_cols = identificar_sinteticos(dfs["animais"], dfs["locais"])
    sinteticos_n = int(flags_animais["excluir_da_analise"].sum())
    motivos = {c: int(flags_animais[c].sum()) for c in flag_cols}
    log(f"  → {sinteticos_n} animais marcados (motivos: {motivos})")

    # (ii)
    log("\n(ii) Avaliando concordância PCR×LAMP (κ Cohen)…")
    divergentes, kappas = divergencias_pcr_lamp(dfs["animais_completos"])
    log(f"  → {len(divergentes)} animais com divergência")
    for nome, k in kappas.items():
        log(f"  · {nome}: κ={k.get('kappa','—')} IC95%[{k.get('ic_lower','—')}, {k.get('ic_upper','—')}] n={k.get('n','—')}")

    # (iii)
    log("\n(iii) Detectando outliers climáticos (z > 3)…")
    outliers = outliers_clima(dfs["dados_climaticos"], z_thresh=3.0)
    log(f"  → {len(outliers)} outliers")

    # (iv)
    log("\n(iv) Gerando codebook.xlsx…")
    gerar_codebook(OUTPUT / "codebook.xlsx")

    # Salvar flags em Excel multi-aba
    flags_path = OUTPUT / "flags_qa.xlsx"
    with pd.ExcelWriter(flags_path, engine="xlsxwriter") as wr:
        flags_animais.to_excel(wr, sheet_name="animais_flaggeados", index=False)
        if not divergentes.empty:
            divergentes.to_excel(wr, sheet_name="divergencias_pcr_lamp", index=False)
        if not outliers.empty:
            outliers.to_excel(wr, sheet_name="outliers_clima", index=False)
        # Aba kappa
        if kappas:
            pd.DataFrame(kappas).T.reset_index(names="comparacao").to_excel(
                wr, sheet_name="kappa_cohen", index=False
            )
        # Aba inventário
        pd.DataFrame(totais.items(), columns=["tabela", "linhas"]).to_excel(
            wr, sheet_name="inventario", index=False
        )
    log(f"Flags exportados em {flags_path}")

    # PDF
    gerar_pdf(
        OUTPUT / "relatorio_qa.pdf",
        totais=totais,
        sinteticos_n=sinteticos_n,
        kappas=kappas,
        outliers_n=len(outliers),
        n_excluidos_por_motivo=motivos,
        divergencias_n=len(divergentes),
    )

    # Log
    log_path = OUTPUT / "log_qa.txt"
    log_path.write_text("\n".join(LOG_LINES), encoding="utf-8")
    log("Log salvo em %s" % log_path)
    log("Pipeline QA finalizado.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
