<?php

namespace App\Actions;

use App\DTOs\MerchantDashboardData;
use App\Models\Plan;
use App\Services\PlanService;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use OverflowException;

class GetMerchantDashboardAction
{
    private const CACHE_TTL_SECONDS = 600;

    public function __construct(private readonly PlanService $planService) {}

    public function execute(int $merchantId): MerchantDashboardData
    {
        $today = CarbonImmutable::today('UTC');
        $cycleStart = $today->modify('first day of this month');
        $cycleEnd = $today->modify('last day of this month');
        $yesterday = $today->modify('-1 day');
        $daysInCycle = (int) $cycleStart->format('t');
        $elapsedCycleDays = (int) $cycleStart->diff($today)->days + 1;
        $version = $this->planService->getPlanVersion($merchantId);
        $cacheKey = "merchant:{$merchantId}:dashboard:v{$version}:".$today->format('Y-m-d');

        return Cache::remember($cacheKey, self::CACHE_TTL_SECONDS, function () use (
            $merchantId,
            $today,
            $cycleStart,
            $cycleEnd,
            $yesterday,
            $daysInCycle,
            $elapsedCycleDays,
        ): MerchantDashboardData {
            $segmentRows = $this->cycleSegmentRows(
                $merchantId,
                $cycleStart->format('Y-m-d'),
                $cycleEnd->format('Y-m-d'),
                $today->format('Y-m-d'),
                $yesterday->format('Y-m-d'),
            );
            $currentMetrics = $this->buildCycleMetrics(
                $segmentRows,
                $cycleStart,
                $cycleEnd,
                $today,
                $yesterday,
                (int) $cycleStart->diff($today)->days,
                $daysInCycle,
            );

            $churnRiskCustomers = $this->churnRiskCustomers(
                $merchantId,
                $cycleStart,
                $today,
                $yesterday,
            );
            $dailyUsageTrend = $this->dailyUsageTrend($merchantId, $today);
            $activePlans = $this->planService->getActivePlansForMerchant($merchantId)
                ->map(static fn (Plan $plan): array => [
                    'id' => $plan->id,
                    'name' => $plan->name,
                    'billing_cycle' => $plan->billing_cycle,
                    'base_price_paise' => $plan->base_price_paise,
                    'included_units' => $plan->included_units,
                    'overage_rate_paise' => $plan->overage_rate_paise,
                ])
                ->all();

            return new MerchantDashboardData([
                'merchant_id' => $merchantId,
                'period' => [
                    'as_of_date' => $today->format('Y-m-d'),
                    'cycle_start' => $cycleStart->format('Y-m-d'),
                    'cycle_end' => $cycleEnd->format('Y-m-d'),
                    'elapsed_days' => $elapsedCycleDays,
                    'cycle_days' => $daysInCycle,
                ],
                'top_customers' => $currentMetrics['top_customers'],
                'projected_overage_revenue_paise' => $currentMetrics['projected_overage_revenue_paise'],
                'current_cycle_usage' => [
                    'used_units' => $currentMetrics['used_units'],
                    'included_units' => $currentMetrics['included_units'],
                ],
                'churn_risk_customers' => $churnRiskCustomers,
                'daily_usage_trend' => $dailyUsageTrend,
                'active_plans' => $activePlans,
            ]);
        });
    }

