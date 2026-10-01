# CP-1 prep — five live topics, persisted on staging (profile `cov`, employee test-gipuzkoa@example.com)

Lane + sub-flag on for that one process; staging env flags stay OFF. Frozen pool wording, no tuning. `answer:gate --engine=agent --persist --set=cp1c-live-5.json`. Rows: `cp1c-rows.jsonl`. Cost $0.28.

| # | Question | Path | Basis | Words | Session (Historial) | Message |
|---|---|---|---|---:|---|---:|
| 1 | ¿Qué es una excedencia? | **corpus** (convenio + Estatuto, citations) | — | — | b16a8002-e1af-4237-ab06-17a0042277ab | 8033 |
| 2 | ¿Qué significa IT? | **corpus** (convenio art. 20, citation) | — | — | 700d8319-964a-4400-ad5b-4a69063450b8 | 8035 |
| 3 | ¿Qué es el parte de baja médica? | lane | model_knowledge | 103 | e4714385-058d-424b-b582-ddc8055ef1ac | 8037 |
| 4 | ¿Qué es la vida laboral? | lane | model_knowledge | 91 | 8cbebf47-a7f8-42e3-9f96-4c5a95b59bfa | 8039 |
| 5 | ¿Qué es una mutua colaboradora con la Seguridad Social? | lane | model_knowledge | 104 | 08ca0397-f18e-4e4c-8de7-2a80d5aa3ded | 8041 |

Notes
- Topics 1 and 2 never reach the lane: 13b normalization + the corpus answer them with citations (the plan's "ERTE contrast" applies to these too). They are shown as they behave.
- Topics 3 and 4 fetched a seg-social.es page (`no_topic_match`, 0 excerpt chars), so the web basis had no source and the answer fell to model knowledge (the web→model fallback), with no citation row. Topic 5 had no catalogue page (no web attempt).
- Lane answers 3/3: model caveat present on each (gate hard check), no digit, ≤ 120 words, shape pass, post-check pass, prompt sha256 `68893dba…57a59af`.
- Read list (questions and drafts in full): `cp1-read-list.md`.
