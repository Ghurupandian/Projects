<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AggregateDailyUsageJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [60, 300, 900];

    public function __construct(public readonly string $usageDate) {}

    public function uniqueId(): string
    {
        return $this->usageDate;
    }

    public function handle(): void
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $this->usageDate);

        if ($date === false || $date->format('Y-m-d') !== $this->usageDate) {
            throw new \InvalidArgumentException('Usage date must be a valid YYYY-MM-DD date.');
        }

        $start = $date->format('Y-m-d 00:00:00');
        $end = $date->modify('+1 day')->format('Y-m-d 00:00:00');

        $hasInvoicedCycle = DB::table('invoices')
            ->where('cycle_start', '<=', $date->format('Y-m-d'))
            ->where('cycle_end', '>=', $date->format('Y-m-d'))
            ->exists();
        $lateEventCount = 0;

        if ($hasInvoicedCycle) {
            $lateEventCount = DB::table('usage_events as events')
                ->where('events.occurred_at', '>=', $start)
                ->where('events.occurred_at', '<', $end)
                ->whereExists(function ($query): void {
                    $query->selectRaw('1')
                        ->from('subscriptions')
                        ->join('invoices', 'invoices.subscription_id', '=', 'subscriptions.id')
                        ->whereColumn('subscriptions.customer_id', 'events.customer_id')
                        ->whereRaw('invoices.cycle_start <= DATE(events.occurred_at)')
                        ->whereRaw('invoices.cycle_end >= DATE(events.occurred_at)');
                })
                ->count();
        }

        if ($lateEventCount > 0) {
            Log::warning('Usage events for an already-invoiced cycle were excluded from aggregation.', [
                'usage_date' => $this->usageDate,
                'event_count' => $lateEventCount,
            ]);
        }

        DB::table('usage_events')
            ->where('occurred_at', '>=', $start)
            ->where('occurred_at', '<', $end)
            ->select('customer_id')
            ->distinct()
            ->orderBy('customer_id')
            ->chunk(5000, function ($customers) use ($start, $end): void {
                $customerIds = $customers->pluck('customer_id')->all();
                $placeholders = implode(',', array_fill(0, count($customerIds), '?'));

                $sql = <<<SQL
                    INSERT INTO daily_usages (merchant_id, customer_id, usage_date, total_units, created_at, updated_at)
                    SELECT events.merchant_id, events.customer_id, DATE(events.occurred_at), SUM(events.units), UTC_TIMESTAMP(), UTC_TIMESTAMP()
                    FROM usage_events AS events
                    WHERE events.occurred_at >= ?
                      AND events.occurred_at < ?
                      AND events.customer_id IN ({$placeholders})
                      AND NOT EXISTS (
                          SELECT 1
                          FROM subscriptions
                          INNER JOIN invoices ON invoices.subscription_id = subscriptions.id
                          WHERE subscriptions.customer_id = events.customer_id
                            AND invoices.cycle_start <= DATE(events.occurred_at)
                            AND invoices.cycle_end >= DATE(events.occurred_at)
                      )
                    GROUP BY events.merchant_id, events.customer_id, DATE(events.occurred_at)
                    ON DUPLICATE KEY UPDATE
                        total_units = VALUES(total_units),
                        updated_at = VALUES(updated_at)
                    SQL;

                DB::statement($sql, [$start, $end, ...$customerIds]);
            });
    }
}
