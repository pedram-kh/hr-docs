"""One-shot BGE-M3 model warm-up for hr-ai on staging.

Gap found live in Session 2 (confirmed by reading hr-ai/app/embeddings.py):
hr-ai loads the model LAZILY, on first `/embed` or `/retrieve` call, by
design (no `@app.on_event("startup")`/lifespan hook exists in
hr-ai/app/main.py). Left alone, the model would never be pre-cached "on
first `docker compose up`" the way the staging plan intended (§3.1) — it
would only download whenever a real user/job happened to trigger the first
embed, and `/health/model` would sit `503` indefinitely until then (proven:
it did, for 5+ minutes with nothing else changing).

This script forces the SAME download+cache hr-ai's own embeddings.py would
eventually trigger, using the SAME sentence-transformers library (already
in hr-ai/requirements.txt) and respecting the SAME $HF_HOME hr-ai's
Dockerfile sets — it does not import any hr-ai app code and does not change
hr-ai's lazy-loading design. It just runs once, explicitly, before uvicorn
starts, so the container's own healthcheck (`/health/model`) reflects reality
sooner than "whenever the first request happens to arrive."

Lives in hr-docs (ops tooling), bind-mounted into the hr-ai container by
docker-compose.staging.yml — not part of the hr-ai repo.
"""

import os

from sentence_transformers import SentenceTransformer

model_name = os.environ.get("EMBED_MODEL_HF", "BAAI/bge-m3")
print(f"[warm-model] loading {model_name} into HF_HOME={os.environ.get('HF_HOME')} ...", flush=True)
SentenceTransformer(model_name, device="cpu")
print("[warm-model] done.", flush=True)
