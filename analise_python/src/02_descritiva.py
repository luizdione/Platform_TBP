"""
02_descritiva.py — Tabela 1 cientifica (caracterizacao da amostra).

Le v_dados_cientificos e gera:
  outputs/tabela1_caracterizacao.xlsx — formato pronto para paper
  outputs/tabela1_caracterizacao.tex  — LaTeX para Materials & Methods

Estratificado por fazenda. Reporta n, %, mediana (IQR), media (DP).
"""
from __future__ import annotations
import os
import sys
from pathlib import Path

import numpy as np
import pandas as pd

sys.path.insert(0, str(Path(__file__).resolve().parent))
from db import get_engine

ROOT = Path(__file__).resolve().parent.parent
OUTPUT = ROOT / os.getenv("TPB_OUTPUT_DIR", "outputs")
OUTPUT.mkdir(parents=True, exist_ok=True)


def carregar_dados():
    engine = get_engine()
    df = pd.read_sql("SELECT * FROM v_dados_cientificos", engine)
    print(f"Linhas: {len(df)}  |  Fazendas: {df['fazenda'].nunique()}  |  Animais unicos: {df['codigo_animal'].nunique()}")
    return df


def linha_resumo(df, var, fmt="n_pct"):
    """Retorna dict {fazenda: 'n (%)'} ou {fazenda: 'mediana [IQR]'}."""
    out = {}
    for faz, g in df.groupby("fazenda"):
        n_total = len(g)
        if fmt == "n_pct":
            n = int(g[var].fillna(0).astype(int).sum())
            pct = 100 * n / n_total if n_total else 0
            out[faz] = f"{n} ({pct:.1f}%)"
        elif fmt == "median_iqr":
            x = pd.to_numeric(g[var], errors="coerce").dropna()
            if len(x) == 0:
                out[faz] = "—"
            else:
                med = x.median()
                q1, q3 = x.quantile([0.25, 0.75])
                out[faz] = f"{med:.1f} [{q1:.1f}–{q3:.1f}]"
        elif fmt == "mean_sd":
            x = pd.to_numeric(g[var], errors="coerce").dropna()
            if len(x) == 0:
                out[faz] = "—"
            else:
                out[faz] = f"{x.mean():.1f} ± {x.std():.1f}"
    out["TOTAL"] = ""
    if fmt == "n_pct":
        n = int(df[var].fillna(0).astype(int).sum())
        out["TOTAL"] = f"{n} ({100*n/len(df):.1f}%)" if len(df) else "0"
    elif fmt == "median_iqr":
        x = pd.to_numeric(df[var], errors="coerce").dropna()
        if len(x):
            med = x.median(); q1, q3 = x.quantile([0.25, 0.75])
            out["TOTAL"] = f"{med:.1f} [{q1:.1f}–{q3:.1f}]"
    elif fmt == "mean_sd":
        x = pd.to_numeric(df[var], errors="coerce").dropna()
        if len(x):
            out["TOTAL"] = f"{x.mean():.1f} ± {x.std():.1f}"
    return out


