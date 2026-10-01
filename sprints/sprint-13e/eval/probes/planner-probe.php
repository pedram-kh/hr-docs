<?php

/**
 * Slice 13e plan gate (plan.md §3) — the PLANNER-ONLY probe. ONE /plan call per question (round 1 exactly as the agent sends it,
 * `normalize_question` included), plus one /route call (the classic router) per question, and NOTHING else: no tool runs, no answer,
 * no card, nothing persisted (every case in a rolled-back transaction). ≈ $0.03 per question (7.8k prompt tokens at $3/MTok).
 *
 * Records, per question, every call the planner made with its full input — in particular `escalate{category, reason}` and
 * `normalize_question{confidence}` — which is what "what the planner emits today for off-domain verdicts" means. The `escalate`
 * schema has NO confidence field today (hr-ai/app/planner/tools.py:100-119), so the only numbers available are the normalizer's and
 * the router's; the probe reports both.
 *
 *   PROBE_SETS    comma list of fixture paths             (default the borderline set + the two probed anchors from the off-domain set)
 *   PROBE_IDS     comma list of case ids to run           (default: all of borderline-10 + OD-01,OD-02)
 *   PROBE_BUDGET  USD stop-loss                           (default 0.45; worst case per question 0.06 is checked BEFORE each call)
 *   PROBE_DRY=1   print the cost projection and exit
 * Output: `PP {json}` per question, then `PPSUM {json}`.
 */

use App\Models\AnswerModelSetting;
use App\Models\ChatSession;
use App\Models\Employee;
use App\Services\Agent\ControlTools;
use App\Services\Agent\PlannerClient;
use App\Services\Agent\ScopeSummaryBuilder;
use App\Services\Agent\ToolRegistry;
use App\Services\Agent\TurnState;
use App\Services\RouterService;
use Illuminate\Support\Facades\DB;

const PROBE_WORST_CASE_USD = 0.06;
const PROBE_PRICE = [3.00, 15.00]; // claude-sonnet-5 list $/MTok — reporting only

$dir = '/var/hr-docs/sprints/sprint-13e/eval';
$sets = array_filter(explode(',', (string) (getenv('PROBE_SETS') ?: "{$dir}/borderline-10.json,{$dir}/off-domain-24.json")));
$onlyIds = array_filter(explode(',', (string) (getenv('PROBE_IDS') ?: '')));
$budget = (float) (getenv('PROBE_BUDGET') ?: 0.45);

$cases = [];
foreach ($sets as $path) {
    $fixture = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    foreach ($fixture['cases'] as $c) {
        $take = $onlyIds !== []
            ? in_array($c['id'], $onlyIds, true)
            : (str_starts_with($c['id'], 'BL-') || ($c['probed_in_plan_gate'] ?? false));
        if ($take) {
            $cases[] = $c;
        }
    }
}

printf("PLAN %d questions, worst case \$%.2f, projected \$%.2f, budget \$%.2f\n", count($cases), count($cases) * PROBE_WORST_CASE_USD, count($cases) * 0.032, $budget);
if (getenv('PROBE_DRY')) {
    exit(0);
}

/** @var PlannerClient $planner */
$planner = app(PlannerClient::class);
$scope = app(ScopeSummaryBuilder::class);
$tools = app(ToolRegistry::class);
$router = app(RouterService::class);
$definitions = [...$tools->definitions(), ...ControlTools::definitions(), ControlTools::normalizationDefinition()];

$settings = AnswerModelSetting::current();
$key = $settings->isConfigured() ? $settings->decryptKey() : null;
$routerConfig = [
    'provider' => config('services.hr_ai.answer_provider', 'claude'),
    'model' => config('services.hr_ai.router_model'),
    'endpoint' => config('services.hr_ai.router_endpoint'),
];

$spent = 0.0;
$rows = [];
foreach ($cases as $case) {
    if ($spent + PROBE_WORST_CASE_USD > $budget) {
        echo "BUDGET STOP before {$case['id']} (spent \$".round($spent, 4).")\n";
        break;
    }
    $employee = Employee::query()->where('email', $case['email'])->first();
    if ($employee === null || ! str_ends_with((string) $employee->email, '@example.com')) {
        echo "SKIP {$case['id']}: no test employee {$case['email']}\n";

        continue;
    }
    DB::beginTransaction();
    try {
        $session = ChatSession::create(['employee_id' => $employee->id, 'started_at' => now(), 'last_activity_at' => now()]);
        $state = new TurnState($employee, $case['question'], now(), $session, []);
        $plan = $planner->plan($case['question'], $scope->build($employee, $state->asOfDate), ['exchanges' => [], 'message_ids' => []], $definitions, []);
        $tokens = $plan['tokens'] ?? [];
        $cost = ((int) ($tokens['prompt'] ?? 0) / 1e6) * PROBE_PRICE[0] + ((int) ($tokens['completion'] ?? 0) / 1e6) * PROBE_PRICE[1];

        $calls = array_map(fn ($c) => ['tool' => $c['tool'], 'input' => $c['input']], $plan['calls'] ?? []);
        $esc = collect($calls)->firstWhere('tool', 'escalate');
        $norm = collect($calls)->firstWhere('tool', 'normalize_question');

        $route = $router->classify($case['question'], $key, $routerConfig);

        $row = [
            'id' => $case['id'], 'class' => $case['class'] ?? null, 'question' => $case['question'],
            'hr_wants_to_see' => $case['hr_wants_to_see'] ?? null,
            'planner' => [
                'tools' => array_column($calls, 'tool'),
                'first_tool' => $calls[0]['tool'] ?? null,
                'escalate' => $esc['input'] ?? null,
                'normalize' => $norm['input'] ?? null,
                'calls' => $calls,
                'prompt_version' => $plan['prompt_version'] ?? null,
                'stop_reason' => $plan['stop_reason'] ?? null,
            ],
            'router' => ['label' => $route['label'] ?? null, 'confidence' => $route['confidence'] ?? null, 'source' => $route['source'] ?? null, 'note' => $route['note'] ?? null],
            'cost_usd' => round($cost, 5),
        ];
    } catch (\Throwable $e) {
        $row = ['id' => $case['id'], 'error' => $e::class.': '.mb_substr($e->getMessage(), 0, 240), 'cost_usd' => 0.0];
    } finally {
        DB::rollBack();
    }
    $rows[] = $row;
    $spent += (float) $row['cost_usd'];
    echo 'PP '.json_encode($row, JSON_UNESCAPED_UNICODE)."\n";
}

$ok = array_values(array_filter($rows, fn ($r) => ! isset($r['error'])));
echo 'PPSUM '.json_encode([
    'questions' => count($rows), 'errors' => count($rows) - count($ok), 'spent_usd' => round($spent, 4),
    'planner_first_tool' => array_count_values(array_map(fn ($r) => $r['planner']['first_tool'] ?? 'none', $ok)),
    'planner_escalate_category' => array_count_values(array_map(fn ($r) => $r['planner']['escalate']['category'] ?? 'no_escalate', $ok)),
    'router_label' => array_count_values(array_map(fn ($r) => $r['router']['label'] ?? 'none', $ok)),
    'prompt_versions' => array_values(array_unique(array_filter(array_map(fn ($r) => $r['planner']['prompt_version'] ?? null, $ok)))),
], JSON_UNESCAPED_UNICODE)."\n";
unset($key);
