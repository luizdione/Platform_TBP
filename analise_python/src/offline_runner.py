"""offline_runner.py — Roda 01_qa.py contra o dump SQL via SQLite em memoria."""
from __future__ import annotations
import re
import sys
import sqlite3
import importlib.util
from pathlib import Path
from sqlalchemy import create_engine
from sqlalchemy.pool import StaticPool

ROOT = Path(__file__).resolve().parent.parent.parent
DUMP = ROOT / "u112348440_ifrj_crj_lgm.sql"


def adapt_create_table(stmt: str) -> str:
    # Ordem importa: substituicoes mais especificas primeiro.
    stmt = re.sub(r"ENGINE=\w+", "", stmt)
    stmt = re.sub(r"DEFAULT CHARSET=\S+", "", stmt)
    stmt = re.sub(r"COLLATE=\S+", "", stmt)
    stmt = re.sub(r"COLLATE \S+", "", stmt)
    stmt = re.sub(r"COMMENT '[^']*'", "", stmt)
    stmt = re.sub(r"AUTO_INCREMENT(=\d+)?", "", stmt, flags=re.IGNORECASE)

    # current_timestamp() ANTES de mexer em timestamp
    stmt = re.sub(r"current_timestamp\(\)", "CURRENT_TIMESTAMP", stmt, flags=re.IGNORECASE)

    # tipos com parametros
    stmt = re.sub(r"\benum\([^)]+\)", "TEXT", stmt, flags=re.IGNORECASE)
    stmt = re.sub(r"\btinyint\(\d+\)", "INTEGER", stmt, flags=re.IGNORECASE)
    stmt = re.sub(r"\bint\(\d+\)", "INTEGER", stmt, flags=re.IGNORECASE)
    stmt = re.sub(r"\bdecimal\(\d+,\s*\d+\)", "REAL", stmt, flags=re.IGNORECASE)
    stmt = re.sub(r"\bvarchar\(\d+\)", "TEXT", stmt, flags=re.IGNORECASE)

    # tipos sem parametros — usar word boundary RIGOROSO
    stmt = re.sub(r"\btext\b", "TEXT", stmt, flags=re.IGNORECASE)
    stmt = re.sub(r"(?<!_)\btimestamp\b", "TEXT", stmt, flags=re.IGNORECASE)
    stmt = re.sub(r"(?<!_)\bdate\b", "TEXT", stmt, flags=re.IGNORECASE)

    # Garantir que termina com ;
    s = stmt.rstrip()
    if not s.endswith(";"):
        s += ";"
    return s


def mysql_to_sqlite_dump(text: str) -> str:
    out_lines = []
    skip_block = False
    in_create = False
    create_buffer = []
    for raw in text.splitlines():
        line = raw
        if line.startswith("--") or line.startswith("/*!") or line.strip() == "":
            continue
        u = line.upper().strip()
        if u.startswith("SET ") or u.startswith("START TRANSACTION") or u.startswith("COMMIT"):
            continue
        if u.startswith("CREATE VIEW") or u.startswith("CREATE OR REPLACE"):
            skip_block = True
            continue
        if skip_block:
            if line.rstrip().endswith(";"):
                skip_block = False
            continue
        if u.startswith("ALTER TABLE") or u.startswith("DROP ") or u.startswith("LOCK ") or u.startswith("UNLOCK "):
            if not line.rstrip().endswith(";"):
                skip_block = True
            continue
        line = line.replace("`", "")

        if u.startswith("CREATE TABLE") or in_create:
            in_create = True
            create_buffer.append(line)
            # Detectar fim: linha que comeca com ')' (com possivel ' ENGINE=...') significa fim do CREATE
            stripped = line.strip()
            if stripped.startswith(")") or stripped.endswith(";"):
                stmt = adapt_create_table("\n".join(create_buffer))
                out_lines.append(stmt)
                create_buffer = []
                in_create = False
            continue

        out_lines.append(line)
    return "\n".join(out_lines)


def make_sqlite_engine():
    if not DUMP.exists():
        sys.exit("Dump nao encontrado: %s" % DUMP)
    text = DUMP.read_text(encoding="utf-8")
    sqlite_sql = mysql_to_sqlite_dump(text)
    con = sqlite3.connect(":memory:")
    cur = con.cursor()
    statements = []
    current = []
    for line in sqlite_sql.splitlines():
        current.append(line)
        if line.rstrip().endswith(";"):
            statements.append("\n".join(current))
            current = []
    if current and any(x.strip() for x in current):
        statements.append("\n".join(current))
    for stmt in statements:
        s = stmt.strip()
        if not s:
            continue
        try:
            cur.execute(s)
        except Exception as e:
            msg = str(e)[:90]
            preview = s[:90].replace("\n", " ")
            print("[skip] %s | %s" % (msg, preview))
    con.commit()
    engine = create_engine("sqlite://", creator=lambda: con, poolclass=StaticPool)
    return engine


def patch_db_module():
    sys.path.insert(0, str(Path(__file__).resolve().parent))
    import db
    engine = make_sqlite_engine()
    db.get_engine = lambda: engine
    return engine


def run_qa():
    spec = importlib.util.spec_from_file_location("qa_module", Path(__file__).resolve().parent / "01_qa.py")
    qa = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(qa)
    return qa.main()


if __name__ == "__main__":
    patch_db_module()
    sys.exit(run_qa())
