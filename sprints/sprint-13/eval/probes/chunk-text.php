<?php

/**
 * Sprint 13 CP-1 root-cause probe — print chunk text (env CHUNK_IDS=comma list,
 * CHUNK_CLIP default 900). Read-only.
 */

use Illuminate\Support\Facades\DB;

$ids = array_map('intval', explode(',', (string) getenv('CHUNK_IDS')));
$clip = (int) (getenv('CHUNK_CLIP') ?: 900);
$cols = DB::getSchemaBuilder()->getColumnListing('document_chunks');
$textCol = null;
foreach (['content', 'text', 'body'] as $cand) {
    if (in_array($cand, $cols, true)) {
        $textCol = $cand;
        break;
    }
}
echo 'COLS '.implode(',', $cols)."\n";
foreach (DB::table('document_chunks')->whereIn('id', $ids)->get() as $c) {
    $txt = $textCol ? (string) $c->{$textCol} : '';
    echo 'CHUNK '.$c->id.' doc'.$c->document_id.' len='.mb_strlen($txt).' '.json_encode(mb_substr($txt, 0, $clip), JSON_UNESCAPED_UNICODE)."\n";
    if (preg_match('/Art[íi]culo\s+46|Art\.\s*46/u', $txt, $m, PREG_OFFSET_CAPTURE)) {
        echo '  ART46_AT '.$m[0][1]."\n";
    }
}
