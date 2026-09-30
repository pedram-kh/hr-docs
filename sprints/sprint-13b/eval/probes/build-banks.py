#!/usr/bin/env python3
"""Sprint 13b, build step 0 — author the colloquial banks + normalization negatives.

Deterministic: scopes come from the Sprint 13 fact-routing.json (93 cases), assigned per topic
with a fixed seed; phrasings are authored below (held in this file, the single source).
`anchored` is NOT set here — label-banks.php fills it from TopicLexicon (outcome-independent)
and refuses to write a file whose phrasings are not all unanchored.
Run:  python3 build-banks.py   then   php label-banks.php   then   sh freeze.sh
"""
import json, random, pathlib

HERE = pathlib.Path(__file__).resolve().parent
EVAL = HERE.parent
SRC = HERE.parent.parent.parent / 'sprint-13' / 'eval' / 'fact-routing.json'
cases = json.load(open(SRC))['cases']

# topic -> (dev phrasings[5], held-out lookup phrasings, held-out situational phrasing|None)
BANK = {
 'vacaciones': (
  [
   "¿Cuántos días libres al año puedo coger para descansar?",
   "¿Cuánto tiempo al año me dejan desconectar del trabajo cobrando?",
   "¿Cuántos días puedo pedir para desconectar a lo largo del año?",
   "¿Cuántos días al año me puedo ir de vacas sin que me descuenten?",
   "¿Cuántos días seguidos puedo estar sin venir y que me sigan pagando?",
  ],
  [
   "¿Cuántos días tengo al año para desconectar y que me sigan pagando?",
   "¿Cuánto tiempo puedo estar fuera del curro al año sin que me descuenten nada?",
   "Quiero planear mis días libres pagados del año, ¿de cuántos dispongo?",
   "¿Cuántas semanas al año me pagan sin tener que trabajar?",
   "¿Cuántos días de vacas me tocan al año?",
   "¿Me dan más de tres semanas al año para no ir a trabajar cobrando?",
   "Cuando llevo un año entero currando, ¿cuánto tiempo libre pagado me dan?",
  ],
  "Me han dicho que tengo que gastar mis días pagados de descanso antes de marzo o los pierdo, ¿es verdad?",
 ),
 'jornada': (
  [
   "¿Cuántas horas tengo que hacer en total durante el año?",
   "¿Cuál es el máximo de horas que me pueden hacer trabajar en doce meses?",
   "¿Cuántas horas semanales me corresponde trabajar de media?",
   "¿Cuántas horas de trabajo real me piden en un año completo?",
   "¿Cuánto tengo que trabajar al año según el convenio?",
  ],
  [
   "¿Cuántas horas me toca echar cada año?",
   "Según mi convenio, ¿cuántas horas de curro hay en un año?",
   "¿Cuántas horas al año me pueden hacer currar como mucho?",
   "¿A cuántas horas de trabajo al año estoy obligado?",
   "¿Cuántas horas semanales de media me marca el convenio?",
   "¿Qué cantidad de horas tengo que cumplir durante todo el año?",
   "Mi empresa dice que hago menos horas de las que toca, ¿cuántas me tocan en realidad al año?",
  ],
  "Este mes llevo muchas más horas de lo normal, ¿cuándo me las tienen que devolver?",
 ),
 'permisos retribuidos': (
  [
   "¿En qué casos me pagan el día aunque no vaya a trabajar?",
   "¿Cuándo puedo faltar sin que me lo descuenten?",
   "Si un familiar está ingresado en el hospital, ¿puedo ausentarme cobrando?",
   "¿Me dan tiempo libre pagado para ir al médico?",
   "¿Qué motivos me dejan faltar sin que me penalicen?",
  ],
  [
   "¿Por qué cosas puedo ausentarme del trabajo sin que me quiten dinero?",
   "Me han citado como testigo en un tribunal, ¿me cuentan ese día como trabajado?",
   "¿Me pagan las horas que pierdo si tengo que ir a un examen oficial?",
   "Si mi hijo se pone malo, ¿puedo quedarme con él sin que me descuenten el día?",
   "¿En qué situaciones puedo desaparecer unos días sin que me lo descuenten?",
   "¿Qué días sueltos me tienen que pagar aunque no trabaje?",
   "¿Hay días en los que se puede faltar y aun así cobrar?",
  ],
  "Mi jefe me ha puesto falta por ir al médico con mi madre, ¿tiene razón?",
 ),
 'periodo de prueba': (
  [
   "Llevo dos semanas en la empresa, ¿cuánto tiempo estoy todavía a prueba?",
   "¿Cuánto tarda en pasar mi tiempo de prueba inicial?",
   "¿Cuántos meses tengo que aguantar de prueba en un puesto nuevo?",
   "¿Cuánto tiempo tienen para decidir si me quedo o no?",
   "¿Cuántos meses de prueba tiene un contrato nuevo como el mío?",
  ],
  [
   "Empecé la semana pasada, ¿cuánto dura la etapa de prueba?",
   "¿Cuánto tarda la empresa en decidir si me quedo fijo en el puesto?",
   "¿Hasta cuándo estoy a prueba en mi puesto?",
   "¿Cuántos meses estoy a prueba siendo nuevo?",
   "Soy nuevo aquí, ¿cuánto tiempo tengo de prueba?",
   "¿Qué duración tiene el tiempo de prueba que marca mi convenio?",
   "¿Cuánto dura la fase en la que todavía no me han confirmado en el puesto?",
  ],
  "Me han dicho que como estoy en fase de prueba no tengo ningún derecho, ¿es cierto?",
 ),
 'festivos': (
  [],
  [
   "¿Cuántas fiestas oficiales me tocan en el año?",
   "¿Qué días son de fiesta oficial según mi convenio?",
   "¿Cuántos días señalados en rojo del calendario hay al año?",
   "¿Qué días del calendario se consideran no laborables?",
   "¿En qué días señalados no me toca ir a trabajar?",
   "¿Cuántos días de fiesta hay para mi convenio?",
   "¿Cuáles son los días en los que la empresa cierra por fiesta?",
   "¿Cuántos días de fiesta local o nacional tengo?",
  ],
  None,
 ),
}
SLUG = {'vacaciones': 'vac', 'jornada': 'jor', 'permisos retribuidos': 'per', 'periodo de prueba': 'pru', 'festivos': 'fes'}
COPY = ('class', 'topic', 'fact_ids', 'convenio', 'convenio_id', 'group_label', 'job_category')


