"""Draft gold transcriptions for every fixture with Claude vision (Sprint 7e,
plan.md §2.2 — "draft-then-human-correct, with a hard checkpoint").

This produces `gold/<fixture>.draft.json` — a DRAFT, not gold. Per the
build-authorization prompt's resolved Q1: no engine is scored and no engine
is chosen until Pedram corrects these drafts (the two two-column fixtures
line-by-line against the image, the other three a lighter pass) and the
corrected files are saved as `gold/<fixture>.json` (no `.draft` suffix).

The drafting engine (Claude vision) is not exempt from the eval once gold is
corrected — it is scored against the human-corrected result like every other
candidate, so it is never graded on its own homework.
"""

from __future__ import annotations

import json
import os
from pathlib import Path

from engines.claude_vision import ocr_page

EVAL_ROOT = Path(__file__).resolve().parent
FIXTURES_DIR = EVAL_ROOT / "fixtures"
GOLD_DIR = EVAL_ROOT / "gold"

FIXTURES = [
    "doc18-gipuzkoa-limpieza-p10-twocol-eseu.jpg",
    "doc18-gipuzkoa-limpieza-p20-twocol-eseu.jpg",
    "doc50-deporte-navarra-p05-singlecol-es.jpg",
    "doc50-deporte-navarra-p26-table-annex.jpg",
    "doc69-pacto-p03-lowquality-marginalia.jpg",
]


def main() -> None:
    api_key = os.environ.get("ANTHROPIC_API_KEY", "")
    if not api_key:
        raise SystemExit("ANTHROPIC_API_KEY not set in the environment.")

    GOLD_DIR.mkdir(parents=True, exist_ok=True)
    total_cost = 0.0

    for name in FIXTURES:
        image_path = FIXTURES_DIR / name
        if not image_path.exists():
            print(f"SKIP (missing): {name}")
            continue
        stem = image_path.stem
        print(f"Drafting: {name} ...", flush=True)
        result = ocr_page(image_path, api_key=api_key)
        cost = result.get("_eval", {}).get("cost_usd", 0.0)
        total_cost += cost
        out_path = GOLD_DIR / f"{stem}.draft.json"
        out_path.write_text(json.dumps(result, ensure_ascii=False, indent=2), encoding="utf-8")
        print(
            f"  -> {out_path.name} "
            f"(layout={result.get('layout')}, sec={result.get('_eval', {}).get('sec_per_page')}, "
            f"cost=${cost:.4f})"
        )

    print(f"\nTotal drafting cost: ${total_cost:.4f}")
    print("\nDrafts written to gold/*.draft.json — NOT gold yet.")
    print("Next: Pedram corrects each draft against the fixture image, then saves as")
    print("gold/<stem>.json (drop the .draft suffix). Only then does scoring/decision happen.")


if __name__ == "__main__":
    main()
