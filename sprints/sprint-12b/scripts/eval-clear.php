<?php

/*
 * Slice 12b, item 2 — clear the escalations board of pre-pilot eval traffic.
 *
 * NOT a product path and NOT in hr-backend: a one-off ops script, run through
 * `php artisan tinker --execute="require '/tmp/eval-clear.php';"` (the same PID-1
 * env-load recipe as the other /opt/hr-staging/run-*.sh diagnostics).
 * Parameters come from environment variables, because tinker has no argv:
 *
 *   EC_MODE            dry (default) | execute | reverse
 *   EC_OUT_DIR         where the manifest / result files go (default /tmp)
 *   EC_MANIFEST        execute/reverse: path of the frozen manifest
 *   EC_EXPECT_SHA      execute/reverse: sha256 of that manifest file (guard 1)
 *   EC_SNAPSHOT        execute/reverse: RDS snapshot id taken this session   (guard 2)
 *   EC_SNAPSHOT_STATUS execute/reverse: must be "available" — the box has no
 *                      rds:Describe*, so the wrapper that created the snapshot
 *                      checks it with the AWS CLI and passes the result in
 *   EC_EXPECT_DB_HOST  execute/reverse: the staging DB host; must equal the
 *                      connection's host AND app()->environment('staging') (guard 3)
 *   EC_ACTOR_ID        admin id recorded as the actor (default 1)
 *
 * DRY RUN is the default and writes nothing to the database (it writes the
 * manifest file only). EXECUTE goes ONLY through EscalationService::update() — no
 * raw UPDATE, no DELETE — then appends one more escalation_events row per card
 * carrying the reason code. Nothing is ever deleted. REVERSE walks the manifest
 * back through the same service.
 */

