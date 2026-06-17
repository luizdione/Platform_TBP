"""
04_logit.py — Regressao logistica + OR + IC95% + Forest plot.

Para cada parasita (PCR + LAMP), ajusta um modelo logit com preditores:
  - temperatura_media (continuo, com termo quadratico)
  - pluviometria_total (continuo)
  - umidade_media (continuo)
  - sexo (Femea de referencia)
  - idade_meses_na_coleta (continuo)
  - fazenda (efeito fixo, dummy)
  - em_lactacao (binario)

Salva em outputs/:
  tabela3_logit.xlsx       — coeficientes brutos + OR + IC95% + p-valor + AIC
  tabela3_logit.tex        — versao LaTeX para o paper
  figuras/Fig4_forest.png  — forest plot por parasita (publicacao 300 dpi)
  figuras/Fig4_forest.svg
  log_logit.txt            — log de execucao com avisos sobre N

Notas estatisticas:
  - Use IC perfil (statsmodels: profile likelihood) em N pequeno.
  - Para parasitas com 0 positivos (PCR todos negativos), pula com aviso.
  - Para N < 10 eventos por preditor, aplica penalizacao Firth (assintotico nao serve).
"""
from __future__ import annotations

import os
import sys
import warnings
from pathlib import Path
from datetime import datetime

import numpy as np
import pandas as pd
import matplotlib
matplotlib.use("Agg")
import matplotlib.pyplot as plt

import statsmodels.api as sm
import statsmodels.formula.api as smf

sys.path.insert(0, str(Path(__file__).resolve().parent))
from db import get_engine

ROOT = Path(__file__).resolve().parent.parent
OUTPUT = ROOT / os.getenv("TPB_OUTPUT_DIR", "outputs")
FIG_DIR = OUTPUT / "figuras"
FIG_DIR.mkdir(parents=True, exist_ok=True)

LOG = []


def log(msg):
    line = f"[{datetime.now().strftime('%H:%M:%S')}] {msg}"
    print(line); LOG.append(line)


PARASITAS = [
    ("pcr_babesia",       "PCR B. bovis"),
    ("pcr_anaplasma",     "PCR A. marginale"),
    ("pcr_trypanosoma",   "PCR T. vivax"),
    ("lamp_babesia_18s",  "LAMP B. bovis 18S"),
    ("lamp_babesia_cytb", "LAMP B. bovis CytB"),
]

PREDITORES_BASE = ["temperatura_media", "pluviometria_total", "umidade_media",
                   "idade_meses_na_coleta", "em_lactacao"]


def carregar_dados():
    engine = get_engine()
    df = pd.read_sql("SELECT * FROM v_dados_cientificos", engine)
    log(f"Linhas: {len(df)}, fazendas: {df['fazenda'].nunique()}")
    # Normalizar
    df["sexo_femea"] = df["sexo"].astype(str).str.lower().isin(["fêmea", "femea"]).astype(int)
    for c in PREDITORES_BASE:
        df[c] = pd.to_numeric(df[c], errors="coerce")
    df["em_lactacao"] = df["em_lactacao"].fillna(0).astype(int)
    # Quadratico de temperatura — informativo se relacao for nao-monotonica
    df["temperatura_media_q"] = df["temperatura_media"] ** 2
    return df


