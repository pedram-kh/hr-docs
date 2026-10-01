#!/usr/bin/env python3
"""Slice 13c build step 0 — author the three frozen fixture files from the reviewed lists.

Source of truth: plan.md Appendices A-C + the review additions (POOL-49..52, ADV-09/10, ADV-07 typo fix).
Output (in ../): lane-positives-pool.json, prescreen-fixtures.json, lane-colloquial-negatives.json.
Re-running must reproduce the frozen hashes (freeze.sh -> MANIFEST.sha256); any edit changes them.
Dev/held-out split (prescreen): within each class, odd positions = dev, even = held-out. Minimal-pair
partners sit at the same position in their class, so a pair is never split across halves.
"""
import json
import os

OUT = os.path.join(os.path.dirname(os.path.abspath(__file__)), "..")

# ---- A. positive pool (52) -------------------------------------------------------------------------
POOL = [
    ("¿Qué es la Seguridad Social?", []),
    ("¿Qué es la Tesorería General de la Seguridad Social?", []),
    ("¿Qué es la vida laboral?", []),
    ("¿Qué es una mutua colaboradora con la Seguridad Social?", []),
    ("¿Qué es el parte de baja médica?", []),
    ("¿Qué es la incapacidad temporal?", []),
    ("¿Qué significa IT?", ["cal:lane_candidate_on_cov"]),
    ("¿Qué es la incapacidad permanente?", []),
    ("¿Qué es el SEPE?", []),
    ("¿Qué es la prestación por desempleo?", []),
    ("¿Qué es el alta en la Seguridad Social?", []),
    ("¿Qué es la mejora voluntaria de la Seguridad Social?", []),
    ("¿Qué es la jubilación parcial?", []),
    ("¿Qué es la Inspección de Trabajo?", []),
    ("¿Qué es el FOGASA?", []),
    ("¿Qué es un contrato indefinido?", []),
    ("¿Qué es un contrato fijo discontinuo?", []),
    ("¿Qué es un contrato de relevo?", []),
    ("¿Qué es un contrato en prácticas?", []),
    ("¿Qué es un contrato de formación en alternancia?", []),
    ("¿Qué es un contrato temporal por circunstancias de la producción?", []),
    ("¿Qué es el contrato a tiempo parcial?", []),
    ("¿Qué es la subrogación de personal?", []),
    ("¿Qué es el periodo de prueba?", ["cal:fact_route_risk"]),
    ("¿Qué es una excedencia?", ["spec"]),
    ("¿Qué es un ERTE?", ["spec", "cal:expect_v2_fail"]),
    ("¿Qué es el preaviso?", ["spec"]),
    ("¿Qué es la movilidad funcional?", []),
    ("¿Qué es la modificación sustancial de condiciones de trabajo?", []),
    ("¿Qué es la jornada irregular?", []),
    ("¿Qué es la jornada partida?", []),
    ("¿Qué es el teletrabajo?", []),
    ("¿Qué es la desconexión digital?", []),
    ("¿Qué son las horas extraordinarias?", []),
    ("¿Qué es el registro de jornada?", ["cal:fact_route_risk"]),
    ("¿Qué es un permiso retribuido?", []),
    ("¿Qué son los días de asuntos propios?", []),
    ("¿Qué es la reducción de jornada?", []),
    ("¿Qué son las vacaciones devengadas?", []),
    ("¿Qué es un certificado de empresa?", []),
    ("¿Qué es el comité de empresa?", []),
    ("¿Qué es un delegado de personal?", []),
    ("¿Qué es la ultraactividad de un convenio?", ["cal:expect_v2_fail"]),
    ("¿Qué es un plan de igualdad?", []),
    ("¿Qué es el SMAC?", []),
    ("¿Qué es un convenio colectivo?", []),
    ("¿Qué es un sindicato?", []),
    ("¿Qué es el comité de seguridad y salud?", []),
    # review additions (phrasing variety)
    ("¿Qué diferencia hay entre una baja y una excedencia?", []),
    ("¿En qué consiste la jornada irregular?", []),
    ("¿Qué quiere decir que un convenio está en ultraactividad?", ["cal:expect_v2_fail"]),
    ("¿Cómo funciona una mutua cuando estás de baja?", []),
]


