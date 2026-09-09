#!/usr/bin/env python3
"""Sprint 7f group-structure eval scorer (ADR-0028) — stdlib only.

Scores the trees the proposer actually wrote on staging against the hand-built
gold trees, and prints the table that goes into review.md.

WHAT IT MEASURES, AND WHY THE TWO ERRORS ARE NOT SYMMETRIC.

A group tree is only useful if its GRANULARITY matches the convenio's. The two
ways to get that wrong have very different costs, so they are scored and gated
separately:

  UNDER-SPLIT — gold splits a group, the proposal does not. The matcher then
    binds one value to a group whose halves the convenio prices differently and
    answers 60 días to someone entitled to 90. A wrong answer, silently.
    GATE: zero. Any under-split fails the run.

  OVER-SPLIT — the proposal splits a group gold leaves whole. The matcher then
    wants a distinction the convenio never made, so an employee in that group
    matches nothing and the question escalates. Worse service, not wrong
    information — it degrades to a human. REPORTED, not gated.

MISSING and EXTRA groups are counted too. A missing group is nearly as bad as
an under-split (its facts have nowhere to bind, so they escalate), while an
extra group is inert until someone approves it.

MEMBERSHIP ACCURACY is reported and never gated, on purpose. Category membership
only pre-fills a picker; it never decides an answer, and 72 of the corpus's 94
categories have no group at all. Gating on it would make the eval fail for a
signal nothing depends on.

Scoring is by group IDENTITY, not spelling: each gold group lists the
`code_normalized` keys that mean the same node, because one convenio legitimately
writes "Grupo Prof. 1.º", "Grupo 1" and "Grupo I. Personal directivo" for one
group.

--proposed is the JSON the export writes:
  [{ "convenio_id": 21,
     "groups": [{ "code_normalized": "1", "label": "Grupo Prof. 1.º",
                  "parent_code": null, "status": "needs_review",
                  "has_excerpt": true, "category_ids": [] }, ...] }]

Usage:
  python3 score_trees.py --gold gold-trees.json --proposed proposed-trees.json
"""
from __future__ import annotations

import argparse
import json
import sys


def load(path: str):
    with open(path, encoding="utf-8") as fh:
        return json.load(fh)


def accepted(entry: dict) -> set[str]:
    """Every key that counts as this gold node."""
    return {c for c in ([entry["code"]] + entry.get("accepted_codes", [])) if c}


def score_convenio(gold: dict, proposed_groups: list[dict]) -> dict:
    """Compare one convenio's proposed tree to its gold tree."""
    roots = [g for g in proposed_groups if not g.get("parent_code")]
    children_by_parent: dict[str, list[dict]] = {}
    for g in proposed_groups:
        parent = g.get("parent_code")
        if parent:
            children_by_parent.setdefault(parent, []).append(g)

    matched_root_keys: set[str] = set()
    exact = under = over = missing = 0
    detail: list[str] = []

    for gold_group in gold["groups"]:
        keys = accepted(gold_group)
        hits = [r for r in roots if r["code_normalized"] in keys]

        if not hits:
            missing += 1
            detail.append(f"    MISSING group {gold_group['code']} — its facts have nowhere to bind")
            continue

        # More than one proposed root matching one gold group is itself a
        # duplicate-node bug (the typography trap), so say so.
        if len(hits) > 1:
            detail.append(
                f"    DUPLICATE nodes for group {gold_group['code']}: "
                + ", ".join(sorted(h["code_normalized"] for h in hits))
            )
        for h in hits:
            matched_root_keys.add(h["code_normalized"])

        gold_areas = gold_group.get("sub_areas", [])
        proposed_areas: list[dict] = []
        for h in hits:
            proposed_areas.extend(children_by_parent.get(h["code_normalized"], []))

        if not gold_areas and not proposed_areas:
            exact += 1
        elif gold_areas and not proposed_areas:
            under += 1
            detail.append(
                f"    UNDER-SPLIT group {gold_group['code']}: gold has "
                f"{len(gold_areas)} area(s) the convenio prices differently "
                f"({', '.join(a['code'] for a in gold_areas)}), proposal has none"
            )
        elif proposed_areas and not gold_areas:
            over += 1
            detail.append(
                f"    OVER-SPLIT group {gold_group['code']}: proposal invents "
                f"{', '.join(a['code_normalized'] for a in proposed_areas)}"
            )
        else:
            # Both split — check the areas match, area by area.
            area_missing = []
            proposed_keys = {a["code_normalized"] for a in proposed_areas}
            covered: set[str] = set()
            for gold_area in gold_areas:
                akeys = accepted(gold_area)
                hit = proposed_keys & akeys
                if hit:
                    covered |= hit
                else:
                    area_missing.append(gold_area["code"])
            extra_areas = proposed_keys - covered

            if not area_missing and not extra_areas:
                exact += 1
            elif area_missing:
                under += 1
                detail.append(
                    f"    UNDER-SPLIT group {gold_group['code']}: missing area(s) "
                    f"{', '.join(area_missing)}"
                )
            else:
                over += 1
                detail.append(
                    f"    OVER-SPLIT group {gold_group['code']}: extra area(s) "
                    f"{', '.join(sorted(extra_areas))}"
                )

    extra_groups = sorted(r["code_normalized"] for r in roots if r["code_normalized"] not in matched_root_keys)
    for key in extra_groups:
        detail.append(f"    EXTRA group '{key}' — not in gold (inert until approved)")

    # Every node must carry the citation that makes it checkable.
    no_excerpt = [g["code_normalized"] for g in proposed_groups if not g.get("has_excerpt")]

    # Nothing may arrive approved. The agent proposes; a human approves.
    not_inert = [g["code_normalized"] for g in proposed_groups if g.get("status") != "needs_review"]

    return {
        "exact": exact,
        "under": under,
        "over": over,
        "missing": missing,
        "extra": len(extra_groups),
        "gold_groups": len(gold["groups"]),
        "proposed_roots": len(roots),
        "proposed_areas": sum(len(v) for v in children_by_parent.values()),
        "no_excerpt": no_excerpt,
        "not_inert": not_inert,
        "detail": detail,
    }