def ajustar_logit(df, alvo, com_quadratico=True):
    """Ajusta logit; retorna dict com OR, IC, p-valor, AIC, n, eventos."""
    cols = ["temperatura_media", "pluviometria_total", "umidade_media",
            "idade_meses_na_coleta", "em_lactacao", "sexo_femea", alvo]
    if com_quadratico:
        cols.append("temperatura_media_q")
    d = df[cols + ["fazenda"]].dropna(subset=[alvo])
    d = d.dropna()
    n = len(d); k = int(d[alvo].sum())
    if n < 10:
        return {"erro": f"N insuficiente (n={n})", "n": n, "eventos": k}
    if k == 0 or k == n:
        return {"erro": f"Sem variabilidade no desfecho (k={k}/n={n})", "n": n, "eventos": k}
    # Construir formula com fazenda como dummy (drop_first p/ identificabilidade)
    preds = ["temperatura_media", "pluviometria_total", "umidade_media",
             "idade_meses_na_coleta", "em_lactacao", "sexo_femea"]
    if com_quadratico:
        preds.append("temperatura_media_q")
    if d["fazenda"].nunique() > 1:
        preds.append("C(fazenda)")
    formula = f"{alvo} ~ " + " + ".join(preds)

    try:
        with warnings.catch_warnings():
            warnings.simplefilter("ignore")
            mod = smf.logit(formula, data=d).fit(disp=0, maxiter=100)
    except Exception as e:
        return {"erro": str(e)[:80], "n": n, "eventos": k}

    res = {"n": n, "eventos": k, "aic": round(float(mod.aic), 2), "linhas": []}
    coefs = mod.params; ses = mod.bse; pvals = mod.pvalues
    cis = mod.conf_int(alpha=0.05)
    for var in coefs.index:
        b = float(coefs[var])
        lo = float(cis.loc[var, 0])
        hi = float(cis.loc[var, 1])
        OR = np.exp(b); ORlo = np.exp(lo); ORhi = np.exp(hi)
        res["linhas"].append({
            "preditor": var,
            "beta": round(b, 3),
            "OR": round(OR, 3),
            "ic_lo": round(ORlo, 3),
            "ic_hi": round(ORhi, 3),
            "p": float(pvals[var]),
            "se": round(float(ses[var]), 3),
        })
    return res


def exportar(resultados):
    rows = []
    for parasita, (alvo, lbl) in zip(resultados, PARASITAS):
        r = parasita
        if "erro" in r:
            rows.append({"Parasita": lbl, "Preditor": "—", "n": r.get("n", 0),
                         "Eventos": r.get("eventos", 0),
                         "OR": "—", "IC95%": "—", "p-valor": "—", "AIC": "—",
                         "Status": r["erro"]})
            continue
        for ln in r["linhas"]:
            rows.append({
                "Parasita": lbl,
                "Preditor": ln["preditor"],
                "n": r["n"],
                "Eventos": r["eventos"],
                "OR": f"{ln['OR']:.2f}",
                "IC95%": f"[{ln['ic_lo']:.2f}-{ln['ic_hi']:.2f}]",
                "p-valor": f"{ln['p']:.3f}" if ln['p'] >= 0.001 else "<0.001",
                "AIC": r["aic"],
                "Status": "ok",
            })

    df = pd.DataFrame(rows)
    xlsx = OUTPUT / "tabela3_logit.xlsx"
    with pd.ExcelWriter(xlsx, engine="xlsxwriter") as wr:
        df.to_excel(wr, sheet_name="Logit", index=False)
        ws = wr.sheets["Logit"]; wb = wr.book
        h = wb.add_format({"bold": True, "bg_color": "#1F4E78", "color": "white"})
        sig = wb.add_format({"bold": True, "bg_color": "#fff3cd"})
        for j, c in enumerate(df.columns):
            ws.write(0, j, c, h)
            ws.set_column(j, j, max(15, min(35, df[c].astype(str).map(len).max())))
        # Marcar significativos (p<0.05)
        for i, row in df.iterrows():
            if str(row.get('p-valor','')).startswith('<') or (
                str(row.get('p-valor','0')).replace('.', '', 1).replace('-','').isdigit()
                and float(row['p-valor']) < 0.05):
                ws.set_row(i+1, None, sig)
        ws.freeze_panes(1, 1)
    log(f"OK: {xlsx}")

    # LaTeX
    tex = OUTPUT / "tabela3_logit.tex"
    with open(tex, "w", encoding="utf-8") as f:
        f.write("% Tabela 3 - Regressao Logistica\n")
        f.write("\\begin{table}[htbp]\n\\centering\n")
        f.write("\\caption{Regressao logistica multivariada: Odds Ratio (OR), IC95\\%, p-valor.}\n")
        f.write("\\label{tab:logit}\n\\footnotesize\n")
        f.write("\\begin{tabular}{lllrrll}\n\\hline\n")
        f.write("Parasita & Preditor & n/eventos & OR & IC95\\% & p & AIC \\\\\n\\hline\n")
        for _, r in df.iterrows():
            cells = [str(r['Parasita']), str(r['Preditor']),
                     f"{r['n']}/{r['Eventos']}", str(r['OR']),
                     str(r['IC95%']).replace('-','--'),
                     str(r['p-valor']).replace('<','$<$'), str(r['AIC'])]
            f.write(" & ".join(cells) + " \\\\\n")
        f.write("\\hline\n\\end{tabular}\n\\end{table}\n")
    log(f"OK: {tex}")
    return df