# ---- A2. second-tier pool (24), added after S0 found 18/52 survivors (< 20; plan Q6 -> tier2) -----------------
# Chosen from what S0 showed survives: institutional / Seguridad Social concepts the convenio + Estatuto do not define.
POOL2 = [
    "¿Qué es el INSS?",
    "¿Qué es una mutua de accidentes de trabajo?",
    "¿Qué es el alta médica?",
    "¿Qué es el paro?",
    "¿Qué es la demanda de empleo?",
    "¿Qué es un accidente de trabajo?",
    "¿Qué es una enfermedad profesional?",
    "¿Qué es la prevención de riesgos laborales?",
    "¿Qué es el servicio de prevención?",
    "¿Qué es la mediación laboral?",
    "¿Qué es la representación sindical?",
    "¿Qué es un permiso parental?",
    "¿Qué es el ingreso mínimo vital?",
    "¿En qué consiste la jubilación?",
    "¿En qué consiste el alta en la Seguridad Social?",
    "¿En qué consiste una baja por incapacidad temporal?",
    "¿Qué diferencia hay entre baja médica y alta médica?",
    "¿Qué diferencia hay entre el SEPE y la Seguridad Social?",
    "¿Qué diferencia hay entre un contrato temporal y uno indefinido?",
    "¿Para qué sirve una mutua?",
    "¿Para qué sirve el certificado de empresa?",
    "¿Cómo funciona el paro?",
    "¿Cómo funciona la Seguridad Social?",
    "¿Por qué existe la vida laboral?",
]

# ---- B. pre-screen fixtures ------------------------------------------------------------------------
PAIRS = [
    ("¿Tengo derecho a excedencia?", "¿Qué es una excedencia?"),
    ("¿Cuántos días de preaviso tengo que dar?", "¿Qué es el preaviso?"),
    ("¿Me corresponde una indemnización por fin de contrato?", "¿Qué es la indemnización por fin de contrato?"),
    ("¿Cuánto dura la baja por IT?", "¿Qué es la incapacidad temporal?"),
    ("¿Cuánto tiempo puedo estar de ERTE?", "¿Qué es un ERTE?"),
    ("¿Me pagan las horas extra?", "¿Qué son las horas extraordinarias?"),
    ("¿Puedo pedir una reducción de jornada?", "¿Qué es la reducción de jornada?"),
    ("¿Me dan un día libre por mudanza?", "¿Qué es un permiso retribuido?"),
    ("¿Me toca algo de vacaciones si entré en marzo?", "¿Qué son las vacaciones devengadas?"),
    ("¿Cuántas semanas de baja me tocan por nacimiento?", "¿Qué es el permiso por nacimiento y cuidado del menor?"),
    ("Si me caso, ¿tengo días libres?", "¿Qué es un permiso por matrimonio?"),
    ("¿Me tienen que dar un certificado de empresa cuando me voy?", "¿Qué es un certificado de empresa?"),
    ("¿Hasta cuándo tengo que preavisar si me voy?", "¿Qué significa preavisar la baja voluntaria?"),
]
ENT_U = [
    ("¿Me tienen que dar el día libre si me mudo?", None),
    ("¿La empresa está obligada a pagarme los días de baja?", None),
    ("¿Cuántos días puedo coger por enfermedad de un familiar?", None),
    ("¿Me quitan dinero si falto un día?", None),
    ("¿Puedo cogerme los días de asuntos propios cuando quiera?", None),
    ("¿Tengo que trabajar el festivo?", None),
    ("¿Me toca descanso después de una jornada larga?", None),
    ("¿Cuántas horas puedo hacer seguidas?", None),
    ("¿Me pueden obligar a hacer horas extra?", None),
    ("¿Me deben pagar el día de mi cumpleaños libre?", None),
    ("¿Cuántos días de permiso me dan por fallecimiento de un familiar?", None),
    ("¿A cuántos días de vacaciones tengo derecho?", None),
    ("¿Puedo exigir que me cambien el turno?", None),
    ("¿Tengo derecho a un descanso para el bocadillo?", None),
    ("¿Me corresponde el plus de nocturnidad?", "pay_gated_end_to_end"),
    ("¿Cuándo me pagan la paga extra?", "pay_gated_end_to_end"),
    ("¿Me dan algo si me despiden?", "sensitive_gated_end_to_end"),
    ("¿Cuántos meses de prueba me pueden poner?", None),
    ("¿Tienen que avisarme con tiempo si cambian mi horario?", None),
    ("¿Puedo pedir la baja voluntaria y cobrar el paro?", None),
    ("¿Se me descuenta la nómina si voy al médico?", "pay_gated_end_to_end"),
]
EXP_X = [
    ("¿Qué es la Seguridad Social?", None),
    ("¿Qué es un contrato indefinido?", None),
    ("¿Qué significa IT?", None),
    ("¿Qué es un contrato fijo discontinuo?", None),
    ("¿Qué es la vida laboral?", None),
    ("¿Qué es una mutua?", None),
    ("¿Cómo funciona el periodo de prueba?", None),
    ("¿Para qué sirve el registro de jornada?", None),
    ("¿Qué diferencia hay entre jornada partida y continua?", None),
    ("¿Qué es el comité de empresa?", None),
    ("¿Qué quiere decir subrogación?", None),
    ("¿Qué es el convenio colectivo?", None),
    ("¿Qué es un permiso no retribuido?", None),
    ("¿Cómo se pide una excedencia?", "procedural_expect_refused_by_v2_ticketed"),
    ("¿Qué pasa con mi contrato durante una excedencia?", "first_person_expect_refused_by_v2"),
    ("¿Qué es el parte de baja?", None),
    ("¿Por qué hay días naturales y laborables?", None),
    ("¿Qué es la ultraactividad?", None),
    ("¿Quién paga la baja por enfermedad común?", "borderline_expect_refused_by_v2"),
]
ADV = [
    ("¿Qué es lo que me corresponde si me voy?", "what_is_what_corresponds"),
    ("¿Qué me toca si me despiden?", "que_me_toca"),
    ("¿Qué es lo que me deben pagar al irme?", "what_is_what_they_owe"),
    ("¿Cómo funciona el cálculo de mis días de vacaciones?", "possessive_calculation"),
    ("¿Qué es lo que te toca de vacaciones por ley?", "generic_second_person_toca"),
    ("¿Qué significa tener derecho a excedencia y cuánto dura?", "definition_plus_duration"),
    ("¿Para qué sirve pedir la baja voluntaria si quiero cobrar el paro?", "purpose_wrapper_first_person"),
    ("¿Qué es lo que debe pagar la empresa si caigo de baja?", "what_is_what_company_owes"),
    ("¿Qué es el periodo de prueba en mi contrato?", "first_person_wrapper"),
    ("¿Qué son las horas extra y cuántas me pueden pedir?", "compound_explanatory_plus_entitlement"),
]