def mk(id_, src, question, situational, bank):
    c = {'id': id_, 'bank': bank, 'source_case': src['id']}
    for k in COPY:
        if k in src:
            c[k] = src[k]
    c['authored'] = 'colloquial'
    c['colloquial_question'] = question
    c['anchored'] = None  # filled by label-banks.php
    if situational:
        c['class'] = 'colloquial_situational'
        c['expected_tools'] = {'first': None}
        c['expect'] = {'path_not': ['reference_fact'], 'note': 'topic fits but this is not a lookup: a bare fact quote is wrong; any other route (prose, ask, escalate) is acceptable. Reported separately, excluded from G1.'}
    else:
        c['expected_tools'] = src['expected_tools']
        c['expect'] = src['expect']
    return c


dev, held = [], []
for ti, (topic, (devp, heldp, sit)) in enumerate(BANK.items()):
    pool = sorted([c for c in cases if c['topic'] == topic], key=lambda c: c['id'])
    random.Random(1300 + ti).shuffle(pool)
    n_held = len(heldp) + (1 if sit else 0)
    if topic == 'festivos':          # only 2 scopes exist: alternate them
        held_scopes = [pool[i % len(pool)] for i in range(n_held)]
        dev_scopes = []
    else:
        held_scopes, dev_scopes = pool[:n_held], pool[n_held:n_held + len(devp)]
        assert len(held_scopes) == n_held and len(dev_scopes) == len(devp), topic
    for i, q in enumerate(devp):
        dev.append(mk(f"dv-{SLUG[topic]}-{i+1:02d}", dev_scopes[i], q, False, 'dev'))
    for i, q in enumerate(heldp):
        held.append(mk(f"ho-{SLUG[topic]}-{i+1:02d}", held_scopes[i], q, False, 'held_out'))
    if sit:
        held.append(mk(f"ho-{SLUG[topic]}-s01", held_scopes[len(heldp)], sit, True, 'held_out'))

assert len(dev) == 20 and len(held) == 40, (len(dev), len(held))