def score_memberships(gold: dict, proposed_groups: list[dict]) -> tuple[int, int, int] | None:
    """Category membership: correct, proposed, expected. Reported, never gated."""
    expected = gold.get("expected_memberships")
    if not expected:
        return None

    by_key = {g["code_normalized"]: g for g in proposed_groups}
    correct = proposed = 0
    total = sum(len(v) for v in expected.values())

    for gold_group in gold["groups"]:
        want = set(expected.get(gold_group["code"], []))
        got: set[int] = set()
        for key in accepted(gold_group):
            node = by_key.get(key)
            if node:
                got |= set(node.get("category_ids") or [])
        proposed += len(got)
        correct += len(want & got)

    return correct, proposed, total


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("--gold", required=True)
    ap.add_argument("--proposed", required=True)
    args = ap.parse_args()

    gold_doc = load(args.gold)
    proposed_doc = load(args.proposed)
    proposed_by_convenio = {p["convenio_id"]: p.get("groups", []) for p in proposed_doc}

    total = {"exact": 0, "under": 0, "over": 0, "missing": 0, "extra": 0, "gold_groups": 0}
    rows: list[tuple] = []
    failures: list[str] = []
    membership_lines: list[str] = []

    for gold in gold_doc["convenios"]:
        cid = gold["convenio_id"]
        if cid not in proposed_by_convenio:
            failures.append(f"convenio {cid} ({gold['name']}) was not proposed at all")
            continue

        groups = proposed_by_convenio[cid]
        s = score_convenio(gold, groups)
        for k in total:
            total[k] += s[k]

        rows.append((
            cid, gold["name"][:38], s["gold_groups"],
            f"{s['proposed_roots']}+{s['proposed_areas']}",
            s["exact"], s["under"], s["over"], s["missing"], s["extra"],
        ))

        if s["detail"]:
            membership_lines.append(f"  #{cid} {gold['name']}")
            membership_lines.extend(s["detail"])

        if s["not_inert"]:
            failures.append(
                f"convenio {cid}: node(s) {s['not_inert']} did not land needs_review — "
                "the proposer must never produce approved structure"
            )
        if s["no_excerpt"]:
            membership_lines.append(
                f"    NO EXCERPT on: {', '.join(s['no_excerpt'])} (reviewer cannot check these)"
            )

        m = score_memberships(gold, groups)
        if m:
            correct, proposed_n, expected_n = m
            pct = (100.0 * correct / expected_n) if expected_n else 0.0
            membership_lines.append(
                f"  #{cid} membership (ungated): {correct}/{expected_n} expected recovered "
                f"({pct:.0f}%), {proposed_n} proposed"
            )

    print()
    print("Sprint 7f — group structure eval")
    print("=" * 96)
    print(f"{'id':>4}  {'convenio':38}  {'gold':>4}  {'prop':>6}  {'exact':>5}  {'UNDER':>5}  {'over':>4}  {'miss':>4}  {'extra':>5}")
    print("-" * 96)
    for r in rows:
        print(f"{r[0]:>4}  {r[1]:38}  {r[2]:>4}  {r[3]:>6}  {r[4]:>5}  {r[5]:>5}  {r[6]:>4}  {r[7]:>4}  {r[8]:>5}")
    print("-" * 96)
    print(
        f"{'TOT':>4}  {'':38}  {total['gold_groups']:>4}  {'':>6}  {total['exact']:>5}  "
        f"{total['under']:>5}  {total['over']:>4}  {total['missing']:>4}  {total['extra']:>5}"
    )

    if membership_lines:
        print()
        print("Detail")
        print("-" * 96)
        for line in membership_lines:
            print(line)

    print()
    print("Gate")
    print("-" * 96)
    gated = total["under"] == 0 and not failures
    print(f"  under-splits: {total['under']}  (gate: must be 0 — an under-split is a WRONG ANSWER)")
    print(f"  over-splits : {total['over']}  (reported — degrades to an escalation, not a wrong answer)")
    print(f"  missing     : {total['missing']}  (reported — those facts cannot bind and will escalate)")
    for f in failures:
        print(f"  FAIL: {f}")

    print()
    print("PASS" if gated else "FAIL")
    return 0 if gated else 1


if __name__ == "__main__":
    sys.exit(main())