def half(pos):  # pos is 1-based within its class
    return "dev" if pos % 2 == 1 else "heldout"


prescreen = []
for i, (e, _) in enumerate(PAIRS, 1):
    prescreen.append(dict(id=f"ENT-P{i:02d}", class_="entitlement", expected="entitlement", pair=f"P{i}", note=None, question=e))
for j, (q, note) in enumerate(ENT_U, 1):
    prescreen.append(dict(id=f"ENT-U{j:02d}", class_="entitlement", expected="entitlement", pair=None, note=note, question=q))
for i, (_, x) in enumerate(PAIRS, 1):
    prescreen.append(dict(id=f"EXP-P{i:02d}", class_="explanatory", expected="explanatory", pair=f"P{i}", note=None, question=x))
for j, (q, note) in enumerate(EXP_X, 1):
    prescreen.append(dict(id=f"EXP-X{j:02d}", class_="explanatory", expected="explanatory", pair=None, note=note, question=q))
for k, (q, note) in enumerate(ADV, 1):
    prescreen.append(dict(id=f"ADV-{k:02d}", class_="adversarial", expected="entitlement", pair=None, note=note, question=q))

# split positions are per class (entitlement = ENT-*, explanatory = EXP-*, adversarial = ADV-*)
counters = {}
for f in prescreen:
    counters[f["class_"]] = counters.get(f["class_"], 0) + 1
    f["half"] = half(counters[f["class_"]])
    f["class"] = f.pop("class_")

# S0 re-freeze (user direction, 2026-09-30): record provenance of the adversarial fixtures. ADV-05 and ADV-08 drove the
# `obligation` rule (written after they were seen), so they are contaminated for the v2 number. ADV-09 and ADV-10 were added
# at fixture review, after the v1 list and before any v2 rule was written, and passed v2 unseen: the CLEAN held-out adversarial
# pair, and the only adversarial gate number. `half` is unchanged (frozen positions); `gate_role` overrides it for this class.
ADV_ROLE = {"ADV-05": "contaminated_drove_obligation_rule", "ADV-08": "contaminated_drove_obligation_rule",
            "ADV-09": "clean_heldout", "ADV-10": "clean_heldout"}
for f in prescreen:
    if f["class"] == "adversarial":
        f["gate_role"] = ADV_ROLE.get(f["id"], "visible_at_tuning")

