"""
db.py — conexão MySQL/MariaDB para o pipeline TPB.

Suporta dois modos:
  - local: conecta direto ao container Docker (porta 3307).
  - ssh:   abre um túnel SSH para o Hostinger e conecta via 127.0.0.1.

Uso:
    from db import get_engine
    engine = get_engine()
    df = pd.read_sql('SELECT * FROM v_dados_cientificos', engine)
"""
from __future__ import annotations
import os
from contextlib import contextmanager
from pathlib import Path

from dotenv import load_dotenv
from sqlalchemy import create_engine

# Carrega .env do diretório analise_python/ (pasta-mãe deste arquivo)
ROOT = Path(__file__).resolve().parent.parent
load_dotenv(ROOT / ".env")

MODE = os.getenv("TPB_DB_MODE", "local").lower()


def _engine_local():
    host = os.getenv("TPB_DB_HOST", "127.0.0.1")
    port = int(os.getenv("TPB_DB_PORT", "3307"))
    name = os.getenv("TPB_DB_NAME", "u112348440_ifrj_crj_lgm")
    user = os.getenv("TPB_DB_USER", "tpb_dev")
    pwd  = os.getenv("TPB_DB_PWD",  "tpb_dev_pwd")
    url  = f"mysql+pymysql://{user}:{pwd}@{host}:{port}/{name}?charset=utf8mb4"
    return create_engine(url, pool_pre_ping=True)


@contextmanager
def _ssh_tunnel():
    """Abre túnel SSH para Hostinger e cede engine; fecha tudo no exit."""
    from sshtunnel import SSHTunnelForwarder
    ssh_host = os.getenv("HOSTINGER_SSH_HOST")
    ssh_port = int(os.getenv("HOSTINGER_SSH_PORT", "65002"))
    ssh_user = os.getenv("HOSTINGER_SSH_USER")
    ssh_key  = os.path.expanduser(os.getenv("HOSTINGER_SSH_KEY", "~/.ssh/id_rsa"))
    db_host  = os.getenv("HOSTINGER_DB_HOST", "127.0.0.1")
    db_port  = int(os.getenv("HOSTINGER_DB_PORT", "3306"))
    db_name  = os.getenv("HOSTINGER_DB_NAME")
    db_user  = os.getenv("HOSTINGER_DB_USER")
    db_pwd   = os.getenv("HOSTINGER_DB_PWD")

    with SSHTunnelForwarder(
        (ssh_host, ssh_port),
        ssh_username=ssh_user,
        ssh_pkey=ssh_key,
        remote_bind_address=(db_host, db_port),
    ) as tunnel:
        url = (
            f"mysql+pymysql://{db_user}:{db_pwd}"
            f"@127.0.0.1:{tunnel.local_bind_port}/{db_name}?charset=utf8mb4"
        )
        engine = create_engine(url, pool_pre_ping=True)
        try:
            yield engine
        finally:
            engine.dispose()


def get_engine():
    """Retorna engine do modo configurado em .env (TPB_DB_MODE)."""
    if MODE == "ssh":
        # Para SSH, prefira: ``with get_ssh_engine() as engine: ...``
        raise RuntimeError(
            "Modo SSH ativo. Use:  with db.ssh_engine() as engine: ..."
        )
    return _engine_local()


def ssh_engine():
    """Context manager para uso em modo SSH."""
    return _ssh_tunnel()


def is_ssh_mode() -> bool:
    return MODE == "ssh"
