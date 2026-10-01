<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Convenio;
use App\Models\Employee;
use App\Models\EscalationCard;
use App\Models\EscalationEvent;
use App\Models\Sector;
use App\Models\Territory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Fixture test for sprints/sprint-12b/scripts/eval-clear.php (run 2026-10-01: 55 assertions, green).
 * Kept here as a record; it is NOT part of the product test suite. To re-run, copy it to
 * hr-backend/tests/Feature/ temporarily (it needs the backend's test DB harness), run
 * `php artisan test --filter=TmpEvalClearFixtureTest`, then remove it again.
 */
class TmpEvalClearFixtureTest extends TestCase
{
    use RefreshDatabase;

    private const SCRIPT = __DIR__.'/../../../hr-docs/sprints/sprint-12b/scripts/eval-clear.php';

    private array $employees = [];

    private function emp(string $email, string $name): Employee
    {
        $territory = Territory::firstOrCreate(['code' => '28'], ['name' => 'Madrid', 'level' => 'provincial', 'aliases' => []]);
        $sector = Sector::firstOrCreate(['name' => 'Sector']);
        $convenio = Convenio::firstOrCreate(['numero' => 'EC1'], ['name' => 'Conv', 'territory_id' => $territory->id, 'sector_id' => $sector->id]);

        return $this->employees[$email] = Employee::create([
            'email' => $email, 'full_name' => $name, 'convenio_id' => $convenio->id, 'job_category_id' => null,
            'territory_id' => $territory->id, 'employment_type' => 'full_time', 'status' => 'active',
        ]);
    }

    private function card(string $email, string $status, ?int $assignedTo = null): EscalationCard
    {
        return EscalationCard::create([
            'employee_id' => $this->employees[$email]->id, 'reason' => 'low_confidence', 'status' => $status, 'assigned_to' => $assignedTo,
        ]);
    }

    private function run_script(array $env): string
    {
        foreach (['EC_MODE', 'EC_OUT_DIR', 'EC_MANIFEST', 'EC_EXPECT_SHA', 'EC_SNAPSHOT', 'EC_SNAPSHOT_STATUS', 'EC_EXPECT_DB_HOST', 'EC_ACTOR_ID', 'EC_RUN_ID'] as $k) {
            putenv($k);
        }
        foreach ($env as $k => $v) {
            putenv("{$k}={$v}");
        }
        ob_start();
        try {
            require self::SCRIPT;

            return (string) ob_get_contents();
        } finally {
            ob_end_clean();
        }
    }

    public function test_dry_run_guards_execute_skips_and_reverse(): void
    {
        $dir = sys_get_temp_dir().'/ec-'.uniqid();
        mkdir($dir);
        $admin = Admin::create(['email' => 'admin@hr-staging.internal', 'full_name' => 'Admin', 'status' => 'active']);
        $host = (string) config('database.connections.'.config('database.default').'.host');

        $this->emp('test-gipuzkoa@example.com', 'Gip Test');
        $this->emp('employee@hr-staging.internal', 'Test Employee');
        $this->emp('real@hr-staging.internal', 'María Real');        // internal but not "Test "
        $this->emp('javier@ffrw.es', 'Javier');                       // real
        $this->emp('someone@example.com', 'Someone');                 // example.com but no test- prefix
        $this->emp('Test-Upper@Example.com', 'Upper');                // case-insensitive Tier A

        $aNew = $this->card('test-gipuzkoa@example.com', 'new');
        $aAssigned = $this->card('test-gipuzkoa@example.com', 'assigned', $admin->id);
        $aProg = $this->card('test-gipuzkoa@example.com', 'in_progress');
        $bNew = $this->card('employee@hr-staging.internal', 'new');
        $upper = $this->card('Test-Upper@Example.com', 'new');
        $changed = $this->card('test-gipuzkoa@example.com', 'new');   // will move between dry run and execute
        $realInternal = $this->card('real@hr-staging.internal', 'new');
        $javier = $this->card('javier@ffrw.es', 'new');
        $someone = $this->card('someone@example.com', 'in_progress');
        $resolved = $this->card('test-gipuzkoa@example.com', 'resolved');
        $closedAlready = $this->card('employee@hr-staging.internal', 'closed');
        DB::table('escalation_resolutions')->insert(['card_id' => $aNew->id, 'resolved_by' => $admin->id, 'resolution_text' => 'x', 'created_at' => now(), 'updated_at' => now()]);

        // ---- dry run: writes no DB rows, only the manifest file
        $eventsBefore = EscalationEvent::count();
        $statusBefore = EscalationCard::orderBy('id')->pluck('status', 'id')->all();
        $out = $this->run_script(['EC_MODE' => 'dry', 'EC_OUT_DIR' => $dir, 'EC_RUN_ID' => 'T1']);
        $this->assertSame($eventsBefore, EscalationEvent::count());
        $this->assertSame($statusBefore, EscalationCard::orderBy('id')->pluck('status', 'id')->all());
        $this->assertStringContainsString('DRY RUN', $out);
        $file = "{$dir}/eval-clear-manifest-T1.json";
        $sha = hash_file('sha256', $file);
        $this->assertStringContainsString("sha256={$sha}", $out);
        $m = json_decode(file_get_contents($file), true);
        $ids = array_column($m['rows'], 'id');
        sort($ids);
        $expect = [$aNew->id, $aAssigned->id, $aProg->id, $bNew->id, $upper->id, $changed->id];
        sort($expect);
        $this->assertSame($expect, $ids, 'Tier A (incl. case-insensitive) + Tier B only');
        $stay = collect($m['stays_open'])->pluck('reason', 'id')->all();
        $this->assertSame('no_tier_match', $stay[$realInternal->id]);
        $this->assertSame('no_tier_match', $stay[$javier->id]);
        $this->assertSame('no_tier_match', $stay[$someone->id]);
        $this->assertSame([$closedAlready->id], array_column($m['already_closed'], 'id'));
        $this->assertNotContains($resolved->id, $ids);
        $touched = collect($m['rows'])->where('touched', true)->pluck('id')->sort()->values()->all();
        $this->assertSame(collect([$aNew->id, $aAssigned->id, $aProg->id])->sort()->values()->all(), $touched, 'resolution row / assigned / in_progress are human-touched');
        $this->assertSame(6, count($m['rows']));

        // ---- the world moves after the freeze
        $post = $this->card('test-gipuzkoa@example.com', 'new');                    // id > frozen_max_id
        DB::table('escalation_cards')->where('id', $changed->id)->update(['status' => 'in_progress']);

        // ---- guards refuse (and change nothing)
        $good = ['EC_MODE' => 'execute', 'EC_OUT_DIR' => $dir, 'EC_MANIFEST' => $file, 'EC_EXPECT_SHA' => $sha, 'EC_SNAPSHOT' => 'snap-1', 'EC_SNAPSHOT_STATUS' => 'available', 'EC_EXPECT_DB_HOST' => $host, 'EC_ACTOR_ID' => (string) $admin->id];
        $this->app['env'] = 'staging';
        $snap = EscalationCard::orderBy('id')->pluck('status', 'id')->all();
        foreach ([
            'GUARD 1' => ['EC_EXPECT_SHA' => str_repeat('0', 64)],
            'GUARD 1 ' => ['EC_MANIFEST' => "{$dir}/nope.json"],
            'GUARD 2' => ['EC_SNAPSHOT' => ''],
            'GUARD 2 b' => ['EC_SNAPSHOT_STATUS' => 'creating'],
            'GUARD 3' => ['EC_EXPECT_DB_HOST' => 'some-other-host'],
        ] as $label => $override) {
            try {
                $this->run_script(array_merge($good, $override));
                $this->fail("{$label} should have refused");
            } catch (\RuntimeException $e) {
                $this->assertStringStartsWith(substr($label, 0, 7), $e->getMessage());
            }
        }
        $this->app['env'] = 'testing';
        try {
            $this->run_script($good);
            $this->fail('env guard should have refused');
        } catch (\RuntimeException $e) {
            $this->assertStringStartsWith('GUARD 3', $e->getMessage());
        }
        $this->assertSame($snap, EscalationCard::orderBy('id')->pluck('status', 'id')->all(), 'a refused run writes nothing');
        $this->app['env'] = 'staging';

        // ---- execute
        $cardsBefore = EscalationCard::count();
        $eventsBefore = EscalationEvent::count();
        $out = $this->run_script($good);
        $this->assertStringContainsString('open before: 10', $out);
        foreach ([$aNew, $aAssigned, $aProg, $bNew, $upper] as $c) {
            $this->assertSame('closed', $c->fresh()->status, "#{$c->id}");
        }
        $this->assertSame($admin->id, $aAssigned->fresh()->assigned_to, 'assigned_to preserved');
        $this->assertSame('in_progress', $changed->fresh()->status, 'status changed since the freeze → skipped');
        $this->assertStringContainsString("#{$changed->id} skipped:status_changed(new→in_progress)", $out);
        $this->assertSame('new', $post->fresh()->status, 'post-freeze card untouched');
        foreach ([$realInternal, $javier, $someone] as $c) {
            $this->assertNotSame('closed', $c->fresh()->status);
        }
        $this->assertSame('resolved', $resolved->fresh()->status);
        $this->assertSame($cardsBefore, EscalationCard::count(), 'nothing deleted');
        $this->assertSame($eventsBefore + 10, EscalationEvent::count(), '2 events × 5 closed cards');
        $e = EscalationEvent::where('escalation_card_id', $aProg->id)->where('type', 'bulk_closed')->firstOrFail();
        $this->assertSame('eval_traffic_pre_pilot', $e->note);
        $this->assertSame($admin->id, $e->actor_id);
        $this->assertSame('in_progress', $e->old_value);
        $this->assertEquals(['run_id' => 'T1', 'tier' => 'A', 'manifest_sha256' => $sha, 'snapshot_id' => 'snap-1'], $e->detail);
        $this->assertTrue(EscalationEvent::where('escalation_card_id', $aProg->id)->where('type', 'status_change')->where('new_value', 'closed')->exists());
        $this->assertStringContainsString('total=10 (expect 10 = 2 × 5)', $out);
        $this->assertStringContainsString("#{$post->id}", $out);
        $this->assertStringContainsString('after_frozen_max_id', $out);

        // ---- re-running execute is a no-op for already-closed cards
        $out2 = $this->run_script($good);
        $this->assertStringContainsString('skipped:not_open_now(closed)', $out2);
        $this->assertSame($eventsBefore + 10, EscalationEvent::count(), 'idempotent');

        // ---- reverse restores each prior status (and never touches skipped cards)
        $out3 = $this->run_script(array_merge($good, ['EC_MODE' => 'reverse']));
        $this->assertSame('new', $aNew->fresh()->status);
        $this->assertSame('assigned', $aAssigned->fresh()->status);
        $this->assertSame($admin->id, $aAssigned->fresh()->assigned_to);
        $this->assertSame('in_progress', $aProg->fresh()->status);
        $this->assertSame('new', $bNew->fresh()->status);
        $this->assertSame('new', $upper->fresh()->status);
        $this->assertSame('in_progress', $changed->fresh()->status);
        $this->assertStringContainsString('REVERSE', $out3);
        $this->assertTrue(EscalationEvent::where('type', 'bulk_closed_reverted')->count() === 5);
        $this->assertSame($cardsBefore, EscalationCard::count());
    }
}
