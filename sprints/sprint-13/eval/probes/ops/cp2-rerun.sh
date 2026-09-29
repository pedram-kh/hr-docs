#!/bin/sh
# CP-2 re-runs: SEQUENTIAL, nothing in parallel, agent only unless stated. Aborts (writes ABORT to the progress log)
# if cumulative spend on these streams would exceed the $40 budget. usage: cp2-rerun.sh
E=/var/hr-docs/sprints/sprint-13/eval
P=/tmp/cp2-progress.log
BUDGET=40
spent() { python3 - <<'PY'
import glob,json
t=0.0
for f in glob.glob("/tmp/cp2-*.jsonl"):
    for l in open(f):
        try: t+=json.loads(l).get("cost_usd") or 0
        except Exception: pass
print(round(t,2))
PY
}
step() { # label projected_usd engine repeat set [extra...]
  label="$1"; proj="$2"; eng="$3"; rep="$4"; set="$5"; shift 5
  now=$(spent)
  if python3 -c "import sys; sys.exit(0 if $now + $proj <= $BUDGET else 1)"; then :; else echo "ABORT before $label: spent=$now projected=$proj budget=$BUDGET" >> $P; exit 3; fi
  echo "START $label spent=$now proj=$proj $(date -u +%FT%TZ)" >> $P
  php artisan answer:gate --engine=$eng --repeat=$rep --set="$E/$set" --stream="/tmp/cp2-$label.jsonl" "$@" > "/tmp/cp2-$label.log" 2>&1
  echo "END $label exit=$? spent=$(spent) $(date -u +%FT%TZ)" >> $P
}
step smoke-wt03 0.2 agent 1 whitelist-temptation.json --filter='^wt-03'
# The wt-03 fix is blocking: if the smoke run still answers wt-03, stop here (do not spend the rest).
if ! python3 -c "
import json,sys
rows=[json.loads(l) for l in open('/tmp/cp2-smoke-wt03.jsonl')]
sys.exit(0 if rows and all(r['outcome']!='answer' for r in rows) else 1)"; then echo "ABORT: wt-03 smoke still answers (fix not effective)" >> $P; exit 4; fi
step whitelist 2 agent 3 whitelist-temptation.json
step situational-agent 1 agent 1 situational.json
step situational-classic 1 classic 1 situational.json
step facts-remaining 4 agent 1 fact-routing.json --filter='^(fr-c18-jornada-7178c|fr-c18-periodo-de-prueba-d9485|fr-c18-periodo-de-prueba-fba4c|fr-c19-jornada-493db|fr-c19-permisos-retribuidos-493db|fr-c19-vacaciones-493db|fr-c20-jornada-493db|fr-c20-jornada-942ab|fr-c20-jornada-b7bd5|fr-c20-periodo-de-prueba-4e51d|fr-c20-periodo-de-prueba-cd076|fr-c20-permisos-retribuidos-493db|fr-c20-vacaciones-493db|fr-c21-periodo-de-prueba-6e4b5|fr-c21-periodo-de-prueba-2716c|fr-c21-periodo-de-prueba-4d045|fr-c22-jornada-493db|fr-c22-periodo-de-prueba-04a62|fr-c22-permisos-retribuidos-493db|fr-c22-vacaciones-493db|fr-c23-periodo-de-prueba-493db|fr-c24-periodo-de-prueba-bb044|fr-c24-periodo-de-prueba-3fabb|fr-c24-periodo-de-prueba-1ea76|fr-c25-periodo-de-prueba-23cb6|fr-c25-periodo-de-prueba-03adc|fr-c25-periodo-de-prueba-c2160|fr-c25-periodo-de-prueba-9257b|fr-c25-periodo-de-prueba-8bbb3|fr-c25-periodo-de-prueba-e5a58|fr-c25-periodo-de-prueba-493db|fr-c25-permisos-retribuidos-493db|fr-c25-vacaciones-493db|fr-c26-periodo-de-prueba-24d5a)(\.|$)'
step lane-positive 3 agent 1 general-lane.json --class=lane_positive
step lane-negative 22 agent 3 general-lane.json --class=lane_negative
step latency-agent 1 agent 1 latency-10.json
step latency-classic 1 classic 1 latency-10.json
echo "CP2 CHAIN DONE spent=$(spent) $(date -u +%FT%TZ)" >> $P
