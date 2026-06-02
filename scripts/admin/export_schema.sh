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

TMP_SQL="$(mktemp)"
cleanup() {
  rm -f "${TMP_SQL}"
}
trap cleanup EXIT

MYSQL_PWD="${DB_PASS}" mysqldump \
  --host="${DB_HOST}" \
  --user="${DB_USER}" \
  --no-tablespaces \
  --no-data \
  --skip-comments \
  --single-transaction \
  "${DB_NAME}" > "${TMP_SQL}"

unset MYSQL_PWD

# Keep schema.sql deterministic and portable:
# - remove table-level AUTO_INCREMENT counters, which are live-data artefacts
# - remove machine/user-specific DEFINER clauses from dumped views
#
# Important: this does not remove column-level AUTO_INCREMENT definitions.
perl -0pi -e 's/ AUTO_INCREMENT=\d+//g' "${TMP_SQL}"
perl -0pi -e 's{/\*!\d{5}\s+DEFINER=`[^`]+`@`[^`]+`\s+SQL SECURITY DEFINER\s+\*/\n?}{}g' "${TMP_SQL}"

if grep -qE ' AUTO_INCREMENT=[0-9]+' "${TMP_SQL}"; then
  echo "ERROR: schema export still contains table-level AUTO_INCREMENT values."
  exit 1
fi

if grep -qE 'DEFINER=`[^`]+`@`[^`]+' "${TMP_SQL}"; then
  echo "ERROR: schema export still contains environment-specific DEFINER clauses."
  exit 1
fi

mv "${TMP_SQL}" "${OUT_SQL}"

echo "Exported normalised schema to: ${OUT_SQL}"