def montar_tabela1(df):
    df = df.copy()
    # Normalizar sexo (banco tem 'Femea' ou 'Fêmea')
    df["femea"] = df["sexo"].astype(str).str.lower().isin(["fêmea", "femea"]).astype(int)
    df["macho"] = (df["femea"] == 0).astype(int)

    # Carrapaticidas (qualquer um nos ultimos 45 dias)
    for c in ["carrapaticida_pouron", "carrapaticida_pulverizacao", "carrapaticida_injetavel"]:
        if c not in df.columns:
            df[c] = 0
    df["carrapaticida_qualquer"] = (
        df[["carrapaticida_pouron", "carrapaticida_pulverizacao", "carrapaticida_injetavel"]]
        .fillna(0).astype(int).sum(axis=1) > 0
    ).astype(int)

    # Construir Tabela 1
    fazendas = sorted(df["fazenda"].dropna().unique())
    cols = list(fazendas) + ["TOTAL"]

    rows = []
    rows.append(("n (coletas)", {f: str(len(df[df["fazenda"] == f])) for f in fazendas} | {"TOTAL": str(len(df))}))
    rows.append(("Animais únicos", {f: str(df[df["fazenda"] == f]["codigo_animal"].nunique()) for f in fazendas}
                                   | {"TOTAL": str(df["codigo_animal"].nunique())}))
    rows.append(("Fêmeas, n (%)", linha_resumo(df, "femea")))
    rows.append(("Em lactação, n (%)", linha_resumo(df, "em_lactacao")))
    rows.append(("Idade na coleta (meses), mediana [IQR]", linha_resumo(df, "idade_meses_na_coleta", "median_iqr")))
    rows.append(("Carrapaticida (qualquer), n (%)", linha_resumo(df, "carrapaticida_qualquer")))
    rows.append(("Coletas com clima registrado, n (%)",
                 linha_resumo(df.assign(tem_clima=df["temperatura_media"].notna().astype(int)), "tem_clima")))
    rows.append(("Temperatura média (°C), média ± DP",
                 linha_resumo(df, "temperatura_media", "mean_sd")))
    rows.append(("Pluviometria total (mm), mediana [IQR]",
                 linha_resumo(df, "pluviometria_total", "median_iqr")))
    rows.append(("Umidade média (%), média ± DP",
                 linha_resumo(df, "umidade_media", "mean_sd")))

    tabela = pd.DataFrame([{"Característica": k, **v} for k, v in rows], columns=["Característica"] + cols)
    return tabela


def exportar(tabela: pd.DataFrame):
    # XLSX
    xlsx_path = OUTPUT / "tabela1_caracterizacao.xlsx"
    with pd.ExcelWriter(xlsx_path, engine="xlsxwriter") as wr:
        tabela.to_excel(wr, sheet_name="Tabela1", index=False)
        ws = wr.sheets["Tabela1"]; wb = wr.book
        h = wb.add_format({"bold": True, "bg_color": "#1F4E78", "color": "white", "align": "left"})
        for j, col in enumerate(tabela.columns):
            ws.write(0, j, col, h)
            largura = max(15, min(35, tabela[col].astype(str).map(len).max()))
            ws.set_column(j, j, largura)
        ws.freeze_panes(1, 1)
    print(f"OK: {xlsx_path}")

    # LaTeX
    tex_path = OUTPUT / "tabela1_caracterizacao.tex"
    with open(tex_path, "w", encoding="utf-8") as f:
        f.write("% Tabela 1 - Caracterizacao da amostra\n")
        f.write("% Gerado por 02_descritiva.py\n")
        f.write("\\begin{table}[htbp]\n")
        f.write("\\centering\n")
        f.write("\\caption{Caracteristicas da amostra estudada, estratificadas por fazenda.}\n")
        f.write("\\label{tab:caracterizacao}\n")
        f.write("\\begin{tabular}{l" + "r" * (len(tabela.columns) - 1) + "}\n")
        f.write("\\hline\n")
        f.write(" & ".join(tabela.columns) + " \\\\\n")
        f.write("\\hline\n")
        for _, row in tabela.iterrows():
            cells = [str(row[c]).replace("±", "$\\pm$").replace("%", "\\%") for c in tabela.columns]
            f.write(" & ".join(cells) + " \\\\\n")
        f.write("\\hline\n")
        f.write("\\end{tabular}\n")
        f.write("\\end{table}\n")
    print(f"OK: {tex_path}")


def main():
    df = carregar_dados()
    if df.empty:
        print("ERRO: v_dados_cientificos vazia. Rode aplicar_migracoes.bat antes.")
        return 1
    tabela = montar_tabela1(df)
    print("\n=== Tabela 1 ===")
    print(tabela.to_string(index=False))
    exportar(tabela)
    return 0


if __name__ == "__main__":
    sys.exit(main())