    /**
     * @return Collection<int, object>
     */
    private function cycleSegmentRows(
        int $merchantId,
        string $cycleStart,
        string $cycleEnd,
        string $today,
        string $yesterday,
    ) {
        return DB::table('subscriptions')
            ->join('subscription_segments', 'subscription_segments.subscription_id', '=', 'subscriptions.id')
            ->join('plans', 'plans.id', '=', 'subscription_segments.plan_id')
            ->join('customers', 'customers.id', '=', 'subscriptions.customer_id')
            ->leftJoin('daily_usages', function ($join) use ($cycleStart, $today): void {
                $join->on('daily_usages.customer_id', '=', 'subscriptions.customer_id')
                    ->where('daily_usages.usage_date', '>=', $cycleStart)
                    ->where('daily_usages.usage_date', '<=', $today)
                    ->whereRaw('daily_usages.usage_date >= GREATEST(subscription_segments.starts_at, subscriptions.starts_at)')
                    ->whereRaw('daily_usages.usage_date <= LEAST(COALESCE(subscription_segments.ends_at, ?), COALESCE(subscriptions.ends_at, ?))', [
                        $today,
                        $today,
                    ]);
            })
            ->where('subscriptions.merchant_id', $merchantId)
            ->whereDate('subscriptions.starts_at', '<=', $cycleEnd)
            ->where(function ($query) use ($cycleStart): void {
                $query->whereNull('subscriptions.ends_at')
                    ->orWhereDate('subscriptions.ends_at', '>=', $cycleStart);
            })
            ->whereDate('subscription_segments.starts_at', '<=', $cycleEnd)
            ->where(function ($query) use ($cycleStart): void {
                $query->whereNull('subscription_segments.ends_at')
                    ->orWhereDate('subscription_segments.ends_at', '>=', $cycleStart);
            })
            ->select([
                'subscriptions.id as subscription_id',
                'subscriptions.customer_id',
                'subscriptions.starts_at as subscription_starts_at',
                'subscriptions.ends_at as subscription_ends_at',
                'customers.customer_reference',
                'customers.name as customer_name',
                'subscription_segments.id as segment_id',
                'subscription_segments.plan_id',
                'subscription_segments.starts_at as segment_starts_at',
                'subscription_segments.ends_at as segment_ends_at',
                'plans.name as plan_name',
                'plans.included_units',
                'plans.overage_rate_paise',
            ])
            ->selectRaw('COALESCE(SUM(daily_usages.total_units), 0) as usage_units')
            ->selectRaw(
                'COALESCE(SUM(CASE WHEN daily_usages.usage_date <= ? THEN daily_usages.total_units ELSE 0 END), 0) as completed_usage_units',
                [$yesterday],
            )
            ->groupBy(
                'subscriptions.id',
                'subscriptions.customer_id',
                'subscriptions.starts_at',
                'subscriptions.ends_at',
                'customers.customer_reference',
                'customers.name',
                'subscription_segments.id',
                'subscription_segments.plan_id',
                'subscription_segments.starts_at',
                'subscription_segments.ends_at',
                'plans.name',
                'plans.included_units',
                'plans.overage_rate_paise',
            )
            ->orderBy('subscriptions.customer_id')
            ->orderBy('subscription_segments.starts_at')
            ->get();
    }

