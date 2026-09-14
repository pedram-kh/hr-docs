#!/usr/bin/env python3
"""Sprint 10c jornada segmentation eval scorer (topic 3, next checkpoint
after CP-B) — stdlib only. Copied unmodified in logic from
eval/vacaciones/score_eval.py (CP-B), itself unmodified from
eval/permisos/score_eval.py (CP-A) — the scoring shape (keyword-matched
positive/negative gold entries, digit-set value matching, 0-tolerance scope
gate) is topic-agnostic; only the gold set and quotes differ per topic.

THE EVAL IS THE DELIVERABLE (same posture as 7b-2's and permisos'/vacaciones'
scorers).

Structural note (same as permisos/vacaciones): this driver calls hr-ai ONCE
PER CONVENIO (single-candidate binding), so scope reduces to "did every
proposed fact carry the call's own convenio_id" (checked directly,
0-tolerance). For jornada, this eval's own real risk is the REVERSE
direction of vacaciones' finding: c.20's Art. 22 JORNADA (the four-year,
three-job-category declining schedule) and c.10's bare "1.752 horas" figure
are jornada's own genuine positive content here — the same passages the
vacaciones eval correctly excluded on ITS side now need to be correctly
INCLUDED on this one. This topic's own boundary risk is (a) the same
page-break/running-header adjacency (c.20's Art. 22 JORNADA sits directly
beside Art. 25 VACACIONES), and (b) an ANCHOR-TERM collision rather than a
lexical topic-name collision: `TopicLexicon`'s jornada anchors include
"horas anuales", which also appears inside an unrelated formación-credit
clause (c.3 Art. 22) — a different failure shape than permisos' lactancia
leak or vacaciones' interruption-narrative leak, but the same underlying
category (a topic-boundary trap the corpus's own real structure creates,
not a hypothetical). Matching here is by MOTIVO KEYWORD (a gold entry's
`keywords`, matched against a proposed fact's `value`+`source_excerpt`),
not group/scope.

--facts is a JSON list of proposed facts for ONE convenio (the shape the
`/admin/reference-facts?convenio_id=X&topic_id=Y&source=ai_agent` export
writes, or the row objects `facts:segment-topic`'s summary implies):
  { "convenio_id": 2, "value": "17 días naturales...", "source_excerpt": "...",
    "confidence": 0.95, "uncertainty": {"field":..., "reason":...}|null }

Usage:
  python3 score_eval.py --gold gold-set.json --facts facts_run1.json
"""
from __future__ import annotations

import argparse
import json
import re
import sys
import unicodedata


def norm(s: str | None) -> str:
    if not s:
        return ""
    s = unicodedata.normalize("NFKD", s).encode("ascii", "ignore").decode("ascii")
    s = s.lower()
    # Sprint 10c jornada eval fix (found live — the first topic with 4-digit
    # thousands-separated hour figures, e.g. "1.752"): collapse a Spanish
    # thousands-separator period BEFORE the punctuation strip below, or
    # "1.752" tokenizes as the two separate digit-groups "1" and "752"
    # instead of "1752", silently breaking value_contains for every 4-digit
    # figure. permisos/vacaciones never hit this (their day-counts were all
    # 1-2 digits) — additive, does not change either prior topic's scoring.
    s = re.sub(r"(?<=\d)\.(?=\d{3}\b)", "", s)
    s = re.sub(r"[^a-z0-9 ]+", " ", s)
    return re.sub(r"\s+", " ", s).strip()


_WORD_NUM = {
    "un": "1", "uno": "1", "una": "1", "dos": "2", "tres": "3", "cuatro": "4",
    "cinco": "5", "seis": "6", "siete": "7", "ocho": "8", "nueve": "9",
    "diez": "10", "once": "11", "doce": "12", "quince": "15", "dieciseis": "16",
    "diecisiete": "17", "dieciocho": "18", "veinte": "20", "veintiuna": "21",
}


