<?php

/**
 * Slice 13c step 0 — offline, $0. Two facts quoted in plan.md §0.6 / §2.4 / App. D:
 *  (1) GeneralLanePostCheck::scan() PASSES fabricated legal citations (article numbers, RD/RDL numbers, URLs, [Fuente] markers)
 *      — the gap ModelKnowledgeShapeCheck S1 closes;
 *  (2) the closing-pointer sentences and the model-basis badge caveat pass scan() AND audit() clean.
 * Re-run after the caveat/pointer text is final (build step 4): every constant must still print PASS/[].
 */

use App\Services\Agent\Rules\GeneralLanePostCheck as P;

require (getenv('HR_BACKEND') ?: __DIR__.'/../../../../../hr-backend').'/vendor/autoload.php';

$line = static function (string $tag, string $t): void {
    $r = P::scan($t);
    echo str_pad($tag, 10), ($r === null ? 'PASS ' : 'BLOCK '), $r === null ? '' : json_encode($r, JSON_UNESCAPED_UNICODE),
        ' audit=', json_encode(P::audit($t), JSON_UNESCAPED_UNICODE), ' | ', mb_substr($t, 0, 70), "\n";
};

echo "--- (1) fabricated citations the post-check lets through ---\n";
foreach ([
    'La excedencia está regulada en el artículo 46 del Estatuto de los Trabajadores y suspende el contrato.',
    'El ERTE se regula en el art. 47 ET y en el Real Decreto-ley 8/2019.',
    'La excedencia es una suspensión del contrato (sentencia del Tribunal Supremo de referencia) que se regula en la Ley Orgánica de Igualdad.',
    'Puedes consultar más en https://www.sepe.gob.es [Fuente: BOE].',
    'El preaviso es la comunicación previa que la persona trabajadora hace a la empresa antes de dejar el puesto.',
] as $t) {
    $line('cite', $t);
}

echo "--- (2) closing pointers and caveats ---\n";
foreach ([
    'Para saber cómo se aplica en tu caso, consulta tu convenio colectivo o pregunta a Recursos Humanos.',
    'Cómo se aplica a tu caso depende de tu convenio colectivo: consúltalo o pregunta a Recursos Humanos.',
    'Para conocer las condiciones concretas, revisa tu convenio colectivo o consulta con Recursos Humanos.',
    'Información general, redactada sin consultar tu convenio ni la normativa cargada y sin una fuente verificable. No describe lo que se te aplica a ti: consúltalo en tu convenio o con Recursos Humanos.',
] as $t) {
    $line('pointer', $t);
}
