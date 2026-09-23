# Screenshots

Staging `http://52.211.251.235`, locale `es`, light theme, signed in as `admin@hr-staging.internal` unless noted. Viewport 1440×900. Captured by `../capture.sh`.

| File | Screen | What is in the frame |
|---|---|---|
| `01-map-jerarquia.png` | `/admin` Conocimiento · Mapa, Jerarquía | Territory lens, graph form, coverage-gap bar, `+ New reference fact`. |
| `02-map-grafo.png` | `/admin#view=map&tab=grafo` | 3D graph, territory chips, `Ocultar históricos` / `Ocultar IA sin verificar`. Legend in frame: `Fucsia = IA sin verificar`. |
| `03-documents.png` | `/admin#view=documents` | Document table, FilterToolbar open, no filter selected. |
| `04-document-detail.png` | Documentos, first row open | Document detail drawer. |
| `05-filter-toolbar.png` | Documentos, FilterToolbar | `Filtros` badge `1`, `Limpiar filtros`, status `Under review`, `Conflicts only`. |
| `10-review-ai-tagging.png` | `/admin#view=review` tab AI tagging | Tagging queue. |
| `11-review-reference-facts.png` | Revisión, Reference facts | Fact queue. |
| `12-review-fact-panel.png` | Revisión, fact drawer | Fuchsia `AI proposal` / `needs review` on an unverified fact. |
| `13-review-groups.png` | Revisión, Groups | Convenio group list. |
| `14-review-vocabulary.png` | Revisión, Vocabulary proposals | Proposal list or its empty state. |
| `15-review-expiry.png` | Revisión, Expiry | Expiry queue or its empty state. |
| `20-escalations-board.png` | `/admin#view=escalations` | Five columns. |
| `21-escalation-card.png` | Escalaciones, drawer | Card for a Test Employee fixture, explanation and `Corregir`. |
| `22-history.png` | `/admin#view=history` | Conversation list, FilterToolbar open, fixture employees only. |
| `23-history-trace.png` | Historial, answered row | Trace open on a Respondida conversation (not escalated). Steps in frame: Ámbito resuelto, Salvaguarda, Enrutado, Salario (SQL), Decisión. |
| `30-analytics.png` | `/admin#view=analytics` | Analítica. |
| `31-cobertura.png` | `/admin#view=coverage` | Cobertura. |
| `32-calidad.png` | `/admin#view=quality` | Calidad. |
| `40-directory.png` | `/admin#view=directory` | Search `test`. Visible emails are `@example.com` or `@hr-staging.internal` only. |
| `41-admins.png` | `/admin#view=admins` | The four staging test admins. |
| `42-guardrails.png` | `/admin#view=guardrails` | Guardarraíles. |
| `43-settings.png` | `/admin#view=settings` | Answer model. |
| `50-chat-welcome.png` | `/app`, `test-andalucia-nocat@example.com` | Empty conversation: greeting and the five suggested questions. No message sent. |
| `51-chat-answer.png` | `/app`, `test-navarra@example.com` | Question `¿Cuántos días de vacaciones me corresponden al año?`, the answer, and `Basado en:`. The employee chat has no `Fuentes` list; that block is admin-only. |
| `52-chat-escalation.png` | `/app`, same session | `Quiero hablar con una persona de Recursos Humanos` and the badge `Escalado a Recursos Humanos`. |

Opening Historial writes a `conversation_access_log` row. Re-capture with `bash hr-docs/guides/capture.sh` (`CAPTURE=gaps` retakes 02, 23, 40, 50–52). Needs the staging SSH key at `~/.hr-staging/hr-staging-ec2-key.pem` and `MAIL_MAILER=log` on the hr-backend container.
