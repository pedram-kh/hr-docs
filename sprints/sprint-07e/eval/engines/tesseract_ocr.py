"""Tesseract OCR candidate — EVAL ONLY (Sprint 7e, plan.md §2.4).

Runs system `tesseract` (via `pytesseract`) with `spa+eus` traineddata and a
layout-analysis PSM, keeping the TSV word-level bounding boxes — this is the
ingredient "Option A" (plan.md §3.2) needs: Tesseract does not know Spanish
from Basque, so the *existing, tested* column/bilingual logic in
`hr-ai/app/chunking/extract_columns.py` should make that call, not a
reimplementation of it. This script therefore imports `_classify_page` and
`_es_ratio` DIRECTLY from hr-ai (same monorepo checkout) rather than
duplicating that logic — the eval exercises the exact code path Option A
would splice OCR blocks into, in miniature (one synthetic "page" per fixture).

No LLM anywhere in this path — Tesseract has no cleanup step by construction,
which is exactly the "no LLM cleanup" constraint applied to its logical
extreme.
"""

from __future__ import annotations

import re
import sys
import time
from pathlib import Path

import pytesseract
from PIL import Image

# Import the REAL production column/bilingual-gate logic from hr-ai, not a
# reimplementation (plan.md §3.2 Option A's whole point).
_HR_AI_ROOT = Path(__file__).resolve().parents[5] / "hr-ai"
if str(_HR_AI_ROOT) not in sys.path:
    sys.path.insert(0, str(_HR_AI_ROOT))
from app.chunking.extract_columns import _classify_page, _es_ratio  # noqa: E402

LANGS = "spa+eus"

# Article/section header patterns — a lightweight eval-only regex (NOT the
# guarded, load-bearing detector in hr-ai/app/chunking/chunker.py; good enough
# to score header survival, not to ship).
_HEADER_RE = re.compile(
    r"^(Art[íi]culo\s+\d+|ART\.?\s*\d+|CAP[ÍI]TULO\s+[IVXLCDM]+|"
    r"Disposici[óo]n\s+\w+|\d+\.\s*artikulua)",
    re.IGNORECASE,
)


def _tsv_to_blocks(image: Image.Image, lang: str, psm: int) -> tuple[list[dict], float, float]:
    """Run tesseract image_to_data and aggregate words into paragraph-level
    blocks with pixel-space bboxes — the same shape Pass 1 of
    `extract_language_streams` builds (page/pw/ph/x0/x1/y0/y1/x_mid/text),
    just in pixel space instead of PDF points (both are page-relative
    fractions once normalized by pw/ph, which is all `_classify_page` uses)."""
    pw, ph = float(image.width), float(image.height)
    config = f"--psm {psm}"
    data = pytesseract.image_to_data(image, lang=lang, config=config, output_type=pytesseract.Output.DICT)

    groups: dict[tuple[int, int], dict] = {}
    n = len(data["level"])
    for i in range(n):
        text = (data["text"][i] or "").strip()
        if not text:
            continue
        conf = data["conf"][i]
        try:
            conf = float(conf)
        except (TypeError, ValueError):
            conf = -1.0
        key = (data["block_num"][i], data["par_num"][i])
        left, top, width, height = data["left"][i], data["top"][i], data["width"][i], data["height"][i]
        g = groups.setdefault(
            key,
            {"words": [], "x0": left, "y0": top, "x1": left + width, "y1": top + height, "confs": []},
        )
        g["words"].append(text)
        g["x0"] = min(g["x0"], left)
        g["y0"] = min(g["y0"], top)
        g["x1"] = max(g["x1"], left + width)
        g["y1"] = max(g["y1"], top + height)
        if conf >= 0:
            g["confs"].append(conf)

    blocks: list[dict] = []
    for g in groups.values():
        text = " ".join(g["words"])
        x0, y0, x1, y1 = float(g["x0"]), float(g["y0"]), float(g["x1"]), float(g["y1"])
        blocks.append(
            {
                "page": 1,
                "pw": pw,
                "ph": ph,
                "x0": x0,
                "x1": x1,
                "y0": y0,
                "y1": y1,
                "x_mid": (x0 + x1) / 2.0,
                "width": x1 - x0,
                "text": text,
                "furniture": False,
                "mean_conf": (sum(g["confs"]) / len(g["confs"])) if g["confs"] else None,
            }
        )
    # Reading order for a single column / before geometry decides: top-to-bottom.
    blocks.sort(key=lambda b: b["y0"])
    return blocks, pw, ph


