#!/bin/sh
# CP-2 merge conditions (run INSIDE the hr-backend container, detached, ONE process at a time):
#   1. Estatuto gold evals, all six (3 profiles x classic|agent) x1
#   2. forced-lane harness after the F2 fix: lane negatives x3, lane positives x1 (test-gipuzkoa)
# Outputs land in /tmp/cp2c-*; COPY THEM OFF THE BOX before any redeploy (deploy recreates the container).
P=/tmp/cp2c-progress.log
E=/var/hr-docs/sprints/sprint-13/eval/probes
for p in positive negative second-negative; do
  for eng in classic agent; do
    echo "START estatuto-$p-$eng $(date -u +%FT%TZ)" >> $P
    php artisan estatuto:gold-eval --profile="$p" --engine="$eng" --json > "/tmp/cp2c-estatuto-$p-$eng.json" 2>&1
    echo "END estatuto-$p-$eng exit=$? $(date -u +%FT%TZ)" >> $P
  done
done
for r in 1 2 3; do
  echo "START lane-forced-neg-$r $(date -u +%FT%TZ)" >> $P
  LANE_EMAIL=test-gipuzkoa@example.com LANE_CLASS=lane_negative php artisan tinker --execute='include "/var/hr-docs/sprints/sprint-13/eval/probes/lane-forced.php";' > "/tmp/cp2c-lane-forced-neg-$r.log" 2>&1
  echo "END lane-forced-neg-$r $(date -u +%FT%TZ)" >> $P
done
echo "START lane-forced-pos-1 $(date -u +%FT%TZ)" >> $P
LANE_EMAIL=test-gipuzkoa@example.com LANE_CLASS=lane_positive php artisan tinker --execute='include "/var/hr-docs/sprints/sprint-13/eval/probes/lane-forced.php";' > /tmp/cp2c-lane-forced-pos-1.log 2>&1
echo "END lane-forced-pos-1 $(date -u +%FT%TZ)" >> $P
echo "CP2C DONE $(date -u +%FT%TZ)" >> $P
