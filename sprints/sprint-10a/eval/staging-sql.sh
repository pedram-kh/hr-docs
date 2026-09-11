#!/usr/bin/env bash
# staging-sql.sh — run a READ-ONLY SQL query against the staging DB and print
# JSON rows. Sprint 10a tooling: the fallback trigger, the Estatuto chunk
# assessment and both eval sets are all statements about real staging data, so
# the plan/review numbers have to be reproducible by whoever reads them.
#
# Runs inside the already-deployed hr-backend container (so no DB credential
# ever lands on a laptop): the container resolves the RDS password from SSM at
# call time using the EC2 instance profile, exactly as
# hr-docs/infra/compose/entrypoint.sh does at boot.
#
# Read-only by construction: DB::select() on a single statement. Do not extend
# this to writes — seeding goes through an artisan seeder, not through here.
#
# Usage:
#   ./staging-sql.sh <<'SQL'
#   select count(*) from documents
#   SQL
set -euo pipefail
source "$(dirname "${BASH_SOURCE[0]}")/../../../infra/vars.sh"
KEY="${LOCAL_STATE_DIR}/${NAME_EC2_KEYPAIR}.pem"
B64=$(base64 | tr -d '\n')

ssh -i "$KEY" -o StrictHostKeyChecking=accept-new -o ConnectTimeout=10 ubuntu@"${STAGING_EIP}" \
  "HRSQL='${B64}' SSM_PW='${SSM_RDS_MASTER_PASSWORD}' AWS_REGION='${AWS_REGION}' bash -s" <<'REMOTE' 2>&1 | grep -v 'level=warning'
set -euo pipefail
cd /opt/hr-staging
cat > /tmp/hrq.php <<'PHPEOF'
<?php
$rows = Illuminate\Support\Facades\DB::select(base64_decode(getenv('HRSQL')));
echo json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), PHP_EOL;
PHPEOF
docker compose -f docker-compose.staging.yml cp /tmp/hrq.php hr-backend:/tmp/hrq.php >/dev/null
docker compose -f docker-compose.staging.yml exec -T -e HRSQL="$HRSQL" hr-backend bash -c '
  export DB_PASSWORD=$(aws ssm get-parameter --name "'"$SSM_PW"'" --with-decryption --region "'"$AWS_REGION"'" --query Parameter.Value --output text)
  php artisan tinker /tmp/hrq.php
'
REMOTE
