#!/usr/bin/env bash
set -euo pipefail

PROJECT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
CONFIG_PHP="${PROJECT_ROOT}/config/config.php"
OUT_SQL="${PROJECT_ROOT}/config/schema.sql"

if [[ ! -f "${CONFIG_PHP}" ]]; then
  echo "Missing ${CONFIG_PHP}. Create it from config.sample.php first."
  exit 1
fi

DB_HOST=$(php -r '$c = require "'"${CONFIG_PHP}"'"; echo $c["db"]["host"];')
DB_NAME=$(php -r '$c = require "'"${CONFIG_PHP}"'"; echo $c["db"]["name"];')
DB_USER=$(php -r '$c = require "'"${CONFIG_PHP}"'"; echo $c["db"]["user"];')
DB_PASS=$(php -r '$c = require "'"${CONFIG_PHP}"'"; echo $c["db"]["pass"];')

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