HEAD = ("Sprint 13b, build step 0 (plan.md §7.1). GENERATED by probes/build-banks.py — do not hand-edit; "
        "the sha256 in MANIFEST.sha256 is the freeze. Scopes are drawn from the Sprint 13 fact-routing.json cases "
        "(source_case), so every case has a verified in-scope fact wherever a fact answer is expected. "
        "`anchored` is filled by label-banks.php from TopicLexicon and must be false for every case.")
json.dump({'_description': HEAD + " DEV bank: the ONLY bank prompt iteration may look at.", 'cases': dev},
          open(EVAL / 'fact-routing-colloquial-dev.json', 'w'), ensure_ascii=False, indent=1)
json.dump({'_description': HEAD + " HELD-OUT bank: never looked at during prompt iteration; run once per gate stage. "
           "`colloquial_situational` cases (4) are scored `path_not`, informational, excluded from G1.", 'cases': held},
          open(EVAL / 'fact-routing-colloquial.json', 'w'), ensure_ascii=False, indent=1)

# ---- normalization negatives (plan.md Appendix A) -----------------------------------------
N = [
 ("nn-01","¿cuántos días me dan si me caso?","permiso por matrimonio: 15 días naturales","figure",["figure_not_in_literal"]),
 ("nn-02","¿cuántos días me dan si me caso?","permiso retribuido por matrimonio de quince días","spelled_figure",["scan:F2"]),
 ("nn-03","¿cuántos días me dan si me caso?","tienes derecho a permiso por matrimonio","entitlement",["scan:E1"]),
 ("nn-04","¿cuántos días me dan si me caso?","permiso por matrimonio en el convenio de Navarra","territory",["territory_or_convenio_name"]),
 ("nn-05","acabo de empezar, ¿cuánto me pueden tener a prueba?","periodo de prueba grupo profesional 3","group",["figure_not_in_literal","group_designator"]),
 ("nn-06","acabo de empezar, ¿cuánto me pueden tener a prueba?","duración máxima del periodo de prueba de seis meses","spelled_figure",["scan:F2"]),
 ("nn-07","acabo de empezar, ¿cuánto me pueden tener a prueba?","periodo de prueba técnicos titulados grupo 1","group",["figure_not_in_literal","group_designator"]),
 ("nn-08","¿cuántas horas al año me toca trabajar?","jornada anual de 1.800 horas","amount",["figure_not_in_literal"]),
 ("nn-09","¿cuántas horas al año me toca trabajar?","jornada máxima anual en Bizkaia","territory",["territory_or_convenio_name"]),
 ("nn-10","¿cuántas horas al año me toca trabajar?","jornada anual 2026 en cómputo anual","year",["figure_not_in_literal"]),
 ("nn-11","¿qué días libres me dan por asuntos familiares?","permisos retribuidos: te corresponden tres días por fallecimiento","spelled_figure",["scan:F2","scan:E1"]),
 ("nn-12","¿qué días libres me dan por asuntos familiares?","permiso por fallecimiento de familiar hasta segundo grado, mínimo dos días","bound",["scan:F2","scan:E3"]),
 ("nn-13","¿cuántas vacaciones tengo?","vacaciones anuales: 30 días naturales","figure",["figure_not_in_literal"]),
 ("nn-14","¿cuántas vacaciones tengo?","vacaciones anuales según Estatuto: treinta días","spelled_figure",["scan:F2"]),
 ("nn-15","¿cuántas vacaciones tengo?","vacaciones anuales del grupo profesional 2 en Madrid","group",["figure_not_in_literal","group_designator","territory_or_convenio_name"]),
 ("nn-16","me despiden, ¿me dan algo?","indemnización de 33 días por año trabajado","amount",["figure_not_in_literal"]),
 ("nn-17","¿me pagan más si trabajo de noche?","plus de nocturnidad del 25 %","amount",["figure_not_in_literal"]),
 ("nn-18","¿me pagan más si trabajo de noche?","complemento de nocturnidad 4,5 euros por hora","amount",["figure_not_in_literal"]),
 ("nn-19","¿cuánto tiempo me dan para avisar si me voy?","preaviso de quince días","spelled_figure",["scan:F2"]),
 ("nn-20","¿cuánto tiempo me dan para avisar si me voy?","plazo de preaviso mínimo","bound",["scan:E3"]),
 ("nn-21","¿puedo pedir días libres por la mudanza?","permiso por traslado de domicilio: 1 día","figure",["figure_not_in_literal"]),
 ("nn-22","¿puedo pedir días libres por la mudanza?","tienes derecho a un día de permiso por traslado de domicilio","entitlement",["scan:D1","scan:E1"]),
 ("nn-23","¿tengo derecho a festivos?","festivos 2026: 14 festivos nacionales","year",["figure_not_in_literal"]),
 ("nn-24","¿y cuántos me tocan de festivos?","festivos en Gipuzkoa","territory",["territory_or_convenio_name"]),
 ("nn-25","¿cuánto me dan por tener un hijo?","permiso de nacimiento: 16 semanas","figure",["figure_not_in_literal"]),
 ("nn-26","¿cuánto me dan por tener un hijo?","permiso por nacimiento y cuidado del menor para el grupo 4","group",["figure_not_in_literal","group_designator"]),
 ("nn-27","¿me tienen que dar descanso?","descanso semanal de día y medio ininterrumpido","spelled_figure",["scan:F3"]),
 ("nn-28","¿me tienen que dar descanso?","descanso diario de doce horas entre jornadas","spelled_figure",["scan:F2"]),
]
# S1 live smoke uses 10 negatives with 10 DISTINCT literals (one per literal, the first authored)
seen, s1 = set(), set()
for i, l, *_ in N:
    if l not in seen and len(s1) < 10:
        seen.add(l); s1.add(i)