NEG = [
    "¿Me dan un día libre si me mudo de casa?",
    "Si me caso, ¿me tocan días de vacaciones extra?",
    "¿La empresa me tiene que pagar los días que esté de baja?",
    "¿Me descuentan dinero si falto un día al trabajo?",
    "¿Puedo cogerme los asuntos propios cuando me dé la gana?",
    "¿Me obligan a currar el día de un festivo?",
    "¿Me toca descanso si hago una jornada muy larga?",
    "¿Me pueden mandar a hacer horas extra sin avisar?",
    "¿Si me echan me dan algo?",
    "¿Tengo que avisar con tiempo si quiero dejar el trabajo?",
    "¿Me dejan irme antes si tengo médico?",
    "¿Me lo tienen que pagar si me cambian el turno a última hora?",
    "¿Mi empresa está obligada a darme un día por el cumple de mi hijo?",
    "¿Me tocan días por la muerte de mi abuelo?",
    "¿Puedo pedir un año sin trabajar y luego volver?",
    "¿Me guardan el puesto si me pillo una excedencia?",
    "¿Hasta qué edad me pueden hacer contratos temporales?",
    "¿Me pueden cambiar de puesto sin preguntarme?",
]


def dump(name, obj):
    with open(os.path.join(OUT, name), "w", encoding="utf-8") as fh:
        json.dump(obj, fh, ensure_ascii=False, indent=1)
        fh.write("\n")


pool = {
    "_description": "Slice 13c plan.md App. A + review additions (POOL-49..52). 52 CANDIDATES, not positives: S0 (V1 Check A + V2 corpus synthesis on profile cov = test-gipuzkoa) selects the survivors, which are frozen separately as lane-positives.json. `flags`: spec = topic named in the 13c spec; cal:* = calibration item with a predicted V2 outcome (expect_v2_fail = the corpus already answers it, must NOT survive).",
    "cases": [{"id": f"POOL-{n:02d}", "class": "lane_positive_candidate", "email": "test-gipuzkoa@example.com", "question": q, "flags": fl}
              for n, (q, fl) in enumerate(POOL, 1)],
}
dump("lane-positives-pool.json", pool)
dump("lane-positives-pool-2.json", {
    "_description": "Slice 13c second-tier pool (POOL-53..76), added after S0 (18/52 survivors, < 20; plan Q6 answered 'tier2'). 24 CANDIDATES, same rules as lane-positives-pool.json; S0b runs V1+V2 on these only. Frozen BEFORE S0b ran.",
    "cases": [{"id": f"POOL-{n:02d}", "class": "lane_positive_candidate", "email": "test-gipuzkoa@example.com", "question": q, "flags": []}
              for n, q in enumerate(POOL2, len(POOL) + 1)],
})
dump("prescreen-fixtures.json", {
    "_description": "Slice 13c plan.md App. B + review additions (ADV-09/10) and the ADV-07 typo fix. entitlement 34 (13 minimal-pair partners ENT-P01..13 + 21 unpaired), explanatory 32 (EXP-P01..13 + 19 unpaired), adversarial 10 (explanatory-shaped or compound entitlements; expected = entitlement). `pair` ties the minimal pairs P1-P13. `half`: dev = tuning allowed, heldout = gate number (split by position within class; pair partners share a half). `note` marks fixtures that the pre-corpus gates stop end to end, or that the fail-closed v2 is expected to refuse.",
    "_provenance": "ADV-05 and ADV-08 drove the `obligation` rule of pre-screen v2 (written after they were seen): contaminated. ADV-09 and ADV-10 were unseen when the rules were written: the clean held-out adversarial pair, the ONLY adversarial gate number (report them separately). `gate_role` on each adversarial case says which; `half` is the frozen position split and is not used for the adversarial gate.",
    "cases": prescreen,
})
dump("lane-colloquial-negatives.json", {
    "_description": "Slice 13c plan.md App. C. 18 colloquial, second-person entitlement questions (13b style: no `cuanto`, no anchor word). Every one clears the pre-corpus gates (verified offline) so it would reach the corpus end to end; 17/18 are missed by the v1 pre-screen. Forced-lane negatives and pre-screen adversarial half.",
    "cases": [{"id": f"NEG-{n:02d}", "class": "lane_negative_colloquial", "email": "test-gipuzkoa@example.com", "question": q,
               "expect": {"forbid_paths": ["general_knowledge"]}} for n, q in enumerate(NEG, 1)],
})
print("pool", len(POOL), "prescreen", len(prescreen), "neg", len(NEG))
