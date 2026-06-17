"""
03_prevalencia.py — Tabela de prevalencia com IC95% Wilson.

Gera:
  outputs/tabela2_prevalencia_fazenda.xlsx — fazenda x parasita
  outputs/tabela2_prevalencia_estacao.xlsx — estacao x parasita
  outputs/tabela2_prevalencia.tex          — versao LaTeX consolidada

Wilson score interval — recomendado para amostras pequenas e proporcoes
extremas (Wilson 1927). Robusto a 0% e 100%.
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


def wilson95(k, n):
    if n <= 0:
        return (0.0, 0.0, 0.0)
    p = k / n
    z = 1.959963984540054
    denom = 1 + z * z / n
    center = p + z * z / (2 * n)
    margin = z * np.sqrt((p * (1 - p) + z * z / (4 * n)) / n)
    return p, max(0.0, (center - margin) / denom), min(1.0, (center + margin) / denom)


def fmt_cell(k, n):
    p, lo, hi = wilson95(k, n)
    return f"{k}/{n} ({100*p:.1f}%) [{100*lo:.1f}–{100*hi:.1f}]"


PARASITAS = [
    ("pcr_babesia", "PCR B. bovis"),
    ("pcr_anaplasma", "PCR A. marginale"),
    ("pcr_trypanosoma", "PCR T. vivax"),
    ("lamp_babesia_18s", "LAMP 18S"),
    ("lamp_babesia_cytb", "LAMP CytB"),
]


def tabela_prev(df, grupo_col):
    out = []
    for grupo, g in df.groupby(grupo_col, dropna=False):
        if pd.isna(grupo):
            continue
        n = len(g)
        row = {grupo_col: grupo, "n": n}
        for col, lbl in PARASITAS:
            k = int(g[col].fillna(0).astype(int).sum()) if col in g.columns else 0
            row[lbl] = fmt_cell(k, n)
        out.append(row)
    return pd.DataFrame(out)


def exportar_xlsx(df, sheet, path):
    with pd.ExcelWriter(path, engine="xlsxwriter") as wr:
        df.to_excel(wr, sheet_name=sheet, index=False)
        ws = wr.sheets[sheet]
        wb = wr.book
        h = wb.add_format({"bold": True, "bg_color": "#1F4E78", "color": "white"})
        for j, col in enumerate(df.columns):
            ws.write(0, j, col, h)
            ws.set_column(j, j, max(20, min(35, df[col].astype(str).map(len).max())))
        ws.freeze_panes(1, 1)
    print(f"OK: {path}")


def exportar_latex(t_fazenda, t_estacao, path):
    with open(path, "w", encoding="utf-8") as f:
        f.write("% Tabela 2 - Prevalencia (IC95% Wilson)\n\n")
        for nome, df in [("fazenda", t_fazenda), ("estacao", t_estacao)]:
            f.write(f"% Por {nome}\n")
            f.write("\\begin{table}[htbp]\n")
            f.write("\\centering\n")
            f.write(f"\\caption{{Prevalencia por {nome} (\\% [IC95\\% Wilson]).}}\n")
            f.write(f"\\label{{tab:prev_{nome}}}\n")
            f.write("\\footnotesize\n")
            f.write("\\begin{tabular}{l" + "l" * (len(df.columns) - 1) + "}\n")
            f.write("\\hline\n")
            f.write(" & ".join(c.replace("%", "\\%").replace("_", "\\_") for c in df.columns) + " \\\\\n")
            f.write("\\hline\n")
            for _, row in df.iterrows():
                cells = [str(row[c]).replace("%", "\\%").replace("[", "[")
                                    .replace("–", "--") for c in df.columns]
                f.write(" & ".join(cells) + " \\\\\n")
            f.write("\\hline\n")
            f.write("\\end{tabular}\n")
            f.write("\\end{table}\n\n")
    print(f"OK: {path}")


def main():
    engine = get_engine()
    df = pd.read_sql("SELECT * FROM v_dados_cientificos", engine)
    if df.empty:
        print("ERRO: v_dados_cientificos vazia.")
        return 1
    print(f"N={len(df)} coletas, {df['fazenda'].nunique()} fazendas, {df['estacao'].nunique()} estacoes")

    t_faz = tabela_prev(df, "fazenda")
    t_est = tabela_prev(df, "estacao")

    print("\n=== Por fazenda ===")
    print(t_faz.to_string(index=False))
    print("\n=== Por estacao ===")
    print(t_est.to_string(index=False))

    exportar_xlsx(t_faz, "Por_fazenda", OUTPUT / "tabela2_prevalencia_fazenda.xlsx")
    exportar_xlsx(t_est, "Por_estacao", OUTPUT / "tabela2_prevalencia_estacao.xlsx")
    exportar_latex(t_faz, t_est, OUTPUT / "tabela2_prevalencia.tex")
    return 0


if __name__ == "__main__":
    sys.exit(main())