def value_tokens(s: str) -> set[str]:
    n = norm(s)
    nums = set(re.findall(r"\d+", n))
    for word, digit in _WORD_NUM.items():
        if re.search(rf"\b{word}\b", n):
            nums.add(digit)
    return nums


def value_match(expected_nums: list[str], prop: str) -> bool:
    """Every expected digit must appear SOMEWHERE in the proposed value/excerpt
    (order-independent — this is a degree/category-breakdown corpus, plan
    §D.10's own trap: 5/3/2 días per grado must all land in ONE fact)."""
    if not expected_nums:
        return True
    got = value_tokens(prop)
    return set(expected_nums).issubset(got)


def fact_text(f: dict) -> str:
    return f"{f.get('value', '')} {f.get('source_excerpt', '')}"


def keyword_hit(keywords: list[str], text: str) -> bool:
    n = norm(text)
    return any(norm(kw) in n for kw in keywords)


def score(gold_facts: list[dict], facts: list[dict], convenio_id: int) -> dict:
    # 0-tolerance scope gate (plan §D.10): every proposed fact for THIS run
    # must carry the call's own convenio_id — single-candidate binding should
    # make this structurally impossible to violate, but it's asserted
    # directly, never inferred.
    mis_scoped = [f for f in facts if f.get("convenio_id") != convenio_id]

    positive_gold = [g for g in gold_facts if g.get("expect", "fact") == "fact"]
    negative_gold = [g for g in gold_facts if g.get("expect") == "no_fact"]

    # NOT mutually exclusive matching: since Sprint 10c run2's prompt fix (rule
    # 3's mandatory bundling of enumerated multi-motivo lists), ONE proposed
    # fact legitimately satisfies MANY gold motivos at once (a whole article
    # bundled into one convenio-wide fact, D5's own multi-year-schedule
    # pattern generalized) — a gold entry is "found" if ANY proposed fact's
    # text contains its keywords, full stop; it does not "consume" that fact.
    matched_fact_idx: set[int] = set()
    correct = 0
    wrong_value: list[dict] = []
    missed: list[dict] = []

    for g in positive_gold:
        # Sprint 10c jornada eval fix (found live, c.2's year x group compound
        # trap): keyword_hit is OR/any-of-keywords by design (some entries,
        # e.g. c.10's spelled-out-vs-digit alternatives, list keywords as
        # ALTERNATIVE phrasings of the SAME fact, not joint requirements) —
        # but that means a first-match scan can latch onto the WRONG fact
        # when a compound gold entry's keywords (e.g. "2026" + "grupos 3 y 4")
        # are split across two real facts (fact A has the year, fact B has
        # the year AND the group). Picking the BEST match (most keywords
        # present, not just the first with >=1) fixes the compound case
        # while leaving single-keyword and true-alternative entries
        # unaffected (they only ever have one real candidate anyway).
        hit = None
        best_count = 0
        for fi, f in enumerate(facts):
            n = norm(fact_text(f))
            count = sum(1 for kw in g["keywords"] if norm(kw) in n)
            if count > best_count:
                best_count = count
                hit = fi
        if hit is None:
            missed.append(g)
            continue
        matched_fact_idx.add(hit)
        f = facts[hit]
        if value_match(g.get("value_contains", []), fact_text(f)):
            correct += 1
        else:
            wrong_value.append({"f": f, "g": g})

    # Restraint check (the heart of THIS topic's report): a proposed fact
    # matching a negative gold entry's keywords is over-extraction — either a
    # genuinely figure-less clause (deber inexcusable, etc.) or, worse, the
    # topic-boundary trap (lactancia/maternidad content extracted under
    # target_topic=permisos).
    restraint_failures: list[dict] = []
    for g in negative_gold:
        for f in facts:
            if keyword_hit(g["keywords"], fact_text(f)):
                restraint_failures.append({"f": f, "g": g})

    # Anything proposed that matched no gold keyword set at all (for either
    # polarity) is either a legitimate motivo this gold set didn't enumerate,
    # or a genuine mis-extraction — flagged for a human look, not auto-failed.
    all_keyword_sets = [g["keywords"] for g in gold_facts if "keywords" in g]
    unaccounted: list[dict] = []
    for fi, f in enumerate(facts):
        if fi in matched_fact_idx:
            continue
        if any(keyword_hit(kws, fact_text(f)) for kws in all_keyword_sets):
            continue  # matched a restraint-check keyword set (already reported above)
        unaccounted.append(f)

    return {
        "proposed": len(facts),
        "mis_scoped": mis_scoped,
        "correct": correct,
        "wrong_value": wrong_value,
        "missed": missed,
        "restraint_failures": restraint_failures,
        "unaccounted": unaccounted,
        "gold_positive_total": len(positive_gold),
        "gold_negative_total": len(negative_gold),
    }


