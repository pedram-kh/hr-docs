"""score_ocr.py — Sprint 7e eval scoring (plan.md §2.3). Stdlib only.

Scores one engine's raw output (`out/<engine>/<stem>.json`, same shape as
`gold/<stem>.json`) against the human-corrected gold for every fixture that
has one, and prints/writes the decision table (plan.md §2.5 order):

    1. column integrity % + article-header survival  (primary — disqualifying)
    2. CER / WER                                       (tie-break among survivors)
    3. cost/page, sec/page                             (final tie-break if close)

IMPORTANT: this script must not be pointed at `gold/*.draft.json` as if it
were gold. It only reads `gold/<stem>.json` (no `.draft` suffix) — the
human-corrected file. If that file does not exist yet for a fixture, the
fixture is skipped with a loud note, never silently scored against a draft.
"""

from __future__ import annotations

import argparse
import json
import re
import statistics
from pathlib import Path

EVAL_ROOT = Path(__file__).resolve().parent
GOLD_DIR = EVAL_ROOT / "gold"
OUT_DIR = EVAL_ROOT / "out"

_WS = re.compile(r"\s+")


def _norm(text: str) -> str:
    return _WS.sub(" ", (text or "").strip())


def _norm_line(text: str) -> str:
    return _norm(text).lower()


# --- Levenshtein (stdlib, O(n*m), rolling-row) ------------------------------


def _levenshtein(a: list, b: list) -> int:
    if a == b:
        return 0
    if not a:
        return len(b)
    if not b:
        return len(a)
    prev = list(range(len(b) + 1))
    for i, ca in enumerate(a, start=1):
        cur = [i] + [0] * len(b)
        for j, cb in enumerate(b, start=1):
            cost = 0 if ca == cb else 1
            cur[j] = min(prev[j] + 1, cur[j - 1] + 1, prev[j - 1] + cost)
        prev = cur
    return prev[-1]


def cer(gold_text: str, cand_text: str) -> float:
    """Character error rate = edit distance / len(gold), chars."""
    g = list(_norm(gold_text))
    c = list(_norm(cand_text))
    if not g:
        return 0.0 if not c else 1.0
    return _levenshtein(g, c) / len(g)


def wer(gold_text: str, cand_text: str) -> float:
    """Word error rate = edit distance / len(gold), whitespace tokens."""
    g = _norm(gold_text).split(" ")
    c = _norm(cand_text).split(" ")
    g = [w for w in g if w]
    c = [w for w in c if w]
    if not g:
        return 0.0 if not c else 1.0
    return _levenshtein(g, c) / len(g)


# --- Column integrity (plan.md §2.3 — the primary, disqualifying metric) ---


def _column_integrity(gold_cols: list[dict], cand_cols: list[dict]) -> tuple[float, list[str]]:
    """For each gold column, is there a candidate column that (a) sits at the
    same reading-order position, (b) carries the same language tag, and (c)
    is genuinely a closer match (lower CER) to THIS gold column than to any
    OTHER gold column (i.e. not a merge/swap)? Binary per column, averaged.
    Returns (score 0..1, human-readable notes)."""
    notes: list[str] = []
    if len(gold_cols) != len(cand_cols):
        notes.append(f"column COUNT mismatch: gold={len(gold_cols)} candidate={len(cand_cols)}")
        # Still attempt a best-effort score rather than an automatic zero —
        # a missing column is severe but the surviving ones may still be intact.
    if not gold_cols:
        return (1.0 if not cand_cols else 0.0), notes

    by_order_cand = {c.get("order"): c for c in cand_cols}
    hits = 0
    for g in gold_cols:
        order = g.get("order")
        cand = by_order_cand.get(order)
        if cand is None:
            notes.append(f"col order={order} ({g.get('language')}): NO candidate at this position")
            continue
        lang_ok = cand.get("language") == g.get("language")
        if not lang_ok:
            notes.append(
                f"col order={order}: language mismatch gold={g.get('language')} candidate={cand.get('language')}"
            )
        # Not-swapped check: this candidate column must read closer to ITS
        # gold column than to any other gold column at a different order.
        own_cer = cer(g.get("text", ""), cand.get("text", ""))
        cross_cers = [
            cer(other.get("text", ""), cand.get("text", ""))
            for other in gold_cols
            if other.get("order") != order
        ]
        not_swapped = not cross_cers or own_cer <= min(cross_cers)
        if not not_swapped:
            notes.append(f"col order={order}: reads closer to a DIFFERENT gold column — likely swapped/merged")
        if lang_ok and not_swapped:
            hits += 1
    return hits / len(gold_cols), notes


