from pathlib import Path
import os


def _repo_root() -> Path:
    return Path(__file__).resolve().parents[1]


def load_dotenv(env_path: Path | None = None) -> None:
    env_path = env_path or (_repo_root() / ".env")
    if not env_path.is_file():
        return

    for raw_line in env_path.read_text(encoding="utf-8").splitlines():
        line = raw_line.strip()

        if not line or line.startswith("#"):
            continue

        if line.startswith("export "):
            line = line[7:].strip()

        if "=" not in line:
            continue

        key, value = line.split("=", 1)
        key = key.strip()
        value = value.strip()

        if not key:
            continue

        if key in os.environ:
            continue

        if len(value) >= 2 and (
            (value[0] == '"' and value[-1] == '"') or
            (value[0] == "'" and value[-1] == "'")
        ):
            value = value[1:-1]

        os.environ[key] = value


def get_db_config() -> dict:
    load_dotenv()

    host = os.getenv("FINANCE_DB_HOST", "localhost")
    user = os.getenv("FINANCE_DB_USER", "john")
    database = os.getenv("FINANCE_DB_NAME", "accounts")
    charset = os.getenv("FINANCE_DB_CHARSET", "utf8mb4")
    password = os.getenv("FINANCE_DB_PASSWORD")

    if password is None or password == "":
        raise RuntimeError(
            "Missing FINANCE_DB_PASSWORD. Set it in /.env or the process environment."
        )

    return {
        "host": host,
        "user": user,
        "password": password,
        "database": database,
        "charset": charset,
    }
