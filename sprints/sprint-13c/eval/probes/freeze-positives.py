#!/usr/bin/env python3
"""Slice 13c, end of S0 — freeze the positive set from the recorded V1+V2 rows (plan.md §3.1-3.2).

Input : ../results/s0-verification.jsonl (pool 1, 52 rows) and ../results/s0b-verify.log (pool 2, 24 rows; `V {json}` lines)
Output: ../lane-positives.json (same case format as sprint-13/eval/general-lane.json `lane_positive`)

Qualification (pre-registered in the plan): pre-corpus gates clear AND pre-screen v2 admits AND the corpus cannot answer on
profile `cov` = corpus_shape in {check_a_miss, synthesis_abstention, entailment_only}. Fact-route and corpus_answers are out.

Gate subset (`gate_set: true`), RE-FROZEN 2026-09-30 by user direction (stratified; supersedes the pure hash-order rule):
S0 found more survivors than the gate needs (33 vs 20) and S3a cost scales with its size ($0.10/turn x 2 runs), so the
end-to-end gate runs on 20. Rule, with no other input: (1) ALL non-abstention survivors (check_a_miss + entailment_only: 7),
because those are the only ones that open the UNMODIFIED Sprint-13 lane and the abstention relaxation says nothing about them;
(2) then abstention survivors in ascending sha256(question) order until 20, skipping a candidate whose FAMILY already holds
FAMILY_CAP (12) gate cases. Families are topic groups fixed below BEFORE the selection ran (defined in FREEZE-NOTE.md).
S1 (forced-lane drafts, $0.006 each) uses all survivors.
"""
import hashlib
import json
import os
from collections import Counter

HERE = os.path.dirname(os.path.abspath(__file__))
EVAL = os.path.join(HERE, "..")
QUALIFYING = {"check_a_miss", "synthesis_abstention", "entailment_only"}
GATE_N = 20
FAMILY_CAP = 12

# Topic families (the question's subject, not its outcome). Every survivor must be listed: an unlisted one aborts the freeze.
FAMILIES = {
    "SS": ("Seguridad Social: organismos, alta, vida laboral, INSS, IMV",
           ["POOL-01", "POOL-02", "POOL-03", "POOL-11", "POOL-53", "POOL-65", "POOL-67", "POOL-75", "POOL-76"]),
    "EMPLEO": ("Empleo y desempleo: SEPE, paro, prestacion, demanda, certificado de empresa, FOGASA",
               ["POOL-09", "POOL-10", "POOL-15", "POOL-40", "POOL-56", "POOL-57", "POOL-70", "POOL-73", "POOL-74"]),
    "BAJA": ("Baja y alta medicas: IT, parte de baja, alta medica, mutua",
             ["POOL-04", "POOL-05", "POOL-07", "POOL-55", "POOL-69"]),
    "PRL": ("Prevencion y accidentes: servicio/comite, accidente de trabajo, enfermedad profesional, Inspeccion",
            ["POOL-14", "POOL-48", "POOL-58", "POOL-59", "POOL-61"]),
    "RRLL": ("Relaciones laborales: sindicato, SMAC, movilidad funcional, excedencia, MSCT",
             ["POOL-25", "POOL-28", "POOL-29", "POOL-45", "POOL-47"]),
}
FAMILY_OF = {pid: fam for fam, (_, ids) in FAMILIES.items() for pid in ids}


def rows():
    out = []
    for name in ("s0-verification.jsonl", "s0b-verify.log"):
        with open(os.path.join(EVAL, "results", name), encoding="utf-8") as fh:
            for line in fh:
                if line.startswith("V {"):
                    out.append(json.loads(line[2:]))
                elif line.startswith("{"):
                    out.append(json.loads(line))
    return out


allrows = rows()
assert len({r["id"] for r in allrows}) == len(allrows) == 76, len(allrows)
survivors = [r for r in allrows if r["corpus_shape"] in QUALIFYING and not r["pre_corpus_gates"]]
survivors.sort(key=lambda r: int(r["id"].split("-")[1]))

assert {r["id"] for r in survivors} == set(FAMILY_OF), sorted({r["id"] for r in survivors} ^ set(FAMILY_OF))
by_hash = sorted(survivors, key=lambda r: hashlib.sha256(r["question"].encode("utf-8")).hexdigest())
gate_ids = {r["id"] for r in survivors if r["corpus_shape"] != "synthesis_abstention"}
assert len(gate_ids) == 7, len(gate_ids)
fam_count = Counter(FAMILY_OF[i] for i in gate_ids)
skipped = []
for r in by_hash:
    if len(gate_ids) == GATE_N:
        break
    if r["id"] in gate_ids:
        continue
    if fam_count[FAMILY_OF[r["id"]]] >= FAMILY_CAP:
        skipped.append(r["id"])
        continue
    gate_ids.add(r["id"])
    fam_count[FAMILY_OF[r["id"]]] += 1
assert len(gate_ids) == GATE_N

cases = []
for r in survivors:
    cases.append({
        "id": "LP" + r["id"][4:] + "-cov",
        "pool_id": r["id"],
        "class": "lane_positive",
        "email": "test-gipuzkoa@example.com",
        "question": r["question"],
        "expect": {},
        "expected_tools": {"first": ["convenio_search", "national_law", "reference_fact"]},
        "gate_set": r["id"] in gate_ids,
        "family": FAMILY_OF[r["id"]],
        "s0": {
            "corpus_shape": r["corpus_shape"],
            "lane_gate_derived": {"check_a_miss": "V1_check_a_miss", "synthesis_abstention": "V2_synthesis_abstention", "entailment_only": "V2_entailment_only"}[r["corpus_shape"]],
            "opens_on_unmodified_sprint13_lane": r.get("corpus_miss_classify") in ("check_a_miss", "entailment_only"),
            "top_score": r.get("top_score"),
            "confidence": r.get("confidence"),
        },
        "flags": r.get("flags", []),
    })

doc = {
    "_description": "Slice 13c S0 survivors: 33 of the 76 pool candidates (pool 1 = 52, pool 2 = 24) that the corpus cannot answer on profile cov = test-gipuzkoa (V1 Check A miss, V2 synthesis abstention or entailment-only), clear every pre-corpus gate and pre-screen v2. `gate_set: true` marks the 20 the end-to-end gate (S3a) runs on: all 7 non-abstention survivors plus 13 abstention survivors in ascending sha256(question) order, under a family cap of 12/20 (re-frozen 2026-09-30, FREEZE-NOTE.md); `family` is the topic group; S1 (forced-lane positives) uses all 33. Per-row verdicts in results/s0-verification.jsonl and results/s0b-verify.log. Shape counts and the unmodified-lane column are in `s0`.",
    "cases": cases,
}
with open(os.path.join(EVAL, "lane-positives.json"), "w", encoding="utf-8") as fh:
    json.dump(doc, fh, ensure_ascii=False, indent=1)
    fh.write("\n")

print("survivors", len(cases), dict(Counter(c["s0"]["corpus_shape"] for c in cases)))
print("gate set", sum(c["gate_set"] for c in cases), dict(Counter(c["s0"]["corpus_shape"] for c in cases if c["gate_set"])))
print("gate families", dict(fam_count), "cap", FAMILY_CAP, "skipped_by_cap", skipped)
print("family sizes (all survivors)", dict(Counter(FAMILY_OF.values())))