def _header_survival(gold_headers: list[str], cand_text_all: str) -> tuple[float, list[str]]:
    if not gold_headers:
        return 1.0, []
    haystack = _norm_line(cand_text_all)
    missing = []
    hits = 0
    for h in gold_headers:
        needle = _norm_line(h)
        if needle and needle in haystack:
            hits += 1
        else:
            missing.append(h)
    return hits / len(gold_headers), missing


def _table_cell_accuracy(gold_rows: list[list], cand_rows: list[list]) -> tuple[float | None, list[str]]:
    """Cell-by-cell match rate (normalized-whitespace, case-insensitive exact
    match) for row/column-aligned table gold. Returns None if row counts
    don't match at all (nothing to align cell-for-cell) rather than a
    misleading number. This — not flattened CER — is the table metric: per
    plan.md §2.1, 'CER on a table is close to meaningless (a transposed
    digit is catastrophic, a re-wrapped line is not)'."""
    if not gold_rows:
        return (1.0 if not cand_rows else None), []
    if len(gold_rows) != len(cand_rows):
        return None, [f"row COUNT mismatch: gold={len(gold_rows)} candidate={len(cand_rows)} — cells not aligned, no score"]
    notes = []
    total = 0
    hits = 0
    for ri, (grow, crow) in enumerate(zip(gold_rows, cand_rows)):
        width = max(len(grow), len(crow))
        for ci in range(width):
            gcell = _norm_line(str(grow[ci])) if ci < len(grow) else ""
            ccell = _norm_line(str(crow[ci])) if ci < len(crow) else ""
            total += 1
            if gcell == ccell:
                hits += 1
            else:
                notes.append(f"row {ri} col {ci}: gold={grow[ci] if ci < len(grow) else '<missing>'!r} candidate={crow[ci] if ci < len(crow) else '<missing>'!r}")
    return (hits / total if total else 1.0), notes


def _flatten_text(record: dict) -> str:
    """Flatten a record's body text for CER/WER + header-search purposes.

    Includes `article_headers` (Sprint 7e Round-2 Adjustment 2 — the pinned
    table-placement contract): a table page's title correctly lives ONLY in
    `article_headers`, never duplicated into `table_rows`/`columns`. Before this
    fix, a candidate that followed that exact contract could never pass header
    survival for a table title — the haystack search only ever looked at
    columns+table_rows, so a title parked (correctly) in article_headers was
    invisible to it. This was a harness bug, not an engine quality difference:
    two of the three round-2 models scored 0% for putting the title in the
    *correct* place, while the one model that scored 100% did so by *incorrectly*
    duplicating the title into table_rows (review.md §1.5 / §1.6)."""
    parts = [c.get("text", "") for c in record.get("columns", [])]
    for row in record.get("table_rows", []) or []:
        parts.append(" | ".join(str(cell) for cell in row))
    parts.extend(str(h) for h in (record.get("article_headers") or []))
    return "\n".join(parts)


def score_one(gold: dict, cand: dict) -> dict:
    gold_cols = gold.get("columns", []) or []
    cand_cols = cand.get("columns", []) or []

    result: dict = {}
    is_table = gold.get("layout") == "table" or (not gold_cols and gold.get("table_rows"))

    if is_table:
        # Table pages: column-integrity doesn't apply the same way — score
        # cell-by-cell match instead, and say so explicitly. Flattened CER/WER
        # is deliberately NOT computed here (plan.md §2.1: "CER on a table is
        # close to meaningless") — see table_cell_accuracy below instead.
        gold_rows = gold.get("table_rows", []) or []
        cand_rows = cand.get("table_rows", []) or []
        result["column_integrity"] = None
        result["column_integrity_notes"] = ["table layout — cell match used instead of column integrity"]
        result["row_count_match"] = len(gold_rows) == len(cand_rows)
        result["row_count_gold"] = len(gold_rows)
        result["row_count_candidate"] = len(cand_rows)
        accuracy, cell_notes = _table_cell_accuracy(gold_rows, cand_rows)
        result["table_cell_accuracy"] = accuracy
        result["table_cell_notes"] = cell_notes
    else:
        integrity, notes = _column_integrity(gold_cols, cand_cols)
        result["column_integrity"] = integrity
        result["column_integrity_notes"] = notes

    gold_headers = gold.get("article_headers", []) or []
    cand_text_all = _flatten_text(cand)
    survival, missing = _header_survival(gold_headers, cand_text_all)
    result["header_survival"] = survival
    result["header_survival_missing"] = missing

    if is_table:
        # Excluded from the CER/WER means the same way column_integrity is
        # excluded — a number here would silently corrupt "Mean CER" with a
        # comparison that was never apples-to-apples (see table_cell_accuracy).
        result["cer"] = None
        result["wer"] = None
    elif gold_cols and cand_cols:
        # CER/WER: aligned by order when possible, else flattened whole-page.
        by_order_cand = {c.get("order"): c for c in cand_cols}
        cers, wers = [], []
        for g in gold_cols:
            cand_col = by_order_cand.get(g.get("order"))
            if cand_col is None:
                cers.append(1.0)
                wers.append(1.0)
                continue
            cers.append(cer(g.get("text", ""), cand_col.get("text", "")))
            wers.append(wer(g.get("text", ""), cand_col.get("text", "")))
        result["cer"] = round(statistics.mean(cers), 4)
        result["wer"] = round(statistics.mean(wers), 4)
    else:
        gold_flat = _flatten_text(gold)
        result["cer"] = round(cer(gold_flat, cand_text_all), 4)
        result["wer"] = round(wer(gold_flat, cand_text_all), 4)

    ev = cand.get("_eval", {}) or {}
    result["cost_usd"] = ev.get("cost_usd")
    result["sec_per_page"] = ev.get("sec_per_page")
    result["engine"] = ev.get("engine")
    return result


