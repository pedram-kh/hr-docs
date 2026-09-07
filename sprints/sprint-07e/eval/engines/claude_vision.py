"""Claude-vision OCR candidate — EVAL ONLY (Sprint 7e, plan.md §2.4).

Not part of hr-ai. This calls the Anthropic Messages API directly with an
image content block, mirroring the per-call-key idiom already established in
`hr-ai/app/providers/claude.py` (ADR-0015) — but there is no vision method on
`AnswerProvider` today, and adding one is an integration-phase decision gated
on this eval's outcome (plan.md §1.6), not a prerequisite for running it.

Output shape mirrors the gold JSON shape (plan.md §2.1): the model is prompted
to read the page in visual reading order, self-report which column is which
language, and flag article/section headers verbatim — exactly the three
properties the pipeline needs (column integrity, es/eu tagging, header
survival) and exactly what "Option B" (plan.md §3.2) would need in production
if this engine wins: pre-split, pre-tagged stream units, no PyMuPDF geometry.

No LLM "cleanup": the model is instructed to transcribe verbatim, never to
correct, modernize, or complete the source text.
"""

from __future__ import annotations

import base64
import json
import re
import time
from pathlib import Path

import anthropic

MODEL = "claude-sonnet-4-5"  # matches hr-ai's ANSWER_MODEL convention (config.py:91)

# Anthropic's published per-token prices, checked 2026-09-07 (platform.claude.com
# pricing + model overview pages) — recorded here, per-model, so cost/page is
# computed the same way for every candidate. Update if re-run after a price
# change, and note the date in review.md if so.
PRICING_PER_MTOK: dict[str, tuple[float, float]] = {
    # model: (input $/MTok, output $/MTok)
    "claude-sonnet-4-5": (3.00, 15.00),
    "claude-sonnet-5": (2.00, 10.00),
    "claude-opus-5": (5.00, 25.00),
}
# Fallback if a model isn't in the table above — same as claude-sonnet-4-5,
# loudly wrong is better than silently wrong, so this only kicks in with an
# unrecognized model string.
_DEFAULT_PRICING = (3.00, 15.00)

SYSTEM_PROMPT = (
    "Eres un transcriptor OCR. Tu única tarea es TRANSCRIBIR EXACTAMENTE el "
    "texto visible en la imagen de una página escaneada de un convenio "
    "colectivo español (a veces bilingüe euskera/castellano). NUNCA corrijas, "
    "completes, modernices, resumas ni \"limpies\" el texto — transcribe "
    "literalmente lo que ves, incluyendo erratas, mayúsculas y saltos de "
    "línea de artículo. Si una palabra es ilegible, escribe [ilegible] en su "
    "lugar en vez de inventarla.\n\n"
    "PASO 1 — determina el layout de la página:\n"
    '  - "two_column_bilingual": dos columnas verticales separadas por un '
    "gutter central, cada una en un idioma distinto (euskera / castellano).\n"
    '  - "two_column_monolingual": dos columnas verticales, ambas en '
    "castellano (layout tipo periódico, NO bilingüe).\n"
    '  - "single_column": una sola columna de prosa (aunque tenga un margen '
    "o índice lateral corto).\n"
    '  - "table": una tabla/rejilla salarial o anexo con filas y columnas de '
    "datos, sin prosa corrida.\n\n"
    "PASO 2 — transcribe según el layout:\n"
    '  - two_column_bilingual / two_column_monolingual: devuelve "columns" '
    "con DOS entradas, cada una con su \"order\" (0=izquierda, 1=derecha), "
    "su \"language\" (\"es\" o \"eu\" — para monolingüe ambas \"es\"), y su "
    "\"text\" completo en orden de lectura de arriba a abajo DENTRO de esa "
    "columna (nunca intercales texto de la otra columna).\n"
    '  - single_column: devuelve "columns" con UNA entrada, order=0, '
    "language=\"es\" o \"eu\".\n"
    '  - table: CONTRATO DE COLOCACIÓN FIJO para páginas de tabla (nunca lo '
    "dejes a tu criterio) — \"table_rows\" contiene EXCLUSIVAMENTE la rejilla "
    "de filas/columnas de datos (cabecera de columnas + filas de valores), "
    "una lista de listas de celdas, izquierda a derecha, arriba a abajo. Un "
    "título de tabla/anexo (p. ej. \"ANEXO I: TABLA SALARIAL...\") NUNCA es "
    "una fila de table_rows — va SOLO en \"article_headers\" (paso 3). Un "
    "pie de tabla o nota a pie (p. ej. \"Plus Festivo: 3,18 €/h.\", "
    "\"Kilometraje: 0,21 €/km.\") NUNCA es una fila de table_rows — va en "
    "\"columns\" como UNA entrada order=0, language=\"es\", con todas las "
    "líneas de nota unidas por salto de línea. Si no hay título o no hay "
    "notas al pie, simplemente omite esa parte (no inventes una entrada "
    "vacía). table_rows queda EXACTAMENTE del mismo tamaño que la rejilla "
    "visible — nunca una fila más por el título, nunca una fila más por "
    "una nota.\n\n"
    "PASO 3 — \"article_headers\": lista TODAS las cabeceras de artículo/"
    "capítulo/disposición que veas literalmente como aparecen (p. ej. "
    "\"Artículo 22\", \"22. artikulua\", \"CAPÍTULO V\", \"Disposición "
    "adicional primera\"), UNA por línea de cabecera, en el orden en que "
    "aparecen en la página. En una página \"table\", el TÍTULO de la tabla/"
    "anexo (p. ej. \"ANEXO I: TABLA SALARIAL DE 1-1-2025 A 31-12-2025\") es "
    "TAMBIÉN una cabecera y va aquí, no en table_rows.\n\n"
    "FORMATO DE SALIDA: devuelve EXCLUSIVAMENTE un objeto JSON válido, sin "
    "texto alrededor ni backticks:\n"
    '{"layout": "two_column_bilingual|two_column_monolingual|single_column|table", '
    '"columns": [{"order": 0, "language": "es|eu", "text": "..."}], '
    '"table_rows": [["cell", "cell"]], '
    '"article_headers": ["..."]}'
)

