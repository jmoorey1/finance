#!/usr/bin/env bash
set -euo pipefail

PROJECT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
ENV_FILE="${PROJECT_ROOT}/.env"
OUT_SQL="${PROJECT_ROOT}/config/schema.sql"

if [[ ! -f "${ENV_FILE}" ]]; then
  echo "Missing ${ENV_FILE}. Create it from .env.example first."
  exit 1
fi

set -a
# shellcheck disable=SC1090
source "${ENV_FILE}"
set +a

DB_HOST="${FINANCE_DB_HOST:-localhost}"
DB_NAME="${FINANCE_DB_NAME:-accounts}"
DB_USER="${FINANCE_DB_USER:-john}"
DB_PASS="${FINANCE_DB_PASSWORD:-}"

if [[ -z "${DB_PASS}" ]]; then
  echo "Missing FINANCE_DB_PASSWORD in ${ENV_FILE}."
  exit 1
fi

MYSQL_PWD="${DB_PASS}" mysqldump \
  --host="${DB_HOST}" \
  --user="${DB_USER}" \
  --no-tablespaces \
  --no-data \
  --skip-comments \
  --single-transaction \
  "${DB_NAME}" > "${OUT_SQL}"

unset MYSQL_PWD

echo "Exported schema to: ${OUT_SQL}"