def main() -> None:
    parser = argparse.ArgumentParser(description="Score OCR engine candidates against human-corrected gold.")
    parser.add_argument("--engine-dir", required=True, help="dir under out/ with one JSON per fixture, e.g. out/tesseract")
    parser.add_argument("--gold-dir", default=str(GOLD_DIR))
    args = parser.parse_args()

    gold_dir = Path(args.gold_dir)
    engine_dir = Path(args.engine_dir)

    rows = []
    for cand_path in sorted(engine_dir.glob("*.json")):
        stem = cand_path.stem
        gold_path = gold_dir / f"{stem}.json"
        if not gold_path.exists():
            print(f"SKIP {stem}: no human-corrected gold at {gold_path} "
                  f"(a .draft.json does not count — correct it first)")
            continue
        gold = json.loads(gold_path.read_text(encoding="utf-8"))
        cand = json.loads(cand_path.read_text(encoding="utf-8"))
        row = {"fixture": stem, **score_one(gold, cand)}
        rows.append(row)

    if not rows:
        print("No scored fixtures — no gold/*.json (human-corrected) found yet. "
              "Draft correction is the checkpoint; nothing to score until it's done.")
        return

    print(f"\n{'fixture':<45} {'col_integrity':>13} {'header_surv':>12} {'CER':>7} {'WER':>7} {'sec/pg':>8} {'cost':>8}")
    for r in rows:
        ci = "n/a (table)" if r["column_integrity"] is None else f"{r['column_integrity']*100:.0f}%"
        if r["cer"] is None:
            acc = r.get("table_cell_accuracy")
            cer_str = "  n/a  "
            wer_str = f"cells={acc*100:.0f}%" if acc is not None else "cells=n/a"
        else:
            cer_str = f"{r['cer']:>7.3f}"
            wer_str = f"{r['wer']:>7.3f}"
        print(
            f"{r['fixture']:<45} {ci:>13} {r['header_survival']*100:>11.0f}% "
            f"{cer_str:>7} {wer_str:>7} {r['sec_per_page'] or 0:>8.2f} {r['cost_usd'] or 0:>8.4f}"
        )
        if r["column_integrity"] is None:
            match = "match" if r["row_count_match"] else "MISMATCH"
            print(f"    (table: {r['row_count_gold']} gold rows vs {r['row_count_candidate']} candidate rows — {match})")

    ci_scores = [r["column_integrity"] for r in rows if r["column_integrity"] is not None]
    if ci_scores:
        print(f"\nMean column integrity (non-table fixtures): {statistics.mean(ci_scores)*100:.1f}%")
    table_acc = [r["table_cell_accuracy"] for r in rows if r.get("table_cell_accuracy") is not None]
    if table_acc:
        print(f"Mean table cell accuracy (table fixtures): {statistics.mean(table_acc)*100:.1f}%")
    print(f"Mean header survival: {statistics.mean(r['header_survival'] for r in rows)*100:.1f}%")
    cers = [r["cer"] for r in rows if r["cer"] is not None]
    wers = [r["wer"] for r in rows if r["wer"] is not None]
    if cers:
        print(f"Mean CER (non-table fixtures): {statistics.mean(cers):.4f}")
        print(f"Mean WER (non-table fixtures): {statistics.mean(wers):.4f}")
    total_cost = sum(r["cost_usd"] or 0 for r in rows)
    print(f"Total cost across fixtures: ${total_cost:.4f}")


if __name__ == "__main__":
    main()
