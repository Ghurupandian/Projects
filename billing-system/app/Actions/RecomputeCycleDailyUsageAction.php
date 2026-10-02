<?php

namespace App\Actions;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class RecomputeCycleDailyUsageAction
{
    /**
     * @param  list<int>  $customerIds
     */
    public function execute(string $cycleStart, string $cycleEnd, array $customerIds): void
    {
        if ($customerIds === []) {
            return;
        }

        $start = \DateTimeImmutable::createFromFormat('!Y-m-d', $cycleStart);
        $end = \DateTimeImmutable::createFromFormat('!Y-m-d', $cycleEnd);

        if ($start === false || $end === false
            || $start->format('Y-m-d') !== $cycleStart
            || $end->format('Y-m-d') !== $cycleEnd
            || $start->format('d') !== '01'
            || $end->format('Y-m-d') !== $start->modify('last day of this month')->format('Y-m-d')) {
            throw new InvalidArgumentException('Cycle dates must describe one complete calendar month.');
        }

        $firstDay = $cycleStart;
        $dayAfterEnd = $end->modify('+1 day')->format('Y-m-d');
        $placeholders = implode(',', array_fill(0, count($customerIds), '?'));

        DB::delete(
            <<<SQL
                DELETE daily
                FROM daily_usages AS daily
                WHERE daily.usage_date >= ?
                  AND daily.usage_date < ?
                  AND daily.customer_id IN ({$placeholders})
                  AND NOT EXISTS (
                      SELECT 1
                      FROM subscriptions
                      INNER JOIN invoices ON invoices.subscription_id = subscriptions.id
                      WHERE subscriptions.customer_id = daily.customer_id
                        AND invoices.cycle_start <= daily.usage_date
                        AND invoices.cycle_end >= daily.usage_date
                  )
                SQL,
            [$firstDay, $dayAfterEnd, ...$customerIds],
        );

        DB::statement(
            <<<SQL
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
                SQL,
            [$firstDay.' 00:00:00', $dayAfterEnd.' 00:00:00', ...$customerIds],
        );
    }
}
