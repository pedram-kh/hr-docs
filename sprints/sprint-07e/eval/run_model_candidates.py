"""Run additional Claude-vision model candidates against all 5 fixtures and
write each straight to `out/<model>/<stem>.json` — same shape as the frozen
`out/claude_vision/` (claude-sonnet-4-5) candidates, ready for score_ocr.py.

Usage: python3 run_model_candidates.py claude-sonnet-5 claude-opus-5

Same prompt, same fixtures, same call shape as the original claude-sonnet-4-5
run (`draft_gold.py` / `engines/claude_vision.py`) — only the model string
changes, so this is a like-for-like comparison, not a re-tuned one.
"""

from __future__ import annotations

import json
import os
import sys
from pathlib import Path

from engines.claude_vision import ocr_page

EVAL_ROOT = Path(__file__).resolve().parent
FIXTURES_DIR = EVAL_ROOT / "fixtures"
OUT_DIR = EVAL_ROOT / "out"

FIXTURES = [
    "doc18-gipuzkoa-limpieza-p10-twocol-eseu.jpg",
    "doc18-gipuzkoa-limpieza-p20-twocol-eseu.jpg",
    "doc50-deporte-navarra-p05-singlecol-es.jpg",
    "doc50-deporte-navarra-p26-table-annex.jpg",
    "doc69-pacto-p03-lowquality-marginalia.jpg",
]


def run_model(model: str, api_key: str) -> float:
    model_dir = OUT_DIR / model
    model_dir.mkdir(parents=True, exist_ok=True)
    total_cost = 0.0
    for name in FIXTURES:
        image_path = FIXTURES_DIR / name
        if not image_path.exists():
            print(f"  SKIP (missing): {name}")
            continue
        stem = image_path.stem
        print(f"  {model}: {name} ...", flush=True)
        result = ocr_page(image_path, api_key=api_key, model=model)
        cost = result.get("_eval", {}).get("cost_usd", 0.0)
        total_cost += cost
        out_path = model_dir / f"{stem}.json"
        out_path.write_text(json.dumps(result, ensure_ascii=False, indent=2), encoding="utf-8")
        sec = result.get("_eval", {}).get("sec_per_page")
        print(f"    -> {out_path} (layout={result.get('layout')}, sec={sec}, cost=${cost:.4f})")
    return total_cost


def main() -> None:
    models = sys.argv[1:]
    if not models:
        raise SystemExit("Usage: run_model_candidates.py <model-id> [<model-id> ...]")
    api_key = os.environ.get("ANTHROPIC_API_KEY", "")
    if not api_key:
        raise SystemExit("ANTHROPIC_API_KEY not set in the environment.")

    for model in models:
        print(f"=== {model} ===")
        total = run_model(model, api_key)
        print(f"  total cost for {model}: ${total:.4f}\n")


if __name__ == "__main__":
    main()