(function () {
    $env = static function (string $k, ?string $d = null): ?string {
        $v = getenv($k);

        return ($v === false || $v === '') ? $d : $v;
    };

    $mode = $env('EC_MODE', 'dry');
    if (! in_array($mode, ['dry', 'execute', 'reverse'], true)) {
        throw new RuntimeException("EC_MODE must be dry|execute|reverse, got '{$mode}'");
    }

    // The predicate (plan.md §1.3). Open only; Tier A = the seeded test-*@example.com
    // profiles; Tier B = .internal AND a seeded "Test …" name. Joined through
    // employees, never through chat_sessions. The text is hashed into the manifest.
    $tierSql = "CASE
        WHEN e.email ~* '^test-[a-z0-9-]+@example\\.com$' THEN 'A'
        WHEN (e.email ~* '@hr-staging\\.internal$' AND e.full_name ILIKE 'Test %') THEN 'B'
        ELSE NULL END";
    $predicateText = "c.status IN ('new','assigned','in_progress') AND c.id <= :frozen_max_id AND tier IS NOT NULL; tier = ".preg_replace('/\s+/', ' ', $tierSql);
    $predicateSha = hash('sha256', $predicateText);

    $baseSelect = "SELECT c.id, c.uuid, c.status, c.assigned_to, c.created_at,
            e.email, e.full_name, ({$tierSql}) AS tier,
            (SELECT count(*) FROM escalation_events ev WHERE ev.escalation_card_id = c.id) AS events,
            EXISTS (SELECT 1 FROM escalation_resolutions r WHERE r.card_id = c.id) AS has_resolution
        FROM escalation_cards c JOIN employees e ON e.id = c.employee_id";

    $conn = config('database.default');
    $dbHost = (string) config("database.connections.{$conn}.host");
    $appEnv = (string) app()->environment();
    $outDir = rtrim($env('EC_OUT_DIR', '/tmp'), '/');
    $actorId = (int) $env('EC_ACTOR_ID', '1');
    $line = static function (string $s = ''): void {
        echo $s."\n";
    };
    $touched = static fn (object $r): bool => (int) $r->events > 0 || (bool) $r->has_resolution
        || $r->assigned_to !== null || $r->status !== 'new';

    $openRows = static fn () => \Illuminate\Support\Facades\DB::select($baseSelect." WHERE c.status IN ('new','assigned','in_progress') ORDER BY c.id");
    $openCount = static fn (): int => (int) \Illuminate\Support\Facades\DB::table('escalation_cards')->whereIn('status', ['new', 'assigned', 'in_progress'])->count();

    // ------------------------------------------------------------ DRY RUN
    if ($mode === 'dry') {
        $runId = $env('EC_RUN_ID', '12b-'.gmdate('Ymd\THi\Z'));
        $frozenMax = (int) \Illuminate\Support\Facades\DB::table('escalation_cards')->max('id');
        $open = $openRows();

        $rows = [];
        $stays = [];
        foreach ($open as $r) {
            if ($r->tier === null) {
                $stays[] = ['id' => (int) $r->id, 'uuid' => $r->uuid, 'account' => $r->email, 'status' => $r->status, 'reason' => 'no_tier_match'];
            } elseif ((int) $r->id > $frozenMax) {
                $stays[] = ['id' => (int) $r->id, 'uuid' => $r->uuid, 'account' => $r->email, 'status' => $r->status, 'reason' => 'after_frozen_max_id'];
            } else {
                $rows[] = [
                    'id' => (int) $r->id, 'uuid' => $r->uuid, 'employee_email' => $r->email, 'tier' => $r->tier,
                    'prior_status' => $r->status, 'assigned_to' => $r->assigned_to === null ? null : (int) $r->assigned_to,
                    'touched' => $touched($r),
                ];
            }
        }
        $closed = \Illuminate\Support\Facades\DB::select($baseSelect." WHERE c.status = 'closed' ORDER BY c.id");
        $resolvedCount = (int) \Illuminate\Support\Facades\DB::table('escalation_cards')->where('status', 'resolved')->count();
        $totalCards = (int) \Illuminate\Support\Facades\DB::table('escalation_cards')->count();

        $manifest = [
            'run_id' => $runId,
            'db_host' => $dbHost,
            'app_env' => $appEnv,
            'frozen_max_id' => $frozenMax,
            'predicate' => $predicateText,
            'predicate_sha256' => $predicateSha,
            'open_before' => count($open),
            'rows' => $rows,
            'stays_open' => $stays,
            'already_closed' => array_map(static fn ($r) => ['id' => (int) $r->id, 'account' => $r->email], $closed),
        ];
        $file = "{$outDir}/eval-clear-manifest-{$runId}.json";
        file_put_contents($file, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
        $sha = hash_file('sha256', $file);

        $line("== 12b eval-clear · DRY RUN · run_id={$runId}");
        $line("db_host={$dbHost}  env={$appEnv}  frozen_max_id={$frozenMax}");
        $line("predicate_sha256={$predicateSha}");
        $line("cards in table: {$totalCards}  (open ".count($open).", resolved {$resolvedCount}, closed ".count($closed).')');

        $line('-- tier × status (open cards that WOULD close)');
        $grid = [];
        foreach ($rows as $r) {
            $grid[$r['tier']][$r['prior_status']] = ($grid[$r['tier']][$r['prior_status']] ?? 0) + 1;
        }
        ksort($grid);
        $line(sprintf(' %-5s| %5s | %8s | %11s | %5s', 'tier', 'new', 'assigned', 'in_progress', 'total'));
        $tot = ['new' => 0, 'assigned' => 0, 'in_progress' => 0];
        foreach ($grid as $tier => $g) {
            $n = $g['new'] ?? 0;
            $a = $g['assigned'] ?? 0;
            $p = $g['in_progress'] ?? 0;
            $tot['new'] += $n;
            $tot['assigned'] += $a;
            $tot['in_progress'] += $p;
            $line(sprintf(' %-5s| %5d | %8d | %11d | %5d', $tier, $n, $a, $p, $n + $a + $p));
        }
        $line(sprintf(' %-5s| %5d | %8d | %11d | %5d', 'TOTAL', $tot['new'], $tot['assigned'], $tot['in_progress'], count($rows)));

        $line('-- by account (would close): account | cards | first → last created');
        $byAcct = [];
        foreach ($open as $r) {
            if ($r->tier === null || (int) $r->id > $frozenMax) {
                continue;
            }
            $byAcct[$r->email]['n'] = ($byAcct[$r->email]['n'] ?? 0) + 1;
            $byAcct[$r->email]['first'] = min($byAcct[$r->email]['first'] ?? $r->created_at, $r->created_at);
            $byAcct[$r->email]['last'] = max($byAcct[$r->email]['last'] ?? $r->created_at, $r->created_at);
            $byAcct[$r->email]['tier'] = $r->tier;
        }
        uasort($byAcct, static fn ($x, $y) => $y['n'] <=> $x['n']);
        foreach ($byAcct as $acct => $a) {
            $line(sprintf('  %-42s %5d  %s → %s  (tier %s)', $acct, $a['n'], substr((string) $a['first'], 0, 10), substr((string) $a['last'], 0, 10), $a['tier']));
        }

        $touchedRows = array_values(array_filter($open, static fn ($r) => $r->tier !== null && (int) $r->id <= $frozenMax && $touched($r)));
        $line('-- human-touched ('.count($touchedRows).'): id, uuid, account, status, assigned_to, events, resolution?');
        foreach ($touchedRows as $r) {
            $line(sprintf('  #%d  %s  %s  %s  assigned_to=%s  events=%d  resolution=%s', $r->id, $r->uuid, $r->email, $r->status, $r->assigned_to ?? '-', $r->events, $r->has_resolution ? 'yes' : 'no'));
        }

        $line('-- STAYS OPEN ('.count($stays).'): id, uuid, account, status, reason');
        foreach ($stays as $s) {
            $line("  #{$s['id']}  {$s['uuid']}  {$s['account']}  {$s['status']}  {$s['reason']}");
        }
        $line('-- already closed ('.count($closed).'): '.implode(', ', array_map(static fn ($r) => "#{$r->id} ({$r->email})", $closed)));
        $line("manifest: {$file}  sha256={$sha}  (".count($rows).' rows)');

        return;
    }

    // ------------------------------------------- EXECUTE / REVERSE: guards
    $manifestPath = $env('EC_MANIFEST');
    $expectSha = $env('EC_EXPECT_SHA');
    $snapshot = $env('EC_SNAPSHOT');
    $snapshotStatus = $env('EC_SNAPSHOT_STATUS');
    $expectHost = $env('EC_EXPECT_DB_HOST');

    // Guard 1 — the manifest is exactly the file the human confirmed.
    if ($manifestPath === null || $expectSha === null || ! is_file($manifestPath)) {
        throw new RuntimeException('GUARD 1: EC_MANIFEST (existing file) and EC_EXPECT_SHA are required');
    }
    $actualSha = hash_file('sha256', $manifestPath);
    if (! hash_equals(strtolower($expectSha), $actualSha)) {
        throw new RuntimeException("GUARD 1: manifest sha256 {$actualSha} does not match EC_EXPECT_SHA {$expectSha}");
    }
    // Guard 2 — a fresh RDS snapshot exists and is available.
    if ($snapshot === null || $snapshotStatus !== 'available') {
        throw new RuntimeException('GUARD 2: EC_SNAPSHOT and EC_SNAPSHOT_STATUS=available are required');
    }
    // Guard 3 — this is the staging database, and the app thinks so too.
    if ($expectHost === null || $expectHost !== $dbHost || ! app()->environment('staging')) {
        throw new RuntimeException("GUARD 3: db host '{$dbHost}' / env '{$appEnv}' is not the expected staging target");
    }

    $manifest = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
    $runId = $manifest['run_id'];
    $frozenMax = (int) $manifest['frozen_max_id'];
    if (($manifest['predicate_sha256'] ?? null) !== $predicateSha) {
        throw new RuntimeException('manifest was produced by a different predicate than this script');
    }
    $actor = App\Models\Admin::find($actorId);
    if ($actor === null) {
        throw new RuntimeException("actor admin id {$actorId} not found");
    }
    $svc = app(App\Services\EscalationService::class);
    $startedAt = now();

    $line('== 12b eval-clear · '.strtoupper($mode)." · run_id={$runId}");
    $line("db_host={$dbHost}  env={$appEnv}  actor=#{$actor->id} {$actor->email}");
    $line("manifest={$manifestPath}  sha256={$actualSha}  rows=".count($manifest['rows']));
    $line("snapshot={$snapshot} ({$snapshotStatus})");

    $openBefore = $openCount();
    $outcomes = [];

    // --------------------------------------------------------------- EXECUTE
    if ($mode === 'execute') {
        foreach ($manifest['rows'] as $m) {
            $id = (int) $m['id'];
            try {
                $result = \Illuminate\Support\Facades\DB::transaction(function () use ($m, $id, $frozenMax, $baseSelect, $svc, $actor, $runId, $actualSha, $snapshot) {
                    // Lock the row, then re-read it through the SAME predicate.
                    \Illuminate\Support\Facades\DB::select('SELECT id FROM escalation_cards WHERE id = ? FOR UPDATE', [$id]);
                    $cur = \Illuminate\Support\Facades\DB::select($baseSelect.' WHERE c.id = ?', [$id])[0] ?? null;
                    if ($cur === null) {
                        return 'skipped:missing_card';
                    }
                    if ($id > $frozenMax) {
                        return 'skipped:after_frozen_max_id';
                    }
                    if (! in_array($cur->status, ['new', 'assigned', 'in_progress'], true)) {
                        return "skipped:not_open_now({$cur->status})";
                    }
                    if ($cur->status !== $m['prior_status']) {
                        return "skipped:status_changed({$m['prior_status']}→{$cur->status})";
                    }
                    if ($cur->tier === null || $cur->tier !== $m['tier']) {
                        return 'skipped:no_longer_matches_predicate';
                    }

                    $card = App\Models\EscalationCard::findOrFail($id);
                    $svc->update($card, 'closed', false, $actor);   // writes the status_change event
                    App\Models\EscalationEvent::create([
                        'escalation_card_id' => $id,
                        'type' => 'bulk_closed',
                        'old_value' => $m['prior_status'],
                        'new_value' => 'closed',
                        'actor_id' => $actor->id,
                        'note' => 'eval_traffic_pre_pilot',
                        'detail' => ['run_id' => $runId, 'tier' => $m['tier'], 'manifest_sha256' => $actualSha, 'snapshot_id' => $snapshot],
                    ]);

                    return 'closed';
                });
            } catch (Throwable $e) {
                $result = 'error:'.$e->getMessage();
            }
            $outcomes[$id] = ['result' => $result, 'tier' => $m['tier'], 'prior_status' => $m['prior_status']];
        }
    }

    // --------------------------------------------------------------- REVERSE
    if ($mode === 'reverse') {
        // closed → in_progress → (assigned → (new)), all legal service transitions.
        $path = ['in_progress' => ['in_progress'], 'assigned' => ['in_progress', 'assigned'], 'new' => ['in_progress', 'assigned', 'new']];
        foreach ($manifest['rows'] as $m) {
            $id = (int) $m['id'];
            try {
                $result = \Illuminate\Support\Facades\DB::transaction(function () use ($m, $id, $svc, $actor, $runId, $path, $snapshot) {
                    \Illuminate\Support\Facades\DB::select('SELECT id FROM escalation_cards WHERE id = ? FOR UPDATE', [$id]);
                    $card = App\Models\EscalationCard::find($id);
                    if ($card === null || $card->status !== 'closed') {
                        return 'skipped:not_closed_now';
                    }
                    $byThisRun = App\Models\EscalationEvent::where('escalation_card_id', $id)->where('type', 'bulk_closed')
                        ->whereRaw("detail->>'run_id' = ?", [$runId])->exists();
                    if (! $byThisRun) {
                        return 'skipped:not_closed_by_this_run';
                    }
                    foreach ($path[$m['prior_status']] as $step) {
                        $card = $svc->update($card, $step, false, $actor);
                    }
                    App\Models\EscalationEvent::create([
                        'escalation_card_id' => $id,
                        'type' => 'bulk_closed_reverted',
                        'old_value' => 'closed',
                        'new_value' => $m['prior_status'],
                        'actor_id' => $actor->id,
                        'note' => 'eval_traffic_pre_pilot_reverted',
                        'detail' => ['run_id' => $runId, 'snapshot_id' => $snapshot],
                    ]);

                    return 'reverted';
                });
            } catch (Throwable $e) {
                $result = 'error:'.$e->getMessage();
            }
            $outcomes[$id] = ['result' => $result, 'tier' => $m['tier'], 'prior_status' => $m['prior_status']];
        }
    }

    // ---------------------------------------------------------------- report
    $done = $mode === 'execute' ? 'closed' : 'reverted';
    $ok = array_filter($outcomes, static fn ($o) => $o['result'] === $done);
    $skipped = array_filter($outcomes, static fn ($o) => str_starts_with($o['result'], 'skipped'));
    $errors = array_filter($outcomes, static fn ($o) => str_starts_with($o['result'], 'error'));
    $okIds = array_keys($ok);

    $byTier = [];
    $byPrior = [];
    foreach ($ok as $o) {
        $byTier[$o['tier']] = ($byTier[$o['tier']] ?? 0) + 1;
        $byPrior[$o['prior_status']] = ($byPrior[$o['prior_status']] ?? 0) + 1;
    }
    ksort($byTier);
    ksort($byPrior);

    // Audit rows this run wrote, counted from the table (not from a counter).
    $statusRows = $okIds === [] ? 0 : (int) \Illuminate\Support\Facades\DB::table('escalation_events')->whereIn('escalation_card_id', $okIds)
        ->where('type', 'status_change')->where('created_at', '>=', $startedAt)->count();
    $reasonType = $mode === 'execute' ? 'bulk_closed' : 'bulk_closed_reverted';
    $reasonRows = $okIds === [] ? 0 : (int) \Illuminate\Support\Facades\DB::table('escalation_events')->whereIn('escalation_card_id', $okIds)
        ->where('type', $reasonType)->whereRaw("detail->>'run_id' = ?", [$runId])->count();

    // Everything still open that is NOT in the manifest, and why.
    $inManifest = array_flip(array_map(static fn ($m) => (int) $m['id'], $manifest['rows']));
    $stays = [];
    foreach ($openRows() as $r) {
        if (isset($inManifest[(int) $r->id]) && ! isset($ok[(int) $r->id]) && $mode === 'execute') {
            $stays[] = "#{$r->id} {$r->email} {$r->status} — ".($outcomes[(int) $r->id]['result'] ?? 'in manifest, not processed');

            continue;
        }
        if (isset($inManifest[(int) $r->id])) {
            continue; // reverse mode: reopened by design
        }
        $stays[] = "#{$r->id} {$r->email} {$r->status} — ".($r->tier === null ? 'no_tier_match' : ((int) $r->id > $frozenMax ? 'after_frozen_max_id' : 'not_in_manifest'));
    }
    $openAfter = $openCount();

    $line('-- result');
    $line("open before: {$openBefore}   open after: {$openAfter}");
    $line("{$done}: ".count($ok).'  by tier '.json_encode($byTier).'  by prior status '.json_encode($byPrior));
    $line('skipped: '.count($skipped).'   errors: '.count($errors));
    foreach ($skipped + $errors as $id => $o) {   // `+` keeps the card-id keys (array_merge would renumber them)
        $line("  #{$id} {$o['result']}");
    }
    $line('-- left open ('.count($stays).')');
    foreach ($stays as $s) {
        $line("  {$s}");
    }
    $line("escalation_events written by this run: status_change={$statusRows}  {$reasonType}={$reasonRows}  total=".($statusRows + $reasonRows)
        .' (expect '.(2 * count($ok)).' = 2 × '.count($ok).')');
    $line("snapshot={$snapshot}  run_id={$runId}  manifest_sha256={$actualSha}");

    $resultFile = "{$outDir}/eval-clear-{$mode}-result-{$runId}.json";
    file_put_contents($resultFile, json_encode([
        'mode' => $mode, 'run_id' => $runId, 'manifest_sha256' => $actualSha, 'snapshot_id' => $snapshot,
        'open_before' => $openBefore, 'open_after' => $openAfter, 'by_tier' => $byTier, 'by_prior_status' => $byPrior,
        'events_status_change' => $statusRows, 'events_reason' => $reasonRows, 'left_open' => $stays, 'outcomes' => $outcomes,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)."\n");
    $line("result file: {$resultFile}");
})();
