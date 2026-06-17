"""
05_figuras.py — Figuras publication-ready (300 dpi PNG + SVG).

Gera em outputs/figuras/:
  Fig1_mapa_fazendas.{png,svg}      — fazendas no mapa do RJ
  Fig2_prevalencia_forest.{png,svg} — barras com IC95% Wilson por fazenda
  Fig3_sazonal.{png,svg}            — prevalencia ao longo das estacoes
  FigS1_concordancia.{png,svg}      — heatmap PCR x LAMP

Estilo: paleta colorblind do seaborn, Arial 9pt corpo / 11pt titulos,
sem grid pesado, axes spines simplificados — pronto para Frontiers,
Vet Parasitology, PLOS NTDs, Parasites & Vectors.
"""
from __future__ import annotations
import os
import sys
from pathlib import Path

import numpy as np
import pandas as pd
import matplotlib
matplotlib.use("Agg")
import matplotlib.pyplot as plt
import seaborn as sns

sys.path.insert(0, str(Path(__file__).resolve().parent))
from db import get_engine

ROOT = Path(__file__).resolve().parent.parent
OUTPUT = ROOT / os.getenv("TPB_OUTPUT_DIR", "outputs")
FIG_DIR = OUTPUT / "figuras"
FIG_DIR.mkdir(parents=True, exist_ok=True)

# ============== Estilo publication ==============
plt.rcParams.update({
    "font.family": "DejaVu Sans",  # Arial substitute — sempre disponivel
    "font.size": 9,
    "axes.titlesize": 11,
    "axes.labelsize": 9,
    "axes.spines.top": False,
    "axes.spines.right": False,
    "xtick.labelsize": 8,
    "ytick.labelsize": 8,
    "legend.fontsize": 8,
    "figure.dpi": 100,
    "savefig.dpi": 300,
    "savefig.bbox": "tight",
})
PALETTE = sns.color_palette("colorblind")


def wilson95(k, n):
    if n <= 0:
        return 0.0, 0.0, 0.0
    p = k / n
    z = 1.959963984540054
    denom = 1 + z * z / n
    center = p + z * z / (2 * n)
    margin = z * np.sqrt((p * (1 - p) + z * z / (4 * n)) / n)
    return p, max(0.0, (center - margin) / denom), min(1.0, (center + margin) / denom)


def save_both(fig, basename):
    fig.savefig(FIG_DIR / f"{basename}.png")
    fig.savefig(FIG_DIR / f"{basename}.svg")
    plt.close(fig)
    print(f"OK: {basename}.png e .svg")


# ============== Figuras ==============
PARASITAS = [
    ("pcr_babesia", "PCR\nB. bovis"),
    ("pcr_anaplasma", "PCR\nA. marginale"),
    ("pcr_trypanosoma", "PCR\nT. vivax"),
    ("lamp_babesia_18s", "LAMP\n18S"),
    ("lamp_babesia_cytb", "LAMP\nCytB"),
]


def fig1_mapa(df_locais):
    """Mapa simples scatter das fazendas com tamanho proporcional ao N."""
    fig, ax = plt.subplots(figsize=(6, 5))
    if df_locais.empty:
        ax.text(0.5, 0.5, "Sem dados de fazendas", ha="center", va="center")
        save_both(fig, "Fig1_mapa_fazendas")
        return

    # Plotar pontos
    sizes = df_locais["n_animais"] * 30 + 80
    ax.scatter(df_locais["longitude"], df_locais["latitude"],
               s=sizes, c=PALETTE[0], alpha=0.7, edgecolors="black", linewidth=1.2)

    # Labels nas fazendas
    for _, r in df_locais.iterrows():
        ax.annotate(f"{r['fazenda']}\n(n={r['n_animais']})",
                    (r["longitude"], r["latitude"]),
                    xytext=(8, 5), textcoords="offset points",
                    fontsize=8, fontweight="bold")

    ax.set_xlabel("Longitude")
    ax.set_ylabel("Latitude")
    ax.set_title("Figura 1. Localização das fazendas estudadas no estado do Rio de Janeiro")
    ax.grid(True, alpha=0.3, linestyle=":")
    ax.set_aspect("equal", adjustable="datalim")
    save_both(fig, "Fig1_mapa_fazendas")


