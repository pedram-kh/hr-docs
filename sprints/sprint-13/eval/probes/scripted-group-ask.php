<?php

/**
 * Sprint 13 step 10 — CP-1 scenario 5b, deterministic half.
 *
 * The live planner (claude-sonnet-5) was offered five group-shaped prompts and
 * never proposed asking for the professional group (it asked for a sub-question
 * or used a tool instead) — so the rule cannot be shown firing on model output
 * by luck. This drives the DEPLOYED loop, rules, tools and persistence with a
 * SCRIPTED planner that proposes exactly the forbidden ask, on a real fresh
 * session for a test employee whose Directory `convenio_group_id` is NULL.
 * Everything except the model's choice is production code. Persisted (visible
 * in Historial); the trace's planner block says `scripted-cp1-probe`, so nobody
 * mistakes it for model output.
 */

use App\Models\ChatSession;
use App\Models\Employee;
use App\Services\Agent\PlannerClient;
use App\Services\AnswerEngineDispatcher;

$scripted = new class implements PlannerClient
{
    public int $round = 0;

    public function plan(string $question, array $scopeSummary, array $window, array $toolDefinitions, array $priorSteps): array
    {
        $this->round++;

        return [
            'stop_reason' => 'tool_use',
            'calls' => [[
                'id' => 'scripted_1', 'tool' => 'ask_employee',
                'input' => ['topic' => 'sub_question', 'question' => '¿En qué grupo profesional estás?'],
            ]],
            'model' => 'scripted-cp1-probe', 'request_id' => null, 'prompt_version' => 'scripted', 'tokens' => [], 'ms' => 0,
        ];
    }
};
app()->instance(PlannerClient::class, $scripted);

$employee = Employee::where('email', 'test-ocio-alava@example.com')->firstOrFail();
echo 'employee convenio_group_id='.var_export($employee->convenio_group_id, true)."\n";

$session = ChatSession::create(['employee_id' => $employee->id, 'started_at' => now(), 'last_activity_at' => now()]);
$dispatcher = app(AnswerEngineDispatcher::class);
$dispatcher->handle($employee, 'Hola, tengo una duda sobre mis condiciones de trabajo', $session->uuid, null, 'agent');

$r = $dispatcher->handle($employee, '¿Qué condiciones me corresponden según mi grupo profesional?', $session->uuid, null, 'agent');
echo 'SCRIPTED '.json_encode([
    'session' => $session->uuid,
    'outcome' => $r['outcome'] ?? null,
    'reason' => $r['escalation_reason'] ?? null,
    'answer' => $r['answer'] ?? null,
    'planner_rounds_used' => $scripted->round,
], JSON_UNESCAPED_UNICODE)."\n";
