"""Sprint 13 CP-1 catalogue discovery probe — READ-ONLY, allowlisted domains only.

Fetches an index page with the lane's own TLS context (certifi + bundled
intermediates, verification on) and prints links whose text/href match the
topic keywords, so candidate catalogue URLs come from a real page's own link
graph rather than memory. Prints `LINK <text> | <absolute url>` lines.

    python3 catalogue_crawl.py <index-url> [keyword ...]

Run inside the hr-ai container (PYTHONPATH=/app) or locally from hr-ai/.
"""

from __future__ import annotations

import re
import sys
import unicodedata
from html.parser import HTMLParser
from urllib.parse import urljoin, urlsplit

sys.path.insert(0, "/app")
import httpx  # noqa: E402

from app.general_lane import USER_AGENT, _default_ssl_context, _fold  # noqa: E402

ALLOWED = ("boe.es", "mites.gob.es", "seg-social.es", "sepe.es")
DEFAULT_KEYS = ["excedencia", "incapacidad temporal", "baja", "permiso", "periodo de prueba", "jornada", "finiquito", "conciliacion", "vacaciones"]


class Links(HTMLParser):
    def __init__(self) -> None:
        super().__init__(convert_charrefs=True)
        self.links: list[tuple[str, str]] = []
        self._href: str | None = None
        self._text: list[str] = []

    def handle_starttag(self, tag, attrs):
        if tag == "a":
            self._href = dict(attrs).get("href")
            self._text = []

    def handle_data(self, data):
        if self._href is not None:
            self._text.append(data.strip())

    def handle_endtag(self, tag):
        if tag == "a" and self._href:
            self.links.append((" ".join(t for t in self._text if t), self._href))
            self._href = None


def main() -> int:
    url = sys.argv[1]
    keys = [_fold(k) for k in (sys.argv[2:] or DEFAULT_KEYS)]
    host = urlsplit(url).hostname or ""
    if not any(host == d or host.endswith("." + d) for d in ALLOWED):
        print("refusing: host not on the lane allowlist")
        return 2
    r = httpx.get(url, headers={"User-Agent": USER_AGENT}, verify=_default_ssl_context(), timeout=15, follow_redirects=True)
    print(f"INDEX {r.status_code} {len(r.content)}B {r.url}")
    p = Links()
    p.feed(r.text)
    seen = set()
    for text, href in p.links:
        abs_url = urljoin(str(r.url), href)
        h = urlsplit(abs_url).hostname or ""
        if not any(h == d or h.endswith("." + d) for d in ALLOWED) or abs_url in seen:
            continue
        hay = _fold(text + " " + unicodedata.normalize("NFKD", abs_url))
        if any(k in hay for k in keys):
            seen.add(abs_url)
            print(f"LINK {text[:70]} | {abs_url}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
