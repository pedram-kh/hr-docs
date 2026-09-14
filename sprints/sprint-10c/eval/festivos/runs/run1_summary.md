# festivos eval — run 1 (final, no iteration needed)

Live run: `facts:segment-topic festivos --convenio=2 --convenio=3 --convenio=10 --convenio=18 --convenio=20 --convenio=25 -v`

| convenio_id | facts created | fact id(s) | `text_truncated` |
|---|---|---|---|
| 2  | 1 | 165 | false |
| 3  | 0 | — | false |
| 10 | 1 | 166 | false |
| 18 | 0 | — | false |
| 20 | 0 | — | false |
| 25 | 0 | — | false |

Scorer result per convenio (see `score_eval.py` output, this file's sibling
`facts_cN.json` exports): **6/6 clean** — correct=1/1 (c.2), correct=1/1
(c.10), restraint-failures=0/1 on every negative convenio (c.3/18/20/25),
0 mis-scoped, 0 wrong-value, 0 missed, 0 unaccounted.

No superseded/deleted facts this run — eval-iteration hygiene (D-decision,
CP-A) is a no-op here since run 1 passed outright.

Note (not a scoring issue, recorded for completeness): c.10's fact (id 166)
bundled 23.4's libranza rule together with a related clause found on a
LATER page (p38, inside the Vacaciones article: "no se computarán como
disfrutados los días festivos... que coincidan con el período... de
vacaciones") — both describe which days are festivos, just from two
different articles. This is not a restraint failure against this gold set
(no euro figure or unrelated retribución content was pulled in — the
positive keywords all matched, the negative 23.3 keywords did not), and
factually nothing is wrong in the fact's value, but it is a cross-article
merge ADR-0034's granularity principle would normally flag if it introduced
a genuinely separate provision. Recorded here for the review, not treated
as a failure.
