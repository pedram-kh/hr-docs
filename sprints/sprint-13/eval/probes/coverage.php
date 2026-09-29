<?php
use Illuminate\Support\Facades\DB;

foreach (DB::select("select c.id, c.name, (select count(*) from document_chunks dc where dc.convenio_id=c.id) chunks, (select string_agg(distinct t.name, ' | ') from reference_facts rf join topics t on t.id=rf.topic_id where rf.convenio_id=c.id and rf.status='verified') fact_topics, (select count(*) from convenio_groups g where g.convenio_id=c.id) grps from convenios c order by c.id") as $r) {
    echo json_encode($r, JSON_UNESCAPED_UNICODE)."\n";
}
