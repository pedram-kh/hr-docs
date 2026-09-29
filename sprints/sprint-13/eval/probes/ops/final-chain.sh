#!/bin/sh
# Runs INSIDE the hr-backend container (detached by the caller). Sequential measured
# gates for CP-2; each set streams rows to /tmp/final-<label>.jsonl and writes a text
# report to /tmp/final-<label>.log. usage: final-chain.sh A|B
E=/var/hr-docs/sprints/sprint-13/eval
run() { # label set [extra args]
  label="$1"; set="$2"; shift 2
  echo "START $label $(date -u +%FT%TZ)" >> /tmp/final-progress.log
  php artisan answer:gate --engine=both --repeat=3 --set="$E/$set" --stream="/tmp/final-$label.jsonl" "$@" > "/tmp/final-$label.log" 2>&1
  echo "END $label exit=$? $(date -u +%FT%TZ)" >> /tmp/final-progress.log
}
estatuto() { # profile engine repeat-index
  echo "START estatuto-$1-$2-$3 $(date -u +%FT%TZ)" >> /tmp/final-progress.log
  php artisan estatuto:gold-eval --profile="$1" --engine="$2" --json > "/tmp/final-estatuto-$1-$2-$3.json" 2>&1
  echo "END estatuto-$1-$2-$3 exit=$? $(date -u +%FT%TZ)" >> /tmp/final-progress.log
}
case "$1" in
  A)
    run facts fact-routing.json
    run situational situational.json
    ;;
  B)
    run whitelist whitelist-temptation.json
    run gold2c gold-2c.json
    run lane general-lane.json
    for r in 1 2 3; do
      for p in positive negative second-negative; do
        for eng in classic agent; do estatuto "$p" "$eng" "$r"; done
      done
    done
    ;;
esac
echo "CHAIN $1 DONE $(date -u +%FT%TZ)" >> /tmp/final-progress.log
