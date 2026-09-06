#!/usr/bin/env bash
# status.sh — read-only. Prints instance/RDS state, and (once the app is
# deployed in session 2) container health, queue pending/failed, chunk
# count, disk/RAM via SSH. Safe to run any time.
set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")"
source ./vars.sh

echo "=== EC2 ==="
aws ec2 describe-instances --instance-ids "$(cat "${LOCAL_STATE_DIR}/instance_id" 2>/dev/null || echo '')" \
  --query 'Reservations[0].Instances[0].{State:State.Name,Type:InstanceType,LaunchTime:LaunchTime}' --output table 2>/dev/null || echo "no instance recorded"

echo "=== Elastic IP ==="
cat "${LOCAL_STATE_DIR}/eip_address" 2>/dev/null || echo "none recorded"

echo "=== RDS ==="
aws rds describe-db-instances --db-instance-identifier "$NAME_RDS_INSTANCE" \
  --query 'DBInstances[0].{Status:DBInstanceStatus,Class:DBInstanceClass}' --output table 2>/dev/null || echo "no RDS instance"

echo "=== App stack (SSH — only meaningful after session 2's deploy) ==="
KEY_FILE="${LOCAL_STATE_DIR}/${NAME_EC2_KEYPAIR}.pem"
EIP=$(cat "${LOCAL_STATE_DIR}/eip_address" 2>/dev/null || echo "")
if [[ -n "$EIP" && -f "$KEY_FILE" ]]; then
  ssh -i "$KEY_FILE" -o StrictHostKeyChecking=accept-new -o ConnectTimeout=5 ubuntu@"$EIP" \
    "cd /opt/hr-staging 2>/dev/null && docker compose -f docker-compose.staging.yml ps 2>/dev/null || echo '(no docker-compose.staging.yml deployed yet — session 2)'; echo; df -h / ; free -m" 2>&1 || echo "(SSH not reachable right now)"
else
  echo "(no EIP/key recorded yet)"
fi
