#!/usr/bin/env python3
"""Offline mirror of AnswerGate::summarize() over one or more --stream JSONL files.

usage: analyze.py file.jsonl [file2.jsonl ...]      (rows are merged; use for the lane set,
which was stopped at case 45 and resumed as three chunks)
Prints the per-engine summary plus a per-case stability/disagreement table.
"""
import collections
import json
import sys


def pct(v, p):
    if not v:
        return None
    v = sorted(v)
    k = max(0, min(len(v) - 1, int(round(p / 100 * (len(v) - 1)))))
    return v[k]


rows = []
for f in sys.argv[1:]:
    rows += [json.loads(l) for l in open(f) if l.strip()]

engines = sorted({r["engine"] for r in rows})
out = {}
for e in engines:
    s = [r for r in rows if r["engine"] == e and not r.get("skipped")]
    n = len(s)
    ans = [r for r in s if r["outcome"] == "answer"]
    ft = [r for r in s if r.get("expected_first_tool") is not None]
    tm = [r for r in s if r.get("expected_terminal") is not None]
    lat = [r["latency_ms"] for r in s if isinstance(r.get("latency_ms"), int)]
    cost = sum(r.get("cost_usd") or 0 for r in s)
    by = collections.defaultdict(lambda: collections.Counter())
    for r in s:
        c = r.get("class") or "(none)"
        by[c]["n"] += 1
        by[c]["pass"] += 1 if r["pass"] else 0
        by[c]["hard"] += 1 if r.get("must_not_answer_violated") else 0
        by[c]["answers"] += 1 if r["outcome"] == "answer" else 0
        by[c]["lane_answers"] += 1 if r.get("lane_answer") else 0
    out[e] = {
        "turns": n,
        "pass": sum(1 for r in s if r["pass"]),
        "hard_violations": sum(1 for r in s if r.get("must_not_answer_violated")),
        "hard_kinds": dict(collections.Counter(r["hard_kind"] for r in s if r.get("hard_kind"))),
        "outcomes": dict(collections.Counter(r["outcome"] for r in s)),
        "paths": dict(collections.Counter((r.get("path") or ("prose" if r.get("authority") else "(none)")) for r in s)),
        "lane_answers": sum(1 for r in s if r.get("lane_answer")),
        "forbidden_asks_reached": sum(1 for r in s if r.get("forbidden_ask_reached")),
        "asks_attempted": sum(1 for r in s if r.get("ask_attempted")),
        "asks_denied": sum(1 for r in s if r.get("ask_denied")),
        "first_tool": [sum(1 for r in ft if r["first_tool"] in (r["expected_first_tool"] if isinstance(r["expected_first_tool"], list) else [r["expected_first_tool"]])), len(ft)],
        "terminal": [sum(1 for r in tm if r["terminal"] == r["expected_terminal"]), len(tm)],
        "corrections_per_100": round(sum(r.get("rule_overrides") or 0 for r in s) / n * 100, 1) if n else None,
        "forced_terminations_per_100": round(sum(r.get("forced_terminations") or 0 for r in s) / n * 100, 1) if n else None,
        "cost_usd": round(cost, 4),
        "cost_per_turn": round(cost / n, 5) if n else None,
        "cost_per_answer": round(cost / len(ans), 5) if ans else None,
        "latency_p50_p95_ms": [pct(lat, 50), pct(lat, 95)],
        "by_class": {k: dict(v) for k, v in by.items()},
    }
print(json.dumps(out, ensure_ascii=False, indent=1))

# per-case disagreement / instability
cases = collections.defaultdict(lambda: collections.defaultdict(list))
for r in rows:
    if r.get("skipped"):
        continue
    cases[r["id"]][r["engine"]].append(r["outcome"] + ("*" if not r["pass"] else ""))
print("\nCases where the engines differ in outcome mix, or a repeat is unstable ('*' = failed):")
for cid, per in cases.items():
    sets = {e: tuple(sorted(v)) for e, v in per.items()}
    unstable = any(len(set(x.rstrip('*') for x in v)) > 1 for v in per.values())
    differ = len({tuple(sorted(x.rstrip('*') for x in v)) for v in per.values()}) > 1
    failed = any(x.endswith("*") for v in per.values() for x in v)
    if unstable or differ or failed:
        print(" ", cid, {e: list(v) for e, v in per.items()})
