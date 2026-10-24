<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Odden\Core\Encryption\EncryptedAmounts;
use Odden\Sales\Actions\CalculatePipelineForecastAction;
use Odden\Sales\Models\Deal;
use Odden\Sales\Models\Pipeline;

/*
 * Times the in-PHP totals over encrypted deal amounts (PLAN-encryption-at-rest.md, section 6). It does nothing unless ODDEN_BENCH_DEALS is
 * set, so the suite stays fast:
 *
 *   ODDEN_BENCH_DEALS=10000,50000 vendor/bin/pest tests/Benchmark
 *
 * Add DB_CONNECTION=pgsql / mariadb (and the DB_* variables, see scripts/ci.sh) to time another database. Each size reports, in seconds,
 * the same work with the amounts plain (the database adds them) and encrypted (decrypted and added here).
 */
$sizes = array_filter(array_map('intval', explode(',', (string) getenv('ODDEN_BENCH_DEALS'))));

foreach ($sizes as $size) {
    it("times the encrypted amount totals on {$size} deals", function () use ($size): void {
        config(['odden-core.encryption.fallback_limit' => $size + 1]);
        $pipeline = Pipeline::factory()->withStages()->create();
        $stages = $pipeline->stages->pluck('id')->all();
        $now = now()->toDateTimeString();

        foreach (array_chunk(range(1, $size), 1000) as $chunk) {
            DB::table('odden_deals')->insert(array_map(fn (int $n): array => [
                'pipeline_id' => $pipeline->id, 'stage_id' => $stages[$n % count($stages)], 'name' => "Deal {$n}", 'amount' => ($n * 37 % 90000) + 100.25,
                'currency' => 'USD', 'status' => 'open', 'created_at' => $now, 'updated_at' => $now,
            ], $chunk));
        }

        $time = function (callable $work) use (&$seconds): mixed {
            $start = hrtime(true);
            $result = $work();
            $seconds = (hrtime(true) - $start) / 1e9;

            return $result;
        };
        $amounts = app(EncryptedAmounts::class);
        $report = [];
        $measure = function (string $form) use ($time, $amounts, $pipeline, &$seconds, &$report): void {
            $time(fn () => $amounts->sum(Deal::query(), 'amount'));
            $report["sum of all amounts ({$form})"] = $seconds;

            $time(fn () => (new CalculatePipelineForecastAction)->execute($pipeline->id));
            $report["pipeline forecast ({$form})"] = $seconds;

            $time(fn () => $amounts->top(Deal::query(), 'amount', 25)->get());
            $report["25 largest deals ({$form})"] = $seconds;
        };

        $measure('plain');

        config(['odden-core.encryption.business_data' => true]);
        $time(fn () => Artisan::call('odden:convert-business-data', ['--to' => 'encrypted']));
        $report['convert to encrypted'] = $seconds;

        $measure('encrypted');

        $time(fn () => Artisan::call('odden:convert-business-data', ['--to' => 'plain']));
        $report['convert back to plain'] = $seconds;

        fwrite(STDERR, "\n".DB::getDriverName()." {$size} deals\n");

        foreach ($report as $what => $took) {
            fwrite(STDERR, sprintf("  %-48s %7.3f s\n", $what, $took));
        }

        expect(true)->toBeTrue();
    });
}
