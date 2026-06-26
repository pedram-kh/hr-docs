#!/usr/bin/env python3
"""Sprint 7b-2 segmentation eval scorer (ADR-0022) — stdlib only.

THE EVAL IS THE DELIVERABLE. This scores the agent's PROPOSED facts (after a live
segmentation run) against the hand-built gold set, and prints the accuracy table
+ the mis-scoped enumeration + the confidence/uncertainty correlation that go
into review.md.

Scope is checked the way the gold set itself specifies: by CONVENIO IDENTITY.
Every proposed fact is bound (closed-set validated) to a real convenio; the
export carries that convenio's `numero`. When a gold entry carries a
`convenio_hint` (the registry numero), a proposed fact is correctly-scoped iff
`numero == convenio_hint` — an exact entity check, immune to the registry's
messy/duplicated sector NAMES (it has both a `COEAS` sector and an
`OCIO EDUCATIVO Y ANIMACION SOCIOCUL` sector for the same real sector, etc.).
For the minority of gold entries with no hint, scope falls back to a canonical
(territory, sector) match through the alias maps below.

--facts is a JSON list of proposed facts, each (the shape the export writes):
  { "convenio_numero": "01100635012017", "territory": "Álava",
    "sector": "OCIO EDUCATIVO Y ANIMACION SOCIOCUL", "group_label": "Grupo 1",
    "value": "Cinco meses", "confidence": 0.95,
    "uncertainty": {"field": "...", "reason": "..."}|null,
    "is_possible_duplicate": false }

Usage:
  python3 score_eval.py --gold gold-set.json --facts facts_file1.json \
      --fixture "PERIODOS DE PRUEBA.docx"
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
    s = re.sub(r"[^a-z0-9 ]+", " ", s)
    return re.sub(r"\s+", " ", s).strip()


# Canonical SECTOR identity. The registry stores the same real sector under
# several names (truncated/variant rows); the gold uses short names. Map both
# sides to one canonical token so a non-hinted (territory, sector) match is fair.
SECTOR_CANON = {
    "ocio educativo y animacion sociocul": "coeas",
    "ocio educativo y animacion": "coeas",
    "ocio educativo": "coeas",
    "coeas": "coeas",
    "intervencion social": "intervencion social",
    "accion e intervencion social": "intervencion social",
    "limpieza": "limpieza",
    "limpieza edificios y locales": "limpieza",
    "limpieza de edificios y locales": "limpieza",
    "gestion deportiva": "gestion deportiva",
    "entidades privadas gestoras de servicios deportivos": "gestion deportiva",
    "deporte": "servicios deportivos",
    "servicios deportivos": "servicios deportivos",
    "contratos publicos de servicios deportivos": "servicios deportivos",
    "hosteleria": "hosteleria",
    "hosteleria y turismo huesc": "hosteleria",
}

# Canonical TERRITORY identity (gold long names / spelling variants ↔ registry).
TERRITORY_CANON = {
    "comunidad de madrid": "madrid",
    "madrid": "madrid",
    "vizcaya": "bizkaia",
    "bizkaia": "bizkaia",
    "guipuzcoa": "gipuzkoa",
    "gipuzkoa": "gipuzkoa",
    "castilla y leon salamanca": "salamanca",
    "salamanca": "salamanca",
}


def nsector(s: str | None) -> str:
    n = norm(s)
    return SECTOR_CANON.get(n, n)


def nterr(s: str | None) -> str:
    n = norm(s)
    return TERRITORY_CANON.get(n, n)


_WORD_NUM = {
    "un": "1", "uno": "1", "una": "1", "dos": "2", "tres": "3", "cuatro": "4",
    "cinco": "5", "seis": "6", "siete": "7", "ocho": "8", "nueve": "9",
    "diez": "10", "quince": "15", "treinta": "30", "cuarenta": "40",
    "cuarenta y cinco": "45", "sesenta": "60", "noventa": "90",
}


def value_tokens(s: str) -> set[str]:
    n = norm(s)
    nums = set(re.findall(r"\d+", n))
    for word, digit in _WORD_NUM.items():
        if re.search(rf"\b{word}\b", n):
            nums.add(digit)
    return nums


def value_match(gold: str, prop: str) -> bool:
    g_nums = value_tokens(gold)
    if not g_nums:
        return True
    return g_nums.issubset(value_tokens(prop))


def group_match(gold: str | None, prop: str | None) -> bool:
    g, p = norm(gold), norm(prop)
    if not g and not p:
        return True
    if not g or not p:
        # gold None ≈ a single undifferentiated proposed group (and vice versa):
        # the agent often labels a one-rule sector "Todo"/"General" where gold = None.
        single = (p in {"todo", "todos", "general", "todos los grupos"} or
                  g in {"todo", "todos", "general", "todos los grupos"})
        return single or g == p
    g_tok = set(re.findall(r"[0-9ivx]+", g)) or {g}
    p_tok = set(re.findall(r"[0-9ivx]+", p)) or {p}
    return bool(g_tok & p_tok) or g in p or p in g


def gold_scope_match(g: dict, f: dict) -> bool:
    """Scope = convenio identity. Prefer the exact numero==convenio_hint check;
    fall back to canonical (territory, sector) when the gold carries no hint."""
    hint = g.get("convenio_hint")
    if hint:
        return str(f.get("convenio_numero")) == str(hint)
    return nterr(g["territory"]) == nterr(f.get("territory")) and \
        nsector(g.get("sector")) == nsector(f.get("sector"))


def score(gold_fixture: dict, facts: list[dict]) -> dict:
    gold_facts = gold_fixture.get("expected_facts", [])
    # the set of legitimate gold scopes (numeros + canonical territory/sector) so
    # an unmatched proposed fact can be classed "wrong scope" vs "extra/over".
    gold_numeros = {str(g["convenio_hint"]) for g in gold_facts if g.get("convenio_hint")}
    gold_ts = {(nterr(g["territory"]), nsector(g.get("sector"))) for g in gold_facts}

    gold_used = [False] * len(gold_facts)
    matched_fact = [False] * len(facts)

    correct = 0
    flagged_ok = 0
    wrong_value: list[dict] = []

    for fi, f in enumerate(facts):
        for gi, g in enumerate(gold_facts):
            if gold_used[gi]:
                continue
            if gold_scope_match(g, f) and group_match(g.get("group"), f.get("group_label")):
                gold_used[gi] = True
                matched_fact[fi] = True
                if value_match(g["value"], f.get("value", "")):
                    correct += 1
                else:
                    wrong_value.append({"f": f, "g": g})
                if g.get("uncertain") and f.get("uncertainty"):
                    flagged_ok += 1
                break

    # classify the proposed facts that matched no gold line
    mis_scoped: list[dict] = []   # bound to a convenio outside every gold scope
    over: list[dict] = []         # scope is a valid gold scope, but an extra line
    for fi, f in enumerate(facts):
        if matched_fact[fi]:
            continue
        num = str(f.get("convenio_numero"))
        ts = (nterr(f.get("territory")), nsector(f.get("sector")))
        if num in gold_numeros or ts in gold_ts:
            over.append(f)
        else:
            mis_scoped.append(f)

    missed = [gold_facts[i] for i, used in enumerate(gold_used) if not used]
    return {
        "proposed": len(facts),
        "correct": correct,
        "wrong_value": wrong_value,
        "mis_scoped": mis_scoped,
        "over": over,
        "correctly_flagged": flagged_ok,
        "uncertain_gold": sum(1 for g in gold_facts if g.get("uncertain")),
        "missed": missed,
    }


def fc(f: dict) -> str:
    u = f.get("uncertainty")
    flag = f"uncertainty={u['field']}" if isinstance(u, dict) else "uncertainty=NONE"
    dup = " dup=YES" if f.get("is_possible_duplicate") else ""
    return f"conf={f.get('confidence')} {flag}{dup}"


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("--gold", required=True)
    ap.add_argument("--facts", required=True, help="JSON list of proposed facts")
    ap.add_argument("--fixture", required=True)
    args = ap.parse_args()

    gold = json.load(open(args.gold, encoding="utf-8"))
    facts = json.load(open(args.facts, encoding="utf-8"))
    if isinstance(facts, dict):
        facts = facts.get("facts", {}).get("data", facts.get("facts", []))

    gf = gold["fixtures"].get(args.fixture)
    if gf is None:
        print(f"no gold for fixture {args.fixture!r}", file=sys.stderr)
        return 2

    r = score(gf, facts)
    gold_total = len(gf.get("expected_facts", []))
    print(f"\n=== {args.fixture} ===")
    print(f"proposed={r['proposed']}  correct(scope+value)={r['correct']}  "
          f"wrong-value={len(r['wrong_value'])}  mis-scoped(wrong convenio)={len(r['mis_scoped'])}  "
          f"over-proposed(valid scope, extra line)={len(r['over'])}  "
          f"correctly-flagged-uncertain={r['correctly_flagged']}/{r['uncertain_gold']}  "
          f"missed={len(r['missed'])}  (gold total={gold_total})")

    if r["mis_scoped"]:
        print("\n-- mis-scoped (bound to a convenio outside every gold scope) — the heart of the report --")
        for f in r["mis_scoped"]:
            print(f"  [{f.get('convenio_numero')}] {f.get('territory')} · {f.get('sector')} · "
                  f"{f.get('group_label')} = {f.get('value','')[:80]!r}")
            print(f"        {fc(f)}")
    else:
        print("\n-- mis-scoped: NONE (no proposed fact bound to a convenio outside the gold scopes) --")

    if r["wrong_value"]:
        print("\n-- wrong value (scope right, value mismatch) --")
        for w in r["wrong_value"]:
            f, g = w["f"], w["g"]
            print(f"  [{f.get('convenio_numero')}] {f.get('territory')}·{f.get('sector')}·{f.get('group_label')}")
            print(f"        proposed value={f.get('value','')[:70]!r}  gold value={g['value'][:70]!r}  {fc(f)}")

    if r["over"]:
        print("\n-- over-proposed (valid gold scope, no specific gold line: finer split or statutory block) --")
        for f in r["over"]:
            print(f"  [{f.get('convenio_numero')}] {f.get('territory')}·{f.get('sector')}·{f.get('group_label')} "
                  f"= {f.get('value','')[:60]!r}  {fc(f)}")

    if r["missed"]:
        print("\n-- missed (gold not proposed) --")
        for g in r["missed"]:
            print(f"  {g['territory']} · {g.get('sector')} · {g.get('group')} = {g['value']}"
                  + ("   [gold:uncertain]" if g.get("uncertain") else ""))

    # confidence/uncertainty correlation — the drowning-vs-catchable signal
    errs = r["mis_scoped"] + [w["f"] for w in r["wrong_value"]]
    if errs:
        conf_no_flag = [f for f in errs if not f.get("uncertainty")]
        print("\n-- ERROR confidence/uncertainty correlation (drowning test) --")
        print(f"  errors(mis-scoped+wrong-value)={len(errs)}  "
              f"of which CONFIDENT+UNFLAGGED (rubber-stampable)={len(conf_no_flag)}  "
              f"flagged-or-low-conf(catchable)={len(errs)-len(conf_no_flag)}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
