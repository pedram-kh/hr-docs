<?php

/**
 * Slice 13e plan gate — READ-ONLY staging state (SELECTs only; nothing written, no model call, $0).
 *
 *   ./stg.sh hr-backend "php artisan tinker /var/hr-docs/sprints/sprint-13e/eval/probes/readonly-state.php"
 *
 * Prints `RO <name> <json>` lines: the effective answer engine, the Guardarraíles admin layer (blocked/off-domain list, off-domain
 * message, router floor), escalation cards by reason, and — from `message_traces` — how today's `off_domain` turns split by source
 * (guard baseline / guard admin / router / planner) and which `planner_escalation.category` values the planner actually emits.
 */

use App\Models\AnswerEngineSetting;
use App\Models\GuardrailBlockedTopic;
use App\Models\GuardrailConfig;
use Illuminate\Support\Facades\DB;

$out = static function (string $name, mixed $v): void {
    echo 'RO '.$name.' '.json_encode($v, JSON_UNESCAPED_UNICODE)."\n";
};

$out('engine_override', AnswerEngineSetting::query()->value('engine'));
$out('engine_env_baseline', config('hr.answer_engine'));
$out('router_floor_cfg', config('hr.router_confidence_floor'));

$cfg = GuardrailConfig::current();
$out('guardrail_config', [
    'off_domain_message' => $cfg->off_domain_message,
    'router_confidence_floor' => $cfg->router_confidence_floor,
    'convert_allowed_reasons' => $cfg->convert_allowed_reasons,
]);
$out('blocked_topics', GuardrailBlockedTopic::query()->get(['pattern', 'kind', 'enabled'])->toArray());

$out('cards_by_reason', DB::select('select reason, count(*) n from escalation_cards group by reason order by n desc'));
$out('cards_total', DB::table('escalation_cards')->count());

$out('turns_by_outcome', DB::select("select coalesce(trace->'floor_decision'->>'outcome','unknown') outcome, case when trace->'agent' is not null then 'agent' else 'classic' end engine, count(*) n from message_traces group by 1,2 order by n desc"));

$out('off_domain_turns_by_source', DB::select("
    select case
      when trace->'guardrail_check'->>'rule' = 'legal_medical' then 'guard:legal_medical'
      when trace->'guardrail_check'->>'rule' = 'other_employee_data' then 'guard:other_employee_data'
      when trace->'guardrail_check'->>'layer' = 'admin' then 'guard:admin'
      when trace->'floor_decision'->>'note' = 'router classified off_domain' then 'router'
      else 'other' end as source, count(*) n
    from message_traces
    where trace->'floor_decision'->>'escalation_reason' = 'off_domain'
    group by 1 order by n desc"));

$out('planner_escalated_by_category', DB::select("
    select trace->'agent'->'planner_escalation'->>'category' as category, count(*) n
    from message_traces
    where trace->'floor_decision'->>'escalation_reason' = 'planner_escalated'
    group by 1 order by n desc"));

// The raw questions behind planner off_domain escalations (test accounts and real alike — staging has only test users).
$out('planner_off_domain_samples', DB::select("
    select um.content as question, mt.trace->'agent'->'planner_escalation'->>'reason' as planner_reason,
           jsonb_array_length(coalesce((mt.trace->'agent'->'steps')::jsonb,'[]'::jsonb)) as steps
    from message_traces mt
    join chat_messages am on am.id = mt.message_id
    join chat_messages um on um.session_id = am.session_id and um.role='user'
      and um.id = (select max(id) from chat_messages x where x.session_id = am.session_id and x.role='user' and x.id < am.id)
    where mt.trace->'agent'->'planner_escalation'->>'category' = 'off_domain'
    order by am.id desc limit 40"));

$out('router_off_domain_samples', DB::select("
    select um.content as question, mt.trace->'router_decision'->>'confidence' as router_conf
    from message_traces mt
    join chat_messages am on am.id = mt.message_id
    join chat_messages um on um.session_id = am.session_id and um.role='user'
      and um.id = (select max(id) from chat_messages x where x.session_id = am.session_id and x.role='user' and x.id < am.id)
    where mt.trace->'floor_decision'->>'note' = 'router classified off_domain'
    order by am.id desc limit 20"));