USER_PROMPT = (
    "Transcribe esta página escaneada siguiendo exactamente las instrucciones "
    "del sistema. Devuelve solo el JSON."
)


def _extract_json(text: str) -> dict:
    text = text.strip()
    fence = re.search(r"```(?:json)?\s*(\{.*\})\s*```", text, re.DOTALL)
    if fence:
        text = fence.group(1)
    else:
        brace = re.search(r"\{.*\}", text, re.DOTALL)
        if brace:
            text = brace.group(0)
    return json.loads(text)


def ocr_page(image_path: str | Path, api_key: str, model: str = MODEL) -> dict:
    """OCR one page image with Claude vision. Returns the gold-shaped dict plus
    an `_eval` metadata block (cost/page, sec/page, raw usage) — the `_eval`
    key is stripped before anything is treated as a gold/candidate record."""
    image_path = Path(image_path)
    media_type = "image/jpeg" if image_path.suffix.lower() in (".jpg", ".jpeg") else "image/png"
    image_b64 = base64.standard_b64encode(image_path.read_bytes()).decode("ascii")

    client = anthropic.Anthropic(api_key=api_key)

    started = time.monotonic()
    resp = client.messages.create(
        model=model,
        max_tokens=4096,
        system=SYSTEM_PROMPT,
        messages=[
            {
                "role": "user",
                "content": [
                    {
                        "type": "image",
                        "source": {"type": "base64", "media_type": media_type, "data": image_b64},
                    },
                    {"type": "text", "text": USER_PROMPT},
                ],
            }
        ],
    )
    elapsed_s = time.monotonic() - started

    raw_text = "".join(block.text for block in resp.content if getattr(block, "type", None) == "text")
    in_tok = getattr(resp.usage, "input_tokens", 0) or 0
    out_tok = getattr(resp.usage, "output_tokens", 0) or 0
    price_in, price_out = PRICING_PER_MTOK.get(model, _DEFAULT_PRICING)
    cost = (in_tok / 1_000_000) * price_in + (out_tok / 1_000_000) * price_out

    try:
        envelope = _extract_json(raw_text)
    except (json.JSONDecodeError, ValueError) as exc:
        envelope = {
            "layout": "parse_error",
            "columns": [],
            "table_rows": [],
            "article_headers": [],
            "_raw_text": raw_text,
            "_parse_error": str(exc),
        }

    envelope["_eval"] = {
        "engine": "claude_vision",
        "model": model,
        "sec_per_page": round(elapsed_s, 3),
        "input_tokens": in_tok,
        "output_tokens": out_tok,
        "cost_usd": round(cost, 6),
    }
    return envelope


if __name__ == "__main__":
    import argparse
    import os

    parser = argparse.ArgumentParser(description="OCR one page image with Claude vision (eval only).")
    parser.add_argument("image", help="path to the page JPEG/PNG")
    parser.add_argument("--out", help="write JSON result here (default: stdout)")
    parser.add_argument("--api-key-file", help="path to a file containing the API key (one line)")
    parser.add_argument("--model", default=MODEL, help=f"Anthropic model id (default: {MODEL})")
    args = parser.parse_args()

    if args.api_key_file:
        api_key = Path(args.api_key_file).read_text().strip()
    else:
        api_key = os.environ.get("ANTHROPIC_API_KEY", "")
    if not api_key:
        raise SystemExit("No API key: set ANTHROPIC_API_KEY or pass --api-key-file")

    result = ocr_page(args.image, api_key, model=args.model)
    out_json = json.dumps(result, ensure_ascii=False, indent=2)
    if args.out:
        Path(args.out).write_text(out_json, encoding="utf-8")
    else:
        print(out_json)
