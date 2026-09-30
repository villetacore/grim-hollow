#!/usr/bin/env sh
set -eu
cd "$(dirname "$0")/.."
if [ ! -f infra/compose/.env ]; then
  umask 077
  printf 'APP_KEY=base64:%s\nDB_PASSWORD=%s\nDB_ROOT_PASSWORD=%s\n' \
    "$(openssl rand -base64 32)" "$(openssl rand -hex 32)" "$(openssl rand -hex 32)" > infra/compose/.env
fi
docker compose --env-file infra/compose/.env -f infra/compose/dev.yaml up --build -d api world