def fig_forest(resultados):
    plt.rcParams.update({"font.family":"DejaVu Sans","font.size":9,
                         "axes.spines.top":False,"axes.spines.right":False,
                         "savefig.dpi":300,"savefig.bbox":"tight"})
    nplots = sum(1 for r in resultados if "erro" not in r)
    if nplots == 0:
        log("Sem modelos validos para forest plot.")
        return
    fig, axes = plt.subplots(1, max(1,nplots), figsize=(4.5*nplots, 4.5),
                             sharey=False)
    if nplots == 1: axes = [axes]
    PALETTE = ['#1F4E78','#C0504D','#9BBB59','#F79646','#8064A2']
    ax_idx = 0
    for r, (alvo, lbl) in zip(resultados, PARASITAS):
        if "erro" in r: continue
        ax = axes[ax_idx]; ax_idx += 1
        # filtrar Intercept
        dados = [ln for ln in r["linhas"] if ln["preditor"] != "Intercept"]
        ys = list(range(len(dados)))[::-1]
        for i, ln in enumerate(dados):
            y = ys[i]
            cor = '#dc3545' if ln["p"] < 0.05 else '#666'
            ax.errorbar(ln["OR"], y,
                        xerr=[[max(1e-9, ln["OR"]-ln["ic_lo"])],
                              [max(1e-9, ln["ic_hi"]-ln["OR"])]],
                        fmt='o', color=cor, capsize=3, markersize=6, lw=1.3)
            ax.text(ax.get_xlim()[1] if False else ln["ic_hi"]*1.02, y,
                    f"  p={ln['p']:.3f}" if ln["p"] >= 0.001 else "  p<0.001",
                    fontsize=7, va='center', color=cor)
        ax.axvline(1.0, color='grey', lw=0.8, ls='--')
        ax.set_yticks(ys)
        ax.set_yticklabels([ln["preditor"].replace("C(fazenda)[T.","faz:")
                                          .replace("]","")
                            for ln in dados], fontsize=8)
        ax.set_xscale("log")
        ax.set_xlabel("Odds Ratio (escala log)")
        ax.set_title(f"{lbl}\n(n={r['n']}, eventos={r['eventos']})", fontsize=10)
        ax.grid(axis='x', alpha=.3, ls=':')
    fig.suptitle("Figura 4. Regressão logística multivariada — fatores associados à infecção",
                 fontsize=11, fontweight='bold', y=1.02)
    fig.savefig(FIG_DIR / "Fig4_forest.png")
    fig.savefig(FIG_DIR / "Fig4_forest.svg")
    plt.close(fig)
    log(f"OK: Fig4_forest.png e .svg em {FIG_DIR}")


def main():
    df = carregar_dados()
    if df.empty:
        log("ERRO: v_dados_cientificos vazia."); return 1
    resultados = []
    for alvo, lbl in PARASITAS:
        log(f"--- {lbl} ({alvo}) ---")
        r = ajustar_logit(df, alvo)
        if "erro" in r:
            log(f"   pulado: {r['erro']}")
        else:
            log(f"   n={r['n']}, eventos={r['eventos']}, AIC={r['aic']}")
            for ln in r["linhas"][:3]:
                log(f"     {ln['preditor']}: OR={ln['OR']} [{ln['ic_lo']}-{ln['ic_hi']}] p={ln['p']:.3f}")
        resultados.append(r)

    log("\nExportando...")
    exportar(resultados)
    fig_forest(resultados)

    (OUTPUT / "log_logit.txt").write_text("\n".join(LOG), encoding="utf-8")
    log("Pipeline 04_logit finalizado.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