    /**
     * @param  Collection<int, object>  $segmentRows
     * @return array{top_customers: list<array<string, mixed>>, used_units: int, included_units: int, projected_overage_revenue_paise: int}
     */
    private function buildCycleMetrics(
        $segmentRows,
        DateTimeImmutable $cycleStart,
        DateTimeImmutable $cycleEnd,
        DateTimeImmutable $today,
        DateTimeImmutable $yesterday,
        int $elapsedCycleDays,
        int $daysInCycle,
    ): array {
        $customers = [];
        $usedUnits = 0;
        $allowanceNumerator = 0;
        $projectedOveragePaise = 0;

        foreach ($segmentRows as $row) {
            $segmentStart = $this->maxDate(
                $this->parseDate($row->segment_starts_at),
                $this->parseDate($row->subscription_starts_at),
                $cycleStart,
            );
            $segmentEnd = $this->minDate(
                $row->segment_ends_at === null ? $cycleEnd : $this->parseDate($row->segment_ends_at),
                $row->subscription_ends_at === null ? $cycleEnd : $this->parseDate($row->subscription_ends_at),
                $cycleEnd,
            );

            if ($segmentStart > $segmentEnd) {
                continue;
            }

            $segmentDays = $this->inclusiveDays($segmentStart, $segmentEnd);
            $usage = (int) $row->usage_units;
            $completedUsage = (int) $row->completed_usage_units;
            $planIncludedUnits = (int) $row->included_units;
            $rate = (int) $row->overage_rate_paise;
            $segmentAllowanceNumerator = $this->checkedMultiply($planIncludedUnits, $segmentDays);
            $usedUnits = $this->checkedAdd($usedUnits, $usage);
            $allowanceNumerator = $this->checkedAdd($allowanceNumerator, $segmentAllowanceNumerator);

            $customerId = (int) $row->customer_id;
            if (! isset($customers[$customerId])) {
                $customers[$customerId] = [
                    'customer_reference' => $row->customer_reference,
                    'name' => $row->customer_name,
                    'usage_units' => 0,
                    'included_units_numerator' => 0,
                ];
            }
            $customers[$customerId]['usage_units'] = $this->checkedAdd(
                $customers[$customerId]['usage_units'],
                $usage,
            );
            $customers[$customerId]['included_units_numerator'] = $this->checkedAdd(
                $customers[$customerId]['included_units_numerator'],
                $segmentAllowanceNumerator,
            );

            if ($elapsedCycleDays === 0) {
                continue;
            }

            if ($row->segment_ends_at !== null) {
                $overageNumerator = max(
                    0,
                    $this->checkedMultiply($usage, $daysInCycle) - $segmentAllowanceNumerator,
                );
                $projectedOveragePaise = $this->checkedAdd(
                    $projectedOveragePaise,
                    $this->roundHalfUp($this->checkedMultiply($overageNumerator, $rate), $daysInCycle),
                );

                continue;
            }

            $observedStart = $this->maxDate($segmentStart, $cycleStart);
            $observedEnd = $this->minDate($yesterday, $segmentEnd);
            $completedSegmentDays = $observedStart <= $observedEnd
                ? $this->inclusiveDays($observedStart, $observedEnd)
                : 0;

            if ($completedSegmentDays === 0 || $elapsedCycleDays === 0) {
                continue;
            }

            $projectedUsageNumerator = $this->checkedMultiply(
                $completedUsage,
                $segmentDays,
                $daysInCycle,
            );
            $projectedAllowanceNumerator = $this->checkedMultiply(
                $segmentAllowanceNumerator,
                $completedSegmentDays,
            );
            $projectionDenominator = $this->checkedMultiply($completedSegmentDays, $daysInCycle);
            $overageNumerator = max(0, $projectedUsageNumerator - $projectedAllowanceNumerator);
            $projectedOveragePaise = $this->checkedAdd(
                $projectedOveragePaise,
                $this->roundHalfUp(
                    $this->checkedMultiply($overageNumerator, $rate),
                    $projectionDenominator,
                ),
            );
        }

        $topCustomers = [];
        foreach ($customers as $customer) {
            if ($customer['usage_units'] === 0) {
                continue;
            }

            $customerAllowanceNumerator = $customer['included_units_numerator'];
            $allowanceDisplay = $this->roundHalfUp($customerAllowanceNumerator, $daysInCycle);
            $customer['included_units'] = $allowanceDisplay;
            $customer['allowance_percent'] = $customerAllowanceNumerator === 0
                ? 0
                : round(
                    ($customer['usage_units'] * 100 * $daysInCycle) / $customerAllowanceNumerator,
                    2,
                );
            unset($customer['included_units_numerator']);
            $topCustomers[] = $customer;
        }

        usort($topCustomers, static fn (array $a, array $b): int => $b['usage_units'] <=> $a['usage_units']
            ?: strcmp((string) $a['customer_reference'], (string) $b['customer_reference']));

        return [
            'top_customers' => array_slice($topCustomers, 0, 5),
            'used_units' => $usedUnits,
            'included_units' => $this->roundHalfUp($allowanceNumerator, $daysInCycle),
            'projected_overage_revenue_paise' => $projectedOveragePaise,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function churnRiskCustomers(
        int $merchantId,
        DateTimeImmutable $cycleStart,
        DateTimeImmutable $today,
        DateTimeImmutable $yesterday,
    ): array {
        if ($today->format('j') === '1') {
            return [];
        }

        $previousStart = $cycleStart->modify('-1 month');
        $previousEnd = $previousStart->modify('last day of this month');
        $currentElapsedDay = (int) $yesterday->format('j');
        $previousRangeEnd = $previousStart->setDate(
            (int) $previousStart->format('Y'),
            (int) $previousStart->format('n'),
            min($currentElapsedDay, (int) $previousEnd->format('j')),
        );

        $rows = DB::table('daily_usages')
            ->join('customers', 'customers.id', '=', 'daily_usages.customer_id')
            ->where('daily_usages.merchant_id', $merchantId)
            ->whereBetween('daily_usages.usage_date', [
                $previousStart->format('Y-m-d'),
                $yesterday->format('Y-m-d'),
            ])
            ->groupBy('customers.id', 'customers.customer_reference', 'customers.name')
            ->select([
                'customers.id as customer_id',
                'customers.customer_reference',
                'customers.name',
            ])
            ->selectRaw(
                'SUM(CASE WHEN daily_usages.usage_date BETWEEN ? AND ? THEN daily_usages.total_units ELSE 0 END) as current_units',
                [$cycleStart->format('Y-m-d'), $yesterday->format('Y-m-d')],
            )
            ->selectRaw(
                'SUM(CASE WHEN daily_usages.usage_date BETWEEN ? AND ? THEN daily_usages.total_units ELSE 0 END) as previous_units',
                [$previousStart->format('Y-m-d'), $previousRangeEnd->format('Y-m-d')],
            )
            ->havingRaw(
                'SUM(CASE WHEN daily_usages.usage_date BETWEEN ? AND ? THEN daily_usages.total_units ELSE 0 END) > 0',
                [$previousStart->format('Y-m-d'), $previousRangeEnd->format('Y-m-d')],
            )
            ->get();

        return $rows
            ->filter(static fn (object $row): bool => (int) $row->current_units * 2 < (int) $row->previous_units)
            ->map(static function (object $row): array {
                $previous = (int) $row->previous_units;
                $current = (int) $row->current_units;

                return [
                    'customer_reference' => $row->customer_reference,
                    'name' => $row->name,
                    'current_usage_units' => $current,
                    'previous_usage_units' => $previous,
                    'drop_percent' => round((($previous - $current) * 100) / $previous, 2),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return list<array{date: string, usage_units: int}>
     */
    private function dailyUsageTrend(int $merchantId, DateTimeImmutable $today): array
    {
        $firstDate = $today->modify('-29 days');
        $usageByDate = DB::table('daily_usages')
            ->where('merchant_id', $merchantId)
            ->whereBetween('usage_date', [$firstDate->format('Y-m-d'), $today->format('Y-m-d')])
            ->groupBy('usage_date')
            ->orderBy('usage_date')
            ->selectRaw('usage_date, SUM(total_units) as usage_units')
            ->pluck('usage_units', 'usage_date');

        $trend = [];
        for ($day = $firstDate; $day <= $today; $day = $day->modify('+1 day')) {
            $date = $day->format('Y-m-d');
            $trend[] = [
                'date' => $date,
                'usage_units' => (int) ($usageByDate[$date] ?? 0),
            ];
        }

        return $trend;
    }

    private function parseDate(string $value): DateTimeImmutable
    {
        return new DateTimeImmutable($value, new \DateTimeZone('UTC'));
    }

    private function inclusiveDays(DateTimeImmutable $start, DateTimeImmutable $end): int
    {
        return (int) $start->diff($end)->days + 1;
    }

    private function maxDate(DateTimeImmutable ...$dates): DateTimeImmutable
    {
        usort($dates, static fn (DateTimeImmutable $a, DateTimeImmutable $b): int => $a <=> $b);

        return $dates[array_key_last($dates)];
    }

    private function minDate(DateTimeImmutable ...$dates): DateTimeImmutable
    {
        usort($dates, static fn (DateTimeImmutable $a, DateTimeImmutable $b): int => $a <=> $b);

        return $dates[0];
    }

    private function roundHalfUp(int $numerator, int $denominator): int
    {
        $whole = intdiv($numerator, $denominator);
        $remainder = $numerator % $denominator;
        $halfThreshold = intdiv($denominator, 2) + ($denominator % 2);

        return $remainder >= $halfThreshold ? $whole + 1 : $whole;
    }

    private function checkedMultiply(int ...$values): int
    {
        $result = 1;

        foreach ($values as $value) {
            if ($value < 0 || ($value !== 0 && $result > intdiv(PHP_INT_MAX, $value))) {
                throw new OverflowException('Dashboard calculation exceeds the supported integer range.');
            }

            $result *= $value;
        }

        return $result;
    }

    private function checkedAdd(int $left, int $right): int
    {
        if ($left < 0 || $right < 0 || $left > PHP_INT_MAX - $right) {
            throw new OverflowException('Dashboard calculation exceeds the supported integer range.');
        }

        return $left + $right;
    }
}