def fig2_forest(df):
    """Forest plot: prevalencia por fazenda x parasita com IC95% Wilson."""
    fazendas = sorted(df["fazenda"].dropna().unique())
    fig, axes = plt.subplots(1, len(fazendas), figsize=(3 * len(fazendas), 4.5),
                             sharey=True)
    if len(fazendas) == 1:
        axes = [axes]

    for ax, faz in zip(axes, fazendas):
        g = df[df["fazenda"] == faz]
        n = len(g)
        ys = list(range(len(PARASITAS)))[::-1]
        for i, (col, lbl) in enumerate(PARASITAS):
            if col not in g.columns:
                continue
            k = int(g[col].fillna(0).astype(int).sum())
            p, lo, hi = wilson95(k, n)
            y = ys[i]
            err_lo = max(0.0, (p - lo) * 100)
            err_hi = max(0.0, (hi - p) * 100)
            ax.errorbar(p * 100, y,
                        xerr=[[err_lo], [err_hi]],
                        fmt="o", color=PALETTE[i % len(PALETTE)],
                        capsize=4, markersize=8, linewidth=1.5)
            ax.text(p * 100 + 1, y, f" {k}/{n}", va="center", fontsize=7, color="#444")

        ax.set_yticks(ys)
        ax.set_yticklabels([lbl for _, lbl in PARASITAS])
        ax.set_xlim(-3, 105)
        ax.set_xlabel("Prevalência (%)")
        ax.set_title(f"{faz}\n(n={n})", fontsize=10)
        ax.axvline(0, color="grey", linewidth=0.5)
        ax.grid(axis="x", alpha=0.3, linestyle=":")

    fig.suptitle("Figura 2. Prevalência por fazenda e parasita (IC 95% Wilson)",
                 fontsize=11, fontweight="bold", y=1.02)
    save_both(fig, "Fig2_prevalencia_forest")


def fig3_sazonal(df):
    estacoes_ord = ["Verão", "Outono", "Inverno", "Primavera"]
    df = df.copy()
    df["estacao"] = pd.Categorical(df["estacao"], categories=estacoes_ord, ordered=True)

    fig, ax = plt.subplots(figsize=(7, 4.5))
    for i, (col, lbl) in enumerate(PARASITAS):
        if col not in df.columns:
            continue
        ys, los, his, ns = [], [], [], []
        for est in estacoes_ord:
            g = df[df["estacao"] == est]
            n = len(g)
            k = int(g[col].fillna(0).astype(int).sum()) if n else 0
            p, lo, hi = wilson95(k, n)
            ys.append(p * 100); los.append(lo * 100); his.append(hi * 100); ns.append(n)
        ax.plot(estacoes_ord, ys, "-o", color=PALETTE[i % len(PALETTE)],
                label=lbl.replace("\n", " "), linewidth=1.6, markersize=6)
        ax.fill_between(estacoes_ord, los, his, color=PALETTE[i % len(PALETTE)], alpha=0.15)

    ax.set_ylabel("Prevalência (%)")
    ax.set_xlabel("Estação")
    ax.set_ylim(-2, 105)
    ax.set_title("Figura 3. Prevalência sazonal (linhas com banda IC95% Wilson)")
    ax.legend(loc="upper left", bbox_to_anchor=(1.02, 1), borderaxespad=0)
    ax.grid(axis="y", alpha=0.3, linestyle=":")
    save_both(fig, "Fig3_sazonal")


def fig_s1_concordancia(df):
    """Heatmap concordancia 2x2 PCR vs LAMP-18S e LAMP-CytB."""
    fig, axes = plt.subplots(1, 2, figsize=(7, 3.5))
    pares = [
        ("pcr_babesia", "lamp_babesia_18s", "PCR vs LAMP-18S"),
        ("pcr_babesia", "lamp_babesia_cytb", "PCR vs LAMP-CytB"),
    ]
    for ax, (a, b, titulo) in zip(axes, pares):
        if a not in df.columns or b not in df.columns:
            ax.set_title(titulo + "\n(sem dados)")
            continue
        d = df[[a, b]].dropna().astype(int)
        ct = pd.crosstab(d[a], d[b]).reindex(index=[0, 1], columns=[0, 1], fill_value=0)
        sns.heatmap(ct, annot=True, fmt="d", cmap="Blues", cbar=False, ax=ax,
                    xticklabels=["LAMP-", "LAMP+"], yticklabels=["PCR-", "PCR+"])
        ax.set_title(titulo, fontsize=10)
        ax.set_xlabel(""); ax.set_ylabel("")
    fig.suptitle("Figura S1. Concordância PCR vs PCR-LAMP (B. bovis)",
                 fontsize=11, fontweight="bold", y=1.02)
    save_both(fig, "FigS1_concordancia")


def main():
    engine = get_engine()
    df = pd.read_sql("SELECT * FROM v_dados_cientificos", engine)
    if df.empty:
        print("ERRO: v_dados_cientificos vazia.")
        return 1
    print(f"N={len(df)} coletas")

    # Locais agregados para Fig1
    locais = df.groupby(["fazenda", "latitude", "longitude"]).size().reset_index(name="n_animais")
    locais = locais.dropna(subset=["latitude", "longitude"])

    fig1_mapa(locais)
    fig2_forest(df)
    fig3_sazonal(df)
    fig_s1_concordancia(df)

    print(f"\nFiguras salvas em: {FIG_DIR}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
