#!/usr/bin/env bash
# Runs Sprint13GoldenTraceTest against the deployed commit, in the DEPLOYED IMAGE's runtime, against a THROWAWAY Postgres
# (never the staging RDS: RefreshDatabase would wipe it). No staging env/secrets are passed to the test container.
set -euo pipefail
W=/tmp/hb-verify; sudo rm -rf $W; mkdir -p $W
git -C /opt/hr-staging/hr-backend archive HEAD | tar -x -C $W
echo "code under test: $(git -C /opt/hr-staging/hr-backend rev-parse HEAD)"
docker rm -f pg13verify >/dev/null 2>&1 || true
docker run -d --name pg13verify -e POSTGRES_USER=hr -e POSTGRES_PASSWORD=hr_secret -e POSTGRES_DB=hr_platform_test pgvector/pgvector:pg16 >/dev/null
for i in $(seq 1 30); do docker exec pg13verify pg_isready -U hr -d hr_platform_test >/dev/null 2>&1 && break; sleep 2; done
cp $W/.env.example $W/.env
docker run --rm -v $W:/app -w /app composer:2 install --no-interaction --no-progress --prefer-dist --ignore-platform-reqs -q
docker run --rm --network container:pg13verify -v $W:/var/www -w /var/www \
  -e APP_ENV=testing -e APP_KEY=base64:$(head -c32 /dev/urandom | base64) -e DB_CONNECTION=pgsql -e DB_HOST=127.0.0.1 -e DB_PORT=5432 -e DB_DATABASE=hr_platform_test -e DB_USERNAME=hr -e DB_PASSWORD=hr_secret \
  --entrypoint php hr-staging-hr-backend:latest artisan test --filter=Sprint13GoldenTraceTest
docker rm -f pg13verify >/dev/null
sudo rm -rf $W
