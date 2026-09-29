#!/bin/sh
# Lane set resumed as 3 parallel chunks (cases 46..103) after the single process was stopped at case 45.
E=/var/hr-docs/sprints/sprint-13/eval
run() { label="$1"; filt="$2"; echo "START lane-$label $(date -u +%FT%TZ)" >> /tmp/final-progress.log; php artisan answer:gate --engine=both --repeat=3 --set=$E/general-lane.json --filter="$filt" --stream=/tmp/final-lane-$label.jsonl > /tmp/final-lane-$label.log 2>&1; echo "END lane-$label exit=$? $(date -u +%FT%TZ)" >> /tmp/final-progress.log; }
case "$1" in
  1) run p1 '^(ln-16-cov|ln-17-cov|ln-18-cov|ln-19-cov|ln-20-cov|ln-21-cov|ln-22-cov|ln-23-cov|ln-24-cov|ln-25-cov|lp-01-cov|lp-02-ni|lp-04-cov|lp-05-ni|lp-07-cov|lp-08-ni|lp-10-cov|lp-11-ni|lp-13-cov|lp-14-ni)$';;
  2) run p2 '^(ln-16-ni|ln-17-ni|ln-18-ni|ln-19-ni|ln-20-ni|ln-21-ni|ln-22-ni|ln-23-ni|ln-24-ni|ln-25-ni|lp-01-ni|lp-03-cov|lp-04-ni|lp-06-cov|lp-07-ni|lp-09-cov|lp-10-ni|lp-12-cov|lp-13-ni)$';;
  3) run p3 '^(ln-16-miss|ln-17-miss|ln-18-miss|ln-19-miss|ln-20-miss|ln-21-miss|ln-22-miss|ln-23-miss|ln-24-miss|ln-25-miss|lp-02-cov|lp-03-ni|lp-05-cov|lp-06-ni|lp-08-cov|lp-09-ni|lp-11-cov|lp-12-ni|lp-14-cov)$';;
esac