assert len(s1) == 10
neg = [{'id': i, 'literal': l, 'over_reach_canonical': c, 'class': k, 'expect_rules_any': r, 's1_live': i in s1} for i, l, c, k, r in N]
json.dump({'_description': "Sprint 13b plan.md Appendix A. `over_reach_canonical` is what an over-reaching planner might emit: the validator unit test feeds it and expects rejection (any of expect_rules_any recorded). `s1_live` = the 10 literals (distinct) used in the S1 live smoke, where the REAL planner produces its own canonical; pass = no accepted canonical fails the independent audit.",
           'cases': neg}, open(EVAL / 'normalization-negatives.json', 'w'), ensure_ascii=False, indent=1)

# ---- prompt few-shot examples + CP-1 pool (frozen so the overlap guard has something to check) ----
json.dump({'_description': "The two few-shot examples in the planner system prompt (plan.md §2.3). No eval/CP-1 question may resemble them (label-banks.php overlap guard).",
           'examples': [
             {'literal': "Quiero pedirme un año sin trabajar pero conservando mi puesto", 'topic': 'excedencias', 'canonical_query': 'excedencia voluntaria: reserva del puesto de trabajo'},
             {'literal': "¿Puedo llevar a mi perro a la oficina?", 'topic': None, 'canonical_query': None}]},
          open(EVAL / 'prompt-examples.json', 'w'), ensure_ascii=False, indent=1)
CP1 = [
 (1,"Entré este mes, ¿cuánto tiempo estoy en fase de prueba?","periodo de prueba"),
 (2,"¿Cuál es el tope de horas que puedo trabajar en un año?","jornada"),
 (3,"Me caso en octubre, ¿me dan días libres?","permisos retribuidos"),
 (4,"Si se muere un familiar cercano, ¿me dejan faltar al trabajo?","permisos retribuidos"),
 (5,"Mi pareja va a dar a luz, ¿cuántos días libres me tocan?","permisos retribuidos"),
 (6,"¿Cuántos días puedo cogerme para irme de viaje en verano?","vacaciones"),
 (7,"¿Qué días del año son fiesta y no se trabaja?","festivos"),
 (8,"Me mudo de casa la semana que viene, ¿tengo algún día libre por eso?","permisos retribuidos"),
 (9,"Mi jefa me ha dicho que en agosto no puedo cogerme días, ¿puede hacer eso?","vacaciones|null"),
 (10,"¿La empresa me da seguro médico?","null"),
]
json.dump({'_description': "CP-1 live questions (plan.md §7.5) — a third pool, never used in tuning. Employee/scope mapping is added at staging time from facts-export.php; the questions and expected topics are frozen here.",
           'cases': [{'id': f'cp1-{n:02d}', 'question': q, 'expected_topic': t} for n, q, t in CP1]},
          open(EVAL / 'cp1-colloquial.json', 'w'), ensure_ascii=False, indent=1)
print('ok: dev', len(dev), 'held', len(held), 'neg', len(neg))
