<?php

/**
 * Sprint 13 step 11 — read-only export of the verified, in-validity
 * reference_facts (as of today) with the scope a test employee needs to hit
 * each one. Output: one `FACT {json}` line per fact. Feeds the fact-routing
 * fixture generator (facts-to-fixture.py). Writes nothing.
 */

use Illuminate\Support\Facades\DB;

$today = now()->toDateString();
$rows = DB::select("
    select rf.id, rf.convenio_id, c.name as convenio, t.name as topic,
           rf.value, rf.group_label, rf.job_category_id, jc.name as job_category,
           rf.validity_start, rf.validity_end, rf.raw_values,
           (select string_agg(cg.label, ' | ' order by cg.id)
              from reference_fact_group_scopes s join convenio_groups cg on cg.id = s.convenio_group_id
             where s.reference_fact_id = rf.id) as bound_groups
      from reference_facts rf
      join convenios c on c.id = rf.convenio_id
      left join topics t on t.id = rf.topic_id
      left join convenio_job_categories jc on jc.id = rf.job_category_id
     where rf.status = 'verified'
       and rf.duplicate_of_id is null
       and rf.superseded_by_id is null
       and (rf.validity_start is null or rf.validity_start <= ?)
       and (rf.validity_end is null or rf.validity_end >= ?)
     order by rf.convenio_id, t.name, rf.id
", [$today, $today]);

foreach ($rows as $r) {
    echo 'FACT '.json_encode($r, JSON_UNESCAPED_UNICODE)."\n";
}
echo 'COUNT '.count($rows)."\n";