def _article_headers(text: str) -> list[str]:
    headers = []
    for line in text.splitlines():
        line = line.strip()
        if line and _HEADER_RE.match(line):
            headers.append(line)
    return headers


def ocr_page(image_path: str | Path, psm: int = 3) -> dict:
    """OCR one page image with Tesseract, then run the SAME column/bilingual
    decision `extract_language_streams` runs (Pass 3 shape), reusing the real
    functions. Returns the gold-shaped dict plus `_eval` metadata."""
    image_path = Path(image_path)
    image = Image.open(image_path)

    started = time.monotonic()
    blocks, pw, ph = _tsv_to_blocks(image, LANGS, psm)
    layout = _classify_page(blocks, pw, ph)
    elapsed_s = time.monotonic() - started

    mean_confs = [b["mean_conf"] for b in blocks if b["mean_conf"] is not None]
    mean_conf = (sum(mean_confs) / len(mean_confs)) if mean_confs else None

    center = pw / 2.0
    left_blocks = [b for b in blocks if b["x_mid"] < center]
    right_blocks = [b for b in blocks if b["x_mid"] >= center]

    if layout["tabular"]:
        # Best-effort row grouping by y-proximity, cells left-to-right — good
        # enough to score table-cell CER; not a shipped table reconstructor.
        rows: list[list[str]] = []
        row_tol = 0.015 * ph
        sorted_blocks = sorted(blocks, key=lambda b: (b["y0"], b["x0"]))
        current_row: list[dict] = []
        current_y = None
        for b in sorted_blocks:
            if current_y is None or abs(b["y0"] - current_y) <= row_tol:
                current_row.append(b)
                current_y = b["y0"] if current_y is None else current_y
            else:
                rows.append([c["text"] for c in sorted(current_row, key=lambda c: c["x0"])])
                current_row = [b]
                current_y = b["y0"]
        if current_row:
            rows.append([c["text"] for c in sorted(current_row, key=lambda c: c["x0"])])
        full_text = "\n".join(" | ".join(r) for r in rows)
        result = {
            "layout": "table",
            "columns": [],
            "table_rows": rows,
            "article_headers": _article_headers(full_text),
        }
    elif layout["two_column"]:
        lr = _es_ratio(" ".join(b["text"] for b in left_blocks))
        rr = _es_ratio(" ".join(b["text"] for b in right_blocks))
        bilingual = (lr < 0.05 and rr > 0.07) or (rr < 0.05 and lr > 0.07)
        left_text = "\n".join(b["text"] for b in sorted(left_blocks, key=lambda b: b["y0"]))
        right_text = "\n".join(b["text"] for b in sorted(right_blocks, key=lambda b: b["y0"]))
        if bilingual:
            left_lang, right_lang = ("eu", "es") if lr <= rr else ("es", "eu")
        else:
            left_lang, right_lang = "es", "es"
        result = {
            "layout": "two_column_bilingual" if bilingual else "two_column_monolingual",
            "columns": [
                {"order": 0, "language": left_lang, "text": left_text},
                {"order": 1, "language": right_lang, "text": right_text},
            ],
            "table_rows": [],
            "article_headers": _article_headers(left_text) + _article_headers(right_text),
        }
    else:
        full_text = "\n".join(b["text"] for b in sorted(blocks, key=lambda b: b["y0"]))
        ratio = _es_ratio(full_text)
        result = {
            "layout": "single_column",
            "columns": [{"order": 0, "language": "es" if ratio > 0.03 else "unknown", "text": full_text}],
            "table_rows": [],
            "article_headers": _article_headers(full_text),
        }

    result["_eval"] = {
        "engine": "tesseract",
        "psm": psm,
        "langs": LANGS,
        "sec_per_page": round(elapsed_s, 3),
        "cost_usd": 0.0,  # local CPU, no metered API cost
        "mean_word_conf": round(mean_conf, 2) if mean_conf is not None else None,
        "block_count": len(blocks),
        "classify_raw": layout,
    }
    return result


if __name__ == "__main__":
    import argparse
    import json

    parser = argparse.ArgumentParser(description="OCR one page image with Tesseract (eval only).")
    parser.add_argument("image", help="path to the page JPEG/PNG")
    parser.add_argument("--psm", type=int, default=3, help="Tesseract page-segmentation mode (default 3)")
    parser.add_argument("--out", help="write JSON result here (default: stdout)")
    args = parser.parse_args()

    result = ocr_page(args.image, psm=args.psm)
    out_json = json.dumps(result, ensure_ascii=False, indent=2)
    if args.out:
        Path(args.out).write_text(out_json, encoding="utf-8")
    else:
        print(out_json)
