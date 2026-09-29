#!/bin/sh
# Chain C (started mid-run to parallelise): the Estatuto gold profiles x engines x 3 repeats,
# same outputs as the tail of final-chain.sh B (which is stopped once its `lane` set ends).
estatuto() { # profile engine repeat-index
  echo "START estatuto-$1-$2-$3 $(date -u +%FT%TZ)" >> /tmp/final-progress.log
  php artisan estatuto:gold-eval --profile="$1" --engine="$2" --json > "/tmp/final-estatuto-$1-$2-$3.json" 2>&1
  echo "END estatuto-$1-$2-$3 exit=$? $(date -u +%FT%TZ)" >> /tmp/final-progress.log
}
for r in 1 2 3; do
  for p in positive negative second-negative; do
    for eng in classic agent; do estatuto "$p" "$eng" "$r"; done
  done
done
echo "CHAIN C DONE $(date -u +%FT%TZ)" >> /tmp/final-progress.log
