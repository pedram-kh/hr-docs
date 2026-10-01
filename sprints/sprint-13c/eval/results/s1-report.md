# S1 — forced-lane positives ×1 (2026-09-30)

Harness `probes/lane-forced-13c.php`, staging, profile cov, sub-flag forced on in-process (the staging env flags stay OFF), all 33 survivors, 1 repeat.
Raw per-draft log: `s1-lane-forced-raw.log`. Spend ≈ $0.30 (drafts $0.216 + 3 web `/ground` ≈ $0.04 + 2 empty web drafts $0.026 + 1 diagnostic call $0.013).

| | n |
|---|---|
| rows | 33 |
| model-basis drafts (reached the locks) | 28 |
| blocked by post-check | 6 (E2 ×4, A1 ×1, E3 ×1) |
| blocked by shape check | 4 (S2 ×4; S1 ×0, S3 ×0) |
| passed clean | 17 |
| **AUDIT_BYPASS** | **1** (LP-45) |
| web basis: failed `/ground` | 3 of 3 (all BAJA pages) |
| unavailable (web page matched, model returned an empty answer) | 2 (LP-03, LP-76, both "vida laboral") |

**R1 block rate (model basis) = 10/28 = 35.7%.** Words p50 114, p95 129, max 134 (limit 120). Draft cost p50 $0.0062, latency p50 4.0 s.

### PASSED all locks — LP-09-cov · ¿Qué es el SEPE?
verdict `passed_clean` · 105 words · post-check `None` «None» · shape `[]` · audit `[]`

> El SEPE (Servicio Público de Empleo Estatal) es el organismo público que gestiona las políticas de empleo en España. Se encarga de tramitar y abonar las prestaciones y subsidios por desempleo, ofrecer orientación e intermediación laboral, y gestionar programas de formación para el empleo. También expide certificados relacionados con la vida laboral y el desempleo, que a veces se solicitan para trámites administrativos o de empresa. Es el interlocutor habitual cuando una persona pierde su trabajo y necesita solicitar la prestación correspondiente o información sobre su situación laboral. Para conocer cómo afecta a tu caso concreto, consulta tu convenio colectivo o dirígete a Recursos Humanos.

### BLOCKED by the post-check — LP-40-cov · ¿Qué es un certificado de empresa?
verdict `blocked_postcheck` · 109 words · post-check `E2` «la empresa esta obligada» · shape `None` · audit `[]`

> Un certificado de empresa es un documento oficial que la empresa emite para acreditar datos laborales de una persona trabajadora, como el periodo trabajado, el tipo de contrato, la categoría profesional, la jornada y las bases de cotización. Suele solicitarse principalmente para tramitar la prestación por desempleo ante el SEPE, aunque también se usa para otras gestiones administrativas, como solicitar prestaciones por otras contingencias o acreditar experiencia laboral. La empresa está obligada a facilitarlo cuando finaliza la relación laboral, generalmente junto con la liquidación y el finiquito. Para conocer el procedimiento exacto y los plazos de entrega en tu caso, consulta tu convenio colectivo o contacta con Recursos Humanos.

### BLOCKED by the shape check (S2, length) — LP-28-cov · ¿Qué es la movilidad funcional?
verdict `blocked_shape` · 129 words · post-check `None` «None» · shape `['S2']` · audit `[]`

> La movilidad funcional consiste en la posibilidad de que la empresa asigne a una persona trabajadora funciones distintas a las habituales, ya sea dentro del mismo grupo profesional o, en casos más limitados, en otro diferente. Sirve para adaptar la organización del trabajo a necesidades productivas, técnicas u organizativas, permitiendo cubrir tareas puntuales o reorganizar equipos sin necesidad de modificar el contrato formalmente. Suele exigir que el cambio sea razonable, que respete la dignidad profesional y, en algunos casos, la titulación exigida. Los límites concretos, el procedimiento a seguir y si conlleva compensación económica dependen de lo que establezcan la normativa aplicable y el convenio colectivo correspondiente. Para conocer cómo se aplicaría en un caso concreto, lo mejor es consultar el convenio colectivo vigente o contactar con Recursos Humanos.

### AUDIT_BYPASS (post-check and shape pass, independent audit flags `obligatorio`) — LP-45-cov · ¿Qué es el SMAC?
verdict `AUDIT_BYPASS` · 118 words · post-check `None` «None» · shape `[]` · audit `['entitlement_word']`

> El SMAC (Servicio de Mediación, Arbitraje y Conciliación) es un organismo administrativo, dependiente de las comunidades autónomas, que sirve para intentar resolver conflictos laborales individuales o colectivos antes de acudir a los tribunales. Consiste en un acto de conciliación en el que empresa y persona trabajadora, con ayuda de un mediador imparcial, intentan llegar a un acuerdo sobre la controversia planteada, por ejemplo en casos de despido o reclamaciones de cantidad. Suele ser un trámite previo obligatorio antes de presentar una demanda judicial, ya que agiliza la solución del conflicto y evita, en muchos casos, el proceso judicial. Para saber cómo se aplica en tu caso concreto, consulta tu convenio colectivo o dirígete al departamento de Recursos Humanos.
