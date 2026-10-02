<?php

namespace Tests\Unit\Billing;

use App\DTOs\Billing\BillingCalculationInput;
use App\DTOs\Billing\BillingCalculationResult;
use App\DTOs\Billing\BillingSegmentInput;
use App\DTOs\Billing\DailyUsageInput;
use App\Services\Billing\BillingCalculationService;
use InvalidArgumentException;
use OverflowException;
use PHPUnit\Framework\TestCase;

class BillingCalculationServiceTest extends TestCase
{
    private BillingCalculationService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new BillingCalculationService;
    }

    public function test_calculates_approved_october_plan_change_values(): void
    {
        $result = $this->service->calculate(new BillingCalculationInput(
            cycleStart: '2026-10-01',
            cycleEnd: '2026-10-31',
            subscriptionStart: '2026-10-01',
            subscriptionEnd: null,
            segments: [
                new BillingSegmentInput(1, '2026-10-01', '2026-10-15', 50000, 5000, 20),
                new BillingSegmentInput(2, '2026-10-16', null, 100000, 10000, 10),
            ],
            dailyUsages: [
                new DailyUsageInput('2026-10-10', 2000),
                new DailyUsageInput('2026-10-20', 6000),
            ],
        ));

        $this->assertSame(24194, $result->segments[0]->baseAmountPaise);
        $this->assertSame(0, $result->segments[0]->overageAmountPaise);
        $this->assertSame(51613, $result->segments[1]->baseAmountPaise);
        $this->assertSame(8387, $result->segments[1]->overageAmountPaise);
        $this->assertSame(84194, $result->totalAmountPaise);
    }

    public function test_rounds_half_up_to_one_paise_using_integer_arithmetic(): void
    {
        $result = $this->calculate(
            '2026-02-01',
            '2026-02-28',
            '2026-02-01',
            '2026-02-01',
            [new BillingSegmentInput(1, '2026-02-01', '2026-02-01', 14, 0, 0)],
        );

        $this->assertSame(1, $result->segments[0]->baseAmountPaise);
    }

    public function test_calculates_full_cycle_base_and_allowance_without_proration(): void
    {
        $result = $this->calculate(
            '2026-10-01',
            '2026-10-31',
            '2026-10-01',
            null,
            [new BillingSegmentInput(1, '2026-10-01', null, 12500, 500, 3)],
            [new DailyUsageInput('2026-10-31', 500)],
        );

        $this->assertSame(31, $result->segments[0]->daysInSegment);
        $this->assertSame(31, $result->segments[0]->daysInCycle);
        $this->assertSame(15500, $result->segments[0]->allowanceUnitsNumerator);
        $this->assertSame(0, $result->segments[0]->overageUnitsNumerator);
        $this->assertSame(12500, $result->totalAmountPaise);
    }

    public function test_prorates_subscription_that_starts_mid_cycle(): void
    {
        $result = $this->calculate(
            '2026-10-01',
            '2026-10-31',
            '2026-10-16',
            null,
            [new BillingSegmentInput(1, '2026-10-01', null, 10000, 3100, 0)],
        );

        $this->assertSame('2026-10-16', $result->segments[0]->segmentStart);
        $this->assertSame(16, $result->segments[0]->daysInSegment);
        $this->assertSame(5161, $result->totalAmountPaise);
        $this->assertSame(49600, $result->segments[0]->allowanceUnitsNumerator);
    }

    public function test_prorates_subscription_that_ends_mid_cycle_inclusively(): void
    {
        $result = $this->calculate(
            '2026-10-01',
            '2026-10-31',
            '2026-10-01',
            '2026-10-10',
            [new BillingSegmentInput(1, '2026-10-01', null, 3100, 0, 0)],
            [new DailyUsageInput('2026-10-10', 12), new DailyUsageInput('2026-10-11', 99)],
        );

        $this->assertSame('2026-10-10', $result->segments[0]->segmentEnd);
        $this->assertSame(10, $result->segments[0]->daysInSegment);
        $this->assertSame(12, $result->segments[0]->unitsUsed);
        $this->assertSame(1000, $result->totalAmountPaise);
    }

    public function test_calculates_two_plan_changes_as_three_segments(): void
    {
        $result = $this->calculate(
            '2026-10-01',
            '2026-10-31',
            '2026-10-01',
            null,
            [
                new BillingSegmentInput(1, '2026-10-01', '2026-10-10', 3100, 100, 10),
                new BillingSegmentInput(2, '2026-10-11', '2026-10-20', 6200, 200, 20),
                new BillingSegmentInput(3, '2026-10-21', null, 9300, 300, 30),
            ],
            [
                new DailyUsageInput('2026-10-10', 101),
                new DailyUsageInput('2026-10-11', 202),
                new DailyUsageInput('2026-10-21', 303),
            ],
        );

        $this->assertCount(3, $result->segments);
        $this->assertSame([1, 2, 3], array_map(
            static fn ($segment): int => $segment->planId,
            $result->segments,
        ));
        $this->assertSame([101, 202, 303], array_map(
            static fn ($segment): int => $segment->unitsUsed,
            $result->segments,
        ));
        $this->assertSame([687, 2750, 5896], array_map(
            static fn ($segment): int => $segment->overageAmountPaise,
            $result->segments,
        ));
    }

    public function test_zero_usage_has_no_overage_and_preserves_base_charge(): void
    {
        $result = $this->calculate(
            '2026-10-01',
            '2026-10-31',
            '2026-10-01',
            null,
            [new BillingSegmentInput(1, '2026-10-01', null, 5000, 100, 20)],
        );

        $this->assertSame(0, $result->segments[0]->unitsUsed);
        $this->assertSame(0, $result->segments[0]->overageUnitsNumerator);
        $this->assertSame(0, $result->overageAmountPaise);
        $this->assertSame(5000, $result->baseAmountPaise);
    }

    public function test_usage_exactly_at_prorated_allowance_has_no_overage(): void
    {
        $result = $this->calculate(
            '2026-10-01',
            '2026-10-31',
            '2026-10-16',
            null,
            [new BillingSegmentInput(1, '2026-10-01', null, 0, 3100, 7)],
            [new DailyUsageInput('2026-10-16', 1600)],
        );

        $this->assertSame(49600, $result->segments[0]->allowanceUnitsNumerator);
        $this->assertSame(1600, $result->segments[0]->unitsUsed);
        $this->assertSame(0, $result->segments[0]->overageUnitsNumerator);
        $this->assertSame(0, $result->totalAmountPaise);
    }

    public function test_calculates_usage_far_above_allowance_without_rounding_units(): void
    {
        $result = $this->calculate(
            '2026-10-01',
            '2026-10-31',
            '2026-10-01',
            null,
            [new BillingSegmentInput(1, '2026-10-01', null, 0, 100, 3)],
            [new DailyUsageInput('2026-10-15', 1000000)],
        );

        $this->assertSame(30996900, $result->segments[0]->overageUnitsNumerator);
        $this->assertSame(2999700, $result->overageAmountPaise);
    }

    public function test_uses_actual_day_count_for_month_lengths_including_leap_year(): void
    {
        $months = [
            ['2026-02-01', '2026-02-28', 28],
            ['2024-02-01', '2024-02-29', 29],
            ['2026-04-01', '2026-04-30', 30],
            ['2026-10-01', '2026-10-31', 31],
        ];

        foreach ($months as [$start, $end, $expectedDays]) {
            $result = $this->calculate(
                $start,
                $end,
                $start,
                null,
                [new BillingSegmentInput(1, $start, null, 2800, 280, 0)],
            );

            $this->assertSame($expectedDays, $result->segments[0]->daysInCycle);
            $this->assertSame($expectedDays, $result->segments[0]->daysInSegment);
            $this->assertSame(2800, $result->totalAmountPaise);
            $this->assertSame(280 * $expectedDays, $result->segments[0]->allowanceUnitsNumerator);
        }
    }

    public function test_returns_zero_when_subscription_has_no_active_days_in_cycle(): void
    {
        $result = $this->calculate(
            '2026-10-01',
            '2026-10-31',
            '2026-11-01',
            null,
            [],
        );

        $this->assertSame([], $result->segments);
        $this->assertSame(0, $result->totalAmountPaise);
    }

    public function test_clips_out_plan_segment_starting_after_billed_cycle(): void
    {
        $result = $this->calculate(
            '2026-10-01',
            '2026-10-31',
            '2026-10-01',
            null,
            [
                new BillingSegmentInput(1, '2026-10-01', '2026-10-31', 3100, 0, 0),
                new BillingSegmentInput(2, '2026-11-01', null, 6200, 0, 0),
            ],
        );

        $this->assertCount(1, $result->segments);
        $this->assertSame(1, $result->segments[0]->planId);
        $this->assertSame(3100, $result->totalAmountPaise);
    }

    public function test_rejects_a_gap_in_plan_segment_coverage(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->calculate(
            '2026-10-01',
            '2026-10-31',
            '2026-10-01',
            null,
            [
                new BillingSegmentInput(1, '2026-10-01', '2026-10-10', 0, 0, 0),
                new BillingSegmentInput(2, '2026-10-12', null, 0, 0, 0),
            ],
        );
    }

    public function test_rejects_overlapping_plan_segment_coverage(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->calculate(
            '2026-10-01',
            '2026-10-31',
            '2026-10-01',
            null,
            [
                new BillingSegmentInput(1, '2026-10-01', null, 0, 0, 0),
                new BillingSegmentInput(2, '2026-10-15', '2026-10-20', 0, 0, 0),
            ],
        );
    }

    public function test_rejects_duplicate_daily_usage_dates(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->calculate(
            '2026-10-01',
            '2026-10-31',
            '2026-10-01',
            null,
            [new BillingSegmentInput(1, '2026-10-01', null, 0, 0, 0)],
            [
                new DailyUsageInput('2026-10-10', 1),
                new DailyUsageInput('2026-10-10', 2),
            ],
        );
    }

    public function test_rejects_non_calendar_month_cycles(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->calculate(
            '2026-10-02',
            '2026-10-31',
            '2026-10-02',
            null,
            [],
        );
    }

    public function test_fails_explicitly_if_integer_multiplication_overflows(): void
    {
        $this->expectException(OverflowException::class);

        $this->calculate(
            '2026-10-01',
            '2026-10-31',
            '2026-10-01',
            null,
            [new BillingSegmentInput(1, '2026-10-01', null, PHP_INT_MAX, 0, 0)],
        );
    }

    public function test_usage_on_plan_change_boundary_is_assigned_to_the_correct_segment(): void
    {
        $result = $this->calculate(
            '2026-10-01',
            '2026-10-31',
            '2026-10-01',
            null,
            [
                new BillingSegmentInput(1, '2026-10-01', '2026-10-15', 0, 0, 0),
                new BillingSegmentInput(2, '2026-10-16', null, 0, 0, 0),
            ],
            [
                new DailyUsageInput('2026-10-15', 100),
                new DailyUsageInput('2026-10-16', 200),
            ],
        );

        $this->assertSame(100, $result->segments[0]->unitsUsed);
        $this->assertSame(200, $result->segments[1]->unitsUsed);
    }

    public function test_rounds_half_paise_overage_amount_up(): void
    {
        $result = $this->calculate(
            '2026-02-01',
            '2026-02-28',
            '2026-02-01',
            '2026-02-01',
            [new BillingSegmentInput(1, '2026-02-01', '2026-02-01', 0, 14, 1)],
            [new DailyUsageInput('2026-02-01', 1)],
        );

        $this->assertSame(1, $result->segments[0]->overageAmountPaise);
    }

    public function test_prorates_subscription_starting_february_fifteenth_for_fourteen_days(): void
    {
        $result = $this->calculate(
            '2026-02-01',
            '2026-02-28',
            '2026-02-15',
            null,
            [new BillingSegmentInput(1, '2026-02-01', null, 2800, 0, 0)],
        );

        $this->assertSame(14, $result->segments[0]->daysInSegment);
        $this->assertSame(1400, $result->segments[0]->baseAmountPaise);
    }

    public function test_excludes_usage_before_subscription_start_date(): void
    {
        $result = $this->calculate(
            '2026-10-01',
            '2026-10-31',
            '2026-10-16',
            null,
            [new BillingSegmentInput(1, '2026-10-01', null, 0, 0, 0)],
            [
                new DailyUsageInput('2026-10-15', 99),
                new DailyUsageInput('2026-10-16', 5),
            ],
        );

        $this->assertSame(5, $result->segments[0]->unitsUsed);
    }

    /**
     * @param  list<BillingSegmentInput>  $segments
     * @param  list<DailyUsageInput>  $dailyUsages
     */
    private function calculate(
        string $cycleStart,
        string $cycleEnd,
        string $subscriptionStart,
        ?string $subscriptionEnd,
        array $segments,
        array $dailyUsages = [],
    ): BillingCalculationResult {
        return $this->service->calculate(new BillingCalculationInput(
            cycleStart: $cycleStart,
            cycleEnd: $cycleEnd,
            subscriptionStart: $subscriptionStart,
            subscriptionEnd: $subscriptionEnd,
            segments: $segments,
            dailyUsages: $dailyUsages,
        ));
    }
}