def fc(f: dict) -> str:
    u = f.get("uncertainty")
    flag = f"uncertainty={u['field']}" if isinstance(u, dict) else "uncertainty=NONE"
    return f"conf={f.get('confidence')} {flag}"


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("--gold", required=True)
    ap.add_argument("--facts", required=True, help="JSON list of proposed facts for ONE convenio")
    ap.add_argument("--convenio", required=True, type=int)
    args = ap.parse_args()

    gold = json.load(open(args.gold, encoding="utf-8"))
    facts = json.load(open(args.facts, encoding="utf-8"))
    if isinstance(facts, dict):
        facts = facts.get("facts", facts.get("data", facts))

    gold_facts = [g for g in gold["expected_facts"] if g["convenio_id"] == args.convenio]
    if not gold_facts:
        print(f"no gold for convenio_id {args.convenio}", file=sys.stderr)
        return 2

    r = score(gold_facts, facts, args.convenio)
    conv_name = gold.get("convenios", {}).get(str(args.convenio), {}).get("name", "?")
    print(f"\n=== convenio {args.convenio} ({conv_name}) ===")
    print(f"proposed={r['proposed']}  correct={r['correct']}/{r['gold_positive_total']}  "
          f"wrong-value={len(r['wrong_value'])}  missed={len(r['missed'])}  "
          f"mis-scoped(wrong convenio_id)={len(r['mis_scoped'])}  "
          f"restraint-failures(over-extraction)={len(r['restraint_failures'])}/{r['gold_negative_total']}  "
          f"unaccounted(no gold match either way)={len(r['unaccounted'])}")

    if r["mis_scoped"]:
        print("\n-- MIS-SCOPED (wrong convenio_id — must be 0, single-candidate binding) --")
        for f in r["mis_scoped"]:
            print(f"  convenio_id={f.get('convenio_id')}  {f.get('value', '')[:80]!r}")

    if r["restraint_failures"]:
        print("\n-- RESTRAINT FAILURES (over-extraction — the heart of this topic's report) --")
        for rf in r["restraint_failures"]:
            f, g = rf["f"], rf["g"]
            trap = f"  [{g['spot_check']}]" if g.get("spot_check") else ""
            print(f"  gold-negative: {g['motivo']}{trap}")
            print(f"    proposed value={f.get('value', '')[:90]!r}  {fc(f)}")
    else:
        print("\n-- restraint failures: NONE (no figure-less/wrong-topic clause was extracted) --")

    if r["wrong_value"]:
        print("\n-- wrong value (motivo matched, figure mismatch) --")
        for w in r["wrong_value"]:
            f, g = w["f"], w["g"]
            print(f"  {g['motivo']}: proposed={f.get('value', '')[:70]!r}  expected_nums={g.get('value_contains')}  {fc(f)}")

    if r["missed"]:
        print("\n-- missed (gold motivo not proposed) --")
        for g in r["missed"]:
            print(f"  {g['motivo']}: {g.get('quote', '')[:80]!r}")

    if r["unaccounted"]:
        print("\n-- unaccounted (proposed fact matched no gold entry, either polarity — human look) --")
        for f in r["unaccounted"]:
            print(f"  {f.get('value', '')[:90]!r}  {fc(f)}")

    return 0


if __name__ == "__main__":
    raise SystemExit(main())
